<?php
require_once __DIR__ . '/functions.php';
setCorsHeaders();

$user   = requireAuth();
$userId = (int)$user['id'];
$appId  = getPlansAppId();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET /api/projects.php  – seznam projektů uživatele
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare('
        SELECT p.id, p.name, p.created_at, pm.role
        FROM projects p
        JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
        WHERE p.app_id = ?
        ORDER BY p.created_at DESC
    ');
    $stmt->execute([$userId, $appId]);
    jsonOk(['projects' => $stmt->fetchAll()]);
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
    $invite_code = substr(bin2hex(random_bytes(8)), 0, 10);

    $db->prepare('INSERT INTO projects (app_id, name, invite_code) VALUES (?,?,?)')
       ->execute([$appId, $name, $invite_code]);
    $projectId = (int)$db->lastInsertId();

    // Tvůrce = owner
    $db->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?,?,?)')
       ->execute([$projectId, $userId, 'owner']);

    jsonOk(['project' => [
        'id'         => $projectId,
        'name'       => $name,
        'created_at' => date('Y-m-d H:i:s'),
        'role'       => 'owner',
    ]]);
}

// ============================================================
// DELETE /api/projects.php?id=123  – smazat projekt (pouze owner)
// ============================================================
if ($method === 'DELETE') {
    $projectId = (int)($_GET['id'] ?? 0);
    if (!$projectId) jsonError('Chybí ID projektu');

    $db   = getDB();
    $stmt = $db->prepare('
        SELECT role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1
    ');
    $stmt->execute([$projectId, $userId]);
    $member = $stmt->fetch();

    if (!$member || $member['role'] !== 'owner') jsonError('Nemáš oprávnění smazat tento projekt', 403);

    $db->prepare('DELETE FROM project_members WHERE project_id = ?')->execute([$projectId]);
    $db->prepare('DELETE FROM projects WHERE id = ? AND app_id = ?')->execute([$projectId, $appId]);

    jsonOk();
}

jsonError('Metoda není povolena', 405);
