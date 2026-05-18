<?php
header('Content-Type: application/json');
$result = [];
try {
    require_once __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $result['db'] = 'ok';

    // All tables
    $result['tables'] = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    // Key tables check
    foreach (['apps','projects','project_members','plan_canvas_data','plan_backgrounds'] as $t) {
        $result['exists'][$t] = in_array($t, $result['tables']);
    }

    // projects table columns
    if (in_array('projects', $result['tables'])) {
        $cols = $pdo->query("SHOW COLUMNS FROM projects")->fetchAll();
        $result['projects_columns'] = array_column($cols, 'Field');
        $result['projects_count']   = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        $result['projects_app_ids'] = $pdo->query("SELECT DISTINCT app_id FROM projects")->fetchAll(PDO::FETCH_COLUMN);
    }

    // project_members columns
    if (in_array('project_members', $result['tables'])) {
        $cols = $pdo->query("SHOW COLUMNS FROM project_members")->fetchAll();
        $result['project_members_columns'] = array_column($cols, 'Field');
        $result['project_members_count']   = $pdo->query("SELECT COUNT(*) FROM project_members")->fetchColumn();
    }

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}
echo json_encode($result, JSON_PRETTY_PRINT);
