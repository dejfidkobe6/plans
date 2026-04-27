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
        echo json_encode(['ok'=>false,'error'=>$err['message']]);
    }
});
set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
});

require_once __DIR__ . '/functions.php';

$user      = requireAuth();
$userId    = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];

if (!$projectId) jsonError('Chybí project_id');

$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

// Vytvoř tabulku pokud neexistuje (idempotentní)
getDB()->exec('CREATE TABLE IF NOT EXISTS plan_kd_data (
    project_id INT          NOT NULL,
    kd_json    MEDIUMTEXT,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

// ============================================================
// GET – načti KD záznamy (nebo jen timestamp)
// ============================================================
if ($method === 'GET') {
    // ts_only=1: lehký dotaz jen na timestamp (pro polling)
    if (!empty($_GET['ts_only'])) {
        $stmt = getDB()->prepare('SELECT updated_at FROM plan_kd_data WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        jsonOk(['updated_at' => $row ? $row['updated_at'] : null]);
    }

    $stmt = getDB()->prepare('SELECT kd_json, updated_at FROM plan_kd_data WHERE project_id = ? LIMIT 1');
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();

    if (!$row || !$row['kd_json']) {
        jsonOk(['records' => [], 'updated_at' => null]);
    }

    $data = json_decode($row['kd_json'], true);
    jsonOk([
        'records'    => $data['records'] ?? [],
        'updated_at' => $row['updated_at'],
    ]);
}

// ============================================================
// POST – ulož KD záznamy s merge (concurrent-safe)
// ============================================================
if ($method === 'POST') {
    if (!in_array($membership['role'], ['owner','admin','member'])) {
        jsonError('Nemáš oprávnění upravovat záznamy', 403);
    }

    $rawJson = $_POST['data'] ?? '';
    if (!$rawJson) $rawJson = file_get_contents('php://input');
    if (!$rawJson) jsonError('Prázdné tělo požadavku', 400);

    $body = json_decode($rawJson, true);
    if ($body === null) jsonError('Neplatný JSON: ' . json_last_error_msg(), 400);

    $incoming = $body['records'] ?? [];
    if (!is_array($incoming)) jsonError('records musí být pole', 400);

    $db = getDB();
    $db->beginTransaction();

    try {
        // Zamkni řádek pro čtení (SELECT FOR UPDATE) – atomická operace
        $stmt = $db->prepare('SELECT kd_json FROM plan_kd_data WHERE project_id = ? FOR UPDATE');
        $stmt->execute([$projectId]);
        $existingRow = $stmt->fetch();

        if ($existingRow && $existingRow['kd_json']) {
            $existingData    = json_decode($existingRow['kd_json'], true) ?: [];
            $existingRecords = $existingData['records'] ?? [];
            // Merge: zachová přidání od jiných uživatelů
            $merged = mergeKDRecords($existingRecords, $incoming);
        } else {
            $merged = $incoming;
        }

        $kdJson = json_encode(['records' => $merged], JSON_UNESCAPED_UNICODE);
        if ($kdJson === false) {
            $db->rollBack();
            jsonError('Chyba serializace JSON: ' . json_last_error_msg(), 500);
        }

        if ($existingRow) {
            $db->prepare('UPDATE plan_kd_data SET kd_json = ?, updated_at = NOW() WHERE project_id = ?')
               ->execute([$kdJson, $projectId]);
        } else {
            $db->prepare('INSERT INTO plan_kd_data (project_id, kd_json) VALUES (?, ?)')
               ->execute([$projectId, $kdJson]);
        }

        $db->commit();

        // Vrať mergnutá data klientovi, aby mohl přidat úkoly od jiných uživatelů
        jsonOk(['saved' => true, 'records' => $merged]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Chyba při ukládání: ' . $e->getMessage(), 500);
    }
}

jsonError('Metoda není povolena', 405);

// ============================================================
// Server-side merge: items/records with same ID → merge recursively
// Items only on server (from concurrent user) → preserved
// Items only from client → added
// Ordering: client order first, server-only items appended
// ============================================================
function mergeKDById(array $server, array $client, ?string $mergeFn): array {
    $serverMap = [];
    foreach ($server as $item) {
        if (!empty($item['id'])) $serverMap[$item['id']] = $item;
    }

    $result  = [];
    $seenIds = [];

    foreach ($client as $clientItem) {
        $id = $clientItem['id'] ?? null;
        if ($id !== null) $seenIds[] = $id;

        if ($id !== null && isset($serverMap[$id])) {
            $result[] = $mergeFn ? $mergeFn($serverMap[$id], $clientItem) : $clientItem;
        } else {
            $result[] = $clientItem;
        }
    }

    // Záznamy na serveru, které klient neposílal (přidal jiný uživatel) → zachovat
    foreach ($server as $serverItem) {
        $id = $serverItem['id'] ?? null;
        if ($id !== null && !in_array($id, $seenIds)) {
            $result[] = $serverItem;
        }
    }

    return $result;
}

function mergeKDRecords(array $server, array $client): array {
    return mergeKDById($server, $client, 'mergeKDRecord');
}

function mergeKDRecord(array $server, array $client): array {
    $merged             = $client; // date, note: klient vyhrává
    $merged['chapters'] = mergeKDById(
        $server['chapters'] ?? [],
        $client['chapters'] ?? [],
        'mergeKDChapter'
    );
    return $merged;
}

function mergeKDChapter(array $server, array $client): array {
    $merged          = $client;
    $merged['cards'] = mergeKDById(
        $server['cards'] ?? [],
        $client['cards'] ?? [],
        'mergeKDCard'
    );
    return $merged;
}

function mergeKDCard(array $server, array $client): array {
    $merged          = $client;
    $merged['tasks'] = mergeKDById(
        $server['tasks'] ?? [],
        $client['tasks'] ?? [],
        null // úkoly: last-write-wins per task ID (žádný sub-merge)
    );
    return $merged;
}
