<?php
require_once __DIR__ . '/map_core.php';

/* ---------- PARAMETERS ---------- */
// Fallback dimensions for non-JS browsers (Opera Mini)
$w = isset($_GET['w']) ? (int)$_GET['w'] : 320;
$h = isset($_GET['h']) ? (int)$_GET['h'] : 320;
$w = max(240, min(2200, $w));
$h = max(160, min(2200, $h));

// Zoom into the map if screen resolution is low
$is_low_res = ($w <= 320 || $h <= 320);
$default_zoom = $is_low_res ? 14 : 10;

$zoom = isset($_GET['z']) ? (int)$_GET['z'] : $default_zoom;
$zoom = max(3, min(17, $zoom));

$profile = isset($_GET['profile']) ? $_GET['profile'] : 'driving';
if (!in_array($profile, ROUTE_PROFILES, true)) $profile = 'driving';

$slat = (isset($_GET['slat']) && $_GET['slat'] !== '') ? (float)$_GET['slat'] : null;
$slon = (isset($_GET['slon']) && $_GET['slon'] !== '') ? (float)$_GET['slon'] : null;
$dlat = (isset($_GET['dlat']) && $_GET['dlat'] !== '') ? (float)$_GET['dlat'] : null;
$dlon = (isset($_GET['dlon']) && $_GET['dlon'] !== '') ? (float)$_GET['dlon'] : null;

$has_start = ($slat !== null && $slon !== null);
$has_dest  = ($dlat !== null && $dlon !== null && $dlat >= -85 && $dlat <= 85 && $dlon >= -180 && $dlon <= 180);

$plat = (isset($_GET['plat']) && $_GET['plat'] !== '') ? (float)$_GET['plat'] : $slat;
$plon = (isset($_GET['plon']) && $_GET['plon'] !== '') ? (float)$_GET['plon'] : $slon;
$ridx = isset($_GET['ridx']) ? max(0, (int)$_GET['ridx']) : 0;

