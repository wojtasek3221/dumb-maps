<?php
/* ---------- IP DETECTION ---------- */
function get_client_ip() {
    foreach ([
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return null;
}

/* ---------- TILE MATH ---------- */
function lon2tile($lon, $zoom) {
    return (int)(($lon + 180) / 360 * pow(2, $zoom));
}

function lat2tile($lat, $zoom) {
    return (int)(
        (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2
        * pow(2, $zoom)
    );
}

function tile2lon($x, $z) {
    return $x / pow(2, $z) * 360.0 - 180.0;
}

function tile2lat($y, $z) {
    $n = pi() - 2.0 * pi() * $y / pow(2, $z);
    return rad2deg(atan(sinh($n)));
}

/* ---------- PARAMETERS ---------- */
$dx = isset($_GET['dx']) ? (int)$_GET['dx'] : 0;
$dy = isset($_GET['dy']) ? (int)$_GET['dy'] : 0;

// Get center coordinates as lat/lon for consistency
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
$zoom = isset($_GET['z']) ? (int)$_GET['z'] : 10;
$zoom = max(3, min(17, $zoom));

/* ---------- INITIAL GEOLOCATION ---------- */
if ($lat === null || $lon === null) {
    $ip = get_client_ip();
    if ($ip) {
        $geo_json = @file_get_contents("https://ipwhois.app/json/$ip");
        if ($geo_json) {
            $geo = json_decode($geo_json, true);
            if (isset($geo['latitude'], $geo['longitude'])) {
                $lat = (float)$geo['latitude'];
                $lon = (float)$geo['longitude'];
            }
        }
    }
    if ($lat === null || $lon === null) {
        $lat = 0.0;
        $lon = 0.0;
    }
}

/* ---------- APPLY MOVEMENT ---------- */
if ($dx !== 0 || $dy !== 0) {
    // Convert current position to tile coordinates
    $cx = lon2tile($lon, $zoom);
    $cy = lat2tile($lat, $zoom);
    
    // Apply movement
    $cx += $dx;
    $cy += $dy;
    
    // Convert back to lat/lon for consistency
    $lon = tile2lon($cx + 0.5, $zoom);
    $lat = tile2lat($cy + 0.5, $zoom);
}

/* ---------- MAP GENERATION ---------- */
// Now calculate tile coordinates at current zoom
$cx = lon2tile($lon, $zoom);
$cy = lat2tile($lat, $zoom);

// Bounds check
if ($cx < 0) $cx = 0;
if ($cy < 0) $cy = 0;
$max_tile = pow(2, $zoom) - 1;
if ($cx > $max_tile) $cx = $max_tile;
if ($cy > $max_tile) $cy = $max_tile;

$map_file = null;

if (extension_loaded('gd')) {
    $tile_size = 256;
    $grid = 3;
    $half = 1;

    // UNIQUE filename per state
    $map_file = "map_{$zoom}_{$cx}_{$cy}.png";

    if (!file_exists($map_file)) {
        $img = imagecreatetruecolor($tile_size * $grid, $tile_size * $grid);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);

        for ($x = -$half; $x <= $half; $x++) {
            for ($y = -$half; $y <= $half; $y++) {
                $tx = $cx + $x;
                $ty = $cy + $y;
                if ($tx < 0 || $ty < 0 || $tx > $max_tile || $ty > $max_tile) continue;

                $url = "https://tile.openstreetmap.org/$zoom/$tx/$ty.png";
                $tile = @imagecreatefrompng($url);
                if ($tile) {
                    imagecopy(
                        $img,
                        $tile,
                        ($x + $half) * $tile_size,
                        ($y + $half) * $tile_size,
                        0, 0,
                        $tile_size,
                        $tile_size
                    );
                    imagedestroy($tile);
                }
            }
        }

        imagepng($img, $map_file);
        imagedestroy($img);
    }
}

/* ------------ CLEANUP ------------- */
foreach (glob('*.png') as $f) if (time() - filemtime($f) > 300) @unlink($f);

/* ---------- LINK BUILDER ---------- */
function link_to($params) {
    return htmlspecialchars('?' . http_build_query($params));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Static Map Navigation</title>
</head>
<body>
    <p style="font-size: small;">Zoom: <?= $zoom ?> | Lat: <?= number_format($lat, 6) ?> | Lon: <?= number_format($lon, 6) ?></p>

<?php if ($map_file): ?>
    <img src="<?= htmlspecialchars($map_file) ?>" alt="Map">
<?php else: ?>
    <p>Map unavailable.</p>
<?php endif; ?>

<p>
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'dx'=>0,'dy'=>-1,'z'=>$zoom]) ?>">Up</a><br>
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'dx'=>-1,'dy'=>0,'z'=>$zoom]) ?>">Left</a>
    |
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'dx'=>1,'dy'=>0,'z'=>$zoom]) ?>">Right</a><br>
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'dx'=>0,'dy'=>1,'z'=>$zoom]) ?>">Down</a>
</p>

<p>
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'z'=>$zoom+1]) ?>">Zoom in</a>
    |
    <a href="<?= link_to(['lat'=>$lat,'lon'=>$lon,'z'=>$zoom-1]) ?>">Zoom out</a>
</p>

<p>
    <a href="<?= htmlspecialchars($map_file) ?>" download="location_map.png">
        Download map
    </a>
</p>

<p style="font-size: small;">
    IP-based initial position.<br>
    Navigation is server-side only.<br>
</p>

</body>
</html>
