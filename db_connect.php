<?php
/* App release shown on the login screen (mobile-app style: v<major>.<minor>.<build>).
 * Bump this on every deploy so users can report which build they are on. */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.0.02');
}

/* ──────────────────────────────────────────────────────────────
 * Central error-reporting switch.
 *   dev  → show every error on screen (for debugging)
 *   prod → never show errors to users; log them to logs/php-error.log
 *
 * This USED to be a hardcoded 'dev', which meant a deploy that forgot to edit
 * this line served stack traces, absolute paths and SQL fragments to every
 * visitor — and turned any injection point into an easily-exploited
 * error-based one. It now fails SAFE: anything not positively identified as a
 * local development host is treated as production.
 *
 * Resolution order:
 *   1. APP_ENV in the web-server environment (authoritative — set this on the
 *      live box: SetEnv APP_ENV prod).
 *   2. Loopback/private hostnames → dev, so local XAMPP work keeps its errors
 *      on screen with no configuration.
 *   3. Everything else → prod.
 * ────────────────────────────────────────────────────────────── */
if (!defined('APP_ENV')) {
    $__env = strtolower(trim((string) getenv('APP_ENV')));

    if (!in_array($__env, ['dev', 'prod'], true)) {
        $__host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? php_uname('n')));
        $__host = preg_replace('/:\d+$/', '', $__host);   // strip :8080 etc.

        $__is_local = in_array($__host, ['localhost', '127.0.0.1', '::1', ''], true)
            || substr($__host, -6) === '.local'
            || substr($__host, -5) === '.test'
            || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $__host) === 1;

        $__env = $__is_local ? 'dev' : 'prod';
    }

    define('APP_ENV', $__env);
    unset($__env, $__host, $__is_local);
}

/* ──────────────────────────────────────────────────────────────
 * Deployment role — lets ONE codebase serve two very different boxes.
 *
 *   'full'   → everything (default; unchanged behaviour for existing installs)
 *   'local'  → the offline payroll machine kept off the internet:
 *              ADMIN LOGIN ONLY, and the menu is limited to DTR + Payroll.
 *              Employee portal logins are refused outright.
 *   'portal' → the internet-facing box: employee portal only, no admin app.
 *
 * Set it per deployment — either edit this line on that machine, or export
 * APP_ROLE in the web server environment (the env var wins).
 * ────────────────────────────────────────────────────────────── */
if (!defined('APP_ROLE')) {
    $__role = getenv('APP_ROLE');
    define('APP_ROLE', in_array($__role, ['full', 'local', 'portal'], true) ? $__role : 'full');
}

// Pages the admin app is allowed to route to when APP_ROLE = 'local'.
// Everything else 404s / redirects, so a stray link can't open a screen that
// has no business being on the payroll machine.
if (!defined('LOCAL_ALLOWED_PAGES')) {
    define('LOCAL_ALLOWED_PAGES', [
        'dtr', 'dtr-details',                                   // DTR review
        'payroll', 'payroll_items', 'payroll_calculations',     // Payroll
        'profile',                                              // own account
    ]);
}

// Guarded like the other helpers in this file so a double include can't
// trigger a redeclare fatal.
if (!function_exists('app_is_local')) {
    /** True when this deployment is the offline payroll machine. */
    function app_is_local() { return APP_ROLE === 'local'; }
    /** True when this deployment is the public employee portal. */
    function app_is_portal() { return APP_ROLE === 'portal'; }
    /** Is $page reachable in this deployment role? */
    function app_page_allowed($page) {
        return app_is_local() ? in_array($page, LOCAL_ALLOWED_PAGES, true) : true;
    }
}

// ── Timekeeper (role 5) ─────────────────────────────────────────────────
// The Timekeeper is a scanner-device operator. On the desktop/kiosk scanner
// they sign in and enroll fingerprints; on the web portal they get a
// read-only slice — the employee attendance report, the employee list, and
// an employee detail page that shows NOTHING but the enrolled fingerprints.
// No pay, no payroll, no leave, no user management.
if (!defined('ROLE_TIMEKEEPER')) {
    define('ROLE_TIMEKEEPER', 5);
}

// The only pages a Timekeeper may route to in index.php. Anything else is
// bounced back to the attendance report, so a stray link or a hand-typed
// ?page= can't open a screen they have no business seeing.
if (!defined('TIMEKEEPER_ALLOWED_PAGES')) {
    define('TIMEKEEPER_ALLOWED_PAGES', [
        'attendance-summary',   // Employee Attendance Report (landing page)
        'employee',             // Employee list
        'employee-details',     // → fingerprints-only view (see index.php routing)
        'employee-fingerprints',// the one thing they may WRITE: enrolled prints
        'profile',              // own account
    ]);
}

// ── Leave approvers: Supervisor + Department Head ───────────────────────
// These two roles exist only to review their OWN department's leave. They get
// a leave-only slice of the admin app — a dashboard, the requests awaiting
// their stage, and a name + leave-count roster of their department.
//
// Explicitly NOT theirs: DTR, payroll, salary figures, employee personal
// details, users, reports, settings. Everything outside the allowlist below
// redirects to the dashboard, so a stray link or a hand-typed ?page= cannot
// open a screen with pay data on it.
//
// HR (role 9) is a leave approver too but is NOT listed here — HR is not
// restricted to the leave-only slice; they have their own, wider slice
// defined in the HR block further down. Edit these two constants to adjust
// the Supervisor / Department Head slice app-wide.
if (!defined('LEAVE_APPROVER_ROLES')) {
    define('LEAVE_APPROVER_ROLES', [8, 10]);   // 8 = Department Head, 10 = Supervisor
}

if (!defined('LEAVE_APPROVER_ALLOWED_PAGES')) {
    define('LEAVE_APPROVER_ALLOWED_PAGES', [
        'leave-dashboard',   // landing page: counts + department roster
        'leaves',            // the requests they must act on (already dept-scoped)
        'calendar',          // holiday calendar — READ-ONLY (see calendar.php)
        'duty-roster',       // their own ward's duty grid — READ-ONLY, and locked
                             // to their department by dept-scope.php. They approve
                             // leave against this schedule, so being unable to see
                             // it made them approve blind; and the Excel export is
                             // how the ward gets its copy.
        'profile',           // own account
    ]);
}

if (!function_exists('is_leave_approver')) {
    /**
     * True when $role (default: the signed-in web session role) is restricted to
     * the leave-only slice. Reads the session defensively so it is safe to call
     * from AJAX endpoints that may not have a session at all.
     */
    function is_leave_approver($role = null): bool
    {
        if ($role === null) {
            if (session_status() === PHP_SESSION_NONE) return false;
            $role = $_SESSION['login_role'] ?? 0;
        }
        return in_array((int) $role, LEAVE_APPROVER_ROLES, true);
    }

    /** Is $page reachable for a Supervisor / Department Head? */
    function leave_approver_page_allowed($page): bool
    {
        return in_array($page, LEAVE_APPROVER_ALLOWED_PAGES, true);
    }
}

if (!function_exists('is_timekeeper')) {
    /**
     * True when $role (default: the signed-in web session role) is a Timekeeper.
     * Reads the session defensively so it is safe to call from AJAX endpoints
     * that may not have a session at all.
     */
    function is_timekeeper($role = null)
    {
        if ($role === null) {
            if (session_status() === PHP_SESSION_NONE) return false;
            $role = $_SESSION['login_role'] ?? 0;
        }
        return (int) $role === ROLE_TIMEKEEPER;
    }

    /** Is $page reachable for a Timekeeper? */
    function timekeeper_page_allowed($page)
    {
        return in_array($page, TIMEKEEPER_ALLOWED_PAGES, true);
    }
}

// ── Payroll & DTR: administrators only ──────────────────────────────────
// The money screens and the raw time record behind them. Anyone who can open
// these can see (and rewrite) what every employee is paid, so they are pinned
// to a single role list instead of being scattered across per-page checks.
//
// Widening access = add the role id here, nowhere else.
if (!defined('PAYROLL_DTR_ROLES')) {
    define('PAYROLL_DTR_ROLES', [1]);   // 1 = Administrator
}

if (!defined('ADMIN_ONLY_PAGES')) {
    define('ADMIN_ONLY_PAGES', [
        'payroll',                // payroll batches
        'payroll_items',
        'payroll_calculations',   // the pay computation sheet (standalone page)
        'dtr',                    // DTR Review
        'dtr-details',
        'dtr-documents',          // standalone DTR workbench (not an index.php route)
        'compare-dtr',
        'biometric-dtr',
    ]);
}

// ── HR (role 9) ─────────────────────────────────────────────────────────
// HR runs people operations, not pay. Theirs: the dashboard, the employee and
// org screens, the whole leave workflow, and a LOOK at attendance. Not theirs:
// shift scheduling, DTR, payroll, pay settings, 13th month, every report, and
// user management. Everything outside this list lands on the dashboard, so a
// hidden menu item and a hand-typed ?page= give the same answer.
if (!defined('ROLE_HR')) {
    define('ROLE_HR', 9);
}

if (!defined('HR_ALLOWED_PAGES')) {
    define('HR_ALLOWED_PAGES', [
        'home', 'daily-board',
        'employee', 'employee-details',          // people records
        'department', 'position',
        'attendance', 'attendance-requests',     // READ-ONLY (see HR_READONLY_PAGES)
        'leave-dashboard', 'leaves', 'leave_types',
        'leave_balances', 'leave-balances-report', 'calendar',
        'sites',                                 // biometric sites
        'profile',                               // own account
    ]);
}

// Pages HR may open but must not act on. The pages themselves hide their
// action buttons for HR; the endpoints below are the actual boundary.
if (!defined('HR_READONLY_PAGES')) {
    define('HR_READONLY_PAGES', [
        'attendance', 'attendance-requests',
        'employee', 'employee-details',   // people records: look, don't touch
    ]);
}

if (!function_exists('is_hr')) {
    /**
     * True when $role (default: the signed-in web session role) is HR.
     * Reads the session defensively so it is safe to call from AJAX endpoints
     * that may not have a session at all.
     */
    function is_hr($role = null): bool
    {
        if ($role === null) {
            if (session_status() === PHP_SESSION_NONE) return false;
            $role = $_SESSION['login_role'] ?? 0;
        }
        return (int) $role === ROLE_HR;
    }

    /** Is $page reachable for HR? */
    function hr_page_allowed($page): bool
    {
        return in_array($page, HR_ALLOWED_PAGES, true);
    }

    /** True when HR may look at $page but not change anything on it. */
    function hr_readonly_page($page): bool
    {
        return in_array($page, HR_READONLY_PAGES, true);
    }
}

// ── Per-page role lists ─────────────────────────────────────────────────
// Screens that were gated by an inline role check in the sidebar. The menu
// used to hold these lists on its own, which meant the link was hidden but the
// URL still worked. They live here now so page_allowed() answers for both.
// A page not listed here is open to every role its slice allows.
if (!defined('PAGE_ROLE_RESTRICTIONS')) {
    define('PAGE_ROLE_RESTRICTIONS', [
        'attendance-requests' => [1, 8, 9],   // HR sees it READ-ONLY
        'pay-settings'        => [1, 8],
        'thirteenth-month'    => [1, 8],
        'users'               => [1, 2, 3, 8],
    ]);
}

