<?php
ob_start();
if (!isset($_GET['action'])) {
	header("HTTP/1.0 404 Not Found");
	echo "<h1>404 Error: Page Not Found</h1>";
	exit();
}

$action = $_GET['action'];
include 'admin_class.php';   // also opens the session
$crud = new Action();

/* ──────────────────────────────────────────────────────────────────────────
 * AUTHORISATION GATE
 *
 * This runs BEFORE every handler below, on purpose. It used to sit ~80 lines
 * down, which left the mobile/legacy actions above it reachable with no
 * credentials at all — an anonymous GET could dump the whole employee table.
 * Any new action added to this file is therefore protected by default; making
 * something public now requires deliberately naming it in PUBLIC_ACTIONS.
 * ────────────────────────────────────────────────────────────────────────── */

// Sign-in / sign-out only. These cannot require a session — they create it.
// login() is itself rate-limited per username+IP inside admin_class.php.
$PUBLIC_ACTIONS = ['login', 'mobile-login-check', 'logout', 'logout2'];

// Endpoints for field devices (old scanner/kiosk builds) that hold no session
// cookie. They authenticate with a shared secret instead of a session. The
// secret lives in the server environment, never in this file.
$DEVICE_ACTIONS = [
	'mobile-all-employee', 'mobile-sync-local', 'mobile-save-logs',
	'mobile-push-dtr', 'manual-push-dtr',
];

/** Reject the request and stop. */
function ajax_deny($message = 'Access Forbidden')
{
	header('HTTP/1.0 403 Forbidden');
	header('Content-Type: application/json');
	echo json_encode(['result' => false, 'message' => $message]);
	exit();
}

/**
 * True when the caller presented the device shared secret.
 *
 * Set PAYROLL_API_TOKEN in the web-server environment to enable the device
 * endpoints. Unset (the default) means no token can ever match, so those
 * endpoints stay closed unless an operator opts in.
 */
function ajax_device_authorized()
{
	$expected = (string) getenv('PAYROLL_API_TOKEN');
	if ($expected === '') {
		return false;   // fail closed
	}
	$presented = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
	if ($presented === '') {
		$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
		if (stripos($auth, 'Bearer ') === 0) {
			$presented = substr($auth, 7);
		}
	}
	// hash_equals: constant-time, so a wrong token leaks nothing by timing.
	return $presented !== '' && hash_equals($expected, $presented);
}

if (in_array($action, $DEVICE_ACTIONS, true)) {
	// A logged-in operator OR a valid device token may call these.
	if (empty($_SESSION['is_login']) && !ajax_device_authorized()) {
		ajax_deny('This endpoint requires a valid device token.');
	}
	// No CSRF check here on purpose: these callers present an explicit token
	// rather than an ambient session cookie, so there is nothing for a third
	// party site to ride on.
} elseif (!in_array($action, $PUBLIC_ACTIONS, true)) {
	// empty() covers both "never set" and "set but false" — the old condition
	// used && with a mis-parsed !$x === true and let a false value through.
	if (empty($_SESSION['is_login'])) {
		ajax_deny();
	}

	// Session-cookie-authenticated mutation: require the CSRF token. Without
	// this, any page a signed-in admin visited could POST here on their behalf.
	// GET/HEAD are exempt inside csrf_verify(). The sign-in actions above are
	// exempt because no session (and so no token) exists yet, and login() is
	// separately protected by per-username+IP rate limiting.
	if (function_exists('csrf_require')) {
		csrf_require();
	}

	// Role gate. Being signed in is not the same as being allowed: the screens
	// behind these actions are closed to some roles (payroll/DTR are admin-only,
	// HR has a people-operations slice), so the endpoints must be closed too —
	// otherwise a hidden menu item is the only thing standing in the way.
	// The lists live in db_connect.php next to the page allowlists.
	if (function_exists('action_allowed') && !action_allowed($action)) {
		ajax_deny('Your role does not have access to this action.');
	}
}


// ── Authentication ──
if ($action == "mobile-login-check") {
	$save = $crud->loginMobile();
	echo json_encode($save);
	return;
}

if ($action == 'login') {
	$save = $crud->login();
	echo json_encode($save);
	return;
}

if ($action == 'logout') {
	$logout = $crud->logout();
	if ($logout)
		echo $logout;
		return;
}
if ($action == 'logout2') {
	$logout = $crud->logout2();
	if ($logout)
		echo $logout;
	return;
}

