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
// GET – načti KD záznamy
// ============================================================
if ($method === 'GET') {
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
// POST – ulož KD záznamy
// Body: URL-encoded field "data" = JSON {records:[...]}
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

    $records = $body['records'] ?? [];
    if (!is_array($records)) jsonError('records musí být pole', 400);

    $kdJson = json_encode(['records' => $records], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($kdJson === false || $kdJson === 'null') jsonError('Chyba serializace', 500);

    $db = getDB();
    $existing = $db->prepare('SELECT project_id FROM plan_kd_data WHERE project_id = ? LIMIT 1');
    $existing->execute([$projectId]);

    if ($existing->fetch()) {
        $db->prepare('UPDATE plan_kd_data SET kd_json = ?, updated_at = NOW() WHERE project_id = ?')
           ->execute([$kdJson, $projectId]);
    } else {
        $db->prepare('INSERT INTO plan_kd_data (project_id, kd_json) VALUES (?, ?)')
           ->execute([$projectId, $kdJson]);
    }

    jsonOk(['saved' => true]);
}

jsonError('Metoda není povolena', 405);
