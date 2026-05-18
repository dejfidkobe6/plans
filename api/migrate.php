<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$result = ['ok' => false];

try {
    require_once __DIR__ . '/functions.php';
    $db = getDB();

    // ── Apps table ────────────────────────────────────────────────────────
    try {
        $result['apps_table'] = $db->query("SELECT * FROM apps ORDER BY id")->fetchAll();
    } catch (\Exception $e) {
        $result['apps_table_error'] = $e->getMessage();
    }

    $result['current_plans_app_id'] = getPlansAppId();

    // ── Projects grouped by app_id ────────────────────────────────────────
    $result['projects_by_app_id'] = $db->query(
        "SELECT app_id, COUNT(*) as total,
                SUM(is_active) as active,
                SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) as inactive,
                GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ') as names
         FROM plan_projects GROUP BY app_id ORDER BY app_id"
    )->fetchAll();

    // ── Which app_ids have plans-specific data ────────────────────────────
    try {
        $result['app_ids_with_canvas_data'] = $db->query(
            "SELECT DISTINCT p.app_id FROM plan_projects p
             INNER JOIN plan_canvas_data c ON c.project_id = p.id"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Exception $e) { $result['canvas_data_error'] = $e->getMessage(); }

    try {
        $result['app_ids_with_backgrounds'] = $db->query(
            "SELECT DISTINCT p.app_id FROM plan_projects p
             INNER JOIN plan_backgrounds b ON b.project_id = p.id"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Exception $e) { $result['backgrounds_error'] = $e->getMessage(); }

    // ── Creator without membership (all app_ids) ──────────────────────────
    $result['creator_without_membership'] = $db->query(
        "SELECT p.id, p.name, p.created_by, p.app_id
         FROM plan_projects p
         LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = p.created_by
         WHERE pm.id IS NULL AND p.is_active = 1"
    )->fetchAll();

    // ── FIX: add missing creator memberships (?fix_members=1) ────────────
    if (($_GET['fix_members'] ?? '') === '1') {
        $orphans = $db->query(
            "SELECT p.id, p.created_by FROM plan_projects p
             LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = p.created_by
             WHERE pm.id IS NULL AND p.is_active = 1"
        )->fetchAll();
        $added = 0;
        foreach ($orphans as $o) {
            $db->prepare(
                "INSERT IGNORE INTO plan_project_members (project_id, user_id, role, invited_by)
                 VALUES (?,?,'owner',?)"
            )->execute([$o['id'], $o['created_by'], $o['created_by']]);
            $added++;
        }
        $result['creator_memberships_added'] = $added;
    }

    // ── FIX: normalize all plans projects to app_id=1 (?normalize_appid=1) ─
    // Sets app_id=1 for all projects that have plan_canvas_data or plan_backgrounds,
    // plus all projects already at app_id=1.
    if (($_GET['normalize_appid'] ?? '') === '1') {
        try {
            $updated = $db->exec(
                "UPDATE plan_projects SET app_id = 1
                 WHERE id IN (
                     SELECT DISTINCT project_id FROM plan_canvas_data
                     UNION
                     SELECT DISTINCT project_id FROM plan_backgrounds
                 ) AND app_id != 1"
            );
            $result['projects_normalized'] = $updated;
        } catch (\Exception $e) {
            $result['normalize_error'] = $e->getMessage();
        }
    }

    // ── Full project list ─────────────────────────────────────────────────
    $result['plan_projects_all'] = $db->query(
        "SELECT p.id, p.app_id, p.name, p.created_by, p.is_active,
                COUNT(pm.id) as member_count
         FROM plan_projects p
         LEFT JOIN plan_project_members pm ON pm.project_id = p.id
         GROUP BY p.id ORDER BY p.app_id, p.id DESC"
    )->fetchAll();

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['file']  = basename($e->getFile());
    $result['line']  = $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
