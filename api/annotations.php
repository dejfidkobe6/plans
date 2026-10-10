<?php
// ============================================================
// GET /api/annotations?project=<slug|id>
//
// Čtecí endpoint pro externí dashboard (stavba.besix.cz). Vrací JSON pole
// anotací daného projektu:
//   {id, label, cat, x, y, zone, activity, time, count}
// x, y = pozice na výkrese v procentech (0–100), počítáno ze středu anotace
// vůči podkladovému výkresu daného podlaží.
//
// Autentizace (jedno z):
//   1) hlavička  x-besix-api-key: <klíč>   – service-to-service, čte libovolný
//      projekt aplikace; klíč je v secrets.php jako BESIX_API_KEY
//   2) přihlášená session – vrací jen projekty, jejichž je uživatel členem.
//      Pozn.: session cookie je SameSite=Lax, takže z cizí domény ji prohlížeč
//      nepošle; pro cross-site volání použij API klíč.
// ============================================================

// CORS musí odejít dřív, než config.php nastaví svoje hlavičky a ukončí preflight.
define('ANNOT_API_ORIGIN', 'https://stavba.besix.cz');
function _annotCors(): void {
    header('Access-Control-Allow-Origin: ' . ANNOT_API_ORIGIN);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, x-besix-api-key');
    header('Access-Control-Max-Age: 600');
}
_annotCors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $err['message']]);
    }
});
set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/functions.php';
_annotCors();   // config.php mezitím přepsal Allow-Origin na plans.besix.cz
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') jsonError('Metoda není povolena', 405);

// ── Autentizace ─────────────────────────────────────────────
$apiKey  = trim($_SERVER['HTTP_X_BESIX_API_KEY'] ?? '');
$viaKey  = false;
$userId  = 0;
if ($apiKey !== '') {
    if (!defined('BESIX_API_KEY') || BESIX_API_KEY === '') {
        jsonError('API klíč není na serveru nastavený', 503);
    }
    if (!hash_equals((string)BESIX_API_KEY, $apiKey)) jsonError('Neplatný API klíč', 401);
    $viaKey = true;
} else {
    $user   = requireAuth();
    $userId = (int)$user['id'];
}

$projectParam = trim($_GET['project'] ?? '');
if ($projectParam === '') jsonError('Chybí parametr project', 400);

// ── Nalezení projektu podle slugu nebo ID ───────────────────
/** Porovnávací tvar názvu: malá písmena, bez diakritiky, jen [a-z0-9]. */
function _annotSlug(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u',
        'ü'=>'u','ý'=>'y','ž'=>'z',
    ]);
    return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
}

// Pevné aliasy veřejného názvu na ID projektu. Hodí se, když je samotný název
// nejednoznačný – například víc etap se slovem „Lihovar“. Klíč je slug (malá
// písmena bez diakritiky a mezer), hodnota ID projektu z plan_projects.
const ANNOT_PROJECT_ALIASES = [
    // 'lihovar' => 12,
];

