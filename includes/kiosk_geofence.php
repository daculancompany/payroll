<?php
/**
 * Site attendance area (geofence) — kiosk Phase C.
 *
 * One circle per site: a centre, a radius in metres, and a mode that says
 * what to do about a punch outside it. Shared by the API (kiosk_api_class.php,
 * kiosk_employee_class.php) and the web side (includes/kiosk_admin.php), so
 * the device, the punch record and the admin screen all measure the same way.
 *
 * The arithmetic deliberately mirrors the mobile app's
 * src/employee/geofence.ts line for line — same earth radius, same haversine,
 * same accuracy slack, same comparison. If the two ever disagree, the app
 * would tell an employee they are inside while the server records them
 * outside, which is the one outcome nobody can explain to payroll.
 *
 * The server is still the decision: the app's copy of the circle only shapes
 * the message it shows before it posts.
 */

/** Metres. Matches EARTH_RADIUS_M in the app. */
if (!defined('GEOFENCE_EARTH_RADIUS_M')) define('GEOFENCE_EARTH_RADIUS_M', 6371000);

/**
 * How much of a poor GPS fix to forgive, in metres.
 *
 * A phone indoors often reports a fix 30–40 m off with an accuracy figure to
 * match. Without this, a staff member standing in the middle of the ward is
 * marked outside because their phone is unsure. Capped so a device claiming
 * ±2 km of uncertainty cannot excuse itself from anywhere on earth.
 * Matches MAX_ACCURACY_SLACK_M in the app.
 */
if (!defined('GEOFENCE_ACCURACY_SLACK_M')) define('GEOFENCE_ACCURACY_SLACK_M', 50);

if (!function_exists('geofence_modes')) {
    /** mode => what it does, for the editor and for help text. */
    function geofence_modes(): array
    {
        return [
            'off'    => 'No area — punches are not measured',
            'flag'   => 'Flag punches made outside the area (recommended first)',
            'strict' => 'Refuse punches made outside the area',
        ];
    }
}

if (!function_exists('site_geofence')) {
    /**
     * The area set for a site, or null when there is none to enforce.
     *
     * Returns null for mode 'off' and for a half-filled row (no centre, no
     * radius): a site that was never finished must not start refusing people.
     *
     * @return array{site_id:int,name:string,lat:float,lng:float,radius_m:int,mode:string,note:?string}|null
     */
    function site_geofence(mysqli $db, $site_id): ?array
    {
        $site_id = (int) $site_id;
        if ($site_id <= 0) return null;

        static $cache = [];
        if (array_key_exists($site_id, $cache)) return $cache[$site_id];

        static $has_cols = null;
        if ($has_cols === null) {
            $has_cols = false;
            $c = $db->query("SHOW COLUMNS FROM sites LIKE 'geofence_mode'");
            $has_cols = (bool) ($c && $c->num_rows);
        }
        if (!$has_cols) return $cache[$site_id] = null;   // migration not run yet

        $stmt = $db->prepare(
            "SELECT id, site_name, latitude, longitude, geofence_radius_m, geofence_mode, geofence_note
             FROM sites WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $site_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();

        if (!$r
            || !in_array($r['geofence_mode'], ['flag', 'strict'], true)
            || $r['latitude'] === null || $r['longitude'] === null
            || (int) $r['geofence_radius_m'] <= 0) {
            return $cache[$site_id] = null;
        }

        return $cache[$site_id] = [
            'site_id'  => (int) $r['id'],
            'name'     => (string) $r['site_name'],
            'lat'      => (float) $r['latitude'],
            'lng'      => (float) $r['longitude'],
            'radius_m' => (int) $r['geofence_radius_m'],
            'mode'     => $r['geofence_mode'],
            'note'     => $r['geofence_note'],
        ];
    }
}

if (!function_exists('geofence_distance_m')) {
    /** Great-circle metres between two points (haversine). */
    function geofence_distance_m($lat1, $lng1, $lat2, $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return GEOFENCE_EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

if (!function_exists('geofence_measure')) {
    /**
     * Measure a punch against a site's area.
     *
     * Returns null when there is nothing to measure — no area, or the punch
     * carried no coordinates. A punch with no location is NOT treated as
     * outside: location can be off for a dozen innocent reasons, and refusing
     * those is a decision for the mode, not for this function.
     *
     * @return array{distance_m:int,outside:bool}|null
     */
    function geofence_measure(?array $fence, $lat, $lng, $accuracy = null): ?array
    {
        if (!$fence || $lat === null || $lng === null) return null;

        $distance = geofence_distance_m($fence['lat'], $fence['lng'], (float) $lat, (float) $lng);
        $slack    = min(max((float) $accuracy, 0), GEOFENCE_ACCURACY_SLACK_M);

        return [
            'distance_m' => (int) round($distance),
            'outside'    => ($distance - $slack) > $fence['radius_m'],
        ];
    }
}

if (!function_exists('geofence_payload')) {
    /**
     * The area in the shape the mobile app parses (toBranchGeofence).
     *
     * Keys and types are the app's, not ours: branch_name, latitude,
     * longitude, radius_m, mode. The app drops the whole object if any one of
     * them is missing or the mode is not 'flag'/'strict', so this returns
     * null rather than a partial.
     */
    function geofence_payload(?array $fence): ?array
    {
        if (!$fence) return null;
        return [
            'branch_name' => $fence['name'],
            // Rounded to the column's own 7 decimal places (≈11 mm). Without
            // it PHP serialises the float at full precision and a tidy
            // 8.482201 goes over the wire as 8.48220099999999987971…
            'latitude'    => round($fence['lat'], 7),
            'longitude'   => round($fence['lng'], 7),
            'radius_m'    => $fence['radius_m'],
            'mode'        => $fence['mode'],
        ];
    }
}

if (!function_exists('geofence_refusal')) {
    /**
     * The message for a punch that strict mode must turn away, or null when
     * the punch may proceed.
     *
     * Only 'strict' refuses. 'flag' records the punch and marks it, which is
     * what you want while you are still learning what your radius should be.
     */
    function geofence_refusal(?array $fence, ?array $measured): ?string
    {
        if (!$fence || $fence['mode'] !== 'strict' || !$measured || !$measured['outside']) return null;

        return 'This punch was made ' . geofence_format_m($measured['distance_m'])
             . ' from ' . $fence['name'] . ', which allows '
             . geofence_format_m($fence['radius_m']) . '.';
    }
}

if (!function_exists('geofence_format_m')) {
    /** "420 m" / "1.4 km", the same wording the app uses. */
    function geofence_format_m($metres): string
    {
        $metres = (int) $metres;
        return $metres >= 1000 ? number_format($metres / 1000, 1) . ' km' : $metres . ' m';
    }
}