// ── One gate for every screen ───────────────────────────────────────────
// Every restriction above, composed. index.php routes through it, the sidebar
// hides links with it, and standalone pages/exports enforce it — so the menu
// and the URL bar can never disagree about who may open what.
if (!function_exists('page_allowed')) {
    /** Current web session role (0 when signed out). */
    function current_role(): int
    {
        if (session_status() === PHP_SESSION_NONE) return 0;
        return (int) ($_SESSION['login_role'] ?? 0);
    }

    /** May $role open $page? The single answer the whole app asks. */
    function page_allowed($page, $role = null): bool
    {
        if ($role === null) $role = current_role();
        $role = (int) $role;

        if (!app_page_allowed($page))                      return false;  // offline payroll box
        if (in_array($page, ADMIN_ONLY_PAGES, true)
            && !in_array($role, PAYROLL_DTR_ROLES, true))  return false;  // payroll + DTR

        $only = PAGE_ROLE_RESTRICTIONS;
        if (isset($only[$page]) && !in_array($role, $only[$page], true)) return false;

        if (is_timekeeper($role))                          return timekeeper_page_allowed($page);
        if (is_leave_approver($role))                      return leave_approver_page_allowed($page);
        if (is_hr($role))                                  return hr_page_allowed($page);

        return true;
    }

    /** Where a blocked request lands — each role's own home screen. */
    function role_landing_page($role = null): string
    {
        if ($role === null) $role = current_role();
        if (app_is_local())            return 'dtr';
        if (is_timekeeper($role))      return 'attendance-summary';
        if (is_leave_approver($role))  return 'leave-dashboard';
        return 'home';
    }

    /**
     * Enforce page_allowed() from a standalone page, export or data feed that
     * index.php's ?page= router never sees. $mode picks how to say no:
     * 'page' redirects to the caller's landing screen, 'json' / 'text' answer 403.
     */
    function require_page_access($page, $mode = 'page'): void
    {
        if (page_allowed($page)) return;

        if ($mode === 'json') {
            header('HTTP/1.0 403 Forbidden');
            header('Content-Type: application/json');
            echo json_encode(['result' => false, 'message' => 'Your role does not have access to this screen.']);
        } elseif ($mode === 'text') {
            header('HTTP/1.0 403 Forbidden');
            echo 'Forbidden — your role does not have access to this screen.';
        } else {
            header('Location: index.php?page=' . role_landing_page());
        }
        exit;
    }
}

// ── Endpoints behind a restricted screen ────────────────────────────────
// A hidden menu item is not a permission: whoever cannot open a screen must
// not be able to POST to what that screen talks to either. So instead of a
// per-role list of banned endpoints — one that goes stale the moment a screen
// moves — every action names the SCREEN THAT OWNS IT, and its permission is
// derived: you may call it if you may open that screen (and, for writes, if
// your role is not read-only there).
//
// Adding an endpoint? Name its page here. An unlisted action stays reachable
// by any signed-in user, exactly as before.
if (!defined('ACTION_PAGE_MAP')) {
    define('ACTION_PAGE_MAP', [
        // payroll batches & computation
        'calculate_payroll' => 'payroll', 'save_payroll' => 'payroll',
        'delete_payroll' => 'payroll', 'save_payroll_amount' => 'payroll',
        'update_payroll_item' => 'payroll', 'update_payroll_item_new' => 'payroll',
        'update_payroll_print' => 'payroll', 'update_payroll_status' => 'payroll',
        'save_payroll_item_etra' => 'payroll', 'delete_payroll_item_etra' => 'payroll',
        'get_payroll_rows_data' => 'payroll', 'payroll_history_details' => 'payroll',
        'payroll_sanity_check' => 'payroll', 'payroll_reconcile' => 'payroll',
        'compare_payrolls' => 'payroll',
        'remittance_breakdown' => 'payroll', 'isLock' => 'payroll',
        'relock_payroll_item' => 'payroll', 'unlock_payroll_item' => 'payroll',
        'set_payroll_item_review' => 'payroll', 'send_payroll_for_review' => 'payroll',
        'bulk_send_payroll_for_review' => 'payroll', 'notify_payroll_review_selected' => 'payroll',
        'remind_payroll_review' => 'payroll', 'eport_payroll_reviews' => 'payroll',
        // DTR review
        'decide_dtr_details' => 'dtr', 'delete_dtr' => 'dtr', 'delete_dtr_logs' => 'dtr',
        'delete_dtr_note' => 'dtr', 'delete_dtr_record' => 'dtr', 'edit_dtr_time' => 'dtr',
        'finalize_dtr' => 'dtr', 'finalize_dtr_bulk' => 'dtr', 'message_dtr_record' => 'dtr',
        'recompute_dtr' => 'dtr', 'save_dtr_note' => 'dtr', 'update_dtr_logs' => 'dtr',
        // Scoped recompute rides the schedule-assign flow (employee-details
        // modal), so it carries that page's permission, not the DTR screen's.
        'recompute_employee_dtr' => 'employee-details',
        'update_status_dtr' => 'dtr', 'send_dtr_for_review' => 'dtr',
        'bulk_send_dtr_for_review' => 'dtr', 'remind_dtr_review' => 'dtr',
        'dtr_review_progress' => 'dtr', 'eport_dtr_reviews' => 'dtr',
        'mark_review_seen' => 'dtr', 'resolve_review_dispute' => 'dtr',
        // shift roster & work schedules
        'roster_assign_schedule' => 'schedule-roster', 'roster_update_rest_days' => 'schedule-roster',
        'roster_update_rate_type' => 'schedule-roster', 'plan_add_schedule' => 'schedule-roster',
        'plan_apply_all' => 'schedule-roster', 'plan_clear' => 'schedule-roster',
        'plan_remove' => 'schedule-roster', 'plan_list' => 'schedule-roster',
        'duty_roster_data' => 'duty-roster', 'duty_roster_save' => 'duty-roster',
        'duty_roster_publish' => 'duty-roster', 'duty_roster_copy' => 'duty-roster',
        'duty_roster_clear_drafts' => 'duty-roster', 'duty_roster_recompute' => 'duty-roster',
        // Import only previews, but it is the front half of a write and is kept
        // out of READ_ONLY_ACTIONS so a look-but-don't-touch role is refused.
        'duty_roster_import' => 'duty-roster',
        'save_work_schedule' => 'work-schedules', 'delete_work_schedule' => 'work-schedules',
        // employee records (incl. their pay rows, which live on the detail page)
        'save_employee' => 'employee', 'delete_employee' => 'employee',
        'import_employee' => 'employee',
        'assign_employee_schedule' => 'employee-details', 'delete_employee_schedule' => 'employee-details',
        'get_employee_schedule_history' => 'employee-details',
        'employee_quick_view' => 'employee-details',
        'save_employee_loan' => 'employee-details', 'active_employee_loan' => 'employee-details',
        'loan_history_details' => 'employee-details',
        'save_employee_allowance' => 'employee-details', 'delete_employee_allowance' => 'employee-details',
        'save_employee_deduction' => 'employee-details', 'delete_employee_deduction' => 'employee-details',
        'save_employee_contribution' => 'employee-details', 'delete_employee_contribution' => 'employee-details',
        // fingerprints are their own permission: the Timekeeper's whole job is
        // enrolling them, while the rest of the detail page stays read-only
        'delete_employee_fingerprint' => 'employee-fingerprints',
        'delete_employee_timelogs' => 'employee-details',
        // attendance
        'save_employee_attendance' => 'attendance', 'delete_employee_attendance' => 'attendance',
        'delete_employee_attendance_single' => 'attendance', 'save_time_logs' => 'attendance',
        'decide_attendance_request' => 'attendance-requests',
        'delete_attendance_request' => 'attendance-requests',
        'save_attendance_request' => 'attendance-requests',
        // pay settings, 13th month, user management
        'save_pay_settings' => 'pay-settings',
        'th13_generate' => 'thirteenth-month', 'th13_save_row' => 'thirteenth-month',
        'th13_set_final' => 'thirteenth-month',
        'th13_post_to_payroll' => 'thirteenth-month',
        'save_user' => 'users', 'update_status_user' => 'users',
    ]);
}

// Mapped actions that only READ. A read-only role (HR on attendance and the
// employee records) may still call these; everything else mapped is a write.
if (!defined('READ_ONLY_ACTIONS')) {
    define('READ_ONLY_ACTIONS', [
        'get_employee_schedule_history', 'employee_quick_view',
        'loan_history_details', 'plan_list', 'duty_roster_data',
        'get_payroll_rows_data', 'payroll_history_details', 'remittance_breakdown',
        'isLock', 'dtr_review_progress', 'eport_payroll_reviews', 'eport_dtr_reviews',
    ]);
}

// Look-but-don't-touch screens, per role. A role listed here can open the page
// and read it, but every write endpoint the page owns is refused and its action
// buttons are not rendered.
if (!defined('READONLY_PAGES_BY_ROLE')) {
    define('READONLY_PAGES_BY_ROLE', [
        ROLE_HR          => HR_READONLY_PAGES,
        ROLE_TIMEKEEPER  => ['employee', 'employee-details'],  // enrols prints, edits nothing else
        7                => ['employee', 'employee-details', 'attendance'],  // Auditor reviews, never edits
    ]);
}

/**
 * Endpoints a role is refused even on a screen it may otherwise edit.
 *
 * READONLY_PAGES_BY_ROLE is all-or-nothing per screen, and the duty roster
 * needs a line drawn INSIDE one screen: a Department Head plans their own ward
 * — paints, saves, imports, discards their own drafts — but does not publish.
 * Publishing is the act that notifies every employee named on the sheet and
 * lets the roster reach DTR figures and the portal, so it stays with whoever
 * owns the whole cutoff. Recompute rewrites attendance figures and is further
 * still from a ward's job.
 *
 * A draft written by a head is therefore always reviewed by someone else
 * before it reaches an employee, which is the point of the split.
 */
if (!defined('ACTION_DENY_BY_ROLE')) {
    define('ACTION_DENY_BY_ROLE', [
        8  => ['duty_roster_publish', 'duty_roster_recompute'],   // Department Head
        10 => ['duty_roster_publish', 'duty_roster_recompute'],   // Supervisor
    ]);
}

if (!function_exists('can_edit')) {
    /**
     * May $role CHANGE things on $page? False when the page is closed to them,
     * and false when their role has it as a look-but-don't-touch screen.
     * Pages call this to decide whether to render action buttons at all;
     * ajax.php calls it for every write endpoint. One answer, both places.
     */
    function can_edit($page, $role = null): bool
    {
        if ($role === null) $role = current_role();
        $role = (int) $role;
        if (!page_allowed($page, $role)) return false;

        $ro = READONLY_PAGES_BY_ROLE;
        if (isset($ro[$role]) && in_array($page, $ro[$role], true)) return false;

        return true;
    }

    /** May the signed-in role call this ajax action? */
    function action_allowed($action, $role = null): bool
    {
        if ($role === null) $role = current_role();

        $map = ACTION_PAGE_MAP;
        if (!isset($map[$action])) return true;      // unmapped: session gate only

        $page = $map[$action];
        if (!page_allowed($page, $role)) return false;
        if (in_array($action, READ_ONLY_ACTIONS, true)) return true;

        // Per-endpoint refusals inside a screen the role may otherwise edit.
        $deny = ACTION_DENY_BY_ROLE;
        if (isset($deny[(int) $role]) && in_array($action, $deny[(int) $role], true)) return false;

        return can_edit($page, $role);
    }
}

// Application timezone — used by all PHP date()/DateTime calls.
date_default_timezone_set('Asia/Manila');

// ── Asset cache-busting (GLOBAL) ────────────────────────────────────────
// Stamp local CSS/JS with its file mtime so an edited stylesheet can never be
// served from a stale browser cache. This used to live only in
// employee-portal.php, which is why the admin pages kept rendering old rules
// after a CSS change. Use it for EVERY local asset link, by echoing av($path)
// into the href of a <link> or the src of a <script>.
//
// NOTE: do NOT put a literal PHP close tag in a comment here — even inside a
// "//" comment it ends PHP mode, and everything below (including $conn) would
// silently stop executing. php -l does not flag it.
if (!function_exists('av')) {
    function av($path)
    {
        $t = @filemtime(__DIR__ . '/' . $path);
        return $path . '?v=' . ($t ?: '1');
    }
}

// ── Leave eligibility (GLOBAL) ──────────────────────────────────────────
// Only these employee classifications are entitled to leave / leave credits.
// Matched by NAME (case-insensitive) so it works regardless of table IDs.
// Change this ONE line to adjust who can have leave app-wide.
if (!defined('LEAVE_ELIGIBLE_CLASSIFICATIONS')) {
    define('LEAVE_ELIGIBLE_CLASSIFICATIONS', ['REGULAR', 'EXECUTIVE']);
}

// ── Employee portal defaults (GLOBAL) ───────────────────────────────────
// Every newly created employee gets a portal login automatically. The account
// starts on this default password with must_change = 1, so the employee is
// forced to set their own on first sign-in. Change these two lines to adjust.
if (!defined('PORTAL_DEFAULT_PASSWORD')) {
    define('PORTAL_DEFAULT_PASSWORD', 'password');
}
// Used to build a username when the employee has no real email address.
if (!defined('PORTAL_DEFAULT_EMAIL_DOMAIN')) {
    define('PORTAL_DEFAULT_EMAIL_DOMAIN', 'hospital.local');
}

