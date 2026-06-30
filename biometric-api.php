<?php
/**
 * Biometric Attendance API
 * Called by fingerprint / face scanner hardware.
 *
 * POST  /biometric-api.php
 * Headers:
 *   Authorization: Bearer <BIOMETRIC_API_KEY>
 *   Content-Type: application/json   (or application/x-www-form-urlencoded)
 *
 * Body (JSON or form-post):
 *   employee_id  int    required
 *   scan_time    string required  format: Y-m-d H:i:s  e.g. 2025-06-30 08:05:00
 *   site_id      int    required
 *   device_id    int    required
 */

header('Content-Type: application/json');

// ── 1. Method guard ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['result' => false, 'message' => 'Method not allowed']));
}

// ── 2. Bootstrap (loads BIOMETRIC_API_KEY constant) ─────────────────────────
require_once __DIR__ . '/db_connect.php';

// ── 3. Bearer token authentication ──────────────────────────────────────────
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

// ── 4. Accept JSON body or form-post ────────────────────────────────────────
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($content_type, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $_POST = array_merge($_POST, $body);
}

// ── 5. Dispatch ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/admin_class.php';
$action = new Action();
$result = $action->save_biometric_attendance();

http_response_code($result['result'] ? 200 : 422);
echo json_encode($result);
