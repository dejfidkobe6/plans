<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$err['message'],'file'=>basename($err['file']),'line'=>$err['line']]);
    }
});
set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
    exit;
});

require_once __DIR__ . '/functions.php';

$user      = requireAuth();
$userId    = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];

if (!$projectId) jsonError('Chybí project_id');
$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

getDB()->exec('CREATE TABLE IF NOT EXISTS plan_profese (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    project_id    INT          NOT NULL,
    name          VARCHAR(255) NOT NULL,
    firma         VARCHAR(255) DEFAULT NULL,
    emails_json   TEXT         DEFAULT NULL,
    kontakt       VARCHAR(255) DEFAULT NULL,
    telefon       VARCHAR(100) DEFAULT NULL,
    color         VARCHAR(32)  DEFAULT NULL,
    export_pinned TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order    INT          NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proj_name (project_id, name),
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

// ── GET ────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['ts_only'])) {
        $stmt = getDB()->prepare('SELECT MAX(updated_at) AS updated_at FROM plan_profese WHERE project_id = ?');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        jsonOk(['updated_at' => $row['updated_at'] ?? null]);
    }
    $rows = _profLoad($projectId);
    jsonOk(['profese' => _rowsToProfese($rows)]);
}

// ── POST ───────────────────────────────────────────────────────
if ($method === 'POST') {
    if (!in_array($membership['role'], ['owner','admin','member'])) {
        jsonError('Nemáš oprávnění upravovat profesí', 403);
    }
    $rawJson = $_POST['data'] ?? '';
    if (!$rawJson) $rawJson = file_get_contents('php://input');
    if (!$rawJson) jsonError('Prázdné tělo požadavku', 400);

    $body = json_decode($rawJson, true);
    if ($body === null) jsonError('Neplatný JSON: ' . json_last_error_msg(), 400);

    $incoming = $body['profese'] ?? null;
    $deleted  = $body['deleted']  ?? [];
    if (!is_array($incoming)) jsonError('profese musí být pole', 400);
    if (!is_array($deleted))  $deleted = [];

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT name, firma, emails_json, kontakt, telefon, color, export_pinned, sort_order
               FROM plan_profese WHERE project_id = ? FOR UPDATE ORDER BY sort_order, name'
        );
        $stmt->execute([$projectId]);
        $existing = $stmt->fetchAll();

        $existingByName = [];
        foreach ($existing as $e) $existingByName[$e['name']] = $e;

        // Explicit deletions (user clicked "Smazat")
        if (!empty($deleted)) {
            $ph = implode(',', array_fill(0, count($deleted), '?'));
            $db->prepare("DELETE FROM plan_profese WHERE project_id = ? AND name IN ($ph)")
               ->execute(array_merge([$projectId], $deleted));
            foreach ($deleted as $dn) unset($existingByName[$dn]);
        }

        $incomingByName = [];
        foreach ($incoming as $p) {
            $n = $p['name'] ?? null;
            if ($n !== null) $incomingByName[$n] = $p;
        }

        // Merge: client entries + server-only entries not explicitly deleted
        $toUpsert = $incoming;
        foreach ($existingByName as $name => $e) {
            if (!isset($incomingByName[$name])) {
                $toUpsert[] = [
                    'name'         => $e['name'],
                    'firma'        => $e['firma'],
                    '_emails_json' => $e['emails_json'], // already encoded
                    'kontakt'      => $e['kontakt'],
                    'telefon'      => $e['telefon'],
                    'color'        => $e['color'],
                    'exportPinned' => (bool)$e['export_pinned'],
                ];
            }
        }

        $ins = $db->prepare(
            'INSERT INTO plan_profese
                 (project_id, name, firma, emails_json, kontakt, telefon, color, export_pinned, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               firma=VALUES(firma), emails_json=VALUES(emails_json), kontakt=VALUES(kontakt),
               telefon=VALUES(telefon), color=VALUES(color), export_pinned=VALUES(export_pinned),
               sort_order=VALUES(sort_order), updated_at=NOW()'
        );

        foreach ($toUpsert as $i => $p) {
            $name = $p['name'] ?? null;
            if (!$name) continue;

            // Current server row for this profese (used for merge logic below)
            $sv = $existingByName[$name] ?? null;

            if (isset($p['_emails_json'])) {
                // Server-only entry re-inserted unchanged
                $emailsJson = $p['_emails_json'];
                $firma   = $p['firma']   ?? null;
                $kontakt = $p['kontakt'] ?? null;
                $telefon = $p['telefon'] ?? null;
            } else {
                // Client-submitted entry: resolve emails and text fields
                if (isset($p['emails']) && is_array($p['emails'])) {
                    $clientEmails = array_values(array_filter($p['emails']));
                } elseif (isset($p['email'])) {
                    $clientEmails = is_array($p['email'])
                        ? array_values(array_filter($p['email']))
                        : array_values(array_filter([$p['email']]));
                } else {
                    $clientEmails = [];
                }

                // Emails: always union — never discard addresses the server already has
                if ($sv && $sv['emails_json']) {
                    $serverEmails = json_decode($sv['emails_json'], true) ?: [];
                    $merged = array_values(array_unique(array_merge($serverEmails, $clientEmails)));
                } else {
                    $merged = $clientEmails;
                }
                $emailsJson = !empty($merged) ? json_encode($merged) : null;

                // Text fields: prefer client value when non-empty, fall back to server
                // so a stale device never blanks out data a richer device already saved
                $firma   = ($p['firma']   ?? '') !== '' ? $p['firma']   : ($sv['firma']   ?? null);
                $kontakt = ($p['kontakt'] ?? '') !== '' ? $p['kontakt'] : ($sv['kontakt'] ?? null);
                $telefon = ($p['telefon'] ?? '') !== '' ? $p['telefon'] : ($sv['telefon'] ?? null);
            }

            $ins->execute([
                $projectId, $name,
                $firma,
                $emailsJson,
                $kontakt,
                $telefon,
                $p['color']   ?? null,
                !empty($p['exportPinned']) ? 1 : 0,
                $i,
            ]);
        }

        $db->commit();
        $rows = _profLoad($projectId);
        jsonOk(['profese' => _rowsToProfese($rows)]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Chyba při ukládání profesí: ' . $e->getMessage(), 500);
    }
}

