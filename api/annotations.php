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
        error_log('[annotations] fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']);
        ob_clean();
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        // Endpoint je dostupný z cizí domény – detail chyby patří do logu, ne ven
        echo json_encode(['ok' => false, 'error' => 'Interní chyba serveru']);
    }
});
set_exception_handler(function($e) {
    error_log('[annotations] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Interní chyba serveru']);
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
    $user = _annotSessionUser();
    if (!$user) jsonError('Nepřihlášen (pro volání ze serveru použij hlavičku x-besix-api-key)', 401);
    $userId = (int)$user['id'];
}

/**
 * Přihlášený uživatel bez jakéhokoli zápisu.
 * Úmyslně nevolá requireAuth(): ta při vypršelé session obnovuje remember-me token,
 * což znamená DDL a zápis do remember_tokens. Tenhle endpoint nesmí nic měnit, takže
 * cookie jen ověří čistým SELECTem a token nerotuje.
 */
function _annotSessionUser(): ?array {
    $u = $_SESSION['user'] ?? null;
    if ($u && !empty($u['id'])) return $u;

    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$raw) return null;
    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || strlen($parts[1]) !== 64) return null;
    try {
        $stmt = getDB()->prepare(
            'SELECT rt.user_id AS id, u.name, u.email
               FROM remember_tokens rt
               JOIN users u ON u.id = rt.user_id
              WHERE rt.user_id = ? AND rt.token_hash = ? AND rt.expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([(int)$parts[0], hash('sha256', $parts[1])]);
        return $stmt->fetch() ?: null;
    } catch (\Throwable $e) {
        return null;   // tabulka nemusí existovat – rozhodně ji nezakládat
    }
}

// ── Nalezení projektu podle názvu nebo ID ───────────────────
// Název se porovnává po slovech, ne jako souvislý řetězec: „etapa II“ se tak
// nesmí trefit do „etapa III“. Římské číslice se převádějí na arabské, takže
// „etapa II“ i „etapa 2“ najdou totéž. Jednopísmenné „i“ a „v“ se nepřevádějí,
// v češtině to jsou běžná slova.
const ANNOT_ROMAN = ['ii'=>'2','iii'=>'3','iv'=>'4','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10'];

/** Název rozložený na porovnávací slova: malá písmena, bez diakritiky, čísla sjednocená. */
function _annotTokens(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','ë'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u',
        'ü'=>'u','ý'=>'y','ž'=>'z',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $t) {
        if ($t === '') continue;
        $out[] = ANNOT_ROMAN[$t] ?? $t;
    }
    return $out;
}