/* login2 / signup removed.
 *
 * login2() built its WHERE clause by concatenating $_POST straight into SQL,
 * so `email=' OR 1=1 -- ` returned the first users row and was copied into
 * $_SESSION as a full admin login — an unauthenticated auth bypass. It also
 * hashed with unsalted md5() and skipped the login_attempts rate limiting that
 * login() enforces. signup() shared both flaws and self-registered type=3
 * users. Neither had a single caller anywhere in the codebase or the mobile
 * app. Use login() / save_user() instead.
 */

// ── Device / legacy mobile sync (gated above) ──
if ($action == "mobile-all-employee") {
	$save = $crud->gel_all_employee();
	echo json_encode($save);
	return;
}

if ($action == "mobile-sync-local") {
	$save = $crud->localSync();
	echo json_encode($save);
	return;
}

if ($action == "mobile-save-logs") {
	$save = $crud->saveLogs();
	echo json_encode($save);
	return;
}

if ($action == "mobile-push-dtr") {
	$save = $crud->save_employee_attendance_mobile();
	echo json_encode($save);
	return;
}

if ($action == "manual-push-dtr") {
	$save = $crud->save_employee_attendance_manual();
	echo json_encode($save);
	return;
}


if ($action == "isLock222") {
	$save = $crud->isLock();
	echo json_encode($save);
}
if ($action == "save_employee") {
	$save = $crud->save_employee();
	if ($save)
		echo $save;
}

if ($action == "save_employee_contribution") {
	$save = $crud->save_employee_contribution();
	if ($save)
		echo $save;
}



if ($action == "delete_employee") {
	$save = $crud->delete_employee();
	if ($save)
		echo $save;
}
if ($action == "save_branch") {
	echo $crud->save_branch();
	return;
}
if ($action == "delete_branch") {
	echo $crud->delete_branch();
	return;
}
// Areas answer in JSON (unlike the older 1/2 integer endpoints next door) so a
// duplicate name or a missing department can say WHY it refused.
if ($action == "save_area") {
	echo json_encode($crud->save_area());
	return;
}
if ($action == "save_area_approvers") {
	echo json_encode($crud->save_area_approvers());
	return;
}
if ($action == "save_department") {
	$save = $crud->save_department();
	if ($save)
		echo $save;
}
if ($action == "delete_department") {
	$save = $crud->delete_department();
	if ($save)
		echo $save;
}
if ($action == "save_position") {
	$save = $crud->save_position();
	if ($save)
		echo $save;
}
if ($action == "delete_position") {
	$save = $crud->delete_position();
	if ($save)
		echo $save;
}
if ($action == "save_allowances") {
	$save = $crud->save_allowances();
	if ($save)
		echo $save;
}
if ($action == "delete_allowances") {
	$save = $crud->delete_allowances();
	if ($save)
		echo $save;
}

if ($action == "save_employee_allowance") {
	$save = $crud->save_employee_allowance();
	if ($save)
		echo $save;
}
if ($action == "delete_employee_allowance") {
	$save = $crud->delete_employee_allowance();
	if ($save)
		echo $save;
}
if ($action == "delete_employee_contribution") {
	$save = $crud->delete_employee_contribution();
	if ($save)
		echo $save;
}
if ($action == "save_deductions") {
	$save = $crud->save_deductions();
	if ($save)
		echo $save;
}
if ($action == "delete_deductions") {
	$save = $crud->delete_deductions();
	if ($save)
		echo $save;
}
if ($action == "save_employee_deduction") {
	$save = $crud->save_employee_deduction();
	if ($save)
		echo $save;
}
if ($action == "delete_employee_deduction") {
	$save = $crud->delete_employee_deduction();
	if ($save)
		echo $save;
}

if ($action == "save_employee_attendance") {
	$save = $crud->save_employee_attendance();
	echo json_encode($save);
}
if ($action == "delete_employee_attendance") {
	$save = $crud->delete_employee_attendance();
	if ($save)
		echo $save;
}
if ($action == "delete_employee_attendance_single") {
	$save = $crud->delete_employee_attendance_single();
	if ($save)
		echo $save;
}
if ($action == "save_payroll") {
	$save = $crud->save_payroll();
	echo json_encode($save);
}
if ($action == "delete_payroll") {
	$save = $crud->delete_payroll();
	echo json_encode($save);
}
if ($action == "calculate_payroll") {
	$save = $crud->calculate_payroll();
	echo json_encode($save);
}
if ($action == "preview_payroll_from_dtr") {
	$save = $crud->preview_payroll_from_dtr();
	echo json_encode($save);
}

