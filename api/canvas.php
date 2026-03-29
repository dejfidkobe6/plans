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

$user      = requireAuth();
$userId    = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];

if (!$projectId) jsonError('Chybí project_id');

// Ověř přístup k projektu
$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

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

    jsonOk([
        'state'   => json_decode($row['state_json'],  true),
        'profese' => $row['profese_json'] ? json_decode($row['profese_json'], true) : null,
        'counter' => (int)($row['annot_counter'] ?? 1),
    ]);
}

// ============================================================
// POST – ulož canvas data projektu
// Body: { state: {...levels...}, profese: [...], counter: N }
// ============================================================
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $state   = $body['state']   ?? null;
    $profese = $body['profese'] ?? null;
    $counter = (int)($body['counter'] ?? 1);

    if ($state === null) jsonError('Chybí state');

    // Odfiltruj backgroundImage z levels před uložením (příliš velká data)
    if (isset($state['levels']) && is_array($state['levels'])) {
        foreach ($state['levels'] as &$lvl) {
            unset($lvl['backgroundImage'], $lvl['backgroundImageOriginal']);
        }
        unset($lvl);
    }

    getDB()->prepare('
        INSERT INTO plan_canvas_data (project_id, state_json, profese_json, annot_counter)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            state_json    = VALUES(state_json),
            profese_json  = VALUES(profese_json),
            annot_counter = VALUES(annot_counter),
            updated_at    = NOW()
    ')->execute([
        $projectId,
        json_encode($state,   JSON_UNESCAPED_UNICODE),
        $profese !== null ? json_encode($profese, JSON_UNESCAPED_UNICODE) : null,
        $counter,
    ]);

    jsonOk();
}

jsonError('Metoda není povolena', 405);
