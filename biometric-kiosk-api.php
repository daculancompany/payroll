<?php
/**
 * Biometric Kiosk API — mobile attendance app.
 *
 * The desktop scanner uses biometric-api.php; this adds the endpoints the
 * mobile kiosk needs on top of it.
 *
 * POST  /biometric-kiosk-api.php?action=<action>
 * Headers:
 *   Authorization: Bearer <BIOMETRIC_API_KEY>    (all actions except login/ping/health)
 *   Content-Type: application/json   (or application/x-www-form-urlencoded)
 *
 * Actions:
 *   health | ping     (no auth, GET or POST)     → liveness probe
 *   login             (no auth) username, password → access_token + site_id  [delegated]
 *   get-employees     (no body)                   → active employees  [delegated]
 *   save-face         employee_id, embedding[], [model]
 *   get-faces         [model]                     → enrolled face vectors
 *   delete-face       employee_id
 *   save-template     employee_id, finger_index, template (base64), [format], [quality]
 *   get-templates     [format]                    → mobile fingerprint templates
 *   get-finger-status employee_id, [format]       → per-finger enroll status (10-finger hands UI)
 *   delete-template   employee_id, finger_index, [format]  → remove one enrolled finger
 *   save-attendance   employee_id, scan_time, site_id, [selfie base64 JPEG]
 *   get-selfies       employee_id, [date]         → attendance photos
 *
 * Why a second file rather than more cases in biometric-api.php: the two
 * devices expose genuinely different capabilities, and keeping the kiosk's
 * action table separate means adding one here cannot regress the desktop
 * scanner. The request envelope, however, is NOT duplicated — body parsing,
 * action resolution, the bearer check and the status codes all come from
 * biometric_api_common.php, shared with biometric-api.php, because those had
 * silently drifted apart and a client could pass one endpoint and fail the
 * other for reasons unrelated to the action it called.
 *
 * Attendance itself is delegated to Action::save_biometric_attendance() via
 * save_attendance_with_selfie(), so overnight-shift re-dating and night
 * differential are computed by exactly one implementation.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/biometric_api_common.php';
require_once __DIR__ . '/biometric_kiosk_class.php';

/* ── Read the body first, so a JSON-only client can name its action there ─── */
bio_api_read_body();

// No default action: unlike the scanner, every kiosk client sends one
// explicitly, and defaulting a malformed request to save-attendance would turn
// a client bug into a stray punch.
$api_action = bio_api_resolve_action();

/* ── Liveness is public and allowed on GET ────────────────────────────────────
 * The kiosk polls it to decide whether it is online. Requiring a token would
 * make an expired session look like a dead network, pushing punches into the
 * offline queue for no reason. Both names are accepted so a client pointed at
 * the wrong endpoint still gets an answer instead of a 404.
 */
if ($api_action === 'health' || $api_action === 'ping') {
    http_response_code(200);
    exit(json_encode(bio_api_liveness($api_action)));
}

/* ── Method guard (liveness above is allowed on GET) ─────────────────────── */
bio_api_require_post();

/* ── Login is public: it is what issues the token ─────────────────────────────
 * Delegated to the same admin check the desktop scanner uses, so one account
 * works on both. Answers access_token (the API key) plus the site_id the
 * kiosk must attach to every punch.
 */
if ($api_action === 'login') {
    require_once __DIR__ . '/admin_class.php';
    $action = new Action();
    bio_api_respond($action->biometric_login(), 'login');
}

/* ── Bearer token authentication ─────────────────────────────────────────── */
bio_api_require_bearer();

/* ── Dispatch ────────────────────────────────────────────────────────────── */
$kiosk = new BiometricKiosk();

// Initialised for static analysis: every default-case path exits inside
// bio_api_unknown_action(), which PHP 7.4 cannot express as a `never` return.
$result = null;

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

    case 'get-finger-status':
        $result = $kiosk->get_finger_status();
        break;

    case 'delete-template':
        $result = $kiosk->delete_template();
        break;

    case 'save-attendance':
        $result = $kiosk->save_attendance_with_selfie();
        break;

    case 'get-selfies':
        $result = $kiosk->get_selfies();
        break;

    default:
        bio_api_unknown_action($api_action);
}

bio_api_respond($result, $api_action);