if ($action == "save_contribution") {
	$save = $crud->save_contribution();
	if ($save)
		echo $save;
}

if ($action == "save_time_logs") {
	$save = $crud->save_time_logs();
	if ($save)
		echo $save;
}

if ($action == "delete_employee_timelogs") {
	$save = $crud->delete_employee_timelogs();
	if ($save)
		echo $save;
}

if ($action == "filter_attendance") {
	$save = $crud->filter_attendance();
	if ($save)
		echo $save;
}



if ($action == "save_site") {
	$save = $crud->save_site();
	if ($save)
		echo json_encode($save);
}

if ($action == "save_user") {
	$save = $crud->save_user();
	if ($save)
		echo json_encode($save);
}

if ($action == "update_status_user") {
	$save = $crud->update_status_user();
	if ($save)
		echo json_encode($save);
}

if ($action == "update_status_dtr") {
	$save = $crud->update_status_dtr();
	if ($save)
		echo json_encode($save);
}

if ($action == "send_dtr_for_review") {
	echo json_encode($crud->send_dtr_for_review());
}

if ($action == "dtr_review_progress") {
	echo json_encode($crud->dtr_review_progress());
}

if ($action == "delete_dtr") {
	$save = $crud->delete_dtr();
	if ($save)
		echo $save;
}


if ($action == "get_sites") {
	$save = $crud->get_sites();
	if ($save)
		echo $save;
}

if ($action == "save_settings") {
	$save = $crud->save_payroll_settings();
	if ($save)
		echo json_encode($save);
}

if ($action == "delete_dtr_logs") {
	$save = $crud->delete_dtr_logs();
		echo json_encode($save);
}

if ($action == "update_dtr_logs") {
	$save = $crud->update_dtr_logs();
	echo json_encode($save);
}

if ($action == "update_payroll_item") {
	$save = $crud->update_payroll_item();
	echo json_encode($save);
}

if ($action == "set_payroll_item_review") {
	$save = $crud->set_payroll_item_review();
	echo json_encode($save);
}

if ($action == "save_payroll_item_extra") {
	echo json_encode($crud->save_payroll_item_extra());
}

if ($action == "delete_payroll_item_extra") {
	echo json_encode($crud->delete_payroll_item_extra());
}

if ($action == "remittance_breakdown") {
	echo json_encode($crud->remittance_breakdown());
}

if ($action == "unlock_payroll_item") {
	echo json_encode($crud->unlock_payroll_item());
}

if ($action == "relock_payroll_item") {
	echo json_encode($crud->relock_payroll_item());
}

if ($action == "save_fcm_token") {
	$save = $crud->save_fcm_token();
	echo json_encode($save);
}

if ($action == "update_payroll_item_new") {
	$save = $crud->update_payroll_item_new();
	echo json_encode($save);
}

if ($action == "get_payroll_rows_data") {
	echo json_encode($crud->get_payroll_rows_data());
}

if ($action == "save_payroll_amount") {
	$save = $crud->save_payroll_amount();
	echo json_encode($save);
}


if ($action == "save_cluster") {
	$save = $crud->save_cluster();
	if ($save)
		echo $save;
}

if ($action == "save_employee_loan") {
	$save = $crud->save_employee_loan();
	if ($save)
		echo $save;
}

if ($action == "active_employee_loan") {
	$save = $crud->active_employee_loan();
	if ($save)
		echo $save;
}

if ($action == "update_payroll_status") {
	$save = $crud->update_payroll_status();
	echo json_encode($save);
}

if ($action == "send_payroll_for_review") {
	echo json_encode($crud->send_payroll_for_review());
}

if ($action == "resolve_review_dispute") {
	echo json_encode($crud->resolve_review_dispute());
}

if ($action == "mark_review_seen") {
	echo json_encode($crud->mark_review_seen());
}

if ($action == "bulk_send_dtr_for_review") {
	echo json_encode($crud->bulk_send_dtr_for_review());
}

if ($action == "bulk_send_payroll_for_review") {
	echo json_encode($crud->bulk_send_payroll_for_review());
}

if ($action == "remind_dtr_review") {
	echo json_encode($crud->remind_dtr_review());
}

if ($action == "remind_payroll_review") {
	echo json_encode($crud->remind_payroll_review());
}

if ($action == "notify_payroll_review_selected") {
	echo json_encode($crud->notify_payroll_review_selected());
}

