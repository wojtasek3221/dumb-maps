<?php
require_once __DIR__ . '/map_core.php';

/* ---------- PARAMETERS ---------- */
$dx = isset($_GET['dx']) ? (int)$_GET['dx'] : 0;
$dy = isset($_GET['dy']) ? (int)$_GET['dy'] : 0;
$zoom = isset($_GET['z']) ? (int)$_GET['z'] : 10;
$zoom = max(3, min(17, $zoom));
$profile = isset($_GET['profile']) ? $_GET['profile'] : 'driving';
if (!in_array($profile, ROUTE_PROFILES, true)) $profile = 'driving';

// slat/slon: the FIXED original start point (the route's anchor - never
// changes while stepping, only when you explicitly set a new start).
$slat = (isset($_GET['slat']) && $_GET['slat'] !== '') ? (float)$_GET['slat'] : null;
$slon = (isset($_GET['slon']) && $_GET['slon'] !== '') ? (float)$_GET['slon'] : null;
$dlat = (isset($_GET['dlat']) && $_GET['dlat'] !== '') ? (float)$_GET['dlat'] : null;
$dlon = (isset($_GET['dlon']) && $_GET['dlon'] !== '') ? (float)$_GET['dlon'] : null;

$start_error = null;
$dest_error = null;
$start_label = null;
$dest_label = null;

if (isset($_GET['start']) && trim($_GET['start']) !== '') {
    $res = resolve_location($_GET['start']);
    if ($res && $res['ok']) {
        $slat = $res['lat']; $slon = $res['lon']; $start_label = $res['label'];
    } else {
        $start_error = 'Could not find "' . htmlspecialchars($_GET['start']) . '". Try a more specific address, or "lat, lon".';
    }
}
if (isset($_GET['dest']) && trim($_GET['dest']) !== '') {
    $res = resolve_location($_GET['dest']);
    if ($res && $res['ok']) {
        $dlat = $res['lat']; $dlon = $res['lon']; $dest_label = $res['label'];
    } else {
        $dest_error = 'Could not find "' . htmlspecialchars($_GET['dest']) . '". Try a more specific address, or "lat, lon".';
    }
}

$has_start = ($slat !== null && $slon !== null);
$has_dest = ($dlat !== null && $dlon !== null && $dlat >= -85 && $dlat <= 85 && $dlon >= -180 && $dlon <= 180);
if (!$has_dest) { $dlat = null; $dlon = null; }

// plat/plon: the CURRENT navigator position (blue marker). Defaults to the
// start point, but moves independently as you pan/step - slat/slon never
// change from panning/stepping, so the route (fetched below) stays stable.
$plat = (isset($_GET['plat']) && $_GET['plat'] !== '') ? (float)$_GET['plat'] : $slat;
$plon = (isset($_GET['plon']) && $_GET['plon'] !== '') ? (float)$_GET['plon'] : $slon;
$ridx = isset($_GET['ridx']) ? max(0, (int)$_GET['ridx']) : 0;

/* ---------- FETCH ROAD ROUTE (once, always from the fixed original start) ---------- */
$route = null;
if ($has_start && $has_dest) {
    $route = get_route($slat, $slon, $dlat, $dlon, $profile);
}
$route_points = (is_array($route) && empty($route['fail'])) ? $route['points'] : [];
$route_failed = ($has_start && $has_dest && empty($route_points));
if (!empty($route_points)) {
    $ridx = min($ridx, count($route_points) - 1);
} else {
    $ridx = 0;
}

