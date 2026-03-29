<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$errstr,'file'=>basename($errfile),'line'=>$errline]);
    exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
    exit;
});

require_once __DIR__ . '/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$appId  = getPlansAppId();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET /api/projects.php  – seznam projektů uživatele (vlastní + jako člen)
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare('
        SELECT p.id, p.name, p.created_at, pm.role
        FROM projects p
        JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
        WHERE p.app_id = ? AND p.is_active = 1
        ORDER BY p.created_at DESC
    ');
    $stmt->execute([$userId, $appId]);
    $projects = $stmt->fetchAll();
    jsonOk(['projects' => $projects]);
}

// ============================================================
// POST /api/projects.php  – vytvořit projekt
// Body: { name }
// ============================================================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    if (!$name) jsonError('Název projektu je povinný');

    $db          = getDB();
    $invite_code = substr(bin2hex(random_bytes(16)), 0, 32);

    $db->prepare('
        INSERT INTO projects (app_id, name, created_by, invite_code, is_active)
        VALUES (?, ?, ?, ?, 1)
    ')->execute([$appId, $name, $userId, $invite_code]);

    $projectId = (int)$db->lastInsertId();

    // Přidej tvůrce jako owner do project_members
    $db->prepare('
        INSERT INTO project_members (project_id, user_id, role, invited_by)
        VALUES (?, ?, "owner", ?)
    ')->execute([$projectId, $userId, $userId]);

    jsonOk(['project' => [
        'id'         => $projectId,
        'name'       => $name,
        'created_at' => date('Y-m-d H:i:s'),
        'role'       => 'owner',
    ]]);
}

// ============================================================
// DELETE /api/projects.php?id=123  – smazat projekt (pouze creator)
// ============================================================
if ($method === 'DELETE') {
    $projectId = (int)($_GET['id'] ?? 0);
    if (!$projectId) jsonError('Chybí ID projektu');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM projects WHERE id = ? AND app_id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([$projectId, $appId, $userId]);
    if (!$stmt->fetch()) jsonError('Projekt nenalezen nebo nemáš oprávnění', 403);

    // Soft delete
    $db->prepare('UPDATE projects SET is_active = 0 WHERE id = ?')->execute([$projectId]);

    jsonOk();
}

jsonError('Metoda není povolena', 405);
