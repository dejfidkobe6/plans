<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$result = ['ok' => false];

try {
    require_once __DIR__ . '/functions.php';
    $db    = getDB();
    $appId = getPlansAppId();
    $result['plans_app_id'] = $appId;

    // Count before
    $result['plan_projects_before']         = (int)$db->query("SELECT COUNT(*) FROM plan_projects")->fetchColumn();
    $result['plan_project_members_before']  = (int)$db->query("SELECT COUNT(*) FROM plan_project_members")->fetchColumn();

    // Missing projects from board_projects
    $missing = $db->query(
        "SELECT bp.id FROM board_projects bp
         LEFT JOIN plan_projects pp ON pp.id = bp.id
         WHERE pp.id IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    $result['missing_project_ids'] = $missing;

    if (!empty($missing)) {
        // Copy missing projects
        $copied = $db->exec(
            "INSERT IGNORE INTO plan_projects
                 (id, app_id, name, created_by, invite_code, is_active, created_at)
             SELECT id, app_id, name, created_by, invite_code, is_active, created_at
             FROM board_projects
             WHERE id IN (" . implode(',', array_map('intval', $missing)) . ")"
        );
        $result['projects_copied'] = $copied;

        // Copy their members
        $copied_members = $db->exec(
            "INSERT IGNORE INTO plan_project_members
                 (id, project_id, user_id, role, invited_by, joined_at)
             SELECT bpm.id, bpm.project_id, bpm.user_id, bpm.role, bpm.invited_by, bpm.joined_at
             FROM board_project_members bpm
             WHERE bpm.project_id IN (" . implode(',', array_map('intval', $missing)) . ")"
        );
        $result['members_copied'] = $copied_members;
    } else {
        $result['projects_copied'] = 0;
        $result['members_copied']  = 0;
        $result['note'] = 'No missing projects found';
    }

    // Count after
    $result['plan_projects_after']        = (int)$db->query("SELECT COUNT(*) FROM plan_projects")->fetchColumn();
    $result['plan_project_members_after'] = (int)$db->query("SELECT COUNT(*) FROM plan_project_members")->fetchColumn();

    // List all plan_projects now
    $result['plan_projects_list'] = $db->query(
        "SELECT id, app_id, name, created_by, is_active FROM plan_projects ORDER BY id DESC"
    )->fetchAll();

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['file']  = basename($e->getFile());
    $result['line']  = $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
