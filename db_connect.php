<?php
/* ──────────────────────────────────────────────────────────────
 * Central error-reporting switch.
 * Change APP_ENV to 'prod' before deploying to a live server.
 *   dev  → show every error on screen (for debugging)
 *   prod → never show errors to users; log them to logs/php-error.log
 * ────────────────────────────────────────────────────────────── */
if (!defined('APP_ENV')) {
    define('APP_ENV', 'dev');   // 'dev' | 'prod'
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

// Application timezone — used by all PHP date()/DateTime calls.
date_default_timezone_set('Asia/Manila');

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
    define('DTR_DEFAULT_SHIFT', 'Day Shift');
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

// ── DTR day math (GLOBAL) ───────────────────────────────────────────────
// Single source of truth for computing one DTR_details row's figures from
// its raw log timestamps + the employee's effective schedule + the holiday
// calendar. Used by the manual time edit, the incident-report repair and
// batch recompute so the three can never drift apart.
//   $log_ts: unix timestamps of the record's logs, any order.
// Returns [work_hours, overtime, undertime, late, nsd_hours, day_type, is_complete].
if (!function_exists('dtr_compute_day')) {
    function dtr_compute_day(mysqli $db, int $employee_id, string $date, array $log_ts): array
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

        $schedule = $db->query("
            SELECT ws.* FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $employee_id AND es.effective_from <= '$dateEsc'
              AND (es.effective_to IS NULL OR es.effective_to >= '$dateEsc')
            ORDER BY es.effective_from DESC LIMIT 1
        ")->fetch_assoc();

        $raw_hours  = ($in_ts && $out_ts) ? ($out_ts - $in_ts) / 3600 : 0;
        $break_hrs  = ($schedule['break_minutes'] ?? 60) / 60;
        $work_hours = $out_ts ? round(max(0, $raw_hours - $break_hrs), 2) : 0;
        $overtime = $late = $undertime = $nsd_hours = 0;
        if ($out_ts && $schedule) {
            $sched_start = strtotime($date . ' ' . $schedule['start_time']);
            $sched_end   = strtotime($date . ' ' . $schedule['end_time']);
            if ($schedule['is_graveyard']) $sched_end = strtotime('+1 day', $sched_end);
            $late       = round(max(0, ($in_ts - $sched_start) / 3600), 2);
            $undertime  = round(max(0, ($sched_end - $out_ts) / 3600), 2);
            $overtime   = round(max(0, ($out_ts - $sched_end) / 3600), 2);
            $work_hours = round(min($work_hours, $schedule['total_hours']), 2);
            foreach ([[$date . ' 22:00:00', $date . ' 23:59:59'], [$date . ' 00:00:00', $date . ' 06:00:00']] as $w) {
                $nsd_hours += max(0, min($out_ts, strtotime($w[1])) - max($in_ts, strtotime($w[0]))) / 3600;
            }
            $nsd_hours = round($nsd_hours, 2);
        } elseif ($out_ts) {
            $work_hours = round(min(8, $work_hours), 2);
        }

        return [
            'work_hours' => $work_hours, 'overtime' => $overtime, 'undertime' => $undertime,
            'late' => $late, 'nsd_hours' => $nsd_hours, 'day_type' => $day_type,
            'is_complete' => $out_ts ? 1 : 0,
        ];
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

if (!function_exists('leave_eligibility_from')) {
    function leave_eligibility_from($classification, $override): bool
    {
        if ($override !== null && $override !== '') {
            return ((int) $override === 1);
        }
        return in_array(strtoupper(trim((string) $classification)), LEAVE_ELIGIBLE_CLASSIFICATIONS, true);
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
// Current flow:  Employee → Supervisor → Department Head → HR (final).
if (!defined('LEAVE_APPROVAL_STAGES')) {
    define('LEAVE_APPROVAL_STAGES', [
        'sup'   => ['label' => 'Supervisor',      'role' => 10, 'icon' => 'ri-user-star-line'],
        'admin' => ['label' => 'Department Head', 'role' => 8,  'icon' => 'ri-shield-check-line'],
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
        $color = $map[strtoupper(trim((string) $name))] ?? '#009688';
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

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "payroll";

// Biometric scanner API key — change this before deploying, keep it secret.
if (!defined('BIOMETRIC_API_KEY')) {
    define('BIOMETRIC_API_KEY', 'accdad483efc02d030a269bc704cf3230608159f90ff90ba2ee10a3dfda74318');
}

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optionally, set charset
$conn->set_charset("utf8mb4");

// Align MySQL session time so NOW()/CURDATE() use Manila time (UTC+08:00).
$conn->query("SET time_zone = '+08:00'");

// Return connection object
return $conn;
?>