/* ---------- POINT-TO-POINT ACTIONS ---------- */
if ($has_start) {
    $goto_dest = isset($_GET['goto']) && $_GET['goto'] === 'dest' && $has_dest;

    if ($goto_dest) {
        if (!empty($route_points)) {
            $ridx = count($route_points) - 1;
            $plat = $route_points[$ridx]['lat'];
            $plon = $route_points[$ridx]['lon'];
        } else {
            $plat = $dlat;
            $plon = $dlon;
        }
    } elseif (isset($_GET['step']) && $_GET['step'] === 'dest' && $has_dest) {
        if (!empty($route_points)) {
            // Advance a real, zoom-scaled distance along the fixed route -
            // this is what actually fixes the "won't move at intersections"
            // problem: dense clusters of geometry points get skipped over
            // in one step instead of requiring many no-op clicks.
            $mpp = meters_per_pixel($plat ?? $slat, $zoom);
            $min_step_km = max(0.01, min(2.0, ($mpp * 50) / 1000));
            $ridx = advance_route_index($route_points, $ridx, $min_step_km);
            $plat = $route_points[$ridx]['lat'];
            $plon = $route_points[$ridx]['lon'];
        } else {
            // No route available: fall back to a plain one-tile step toward
            // the destination.
            $cx0 = lon2tile($plon, $zoom);
            $cy0 = lat2tile($plat, $zoom);
            $dtx = lon2tile($dlon, $zoom);
            $dty = lat2tile($dlat, $zoom);
            $dx = $dtx <=> $cx0;
            $dy = $dty <=> $cy0;
        }
    }

    /* ---------- APPLY dx/dy MOVEMENT (manual panning) ---------- */
    if ($dx !== 0 || $dy !== 0) {
        $cx = lon2tile($plon, $zoom);
        $cy = lat2tile($plat, $zoom);
        $cx += $dx;
        $cy += $dy;
        $plon = tile2lon($cx + 0.5, $zoom);
        $plat = tile2lat($cy + 0.5, $zoom);
    }
}

/* ---------- MAP GENERATION ---------- */
$map_file = null;
$dest_on_screen = false;