// ── Payroll exclusions (GLOBAL) ─────────────────────────────────────────
// Employees with these classifications are SKIPPED when calculating payroll.
// Matched by NAME (case-insensitive). Change this ONE line to adjust.
if (!defined('PAYROLL_EXCLUDED_CLASSIFICATIONS')) {
    define('PAYROLL_EXCLUDED_CLASSIFICATIONS', ['INTERM', 'INTERN']);
}

// ── DTR review rules (GLOBAL) ───────────────────────────────────────────
// How the DTR Documents screen reads punches and flags exceptions.
// Change these lines to adjust app-wide; other companies may need 'ampm'.
//
// DTR_LOG_MODE
//   'single' → one time-in / one time-out per day; the paper DTR shows a
//              plain Arrival | Departure pair.
//   'ampm'   → classic 4-punch Form 48 (A.M. in/out + P.M. in/out columns).
if (!defined('DTR_LOG_MODE')) {
    define('DTR_LOG_MODE', 'single');
}
// A record with more overtime hours than this is flagged "High OT" and is
// skipped by clean bulk-approval until a human decides it.
if (!defined('DTR_HIGH_OT_HOURS')) {
    define('DTR_HIGH_OT_HOURS', 4);
}
// An employee who logged fewer than this percentage of the batch period's
// days is flagged "Low attendance"; their records are excluded from clean
// bulk-approval so absences can't be waved through silently.
if (!defined('DTR_LOW_ATTENDANCE_PCT')) {
    define('DTR_LOW_ATTENDANCE_PCT', 60);
}
// Default work schedule auto-assigned to every employee that has none —
// applied to new employees (save_employee) and imports (import_employee).
// Matched by work_schedules.description.
if (!defined('DTR_DEFAULT_SHIFT')) {
    define('DTR_DEFAULT_SHIFT', '8-5 (8AM-5PM)');
}
// Default rest days for auto-assigned schedules: 0=Sun … 6=Sat.
if (!defined('DTR_DEFAULT_REST_DAYS')) {
    define('DTR_DEFAULT_REST_DAYS', '0,6');
}

// Minimum logged days for an employee to count as "normal attendance"
// in a batch covering $periodDays calendar days.
if (!function_exists('dtr_min_days')) {
    function dtr_min_days(int $periodDays): int
    {
        if ($periodDays <= 0) return 0;
        return (int)ceil($periodDays * DTR_LOW_ATTENDANCE_PCT / 100);
    }
}

// SQL fragment appended to "... WHERE ddtr_id = X AND status = 0" to keep
// only records that clean bulk-approval may touch: a time-out exists, hours
// are non-zero, OT is within DTR_HIGH_OT_HOURS, and (when $minDays > 0) the
// record's employee has normal attendance. The single source of the rule —
// the summary counters, the docs flags, and decide_dtr_details all use it,
// so the numbers can never disagree. The derived table wrapper keeps the
// self-referencing subquery legal inside an UPDATE on DTR_details.
if (!function_exists('dtr_clean_condition_sql')) {
    function dtr_clean_condition_sql(int $ddtrId, int $minDays): string
    {
        $sql = " AND work_hours > 0 AND overtime <= " . (float)DTR_HIGH_OT_HOURS
             . " AND JSON_VALID(logs) AND JSON_LENGTH(logs) >= 2";
        if ($minDays > 0) {
            $sql .= " AND employee_id IN (SELECT employee_id FROM ("
                  . "SELECT employee_id FROM DTR_details WHERE ddtr_id = " . (int)$ddtrId
                  . " GROUP BY employee_id HAVING COUNT(DISTINCT date_time) >= " . (int)$minDays
                  . ") dtr_att_ok)";
        }
        return $sql;
    }
}

// ── Standard hours per working day (GLOBAL) ─────────────────────────────
// One full day of work = this many hours. Everything that turns hours into
// days ("days present") or a daily rate into an hourly / per-minute rate
// divides by the EMPLOYEE'S OWN schedule hours (work_schedules.total_hours).
// This constant is only the fallback for an employee who has no schedule at
// all — it is NOT the company-wide standard day.
if (!defined('PAYROLL_DEFAULT_DAY_HOURS')) {
    define('PAYROLL_DEFAULT_DAY_HOURS', 8);
}

// Normalises an hours value read from a schedule or from the frozen
// payroll_items.day_hours column. Missing, zero, negative or absurd values
// fall back to the default instead of producing a division by zero.
if (!function_exists('day_hours_or_default')) {
    function day_hours_or_default($hours): float
    {
        $h = (float) $hours;
        return ($h > 0 && $h <= 24) ? $h : (float) PAYROLL_DEFAULT_DAY_HOURS;
    }
}

// Standard daily hours for ONE employee on ONE date, taken from the schedule
// in effect that day. Use this at calculation time; anything reading an
// already-calculated payroll must use the frozen payroll_items.day_hours
// (via day_hours_or_default) so history can't shift under it.
if (!function_exists('payroll_day_hours')) {
    function payroll_day_hours(mysqli $db, int $employee_id, string $date): float
    {
        // Same resolver as dtr_compute_day — a day whose DTR computed against an
        // 11h shift must not be paid against a flat 8h default.
        $r = resolve_employee_schedule($db, (int) $employee_id, $date);
        return day_hours_or_default($r['total_hours'] ?? null);
    }
}

// Same choice as resolve_employee_schedule(), in the same order, but over a
// window list already fetched for a date range — one query for a whole batch
// instead of one per employee per day. Covering window, else the last one that
// had already started (even if ended), else the earliest still to come.
// Returns null only for an employee with no windows at all.
if (!function_exists('pick_schedule_window')) {
    function pick_schedule_window(array $windows, string $date)
    {
        $cover = $prior = $next = null;
        foreach ($windows as $w) {
            $from = $w['effective_from'];
            if ($from <= $date && (empty($w['effective_to']) || $w['effective_to'] >= $date)) {
                $cover = $w;                                            // latest covering wins
            }
            if ($from <= $date && ($prior === null || $from > $prior['effective_from'])) {
                $prior = $w;                                            // most recent already started
            }
            if ($from > $date && ($next === null || $from < $next['effective_from'])) {
                $next = $w;                                             // earliest still to come
            }
        }
        return $cover ?: ($prior ?: $next);
    }
}

// ── Per-minute rate (GLOBAL) ────────────────────────────────────────────
// What one minute of late / undertime costs. This is DAY-LENGTH DEPENDENT, so
// it must divide by the employee's own working day, not a flat 8 hours: on a
// 7-hour shift a flat /480 prices every late minute ~14% under, and the portal
// then shows the employee a different deduction than payroll actually took.
// Reads the day_hours frozen on the payroll item, so recomputing an old payroll
// cannot restate it.
if (!function_exists('payroll_per_minute')) {
    function payroll_per_minute($row): float
    {
        $perDay = (float) ($row['per_day'] ?? 0);
        $hours  = day_hours_or_default($row['day_hours'] ?? null);
        return $hours > 0 ? $perDay / ($hours * 60) : 0.0;
    }
}

// SQL twin of the above, for the dashboard/report queries that recompute in the
// database. $a is the payroll_items alias.
if (!function_exists('sql_per_minute')) {
    function sql_per_minute(string $a = 'pi'): string
    {
        return "($a.per_day / (COALESCE(NULLIF($a.day_hours, 0), " . (int) PAYROLL_DEFAULT_DAY_HOURS . ") * 60))";
    }
}

// Special-holiday premium: 30% of the daily rate. Written throughout as
// `per_day / 8 * 2.4`, which collapses to exactly per_day * 0.3 — the 8 there is
// arithmetic, NOT a day length, and changing it to the real shift length would
// silently alter pay. Kept as one function so it stops looking like a bug.
if (!defined('SPECIAL_HOLIDAY_PREMIUM_RATE')) define('SPECIAL_HOLIDAY_PREMIUM_RATE', 0.30);
if (!function_exists('special_holiday_premium')) {
    function special_holiday_premium($days, $per_day): float
    {
        return (float) $days * (float) $per_day * SPECIAL_HOLIDAY_PREMIUM_RATE;
    }
}

// ── Rest-day premium (GLOBAL) ───────────────────────────────────────────
// Extra paid for rendering duty on a rest day, as a fraction of the daily rate.
// 0.30 = the Labor Code's +30%, i.e. 130% for the day in total.
//
// It is a PREMIUM, not a second day's pay. For a daily-rate employee the day
// itself is already inside `present` (the DTR row is there and counted), so
// adding a whole `sunday_duty × per_day` would pay the base twice — which is
// why the daily gross formula omitted it entirely and rest-day work silently
// earned nothing at all. The payslip meanwhile printed the full-day figure as
// an earning, so the sheet showed money that was never paid.
if (!defined('REST_DAY_PREMIUM_RATE')) define('REST_DAY_PREMIUM_RATE', 0.30);

if (!function_exists('rest_day_premium')) {
    function rest_day_premium($rest_days, $per_day): float
    {
        return round((float) $rest_days * (float) $per_day * REST_DAY_PREMIUM_RATE, 2);
    }
}

// ── Allowances (GLOBAL) ─────────────────────────────────────────────────
// Total allowance on ONE payroll item. Two sources, deliberately ADDITIVE:
//
//   1. payroll_items.allowances — the JSON frozen at calculation time from the
//      employee's configured allowance types (Meal, Hazard, …). Recurring, named,
//      individually taxable-or-not.
//   2. allowance_amount × allowance_days — the original single hand-typed slot,
//      kept as the ad-hoc "this cutoff only" allowance.
//
// Existing rows carry an empty JSON column, so this returns exactly what it
// always did for them; nothing restates a closed run.
if (!function_exists('payroll_allowance_list')) {
    function payroll_allowance_list($row): array
    {
        $list = json_decode($row['allowances'] ?? '', true);
        return is_array($list) ? $list : [];
    }
}

if (!function_exists('payroll_allowance_total')) {
    function payroll_allowance_total($row): float
    {
        $t = (float) ($row['allowance_amount'] ?? 0) * (float) ($row['allowance_days'] ?? 0);
        foreach (payroll_allowance_list($row) as $a) $t += (float) ($a['amount'] ?? 0);
        return $t;
    }
}

// How much of one run's allowance an employee_allowances row contributes.
// `type` on that table: 1 = Monthly, 2 = Semi-Monthly, 3 = Once.
//   Monthly      → spread across the run the same way statutory schedules are
//                  (half on a semi-monthly cutoff, whole on a monthly run).
//   Semi-Monthly → the stated amount, once per cutoff, as named.
//   Once         → paid only in the run whose period contains effective_date.
if (!function_exists('employee_allowance_amount_for_run')) {
    function employee_allowance_amount_for_run(array $ea, string $date_from, string $date_to)
    {
        $amount = (float) ($ea['amount'] ?? 0);
        $type   = (int) ($ea['type'] ?? 1);
        $from   = $ea['effective_date'] ?? null;
        $to     = $ea['effective_to'] ?? null;

        // Outside its effectivity window → contributes nothing to this run.
        if (!empty($from) && date('Y-m-d', strtotime($from)) > $date_to)   return 0.0;
        if (!empty($to)   && date('Y-m-d', strtotime($to))   < $date_from) return 0.0;

        if ($type === 3) {
            // One-off: only in the cutoff that contains its effective date.
            if (empty($from)) return 0.0;
            $d = date('Y-m-d', strtotime($from));
            return ($d >= $date_from && $d <= $date_to) ? $amount : 0.0;
        }
        if ($type === 2) return $amount;                                  // per cutoff as stated
        return round($amount * statutory_period_factor($date_from, $date_to), 2);   // monthly
    }
}

