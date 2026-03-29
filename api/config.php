<?php
// ============================================================
// BESIX Platform – sdílená konfigurace (stejné hodnoty jako board.besix.cz)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', '');          // ← doplň stejné jako board
define('DB_USER', '');          // ← doplň stejné jako board
define('DB_PASS', '');          // ← doplň stejné jako board
define('DB_CHARSET', 'utf8mb4');

define('PLANS_APP_KEY', 'plans');

// Cookie – musí být shodné s board.besix.cz!
define('SESSION_COOKIE',  'BESIX_SESS');
define('COOKIE_DOMAIN',   '.besix.cz');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 dní

// Google OAuth – stejné klienty jako board (nebo vlastní)
define('GOOGLE_CLIENT_ID',     '');  // ← doplň
define('GOOGLE_CLIENT_SECRET', '');  // ← doplň
define('GOOGLE_REDIRECT_URI',  'https://plans.besix.cz/api/auth.php?action=google_callback');