if ($has_start) {
    $cx = lon2tile($plon, $zoom);
    $cy = lat2tile($plat, $zoom);

    if ($cx < 0) $cx = 0;
    if ($cy < 0) $cy = 0;
    $max_tile = pow(2, $zoom) - 1;
    if ($cx > $max_tile) $cx = $max_tile;
    if ($cy > $max_tile) $cy = $max_tile;

    if (extension_loaded('gd')) {
        $tile_size = 256;
        $grid = 3;
        $half = 1;
        $canvas = $tile_size * $grid;
        $origin_tx = $cx - $half;

        // Cache key includes the EXACT current position (not just which
        // tile it falls in). A "step" often moves less than one tile-width,
        // so keying only on cx/cy meant the old cached composite (with the
        // marker still drawn at its previous spot) kept getting reused and
        // the map visually never moved even though plat/plon had changed.
        $pos_key = round($plat, 6) . '_' . round($plon, 6);
        $dest_key = $has_dest
        ? '_d' . round($dlat, 4) . '_' . round($dlon, 4) . '_' . $profile . ($route_failed ? '_nr' : '_r')
        : '';
        $map_file = "map_{$zoom}_{$cx}_{$cy}_p{$pos_key}{$dest_key}.png";

        if (!file_exists($map_file)) {
            $img = imagecreatetruecolor($canvas, $canvas);
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $white);

            for ($x = -$half; $x <= $half; $x++) {
                for ($y = -$half; $y <= $half; $y++) {
                    $tx = $cx + $x;
                    $ty = $cy + $y;
                    if ($tx < 0 || $ty < 0 || $tx > $max_tile || $ty > $max_tile) continue;

                    $tile = get_cached_tile($zoom, $tx, $ty);
                    if ($tile) {
                        imagecopy(
                            $img, $tile,
                            ($x + $half) * $tile_size, ($y + $half) * $tile_size,
                                  0, 0, $tile_size, $tile_size
                        );
                        imagedestroy($tile);
                    }
                }
            }

            $blue = imagecolorallocate($img, 30, 90, 220);
            $blue_fill = imagecolorallocate($img, 60, 130, 255);
            $curX = (lon2tileF($plon, $zoom) - $origin_tx) * $tile_size;
            $curY = (lat2tileF($plat, $zoom) - ($cy - $half)) * $tile_size;
            imagefilledellipse($img, (int)$curX, (int)$curY, 14, 14, $blue_fill);
            imageellipse($img, (int)$curX, (int)$curY, 14, 14, $blue);

            if ($has_dest) {
                $red = imagecolorallocate($img, 220, 40, 40);
                $red_fill = imagecolorallocate($img, 240, 90, 90);
                $dtX = (lon2tileF($dlon, $zoom) - $origin_tx) * $tile_size;
                $dtY = (lat2tileF($dlat, $zoom) - ($cy - $half)) * $tile_size;
                $dest_on_screen = ($dtX >= 0 && $dtX <= $canvas && $dtY >= 0 && $dtY <= $canvas);

                if (!empty($route_points)) {
                    // Draw the full road-following path. GD clips lines to
                    // the canvas automatically, so points outside the
                    // visible tiles are simply not drawn.
                    imagesetthickness($img, 4);
                    $prevPx = null;
                    foreach ($route_points as $pt) {
                        $px = (lon2tileF($pt['lon'], $zoom) - $origin_tx) * $tile_size;
                        $py = (lat2tileF($pt['lat'], $zoom) - ($cy - $half)) * $tile_size;
                        if ($prevPx !== null) {
                            imageline($img, (int)$prevPx[0], (int)$prevPx[1], (int)$px, (int)$py, $red);
                        }
                        $prevPx = [$px, $py];
                    }
                    imagesetthickness($img, 1);
                } elseif ($dest_on_screen) {
                    // Fallback: straight line (route lookup failed)
                    imagesetthickness($img, 2);
                    imageline($img, (int)$curX, (int)$curY, (int)$dtX, (int)$dtY, $red);
                    imagesetthickness($img, 1);
                }

                if (!$dest_on_screen) {
                    // Direction arrow when destination isn't in view
                    $bearing = bearing_deg($plat, $plon, $dlat, $dlon);
                    $rad = deg2rad($bearing - 90);
                    $len = 36;
                    $ax = $curX + cos($rad) * $len;
                    $ay = $curY + sin($rad) * $len;
                    imagesetthickness($img, 3);
                    imageline($img, (int)$curX, (int)$curY, (int)$ax, (int)$ay, $red);
                    imagesetthickness($img, 1);
                    $rad1 = $rad + deg2rad(150);
                    $rad2 = $rad - deg2rad(150);
                    imageline($img, (int)$ax, (int)$ay, (int)($ax + cos($rad1) * 10), (int)($ay + sin($rad1) * 10), $red);
                    imageline($img, (int)$ax, (int)$ay, (int)($ax + cos($rad2) * 10), (int)($ay + sin($rad2) * 10), $red);
                } else {
                    imagefilledellipse($img, (int)$dtX, (int)$dtY, 14, 14, $red_fill);
                    imageellipse($img, (int)$dtX, (int)$dtY, 14, 14, $red);
                }
            }

            imagepng($img, $map_file);
            imagedestroy($img);
        } elseif ($has_dest) {
            $dtX = (lon2tileF($dlon, $zoom) - $origin_tx) * $tile_size;
            $dtY = (lat2tileF($dlat, $zoom) - ($cy - $half)) * $tile_size;
            $dest_on_screen = ($dtX >= 0 && $dtX <= $canvas && $dtY >= 0 && $dtY <= $canvas);
        }
    }
}

/* ------------ CLEANUP ------------- */
// Composite images are now keyed per-exact-position, so many more small
// files accumulate during an active navigation session; prune aggressively.
// The underlying OSM tiles (tile_cache/) are unaffected and still kept for
// 7 days per policy - regenerating a composite is cheap.
foreach (glob('*.png') as $f) if (time() - filemtime($f) > 120) @unlink($f);

/* ---------- LINK BUILDER ---------- */
function link_to($params) {
    return htmlspecialchars('?' . http_build_query($params));
}