// ── Statutory contributions (GLOBAL) ────────────────────────────────────
// SSS, PhilHealth and Pag-IBIG employee shares, resolved from the
// effectivity-dated statutory_rates table (see the migration for why they are
// not hardcoded any more). Each takes the MONTHLY compensation the schedule is
// assessed on, and returns the EMPLOYEE share for a FULL MONTH — callers halve
// it for a semi-monthly cutoff via statutory_period_factor().
//
// A missing table or a date with no row in force returns null, and the caller
// falls back to the amount stored on employee_contributions. That keeps an
// un-migrated installation working exactly as it did.
if (!function_exists('statutory_config')) {
    function statutory_config(mysqli $db, string $kind, string $on_date)
    {
        static $cache = [];
        $key = $kind . '|' . $on_date;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $cache[$key] = null;
        $chk = $db->query("SHOW TABLES LIKE 'statutory_rates'");
        if (!$chk || !$chk->num_rows) return null;

        $st = $db->prepare(
            "SELECT config FROM statutory_rates
              WHERE kind = ? AND effective_from <= ?
              ORDER BY effective_from DESC LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('ss', $kind, $on_date);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        if ($r) $cache[$key] = json_decode($r['config'], true) ?: null;
        return $cache[$key];
    }
}

// Which statutory schedule a contribution row refers to, from its name. Same
// aliasing the remittance report and the BIR alphalist already use, kept in one
// place so the three cannot disagree about what counts as "SSS".
if (!function_exists('contribution_kind')) {
    function contribution_kind(string $name)
    {
        $n = strtoupper($name);
        if (strpos($n, 'SSS') !== false)  return 'sss';
        if (strpos($n, 'PHIC') !== false || strpos($n, 'PHIL') !== false) return 'phic';
        if (strpos($n, 'HDMF') !== false || strpos($n, 'PAG') !== false)  return 'hdmf';
        return null;
    }
}

// A full month's schedule spread over this run. Semi-monthly cutoffs take half
// the monthly figure (which is what the client's own paysheet does — its SSS
// column shows 500 / 550 / 920, all half-month amounts); a run covering a whole
// month takes it once.
if (!function_exists('statutory_period_factor')) {
    function statutory_period_factor(string $date_from, string $date_to): float
    {
        $days = (int) round((strtotime($date_to) - strtotime($date_from)) / 86400) + 1;
        return $days >= 28 ? 1.0 : 0.5;
    }
}

if (!function_exists('sss_employee_share')) {
    /** SSS EE share for a full month. MSC is the salary floored to its step. */
    function sss_employee_share(float $monthly, array $cfg): float
    {
        $step = max(1.0, (float) ($cfg['msc_step'] ?? 500));
        $min  = (float) ($cfg['msc_min'] ?? 5000);
        $max  = (float) ($cfg['msc_max'] ?? 35000);
        if ($monthly <= 0) return 0.0;
        $msc = min(max($monthly, $min), $max);
        $msc = floor($msc / $step) * $step;          // MSC is a step, not the raw salary
        return round($msc * (float) ($cfg['ee_rate'] ?? 0.05), 2);
    }
}

if (!function_exists('philhealth_employee_share')) {
    /** PhilHealth EE share for a full month (premium × the employee's half). */
    function philhealth_employee_share(float $monthly, array $cfg): float
    {
        if ($monthly <= 0) return 0.0;
        $base = min(max($monthly, (float) ($cfg['floor'] ?? 10000)), (float) ($cfg['ceiling'] ?? 100000));
        return round($base * (float) ($cfg['rate'] ?? 0.05) * (float) ($cfg['ee_share'] ?? 0.5), 2);
    }
}

if (!function_exists('pagibig_employee_share')) {
    /** Pag-IBIG EE share for a full month: 1% at or below the threshold, 2%
     *  above it, on a base capped by cap_base. */
    function pagibig_employee_share(float $monthly, array $cfg): float
    {
        if ($monthly <= 0) return 0.0;
        $threshold = (float) ($cfg['threshold'] ?? 1500);
        $rate = $monthly <= $threshold
            ? (float) ($cfg['low_rate'] ?? 0.01)
            : (float) ($cfg['high_rate'] ?? 0.02);
        $base = min($monthly, (float) ($cfg['cap_base'] ?? 5000));
        return round($base * $rate, 2);
    }
}

// One entry point: the EE share for $kind on $monthly compensation, for a run
// covering $date_from..$date_to. Returns null when no schedule is in force, so
// the caller knows to fall back to the stored amount.
if (!function_exists('statutory_employee_share')) {
    function statutory_employee_share(mysqli $db, string $kind, float $monthly, string $date_from, string $date_to)
    {
        $cfg = statutory_config($db, $kind, $date_from);
        if (!$cfg) return null;
        $full = $kind === 'sss'  ? sss_employee_share($monthly, $cfg)
              : ($kind === 'phic' ? philhealth_employee_share($monthly, $cfg)
              : ($kind === 'hdmf' ? pagibig_employee_share($monthly, $cfg) : null));
        if ($full === null) return null;
        return round($full * statutory_period_factor($date_from, $date_to), 2);
    }
}

// 13th month & other benefits exemption ceiling, Sec 32(B)(7)(e) NIRC. Lived as
// a file-scoped `const` in bir-alphalist.php and its CSV twin, which meant the
// payroll calculation could not see it — so the cap was applied when REPORTING
// the year to BIR but never when withholding on it.
if (!defined('BIR_13TH_CAP')) define('BIR_13TH_CAP', 90000.0);

// ── Withholding tax (GLOBAL) ────────────────────────────────────────────
// There was no tax computation in this system at all: payroll_items.tax was a
// text box and calculate_payroll() only preserved what an admin typed. These
// functions supply the missing arithmetic; whether it is actually POSTED is a
// separate decision (pay_settings.tax_auto_post, off by default).

// The bracket set in force for $period on $on_date. null when unmigrated.
if (!function_exists('tax_bracket_config')) {
    function tax_bracket_config(mysqli $db, string $period, string $on_date)
    {
        static $cache = [];
        $key = $period . '|' . $on_date;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $cache[$key] = null;
        $chk = $db->query("SHOW TABLES LIKE 'tax_brackets'");
        if (!$chk || !$chk->num_rows) return null;

        $st = $db->prepare(
            "SELECT config FROM tax_brackets
              WHERE period = ? AND effective_from <= ?
              ORDER BY effective_from DESC LIMIT 1"
        );
        if (!$st) return null;
        $st->bind_param('ss', $period, $on_date);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        if ($r) $cache[$key] = json_decode($r['config'], true) ?: null;
        return $cache[$key];
    }
}

// tax = base + (taxable − over) × rate, for the highest band reached.
if (!function_exists('tax_from_brackets')) {
    function tax_from_brackets(float $taxable, array $bands): float
    {
        if ($taxable <= 0) return 0.0;
        $pick = null;
        foreach ($bands as $b) {
            if ($taxable > (float) $b['over']) $pick = $b;
        }
        if ($pick === null) return 0.0;
        $tax = (float) $pick['base'] + ($taxable - (float) $pick['over']) * (float) $pick['rate'];
        return round(max(0.0, $tax), 2);
    }
}

// Non-taxable portion of an item's allowances. An allowance frozen with
// taxable = 0 is de minimis (meal, rice, uniform…) and is excluded from the
// withholding base up to its own ceiling; anything above the ceiling, and every
// allowance marked taxable, is compensation.
if (!function_exists('payroll_nontaxable_allowance')) {
    function payroll_nontaxable_allowance($row, float $period_factor = 1.0): float
    {
        $t = 0.0;
        foreach (payroll_allowance_list($row) as $a) {
            if ((int) ($a['taxable'] ?? 1) === 1) continue;
            $amt = (float) ($a['amount'] ?? 0);
            $cap = isset($a['cap']) && $a['cap'] !== null ? (float) $a['cap'] * $period_factor : null;
            $t  += $cap !== null ? min($amt, $cap) : $amt;
        }
        return round($t, 2);
    }
}

// Taxable compensation for ONE payroll item.
//
//   gross
//   − non-taxable de minimis allowances (within their ceilings)
//   − the employee share of SSS / PhilHealth / Pag-IBIG   [Sec 32(B)(7)(f) NIRC]
//
// Government contributions are deductible from the withholding base by law, and
// the whole reason they had to be computed first: on a stored flat amount the
// base is only as right as whatever someone last typed.
if (!function_exists('payroll_taxable_income')) {
    function payroll_taxable_income($row, array $gov_contribution_ids = [], float $period_factor = 1.0): float
    {
        $e = payroll_earnings($row);
        $taxable = $e['gross'] - payroll_nontaxable_allowance($row, $period_factor);

        foreach ((json_decode($row['contributions'] ?? '', true) ?: []) as $c) {
            $cid = (int) ($c['contribution_id'] ?? 0);
            // No id map supplied (e.g. a caller with only the item) → treat every
            // contribution as statutory, which is what they are in practice here.
            if (!$gov_contribution_ids || isset($gov_contribution_ids[$cid])) {
                $taxable -= (float) ($c['amount'] ?? 0);
            }
        }
        return round(max(0.0, $taxable), 2);
    }
}

// Which bracket period a run's length corresponds to.
if (!function_exists('tax_period_for_run')) {
    function tax_period_for_run(string $date_from, string $date_to): string
    {
        $days = (int) round((strtotime($date_to) - strtotime($date_from)) / 86400) + 1;
        return $days >= 28 ? 'monthly' : 'semi_monthly';
    }
}

// Per-cutoff withholding: the table applied to this run's own taxable income.
// Simple and auditable, but a cutoff carrying heavy overtime over-withholds
// relative to the year — which is what the cumulative method below corrects.
if (!function_exists('withholding_tax_per_cutoff')) {
    function withholding_tax_per_cutoff(mysqli $db, float $taxable, string $date_from, string $date_to)
    {
        $bands = tax_bracket_config($db, tax_period_for_run($date_from, $date_to), $date_from);
        if (!$bands) return null;
        return tax_from_brackets($taxable, $bands);
    }
}

// Cumulative / annualised withholding (RR 11-2018): project the year to date,
// compute the annual tax on it, and withhold the difference against what has
// already been withheld. Self-correcting — a heavy overtime cutoff is evened out
// by the next one instead of needing a December true-up — and it is what the
// client's own legacy paysheet appears to do (equal tax on unequal gross, and a
// negative line where an over-withholding was refunded).
//
// $ytd_taxable / $ytd_withheld EXCLUDE the current run.
if (!function_exists('withholding_tax_cumulative')) {
    function withholding_tax_cumulative(
        mysqli $db, float $taxable, float $ytd_taxable, float $ytd_withheld, string $date_from, string $date_to
    ) {
        $bands = tax_bracket_config($db, 'annual', $date_from);
        if (!$bands) return null;
        $annual_tax_to_date = tax_from_brackets($ytd_taxable + $taxable, $bands);
        // May be negative — that is a refund of over-withholding, and it is
        // correct to return it rather than clamp it to zero.
        return round($annual_tax_to_date - $ytd_withheld, 2);
    }
}

// ── Gross for ONE payroll item, in SQL (GLOBAL) ─────────────────────────
// The database twin of payroll_earnings() below — same components, same order,
// same rate_type branch. Dashboards and reports that SUM gross across a run
// must call THIS instead of pasting the expression, which is how six near-copies
// came to exist that all shared two faults:
//
//   1. NO rate_type branch — every one applied the monthly formula to every
//      employee, so a daily-rate row was grossed as half a monthly salary.
//   2. Absences (and allowance) halved, the same bug fixed in payroll_earnings.
//
// $a is the payroll_items alias. Emits one parenthesised expression.
if (!function_exists('sql_gross')) {
    function sql_gross(string $a = 'pi'): string
    {
        $pm      = sql_per_minute($a);
        $monthly = "$a.rate_type IN ('monthly','fixed')";
        $spec    = (float) SPECIAL_HOLIDAY_PREMIUM_RATE;
        $rest    = (float) REST_DAY_PREMIUM_RATE;

        return "(
            CASE WHEN $monthly
                 THEN $a.basic_pay / 2 - ($a.absent * $a.per_day)
                 ELSE ($a.present + COALESCE($a.paid_leave, 0)) * $a.per_day
            END
            + ($a.allowance_amount * $a.allowance_days)
            + COALESCE($a.allowance_total, 0)
            + ($a.ot * $a.ot_rate)
            + ($a.legal_holiday * $a.per_day)
            + CASE WHEN $monthly
                   THEN $a.sunday_duty * $a.per_day
                   ELSE $a.sunday_duty * $a.per_day * $rest
              END
            + ($a.special_holiday * $a.per_day * $spec)
            + COALESCE($a.nsd_amount, 0)
            - ($a.late * $pm)
            - ($a.under_time * $pm)
        )";
    }
}

