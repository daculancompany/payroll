<?php
/**
 * Biometric Attendance API
 * Called by the fingerprint scanner desktop app / scanner hardware.
 *
 * POST  /biometric-api.php?action=<action>
 * Headers:
 *   Authorization: Bearer <BIOMETRIC_API_KEY>   (all actions except login)
 *   Content-Type: application/json   (or application/x-www-form-urlencoded)
 *
 * Actions:
 *   login             username, password              → access_token, user_id, site_id
 *   ping              (no body)                       → health check for the scanner app
 *   get-employees     (no body)                       → active employees list
 *   get-fingerprints  (no body)                       → all stored templates
 *   save-fingerprint  employee_id, finger_index, template (base64)
 *   save-attendance   employee_id, scan_time (Y-m-d H:i:s), site_id   [DEFAULT]
 */

header('Content-Type: application/json');

// ── 1. Method guard ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['result' => false, 'message' => 'Method not allowed']));
}

// ── 2. Bootstrap (loads BIOMETRIC_API_KEY constant) ─────────────────────────
require_once __DIR__ . '/db_connect.php';

// Default action stays save-attendance so existing scanner devices keep working.
$api_action = $_GET['action'] ?? $_POST['action'] ?? 'save-attendance';

// ── 3. Bearer token authentication (login is exempt — it issues the token) ──
if ($api_action !== 'login') {
    // Apache may strip Authorization header; fall back to apache_request_headers()
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
}

// ── 4. Accept JSON body or form-post ────────────────────────────────────────
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($content_type, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $_POST = array_merge($_POST, $body);
}

// ── 5. Dispatch ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/admin_class.php';
$action = new Action();

switch ($api_action) {
    case 'login':
        $result = $action->biometric_login();
        break;
    case 'ping':
        $result = ['result' => true, 'message' => 'pong', 'time' => date('Y-m-d H:i:s')];
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
    default:
        http_response_code(404);
        exit(json_encode(['result' => false, 'message' => 'Unknown action: ' . $api_action]));
}

http_response_code($result['result'] ? 200 : 422);
echo json_encode($result);
