<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$err['message'],'file'=>basename($err['file']),'line'=>$err['line']]);
    }
});
set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
    exit;
});

require_once __DIR__ . '/functions.php';

// Vytvoř tabulku pokud neexistuje
getDB()->exec('CREATE TABLE IF NOT EXISTS plan_canvas_data (
    project_id    INT          NOT NULL,
    state_json    LONGTEXT,
    profese_json  MEDIUMTEXT,
    annot_counter INT          NOT NULL DEFAULT 1,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$user      = requireAuth();
$userId    = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];

if (!$projectId) jsonError('Chybí project_id');

// Ověř přístup k projektu
$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

// ============================================================
// Komprese / dekomprese (gzip+base64, 5-10× menší data)
// ============================================================
function _compress(string $json): string {
    // Pokud gzip není dostupný nebo selže, ulož plain JSON
    if (function_exists('gzencode')) {
        $gz = @gzencode($json, 6);
        if ($gz !== false) return base64_encode($gz);
    }
    return $json; // fallback – nekomprimovaný JSON
}

function _decompress(string $val): string {
    if (strlen($val) >= 4 && str_starts_with($val, 'H4s')) {
        $decoded = base64_decode($val, true);
        if ($decoded !== false) {
            $inflated = @gzdecode($decoded);
            if ($inflated !== false) return $inflated;
        }
    }
    return $val; // plain JSON nebo starý nekomprimovaný formát
}

