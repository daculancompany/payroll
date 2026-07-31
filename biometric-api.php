<?php
/**
 * Biometric Attendance API
 * Called by the fingerprint scanner desktop app / scanner hardware.
 *
 * POST  /biometric-api.php?action=<action>
 * Headers:
 *   Authorization: Bearer <BIOMETRIC_API_KEY>   (all actions except login/ping/health)
 *   Content-Type: application/json   (or application/x-www-form-urlencoded)
 *
 * Actions:
 *   ping | health     (no auth, GET or POST)      → liveness probe
 *   login             username, password              → access_token, user_id, site_id
 *   get-employees     (no body)                       → active employees list
 *   get-fingerprints  (no body)                       → all stored templates
 *   save-fingerprint  employee_id, finger_index, template (base64)
 *   save-attendance   employee_id, scan_time (Y-m-d H:i:s), site_id   [DEFAULT]
 *   manual-attendance employee_no, scan_time, site_id, admin_username, admin_password
 *                     (admin-authorized fallback when a finger cannot scan)
 *
 * Request handling (body parsing, action resolution, bearer check, status
 * codes) lives in biometric_api_common.php and is shared with
 * biometric-kiosk-api.php so the two endpoints cannot drift apart again.
 */

header('Content-Type: application/json');

// ── 1. Bootstrap (loads BIOMETRIC_API_KEY constant) ─────────────────────────
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/biometric_api_common.php';

// ── 2. Read the body FIRST, so a JSON-only client can name its action there ──
bio_api_read_body();

// Default stays save-attendance so existing scanner devices, which post a bare
// punch with no action at all, keep working untouched.
$api_action = bio_api_resolve_action('save-attendance');

// ── 3. Liveness is public and allowed on GET (see bio_api_liveness) ─────────
if ($api_action === 'ping' || $api_action === 'health') {
    http_response_code(200);
    exit(json_encode(bio_api_liveness($api_action)));
}

// ── 4. Method guard ─────────────────────────────────────────────────────────
bio_api_require_post();

// ── 5. Bearer token authentication (login is exempt — it issues the token) ──
if ($api_action !== 'login') {
    bio_api_require_bearer();
}

// ── 6. Dispatch ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/admin_class.php';
$action = new Action();

// Initialised for static analysis: every default-case path exits inside
// bio_api_unknown_action(), which PHP 7.4 cannot express as a `never` return.
$result = null;

switch ($api_action) {
    case 'login':
        $result = $action->biometric_login();
        break;
    case 'get-employees':
        $result = $action->get_biometric_employees();
        break;
    case 'get-fingerprints':
        $result = $action->get_biometric_fingerprints();
        break;
    case 'attendance-summary':
        $result = $action->get_biometric_attendance_summary();
        break;
    case 'save-fingerprint':
        $result = $action->save_biometric_fingerprint();
        break;
    case 'save-attendance':
        $result = $action->save_biometric_attendance();
        break;
    case 'manual-attendance':
        $result = $action->manual_biometric_attendance();
        break;
    default:
        bio_api_unknown_action($api_action);
}

bio_api_respond($result, $api_action);