// ── Earnings for ONE payroll item (GLOBAL) ──────────────────────────────
// THE formula. Every screen, print sheet, export and dashboard must call this
// instead of keeping its own copy — the copies are how the payroll register,
// the calculation sheet, the portal and the stored net drifted apart (one
// dropped night differential, another priced late on a flat 8-hour day, two
// still paid rest days as a whole extra day).
//
// Takes a payroll_items row (plus optional payroll_type) and returns every
// component named, so callers render the breakdown instead of recomputing it.
//
// Pay basis comes from the item's frozen rate_type:
//   monthly / fixed → half-month salary share, minus absences; premiums added
//   daily           → (days present + paid leave) × daily rate
//
// Holiday duty is paid on BOTH bases. The day worked is already paid once —
// inside the half-month salary for monthly/fixed, inside `present` for daily —
// so the holiday line is the SECOND payment: a legal holiday lands at 200% of
// the daily rate and a special holiday at 130%, whichever basis the employee is
// on. Only days actually worked carry a count (calculate_payroll fills them from
// the holiday calendar), so an unworked holiday adds nothing here: monthly staff
// keep their salary, daily staff are paid nothing for it.
//
// This used to be a documented gap — the daily branch computed both amounts and
// then dropped them from gross, so a daily employee who worked a holiday was
// paid a plain day. resync_item_net() meanwhile did add them, which is how the
// stored net and the displayed gross could disagree on the same row.
if (!function_exists('payroll_earnings')) {
    function payroll_earnings($row): array
    {
        $perDay     = (float) ($row['per_day'] ?? 0);
        $dayHours   = day_hours_or_default($row['day_hours'] ?? null);
        $perMinute  = payroll_per_minute($row);
        $rt         = $row['rate_type'] ?? 'daily';
        $is_monthly = $rt === 'monthly' || $rt === 'fixed'
                   || ((int) ($row['payroll_type'] ?? 0) === 5);   // legacy whole-run override

        // Hand-typed slot + the configured allowance types frozen on the item.
        $allowance   = payroll_allowance_total($row);
        $absent_amt  = (float) ($row['absent'] ?? 0) * $perDay;
        $overtime    = (float) ($row['ot'] ?? 0) * (float) ($row['ot_rate'] ?? 0);
        $late_amt    = (float) ($row['late'] ?? 0) * $perMinute;
        $under_amt   = (float) ($row['under_time'] ?? 0) * $perMinute;
        $legal_amt   = (float) ($row['legal_holiday'] ?? 0) * $perDay;
        $special_amt = special_holiday_premium($row['special_holiday'] ?? 0, $perDay);
        $nsd_amt     = (float) ($row['nsd_amount'] ?? 0);
        // Rest day: a premium on the daily basis (the day is already inside
        // `present`), a whole extra day on the monthly basis as it always was.
        $rest_amt    = $is_monthly
            ? (float) ($row['sunday_duty'] ?? 0) * $perDay
            : rest_day_premium($row['sunday_duty'] ?? 0, $perDay);

        // `subtotal` is BASIC PAY FOR THE PERIOD — no allowance, no premiums. It
        // used to be ($basic + $allowance - $absent_amt) / 2 on both bases, which
        // was wrong twice over:
        //
        //   1. Absences were halved. $basic is a MONTHLY figure, so ÷2 correctly
        //      converts it to a semi-monthly share — but $absent_amt is already
        //      cutoff-scoped (calculate_payroll counts absent days between
        //      date_from and date_to), so dividing it too made a missed day cost
        //      the employee only half a day's pay.
        //   2. Allowance was halved on the monthly basis but added whole on the
        //      daily one, from the same expression. allowance_amount ×
        //      allowance_days is a per-cutoff figure (a day count typed per run),
        //      so the daily branch was right and the monthly branch under-paid it.
        //
        // Both bases now share ONE gross expression: basic for the period, plus
        // allowance and premiums, less lost time.
        if ($is_monthly) {
            $basic    = (float) ($row['basic_pay'] ?? 0);
            $subtotal = $basic / 2 - $absent_amt;
        } else {
            // Daily staff are paid per day worked, so there is no absence line.
            $basic    = ((float) ($row['present'] ?? 0) + (float) ($row['paid_leave'] ?? 0)) * $perDay;
            $subtotal = $basic;
        }
        $gross = $subtotal + $allowance + $overtime
               + $legal_amt + $rest_amt + $special_amt + $nsd_amt
               - $late_amt - $under_amt;

        return [
            'is_monthly' => $is_monthly,
            'day_hours'  => $dayHours,
            'per_minute' => $perMinute,
            'basic'      => $basic,
            'subtotal'   => $subtotal,
            'allowance'  => $allowance,
            'absent_amt' => $absent_amt,
            'overtime'   => $overtime,
            'late_amt'   => $late_amt,
            'under_amt'  => $under_amt,
            'legal_amt'  => $legal_amt,
            'rest_amt'   => $rest_amt,
            'special_amt' => $special_amt,
            'nsd_amt'    => $nsd_amt,
            'gross'      => $gross,
        ];
    }
}

// ── Attachment upload (GLOBAL) ──────────────────────────────────────────
// One optional supporting document (image or PDF, max 5 MB) for loans and
// attendance requests. Validates the REAL MIME type via finfo — the client's
// filename/extension is never trusted — and stores under a server-generated
// name in uploads/. Pairs with assets2/js/attach-upload.js on the form side.
if (!function_exists('payroll_save_attachment')) {
    function payroll_save_attachment(string $field, string $prefix = 'att'): array
    {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true, 'file' => null];   // optional — nothing sent is fine
        }
        $f = $_FILES[$field];
        if (($f['error'] ?? -1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The attachment failed to upload — please try again.'];
        }
        if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'The attachment is larger than 5 MB — please compress it first.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!isset($extByMime[$mime])) {
            return ['ok' => false, 'error' => 'Only an image (JPG/PNG/WebP) or a PDF can be attached.'];
        }
        $name = $prefix . '_' . uniqid() . '.' . $extByMime[$mime];
        if (!@move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/' . $name)) {
            return ['ok' => false, 'error' => 'Could not store the attachment on the server.'];
        }
        return ['ok' => true, 'file' => $name];
    }
}

// ── Punch display (GLOBAL) ──────────────────────────────────────────────
// A punch time, flagged when it lands on a LATER calendar day than the DTR row
// it belongs to. An overnight shift's 5:00 AM time-out is stored on the row of
// the evening the shift started, so unmarked it reads as if the employee
// clocked out nine hours before clocking in. Renders "5:00 AM +1"; the full
// date stays available as a tooltip. Day shifts get no marker at all.
if (!function_exists('dtr_punch_time')) {
    function dtr_punch_time(string $row_date, $ts, string $fmt = 'h:i A'): string
    {
        if (empty($ts)) return '—';
        $ts = is_numeric($ts) ? (int) $ts : strtotime((string) $ts);
        if (!$ts) return '—';

        $label = date($fmt, $ts);
        try {
            // Date-only diff — a raw /86400 would be off by an hour under DST.
            $days = (int) (new DateTime($row_date))->diff(new DateTime(date('Y-m-d', $ts)))->format('%r%a');
        } catch (Exception $e) {
            return $label;
        }
        if ($days <= 0) return $label;

        // tabindex makes the chip focusable, so a TAP shows the detail on touch
        // devices — :hover alone never fires there. On a touch screen the short
        // date is also rendered inline (see .nx-d in dtr-form48.css), so the
        // information is readable with no interaction at all.
        $lead = $days === 1 ? 'Next day' : $days . ' days later';
        return $label
             . '<sup class="dtr-nextday" tabindex="0" role="note" data-tip="'
             . htmlspecialchars($lead . ' — punched after midnight' . "\n" . date('D, M j, Y · g:i A', $ts))
             . '">+' . $days . '<span class="nx-d">' . date('M j', $ts) . '</span></sup>';
    }
}

// ── Schedule resolution (GLOBAL) ────────────────────────────────────────
// The work schedule that applies to ONE employee on ONE date. Scan ingestion
// (Action::save_biometric_attendance) and dtr_compute_day MUST agree on this —
// when they didn't, an Evening 15:00–00:00 row recomputed 15 hours before the
// shift began (see the note in dtr_compute_day), so both now call this.
//
// Falls back in three steps, because "no row covers this date" used to mean
// "no schedule at all", and that silently disabled BOTH overnight punch pairing
// and all hour math for the day:
//   1. The assignment in effect on $date — the normal path.
//   2. The most recent assignment that had already STARTED, even if it has since
//      ended. Deliberately not "nearest by distance": with a gap in the history
//      (a period deleted), a date inside it is closer to the NEXT period's start
//      than to the previous one's, so nearest-by-distance costed a back-dated
//      entry against a shift that had not begun yet. What the employee was last
//      on is the only defensible answer.
//   3. The earliest assignment starting AFTER $date — reached only when nothing
//      had started yet, i.e. punches predating the employee's first assignment.
//      A schedule assigned today with effective_from = today does not cover
//      punches already made this week; without this a night-shift clock-out at
//      07:01 opened its own next-day row instead of closing the evening's shift.
//   4. DEFAULT_WORK_SCHEDULE_ID (8-5, 8AM-5PM) when the employee has no assignment
//      at all, so a day still computes against something sane rather than zero.
// Returns null only if the default schedule row itself is missing.
if (!defined('DEFAULT_WORK_SCHEDULE_ID')) define('DEFAULT_WORK_SCHEDULE_ID', 1);

// The PUBLISHED duty-roster entry for one employee-day, or null.
//
// Draft rows (status 0) are deliberately invisible here: a head nurse builds
// next cutoff's sheet over several days, and a half-painted rotation must never
// reach DTR figures, payroll or the employee portal. Publishing is the only act
// that makes a day real.
if (!function_exists('duty_roster_day')) {
    function duty_roster_day(mysqli $db, int $employee_id, string $date)
    {
        $res = $db->query(
            "SELECT schedule_id, is_rest_day FROM employee_day_schedule
             WHERE employee_id = " . (int) $employee_id . "
               AND work_date = '" . $db->real_escape_string($date) . "'
               AND status = 1 LIMIT 1"
        );
        return $res ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('resolve_employee_schedule')) {
    function resolve_employee_schedule(mysqli $db, int $employee_id, string $date)
    {
        // 0. Duty roster (rotating staff) wins over the period roster — it is
        //    the more specific answer, painted for THIS day. Employees who are
        //    not planned on the day grid have no rows here at all, so fixed
        //    staff fall straight through to the period logic unchanged.
        $day = duty_roster_day($db, $employee_id, $date);
        if ($day) {
            if ($day['schedule_id'] !== null) {
                $res = $db->query("SELECT * FROM work_schedules WHERE id = " . (int) $day['schedule_id'] . " LIMIT 1");
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) {
                    // rest_days is emptied on purpose: the weekday CSV describes a
                    // FIXED week and would contradict the rotation. day_is_rest is
                    // the authoritative flag for a rostered day.
                    $row['rest_days']   = '';
                    $row['day_is_rest'] = (int) $day['is_rest_day'];
                    return $row;
                }
                // Shift row vanished (deleted mid-cutoff) → fall through to the
                // period roster rather than returning nothing.
            }
            // A rest day painted without naming a shift. The day still needs
            // shift BOUNDARIES — someone who punches on their day off earns
            // overtime measured against a shift end — so the period roster
            // supplies the times and only the rest flag is forced.
            $row = resolve_schedule_period($db, $employee_id, $date);
            if ($row) $row['day_is_rest'] = (int) $day['is_rest_day'];
            return $row;
        }

        return resolve_schedule_period($db, $employee_id, $date);
    }
}

