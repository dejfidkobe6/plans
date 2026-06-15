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

$db = getDB();

// ── Tables ────────────────────────────────────────────────────
$db->exec('CREATE TABLE IF NOT EXISTS plan_canvas_data (
    project_id    INT          NOT NULL,
    state_json    LONGTEXT,
    profese_json  MEDIUMTEXT,
    annot_counter INT          NOT NULL DEFAULT 1,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$db->exec('CREATE TABLE IF NOT EXISTS plan_annotations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    project_id       INT          NOT NULL,
    level_id         VARCHAR(64)  NOT NULL,
    annot_id         VARCHAR(32)  NOT NULL,
    fabric_json      MEDIUMTEXT   NOT NULL,
    profese          VARCHAR(255) DEFAULT NULL,
    popisek          MEDIUMTEXT   DEFAULT NULL,
    priorita         VARCHAR(32)  DEFAULT NULL,
    prirazeno        VARCHAR(255) DEFAULT NULL,
    status           VARCHAR(32)  DEFAULT NULL,
    deadline         VARCHAR(64)  DEFAULT NULL,
    date_from        VARCHAR(64)  DEFAULT NULL,
    annot_type       VARCHAR(32)  DEFAULT NULL,
    created_at_annot VARCHAR(64)  DEFAULT NULL,
    updated_at_annot BIGINT       NOT NULL DEFAULT 0,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_annot (project_id, level_id, annot_id),
    INDEX idx_project (project_id),
    INDEX idx_level (project_id, level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$user      = requireAuth();
$userId    = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
$method    = $_SERVER['REQUEST_METHOD'];

if (!$projectId) jsonError('Chybí project_id');

$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup k tomuto projektu', 403);

// ── Compress / decompress ─────────────────────────────────────
function _compress(string $json): string {
    if (function_exists('gzencode')) {
        $gz = @gzencode($json, 6);
        if ($gz !== false) return base64_encode($gz);
    }
    return $json;
}

function _decompress(string $val): string {
    if (strlen($val) >= 4 && str_starts_with($val, 'H4s')) {
        $decoded = base64_decode($val, true);
        if ($decoded !== false) {
            $inflated = @gzdecode($decoded);
            if ($inflated !== false) return $inflated;
        }
    }
    return $val;
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['ts_only'])) {
        $stmt = $db->prepare('SELECT updated_at FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        jsonOk(['updated_at' => $row['updated_at'] ?? null]);
    }

    $stmt = $db->prepare(
        'SELECT state_json, profese_json, annot_counter, updated_at FROM plan_canvas_data WHERE project_id = ? LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();

    if (!$row || !$row['state_json']) {
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1, 'updated_at' => null]);
    }

    $state = json_decode(_decompress($row['state_json']), true);
    if ($state === null) {
        jsonOk(['state' => null, 'profese' => null, 'counter' => 1, 'updated_at' => null]);
    }

    // Load annotation objects from plan_annotations (auto-migrates from state_json if empty)
    $annotsByLevel = _annotLoadOrMigrate($projectId, $state);

    foreach ($state['levels'] as &$lvl) {
        $lid  = $lvl['id'] ?? null;
        $objs = $lid ? ($annotsByLevel[$lid] ?? []) : [];

        if (!isset($lvl['fabricJSON']) || $lvl['fabricJSON'] === null) {
            $lvl['fabricJSON'] = ['version' => '5.3.0', 'objects' => []];
        }
        $lvl['fabricJSON']['objects'] = $objs;

        $lvl['annotations'] = array_values(array_map(fn($o) => array_filter([
            'annotId'   => $o['annotId']   ?? null,
            'profese'   => $o['profese']   ?? null,
            'popisek'   => $o['popisek']   ?? null,
            'priorita'  => $o['priorita']  ?? null,
            'prirazeno' => $o['prirazeno'] ?? null,
            'status'    => $o['status']    ?? null,
            'createdAt' => $o['createdAt'] ?? null,
            'updatedAt' => $o['updatedAt'] ?? null,
            'deadline'  => $o['deadline']  ?? null,
            'dateFrom'  => $o['dateFrom']  ?? null,
        ], fn($v) => $v !== null), $objs));
    }
    unset($lvl);

    $profese = $row['profese_json'] ? json_decode(_decompress($row['profese_json']), true) : null;

    jsonOk([
        'state'      => $state,
        'profese'    => $profese,
        'counter'    => (int)($row['annot_counter'] ?? 1),
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
        $rawJson = $_POST['data'] ?? '';
    } else {
        $rawJson = file_get_contents('php://input');
    }
    if (!$rawJson) jsonError('Prázdné tělo požadavku', 400);

    $body = json_decode($rawJson, true);
    if ($body === null) jsonError('Neplatný JSON: ' . json_last_error_msg(), 400);

    $state   = $body['state']   ?? null;
    $profese = $body['profese'] ?? null;
    $counter = (int)($body['counter'] ?? 1);

    if ($state === null) jsonError('Chybí state');

    if (isset($state['levels']) && is_array($state['levels'])) {
        foreach ($state['levels'] as &$lvl) {
            unset($lvl['backgroundImage'], $lvl['backgroundImageOriginal']);
        }
        unset($lvl);
    }

    $db->beginTransaction();

    try {
        // Lock canvas row
        $stmt = $db->prepare(
            'SELECT state_json, profese_json, annot_counter FROM plan_canvas_data WHERE project_id = ? FOR UPDATE'
        );
        $stmt->execute([$projectId]);
        $existingRow = $stmt->fetch();

        // Server level structure
        $serverLevels  = [];
        $serverCounter = 1;
        $serverProfese = [];
        if ($existingRow && $existingRow['state_json']) {
            $serverState   = json_decode(_decompress($existingRow['state_json']), true);
            $serverLevels  = $serverState['levels'] ?? [];
            $serverCounter = (int)($existingRow['annot_counter'] ?? 1);
        }
        if ($existingRow && $existingRow['profese_json']) {
            $serverProfese = json_decode(_decompress($existingRow['profese_json']), true) ?? [];
        }

        // Load all server annotations for this project (for merge / serverAdded)
        $stmtAnn = $db->prepare(
            'SELECT level_id, annot_id, fabric_json, updated_at_annot FROM plan_annotations WHERE project_id = ?'
        );
        $stmtAnn->execute([$projectId]);
        $serverAnnotRows = $stmtAnn->fetchAll();

        // Index: [level_id][annot_id] => row
        $srvAnnotMap = [];
        foreach ($serverAnnotRows as $ar) {
            $srvAnnotMap[$ar['level_id']][$ar['annot_id']] = $ar;
        }

        // Process client levels – collect annotation upserts and serverAdded
        $clientLevels      = $state['levels'] ?? [];
        $clientLevelIds    = [];
        $clientAnnotSet    = []; // [level_id][annot_id] = true
        $annotUpserts      = [];
        $serverAddedByLvl  = []; // [level_id] => [objects]

        foreach ($clientLevels as $cl) {
            $lid = $cl['id'] ?? null;
            if (!$lid) continue;
            $clientLevelIds[] = $lid;
            $clientAnnotSet[$lid] = [];

            foreach ($cl['fabricJSON']['objects'] ?? [] as $obj) {
                $aid = $obj['annotId'] ?? null;
                if (!$aid) continue;
                $clientAnnotSet[$lid][$aid] = true;

                $clientTs = (int)($obj['updatedAt'] ?? 0);
                $srvRow   = $srvAnnotMap[$lid][$aid] ?? null;
                $serverTs = $srvRow ? (int)$srvRow['updated_at_annot'] : 0;

                if ($srvRow && $serverTs > $clientTs) {
                    // Server is newer — keep DB value; client will see it on next poll
                    continue;
                }

                // Client is newer or annotation is new → upsert
                $fjson = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (!$fjson || $fjson === 'null') continue;
                $annotUpserts[] = [
                    $projectId, $lid, $aid, $fjson,
                    $obj['profese']   ?? null,
                    $obj['popisek']   ?? null,
                    $obj['priorita']  ?? null,
                    $obj['prirazeno'] ?? null,
                    $obj['status']    ?? null,
                    $obj['deadline']  ?? null,
                    $obj['dateFrom']  ?? null,
                    $obj['annotType'] ?? null,
                    $obj['createdAt'] ?? null,
                    $clientTs,
                ];
            }
        }

        // Find server-only annotations for each submitted level → serverAdded
        foreach ($clientLevelIds as $lid) {
            foreach ($srvAnnotMap[$lid] ?? [] as $aid => $srvRow) {
                if (!isset($clientAnnotSet[$lid][$aid])) {
                    $srvObj = json_decode($srvRow['fabric_json'], true);
                    if ($srvObj) $serverAddedByLvl[$lid][] = $srvObj;
                }
            }
        }
        $serverAdded = [];
        foreach ($serverAddedByLvl as $lid => $objs) {
            $serverAdded[] = ['levelId' => $lid, 'objects' => $objs];
        }

        // Execute annotation upserts
        if (!empty($annotUpserts)) {
            $ins = $db->prepare(
                'INSERT INTO plan_annotations
                     (project_id, level_id, annot_id, fabric_json,
                      profese, popisek, priorita, prirazeno, status,
                      deadline, date_from, annot_type, created_at_annot, updated_at_annot)
                 VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   fabric_json      = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(fabric_json),      fabric_json),
                   profese          = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(profese),          profese),
                   popisek          = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(popisek),          popisek),
                   priorita         = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(priorita),         priorita),
                   prirazeno        = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(prirazeno),        prirazeno),
                   status           = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(status),           status),
                   deadline         = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(deadline),         deadline),
                   date_from        = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(date_from),        date_from),
                   annot_type       = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(annot_type),       annot_type),
                   created_at_annot = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(created_at_annot), created_at_annot),
                   updated_at_annot = IF(VALUES(updated_at_annot)>=updated_at_annot, VALUES(updated_at_annot), updated_at_annot),
                   updated_at       = NOW()'
            );
            foreach ($annotUpserts as $params) {
                $ins->execute($params);
            }
        }

        // Merge level STRUCTURE (no annotation objects in state_json)
        $serverLevelMap = [];
        foreach ($serverLevels as $sl) {
            if (!empty($sl['id'])) $serverLevelMap[$sl['id']] = $sl;
        }
        $clientLevelMap = [];
        foreach ($clientLevels as $cl) {
            if (!empty($cl['id'])) $clientLevelMap[$cl['id']] = $cl;
        }

        $mergedLevels = [];
        foreach ($clientLevels as $cl) {
            $id     = $cl['id'] ?? null;
            $merged = $cl;
            // Strip annotation data — stored in plan_annotations, not state_json
            if (isset($merged['fabricJSON']) && $merged['fabricJSON'] !== null) {
                $merged['fabricJSON'] = [
                    'version'    => $merged['fabricJSON']['version']    ?? '5.3.0',
                    'background' => $merged['fabricJSON']['background'] ?? '#f0f0ec',
                ];
            }
            unset($merged['annotations']);
            $mergedLevels[] = $merged;
        }

        // Preserve server-only levels (added by another user)
        foreach ($serverLevels as $sl) {
            $id = $sl['id'] ?? null;
            if ($id && !isset($clientLevelMap[$id])) {
                $sl2 = $sl;
                if (isset($sl2['fabricJSON'])) {
                    $sl2['fabricJSON'] = [
                        'version'    => $sl2['fabricJSON']['version']    ?? '5.3.0',
                        'background' => $sl2['fabricJSON']['background'] ?? '#f0f0ec',
                    ];
                }
                unset($sl2['annotations']);
                $mergedLevels[] = $sl2;
            }
        }

        $mergedState            = $state;
        $mergedState['levels']  = $mergedLevels;
        $newCounter             = max($counter, $serverCounter);

        // Clean up annotations for levels that no longer exist in any user's state
        $activeLevelIds = array_filter(array_column($mergedLevels, 'id'));
        if (!empty($activeLevelIds) && !empty($srvAnnotMap)) {
            $orphanLids = array_diff(array_keys($srvAnnotMap), $activeLevelIds);
            if (!empty($orphanLids)) {
                $ph = implode(',', array_fill(0, count($orphanLids), '?'));
                $db->prepare("DELETE FROM plan_annotations WHERE project_id = ? AND level_id IN ($ph)")
                   ->execute(array_merge([$projectId], array_values($orphanLids)));
            }
        }

        $stateJson = json_encode($mergedState, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($stateJson === false || $stateJson === 'null') {
            $db->rollBack();
            jsonError('Chyba serializace stavu: ' . json_last_error_msg(), 500);
        }

        // Merge profese (still kept in profese_json as backup)
        $mergedProfese = null;
        if ($profese !== null) {
            $mergedProfese = _mergeProfese($serverProfese, $profese);
        } elseif (!empty($serverProfese)) {
            $mergedProfese = $serverProfese;
        }

        $profeseJson = null;
        if ($mergedProfese !== null) {
            $profeseJson = json_encode($mergedProfese, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($profeseJson === false) $profeseJson = null;
        }

        $stateCompressed  = _compress($stateJson);
        $proteseCompressed = $profeseJson !== null ? _compress($profeseJson) : null;

        if (empty($stateCompressed)) {
            $db->rollBack();
            jsonError('Komprese selhala – prázdný výsledek', 500);
        }

        if ($existingRow) {
            $db->prepare(
                'UPDATE plan_canvas_data SET state_json=?, profese_json=?, annot_counter=?, updated_at=NOW() WHERE project_id=?'
            )->execute([$stateCompressed, $proteseCompressed, $newCounter, $projectId]);
        } else {
            $db->prepare(
                'INSERT INTO plan_canvas_data (project_id, state_json, profese_json, annot_counter) VALUES (?,?,?,?)'
            )->execute([$projectId, $stateCompressed, $proteseCompressed, $newCounter]);
        }

        $db->commit();
        jsonOk(['saved' => strlen($stateCompressed), 'serverAdded' => $serverAdded, 'counter' => $newCounter]);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Chyba při ukládání: ' . $e->getMessage(), 500);
    }
}