if ($action == "export_dtr_reviews") {
	ob_end_clean();
	$crud->export_dtr_reviews();
	exit;
}

if ($action == "export_payroll_reviews") {
	ob_end_clean();
	$crud->export_payroll_reviews();
	exit;
}

if ($action == "loan_history_details") {
	$save = $crud->loan_history_details();
}

if ($action == "payroll_history_details") {
	$save = $crud->payroll_history_details();
}

if ($action == "update_payroll_print") {
	$save = $crud->update_payroll_print();
}

if ($action == "save_refunds") {
	$save = $crud->save_refunds();
	if ($save)
		echo $save;
}

if ($action == "import_employee") {
	$save = $crud->import_employee();
	if ($save)
		echo $save;
}

if ($action == "preview_import_employee") {
	header('Content-Type: application/json');
	echo json_encode($crud->preview_import_employee());
}

if ($action == "compare_payrolls") {
	echo json_encode($crud->compare_payrolls());
}

// ── Leave management ──
if ($action == "save_leave_type") {
	echo json_encode($crud->save_leave_type());
}
if ($action == "delete_leave_type") {
	echo json_encode($crud->delete_leave_type());
}
if ($action == "save_leave_request") {
	echo json_encode($crud->save_leave_request());
}
if ($action == "get_leave_filing_info") {
	echo json_encode($crud->get_leave_filing_info());
}
if ($action == "decide_leave") {
	echo json_encode($crud->decide_leave());
}
if ($action == "delete_leave_request") {
	echo json_encode($crud->delete_leave_request());
}
if ($action == "save_leave_credit") {
	echo json_encode($crud->save_leave_credit());
}
if ($action == "save_leave_override") {
	echo json_encode($crud->save_leave_override());
}
if ($action == "run_leave_rollover") {
	echo json_encode($crud->run_leave_rollover());
}
if ($action == "bulk_init_leave_credits") {
	echo json_encode($crud->bulk_init_leave_credits());
}

// ── Calendar / Holidays ──
if ($action == "save_calendar_event") {
	echo json_encode($crud->save_calendar_event());
}
if ($action == "delete_calendar_event") {
	echo json_encode($crud->delete_calendar_event());
}
if ($action == "calendar_event_impact") {
	echo json_encode($crud->calendar_event_impact());
}
if ($action == "get_calendar_events") {
	echo json_encode($crud->get_calendar_events());
}

// ── Notifications ──
if ($action == "get_notifications") {
	echo json_encode($crud->get_notifications());
}
if ($action == "mark_notification_read") {
	echo json_encode($crud->mark_notification_read());
}
if ($action == "mark_all_notifications_read") {
	echo json_encode($crud->mark_all_notifications_read());
}

// ── Work Schedules ──
if ($action == 'save_work_schedule') {
    echo json_encode($crud->save_work_schedule());
}
if ($action == 'delete_work_schedule') {
    echo json_encode($crud->delete_work_schedule());
}
if ($action == 'assign_employee_schedule') {
    echo json_encode($crud->assign_employee_schedule());
}
// Admin-only destructive actions on an employee's record (role check lives in the class).
if ($action == 'delete_employee_schedule') {
    echo json_encode($crud->delete_employee_schedule());
}
if ($action == 'delete_employee_fingerprint') {
    echo json_encode($crud->delete_employee_fingerprint());
}
if ($action == 'roster_assign_schedule') {
    echo json_encode($crud->roster_assign_schedule());
}
if ($action == 'roster_update_rest_days') {
    echo json_encode($crud->roster_update_rest_days());
}
if ($action == 'roster_update_rate_type') {
    echo json_encode($crud->roster_update_rate_type());
}
if ($action == 'plan_add_schedule') {
    echo json_encode($crud->plan_add_schedule());
}
if ($action == 'plan_list') {
    echo json_encode($crud->plan_list());
}
if ($action == 'plan_remove') {
    echo json_encode($crud->plan_remove());
}
if ($action == 'plan_clear') {
    echo json_encode($crud->plan_clear());
}
if ($action == 'plan_apply_all') {
    echo json_encode($crud->plan_apply_all());
}
// ── Duty Roster (per-day cutoff grid for rotating staff) ──
if ($action == 'duty_roster_data') {
    echo json_encode($crud->duty_roster_data());
}
if ($action == 'duty_roster_areas') {
    echo json_encode($crud->duty_roster_areas());
}
if ($action == 'duty_roster_save') {
    echo json_encode($crud->duty_roster_save());
}
if ($action == 'duty_roster_publish') {
    echo json_encode($crud->duty_roster_publish());
}
if ($action == 'duty_roster_copy') {
    echo json_encode($crud->duty_roster_copy());
}
if ($action == 'duty_roster_clear_drafts') {
    echo json_encode($crud->duty_roster_clear_drafts());
}
if ($action == 'duty_roster_recompute') {
    echo json_encode($crud->duty_roster_recompute());
}
if ($action == 'duty_roster_import') {
    echo json_encode($crud->duty_roster_import());
}
if ($action == 'duty_roster_history') {
    echo json_encode($crud->duty_roster_history());
}
if ($action == 'duty_roster_set_lock') {
    echo json_encode($crud->duty_roster_set_lock());
}
if ($action == 'get_employee_schedule_history') {
    echo json_encode($crud->get_employee_schedule_history());
}
if ($action == 'employee_quick_view') {
    echo json_encode($crud->employee_quick_view());
}
if ($action == 'get_employee_edit') {
    echo json_encode($crud->get_employee_edit());
}

