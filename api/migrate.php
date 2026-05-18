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

    // ── 1. Copy missing projects from board_projects ──────────────────────
    $missing = $db->query(
        "SELECT bp.id FROM board_projects bp
         LEFT JOIN plan_projects pp ON pp.id = bp.id
         WHERE pp.id IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    $result['missing_project_ids'] = $missing;

    if (!empty($missing)) {
        $ids = implode(',', array_map('intval', $missing));
        $result['projects_copied'] = $db->exec(
            "INSERT IGNORE INTO plan_projects
                 (id, app_id, name, created_by, invite_code, is_active, created_at)
             SELECT id, app_id, name, created_by, invite_code, is_active, created_at
             FROM board_projects WHERE id IN ($ids)"
        );
        $result['members_copied'] = $db->exec(
            "INSERT IGNORE INTO plan_project_members
                 (id, project_id, user_id, role, invited_by, joined_at)
             SELECT bpm.id, bpm.project_id, bpm.user_id, bpm.role, bpm.invited_by, bpm.joined_at
             FROM board_project_members bpm WHERE bpm.project_id IN ($ids)"
        );
    }

    // ── 2. Soft-deleted projects (is_active = 0) ──────────────────────────
    $result['inactive_projects'] = $db->query(
        "SELECT id, app_id, name, created_by, is_active FROM plan_projects WHERE is_active = 0"
    )->fetchAll();

    // ── 3. Fix: restore soft-deleted if ?restore=1 ────────────────────────
    if (($_GET['restore'] ?? '') === '1') {
        $restored = $db->exec("UPDATE plan_projects SET is_active = 1 WHERE is_active = 0");
        $result['restored_count'] = $restored;
    }

    // ── 4. Projects where creator has no membership row ───────────────────
    $result['creator_without_membership'] = $db->query(
        "SELECT p.id, p.name, p.created_by, p.app_id
         FROM plan_projects p
         LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = p.created_by
         WHERE pm.id IS NULL AND p.is_active = 1"
    )->fetchAll();

    // ── 5. Fix: add missing creator memberships if ?fix_members=1 ─────────
    if (($_GET['fix_members'] ?? '') === '1') {
        $orphans = $db->query(
            "SELECT p.id, p.created_by FROM plan_projects p
             LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = p.created_by
             WHERE pm.id IS NULL AND p.is_active = 1"
        )->fetchAll();
        $added = 0;
        foreach ($orphans as $o) {
            $db->prepare(
                "INSERT IGNORE INTO plan_project_members (project_id, user_id, role, invited_by) VALUES (?,?,'owner',?)"
            )->execute([$o['id'], $o['created_by'], $o['created_by']]);
            $added++;
        }
        $result['creator_memberships_added'] = $added;
    }

    // ── 6. Full project list ──────────────────────────────────────────────
    $result['plan_projects_all'] = $db->query(
        "SELECT p.id, p.app_id, p.name, p.created_by, p.is_active,
                COUNT(pm.id) as member_count
         FROM plan_projects p
         LEFT JOIN plan_project_members pm ON pm.project_id = p.id
         GROUP BY p.id ORDER BY p.id DESC"
    )->fetchAll();

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['file']  = basename($e->getFile());
    $result['line']  = $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
