<?php
require_once __DIR__ . '/functions.php';

$user      = requireAuth();
$userId    = (int)$user['id'];
$method    = $_SERVER['REQUEST_METHOD'];
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) jsonError('Chybí project_id');

// Ověř že uživatel je členem projektu
$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

$isOwnerOrAdmin = in_array($membership['role'], ['owner', 'admin']);

// ============================================================
// GET /api/members.php?project_id=X – seznam členů
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare('
        SELECT pm.id, pm.user_id, pm.role, pm.joined_at,
               u.name, u.email, u.avatar_color
        FROM project_members pm
        JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ?
        ORDER BY FIELD(pm.role,"owner","admin","member","viewer"), u.name
    ');
    $stmt->execute([$projectId]);
    jsonOk(['members' => $stmt->fetchAll()]);
}

// ============================================================
// PUT /api/members.php?project_id=X – změnit roli
// Body: { member_id, role }
// ============================================================
if ($method === 'PUT') {
    if (!$isOwnerOrAdmin) jsonError('Nemáš oprávnění měnit role', 403);

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $memberId = (int)($body['member_id'] ?? 0);
    $newRole  = $body['role'] ?? '';

    if (!in_array($newRole, ['admin', 'member', 'viewer'])) jsonError('Neplatná role');

    $db   = getDB();
    $stmt = $db->prepare('SELECT user_id, role FROM project_members WHERE id = ? AND project_id = ?');
    $stmt->execute([$memberId, $projectId]);
    $target = $stmt->fetch();

    if (!$target)                         jsonError('Člen nenalezen', 404);
    if ($target['role'] === 'owner')      jsonError('Nelze měnit roli vlastníka');
    if ((int)$target['user_id'] === $userId) jsonError('Nelze měnit vlastní roli');

    $db->prepare('UPDATE project_members SET role = ? WHERE id = ?')->execute([$newRole, $memberId]);
    jsonOk();
}

// ============================================================
// DELETE /api/members.php?project_id=X&member_id=Y – odebrat člena
// ============================================================
if ($method === 'DELETE') {
    if (!$isOwnerOrAdmin) jsonError('Nemáš oprávnění odebírat členy', 403);

    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('Chybí member_id');

    $db   = getDB();
    $stmt = $db->prepare('SELECT user_id, role FROM project_members WHERE id = ? AND project_id = ?');
    $stmt->execute([$memberId, $projectId]);
    $target = $stmt->fetch();

    if (!$target)                    jsonError('Člen nenalezen', 404);
    if ($target['role'] === 'owner') jsonError('Nelze odebrat vlastníka');

    $db->prepare('DELETE FROM project_members WHERE id = ?')->execute([$memberId]);
    jsonOk();
}

jsonError('Metoda není povolena', 405);
