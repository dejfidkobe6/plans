<?php
require_once __DIR__ . '/functions.php';

$user      = requireAuth();
$userId    = (int)$user['id'];
$method    = $_SERVER['REQUEST_METHOD'];
$projectId = (int)($_GET['project_id'] ?? 0);
if (!$projectId) jsonError('Chybí project_id');

$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);
$isOwnerOrAdmin = in_array($membership['role'], ['owner', 'admin']);

// ============================================================
// GET – seznam čekajících pozvánek
// ============================================================
if ($method === 'GET') {
    $stmt = getDB()->prepare('
        SELECT i.id, i.invited_email, i.role, i.status, i.created_at, i.expires_at,
               u.name AS invited_by_name
        FROM invitations i
        LEFT JOIN users u ON u.id = i.invited_by
        WHERE i.project_id = ? AND i.status = "pending"
        ORDER BY i.created_at DESC
    ');
    $stmt->execute([$projectId]);
    jsonOk(['invitations' => $stmt->fetchAll()]);
}

// ============================================================
// POST – vytvořit pozvánku + odeslat email
// Body: { email, role }
// ============================================================
if ($method === 'POST') {
    if (!$isOwnerOrAdmin) jsonError('Nemáš oprávnění pozvat členy', 403);

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = $body['role'] ?? 'member';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Neplatný email');
    if (!in_array($role, ['admin', 'member', 'viewer'])) jsonError('Neplatná role');

    $db = getDB();

    // Zkontroluj zda uživatel není již členem
    $check = $db->prepare('
        SELECT pm.id FROM project_members pm
        JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ? AND u.email = ?
    ');
    $check->execute([$projectId, $email]);
    if ($check->fetch()) jsonError('Uživatel je již členem projektu');

    // Zruš existující čekající pozvánky pro tento email
    $db->prepare('UPDATE invitations SET status = "expired" WHERE project_id = ? AND invited_email = ? AND status = "pending"')
       ->execute([$projectId, $email]);

    // Vytvoř novou pozvánku
    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    $db->prepare('INSERT INTO invitations (project_id, invited_email, invited_by, token, role, expires_at) VALUES (?,?,?,?,?,?)')
       ->execute([$projectId, $email, $userId, $token, $role, $expiresAt]);

    // Získej název projektu
    $proj = $db->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
    $proj->execute([$projectId]);
    $projName = $proj->fetch()['name'] ?? 'projekt';

    // Odešli email
    $inviteUrl = 'https://plans.besix.cz/invite.php?token=' . $token;
    $roleLabels = ['admin' => 'Administrátor', 'member' => 'Člen', 'viewer' => 'Pozorovatel'];
    $roleLabel  = $roleLabels[$role] ?? $role;

    $subject = "Pozvánka do projektu „{$projName}" – BeSix Plans";
    $html = "
    <div style='font-family:sans-serif;max-width:520px;margin:0 auto'>
      <img src='https://plans.besix.cz/besix_logo_bila.png' style='height:36px;margin-bottom:24px;filter:invert(1)' alt='BeSix'>
      <h2 style='margin:0 0 8px'>Byl(a) jsi pozván(a) do projektu</h2>
      <p style='font-size:20px;font-weight:600;margin:0 0 16px'>{$projName}</p>
      <p style='color:#555'>Uživatel <strong>{$user['name']}</strong> tě zve jako <strong>{$roleLabel}</strong>.</p>
      <a href='{$inviteUrl}' style='display:inline-block;margin:24px 0;padding:12px 28px;background:#4A5340;color:#fff;text-decoration:none;border-radius:6px;font-weight:600'>
        Přijmout pozvánku
      </a>
      <p style='color:#999;font-size:12px'>Platnost pozvánky vyprší za 7 dní. Pokud pozvánku neočekáváš, ignoruj tento email.</p>
    </div>";

    sendMail($email, $subject, $html);

    jsonOk(['message' => 'Pozvánka odeslána']);
}

// ============================================================
// DELETE – zrušit pozvánku
// ============================================================
if ($method === 'DELETE') {
    if (!$isOwnerOrAdmin) jsonError('Nemáš oprávnění', 403);

    $invId = (int)($_GET['id'] ?? 0);
    if (!$invId) jsonError('Chybí id');

    getDB()->prepare('UPDATE invitations SET status = "expired" WHERE id = ? AND project_id = ?')
           ->execute([$invId, $projectId]);
    jsonOk();
}

jsonError('Metoda není povolena', 405);