/* ---------- Bail out to a friendly message if start/dest aren't set ---------- */
if (!$has_start || !$has_dest) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Navigation</title>
    <style>
    body { font-family: sans-serif; margin: 0; padding: 24px; background: #111; color: #eee; text-align: center; }
    a { color: #6cf; text-decoration: none; }
    </style>
    </head>
    <body>
    <p>Minimalistic navigation mode needs a start and a destination set first.</p>
    <p><a href="index.php">&larr; Go set them in normal mode</a></p>
    </body>
    </html>
    <?php
    exit;
}

/* ---------- FETCH ROAD ROUTE ---------- */
$route = get_route($slat, $slon, $dlat, $dlon, $profile);
$route_points = (is_array($route) && empty($route['fail'])) ? $route['points'] : [];
$route_failed = empty($route_points);
if (!empty($route_points)) {
    $ridx = min($ridx, count($route_points) - 1);
} else {
    $ridx = 0;
}

/* ---------- STEP / JUMP ACTIONS ---------- */
$goto_dest = isset($_GET['goto']) && $_GET['goto'] === 'dest';
$step_dest = isset($_GET['step']) && $_GET['step'] === 'dest';
$tdx = 0; $tdy = 0;

if ($goto_dest) {
    if (!empty($route_points)) {
        $ridx = count($route_points) - 1;
        $plat = $route_points[$ridx]['lat'];
        $plon = $route_points[$ridx]['lon'];
    } else {
        $plat = $dlat;
        $plon = $dlon;
    }
} elseif ($step_dest) {
    if (!empty($route_points)) {
        $mpp = meters_per_pixel($plat ?? $slat, $zoom);
        $min_step_km = max(0.01, min(2.0, ($mpp * 50) / 1000));
        $ridx = advance_route_index($route_points, $ridx, $min_step_km);
        $plat = $route_points[$ridx]['lat'];
        $plon = $route_points[$ridx]['lon'];
    } else {
        $cx0 = lon2tile($plon, $zoom);
        $cy0 = lat2tile($plat, $zoom);
        $dtx = lon2tile($dlon, $zoom);
        $dty = lat2tile($dlat, $zoom);
        $tdx = $dtx <=> $cx0;
        $tdy = $dty <=> $cy0;
    }
}
if ($tdx !== 0 || $tdy !== 0) {
    $cx = lon2tile($plon, $zoom) + $tdx;
    $cy = lat2tile($plat, $zoom) + $tdy;
    $plon = tile2lon($cx + 0.5, $zoom);
    $plat = tile2lat($cy + 0.5, $zoom);
}

/* ---------- LINK BUILDER ---------- */
function nav_link($params) {
    return htmlspecialchars('?' . http_build_query($params));
}

$base = [
    'z' => $zoom, 'profile' => $profile,
'slat' => $slat, 'slon' => $slon,
'plat' => $plat, 'plon' => $plon,
'dlat' => $dlat, 'dlon' => $dlon,
];
if (!empty($route_points)) $base['ridx'] = $ridx;
if (isset($_GET['w'])) $base['w'] = $w;
if (isset($_GET['h'])) $base['h'] = $h;

$exit_params = $base;
unset($exit_params['w'], $exit_params['h']);

/* ---------- DYNAMIC MAP COMPOSITE ---------- */
$map_file = null;
$dest_on_screen = false;

if (extension_loaded('gd')) {
    $tile_size = 256;
    $max_tile = pow(2, $zoom) - 1;

    $centerWX = world_px_x($plon, $zoom, $tile_size);
    $centerWY = world_px_y($plat, $zoom, $tile_size);
    $originX = $centerWX - $w / 2;
    $originY = $centerWY - $h / 2;

    $pos_key = round($plat, 6) . '_' . round($plon, 6);
    $dest_key = '_d' . round($dlat, 4) . '_' . round($dlon, 4) . '_' . $profile . ($route_failed ? '_nr' : '_r');
    $map_file = "map_nav_{$zoom}_{$w}x{$h}_p{$pos_key}{$dest_key}.png";

    if (!file_exists($map_file)) {
        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);

        $txStart = (int)floor($originX / $tile_size);
        $txEnd   = (int)floor(($originX + $w - 1) / $tile_size);
        $tyStart = (int)floor($originY / $tile_size);
        $tyEnd   = (int)floor(($originY + $h - 1) / $tile_size);

        for ($tx = $txStart; $tx <= $txEnd; $tx++) {
            if ($tx < 0 || $tx > $max_tile) continue;
            for ($ty = $tyStart; $ty <= $tyEnd; $ty++) {
                if ($ty < 0 || $ty > $max_tile) continue;
                $tile = get_cached_tile($zoom, $tx, $ty);
                if ($tile) {
                    imagecopy(
                        $img, $tile,
                        (int)($tx * $tile_size - $originX), (int)($ty * $tile_size - $originY),
                              0, 0, $tile_size, $tile_size
                    );
                    imagedestroy($tile);
                }
            }
        }

        $curX = $w / 2;
        $curY = $h / 2;

        $red = imagecolorallocate($img, 220, 40, 40);
        $red_fill = imagecolorallocate($img, 240, 90, 90);
        $dtX = world_px_x($dlon, $zoom, $tile_size) - $originX;
        $dtY = world_px_y($dlat, $zoom, $tile_size) - $originY;
        $dest_on_screen = ($dtX >= 0 && $dtX <= $w && $dtY >= 0 && $dtY <= $h);

        if (!empty($route_points)) {
            imagesetthickness($img, 5);
            $prevPx = null;
            foreach ($route_points as $pt) {
                $px = world_px_x($pt['lon'], $zoom, $tile_size) - $originX;
                $py = world_px_y($pt['lat'], $zoom, $tile_size) - $originY;
                if ($prevPx !== null) {
                    imageline($img, (int)$prevPx[0], (int)$prevPx[1], (int)$px, (int)$py, $red);
                }
                $prevPx = [$px, $py];
            }
            imagesetthickness($img, 1);
        } elseif ($dest_on_screen) {
            imagesetthickness($img, 3);
            imageline($img, (int)$curX, (int)$curY, (int)$dtX, (int)$dtY, $red);
            imagesetthickness($img, 1);
        }

        if (!$dest_on_screen) {
            $bearing = bearing_deg($plat, $plon, $dlat, $dlon);
            $rad = deg2rad($bearing - 90);
            $len = 44;
            $ax = $curX + cos($rad) * $len;
            $ay = $curY + sin($rad) * $len;
            imagesetthickness($img, 4);
            imageline($img, (int)$curX, (int)$curY, (int)$ax, (int)$ay, $red);
            imagesetthickness($img, 1);
            $rad1 = $rad + deg2rad(150);
            $rad2 = $rad - deg2rad(150);
            imageline($img, (int)$ax, (int)$ay, (int)($ax + cos($rad1) * 12), (int)($ay + sin($rad1) * 12), $red);
            imageline($img, (int)$ax, (int)$ay, (int)($ax + cos($rad2) * 12), (int)($ay + sin($rad2) * 12), $red);
        } else {
            imagefilledellipse($img, (int)$dtX, (int)$dtY, 16, 16, $red_fill);
            imageellipse($img, (int)$dtX, (int)$dtY, 16, 16, $red);
        }

        $blue = imagecolorallocate($img, 30, 90, 220);
        $blue_fill = imagecolorallocate($img, 60, 130, 255);
        imagefilledellipse($img, (int)$curX, (int)$curY, 18, 18, $blue_fill);
        imageellipse($img, (int)$curX, (int)$curY, 18, 18, $blue);

        imagepng($img, $map_file);
        imagedestroy($img);
    } else {
        $dtX = world_px_x($dlon, $zoom, $tile_size) - $originX;
        $dtY = world_px_y($dlat, $zoom, $tile_size) - $originY;
        $dest_on_screen = ($dtX >= 0 && $dtX <= $w && $dtY >= 0 && $dtY <= $h);
    }
}

