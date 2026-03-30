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
    $stmt = getDB()->prepare(
        'SELECT state_json, profese_json, annot_counter FROM plan_canvas_data WHERE project_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();

    if (!$row || !$row['state_json']) {
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1]);
    }

    $state   = json_decode(_decompress($row['state_json']), true);
    $profese = $row['profese_json'] ? json_decode(_decompress($row['profese_json']), true) : null;

    if ($state === null) {
        // Dekomprese nebo parsování selhalo – vrať prázdný stav
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1]);
    }

    jsonOk([
        'state'   => $state,
        'profese' => $profese,
        'counter' => (int)($row['annot_counter'] ?? 1),
    ]);
}

// ============================================================
// POST – ulož canvas data projektu
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

    // Serializuj JSON – JSON_PARTIAL_OUTPUT_ON_ERROR jako pojistka pro bad UTF-8
    $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($stateJson === false || $stateJson === 'null') {
        jsonError('Chyba serializace stavu: ' . json_last_error_msg(), 500);
    }

    $profeseJson = null;
    if ($profese !== null) {
        $profeseJson = json_encode($profese, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($profeseJson === false) $profeseJson = null;
    }

    $stateCompressed   = _compress($stateJson);
    $proteseCompressed = $profeseJson !== null ? _compress($profeseJson) : null;

    // Ověř, že komprimovaná data nejsou prázdná
    if (empty($stateCompressed)) {
        jsonError('Komprese selhala – prázdný výsledek', 500);
    }

    // INSERT nebo UPDATE – explicitní syntax (kompatibilní s MySQL 8.0+)
    $db = getDB();
    $existing = $db->prepare('SELECT project_id FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
    $existing->execute([$projectId]);

    if ($existing->fetch()) {
        $db->prepare('
            UPDATE plan_canvas_data
            SET state_json = ?, profese_json = ?, annot_counter = ?, updated_at = NOW()
            WHERE project_id = ?
        ')->execute([$stateCompressed, $proteseCompressed, $counter, $projectId]);
    } else {
        $db->prepare('
            INSERT INTO plan_canvas_data (project_id, state_json, profese_json, annot_counter)
            VALUES (?, ?, ?, ?)
        ')->execute([$projectId, $stateCompressed, $proteseCompressed, $counter]);
    }

    jsonOk(['saved' => strlen($stateCompressed)]);
}

jsonError('Metoda není povolena', 405);
