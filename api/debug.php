<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$result = ['php' => PHP_VERSION, 'ok' => false];

try {
    require_once __DIR__ . '/functions.php';
    $result['config_loaded'] = true;
    $result['session_user']  = $_SESSION['user'] ?? null;

    $db = getDB();
    $result['db'] = 'connected';

    // List all tables in DB
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $result['tables'] = $tables;

    // Check key tables
    foreach (['apps','plan_projects','plan_project_members','plan_canvas_data','plan_backgrounds'] as $tbl) {
        $result['table_exists'][$tbl] = in_array($tbl, $tables);
    }

    // Count projects rows
    try {
        $result['projects_count'] = $db->query("SELECT COUNT(*) FROM plan_projects")->fetchColumn();
        $result['projects_app_ids'] = $db->query("SELECT DISTINCT app_id FROM plan_projects")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Exception $e) { $result['projects_error'] = $e->getMessage(); }

    try { $app = $db->query("SELECT id, app_key FROM apps WHERE app_key = 'plans' LIMIT 1")->fetch(); } catch (\Exception $e) { $app = false; }
    $result['plans_app'] = $app ?: 'NOT FOUND';

    $appId = getPlansAppId();
    $result['plans_app_id'] = $appId;

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['file']  = basename($e->getFile());
    $result['line']  = $e->getLine();
    $result['trace'] = array_slice(array_map(fn($t) => ($t['file'] ?? '').'#'.($t['line'] ?? ''), $e->getTrace()), 0, 5);
}

echo json_encode($result, JSON_PRETTY_PRINT);