// The original period-roster resolution (steps 1-4), unchanged. Split out so
// the duty-roster layer above can reuse it for shift boundaries.
if (!function_exists('resolve_schedule_period')) {
    function resolve_schedule_period(mysqli $db, int $employee_id, string $date)
    {
        $dateEsc = $db->real_escape_string($date);
        $eid     = (int) $employee_id;

        // 1. Covering assignment.
        $res = $db->query("
            SELECT ws.*, es.rest_days FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $eid AND es.effective_from <= '$dateEsc'
              AND (es.effective_to IS NULL OR es.effective_to >= '$dateEsc')
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) return $row;

        // 2. Last assignment that had already started — an ended period is still
        //    a better answer than one that had not begun on this date.
        $res = $db->query("
            SELECT ws.*, es.rest_days FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $eid AND es.effective_from <= '$dateEsc'
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) return $row;

        // 3. Nothing had started yet → earliest assignment (new hire back-entry).
        $res = $db->query("
            SELECT ws.*, es.rest_days FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $eid AND es.effective_from > '$dateEsc'
            ORDER BY es.effective_from ASC LIMIT 1
        ");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) return $row;

        // 4. System default. No assignment means no rest days either — the key is
        //    still set so callers can read it without checking which step matched.
        $res = $db->query("SELECT * FROM work_schedules WHERE id = " . (int) DEFAULT_WORK_SCHEDULE_ID . " LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) $row['rest_days'] = '';
        return $row;
    }
}

// ── DTR day math (GLOBAL) ───────────────────────────────────────────────
// Single source of truth for computing one DTR_details row's figures from
// its raw log timestamps + the employee's effective schedule + the holiday
// calendar. Used by the manual time edit, the incident-report repair and
// batch recompute so the three can never drift apart.
//   $log_ts: unix timestamps of the record's logs, any order.
//   $use_stamp: true (default) honours the shift frozen on the row at punch
//               time; false re-resolves from employee_schedules so a corrected
//               assignment actually applies (batch Recompute uses this).
// Returns [work_hours, overtime, undertime, late, nsd_hours, day_type, is_complete,
//          schedule_id, day_hours, is_rest_day,
//          sched_start, sched_end, sched_break, sched_graveyard] — everything
// after is_complete is the stamp for the shift the figures were computed
// under, so callers that force a re-resolve can write the new stamp back
// onto the row.
if (!function_exists('dtr_compute_day')) {
    function dtr_compute_day(mysqli $db, int $employee_id, string $date, array $log_ts, bool $use_stamp = true): array
    {
        sort($log_ts);
        $n      = count($log_ts);
        $in_ts  = $n ? $log_ts[0] : null;
        $out_ts = $n >= 2 ? $log_ts[$n - 1] : null;
        if ($in_ts && $out_ts && $out_ts <= $in_ts) $out_ts = strtotime('+1 day', $out_ts);

        $dateEsc  = $db->real_escape_string($date);
        $day_type = 'regular';
        $hol = $db->query("SELECT type FROM calendar_events WHERE '$dateEsc' BETWEEN start_date AND COALESCE(end_date,'$dateEsc') AND type IN (1,3) LIMIT 1")->fetch_assoc();
        if ($hol) $day_type = $hol['type'] == 1 ? 'legal_holiday' : 'special_holiday';

        // The shift frozen on the row when the punch was recorded wins, so a
        // later roster change can't silently restate days already closed. Rows
        // predating the stamp column (schedule_id NULL) fall back to resolving
        // now — and $use_stamp=false skips the stamp entirely, which is how an
        // explicit Recompute applies a corrected schedule assignment.
        $schedule = null;
        if ($use_stamp) {
            $st = $db->query("
                SELECT ws.*, d.day_hours AS stamped_day_hours, d.is_rest_day AS stamped_is_rest,
                       d.sched_start AS stamped_start, d.sched_end AS stamped_end,
                       d.sched_break AS stamped_break, d.sched_graveyard AS stamped_graveyard
                FROM DTR_details d INNER JOIN work_schedules ws ON ws.id = d.schedule_id
                WHERE d.employee_id = $employee_id AND d.date_time = '$dateEsc'
                  AND d.schedule_id IS NOT NULL
                ORDER BY d.id DESC LIMIT 1
            ");
            if ($st && ($srow = $st->fetch_assoc())) {
                $schedule = $srow;
                // Every stamped field beats the shift's CURRENT definition, which
                // may have been edited on the work_schedules row since (in the app
                // or directly in the DB). Without this, late/UT/OT/NSD re-derived
                // against moved shift boundaries even in stamp mode — the freeze
                // only actually covered day_hours and the rest-day flag. NULL
                // (row predates the column) falls back to the live value.
                if ($srow['stamped_day_hours'] !== null) $schedule['total_hours'] = $srow['stamped_day_hours'];
                if ($srow['stamped_start']     !== null) $schedule['start_time']    = $srow['stamped_start'];
                if ($srow['stamped_end']       !== null) $schedule['end_time']      = $srow['stamped_end'];
                if ($srow['stamped_break']     !== null) $schedule['break_minutes'] = (int) $srow['stamped_break'];
                if ($srow['stamped_graveyard'] !== null) $schedule['is_graveyard']  = (int) $srow['stamped_graveyard'];
            }
        }
        if (!$schedule) $schedule = resolve_employee_schedule($db, $employee_id, $date);

        // Rest-day flag for whichever shift won above — same rule as ingestion
        // (weekday number in employee_schedules.rest_days CSV). A stamped row
        // keeps its stamped flag; a re-resolved one gets the roster's answer.
        if (isset($schedule['stamped_is_rest']) && $schedule['stamped_is_rest'] !== null) {
            $is_rest = (int) $schedule['stamped_is_rest'];
        } elseif (isset($schedule['day_is_rest'])) {
            // Rostered day: the grid said so explicitly. A rotating employee's
            // day off moves through the week, so the weekday CSV below cannot
            // answer for them and must not be consulted.
            $is_rest = (int) $schedule['day_is_rest'];
        } else {
            $rest_csv = (string) ($schedule['rest_days'] ?? '');
            $is_rest  = ($rest_csv !== '' && in_array(
                (int) date('w', strtotime($date)),
                array_map('intval', explode(',', $rest_csv)),
                true
            )) ? 1 : 0;
        }

        $raw_hours  = ($in_ts && $out_ts) ? ($out_ts - $in_ts) / 3600 : 0;
        $break_hrs  = (isset($schedule['break_minutes']) ? $schedule['break_minutes'] : 60) / 60;
        $work_hours = 0;
        $overtime = $late = $undertime = $nsd_hours = 0;
        if ($out_ts && $schedule) {
            $sched_start = strtotime($date . ' ' . $schedule['start_time']);
            $sched_end   = strtotime($date . ' ' . $schedule['end_time']);
            // A shift is overnight when the flag says so OR when its end time
            // wraps past midnight (Evening 3PM–12AM, Night 11PM–8AM) even though
            // the flag was never ticked. This MUST match the predicate scan
            // ingestion uses (Action::save_biometric_attendance) — when the two
            // disagreed, an Evening 15:00–00:00 row recomputed to sched_end =
            // midnight at the START of the day, 15 hours before the shift began,
            // giving work_hours 0 and overtime 24.00.
            if ($schedule['is_graveyard'] || $schedule['end_time'] <= $schedule['start_time']) {
                $sched_end = strtotime('+1 day', $sched_end);
            }
            $late       = round(max(0, ($in_ts - $sched_start) / 3600), 2);
            $undertime  = round(max(0, ($sched_end - $out_ts) / 3600), 2);
            $overtime   = round(max(0, ($out_ts - $sched_end) / 3600), 2);

            // Only time INSIDE the shift counts as work. Clocking in early does not
            // earn anything, and time past the shift end is overtime — already
            // measured above, so counting it here too would pay it twice.
            // Without this clamp an early arrival inflated work_hours, and the
            // giveaway was work_hours + undertime exceeding the shift length
            // (e.g. 07:47→13:10 on an 08:00–17:00 shift gave 4.39 + 3.82 = 8.21).
            $eff_in     = max($in_ts, $sched_start);
            $eff_out    = min($out_ts, $sched_end);
            $work_hours = round(max(0, ($eff_out - $eff_in) / 3600 - $break_hrs), 2);
            $work_hours = round(min($work_hours, $schedule['total_hours']), 2);

            // Night differential = time worked inside 22:00–06:00. That window is
            // ONE contiguous 8-hour block crossing midnight, so it is built as
            // such and swept across the shift's neighbouring days.
            //
            // It used to be two windows both anchored to $date — 22:00–23:59:59
            // and 00:00–06:00 of the SAME day. For a graveyard shift the second
            // resolved to the morning BEFORE the shift started, so max(0, …)
            // zeroed it and the whole after-midnight half earned nothing: a
            // 22:00→06:00 shift returned 2.00 instead of 8.00. Ingestion always
            // computed this correctly, which meant a manual edit or a recompute
            // silently cut 6 hours of ND off an already-correct row.
            //
            // Sweeping -1/0/+1 days also covers a row whose punches belong to
            // the previous evening's shift. Break is NOT deducted here, matching
            // Action::save_biometric_attendance.
            for ($k = -1; $k <= 1; $k++) {
                $w_start = strtotime(date('Y-m-d', strtotime("$date $k day")) . ' 22:00:00');
                $w_end   = strtotime('+8 hours', $w_start);   // 22:00 → 06:00 next day
                $nsd_hours += max(0, min($out_ts, $w_end) - max($in_ts, $w_start)) / 3600;
            }
            $nsd_hours = round($nsd_hours, 2);
        } elseif ($out_ts) {
            // No schedule on file — nothing to clamp against, so fall back to the
            // raw span (capped at 8h) exactly as before.
            $work_hours = round(min(8, max(0, $raw_hours - $break_hrs)), 2);
        }

        return [
            'work_hours' => $work_hours, 'overtime' => $overtime, 'undertime' => $undertime,
            'late' => $late, 'nsd_hours' => $nsd_hours, 'day_type' => $day_type,
            'is_complete' => $out_ts ? 1 : 0,
            'schedule_id' => isset($schedule['id']) ? (int) $schedule['id'] : null,
            'day_hours'   => isset($schedule['total_hours']) ? (float) $schedule['total_hours'] : null,
            'is_rest_day' => $is_rest,
            // Shift boundaries the figures above were computed against — the
            // rest of the frozen-shift stamp. Write paths persist these onto
            // the row so a later work_schedules edit can't re-price it.
            'sched_start'     => $schedule['start_time'] ?? null,
            'sched_end'       => $schedule['end_time'] ?? null,
            'sched_break'     => isset($schedule['break_minutes']) ? (int) $schedule['break_minutes'] : null,
            'sched_graveyard' => isset($schedule['is_graveyard']) ? (int) $schedule['is_graveyard'] : null,
        ];
    }
}

// ── Schedule-mismatch detection (GLOBAL) ────────────────────────────────
// A row is "stale" when the shift stamped onto it at punch time no longer
// matches the employee_schedules period covering that date — i.e. someone
// changed (or corrected) the employee's schedule AFTER the attendance was
// recorded. Figures are frozen, so nothing recomputes by itself; these
// helpers power the warning banner on dtr-documents.php and the dashboard
// card so an admin knows a batch needs its Recompute button pressed.
// Only stamped rows (schedule_id NOT NULL) are testable — unstamped legacy
// rows carry no record of the shift that computed them. Rest-day-only edits
// are caught too (they overwrite employee_schedules.rest_days in place).
//
// Two branches, mirroring resolve_employee_schedule's first two steps:
//   1. A period COVERS the date and disagrees with the stamp — the normal case.
//   2. NO period covers the date but one had already STARTED — a gap left by a
//      deleted period. A recompute would resolve the last-started period
//      (resolver step 2), so the stamp is stale when THAT disagrees. Requiring
//      a started period is deliberate: rows predating the employee's FIRST
//      assignment were computed under the system default and that stays the
//      right answer — flagging them would demand a recompute that restates
//      history against a shift the employee was not yet on.
// A published duty-roster day OVERRIDES the period roster for that date, so it
// is tested first and the period branches are skipped entirely when one exists
// — mirroring resolve_employee_schedule's own precedence. Testing both would
// flag every rostered nurse forever, since a rotation always disagrees with the
// fixed weekly period underneath it.
if (!function_exists('dtr_schedule_mismatch_where')) {
    function dtr_schedule_mismatch_where(string $alias = 'd'): string
    {
        // The period roster's answer disagrees with the stamp. $cmp selects
        // WHAT to compare, because the day-row branch below reuses this same
        // two-step structure to test the shift alone.
        $periodStale = function (string $cmp) use ($alias) {
            $covering = "SELECT 1 FROM employee_schedules es
                WHERE es.employee_id = $alias.employee_id
                  AND es.effective_from <= $alias.date_time
                  AND (es.effective_to IS NULL OR es.effective_to >= $alias.date_time)";
            return "(EXISTS ($covering AND $cmp)
                OR (NOT EXISTS ($covering)
                    AND EXISTS (
                        SELECT 1 FROM employee_schedules es
                        WHERE es.employee_id = $alias.employee_id
                          AND es.effective_from <= $alias.date_time
                          AND es.effective_from = (
                              SELECT MAX(es2.effective_from) FROM employee_schedules es2
                              WHERE es2.employee_id = $alias.employee_id
                                AND es2.effective_from <= $alias.date_time)
                          AND $cmp
                    )))";
        };

        $shiftDiffers = "es.schedule_id <> $alias.schedule_id";
        $differs = "($shiftDiffers
                OR (FIND_IN_SET(DAYOFWEEK($alias.date_time) - 1, COALESCE(es.rest_days, '')) > 0)
                   <> (COALESCE($alias.is_rest_day, 0) = 1))";

        $dayRow = "SELECT 1 FROM employee_day_schedule eds
            WHERE eds.employee_id = $alias.employee_id
              AND eds.work_date = DATE($alias.date_time)
              AND eds.status = 1";
        // A rostered day with no shift named is a rest day whose BOUNDARIES
        // still come from the period roster (resolver step 0's fallback), so a
        // period shift change restates it too — hence the nested period test.
        $dayDiffers = "(
            (eds.schedule_id IS NOT NULL AND eds.schedule_id <> $alias.schedule_id)
            OR (eds.is_rest_day = 1) <> (COALESCE($alias.is_rest_day, 0) = 1)
            OR (eds.schedule_id IS NULL AND " . $periodStale($shiftDiffers) . ")
        )";

        return "($alias.schedule_id IS NOT NULL AND (
            EXISTS ($dayRow AND $dayDiffers)
            OR (NOT EXISTS ($dayRow) AND " . $periodStale($differs) . ")
        ))";
    }
}

