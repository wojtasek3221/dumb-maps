<?php
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

// Fractional (non-truncated) tile coordinates, needed to place markers and
// route points at the exact pixel position of a lat/lon within the
// composite image rather than snapping to a tile corner.
function lon2tileF($lon, $zoom) {
    return ($lon + 180) / 360 * pow(2, $zoom);
}

function lat2tileF($lat, $zoom) {
    return (
        1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()
    ) / 2 * pow(2, $zoom);
}

// Approx meters-per-pixel at a given latitude/zoom (Web Mercator). Used to
// size a single navigation "step" so it's always a visible move on screen,
// regardless of how tightly packed the route's geometry points are.
function meters_per_pixel($lat, $zoom) {
    return 156543.03392 * cos(deg2rad($lat)) / pow(2, $zoom);
}

/* ---------- STRAIGHT-LINE HELPERS (fallback when routing is unavailable) ---------- */
function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
    + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function bearing_deg($lat1, $lon1, $lat2, $lon2) {
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dLon = deg2rad($lon2 - $lon1);
    $y = sin($dLon) * cos($phi2);
    $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLon);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

function bearing_to_compass($deg) {
    $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE',
    'S','SSW','SW','WSW','W','WNW','NW','NNW'];
    $idx = (int)round($deg / 22.5) % 16;
    return $dirs[$idx];
}

/* ---------- APP IDENTITY (User-Agent/Referer for OSM tiles, Nominatim, OSRM) ---------- */
const APP_URL = 'https://mappy.ct.ws';

/* ---------- GEOCODING (address / place name -> lat,lon) ---------- */
// Nominatim (OpenStreetMap's own geocoder): no API key needed. Swap
// geocode_address() for a different provider if you'd rather use one -
// keep the same return shape: ['lat'=>..,'lon'=>..,'label'=>..] or
// ['fail'=>true,'reason'=>..].
const GEOCODE_CACHE_DIR = __DIR__ . '/geocode_cache';
const GEOCODE_CACHE_TTL = 24 * 60 * 60;
const GEOCODE_MIN_INTERVAL = 1.0; // Nominatim policy: max 1 request/sec
const GEOCODE_THROTTLE_FILE = GEOCODE_CACHE_DIR . '/.last_request';

function geocode_cache_path($addr) {
    return GEOCODE_CACHE_DIR . '/' . md5(strtolower(trim($addr))) . '.json';
}

function geocode_throttle() {
    if (!is_dir(GEOCODE_CACHE_DIR)) @mkdir(GEOCODE_CACHE_DIR, 0755, true);
    $last = file_exists(GEOCODE_THROTTLE_FILE) ? (float)file_get_contents(GEOCODE_THROTTLE_FILE) : 0;
    $wait = GEOCODE_MIN_INTERVAL - (microtime(true) - $last);
    if ($wait > 0) usleep((int)($wait * 1_000_000));
    @file_put_contents(GEOCODE_THROTTLE_FILE, microtime(true));
}

function geocode_address($addr) {
    $addr = trim($addr);
    if ($addr === '') return ['fail' => true, 'reason' => 'empty'];

    if (!is_dir(GEOCODE_CACHE_DIR)) @mkdir(GEOCODE_CACHE_DIR, 0755, true);
    $cpath = geocode_cache_path($addr);
    if (file_exists($cpath) && (time() - filemtime($cpath)) < GEOCODE_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cpath), true);
        if (is_array($cached)) return $cached;
    }

    if (!function_exists('curl_init')) {
        error_log("geocode_address: php-curl extension is not installed");
        return ['fail' => true, 'reason' => 'network'];
    }

    geocode_throttle();

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($addr);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: StaticMapNav/1.0 (+' . APP_URL . ')'],
                      CURLOPT_REFERER        => APP_URL,
                      CURLOPT_TIMEOUT        => 6,
                      CURLOPT_FOLLOWLOCATION => true,
                      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $http_code !== 200) {
        error_log(sprintf("geocode_address failed for '%s' | HTTP %s | curl_error: %s", $addr, $http_code, $curl_err ?: 'none'));
        return ['fail' => true, 'reason' => 'network'];
    }

    $json = json_decode($data, true);
    if (!is_array($json) || empty($json[0]['lat']) || empty($json[0]['lon'])) {
        $result = ['fail' => true, 'reason' => 'not_found'];
        @file_put_contents($cpath, json_encode($result));
        return $result;
    }

    $result = [
        'lat'   => (float)$json[0]['lat'],
        'lon'   => (float)$json[0]['lon'],
        'label' => $json[0]['display_name'] ?? $addr,
    ];
    @file_put_contents($cpath, json_encode($result));
    return $result;
}

