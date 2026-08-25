<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/functions.php';

// Support single URL-encoded JSON param ?d=... (avoids & in URL breaking mobile PDF viewers)
// PHP $_GET auto-decodes the URL encoding, so json_decode directly works
if (!empty($_GET['d'])) {
    $parsed    = json_decode($_GET['d'], true) ?: [];
    $projectId = (int)($parsed['p'] ?? 0);
    $name      = trim($parsed['n'] ?? '');
    $token     = $parsed['t'] ?? '';
} else {
    $projectId = (int)($_GET['project_id'] ?? 0);
    $name      = trim($_GET['name'] ?? '');
    $token     = $_GET['token'] ?? '';
}

if (!$projectId || !$name) {
    http_response_code(400);
    ob_end_clean();
    exit('Chybí parametry');
}

// Validate HMAC token (allows shared PDF links without session)
$expectedToken = substr(hash_hmac('sha256', strval($projectId), DB_PASS), 0, 24);
$tokenValid    = $token !== '' && hash_equals($expectedToken, $token);

if (!$tokenValid) {
    // Fall back to session authentication
    $user = requireAuth();
    $membership = getProjectMembership($projectId, $user['id']);
    if (!$membership) {
        http_response_code(403);
        ob_end_clean();
        exit('Nemáš přístup k tomuto projektu');
    }
}

$stmt = getDB()->prepare(
    'SELECT name, firma, emails_json, kontakt, telefon, contacts_json
       FROM plan_profese WHERE project_id = ? AND name = ? LIMIT 1'
);
$stmt->execute([$projectId, $name]);
$p = $stmt->fetch();

if (!$p) {
    http_response_code(404);
    ob_end_clean();
    exit('Profese nenalezena');
}

// Collect all contacts
$contacts = [];
if (!empty($p['contacts_json'])) {
    $decoded = json_decode($p['contacts_json'], true);
    if (is_array($decoded)) $contacts = $decoded;
} elseif ($p['kontakt'] || $p['telefon']) {
    $c = [];
    if ($p['kontakt']) $c['name']    = $p['kontakt'];
    if ($p['telefon']) $c['telefon'] = $p['telefon'];
    $contacts = [$c];
}

// Standalone emails
$standaloneEmails = [];
if ($p['emails_json']) {
    $decoded = json_decode($p['emails_json'], true);
    if (is_array($decoded)) $standaloneEmails = $decoded;
}

// Primary name for FN/ORG
$fn = '';
if (!empty($contacts[0]['name'])) $fn = $contacts[0]['name'];
elseif ($p['firma'])              $fn = $p['firma'];
else                              $fn = $p['name'];

$vcf  = "BEGIN:VCARD\r\n";
$vcf .= "VERSION:3.0\r\n";
$vcf .= "FN:" . _vcfEscape($fn) . "\r\n";
if ($p['firma'])  $vcf .= "ORG:" . _vcfEscape($p['firma']) . "\r\n";
if ($p['name'])   $vcf .= "TITLE:" . _vcfEscape($p['name']) . "\r\n";

foreach ($contacts as $c) {
    if (!empty($c['telefon'])) {
        $vcf .= "TEL;TYPE=CELL:" . preg_replace('/\s+/', '', $c['telefon']) . "\r\n";
    }
    if (!empty($c['name']) && $c['name'] !== $fn) {
        $vcf .= "X-CONTACT-NAME:" . _vcfEscape($c['name']) . "\r\n";
    }
    if (!empty($c['email'])) {
        $vcf .= "EMAIL;TYPE=INTERNET:" . $c['email'] . "\r\n";
    }
}

foreach ($standaloneEmails as $e) {
    if ($e) $vcf .= "EMAIL;TYPE=INTERNET:" . $e . "\r\n";
}

$vcf .= "END:VCARD\r\n";

$filename = preg_replace('/[^a-z0-9_-]/i', '_', $fn) . '.vcf';

ob_end_clean();
header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: no-cache, no-store');
echo $vcf;

function _vcfEscape(string $s): string {
    return str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $s);
}