// Per-employee stale-row counts for one batch — feeds the banner on
// dtr-documents.php. Returns ['rows' => total, 'employees' => [{id,name,rows}]].
if (!function_exists('dtr_schedule_mismatches')) {
    function dtr_schedule_mismatches(mysqli $db, int $ddtr_id): array
    {
        $out = ['rows' => 0, 'employees' => []];
        $res = $db->query("
            SELECT d.employee_id AS id,
                   CONCAT(e.lastname, ', ', e.firstname) AS name,
                   COUNT(*) AS n
            FROM DTR_details d
            INNER JOIN employee e ON e.id = d.employee_id
            WHERE d.ddtr_id = " . (int) $ddtr_id . " AND " . dtr_schedule_mismatch_where('d') . "
            GROUP BY d.employee_id, e.lastname, e.firstname
            ORDER BY e.lastname, e.firstname
        ");
        while ($res && ($r = $res->fetch_assoc())) {
            $out['rows'] += (int) $r['n'];
            $out['employees'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'rows' => (int) $r['n']];
        }
        return $out;
    }
}

// ── Overtime request limits (GLOBAL) ────────────────────────────────────
// An OT request may only claim hours the employee's OWN scans actually show.
// Without this, anyone could file "8 hrs OT" for a day they went home on time
// (or never scanned at all) and an approver writing it straight onto the DTR
// would pay it. The ceiling for one date is therefore:
//
//     regular day → the time scanned PAST the shift end (dtr_compute_day's
//                   `overtime`, the same figure the DTR itself shows)
//     rest day    → the whole worked span (minus break) — every hour on a
//                   rest day is overtime, there is no shift to exceed
//
// then floored onto the 0.5-hour grid the form uses, clamped to the per-day
// hard ceiling, and reduced by whatever the employee already has pending or
// approved for that same date (so 4 × "2 hrs" can't sneak past a 3-hour cap).
if (!defined('OT_REQUEST_MIN_HOURS'))         define('OT_REQUEST_MIN_HOURS', 0.5);
if (!defined('OT_REQUEST_STEP_HOURS'))        define('OT_REQUEST_STEP_HOURS', 0.5);
if (!defined('OT_REQUEST_MAX_HOURS_PER_DAY')) define('OT_REQUEST_MAX_HOURS_PER_DAY', 12);

if (!function_exists('ot_request_limit')) {
    /**
     * How much overtime this employee may still file for ONE date.
     *
     * $exclude_request_id lets an edit/re-file ignore its own pending row when
     * totalling what has already been filed.
     *
     * Returns:
     *   allowed      bool   — may file at least OT_REQUEST_MIN_HOURS right now
     *   max_hours    float  — hours still fileable (0 when not allowed)
     *   excess_hours float  — raw overtime the scans show for that date
     *   already      float  — hours already pending/approved for that date
     *   message      string — why it is blocked / how the cap was derived
     *   time_in/time_out/shift_end — display strings for the UI hint
     */
    function ot_request_limit(mysqli $db, int $employee_id, string $date, int $exclude_request_id = 0): array
    {
        $out = [
            'allowed'      => false,
            'max_hours'    => 0.0,
            'excess_hours' => 0.0,
            'already'      => 0.0,
            'message'      => '',
            'time_in'      => '',
            'time_out'     => '',
            'shift_end'    => '',
            'rest_day'     => false,
        ];

        $ts = strtotime($date);
        if ($employee_id <= 0 || !$ts) {
            $out['message'] = 'Please select a valid date.';
            return $out;
        }
        $ymd = date('Y-m-d', $ts);
        if ($ymd > date('Y-m-d')) {
            $out['message'] = 'You cannot file overtime for a future date — file it after you have rendered and scanned it.';
            return $out;
        }
        $ymdEsc  = $db->real_escape_string($ymd);
        $dateStr = date('M d, Y', $ts);

        // The schedule in effect that date — its end time is what OT is measured
        // against, and its rest days decide which rule prices the span.
        $sched = $db->query("
            SELECT ws.end_time, ws.break_minutes, ws.is_graveyard, ws.total_hours, es.rest_days
            FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = " . (int) $employee_id . "
              AND es.effective_from <= '$ymdEsc'
              AND (es.effective_to IS NULL OR es.effective_to >= '$ymdEsc')
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $sched = $sched ? $sched->fetch_assoc() : null;

        // A published duty-roster day beats the period, exactly as it does in
        // resolve_employee_schedule — otherwise a nurse rostered onto NOC would
        // have their OT ceiling measured against the AM shift end the period
        // roster still names, and the cap would disagree with their own DTR.
        // A rostered rest day with no shift keeps the period's boundaries.
        $day = duty_roster_day($db, $employee_id, $ymd);
        if ($day && $day['schedule_id'] !== null) {
            $dsq = $db->query("SELECT end_time, break_minutes, is_graveyard, total_hours
                               FROM work_schedules WHERE id = " . (int) $day['schedule_id'] . " LIMIT 1");
            if ($dsq && ($drow = $dsq->fetch_assoc())) {
                $drow['rest_days'] = '';
                $sched = $drow;
            }
        }

        if (!$sched) {
            $out['message'] = "You have no work schedule on file for $dateStr, so there are no duty hours to measure overtime against. Ask HR to set your schedule first.";
            return $out;
        }
        $out['shift_end'] = date('g:i A', strtotime($ymd . ' ' . $sched['end_time']));

        // The day's actual scans.
        $rec = $db->query(
            "SELECT logs FROM DTR_details
             WHERE employee_id = " . (int) $employee_id . " AND DATE(date_time) = '$ymdEsc'
             ORDER BY id DESC LIMIT 1"
        );
        $rec = $rec ? $rec->fetch_assoc() : null;
        if (!$rec) {
            $out['message'] = "No attendance record for $dateStr yet. Overtime can only be filed for a day you actually scanned — if a scan is missing, file an Incident Report for that date first.";
            return $out;
        }

        $log_ts = [];
        foreach ((json_decode($rec['logs'] ?? '[]', true) ?: []) as $lg) {
            $t = strtotime($lg['dateTime'] ?? '');
            if ($t) $log_ts[] = $t;
        }
        sort($log_ts);
        if (count($log_ts) < 2) {
            $out['message'] = "Your record for $dateStr has no time-out scan, so there is nothing showing you worked past your shift. File an Incident Report for the missing scan first.";
            return $out;
        }
        $in_ts  = $log_ts[0];
        $out_ts = $log_ts[count($log_ts) - 1];
        if ($out_ts <= $in_ts) $out_ts = strtotime('+1 day', $out_ts);
        $out['time_in']  = date('g:i A', $in_ts);
        $out['time_out'] = date('g:i A', $out_ts);

        // Rest day → duty up to one full day's hours is paid AUTOMATICALLY from
        // the punches (basic + rest-day premium in payroll), so it must not be
        // filed as OT too — that double-paid the same hours. Only time rendered
        // BEYOND the full duty is fileable. Regular day → only the part past the
        // shift end (dtr_compute_day, so this always agrees with the DTR).
        if ($day) {
            $out['rest_day'] = ((int) $day['is_rest_day'] === 1);
        } else {
            $rest = array_filter(array_map('intval', explode(',', (string) ($sched['rest_days'] ?? ''))), function ($d) {
                return $d >= 0 && $d <= 6;
            });
            $out['rest_day'] = in_array((int) date('w', $ts), $rest, true);
        }

        $duty = day_hours_or_default($sched['total_hours'] ?? null);
        if ($out['rest_day']) {
            $break  = ($sched['break_minutes'] ?? 60) / 60;
            $excess = round(max(0, ($out_ts - $in_ts) / 3600 - $break - $duty), 2);
        } else {
            $calc   = dtr_compute_day($db, $employee_id, $ymd, $log_ts);
            $excess = round(max(0, (float) $calc['overtime']), 2);
        }
        $out['excess_hours'] = $excess;

        // Floor onto the form's 0.5 grid so the cap is never rounded UP past
        // what was actually rendered, then apply the per-day hard ceiling.
        $step = OT_REQUEST_STEP_HOURS;
        $cap  = min(floor($excess / $step) * $step, (float) OT_REQUEST_MAX_HOURS_PER_DAY);

        if ($cap < OT_REQUEST_MIN_HOURS) {
            $min  = rtrim(rtrim(number_format(OT_REQUEST_MIN_HOURS, 2), '0'), '.');
            $span = "$dateStr ({$out['time_in']} – {$out['time_out']})";
            if ($out['rest_day']) {
                $out['message'] = "Rest-day duty is paid automatically from your scans (with the rest-day premium) — no filing needed. "
                    . "Overtime on a rest day is only the time beyond your full {$duty}-hr duty, and your scans for $span "
                    . ($excess > 0 ? "show only $excess hr beyond it — less than the $min hr minimum." : "do not go beyond it.");
            } elseif ($excess > 0) {
                // Went past the shift end, but by less than one filing step.
                $out['message'] = "Your scans for $span go past your {$out['shift_end']} shift end by only $excess hr — less than the $min hr minimum, so there is no overtime to file.";
            } else {
                $out['message'] = "Your scans for $span do not go past your {$out['shift_end']} shift end, so you have no overtime to file for that date.";
            }
            return $out;
        }

        // Hours already claimed for the same date (pending or approved).
        $ex  = $exclude_request_id > 0 ? " AND id <> " . (int) $exclude_request_id : '';
        $agg = $db->query(
            "SELECT COALESCE(SUM(ot_hours_requested), 0) AS h FROM attendance_requests
             WHERE employee_id = " . (int) $employee_id . " AND request_type = 'overtime'
               AND request_date = '$ymdEsc' AND status IN (0, 1)$ex"
        );
        $already = round((float) ($agg ? ($agg->fetch_assoc()['h'] ?? 0) : 0), 2);
        $out['already'] = $already;

        $remaining = round($cap - $already, 2);
        if ($remaining < OT_REQUEST_MIN_HOURS) {
            $out['message'] = "You have already filed $already of the $cap overtime hours your scans support for $dateStr.";
            return $out;
        }

        $out['allowed']   = true;
        $out['max_hours'] = $remaining;
        $out['message']   = $out['rest_day']
            ? "Rest day — your first {$duty} hrs are paid automatically with the rest-day premium; your scans for $dateStr ({$out['time_in']} – {$out['time_out']}) support up to $remaining hr of overtime beyond that."
            : "Your scans for $dateStr ({$out['time_in']} – {$out['time_out']}) run past your {$out['shift_end']} shift end, so you may file up to $remaining hr of overtime.";
        return $out;
    }
}

// ── Leave eligibility resolver (GLOBAL) ─────────────────────────────────
// Single source of truth for "can this employee file leave / hold credits".
// Honors the per-employee override first, then falls back to classification.
//   $override: employee.leave_override — NULL/'' = auto, 1 = force allow, 0 = force block.
//   $classification: the employee's classification NAME (any case).
// The operative leave year — leave credits are tracked per calendar year.
if (!function_exists('leave_current_year')) {
    function leave_current_year(): int
    {
        return (int) date('Y');
    }
}

// Roles allowed to change leave credits / balances and the eligibility override.
// HR (9) only — Admin (1) and Department Heads (8) get a read-only view.
// Add a role id here to grant it back; every page and the AJAX handlers follow this.
if (!defined('LEAVE_CREDIT_EDIT_ROLES')) {
    define('LEAVE_CREDIT_EDIT_ROLES', [9]);
}

if (!function_exists('can_edit_leave_credits')) {
    /** May this role edit leave credits? Defaults to the logged-in user. */
    function can_edit_leave_credits($role = null): bool
    {
        $role = (int) ($role !== null ? $role : ($_SESSION['login_role'] ?? 0));
        return in_array($role, LEAVE_CREDIT_EDIT_ROLES, true);
    }
}

if (!function_exists('leave_eligibility_from')) {
    function leave_eligibility_from($classification, $override): bool
    {
        if ($override !== null && $override !== '') {
            return ((int) $override === 1);
        }
        return in_array(strtoupper(trim((string) $classification)), LEAVE_ELIGIBLE_CLASSIFICATIONS, true);
    }
}

// ── Leave vs attendance overlap (GLOBAL) ────────────────────────────────
// Which of $days this employee already has DTR attendance on (hours > 0).
//
// Filing leave on such a day is ALLOWED — a half-day, or an emergency leave
// taken mid-shift, is legitimate and the punches are real. It is only flagged
// so the filer and the approver can see it. The money side is handled
// separately: get_payroll_rows_data caps worked-fraction + leave-fraction at
// one day, so an overlapping day can never be paid twice.
if (!function_exists('leave_dates_with_attendance')) {
    function leave_dates_with_attendance(mysqli $db, int $employee_id, array $days): array
    {
        if ($employee_id <= 0 || !$days) return [];
        $esc = [];
        foreach ($days as $d) {
            $t = strtotime((string) $d);
            if ($t) $esc[] = "'" . $db->real_escape_string(date('Y-m-d', $t)) . "'";
        }
        if (!$esc) return [];
        $in  = implode(',', array_unique($esc));
        $res = $db->query("SELECT DISTINCT date_time FROM DTR_details
                           WHERE employee_id = $employee_id AND work_hours > 0
                             AND date_time IN ($in) ORDER BY date_time");
        $hit = [];
        if ($res) while ($r = $res->fetch_row()) $hit[] = $r[0];
        return $hit;
    }

    /** Human-readable "attendance already recorded on …" note, or '' when clear. */
    function leave_attendance_note(mysqli $db, int $employee_id, array $days): string
    {
        $hit = leave_dates_with_attendance($db, $employee_id, $days);
        if (!$hit) return '';
        $nice = array_map(function ($d) { return date('M j', strtotime($d)); }, array_slice($hit, 0, 5));
        return ' Note: attendance is already recorded on ' . implode(', ', $nice)
            . (count($hit) > 5 ? '…' : '')
            . ' — those days will be paid as worked + leave capped at one day.';
    }
}

// ── Leave approval workflow (GLOBAL) ────────────────────────────────────
// The ORDERED chain a leave request must pass through. Edit THIS ONE array to
// reorder, add, or remove approval stages — the leaves page, employee portal,
// decision handler, notifications, and timeline all follow it automatically.
//
// Each stage's key is also its column prefix on `leave_requests`:
//     {key}_status  (0 pending · 1 approved · 2 rejected)
//     {key}_by      (users.id of the approver)
//     {key}_remarks (reason, mainly on reject)
//     {key}_at      (decision timestamp)
// `role` is the users.role allowed to act on that stage.
//
// `optional` => true marks a stage that is SKIPPED when the employee's
// department has nobody holding that role. Filing resolves this once and
// stamps the stage approved with an "auto-skipped" remark, so the chain simply
// moves on instead of parking the request on an approver who does not exist.
// Leave it out (or set false) to make a stage mandatory — a request will then
// wait indefinitely if no such approver is assigned.
//
// Current flow:  Employee → Department Head → Supervisor (skipped when the
//                department has none) → HR (final).
if (!defined('LEAVE_APPROVAL_STAGES')) {
    define('LEAVE_APPROVAL_STAGES', [
        'admin' => ['label' => 'Department Head', 'role' => 8,  'icon' => 'ri-shield-check-line'],
        'sup'   => ['label' => 'Supervisor',      'role' => 10, 'icon' => 'ri-user-star-line', 'optional' => true],
        'hr'    => ['label' => 'HR',              'role' => 9,  'icon' => 'ri-user-settings-line'],
    ]);
}

if (!function_exists('leave_stages')) {
    /** The ordered leave-approval stages (key => [label, role, icon]). */
    function leave_stages(): array
    {
        return LEAVE_APPROVAL_STAGES;
    }

    /** Stage key a given user role may act on, or null if that role never approves leave. */
    function leave_stage_for_role($role): ?string
    {
        foreach (LEAVE_APPROVAL_STAGES as $key => $s) {
            if ((int) $s['role'] === (int) $role) return $key;
        }
        return null;
    }

    /**
     * The stage currently awaiting action: the first stage not yet approved.
     * Returns null when the chain is fully approved OR already rejected (no
     * further action is possible in either case).
     */
    function leave_current_stage($row): ?string
    {
        foreach (LEAVE_APPROVAL_STAGES as $key => $s) {
            $st = (int) ($row[$key . '_status'] ?? 0);
            if ($st === 2) return null;   // a rejection halts the chain
            if ($st !== 1) return $key;   // first stage still pending
        }
        return null;                      // every stage approved
    }

    /** Overall status derived from the per-stage statuses: 2 rejected, 1 all-approved, else 0. */
    function leave_overall_status($row): int
    {
        $all_approved = true;
        foreach (LEAVE_APPROVAL_STAGES as $key => $s) {
            $st = (int) ($row[$key . '_status'] ?? 0);
            if ($st === 2) return 2;
            if ($st !== 1) $all_approved = false;
        }
        return $all_approved ? 1 : 0;
    }

    /**
     * SQL WHERE predicate (no leading AND) matching leave requests currently
     * awaiting action at the given stage: overall pending, every earlier stage
     * approved, and this stage not yet decided. Returns '0' for an unknown key.
     */
    function leave_stage_pending_predicate(string $stageKey): string
    {
        $keys = array_keys(LEAVE_APPROVAL_STAGES);
        $idx  = array_search($stageKey, $keys, true);
        if ($idx === false) return '0';
        $conds = ['status=0', "{$stageKey}_status=0"];
        for ($j = 0; $j < $idx; $j++) $conds[] = $keys[$j] . '_status=1';
        return implode(' AND ', $conds);
    }

    /**
     * Does anyone exist who could actually decide $stageKey for this employee?
     *
     * Uses the SAME predicate that decides who gets NOTIFIED for a stage
     * (notifyRoleForEmployee in admin_class.php): an active user holding the
     * stage's role, either in the employee's own department or unscoped
     * (department_id NULL/0 = covers every department). Keeping the two in step
     * matters — a stage we consider "staffed" but never notify would strand the
     * request in silence.
     */
    function leave_stage_has_approver(mysqli $db, string $stageKey, int $employee_id): bool
    {
        $stages = LEAVE_APPROVAL_STAGES;
        if (!isset($stages[$stageKey])) return false;
        $role = (int) $stages[$stageKey]['role'];

        $dept = 0;
        $er = $db->query("SELECT department_id FROM employee WHERE id = " . (int) $employee_id);
        if ($er && ($e = $er->fetch_assoc())) $dept = (int) $e['department_id'];

        $r = $db->query(
            "SELECT 1 FROM users
             WHERE role = $role AND status = 1
               AND (department_id = $dept OR department_id IS NULL OR department_id = 0)
             LIMIT 1"
        );
        return (bool) ($r && $r->num_rows);
    }

    /**
     * Resolve every `optional` stage that has no approver for this employee and
     * stamp it approved with an auto-skip remark, so the chain moves straight to
     * the next real approver.
     *
     * Resolved ONCE, at filing time, deliberately: the alternative — deciding it
     * live on every read — would silently re-route a request in flight the
     * moment someone is hired or reassigned. Stamping it makes the skip a fact
     * of record that shows up in the timeline like any other decision.
     *
     * Returns the labels of the stages that were skipped.
     */
    function leave_autoskip_stages(mysqli $db, int $leave_id, int $employee_id): array
    {
        $skipped = [];
        foreach (LEAVE_APPROVAL_STAGES as $key => $cfg) {
            if (empty($cfg['optional'])) continue;
            if (leave_stage_has_approver($db, $key, $employee_id)) continue;

            $note = $db->real_escape_string(
                'Auto-skipped — no ' . $cfg['label'] . ' assigned to this department.'
            );
            $db->query(
                "UPDATE leave_requests
                 SET {$key}_status = 1, {$key}_by = NULL,
                     {$key}_remarks = '$note', {$key}_at = NOW()
                 WHERE id = " . (int) $leave_id
            );
            $skipped[] = $cfg['label'];
        }
        return $skipped;
    }

    /**
     * The stage a freshly filed request is actually waiting on, and its config —
     * i.e. the first stage that was not auto-skipped. Returns [key, cfg] or
     * [null, null] when every stage got skipped (nobody left to notify).
     */
    function leave_first_open_stage(mysqli $db, int $leave_id): array
    {
        $row = $db->query("SELECT * FROM leave_requests WHERE id = " . (int) $leave_id);
        $row = $row ? $row->fetch_assoc() : null;
        if (!$row) return [null, null];
        $key = leave_current_stage($row);
        return $key === null ? [null, null] : [$key, LEAVE_APPROVAL_STAGES[$key]];
    }
}

// ── Classification badge colors (GLOBAL) ────────────────────────────────
// One source of truth for classification badge colors across all pages.
// Returns an inline style string. Edit the map to recolor app-wide.
if (!function_exists('clasif_badge_style')) {
    function clasif_badge_style($name)
    {
        $map = [
            'REGULAR'      => '#198754', // green
            'EXECUTIVE'    => '#6f42c1', // purple
            'INTERM'       => '#dc3545', // red
            'INTERN'       => '#dc3545', // red
            'PROBATIONARY' => '#fd7e14', // orange
            'CONTRACTUAL'  => '#0d6efd', // blue
            'TEMPORARY'    => '#6c757d', // gray
            'CONSULTANTS'  => '#20c997', // teal-green
            'ON-CALL'      => '#0dcaf0', // cyan
            'FULLTIME'     => '#198754', // green
        ];
        $color = $map[strtoupper(trim((string) $name))] ?? '#673bb6';
        return 'background:' . $color . ';color:#fff;';
    }
}

error_reporting(E_ALL);
if (APP_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $__logdir = __DIR__ . '/logs';
    if (!is_dir($__logdir)) { @mkdir($__logdir, 0775, true); }
    ini_set('error_log', $__logdir . '/php-error.log');
} else {
    ini_set('display_errors', '1');
}

/* Database credentials.
 *
 * Read from the environment when provided so the live box never has to keep a
 * real password in a web-served file (set DB_USER / DB_PASS via SetEnv or the
 * PHP-FPM pool config). The XAMPP defaults remain the fallback so an existing
 * local install keeps working untouched.
 *
 * ACTION REQUIRED ON THE LIVE SERVER: 'root' with an empty password gives the
 * app — and anything that finds an injection hole in it — full control of the
 * MySQL instance. Create a dedicated user restricted to the payroll schema
 * with only SELECT/INSERT/UPDATE/DELETE (no FILE, DROP or GRANT), and export
 * DB_USER/DB_PASS for it.
 */
$servername = getenv('DB_HOST') ?: "localhost";
$username   = getenv('DB_USER') ?: "root";
$password   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$dbname     = getenv('DB_NAME') ?: "payroll";

// Biometric scanner API key. Prefer the environment: a literal here is readable
// by anyone who obtains the source or the git history, and rotating it means
// editing and redeploying a file.
if (!defined('BIOMETRIC_API_KEY')) {
    define('BIOMETRIC_API_KEY', getenv('BIOMETRIC_API_KEY')
        ?: 'accdad483efc02d030a269bc704cf3230608159f90ff90ba2ee10a3dfda74318');
}

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // The raw driver message names the host, user and database, so it only goes
    // to the log in production; users get a generic failure.
    error_log('DB connection failed: ' . $conn->connect_error);
    if (APP_ENV === 'prod') {
        http_response_code(503);
        die('The service is temporarily unavailable. Please try again shortly.');
    }
    die("Connection failed: " . $conn->connect_error);
}

// Optionally, set charset
$conn->set_charset("utf8mb4");

// Align MySQL session time so NOW()/CURDATE() use Manila time (UTC+08:00).
$conn->query("SET time_zone = '+08:00'");

// Return connection object
return $conn;
?>