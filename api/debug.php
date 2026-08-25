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

    // plan_projects diagnostic
    try {
        $result['plan_projects_count']   = $db->query("SELECT COUNT(*) FROM plan_projects")->fetchColumn();
        $result['plan_projects_app_ids'] = $db->query("SELECT DISTINCT app_id FROM plan_projects")->fetchAll(PDO::FETCH_COLUMN);
        $result['plan_projects_list']    = $db->query("SELECT id, app_id, name, created_by, is_active FROM plan_projects ORDER BY id DESC LIMIT 50")->fetchAll();
    } catch (\Exception $e) { $result['plan_projects_error'] = $e->getMessage(); }

    // board_projects diagnostic (source for migration)
    try {
        $result['board_projects_count']   = $db->query("SELECT COUNT(*) FROM board_projects")->fetchColumn();
        $result['board_projects_app_ids'] = $db->query("SELECT DISTINCT app_id FROM board_projects")->fetchAll(PDO::FETCH_COLUMN);
        $result['board_projects_list']    = $db->query("SELECT id, app_id, name, created_by, is_active FROM board_projects ORDER BY id DESC LIMIT 50")->fetchAll();
    } catch (\Exception $e) { $result['board_projects_error'] = $e->getMessage(); }

    // Missing projects (in board_projects but not in plan_projects)
    try {
        $result['missing_in_plan_projects'] = $db->query(
            "SELECT bp.id, bp.app_id, bp.name FROM board_projects bp
             LEFT JOIN plan_projects pp ON pp.id = bp.id
             WHERE pp.id IS NULL"
        )->fetchAll();
    } catch (\Exception $e) { $result['missing_projects_error'] = $e->getMessage(); }

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
