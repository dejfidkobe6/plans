<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/functions.php';

$user   = requireAuth();
$userId = (int)$user['id'];
$appId  = getPlansAppId();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET /api/projects.php  – seznam projektů uživatele
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare('
        SELECT id, name, created_at
        FROM projects
        WHERE app_id = ? AND created_by = ? AND is_active = 1
        ORDER BY created_at DESC
    ');
    $stmt->execute([$appId, $userId]);
    $projects = $stmt->fetchAll();
    // Přidáme role=owner pro všechny (creator = owner)
    foreach ($projects as &$p) $p['role'] = 'owner';
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
