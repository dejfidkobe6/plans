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
        echo json_encode(['ok'=>false,'error'=>$err['message'],'fatal'=>true,'file'=>basename($err['file']),'line'=>$err['line']]);
    }
});
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
// POST – přidat registrovaného uživatele do projektu + odeslat notifikaci
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

        // Zkontroluj zda je uživatel registrován v databázi BeSix platformy
        $userCheck = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $userCheck->execute([$email]);
        $registeredUser = $userCheck->fetch();

        if (!$registeredUser) {
            ob_clean();
            http_response_code(422);
            echo json_encode([
                'ok'             => false,
                'error'          => 'Uživatel s tímto emailem není registrován v BeSix platformě. Pozvěte ho nejdříve na besix.cz.',
                'not_registered' => true,
            ]);
            exit;
        }

        $invitedUserId = (int)$registeredUser['id'];

        // Zkontroluj zda uživatel není již členem projektu
        $check = $db->prepare('SELECT id FROM project_members WHERE project_id = ? AND user_id = ?');
        $check->execute([$projectId, $invitedUserId]);
        if ($check->fetch()) jsonError('Uživatel je již členem projektu');

        // Přidej uživatele přímo do projektu
        $db->prepare('INSERT INTO project_members (project_id, user_id, role, invited_by) VALUES (?,?,?,?)')
           ->execute([$projectId, $invitedUserId, $role, $userId]);

        // Odešli notifikační email pokud je Brevo nakonfigurovaný
        $mailSent = false;
        if (defined('BREVO_API_KEY') && BREVO_API_KEY) {
            try {
                $proj = $db->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
                $proj->execute([$projectId]);
                $projName  = htmlspecialchars($proj->fetch()['name'] ?? 'projekt', ENT_QUOTES, 'UTF-8');
                $roleLabel = htmlspecialchars(['admin'=>'Administrátor','member'=>'Člen','viewer'=>'Pozorovatel'][$role] ?? $role, ENT_QUOTES, 'UTF-8');
                $appUrl    = 'https://plans.besix.cz';
                $appUrlEsc = htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8');
                $subject   = "Byl(a) jste přidán(a) do projektu {$projName} – BeSix Plans";
                $html      = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background-color:#111111;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#111111;">
  <tr><td align="center" style="padding:48px 16px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:420px;background-color:#1a1a1a;border-radius:12px;overflow:hidden;">

      <!-- Logo hlavička -->
      <tr><td align="center" style="padding:40px 32px 28px;">
        <img src="https://plans.besix.cz/besix_logo_bila.png" alt="BeSix" width="72" height="72"
             style="display:block;margin:0 auto 14px;">
        <div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:1px;line-height:1;">BeSix</div>
        <div style="font-size:10px;font-weight:700;color:#ffffff;letter-spacing:10px;margin-top:4px;opacity:.7;">PLANS</div>
      </td></tr>

      <!-- Oddělovač -->
      <tr><td style="padding:0 32px;"><div style="height:1px;background:#282828;"></div></td></tr>

      <!-- Tělo -->
      <tr><td style="padding:32px 32px 8px;">
        <p style="margin:0 0 6px;color:#888888;font-size:12px;text-transform:uppercase;letter-spacing:1.5px;">Přidání do projektu</p>
        <h2 style="margin:0 0 28px;color:#ffffff;font-size:22px;font-weight:700;">{$projName}</h2>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
          <tr><td style="padding:14px 16px;background:#222222;border-radius:8px;border-left:3px solid #6b7c3a;">
            <div style="font-size:10px;color:#666666;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px;">Vaše role</div>
            <div style="font-size:16px;color:#ffffff;font-weight:600;">{$roleLabel}</div>
          </td></tr>
        </table>

        <p style="margin:0 0 28px;color:#777777;font-size:13px;line-height:1.7;">
          Byl(a) jste přidán(a) do výše uvedeného projektu. Klikněte na tlačítko níže pro přístup do aplikace.
        </p>

        <!-- Tlačítko -->
        <a href="{$appUrlEsc}"
           style="display:block;text-align:center;background-color:#6b7c3a;color:#ffffff;text-decoration:none;
                  padding:15px 24px;border-radius:7px;font-size:15px;font-weight:700;letter-spacing:.4px;">
          Přejít do BeSix Plans
        </a>
      </td></tr>

      <!-- Patička -->
      <tr><td style="padding:24px 32px 36px;">
        <div style="height:1px;background:#282828;margin-bottom:20px;"></div>
        <p style="margin:0;color:#444444;font-size:11px;text-align:center;line-height:1.8;">
          Pokud tuto zprávu neočekáváte, ignorujte tento email.
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
                sendMail($email, $subject, $html);
                $mailSent = true;
            } catch (\Throwable $ex) { }
        }

        jsonOk(['message' => $mailSent ? 'Uživatel přidán a notifikace odeslána emailem' : 'Uživatel přidán do projektu']);

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
