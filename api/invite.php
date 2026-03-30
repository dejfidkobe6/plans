<?php
require_once __DIR__ . '/functions.php';

$token = trim($_GET['token'] ?? '');
if (!$token) { header('Location: https://plans.besix.cz/?invite_error=missing'); exit; }

$db = getDB();

// Najdi pozvánku – přijmeme i již accepted (pro případ opakovaného kliknutí)
$stmt = $db->prepare('
    SELECT i.*, p.name AS project_name
    FROM invitations i
    JOIN projects p ON p.id = i.project_id
    WHERE i.token = ? AND i.expires_at > NOW()
    LIMIT 1
');
$stmt->execute([$token]);
$inv = $stmt->fetch();

if (!$inv) { header('Location: https://plans.besix.cz/?invite_error=invalid'); exit; }

// Pokud není přihlášen – ulož token do session a přesměruj na login
$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser) {
    $_SESSION['pending_invite'] = $token;
    header('Location: https://plans.besix.cz/?invite=' . urlencode($token));
    exit;
}

$userId = (int)$sessionUser['id'];

// Přidej uživatele do projektu (pokud ještě není členem)
$exists = $db->prepare('SELECT id FROM project_members WHERE project_id = ? AND user_id = ?');
$exists->execute([$inv['project_id'], $userId]);
if (!$exists->fetch()) {
    $db->prepare('INSERT INTO project_members (project_id, user_id, role, invited_by) VALUES (?,?,?,?)')
       ->execute([$inv['project_id'], $userId, $inv['role'], $inv['invited_by']]);
}

// Označ VŠECHNY čekající pozvánky pro tento email+projekt jako přijatou
// (odstraní duplikáty z čekajících pozvánek)
$userEmail = strtolower($sessionUser['email'] ?? '');
if ($userEmail) {
    $db->prepare('UPDATE invitations SET status = "accepted" WHERE project_id = ? AND LOWER(invited_email) = ?')
       ->execute([$inv['project_id'], $userEmail]);
} else {
    $db->prepare('UPDATE invitations SET status = "accepted" WHERE token = ?')->execute([$token]);
}

// Vyčisti pending_invite ze session
unset($_SESSION['pending_invite']);

header('Location: https://plans.besix.cz/?invite_ok=' . $inv['project_id']);
exit;