$db    = getDB();
$appId = getPlansAppId();
if ($viaKey) {
    $stmt = $db->prepare('SELECT id, name FROM plan_projects WHERE app_id = ? AND is_active = 1');
    $stmt->execute([$appId]);
} else {
    $stmt = $db->prepare('
        SELECT p.id, p.name FROM plan_projects p
        JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = ?
        WHERE p.app_id = ? AND p.is_active = 1');
    $stmt->execute([$userId, $appId]);
}
$projects = $stmt->fetchAll();

$want    = _annotSlug($projectParam);
$project = null;
$wantId  = ctype_digit($projectParam) ? (int)$projectParam : (ANNOT_PROJECT_ALIASES[$want] ?? null);
if ($wantId !== null) {
    foreach ($projects as $p) if ((int)$p['id'] === (int)$wantId) { $project = $p; break; }
}
if (!$project) {
    $exact = [];
    $part  = [];
    foreach ($projects as $p) {
        $slug = _annotSlug($p['name']);
        if ($slug === $want)                   $exact[] = $p;
        elseif ($want !== '' && str_contains($slug, $want)) $part[] = $p;
    }
    $hits = $exact ?: $part;
    if (count($hits) === 1) $project = $hits[0];
    elseif (count($hits) > 1) {
        jsonError('Název projektu není jednoznačný: ' . implode(', ', array_column($hits, 'name')), 400);
    }
}
if (!$project) jsonError('Projekt nenalezen nebo k němu nemáš přístup', 404);
$projectId = (int)$project['id'];

// ── Stav projektu (podlaží, geometrie, metadata anotací) ────
$stmt = $db->prepare('SELECT state_json FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
$stmt->execute([$projectId]);
$row   = $stmt->fetch();
$state = $row ? json_decode($row['state_json'] ?? '', true) : null;
$levels = (is_array($state) && isset($state['levels']) && is_array($state['levels'])) ? $state['levels'] : [];

// Rozměry podkladových výkresů – potřeba pro přepočet pozice na procenta
$bgSize = [];   // level_id => [w, h]
$stmt = $db->prepare('SELECT level_id, image_url FROM plan_backgrounds WHERE project_id = ?');
$stmt->execute([$projectId]);
foreach ($stmt->fetchAll() as $b) {
    $url = $b['image_url'] ?? '';
    if (!$url) continue;
    $path = __DIR__ . '/..' . $url;
    if (!is_file($path)) continue;
    $size = @getimagesize($path);
    if ($size && $size[0] > 0 && $size[1] > 0) $bgSize[$b['level_id']] = [$size[0], $size[1]];
}

// Štítky symbolů stavebních činností – zrcadlí SYMBOL_DEFS v index.html.
// Neznámý klíč se vrátí tak, jak je, takže se nic nerozbije, když symbol přibude.
const ANNOT_SYMBOL_LABELS = [
    'crane' => 'Jeřáb', 'excavator' => 'Rypadlo', 'truck' => 'Sklápěč',
    'delivery' => 'Zásobování/Návoz', 'bulldozer' => 'Buldozer', 'forklift' => 'Vysokozdvižný',
    'wheelbarrow' => 'Kolečko', 'platform' => 'Plošina', 'silo' => 'Stavební silo',
    'helmet' => 'Přilba', 'cone' => 'Doprav. kužel', 'worker' => 'Pracovník',
    'goggles' => 'Ochr. brýle', 'warning' => 'Výstraha', 'noentry' => 'Zákaz vstupu',
    'electric' => 'Elektrika', 'lowvoltage' => 'Slaboproud', 'camera' => 'Kamera/CCTV',
    'plumbing' => 'Potrubí', 'ventilation' => 'Vzduchotechnika', 'waterdrop' => 'Voda',
    'pickaxe' => 'Bourání', 'shovel' => 'Výkop', 'scaffolding' => 'Lešení',
    'scaffold' => 'Žebřík', 'brick' => 'Zdění', 'drywall' => 'Sádrokarton',
    'house' => 'Stavba domu', 'barrier' => 'Oplocení', 'hoist' => 'Stav. výtah',
    'stairs' => 'Schody', 'steel' => 'Ocel. konstrukce', 'works' => 'Prováděné práce',
    'saw' => 'Pila', 'hammer' => 'Kladivo', 'wrench' => 'Montáž', 'toolbox' => 'Nářadí',
    'concrete' => 'Autodomíchávač', 'insulation' => 'Izolace', 'paint' => 'Malování',
    'tiles' => 'Obklady a dlažba', 'tape' => 'Metr/Pásmo', 'level' => 'Vodováha',
    'tree' => 'Sázení zeleně', 'irrigation' => 'Závlaha',
];
const ANNOT_TYPE_LABELS = [
    'rect' => 'Obdélník', 'circle' => 'Kruh', 'polygon' => 'Víceúhelník',
    'polyline' => 'Lomená čára', 'arrow' => 'Šipka', 'partition' => 'Příčka',
    'freehand' => 'Volná čára', 'pin' => 'Špendlík', 'text' => 'Text', 'symbol' => 'Symbol',
];

$items = [];
foreach ($levels as $lvl) {
    $levelId = $lvl['id'] ?? null;
    $zone    = $lvl['name'] ?? null;
    $objects = $lvl['fabricJSON']['objects'] ?? [];
    if (!is_array($objects)) $objects = [];

    // Metadata anotací podle annotId (nesou mimo jiné annotSymbol, který ve fabric
    // objektu není uložený)
    $meta = [];
    foreach (($lvl['annotations'] ?? []) as $a) {
        if (!empty($a['annotId'])) $meta[$a['annotId']] = $a;
    }

    // Umístění a měřítko podkladu v souřadnicích plátna
    $bgL  = isset($lvl['bgLeft'])   ? (float)$lvl['bgLeft']   : null;
    $bgT  = isset($lvl['bgTop'])    ? (float)$lvl['bgTop']    : null;
    $bgSX = isset($lvl['bgScaleX']) ? (float)$lvl['bgScaleX'] : null;
    $bgSY = isset($lvl['bgScaleY']) ? (float)$lvl['bgScaleY'] : null;
    $dim  = ($levelId !== null && isset($bgSize[$levelId])) ? $bgSize[$levelId] : null;
    $canPlace = $dim && $bgL !== null && $bgT !== null && $bgSX > 0 && $bgSY > 0;

    foreach ($objects as $o) {
        $annotId = $o['annotId'] ?? null;
        if (!$annotId) continue;
        if (!empty($o['annotLabelFor']) || !empty($o['isBackground']) || !empty($o['isPolyHelper'])) continue;
        $m    = $meta[$annotId] ?? [];
        $type = $o['annotType'] ?? ($m['annotType'] ?? null);
        if ($type === 'levellink') continue;   // navigační odkaz, ne pracovní anotace

        // Střed anotace v souřadnicích plátna. U špendlíku a symbolu je bod
        // původu zároveň místo, kam ho uživatel umístil.
        $x = null; $y = null;
        if ($canPlace && isset($o['left'], $o['top'])) {
            $w = (float)($o['width']  ?? 0) * (float)($o['scaleX'] ?? 1);
            $h = (float)($o['height'] ?? 0) * (float)($o['scaleY'] ?? 1);
            $ox = $o['originX'] ?? 'left';
            $oy = $o['originY'] ?? 'top';
            $cx = ($ox === 'center') ? (float)$o['left'] : (float)$o['left'] + $w / 2;
            $cy = ($oy === 'center' || $oy === 'bottom') ? (float)$o['top'] : (float)$o['top'] + $h / 2;
            $x = round((($cx - $bgL) / $bgSX) / $dim[0] * 100, 2);
            $y = round((($cy - $bgT) / $bgSY) / $dim[1] * 100, 2);
        }

        // Činnost: u symbolu jeho název, jinak typ anotace
        $symKey   = $m['annotSymbol'] ?? null;
        $activity = null;
        if ($type === 'symbol' && $symKey) {
            $activity = ANNOT_SYMBOL_LABELS[$symKey] ?? $symKey;
        } elseif ($type) {
            $activity = ANNOT_TYPE_LABELS[$type] ?? $type;
        }

        // Čas poslední změny: updatedAt je ms epoch, createdAt ISO řetězec
        $time = null;
        $upd  = $m['updatedAt'] ?? ($o['updatedAt'] ?? null);
        if (is_numeric($upd) && $upd > 0) {
            $time = gmdate('c', (int)floor($upd / 1000));
        } elseif (!empty($m['createdAt'])) {
            $time = (string)$m['createdAt'];
        } elseif (!empty($o['createdAt'])) {
            $time = (string)$o['createdAt'];
        }

        $label = trim((string)($m['popisek'] ?? $o['popisek'] ?? ''));
        if ($label === '') $label = (string)$annotId;

        $items[] = [
            'id'       => (string)$annotId,
            'label'    => $label,
            'cat'      => ($m['profese'] ?? $o['profese'] ?? null) ?: null,
            'x'        => $x,
            'y'        => $y,
            'zone'     => $zone,
            'activity' => $activity,
            'time'     => $time,
            'count'    => 1,
        ];
    }
}

echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
