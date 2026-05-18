<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$result = ['ok' => false];

try {
    require_once __DIR__ . '/functions.php';
    $db = getDB();

    $result['current_plans_app_id'] = getPlansAppId(); // should be 1

    // ── Diagnose ──────────────────────────────────────────────────────────
    $result['projects_by_app_id'] = $db->query(
        "SELECT app_id, COUNT(*) as total,
                GROUP_CONCAT(CONCAT(id,':',name) ORDER BY id SEPARATOR ' | ') as projects
         FROM plan_projects GROUP BY app_id ORDER BY app_id"
    )->fetchAll();

    $result['app_ids_with_canvas_data'] = $db->query(
        "SELECT DISTINCT p.app_id FROM plan_projects p
         INNER JOIN plan_canvas_data c ON c.project_id = p.id"
    )->fetchAll(PDO::FETCH_COLUMN);

    $result['creator_without_membership'] = $db->query(
        "SELECT p.id, p.name, p.created_by, p.app_id
         FROM plan_projects p
         LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = p.created_by
         WHERE pm.id IS NULL AND p.is_active = 1"
    )->fetchAll();

    // ── FIX A: normalize orphan app_ids to 1 (?fix_appid=1) ──────────────
    // Moves projects with app_id NOT IN apps table → app_id=1 (plans)
    if (($_GET['fix_appid'] ?? '') === '1') {
        $orphanAppIds = $db->query(
            "SELECT DISTINCT pp.app_id FROM plan_projects pp
             LEFT JOIN apps a ON a.id = pp.app_id
             WHERE a.id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($orphanAppIds)) {
            $ids = implode(',', array_map('intval', $orphanAppIds));
            $updated = $db->exec(
                "UPDATE plan_projects SET app_id = 1 WHERE app_id IN ($ids)"
            );
            $result['projects_app_id_fixed'] = $updated;
            $result['orphan_app_ids_fixed']  = $orphanAppIds;
        } else {
            $result['projects_app_id_fixed'] = 0;
            $result['note_appid'] = 'No orphan app_ids found';
        }
    }

    // ── FIX B: add missing creator memberships (?fix_members=1) ──────────
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

    // ── Summary after fixes ───────────────────────────────────────────────
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
