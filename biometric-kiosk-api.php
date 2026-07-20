<?php
/**
 * Biometric Kiosk API — mobile attendance app.
 *
 * NEW FILE. The desktop scanner keeps using biometric-api.php unchanged; this
 * adds the endpoints the mobile kiosk needs on top of it.
 *
 * POST  /biometric-kiosk-api.php?action=<action>
 * Headers:
 *   Authorization: Bearer <BIOMETRIC_API_KEY>    (all actions except health)
 *   Content-Type: application/json   (or application/x-www-form-urlencoded)
 *
 * Actions:
 *   health            (no auth, GET or POST)      → reachability probe
 *   login             (no auth) username, password → access_token + site_id  [delegated]
 *   get-employees     (no body)                   → active employees  [delegated]
 *   save-face         employee_id, embedding[], [model]
 *   get-faces         [model]                     → enrolled face vectors
 *   delete-face       employee_id
 *   save-template     employee_id, finger_index, template (base64), [format]
 *   get-templates     [format]                    → mobile fingerprint templates
 *   save-attendance   employee_id, scan_time, site_id, [selfie base64 JPEG]
 *   get-selfies       employee_id, [date]         → attendance photos
 *
 * Why a second file rather than more cases in biometric-api.php: the brief was
 * to add without touching existing code, and keeping the kiosk's surface
 * separate means a change here can never regress the desktop scanner.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/biometric_kiosk_class.php';

$api_action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ── Accept JSON body or form-post (same contract as biometric-api.php) ───── */
$content_type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($content_type, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (is_array($body)) {
        $_POST = array_merge($_POST, $body);
    }
}

// Body may carry the action too, for clients that cannot set a query string.
if ($api_action === '') {
    $api_action = $_POST['action'] ?? '';
}

/* ── Health is public and read-only ───────────────────────────────────────────
 * The kiosk polls it to decide whether it is online. Requiring a token would
 * make an expired session look like a dead network, pushing punches into the
 * offline queue for no reason. It exposes nothing but the server clock.
 */
$kiosk = new BiometricKiosk();

if ($api_action === 'health') {
    http_response_code(200);
    exit(json_encode($kiosk->health()));
}

/* ── Method guard (health above is allowed on GET) ───────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['result' => false, 'message' => 'Method not allowed']));
}

/* ── Login is public: it is what issues the token ─────────────────────────────
 * Delegated to the same admin check the desktop scanner uses, so one account
 * works on both. Answers access_token (the API key) plus the site_id the
 * kiosk must attach to every punch.
 */
if ($api_action === 'login') {
    require_once __DIR__ . '/admin_class.php';
    $action = new Action();
    $result = $action->biometric_login();
    http_response_code(!empty($result['result']) ? 200 : 401);
    exit(json_encode($result));
}

/* ── Bearer token authentication ─────────────────────────────────────────── */
$auth_header = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

$provided_key = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    $provided_key = $m[1];
}

if (!$provided_key || !hash_equals(BIOMETRIC_API_KEY, $provided_key)) {
    http_response_code(401);
    exit(json_encode(['result' => false, 'message' => 'Unauthorized']));
}

/* ── Dispatch ────────────────────────────────────────────────────────────── */
switch ($api_action) {
    case 'get-employees':
        // Delegated: the roster the kiosk shows must be the same list the
        // desktop scanner sees, so this reuses the existing implementation
        // rather than writing a second query that could drift from it.
        require_once __DIR__ . '/admin_class.php';
        $action = new Action();
        $result = $action->get_biometric_employees();
        break;

    case 'save-face':
        $result = $kiosk->save_face();
        break;

    case 'get-faces':
        $result = $kiosk->get_faces();
        break;

    case 'delete-face':
        $result = $kiosk->delete_face();
        break;

    case 'save-template':
        $result = $kiosk->save_template();
        break;

    case 'get-templates':
        $result = $kiosk->get_templates();
        break;

    case 'save-attendance':
        $result = $kiosk->save_attendance_with_selfie();
        break;

    case 'get-selfies':
        $result = $kiosk->get_selfies();
        break;

    default:
        http_response_code(404);
        exit(json_encode(['result' => false, 'message' => 'Unknown action: ' . $api_action]));
}

http_response_code(!empty($result['result']) ? 200 : 422);
echo json_encode($result);