/* ------------ CLEANUP ------------- */
foreach (glob('*.png') as $f) if (time() - filemtime($f) > 120) @unlink($f);

if (!empty($route_points)) {
    $remaining_km = 0.0;
    $n = count($route_points);
    for ($i = $ridx; $i < $n - 1; $i++) {
        $remaining_km += haversine_km(
            $route_points[$i]['lat'], $route_points[$i]['lon'],
            $route_points[$i + 1]['lat'], $route_points[$i + 1]['lon']
        );
    }
} else {
    $remaining_km = haversine_km($plat, $plon, $dlat, $dlon);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Navigation</title>
<style>
body { margin: 0; padding: 10px; background: #111; color: #eee; font-family: sans-serif; text-align: center; }
img { max-width: 100%; height: auto; border: 1px solid #444; }
.status { margin: 15px 0; font-size: 14px; padding: 10px; background: #222; border-radius: 4px; }
.nav-links { margin-top: 15px; font-size: 16px; font-weight: bold; line-height: 2; }
.nav-links a { color: #6cf; text-decoration: none; margin: 0 8px; }
.nav-links a.exit { color: #f77; }
</style>
</head>
<body>
<a href="<?= nav_link(array_merge($base, ['step' => 'dest'])) ?>">&#8593; Go</a> |
<?php if ($map_file): ?>
<img src="<?= htmlspecialchars($map_file) ?>?t=<?= time() ?>" alt="Map">
<?php else: ?>
<p>Map unavailable.</p>
<?php endif; ?>

<div class="status">
<?php if (!empty($route_points)): ?>
<?= number_format($remaining_km, 1) ?> km remaining (<?= htmlspecialchars($profile) ?>) <br> point <?= $ridx + 1 ?> / <?= count($route_points) ?>
<?php else: ?>
<?= number_format($remaining_km, 1) ?> km to destination (straight line)<?= $dest_on_screen ? '' : ' &middot; off-screen, follow the arrow' ?>
<?php endif; ?>
</div>

<div class="nav-links">
<a href="<?= nav_link(array_merge($base, ['goto' => 'dest'])) ?>">&#8677; Jump</a> |
<a href="<?= nav_link(array_merge($base, ['z' => min(17, $zoom + 1)])) ?>">+ Zoom In</a> |
<a href="<?= nav_link(array_merge($base, ['z' => max(3, $zoom - 1)])) ?>">- Zoom Out</a> |
<a class="exit" href="index.php?<?= http_build_query($exit_params) ?>">&#10005; Exit</a>
</div>

</body>
</html>
