<?php
/**
 * Endpoint for the HR Management modules (hr_class.php).
 *
 * Separate from ajax.php on purpose: that file dispatches ~300 actions into
 * admin_class.php, and these modules are meant to be addable without touching
 * it. The gate here is the SAME one, in the same order, because a second
 * endpoint with weaker rules would be the hole in the first one's wall:
 *
 *   1. a session (staff OR employee-portal)
 *   2. the CSRF token on every mutation
 *   3. the role gate for staff actions (ACTION_PAGE_MAP → page_allowed)
 *
 * Two families of action:
 *   my_*   the employee's own record. Needs an employee-portal session, and
 *          the employee id ALWAYS comes from that session, never the request.
 *   other  Admin/HR acting on any employee. Needs a staff session.
 *
 * Answers JSON: {result: bool, message: string, …}.
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/hr_class.php';

header('Content-Type: application/json');

function hr_say(array $payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '') hr_say(['result' => false, 'message' => 'No action.'], 400);

/** Actions the employee may call on their OWN record. */
const HR_SELF_ACTIONS = [
    'my_save_profile', 'my_save_photo', 'my_delete_photo',
    'my_doc_upload', 'my_doc_delete',
    'my_memo_ack', 'my_announcement_read',
    'my_request_create', 'my_request_cancel',
];

/** Actions Admin/HR may call on any employee's record. */
const HR_STAFF_ACTIONS = [
    'save_profile', 'save_dependent', 'delete_dependent',
    'save_photo', 'delete_photo',
    // HR modules (includes/hr/*). Every one is mapped to the 'employee-201'
    // screen in ACTION_PAGE_MAP, so only Admin + HR get past step 3.
    'hr_doc_upload', 'hr_doc_update', 'hr_doc_verify', 'hr_doc_delete',
    'hr_movement_save', 'hr_movement_delete',
    'hr_memo_save', 'hr_memo_close', 'hr_memo_delete',
    'hr_announcement_save', 'hr_announcement_delete',
    'hr_training_save', 'hr_training_delete',
    'hr_asset_save', 'hr_asset_return', 'hr_asset_delete',
    'hr_request_release', 'hr_request_decline',
    'hr_clearance_start', 'hr_clearance_item', 'hr_clearance_final_pay',
    'hr_clearance_complete', 'hr_clearance_cancel',
];

$isSelf = in_array($action, HR_SELF_ACTIONS, true);
if (!$isSelf && !in_array($action, HR_STAFF_ACTIONS, true)) {
    hr_say(['result' => false, 'message' => 'Unknown action: ' . $action], 404);
}

// ── 1. Session ────────────────────────────────────────────────────────────
if ($isSelf) {
    if (empty($_SESSION['emp_is_login']) || empty($_SESSION['emp_id'])) {
        hr_say(['result' => false, 'message' => 'Please sign in again.'], 403);
    }
} elseif (empty($_SESSION['is_login'])) {
    hr_say(['result' => false, 'message' => 'Access Forbidden'], 403);
}

// ── 2. CSRF ───────────────────────────────────────────────────────────────
// Every action here mutates, and all of them are authenticated by the session
// cookie alone — exactly the shape a third-party page could ride on.
if (function_exists('csrf_require')) {
    csrf_require();
}

// ── 3. Role ───────────────────────────────────────────────────────────────
// Staff actions are mapped to the 'employee-201' page in ACTION_PAGE_MAP, so
// this is the same answer the page router gives. The class re-checks the role
// itself; being signed in is not the same as being allowed.
if (!$isSelf && function_exists('action_allowed') && !action_allowed($action)) {
    hr_say(['result' => false, 'message' => 'Your role does not have access to this action.'], 403);
}

$hr = new HrManagement($conn);

if (!method_exists($hr, $action)) {
    hr_say(['result' => false, 'message' => 'Unknown action: ' . $action], 404);
}

try {
    $result = $hr->$action();
} catch (\Throwable $e) {
    error_log('[hr-ajax] ' . $action . ' failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    hr_say(['result' => false, 'message' => 'The server hit an error. Try again shortly.'], 500);
}

hr_say(is_array($result) ? $result : ['result' => false, 'message' => 'The action returned nothing.']);