function parse_coord_pair($raw) {
    $raw = trim($raw);
    if (preg_match('/^(-?\d+(?:\.\d+)?)[,\s]+(-?\d+(?:\.\d+)?)$/', $raw, $m)) {
        $lat = (float)$m[1];
        $lon = (float)$m[2];
        if ($lat >= -85 && $lat <= 85 && $lon >= -180 && $lon <= 180) {
            return [$lat, $lon];
        }
    }
    return null;
}

function resolve_location($raw) {
    $raw = trim($raw);
    if ($raw === '') return null;

    $pair = parse_coord_pair($raw);
    if ($pair) {
        return ['ok' => true, 'lat' => $pair[0], 'lon' => $pair[1], 'label' => null];
    }

    $geo = geocode_address($raw);
    if (!empty($geo['fail'])) {
        return ['ok' => false, 'reason' => $geo['reason'] ?? 'not_found'];
    }
    return ['ok' => true, 'lat' => $geo['lat'], 'lon' => $geo['lon'], 'label' => $geo['label'] ?? null];
}

/* ---------- ROUTING (road-following path between two points) ---------- */
// OSRM (Open Source Routing Machine), public demo server, routes on real
// OSM road data - no API key needed. Cached to disk and throttled, same
// pattern as the Nominatim geocoder above. Swap get_route() if you have
// your own OSRM/GraphHopper/Mapbox Directions instance or key.
//
// IMPORTANT: this is always called with the *original* start point, never
// the navigator's current in-progress position. That keeps the returned
// point list (and therefore route-index tracking below) stable across
// every click - previously the route was re-fetched from wherever the
// marker currently was, which re-snapped to a (sometimes different)
// nearby road on every request and made "step" behave erratically,
// especially near intersections.
const ROUTE_CACHE_DIR = __DIR__ . '/route_cache';
const ROUTE_CACHE_TTL = 24 * 60 * 60;
const ROUTE_MIN_INTERVAL = 0.5;
const ROUTE_THROTTLE_FILE = ROUTE_CACHE_DIR . '/.last_request';
const ROUTE_PROFILES = ['driving', 'walking', 'cycling'];

function route_cache_path($slat, $slon, $dlat, $dlon, $profile) {
    $key = round($slat, 5) . ',' . round($slon, 5) . ';' . round($dlat, 5) . ',' . round($dlon, 5) . ';' . $profile;
    return ROUTE_CACHE_DIR . '/' . md5($key) . '.json';
}

function route_throttle() {
    if (!is_dir(ROUTE_CACHE_DIR)) @mkdir(ROUTE_CACHE_DIR, 0755, true);
    $last = file_exists(ROUTE_THROTTLE_FILE) ? (float)file_get_contents(ROUTE_THROTTLE_FILE) : 0;
    $wait = ROUTE_MIN_INTERVAL - (microtime(true) - $last);
    if ($wait > 0) usleep((int)($wait * 1_000_000));
    @file_put_contents(ROUTE_THROTTLE_FILE, microtime(true));
}