$db    = getDB();
$appId = plansAppIdReadOnly();   // varianta bez CREATE/INSERT – endpoint jen čte
if ($appId === null) jsonError('Nelze zjistit plans app_id z DB.', 500);
if ($viaKey) {
    $stmt = $db->prepare('SELECT id, name FROM plan_projects WHERE app_id = ? AND is_active = 1 ORDER BY name');
    $stmt->execute([$appId]);
} else {
    $stmt = $db->prepare('
        SELECT p.id, p.name FROM plan_projects p
        JOIN plan_project_members pm ON pm.project_id = p.id AND pm.user_id = ?
        WHERE p.app_id = ? AND p.is_active = 1 ORDER BY p.name');
    $stmt->execute([$userId, $appId]);
}
$projects = $stmt->fetchAll();

// ?list=1 – přehled dostupných projektů, ať si volající název nemusí hádat
if (!empty($_GET['list'])) {
    echo json_encode(
        array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $projects),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$projectParam = trim($_GET['project'] ?? '');
if ($projectParam === '') jsonError('Chybí parametr project (nebo použij ?list=1)', 400);

$project = null;
if (ctype_digit($projectParam)) {
    foreach ($projects as $p) if ((int)$p['id'] === (int)$projectParam) { $project = $p; break; }
}
if (!$project) {
    $qTok  = _annotTokens($projectParam);
    $qSlug = implode('', $qTok);
    $exact = []; $byWords = []; $loose = [];
    foreach ($projects as $p) {
        $pTok  = _annotTokens($p['name']);
        $pSlug = implode('', $pTok);
        if ($pSlug === $qSlug)                                      $exact[]   = $p;
        elseif ($qTok && !array_diff($qTok, $pTok))                 $byWords[] = $p;   // všechna slova dotazu jsou v názvu
        elseif ($qSlug !== '' && str_contains($pSlug, $qSlug))      $loose[]   = $p;   // až když nic jiného nesedí
    }
    $hits = $exact ?: ($byWords ?: $loose);
    if (count($hits) === 1) $project = $hits[0];
    elseif (count($hits) > 1) {
        jsonError('Název projektu není jednoznačný, upřesni ho: ' . implode(' | ', array_column($hits, 'name')), 400);
    }
}
if (!$project) jsonError('Projekt nenalezen nebo k němu nemáš přístup (seznam: ?list=1)', 404);
$projectId = (int)$project['id'];

// ── Stav projektu (podlaží, geometrie, metadata anotací) ────
$stmt = $db->prepare('SELECT state_json FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
$stmt->execute([$projectId]);
$row   = $stmt->fetch();
// state_json je uložený komprimovaně (viz _compress v functions.php)
$state = $row ? json_decode(_decompress((string)($row['state_json'] ?? '')), true) : null;
$levels = (is_array($state) && isset($state['levels']) && is_array($state['levels'])) ? $state['levels'] : [];

// Dodavatelé: firma k zobrazení a barva, stejně jako je bere aplikace.
// Primárně plan_profese, u starších projektů záloha z profese_json ve stavu.
$profById = [];
try {
    $stmt = $db->prepare('SELECT name, firma, color FROM plan_profese WHERE project_id = ?');
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll() as $pr) $profById[$pr['name']] = $pr;
} catch (\Throwable $e) { /* tabulka nemusí existovat */ }
if (!$profById) {
    try {
        $stmt = $db->prepare('SELECT profese_json FROM plan_canvas_data WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $pj = $stmt->fetch();
        $arr = $pj && $pj['profese_json'] ? json_decode(_decompress((string)$pj['profese_json']), true) : null;
        foreach ((is_array($arr) ? $arr : []) as $pr) {
            if (!empty($pr['name'])) $profById[$pr['name']] = $pr;
        }
    } catch (\Throwable $e) {}
}

/** Barva anotace podle pravidla aplikace (getAnnotColor): Urgence červeně, jinak dodavatel. */
function annotColorHex(?string $type, ?string $status, ?string $profese, array $profById): string {
    if ($type === 'levellink') return '#06B6D4';
    if ($status === 'Urgence') return '#EF4444';
    $c = $profese !== null && isset($profById[$profese]) ? ($profById[$profese]['color'] ?? null) : null;
    return $c ?: '#6B7280';
}

/** Cesta fabric objektu jako SVG "d" (pole segmentů → řetězec). */
function annotPathD(array $o): ?string {
    if (empty($o['path']) || !is_array($o['path'])) return null;
    $parts = [];
    foreach ($o['path'] as $seg) {
        if (is_array($seg)) $parts[] = implode(' ', array_map(fn($v) => is_float($v) ? rtrim(rtrim(sprintf('%.3f', $v), '0'), '.') : (string)$v, $seg));
    }
    $d = trim(implode(' ', $parts));
    return $d !== '' ? $d : null;
}

// Objekty anotací. Zdrojem pravdy je plan_annotations – canvas.php je do stavu
// doplňuje až při čtení, takže uložený state_json má fabricJSON.objects většinou
// prázdné. Starší projekty, které ještě nebyly přemigrované, se dočtou ze stavu.
// Migraci schválně nespouštíme, ta zapisuje a tenhle endpoint jen čte.
$objsByLevel = [];
$stmt = $db->prepare('SELECT level_id, fabric_json FROM plan_annotations WHERE project_id = ? ORDER BY id');
$stmt->execute([$projectId]);
foreach ($stmt->fetchAll() as $r) {
    $obj = json_decode((string)$r['fabric_json'], true);
    if (is_array($obj) && !empty($obj['annotId'])) $objsByLevel[$r['level_id']][] = $obj;
}

// Cesty k podkladovým výkresům. Rozměry se zjišťují až u podlaží, která mají
// anotace – getimagesize sahá na disk, u projektu s mnoha podlažími je to znát.
$bgUrl = [];    // level_id => cesta
$stmt = $db->prepare('SELECT level_id, image_url FROM plan_backgrounds WHERE project_id = ?');
$stmt->execute([$projectId]);
foreach ($stmt->fetchAll() as $b) {
    if (!empty($b['image_url'])) $bgUrl[$b['level_id']] = $b['image_url'];
}
$bgSizeMemo = [];
/** Rozměry výkresu podlaží v pixelech, nebo null. Měří se nejvýš jednou za požadavek. */
$bgSizeFor = function (?string $levelId) use (&$bgSizeMemo, $bgUrl): ?array {
    if ($levelId === null || !isset($bgUrl[$levelId])) return null;
    if (array_key_exists($levelId, $bgSizeMemo)) return $bgSizeMemo[$levelId];
    $bgSizeMemo[$levelId] = null;
    $url  = strtok($bgUrl[$levelId], '?');          // obrana, kdyby v cestě někdy byla verze
    $path = __DIR__ . '/..' . $url;
    if (is_file($path)) {
        $size = @getimagesize($path);
        if ($size && $size[0] > 0 && $size[1] > 0) $bgSizeMemo[$levelId] = [$size[0], $size[1]];
    }
    return $bgSizeMemo[$levelId];
};

/** Absolutní cesta k souboru výkresu podlaží, ověřená že leží v uploads/bg. null = není. */
$bgPathFor = function (?string $levelId) use ($bgUrl): ?string {
    if ($levelId === null || !isset($bgUrl[$levelId])) return null;
    $path = realpath(__DIR__ . '/..' . strtok($bgUrl[$levelId], '?'));
    $base = realpath(__DIR__ . '/../uploads/bg');
    if (!$path || !$base || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) return null;
    return $path;
};

// ── ?drawing=<levelId> – vydá samotný soubor výkresu ────────────────────────
// Bez toho má volající jen procentuální pozice a nemá co podložit. Soubor se čte
// podle cesty z databáze, parametr slouží jen k vyhledání řádku.
if (isset($_GET['drawing']) && $_GET['drawing'] !== '') {
    $path = $bgPathFor((string)$_GET['drawing']);
    if (!$path) jsonError('Výkres pro toto podlaží neexistuje', 404);
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
             'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    // Soubor se po ořezu přepisuje pod stejným názvem, proto krátká cache + ETag
    header('Cache-Control: private, max-age=300');
    header('ETag: "' . md5((string)filemtime($path) . '-' . filesize($path)) . '"');
    readfile($path);
    exit;
}

// ── ?drawings=1 – seznam podkladů projektu ──────────────────────────────────
if (!empty($_GET['drawings'])) {
    $zoneOf = [];
    foreach ($levels as $lvl) {
        if (!empty($lvl['id'])) $zoneOf[$lvl['id']] = $lvl['name'] ?? null;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'plans.besix.cz';
    $self   = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'] ?? '/api/annotations', '?');
    $out = [];
    foreach ($bgUrl as $levelId => $rel) {
        if (!$bgPathFor($levelId)) continue;
        $dim = $bgSizeFor($levelId);
        $out[] = [
            'levelId' => (string)$levelId,
            'zone'    => $zoneOf[$levelId] ?? null,
            'url'     => $self . '?project=' . rawurlencode((string)$projectId) . '&drawing=' . rawurlencode((string)$levelId),
            'width'   => $dim ? $dim[0] : null,
            'height'  => $dim ? $dim[1] : null,
        ];
    }
    header('Cache-Control: private, max-age=30');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

$includeDone = !empty($_GET['includeDone']);   // výchozí stav: hotové skryté
$items = [];
foreach ($levels as $lvl) {
    $levelId = $lvl['id'] ?? null;
    $zone    = $lvl['name'] ?? null;
    $objects = ($levelId !== null && isset($objsByLevel[$levelId]))
        ? $objsByLevel[$levelId]
        : ($lvl['fabricJSON']['objects'] ?? []);
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
    $dim  = $objects ? $bgSizeFor($levelId) : null;   // bez anotací se na disk nesahá
    $canPlace = $dim && $bgL !== null && $bgT !== null && $bgSX > 0 && $bgSY > 0;

    foreach ($objects as $o) {
        $annotId = $o['annotId'] ?? null;
        if (!$annotId) continue;
        if (!empty($o['annotLabelFor']) || !empty($o['isBackground']) || !empty($o['isPolyHelper'])) continue;
        $m    = $meta[$annotId] ?? [];
        $type = $o['annotType'] ?? ($m['annotType'] ?? null);
        if ($type === 'levellink') continue;   // navigační odkaz, ne pracovní anotace
        // Jako v aplikaci: dokončené se nezobrazují, pokud si je volající nevyžádá
        $st = ($m['status'] ?? $o['status'] ?? null);
        if (!$includeDone && $st === 'Hotovo') continue;

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

        // Tvar pro věrné vykreslení: jak se to má kreslit + geometrie v procentech
        $kind = match (true) {
            $type === 'symbol' => 'glyph',
            $type === 'pin'    => 'pin',
            in_array($type, ['rect', 'circle', 'polygon'], true)                 => 'area',
            in_array($type, ['polyline', 'arrow', 'partition', 'freehand'], true) => 'line',
            $type === 'text'   => 'text',
            default            => 'area',
        };
        $shape = ['kind' => $kind, 'angle' => round((float)($o['angle'] ?? 0), 2)];
        if ($canPlace) {
            $wq = (float)($o['width']  ?? 0) * (float)($o['scaleX'] ?? 1);
            $hq = (float)($o['height'] ?? 0) * (float)($o['scaleY'] ?? 1);
            $shape['w'] = round(($wq / $bgSX) / $dim[0] * 100, 3);
            $shape['h'] = round(($hq / $bgSY) / $dim[1] * 100, 3);
        }
        if (in_array($kind, ['glyph', 'pin', 'line'], true)) {
            $d = annotPathD($o);
            if ($d !== null) {
                $shape['path']     = $d;
                $shape['viewBox']  = [round((float)($o['width'] ?? 0), 2), round((float)($o['height'] ?? 0), 2)];
                $shape['fillRule'] = ($o['fillRule'] ?? 'nonzero') === 'evenodd' ? 'evenodd' : 'nonzero';
            }
        }
        if (!empty($o['points']) && is_array($o['points'])) {
            $pts = [];
            foreach ($o['points'] as $pt) {
                if (isset($pt['x'], $pt['y'])) $pts[] = [round((float)$pt['x'], 2), round((float)$pt['y'], 2)];
            }
            if ($pts) $shape['points'] = $pts;
        }

        $profKey = ($m['profese'] ?? $o['profese'] ?? null) ?: null;
        $status  = ($m['status']  ?? $o['status']  ?? null) ?: null;
        $firma   = ($profKey !== null && !empty($profById[$profKey]['firma']))
            ? $profById[$profKey]['firma'] : ($profKey ?: '—');

        $items[] = [
            'id'       => (string)$annotId,
            'label'    => $label,
            'cat'      => $profKey,
            'firma'    => $firma,
            'color'    => annotColorHex($type, $status, $profKey, $profById),
            'status'   => $status,
            'type'     => $type,
            'shape'    => $shape,
            'x'        => $x,
            'y'        => $y,
            'zone'     => $zone,
            'activity' => $activity,
            'time'     => $time,
            'count'    => 1,
        ];
    }
}

// Dashboard se ptá opakovaně; krátká soukromá cache ušetří práci a data zůstanou čerstvá.
// private = odpověď je vázaná na volajícího (API klíč / session), sdílené cache ji držet nesmí.
header('Cache-Control: private, max-age=30');
echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
