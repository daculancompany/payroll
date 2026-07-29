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
        'profile',              // own account
    ]);
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
        $dateEsc = $db->real_escape_string($date);
        $row = $db->query("
            SELECT ws.total_hours FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = " . (int) $employee_id . "
              AND es.effective_from <= '$dateEsc'
              AND (es.effective_to IS NULL OR es.effective_to >= '$dateEsc')
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $r = $row ? $row->fetch_assoc() : null;
        return day_hours_or_default($r['total_hours'] ?? null);
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
        // against, and its rest days decide whether the whole span counts.
        $sched = $db->query("
            SELECT ws.end_time, ws.break_minutes, ws.is_graveyard, es.rest_days
            FROM employee_schedules es
            INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = " . (int) $employee_id . "
              AND es.effective_from <= '$ymdEsc'
              AND (es.effective_to IS NULL OR es.effective_to >= '$ymdEsc')
            ORDER BY es.effective_from DESC LIMIT 1
        ");
        $sched = $sched ? $sched->fetch_assoc() : null;
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

        // Rest day → the whole worked span is overtime; otherwise only the part
        // past the shift end (dtr_compute_day, so this always agrees with the DTR).
        $rest = array_filter(array_map('intval', explode(',', (string) ($sched['rest_days'] ?? ''))), function ($d) {
            return $d >= 0 && $d <= 6;
        });
        $out['rest_day'] = in_array((int) date('w', $ts), $rest, true);

        if ($out['rest_day']) {
            $break  = ($sched['break_minutes'] ?? 60) / 60;
            $excess = round(max(0, ($out_ts - $in_ts) / 3600 - $break), 2);
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
                $out['message'] = "Your scans for $span total less than $min hr of work, so there is no overtime to file.";
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
            ? "Rest day — your scans for $dateStr ({$out['time_in']} – {$out['time_out']}) support up to $remaining hr of overtime."
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