// Returns ['points'=>[['lat'=>..,'lon'=>..], ...], 'distance_m'=>.., 'duration_s'=>..]
// on success, or ['fail'=>true,'reason'=>..] on failure.
function get_route($slat, $slon, $dlat, $dlon, $profile) {
    if (!in_array($profile, ROUTE_PROFILES, true)) $profile = 'driving';

    if (!is_dir(ROUTE_CACHE_DIR)) @mkdir(ROUTE_CACHE_DIR, 0755, true);
    $cpath = route_cache_path($slat, $slon, $dlat, $dlon, $profile);
    if (file_exists($cpath) && (time() - filemtime($cpath)) < ROUTE_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cpath), true);
        if (is_array($cached)) return $cached;
    }

    if (!function_exists('curl_init')) {
        error_log("get_route: php-curl extension is not installed");
        return ['fail' => true, 'reason' => 'network'];
    }

    route_throttle();

    $url = sprintf(
        'https://router.project-osrm.org/route/v1/%s/%F,%F;%F,%F?overview=full&geometries=geojson',
        $profile, $slon, $slat, $dlon, $dlat
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: StaticMapNav/1.0 (+' . APP_URL . ')'],
                      CURLOPT_REFERER        => APP_URL,
                      CURLOPT_TIMEOUT        => 8,
                      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $http_code !== 200) {
        error_log(sprintf("get_route failed | HTTP %s | curl_error: %s", $http_code, $curl_err ?: 'none'));
        return ['fail' => true, 'reason' => 'network'];
    }

    $json = json_decode($data, true);
    if (!is_array($json) || ($json['code'] ?? '') !== 'Ok' || empty($json['routes'][0])) {
        $result = ['fail' => true, 'reason' => 'no_route'];
        @file_put_contents($cpath, json_encode($result));
        return $result;
    }

    $route = $json['routes'][0];
    $coords = $route['geometry']['coordinates'] ?? []; // array of [lon, lat]
    $points = [];
    foreach ($coords as $c) {
        $points[] = ['lat' => $c[1], 'lon' => $c[0]];
    }

    $result = [
        'points'     => $points,
        'distance_m' => $route['distance'] ?? null,
        'duration_s' => $route['duration'] ?? null,
    ];
    @file_put_contents($cpath, json_encode($result));
    return $result;
}

// Advances a route-point index forward by real distance (not just "+1 array
// index"), so a single step is a visible move even where the route geometry
// has many closely-spaced points (typical at intersections/curves).
function advance_route_index(array $route_points, int $ridx, float $min_km) {
    $n = count($route_points);
    if ($n === 0) return 0;
    if ($ridx >= $n - 1) return $n - 1;
    $acc = 0.0;
    $i = $ridx;
    while ($i < $n - 1) {
        $acc += haversine_km(
            $route_points[$i]['lat'], $route_points[$i]['lon'],
            $route_points[$i + 1]['lat'], $route_points[$i + 1]['lon']
        );
        $i++;
        if ($acc >= $min_km) break;
    }
    return $i;
}

/* ---------- TILE FETCH (OSM usage policy compliant) ---------- */
function fetch_osm_tile($url) {
    if (!function_exists('curl_init')) {
        error_log("fetch_osm_tile: php-curl extension is not installed");
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: StaticMapNav/1.0 (+' . APP_URL . ')'],
                      CURLOPT_REFERER        => APP_URL,
                      CURLOPT_TIMEOUT        => 5,
                      CURLOPT_FOLLOWLOCATION => true,
                      CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $http_code !== 200) {
        error_log(sprintf("fetch_osm_tile failed for %s | HTTP %s | curl_error: %s", $url, $http_code, $curl_err ?: 'none'));
        return null;
    }

    return $data;
}

const TILE_CACHE_DIR = __DIR__ . '/tile_cache';
const TILE_CACHE_TTL = 7 * 24 * 60 * 60; // 7 days, per OSM tile policy minimum

function get_cached_tile($z, $x, $y) {
    if (!is_dir(TILE_CACHE_DIR)) {
        @mkdir(TILE_CACHE_DIR, 0755, true);
    }

    $path = TILE_CACHE_DIR . "/{$z}_{$x}_{$y}.png";

    if (file_exists($path) && (time() - filemtime($path)) < TILE_CACHE_TTL) {
        return @imagecreatefromstring(file_get_contents($path));
    }

    $url = "https://tile.openstreetmap.org/$z/$x/$y.png";
    $data = fetch_osm_tile($url);
    if ($data === null) {
        return file_exists($path) ? @imagecreatefromstring(file_get_contents($path)) : null;
    }

    @file_put_contents($path, $data);
    return @imagecreatefromstring($data);
}

/* ---------- GENERIC WORLD-PIXEL HELPERS ----------
   Used by nav.php to composite a map canvas of an arbitrary width/height
   (fitted to the viewport) with the current position exactly centered,
   as opposed to index.php's fixed 3x3-tile square grid. */
function world_px_x($lon, $zoom, $tile_size) {
    return lon2tileF($lon, $zoom) * $tile_size;
}

function world_px_y($lat, $zoom, $tile_size) {
    return lat2tileF($lat, $zoom) * $tile_size;
}
