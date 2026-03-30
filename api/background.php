<?php
ob_start();
ini_set('display_errors', 0);
ini_set('post_max_size',       '52M');
ini_set('upload_max_filesize', '50M');
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$err['message'],'fatal'=>true]);
    }
});
set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
});

require_once __DIR__ . '/functions.php';

// Vytvoř/oprav tabulku (bez velkých LONGTEXT sloupců – ukládáme jen cesty k souborům)
$db = getDB();
$db->exec('CREATE TABLE IF NOT EXISTS plan_backgrounds (
    project_id   INT          NOT NULL,
    level_id     VARCHAR(64)  NOT NULL,
    image_url    VARCHAR(500),
    original_url VARCHAR(500),
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

// Migrace starého schématu (image_data → image_url)
try { $db->exec('ALTER TABLE plan_backgrounds ADD COLUMN image_url    VARCHAR(500) DEFAULT NULL'); } catch (\PDOException $e) {}
try { $db->exec('ALTER TABLE plan_backgrounds ADD COLUMN original_url VARCHAR(500) DEFAULT NULL'); } catch (\PDOException $e) {}
try { $db->exec('ALTER TABLE plan_backgrounds DROP COLUMN image_data');    } catch (\PDOException $e) {}
try { $db->exec('ALTER TABLE plan_backgrounds DROP COLUMN original_data'); } catch (\PDOException $e) {}

$user      = requireAuth();
$userId    = (int)$user['id'];
$method    = $_SERVER['REQUEST_METHOD'];
$projectId = (int)($_GET['project_id'] ?? 0);
$levelId   = trim($_GET['level_id'] ?? '');

if (!$projectId || $levelId === '') jsonError('Chybí parametry');
if (strlen($levelId) > 64) jsonError('Neplatné level_id');

$membership = getProjectMembership($projectId, $userId);
if (!$membership) jsonError('Nemáš přístup', 403);

// ============================================================
// GET – vrátí URL obrázku z DB
// ============================================================
if ($method === 'GET') {
    $stmt = $db->prepare('SELECT image_url, original_url FROM plan_backgrounds WHERE project_id = ? AND level_id = ? LIMIT 1');
    $stmt->execute([$projectId, $levelId]);
    $row = $stmt->fetch();
    jsonOk([
        'url'      => $row['image_url']    ?? null,
        'origUrl'  => $row['original_url'] ?? null,
    ]);
}

// ============================================================
// POST – nahrání souboru (multipart/form-data)
// Pole: image (povinné), original (volitelné)
// ============================================================
if ($method === 'POST') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Soubor nebyl nahrán nebo nastala chyba (' . ($_FILES['image']['error'] ?? 'no file') . ')');
    }

    // Sanitizuj level_id pro název souboru
    $safe    = preg_replace('/[^a-zA-Z0-9_-]/', '_', $levelId);
    $ext     = _guessExtFromMime($_FILES['image']['type'] ?? '');
    $extOrig = _guessExtFromMime($_FILES['original']['type'] ?? '');

    $uploadDir = __DIR__ . '/../uploads/bg/' . $projectId . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        jsonError('Nelze vytvořit upload adresář');
    }

    $filename = $safe . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
        jsonError('Nepodařilo se uložit soubor');
    }
    $imageUrl = '/uploads/bg/' . $projectId . '/' . $filename;

    $originalUrl = $imageUrl; // default: same as image
    if (isset($_FILES['original']) && $_FILES['original']['error'] === UPLOAD_ERR_OK) {
        $origFilename = $safe . '_orig.' . $extOrig;
        if (move_uploaded_file($_FILES['original']['tmp_name'], $uploadDir . $origFilename)) {
            $originalUrl = '/uploads/bg/' . $projectId . '/' . $origFilename;
        }
    }

    $db->prepare('
        INSERT INTO plan_backgrounds (project_id, level_id, image_url, original_url)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            image_url    = VALUES(image_url),
            original_url = VALUES(original_url),
            updated_at   = NOW()
    ')->execute([$projectId, $levelId, $imageUrl, $originalUrl]);

    jsonOk(['url' => $imageUrl, 'origUrl' => $originalUrl]);
}

// ============================================================
// DELETE – smaž soubor a záznam v DB
// ============================================================
if ($method === 'DELETE') {
    $stmt = $db->prepare('SELECT image_url, original_url FROM plan_backgrounds WHERE project_id = ? AND level_id = ? LIMIT 1');
    $stmt->execute([$projectId, $levelId]);
    $row = $stmt->fetch();

    if ($row) {
        foreach ([$row['image_url'], $row['original_url']] as $url) {
            if ($url) {
                $path = __DIR__ . '/..' . $url;
                if (file_exists($path)) @unlink($path);
            }
        }
        $db->prepare('DELETE FROM plan_backgrounds WHERE project_id = ? AND level_id = ?')
           ->execute([$projectId, $levelId]);
    }
    jsonOk();
}

jsonError('Metoda není povolena', 405);

// ============================================================
function _guessExtFromMime(string $mime): string {
    return match($mime) {
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        default         => 'jpg',
    };
}