// ============================================================
// GET – načti canvas data projektu
// ============================================================
if ($method === 'GET') {
    // Lightweight timestamp-only poll for live sync
    if (!empty($_GET['ts_only'])) {
        $stmt = getDB()->prepare('SELECT updated_at FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        jsonOk(['updated_at' => $row['updated_at'] ?? null]);
    }

    $stmt = getDB()->prepare(
        'SELECT state_json, profese_json, annot_counter, updated_at FROM plan_canvas_data WHERE project_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();

    if (!$row || !$row['state_json']) {
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1, 'updated_at' => null]);
    }

    $state   = json_decode(_decompress($row['state_json']), true);
    $profese = $row['profese_json'] ? json_decode(_decompress($row['profese_json']), true) : null;

    if ($state === null) {
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1, 'updated_at' => null]);
    }

    jsonOk([
        'state'      => $state,
        'profese'    => $profese,
        'counter'    => (int)($row['annot_counter'] ?? 1),
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

// ============================================================
// POST – ulož canvas data projektu s merge (concurrent-safe)
// Body: { state: {...levels...}, profese: [...], counter: N }
// ============================================================
if ($method === 'POST') {
    // Accept both application/x-www-form-urlencoded (field: data=<json>)
    // and legacy application/json (raw body).
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
        $rawJson = $_POST['data'] ?? '';
    } else {
        $rawJson = file_get_contents('php://input');
    }
    if (!$rawJson) jsonError('Prázdné tělo požadavku', 400);

    $body = json_decode($rawJson, true);
    if ($body === null) jsonError('Neplatný JSON: ' . json_last_error_msg(), 400);

    $state   = $body['state']   ?? null;
    $profese = $body['profese'] ?? null;
    $counter = (int)($body['counter'] ?? 1);

    if ($state === null) jsonError('Chybí state');

    // Odfiltruj backgroundImage z levels (příliš velká data)
    if (isset($state['levels']) && is_array($state['levels'])) {
        foreach ($state['levels'] as &$lvl) {
            unset($lvl['backgroundImage'], $lvl['backgroundImageOriginal']);
        }
        unset($lvl);
    }

    $db = getDB();
    $db->beginTransaction();

    try {
        // Zamkni řádek – atomická operace (SELECT FOR UPDATE)
        $stmt = $db->prepare(
            'SELECT state_json, annot_counter FROM plan_canvas_data WHERE project_id = ? FOR UPDATE'
        );
        $stmt->execute([$projectId]);
        $existingRow = $stmt->fetch();

        // Načti server stav pro merge
        $serverLevels  = [];
        $serverCounter = 1;
        if ($existingRow && $existingRow['state_json']) {
            $serverState  = json_decode(_decompress($existingRow['state_json']), true);
            $serverLevels = $serverState['levels'] ?? [];
            $serverCounter = (int)($existingRow['annot_counter'] ?? 1);
        }

        // Merge každého levelu – zachová anotace přidané jiným uživatelem
        $clientLevels = $state['levels'] ?? [];
        $serverLevelMap = [];
        foreach ($serverLevels as $sl) {
            if (!empty($sl['id'])) $serverLevelMap[$sl['id']] = $sl;
        }
        $clientLevelMap = [];
        foreach ($clientLevels as $cl) {
            if (!empty($cl['id'])) $clientLevelMap[$cl['id']] = $cl;
        }

        // serverAdded: objekty ze serveru, které klient neměl – vrátíme klientovi
        $serverAdded = [];

        $mergedLevels = [];
        foreach ($clientLevels as $cl) {
            $id = $cl['id'] ?? null;
            if ($id && isset($serverLevelMap[$id])) {
                $sl = $serverLevelMap[$id];

                // Merge fabricJSON.objects by annotId
                $clientObjs = $cl['fabricJSON']['objects'] ?? [];
                $serverObjs = $sl['fabricJSON']['objects'] ?? [];
                [$mergedObjs, $serverOnlyObjs] = _mergeCanvasObjects($serverObjs, $clientObjs);

                // Merge annotations by annotId
                $clientAnnots = $cl['annotations'] ?? [];
                $serverAnnots = $sl['annotations'] ?? [];
                [$mergedAnnots] = _mergeCanvasAnnotations($serverAnnots, $clientAnnots);

                $merged = $cl;
                if (isset($cl['fabricJSON']) && $cl['fabricJSON'] !== null) {
                    $merged['fabricJSON'] = array_merge($cl['fabricJSON'], ['objects' => $mergedObjs]);
                }
                $merged['annotations'] = $mergedAnnots;
                $mergedLevels[] = $merged;

                if (!empty($serverOnlyObjs)) {
                    $serverAdded[] = ['levelId' => $id, 'objects' => $serverOnlyObjs];
                }
            } else {
                $mergedLevels[] = $cl;
            }
        }

        // Zachovej levely přítomné pouze na serveru (přidal jiný uživatel)
        foreach ($serverLevels as $sl) {
            $id = $sl['id'] ?? null;
            if ($id && !isset($clientLevelMap[$id])) {
                $mergedLevels[] = $sl;
            }
        }

        $mergedState           = $state;
        $mergedState['levels'] = $mergedLevels;
        $newCounter            = max($counter, $serverCounter);

        // Serializuj JSON – JSON_PARTIAL_OUTPUT_ON_ERROR jako pojistka pro bad UTF-8
        $stateJson = json_encode($mergedState, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($stateJson === false || $stateJson === 'null') {
            $db->rollBack();
            jsonError('Chyba serializace stavu: ' . json_last_error_msg(), 500);
        }

        $profeseJson = null;
        if ($profese !== null) {
            $profeseJson = json_encode($profese, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($profeseJson === false) $profeseJson = null;
        }

        $stateCompressed   = _compress($stateJson);
        $proteseCompressed = $profeseJson !== null ? _compress($profeseJson) : null;

        if (empty($stateCompressed)) {
            $db->rollBack();
            jsonError('Komprese selhala – prázdný výsledek', 500);
        }

        if ($existingRow) {
            $db->prepare('
                UPDATE plan_canvas_data
                SET state_json = ?, profese_json = ?, annot_counter = ?, updated_at = NOW()
                WHERE project_id = ?
            ')->execute([$stateCompressed, $proteseCompressed, $newCounter, $projectId]);
        } else {
            $db->prepare('
                INSERT INTO plan_canvas_data (project_id, state_json, profese_json, annot_counter)
                VALUES (?, ?, ?, ?)
            ')->execute([$projectId, $stateCompressed, $proteseCompressed, $newCounter]);
        }

        $db->commit();

        // Vrať serverAdded klientovi – přidá chybějící anotace do canvasu
        jsonOk(['saved' => strlen($stateCompressed), 'serverAdded' => $serverAdded, 'counter' => $newCounter]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Chyba při ukládání: ' . $e->getMessage(), 500);
    }
}

// ============================================================
// Merge helpers – canvas objects / annotations by annotId
// server = co je na serveru; client = co posílá klient
// client vítězí pro shodné annotId (last-write-wins per object)
// server-only items jsou zachovány (přidal jiný uživatel)
// Returns: [mergedArray, serverOnlyItems]
// ============================================================
function _mergeCanvasObjects(array $serverObjs, array $clientObjs): array {
    $clientIds = [];
    foreach ($clientObjs as $o) {
        $aid = $o['annotId'] ?? null;
        if ($aid) $clientIds[$aid] = true;
    }

    $merged     = $clientObjs;
    $serverOnly = [];
    foreach ($serverObjs as $o) {
        $aid = $o['annotId'] ?? null;
        if ($aid && !isset($clientIds[$aid])) {
            $merged[]     = $o;
            $serverOnly[] = $o;
        }
    }
    return [$merged, $serverOnly];
}

function _mergeCanvasAnnotations(array $serverAnnots, array $clientAnnots): array {
    $clientIds = [];
    foreach ($clientAnnots as $a) {
        $aid = $a['annotId'] ?? null;
        if ($aid) $clientIds[$aid] = true;
    }

    $merged     = $clientAnnots;
    $serverOnly = [];
    foreach ($serverAnnots as $a) {
        $aid = $a['annotId'] ?? null;
        if ($aid && !isset($clientIds[$aid])) {
            $merged[]     = $a;
            $serverOnly[] = $a;
        }
    }
    return [$merged, $serverOnly];
}

jsonError('Metoda není povolena', 405);
