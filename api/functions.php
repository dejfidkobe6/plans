<?php
require_once __DIR__ . '/config.php';

// ============================================================
// DB
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES   => false]
    );
    // Automatické migrace – spustí se pouze jednou za životnost $pdo
    require_once __DIR__ . '/migrations.php';
    runMigrations($pdo);
    return $pdo;
}

// ============================================================
// JSON responses
// ============================================================
function jsonOk(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ============================================================
// Remember-me persistent cookie (token stored in DB)
// ============================================================
define('REMEMBER_COOKIE', 'BESIX_REM');
define('REMEMBER_DAYS',   30);

/** Issue a new remember-me cookie and store its hash in DB.
 *  Silently skips on any DB error (login still succeeds). */
function setRememberCookie(int $userId): void {
    try {
        $db    = getDB();
        $token = bin2hex(random_bytes(32));   // 64 hex chars
        $hash  = hash('sha256', $token);
        $exp   = date('Y-m-d H:i:s', time() + 86400 * REMEMBER_DAYS);

        // Remove all existing tokens for this user + any expired tokens
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()")
           ->execute([$userId]);

        $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)")
           ->execute([$userId, $hash, $exp]);

        setcookie(REMEMBER_COOKIE, $userId . ':' . $token, [
            'expires'  => time() + 86400 * REMEMBER_DAYS,
            'path'     => '/',
            'domain'   => '.besix.cz',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (\Throwable $e) {
        // Remember token is non-critical – login still succeeds without it
        error_log('setRememberCookie failed: ' . $e->getMessage());
    }
}

/**
 * Validate the remember-me cookie.
 * Returns the user row on success (and rotates the token), null otherwise.
 */
function checkRememberCookie(): ?array {
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$raw) return null;

    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2) return null;
    [$userId, $token] = $parts;
    if (!ctype_digit($userId) || strlen($token) !== 64) return null;

    try {
        $hash = hash('sha256', $token);
        $stmt = getDB()->prepare(
            'SELECT rt.user_id, u.name, u.email, u.avatar_color
               FROM remember_tokens rt
               JOIN users u ON u.id = rt.user_id
              WHERE rt.user_id = ? AND rt.token_hash = ? AND rt.expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([(int)$userId, $hash]);
        $user = $stmt->fetch();
    } catch (\Throwable $e) {
        return null; // table not yet created on first deploy
    }

    if (!$user) return null;

    // Rotate: issue a fresh token (old one deleted inside setRememberCookie)
    setRememberCookie((int)$user['user_id']);
    return $user;
}

/** Expire the cookie in the browser and revoke its DB record. */
function clearRememberCookie(): void {
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($raw) {
        $parts = explode(':', $raw, 2);
        if (count($parts) === 2 && ctype_digit($parts[0]) && strlen($parts[1]) === 64) {
            try {
                $hash = hash('sha256', $parts[1]);
                getDB()->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")
                       ->execute([$hash]);
            } catch (\Throwable $e) {}
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '.besix.cz',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ============================================================
// Auth – PHP native session (sdílená s board.besix.cz)
// Session je nastartovaná v config.php
// ============================================================
function requireAuth(): array {
    $user = $_SESSION['user'] ?? null;
    if (!$user || empty($user['id'])) {
        // Session expired or missing – fall back to remember-me cookie
        $remembered = checkRememberCookie();
        if ($remembered) {
            loginSession($remembered);
            $user = $_SESSION['user'];
        } else {
            jsonError('Nepřihlášen', 401);
        }
    }
    return $user; // ['id', 'name', 'email', 'avatar_color']
}

function loginSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'avatar_color' => $user['avatar_color'] ?? '#4A5340',
    ];
}

function logoutSession(): void {
    clearRememberCookie();   // revoke persistent token before destroying session
    $_SESSION = [];
    session_destroy();
}

// ============================================================
// Plans app ID
// ============================================================
function getPlansAppId(): int {
    static $id = null;
    if ($id) return $id;
    $row = getDB()->query("SELECT id FROM apps WHERE app_key = '" . PLANS_APP_KEY . "' LIMIT 1")->fetch();
    if (!$row) jsonError('Plans app není registrována v DB. Spusť setup.sql.', 500);
    $id = (int)$row['id'];
    return $id;
}

// ============================================================
// Project membership helper
// ============================================================
function getProjectMembership(int $projectId, int $userId): array|false {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$projectId, $userId]);
    $row = $stmt->fetch();
    if ($row) return $row;

    // Fallback: pokud je user creator projektu, auto-migruj ho do project_members
    $check = $db->prepare('SELECT id FROM projects WHERE id = ? AND created_by = ? AND is_active = 1 LIMIT 1');
    $check->execute([$projectId, $userId]);
    if ($check->fetch()) {
        $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, role, invited_by) VALUES (?,?,"owner",?)')
           ->execute([$projectId, $userId, $userId]);
        return ['id' => 0, 'role' => 'owner'];
    }
    return false;
}

// ============================================================
// Email – Brevo API (file_get_contents, no cURL dependency)
// ============================================================
function sendMail(string $to, string $subject, string $htmlBody): bool {
    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@besix.cz';

    if (defined('BREVO_API_KEY') && BREVO_API_KEY !== '') {
        $payload = json_encode([
            'sender'      => ['name' => 'BeSix Plans', 'email' => $from],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'api-key: ' . BREVO_API_KEY,
            ]),
            'content'       => $payload,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
        if ($result !== false) {
            $json = json_decode($result, true);
            if (isset($json['messageId'])) return true;
        }
        error_log('Brevo API error: ' . ($result ?: 'no response'));
        return false;
    }

    // Fallback: PHP mail()
    $headers  = "From: BeSix Plans <{$from}>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\nMIME-Version: 1.0\r\n";
    return (bool)@mail($to, $subject, $htmlBody, $headers);
}