jsonError('Metoda není povolena', 405);

// ── Helpers ───────────────────────────────────────────────────

function _profLoad(int $projectId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT name, firma, emails_json, kontakt, telefon, color, export_pinned, sort_order
           FROM plan_profese WHERE project_id = ? ORDER BY sort_order, name'
    );
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();
    if (!empty($rows)) return $rows;
    return _migrateProfeseFromCanvas($projectId);
}

function _rowsToProfese(array $rows): array {
    return array_values(array_map(function($r) {
        $p = ['name' => $r['name']];
        if ($r['firma'])   $p['firma']   = $r['firma'];
        if ($r['kontakt']) $p['kontakt'] = $r['kontakt'];
        if ($r['telefon']) $p['telefon'] = $r['telefon'];
        if ($r['color'])   $p['color']   = $r['color'];
        if ($r['export_pinned']) $p['exportPinned'] = true;
        if ($r['emails_json']) {
            $emails = json_decode($r['emails_json'], true);
            if (is_array($emails) && !empty($emails)) $p['emails'] = $emails;
        }
        return $p;
    }, $rows));
}

// On first load: migrate existing profese_json from plan_canvas_data into plan_profese rows.
function _migrateProfeseFromCanvas(int $projectId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT profese_json FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        if (!$row || !$row['profese_json']) return [];

        $val = $row['profese_json'];
        // Decompress gzip+base64 (matches canvas.php _decompress)
        if (strlen($val) >= 4 && str_starts_with($val, 'H4s')) {
            $decoded = base64_decode($val, true);
            if ($decoded !== false) {
                $inflated = @gzdecode($decoded);
                if ($inflated !== false) $val = $inflated;
            }
        }
        $profese = json_decode($val, true);
        if (!is_array($profese) || empty($profese)) return [];

        $ins = $db->prepare(
            'INSERT IGNORE INTO plan_profese
                 (project_id, name, firma, emails_json, kontakt, telefon, color, export_pinned, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($profese as $i => $p) {
            $name = $p['name'] ?? null;
            if (!$name) continue;
            $emailsJson = null;
            if (isset($p['emails']) && is_array($p['emails'])) {
                $emailsJson = json_encode(array_values(array_filter($p['emails'])));
            } elseif (isset($p['email']) && $p['email']) {
                $emailsJson = json_encode([$p['email']]);
            }
            $ins->execute([
                $projectId, $name,
                $p['firma']   ?? null,
                $emailsJson,
                $p['kontakt'] ?? null,
                $p['telefon'] ?? null,
                $p['color']   ?? null,
                !empty($p['exportPinned']) ? 1 : 0,
                $i,
            ]);
        }

        $stmt2 = $db->prepare(
            'SELECT name, firma, emails_json, kontakt, telefon, color, export_pinned, sort_order
               FROM plan_profese WHERE project_id = ? ORDER BY sort_order, name'
        );
        $stmt2->execute([$projectId]);
        return $stmt2->fetchAll();
    } catch (\Exception $e) {
        error_log('[profese] migration error: ' . $e->getMessage());
        return [];
    }
}