$base = ['z' => $zoom, 'profile' => $profile];
if ($has_start) {
    $base['slat'] = $slat;
    $base['slon'] = $slon;
    $base['plat'] = $plat;
    $base['plon'] = $plon;
}
if ($has_dest) {
    $base['dlat'] = $dlat;
    $base['dlon'] = $dlon;
}
if (!empty($route_points)) {
    $base['ridx'] = $ridx;
}

if ($has_start && $has_dest) {
    if (!empty($route_points)) {
        $route_distance_km = $route['distance_m'] / 1000;
        $route_duration_min = $route['duration_s'] / 60;
    }
    $straight_km = haversine_km($plat, $plon, $dlat, $dlon);
    $bearing = bearing_deg($plat, $plon, $dlat, $dlon);
    $compass = bearing_to_compass($bearing);
}

$start_input_value = isset($_GET['start']) ? $_GET['start']
: ($has_start ? number_format($slat, 6) . ', ' . number_format($slon, 6) : '');
$dest_input_value = isset($_GET['dest']) ? $_GET['dest']
: ($has_dest ? number_format($dlat, 6) . ', ' . number_format($dlon, 6) : '');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Static Map Navigation</title>
</head>
<body>
<h1 style="position: fixed; z-index: 9999;">*</h1>

<h3 style="font-size: 16px; margin-bottom: 4px;">Set start &amp; destination</h3>
<form method="get" action="" style="margin-bottom: 8px;">
<?php if ($has_dest): ?>
<input type="hidden" name="dlat" value="<?= htmlspecialchars($dlat) ?>">
<input type="hidden" name="dlon" value="<?= htmlspecialchars($dlon) ?>">
<?php endif; ?>
<input type="hidden" name="z" value="<?= htmlspecialchars($zoom) ?>">
<input type="hidden" name="profile" value="<?= htmlspecialchars($profile) ?>">
<div>
<label>Start (address or "lat, lon"):
<input type="text" name="start" value="<?= htmlspecialchars($start_input_value) ?>" size="40" placeholder="e.g. Eiffel Tower, Paris  or  48.8584, 2.2945">
</label>
</div>
<?php if ($start_error): ?><p style="color:#b00; font-size: small;"><?= $start_error ?></p><?php endif; ?>
<?php if ($start_label): ?><p style="color:#555; font-size: small;">Found: <?= htmlspecialchars($start_label) ?></p><?php endif; ?>
<button type="submit">Set start</button>
</form>

<form method="get" action="" style="margin-bottom: 8px;">
<?php if ($has_start): ?>
<input type="hidden" name="slat" value="<?= htmlspecialchars($slat) ?>">
<input type="hidden" name="slon" value="<?= htmlspecialchars($slon) ?>">
<?php endif; ?>
<input type="hidden" name="z" value="<?= htmlspecialchars($zoom) ?>">
<input type="hidden" name="profile" value="<?= htmlspecialchars($profile) ?>">
<div>
<label>Destination (address or "lat, lon"):
<input type="text" name="dest" value="<?= htmlspecialchars($dest_input_value) ?>" size="40" placeholder="e.g. Notre-Dame, Paris  or  48.8530, 2.3499">
</label>
</div>
<?php if ($dest_error): ?><p style="color:#b00; font-size: small;"><?= $dest_error ?></p><?php endif; ?>
<?php if ($dest_label): ?><p style="color:#555; font-size: small;">Found: <?= htmlspecialchars($dest_label) ?></p><?php endif; ?>
<button type="submit">Set destination</button>
</form>

<?php if ($has_start && $has_dest): ?>
<p style="font-size: small;">
Route mode:
<?php $profile_count = count(ROUTE_PROFILES); ?>
<?php foreach (ROUTE_PROFILES as $i => $p): ?>
<?php if ($p === $profile): ?>
<strong><?= ucfirst($p) ?></strong>
<?php else: ?>
<a href="<?= link_to(array_merge($base, ['profile' => $p])) ?>"><?= ucfirst($p) ?></a>
<?php endif; ?>
<?= ($i < $profile_count - 1) ? ' | ' : '' ?>
<?php endforeach; ?>
</p>
<?php endif; ?>
<hr>

