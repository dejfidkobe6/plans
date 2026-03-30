<?php
ob_start();
ini_set('display_errors', 0);
ini_set('post_max_size', '50M');
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$err['message'],'fatal'=>true]);
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
$method    = $_SERVER['REQUEST_METHOD'];
$projectId = (int)($_GET['project_id'] ?? 0);
$levelId   = trim($_GET['level_id'] ?? '');

if (!$projectId || $levelId === '') jsonError('Chybí parametry');
if (strlen($levelId) > 64) jsonError('Neplatné level_id');

$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup', 403);

// ============================================================
// GET – načti obrázek pozadí
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare(
        'SELECT image_data, original_data FROM plan_backgrounds WHERE project_id = ? AND level_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId, $levelId]);
    $row = $stmt->fetch();
    if (!$row || !$row['image_data']) {
        jsonOk(['dataUrl' => null, 'originalUrl' => null]);
    }
    jsonOk(['dataUrl' => $row['image_data'], 'originalUrl' => $row['original_data']]);
}

// ============================================================
// POST – ulož nebo smaž obrázek pozadí
// Body: { dataUrl, originalUrl }  – null dataUrl = smazat
// ============================================================
if ($method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $dataUrl     = $body['dataUrl']     ?? null;
    $originalUrl = $body['originalUrl'] ?? null;

    $db = getDB();
    if (!$dataUrl) {
        $db->prepare('DELETE FROM plan_backgrounds WHERE project_id = ? AND level_id = ?')
           ->execute([$projectId, $levelId]);
    } else {
        $db->prepare('
            INSERT INTO plan_backgrounds (project_id, level_id, image_data, original_data)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                image_data    = VALUES(image_data),
                original_data = VALUES(original_data),
                updated_at    = NOW()
        ')->execute([$projectId, $levelId, $dataUrl, $originalUrl]);
    }
    jsonOk();
}

jsonError('Metoda není povolena', 405);
