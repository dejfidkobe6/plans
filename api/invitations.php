<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$errstr,'file'=>basename($errfile),'line'=>$errline]);
    exit;
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

    try {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $role  = $body['role'] ?? 'member';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Neplatný email');
        if (!in_array($role, ['admin', 'member', 'viewer'])) jsonError('Neplatná role');

        $db = getDB();

        // Zkontroluj zda uživatel není již členem
        $check = $db->prepare('SELECT pm.id FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? AND u.email = ?');
        $check->execute([$projectId, $email]);
        if ($check->fetch()) jsonError('Uživatel je již členem projektu');

        // Zruš existující čekající pozvánky pro tento email
        $db->prepare('UPDATE invitations SET status = "expired" WHERE project_id = ? AND invited_email = ? AND status = "pending"')
           ->execute([$projectId, $email]);

        // Vytvoř novou pozvánku
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $db->prepare('INSERT INTO invitations (project_id, invited_email, invited_by, token, role, status, expires_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$projectId, $email, $userId, $token, $role, 'pending', $expiresAt]);

        // Odešli email pokud je Brevo nakonfigurovaný a cURL dostupný
        $mailSent = false;
        if (defined('BREVO_API_KEY') && BREVO_API_KEY && function_exists('curl_init')) {
            try {
                $proj = $db->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
                $proj->execute([$projectId]);
                $projName  = $proj->fetch()['name'] ?? 'projekt';
                $inviteUrl = 'https://plans.besix.cz/invite.php?token=' . $token;
                $roleLabel = ['admin'=>'Administrátor','member'=>'Člen','viewer'=>'Pozorovatel'][$role] ?? $role;
                $subject   = "Pozvánka do projektu „{$projName}" – BeSix Plans";
                $html      = "<div style='font-family:sans-serif'><h2>Pozvánka do projektu {$projName}</h2><p>Role: {$roleLabel}</p><a href='{$inviteUrl}'>Přijmout pozvánku</a></div>";
                sendMail($email, $subject, $html);
                $mailSent = true;
            } catch (\Throwable $ex) { }
        }

        jsonOk(['message' => $mailSent ? 'Pozvánka odeslána emailem' : 'Pozvánka vytvořena', 'invite_url' => 'https://plans.besix.cz/invite.php?token=' . $token]);

    } catch (\Throwable $e) {
        jsonError('Chyba: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 500);
    }
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
