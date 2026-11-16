<?php
/**
 * Kiosk API front controller — UareUApp.
 *
 *   POST /kiosk/<endpoint>        (also /api/kiosk/<endpoint>)
 *
 * .htaccess rewrites both prefixes here as ?route=<endpoint>. The route table
 * mirrors zweldo's routes/kiosk-endpoints.php one for one, so the app needs no
 * change beyond its base URL. All logic lives in kiosk_api_class.php.
 *
 * Auth: every route except health and login needs
 *   Authorization: Bearer <access_token from /kiosk/login>
 * checked by bio_api_require_bearer() — the same gate as the other biometric
 * APIs. Bodies are JSON (merged into $_POST) or form-encoded.
 *
 * The same app also has an EMPLOYEE mode — one member of staff on their own
 * phone, punching only for themselves. Those routes live under
 *   POST /api/auth/employee/<endpoint>
 * and are handled by KioskEmployeeApi (kiosk_employee_class.php). They do NOT
 * use the shared kiosk key: an employee punch carries no employee_id, so the
 * bearer token has to BE the identity, and each sign-in mints its own.
 *
 * The older ?action= kiosk API (biometric-kiosk-api.php) is untouched and keeps
 * serving any handset still built against it.
 */

header('Content-Type: application/json');

// Shortest round-trip floats. This box's Apache runs with serialize_precision
// at 17, which turns a coordinate of 8.482201 into
// 8.48220099999999987971… over the wire — the same number, but unreadable in
// a log and alarming in a bug report. -1 is PHP's own modern default; set
// here so the API reads the same wherever it is deployed.
ini_set('serialize_precision', '-1');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/biometric_api_common.php';
require_once __DIR__ . '/kiosk_api_class.php';
require_once __DIR__ . '/kiosk_employee_class.php';

/**
 * route => [allowed methods, handler method, auth]
 *
 * auth: 'none'     public
 *       'kiosk'    the shared kiosk key (bio_api_require_bearer)
 *       'employee' a per-employee token (KioskEmployeeApi::requireEmployee)
 */
$KIOSK_ROUTES = [
    'health'             => [['GET', 'POST'], 'health',            'none'],
    'login'              => [['POST'],        'login',             'none'],
    'employees'          => [['GET', 'POST'], 'employees',         'kiosk'],
    'attendance-status'  => [['GET', 'POST'], 'status',            'kiosk'],
    'attendance'         => [['POST'],        'punch',             'kiosk'],
    'attendance/selfie'  => [['POST'],        'attachSelfie',      'kiosk'],
    'templates'          => [['GET', 'POST'], 'fingerprints',      'kiosk'],
    'templates/store'    => [['POST'],        'storeFingerprint',  'kiosk'],
    'templates/status'   => [['GET', 'POST'], 'fingerStatus',      'kiosk'],
    'templates/delete'   => [['POST'],        'deleteFingerprint', 'kiosk'],
    'faces'              => [['GET', 'POST'], 'faces',             'kiosk'],
    'faces/store'        => [['POST'],        'storeFace',         'kiosk'],
    'faces/delete'       => [['POST'],        'deleteFace',        'kiosk'],
    'heartbeat'          => [['POST'],        'heartbeat',         'kiosk'],
    'recognition-events' => [['POST'],        'recognitionEvents', 'kiosk'],

    // ── Employee mode: /api/auth/employee/<x> arrives here as emp/<x> ──
    'emp/login'                => [['POST'], 'employeeLogin',             'none'],
    'emp/logout'               => [['POST'], 'employeeLogout',            'employee'],
    'emp/me'                   => [['POST'], 'employeeMe',                'employee'],
    'emp/password'             => [['POST'], 'employeePassword',          'employee'],
    'emp/faces'                => [['POST'], 'employeeFaces',             'employee'],
    'emp/status'               => [['POST'], 'employeeStatus',            'employee'],
    'emp/attendance'           => [['POST'], 'employeePunch',             'employee'],
    'emp/attendance/selfie'    => [['POST'], 'employeeAttachSelfie',      'employee'],
    'emp/attendance/history'   => [['POST'], 'employeeHistory',           'employee'],
    'emp/recognition-events'   => [['POST'], 'employeeRecognitionEvents', 'employee'],
];

/** Emit one response and stop. */
function kiosk_emit($status, array $body)
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function kiosk_error($status, $message)
{
    kiosk_emit($status, ['result' => false, 'status' => 'error', 'message' => $message]);
}

$route = trim((string) ($_GET['route'] ?? ''), '/');
unset($_GET['route']);

if (!isset($KIOSK_ROUTES[$route])) {
    kiosk_error(404, $route === '' ? 'Missing endpoint' : 'Unknown endpoint: ' . $route);
}

list($methods, $handler, $auth) = $KIOSK_ROUTES[$route];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, $methods, true)) {
    header('Allow: ' . implode(', ', $methods));
    kiosk_error(405, 'Method not allowed');
}

bio_api_read_body();

// Exits 401 on a missing or wrong token. Health and login are public: an
// expired token must not make the server look offline, and login is what
// issues the token.
if ($auth === 'kiosk') {
    bio_api_require_bearer();
}

try {
    $result = null;

    if (strpos($route, 'emp/') === 0) {
        // Employee mode. KioskEmployeeApi extends KioskApi, so everything
        // below behaves the same; only the identity is established
        // differently.
        $emp_api = new KioskEmployeeApi();
        $api     = $emp_api;

        // The per-employee token, checked here rather than inside each
        // handler so a route cannot be added that quietly forgets to ask.
        if ($auth === 'employee') {
            $result = $emp_api->requireEmployee();
        }
    } else {
        $api = new KioskApi();
    }

    if ($result === null) {
        // A tablet an administrator has blocked (lost, stolen, retired) is
        // turned away here. Nothing blocks a NEW device: installing a kiosk
        // needs no approval, so an unknown device id is simply allowed
        // through.
        $refused = $api->deviceRefusal($route);
        $result  = $refused ?: $api->$handler();
    }
} catch (\Throwable $e) {
    error_log('[kiosk-api] ' . $route . ' failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    kiosk_error(500, 'The server hit an error. Try again shortly.');
}

kiosk_emit((int) $result['status'], $result['body']);
