<?php
// ============================================================
// BESIX Platform – plans.besix.cz
// Stejná DB jako board.besix.cz → sdílení uživatelů
// ============================================================

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'besixcz');
define('DB_USER', 'besixcz001');
define('DB_PASS', '');           // ← doplň stejné heslo jako board

define('MAIL_FROM',  'Noreply@besix.cz');
define('APP_URL',    'https://plans.besix.cz');

define('GOOGLE_CLIENT_ID',     '257797351627-ha61f9rgcgn49ljmm8gudv4adtr9cif9.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '');  // ← doplň stejné jako board
define('GOOGLE_REDIRECT_URI',  'https://plans.besix.cz/api/auth.php?action=google_callback');

define('PLANS_APP_KEY', 'plans');

// PHP session – sdílená přes celou doménu .besix.cz
// DŮLEŽITÉ: stejné nastavení musí být i v board.besix.cz/api/config.php!
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_domain',   '.besix.cz');  // ← klíč pro sdílení session
session_name('BESIX_SESS');
if (session_status() === PHP_SESSION_NONE) session_start();

header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
