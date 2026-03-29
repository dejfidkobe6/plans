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
// Auth – PHP native session (sdílená s board.besix.cz)
// Session je nastartovaná v config.php
// ============================================================
function requireAuth(): array {
    $user = $_SESSION['user'] ?? null;
    if (!$user || empty($user['id'])) {
        jsonError('Nepřihlášen', 401);
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
// Email – Brevo API
// ============================================================
function sendMail(string $to, string $subject, string $htmlBody): void {
    $payload = json_encode([
        'sender'     => ['name' => 'BeSix Plans', 'email' => MAIL_FROM],
        'to'         => [['email' => $to]],
        'subject'    => $subject,
        'htmlContent'=> $htmlBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
