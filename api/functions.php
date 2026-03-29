<?php
require_once __DIR__ . '/config.php';

// ============================================================
// DB
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ============================================================
// JSON responses
// ============================================================
function jsonOk(array $data = []): never {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + $data);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ============================================================
// Session / Auth  (sdílené s board.besix.cz přes BESIX_SESS cookie)
// ============================================================
function getSessionToken(): ?string {
    return $_COOKIE[SESSION_COOKIE] ?? null;
}

function requireAuth(): array {
    $token = getSessionToken();
    if (!$token) jsonError('Nepřihlášen', 401);

    $db = getDB();
    $stmt = $db->prepare('
        SELECT u.id, u.name, u.email, u.avatar_color
        FROM sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.token = ? AND s.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Relace vypršela', 401);

    // Prodloužit platnost session
    $db->prepare('UPDATE sessions SET expires_at = DATE_ADD(NOW(), INTERVAL ' . SESSION_LIFETIME . ' SECOND) WHERE token = ?')
       ->execute([$token]);

    return $user;
}

function createSession(int $userId): string {
    $token = bin2hex(random_bytes(32)); // 64 znakový hex token
    $db = getDB();
    $db->prepare('
        INSERT INTO sessions (user_id, token, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . SESSION_LIFETIME . ' SECOND))
    ')->execute([$userId, $token]);
    return $token;
}

function setSessionCookie(string $token): void {
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function destroySession(): void {
    $token = getSessionToken();
    if ($token) {
        getDB()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }
    setcookie(SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ============================================================
// Plans app ID helper
// ============================================================
function getPlansAppId(): int {
    static $id = null;
    if ($id) return $id;
    $row = getDB()->query("SELECT id FROM apps WHERE app_key = '" . PLANS_APP_KEY . "' LIMIT 1")->fetch();
    if (!$row) throw new RuntimeException('Plans app not found in apps table. Run setup.sql.');
    $id = (int)$row['id'];
    return $id;
}

// ============================================================
// CORS – povolíme plans.besix.cz (same-origin, cookie sdílíme přes .besix.cz)
// ============================================================
function setCorsHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (preg_match('#^https://[a-z0-9-]+\.besix\.cz$#', $origin)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
