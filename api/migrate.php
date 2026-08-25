<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$result = ['ok' => false];

try {
    require_once __DIR__ . '/functions.php';
    $db = getDB();

    // ── Kdo jsem? (session) ───────────────────────────────────────────────
    $sessionUser = $_SESSION['user'] ?? null;
    $result['session_user'] = $sessionUser;

    $appId = getPlansAppId();
    $result['plans_app_id'] = $appId;

    if ($sessionUser) {
        $userId = (int)$sessionUser['id'];
        $result['my_user_id'] = $userId;

        // Projekty které vidím (stejná query jako projects.php GET)
        $stmt = $db->prepare('
            SELECT p.id, p.name, p.created_at, pm.role
            FROM plan_projects p
            JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = ?
            WHERE p.app_id = ? AND p.is_active = 1
            ORDER BY p.created_at DESC
        ');
        $stmt->execute([$userId, $appId]);
        $result['my_visible_projects'] = $stmt->fetchAll();

        // Všechny projekty kde jsem member (bez app_id filtru)
        $stmt2 = $db->prepare('
            SELECT p.id, p.app_id, p.name, pm.role
            FROM plan_projects p
            JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = ?
            WHERE p.is_active = 1
        ');
        $stmt2->execute([$userId]);
        $result['my_all_memberships'] = $stmt2->fetchAll();

        // Projekty kde jsem creator ale nemám členství
        $stmt3 = $db->prepare('
            SELECT p.id, p.name, p.app_id
            FROM plan_projects p
            LEFT JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = ?
            WHERE p.created_by = ? AND p.is_active = 1 AND pm.id IS NULL
        ');
        $stmt3->execute([$userId, $userId]);
        $result['my_projects_without_membership'] = $stmt3->fetchAll();

    } else {
        $result['note'] = 'Nejsi přihlášen – otevři tuto stránku jako přihlášený uživatel v aplikaci';
    }

    // ── Celkový stav tabulek ──────────────────────────────────────────────
    $result['plan_projects_total']        = (int)$db->query("SELECT COUNT(*) FROM plan_projects WHERE is_active=1")->fetchColumn();
    $result['plan_project_members_total'] = (int)$db->query("SELECT COUNT(*) FROM plan_project_members")->fetchColumn();

    // ── FIX: přidat chybějící membershipy creator → owner ────────────────
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

    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    $result['file']  = basename($e->getFile());
    $result['line']  = $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