// ── Attendance Requests (incident reports / OT filing) ──
if ($action == 'save_attendance_request') {
    echo json_encode($crud->save_attendance_request());
}
// The day's filing ceiling, for the approver's edit form — same rule the
// employee's own portal shows (ot_request_limit), with the request being
// edited excluded from the "already filed" total.
if ($action == 'get_attendance_request') {
    echo json_encode($crud->get_attendance_request());
}

if ($action == 'attendance_request_limit') {
    echo json_encode($crud->attendance_request_limit());
}

if ($action == 'update_attendance_request') {
    echo json_encode($crud->update_attendance_request());
}

if ($action == 'decide_attendance_request') {
    echo json_encode($crud->decide_attendance_request());
}
if ($action == 'delete_attendance_request') {
    echo json_encode($crud->delete_attendance_request());
}

// ── Pay Settings ──
if ($action == 'save_pay_settings') {
    echo json_encode($crud->save_pay_settings());
}

if ($action == 'payroll_sanity_check') {
    echo json_encode($crud->payroll_sanity_check());
}

// Arithmetic reconciliation on its own — the pre-lock check folds this in, but
// it is also callable directly to audit a run at any time. Read-only.
if ($action == 'payroll_reconcile') {
    echo json_encode($crud->payroll_reconcile());
}

// ── 13th Month Pay ──
if ($action == 'th13_generate') {
    echo json_encode($crud->th13_generate());
}
if ($action == 'th13_save_row') {
    echo json_encode($crud->th13_save_row());
}
// Pay a finalized year's 13th month through a payroll run, so it reaches the
// payslip, the bank file and the withholding base instead of only a report.
if ($action == 'th13_post_to_payroll') {
    echo json_encode($crud->th13_post_to_payroll());
}
if ($action == 'th13_set_final') {
    echo json_encode($crud->th13_set_final());
}

// ── DTR Review ──
if ($action == 'save_dtr_punches') {
    echo json_encode($crud->save_dtr_punches());
}

if ($action == 'edit_dtr_time') {
    echo json_encode($crud->edit_dtr_time());
}
if ($action == 'finalize_dtr') {
    echo json_encode($crud->finalize_dtr());
}
if ($action == 'finalize_dtr_bulk') {
    echo json_encode($crud->finalize_dtr_bulk());
}
if ($action == 'decide_dtr_details') {
    echo json_encode($crud->decide_dtr_details());
}
if ($action == 'recompute_dtr') {
    echo json_encode($crud->recompute_dtr());
}
if ($action == 'recompute_employee_dtr') {
    echo json_encode($crud->recompute_employee_dtr());
}
if ($action == 'dtr_set_day_schedule') {
    echo json_encode($crud->dtr_set_day_schedule());
}
if ($action == 'message_dtr_record') {
    echo json_encode($crud->message_dtr_record());
}
if ($action == 'save_dtr_note') {
    echo json_encode($crud->save_dtr_note());
}
if ($action == 'delete_dtr_note') {
    echo json_encode($crud->delete_dtr_note());
}
if ($action == 'delete_dtr_record') {
    echo json_encode($crud->delete_dtr_record());
}
if ($action == 'review_similarity_report') {
    echo json_encode($crud->review_similarity_report());
}
if ($action == 'delete_similarity_report') {
    echo json_encode($crud->delete_similarity_report());
}