jsonError('Metoda není povolena', 405);

// ── Helpers ───────────────────────────────────────────────────

// Merge profese arrays: client wins per name, server-only entries preserved.
function _mergeProfese(array $server, array $client): array {
    $clientByName = [];
    foreach ($client as $p) {
        $name = $p['name'] ?? null;
        if ($name !== null) $clientByName[$name] = $p;
    }
    $merged = $client;
    foreach ($server as $p) {
        $name = $p['name'] ?? null;
        if ($name !== null && !isset($clientByName[$name])) $merged[] = $p;
    }
    return $merged;
}

// Load annotations from plan_annotations, grouped by level_id.
// If the table is empty for this project, auto-migrate from embedded state_json objects
// (one-time, transparent to the caller).
function _annotLoadOrMigrate(int $projectId, array &$state): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT level_id, annot_id, fabric_json FROM plan_annotations WHERE project_id = ? ORDER BY id'
    );
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();

    if (!empty($rows)) {
        $byLevel = [];
        foreach ($rows as $r) {
            $obj = json_decode($r['fabric_json'], true);
            if ($obj) $byLevel[$r['level_id']][] = $obj;
        }
        return $byLevel;
    }

    // Migration: extract annotation objects from embedded state_json
    $byLevel = [];
    $ins = $db->prepare(
        'INSERT IGNORE INTO plan_annotations
             (project_id, level_id, annot_id, fabric_json,
              profese, popisek, priorita, prirazeno, status,
              deadline, date_from, annot_type, created_at_annot, updated_at_annot)
         VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?,?,?)'
    );
    foreach ($state['levels'] as $lvl) {
        $lid = $lvl['id'] ?? null;
        if (!$lid) continue;
        foreach ($lvl['fabricJSON']['objects'] ?? [] as $obj) {
            $aid = $obj['annotId'] ?? null;
            if (!$aid) continue;
            $fjson = json_encode($obj, JSON_UNESCAPED_UNICODE);
            if (!$fjson) continue;
            $ins->execute([
                $projectId, $lid, $aid, $fjson,
                $obj['profese']   ?? null,
                $obj['popisek']   ?? null,
                $obj['priorita']  ?? null,
                $obj['prirazeno'] ?? null,
                $obj['status']    ?? null,
                $obj['deadline']  ?? null,
                $obj['dateFrom']  ?? null,
                $obj['annotType'] ?? null,
                $obj['createdAt'] ?? null,
                (int)($obj['updatedAt'] ?? 0),
            ]);
            $byLevel[$lid][] = $obj;
        }
    }
    return $byLevel;
}