<?php if (!$has_start): ?>
<p>Set a start location above to see the map.</p>
<?php else: ?>
<p style="font-size: small;">Zoom: <?= $zoom ?> | Current lat: <?= number_format($plat, 6) ?> | Current lon: <?= number_format($plon, 6) ?></p>

<?php if ($map_file): ?>
<div style="position: relative; display: inline-block;">
<img src="<?= htmlspecialchars($map_file) ?>" alt="Map">
<span style="position: absolute; bottom: 2px; right: 4px; background: rgba(255,255,255,0.7); font-size: 11px; padding: 1px 4px;">
&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors
</span>
</div>
<?php else: ?>
<p>Map unavailable.</p>
<?php endif; ?>

<p>
<a href="<?= link_to(array_merge($base, ['dx'=>0,'dy'=>-1])) ?>">Up</a><br>
<a href="<?= link_to(array_merge($base, ['dx'=>-1,'dy'=>0])) ?>">Left</a>
|
<a href="<?= link_to(array_merge($base, ['dx'=>1,'dy'=>0])) ?>">Right</a><br>
<a href="<?= link_to(array_merge($base, ['dx'=>0,'dy'=>1])) ?>">Down</a>
</p>

<p>
<a href="<?= link_to(array_merge($base, ['z'=>$zoom+1])) ?>">Zoom in</a>
|
<a href="<?= link_to(array_merge($base, ['z'=>$zoom-1])) ?>">Zoom out</a>
</p>

<?php if ($has_dest): ?>
<p style="font-size: small;">
Destination: <?= number_format($dlat, 6) ?>, <?= number_format($dlon, 6) ?><br>
<?php if (!empty($route_points)): ?>
Road distance (<?= $profile ?>): <?= number_format($route_distance_km, 1) ?> km
&middot; ETA: <?= number_format($route_duration_min, 0) ?> min
&middot; route point <?= $ridx + 1 ?> / <?= count($route_points) ?><br>
<?php else: ?>
Road route unavailable - showing straight-line distance: <?= number_format($straight_km, 1) ?> km<br>
<?php endif; ?>
Bearing: <?= number_format($bearing, 0) ?>&deg; (<?= $compass ?>)
<?= $dest_on_screen ? ' &middot; destination visible on map' : ' &middot; destination off-screen' ?>
</p>
<p>
<a href="<?= link_to(array_merge($base, ['step'=>'dest'])) ?>">Step toward destination</a>
|
<a href="<?= link_to(array_merge($base, ['goto'=>'dest'])) ?>">Jump to destination</a>
|
<a href="<?= link_to(['slat'=>$plat,'slon'=>$plon,'z'=>$zoom,'profile'=>$profile]) ?>">Clear destination</a>
</p>
<p>
<a href="nav.php?<?= http_build_query($base) ?>">&#9654; Minimalistic navigation mode</a>
</p>
<?php endif; ?>

<?php if ($map_file): ?>
<p>
<a href="<?= htmlspecialchars($map_file) ?>" download="location_map.png">
Download map
</a>
</p>
<?php endif; ?>
<?php endif; ?>

<p style="font-size: small;">
Start and destination are set manually (no IP-based guessing).<br>
Navigation is server-side only. Address lookup via
<a href="https://nominatim.openstreetmap.org" target="_blank" rel="noopener">Nominatim</a>,
routing via <a href="https://project-osrm.org" target="_blank" rel="noopener">OSRM</a>.<br>
<a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noopener">Report a map issue</a>
&middot;
Contact: <a href="mailto:appsmem@gmail.com">appsmem@gmail.com</a>
</p>

</body>
</html>
