<?php
require_once __DIR__ . '/functions.php';
setCorsHeaders();

$action = $_GET['action'] ?? '';

// ============================================================
// GET /api/auth.php?action=me
// ============================================================
if ($action === 'me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = requireAuth();
    jsonOk(['user' => $user]);
}

// ============================================================
// POST /api/auth.php?action=login
// Body: { email, password }
// ============================================================
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$email || !$password) jsonError('Vyplňte email a heslo');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, avatar_color, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['password_hash'] === '!google') jsonError('Nesprávný email nebo heslo');
    if (!password_verify($password, $user['password_hash']))  jsonError('Nesprávný email nebo heslo');

    $token = createSession((int)$user['id']);
    setSessionCookie($token);

    jsonOk(['user' => [
        'id'           => $user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'avatar_color' => $user['avatar_color'],
    ]]);
}

// ============================================================
// POST /api/auth.php?action=register
// Body: { name, email, password }
// ============================================================
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name     = trim($body['name'] ?? '');
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$name)                       jsonError('Vyplňte jméno');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Neplatný email');
    if (strlen($password) < 6)        jsonError('Heslo musí mít alespoň 6 znaků');

    $db   = getDB();
    $exists = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) jsonError('Tento email je již zaregistrován');

    $colors = ['#4A5340','#3B82F6','#8B5CF6','#F59E0B','#10B981','#EF4444','#EC4899'];
    $avatar_color = $colors[array_rand($colors)];
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $db->prepare('INSERT INTO users (name, email, password_hash, avatar_color) VALUES (?, ?, ?, ?)')
       ->execute([$name, $email, $hash, $avatar_color]);
    $userId = (int)$db->lastInsertId();

    $token = createSession($userId);
    setSessionCookie($token);

    jsonOk(['user' => [
        'id'           => $userId,
        'name'         => $name,
        'email'        => $email,
        'avatar_color' => $avatar_color,
    ]]);
}

// ============================================================
// POST /api/auth.php?action=logout
// ============================================================
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    destroySession();
    jsonOk();
}

// ============================================================
// Google OAuth – redirect
// ============================================================
if ($action === 'google_redirect' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!GOOGLE_CLIENT_ID) jsonError('Google OAuth není nakonfigurováno');
    $state = bin2hex(random_bytes(16));
    setcookie('oauth_state', $state, ['expires' => time()+600, 'path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ============================================================
// Google OAuth – callback
// ============================================================
if ($action === 'google_callback' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = $_GET['state'] ?? '';
    if (!$state || $state !== ($_COOKIE['oauth_state'] ?? '')) jsonError('Neplatný OAuth state');

    $code = $_GET['code'] ?? '';
    if (!$code) jsonError('Chybí OAuth kód');

    // Vyměníme kód za token
    $resp = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
    ]]));
    $tokens = json_decode($resp, true);
    if (empty($tokens['id_token'])) jsonError('Google OAuth selhal');

    // Dekódujeme id_token (payload je base64url)
    $parts   = explode('.', $tokens['id_token']);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    $googleId = $payload['sub']   ?? null;
    $email    = strtolower($payload['email'] ?? '');
    $name     = $payload['name']  ?? $email;

    if (!$googleId || !$email) jsonError('Nepodařilo se získat data z Google');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE google_id = ? OR email = ? LIMIT 1');
    $stmt->execute([$googleId, $email]);
    $user = $stmt->fetch();

    if ($user) {
        // Aktualizujeme google_id pokud ještě není
        $db->prepare('UPDATE users SET google_id = ?, password_hash = "!google" WHERE id = ?')
           ->execute([$googleId, $user['id']]);
        $userId = (int)$user['id'];
    } else {
        $colors = ['#4A5340','#3B82F6','#8B5CF6','#F59E0B','#10B981','#EF4444','#EC4899'];
        $avatar_color = $colors[array_rand($colors)];
        $db->prepare('INSERT INTO users (name, email, google_id, password_hash, avatar_color) VALUES (?,?,?,"!google",?)')
           ->execute([$name, $email, $googleId, $avatar_color]);
        $userId = (int)$db->lastInsertId();
    }

    $token = createSession($userId);
    setSessionCookie($token);
    header('Location: https://plans.besix.cz/');
    exit;
}

jsonError('Neznámá akce', 404);
