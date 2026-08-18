<?php
/**
 * Daily Attendance Board — data builder.
 *
 * Shared by the on-screen board (daily-board.php) and the CSV export
 * (export-daily-board.php) so the two can never disagree about who was absent.
 *
 * Everything the board knows about one date is assembled here in a fixed number
 * of queries (no per-employee lookups), then each employee row is given an
 * 'att' status by daily_board_status().
 */

require_once __DIR__ . '/../dept-scope.php';

/**
 * Status vocabulary, in the order it should appear as summary cards / head chips.
 * Keep this the single source — the board renders its cards straight off it.
 */
if (!defined('DAILY_BOARD_STATUSES')) {
    define('DAILY_BOARD_STATUSES', [
        'Present'     => ['class' => 'success',   'icon' => 'ri-checkbox-circle-line'],
        'Late'        => ['class' => 'warning',   'icon' => 'ri-alarm-warning-line'],
        'No Time-out' => ['class' => 'attn',      'icon' => 'ri-logout-box-r-line'],
        'Absent'      => ['class' => 'danger',    'icon' => 'ri-close-circle-line'],
        'Not Yet Due' => ['class' => 'info',      'icon' => 'ri-time-line'],
        'Scheduled'   => ['class' => 'secondary', 'icon' => 'ri-calendar-line'],
        'Day Off'     => ['class' => 'secondary', 'icon' => 'ri-moon-line'],
        'On Leave'    => ['class' => 'leave',     'icon' => 'ri-suitcase-line'],
        'Holiday'     => ['class' => 'holiday',   'icon' => 'ri-flag-2-line'],
    ]);
}

/** Statuses that mean the employee actually punched in. */
if (!defined('DAILY_BOARD_IN_STATUSES')) {
    define('DAILY_BOARD_IN_STATUSES', ['Present', 'Late', 'No Time-out']);
}

/** Statuses that were never expected to punch — excluded from the attendance rate. */
if (!defined('DAILY_BOARD_OFF_STATUSES')) {
    define('DAILY_BOARD_OFF_STATUSES', ['Scheduled', 'Day Off', 'On Leave', 'Holiday']);
}

/**
 * The holiday covering $date, or null. Same source and the same type codes
 * dtr_compute_day() reads (db_connect.php) — 1 = legal, 3 = special — so the
 * board can never call a day regular that payroll prices as a holiday.
 */
function daily_board_holiday(mysqli $conn, string $date): ?array
{
    $esc = $conn->real_escape_string($date);
    $q = $conn->query("
        SELECT title, type FROM calendar_events
        WHERE '$esc' BETWEEN start_date AND COALESCE(end_date, '$esc')
          AND type IN (1, 3)
        ORDER BY type ASC LIMIT 1
    ");
    $row = $q ? $q->fetch_assoc() : null;
    if (!$row) return null;
    return [
        'title' => (string) $row['title'],
        'kind'  => ((int) $row['type'] === 1) ? 'Legal Holiday' : 'Special Holiday',
    ];
}

/**
 * Work out an attendance status for one employee on the board date.
 *
 * $dtrRow is the DTR_details row for the date (or null). Precedence for a day
 * with no punches: approved leave, then holiday, then rest day — each says WHY
 * nobody was expected, most employee-specific first.
 */
function daily_board_status(?array $dtrRow, ?string $shiftStart, bool $isFuture, bool $isToday, bool $isRestDay = false, ?array $leave = null, ?array $holiday = null, ?string $shiftEnd = null): array
{
    $make = function (string $label, array $extra = []) {
        $meta = DAILY_BOARD_STATUSES[$label];
        return array_merge(['label' => $label, 'class' => $meta['class'], 'icon' => $meta['icon'], 'in' => null, 'out' => null], $extra);
    };

    // Has the shift this employee was on already finished? A missing time-out is
    // only worth flagging once it is — mid-shift there is nothing to punch out
    // of yet, and flagging it would paint the whole afternoon board orange.
    // A past date is always over; today with no shift on file is unknowable, so
    // it is treated as still running rather than raising a false alarm.
    $shiftOver = !$isToday && !$isFuture;
    if ($isToday && $shiftEnd) {
        $endTs   = strtotime(date('Y-m-d') . ' ' . $shiftEnd);
        $startTs = $shiftStart ? strtotime(date('Y-m-d') . ' ' . $shiftStart) : null;
        // Graveyard shift: the end time belongs to the next calendar day.
        if ($startTs !== null && $endTs <= $startTs) $endTs = strtotime('+1 day', $endTs);
        $shiftOver = time() >= $endTs;
    }

    if ($dtrRow) {
        $logs = json_decode($dtrRow['logs'] ?? '[]', true) ?: [];
        usort($logs, fn($a, $b) => strcmp($a['dateTime'] ?? '', $b['dateTime'] ?? ''));
        if (!empty($logs)) {
            $in  = $logs[0]['dateTime'] ?? null;
            $out = count($logs) > 1 ? end($logs)['dateTime'] : null;
            // late / undertime / overtime are all stored in HOURS (dtr_compute_day)
            $figures = [
                'in'   => $in,
                'out'  => $out,
                'late' => (float) ($dtrRow['late'] ?? 0),
                'ut'   => (float) ($dtrRow['undertime'] ?? 0),
                'ot'   => (float) ($dtrRow['overtime'] ?? 0),
            ];
            // dtr_compute_day() only prices a day once it closes (`if ($out_ts &&
            // $schedule)`), so a row still in progress carries late = 0 and this
            // morning's late arrivals would be invisible all morning. Derive the
            // same figure from the punch for display only — nothing is written
            // back, and the 12h guard keeps a graveyard punch from being measured
            // against the wrong calendar day's shift start.
            if ($isToday && !$shiftOver && $figures['late'] <= 0 && $shiftStart && $in) {
                $diff = (strtotime($in) - strtotime(date('Y-m-d') . ' ' . $shiftStart)) / 3600;
                if ($diff > 0 && $diff < 12) $figures['late'] = round($diff, 2);
            }
            // Punched in but never out, and the shift has already ended. On a
            // *daily* board this is the one thing worth catching the same day,
            // so it outranks Late — the late minutes still ride along as a chip.
            if ($shiftOver && ((int) ($dtrRow['is_complete'] ?? 0) !== 1 || $out === null)) {
                return $make('No Time-out', $figures);
            }
            if ($figures['late'] > 0) return $make('Late', $figures);
            return $make('Present', $figures);
        }
        // A row with zero logs is indistinguishable from no row at all, so it
        // falls through to the same reasoning below rather than getting its own
        // "No Record" bucket nobody could act on.
    }

    if ($leave) {
        return $make('On Leave', [
            'leave_name' => $leave['name'],
            'leave_half' => $leave['half'],
            'leave_part' => $leave['part'],
        ]);
    }
    if ($holiday)   return $make('Holiday', ['holiday_name' => $holiday['title']]);
    if ($isRestDay) return $make('Day Off');
    if ($isFuture)  return $make('Scheduled');
    if ($isToday && $shiftStart && strtotime(date('Y-m-d') . ' ' . $shiftStart) > time()) {
        return $make('Not Yet Due');
    }
    return $make('Absent');
}

/** "1h 15m" / "45m" from a figure stored in hours; '' when zero. */
function daily_board_hm(float $hours): string
{
    $mins = (int) round($hours * 60);
    if ($mins <= 0) return '';
    if ($mins < 60) return $mins . 'm';
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $m ? $h . 'h ' . $m . 'm' : $h . 'h';
}

/**
 * Everything the board needs for one date.
 *
 * The employee list is narrowed by dept_scope_sql(), so a Department Head /
 * Supervisor / Section Head sees only their own people — the same scoping the
 * rest of the app applies, and the same scope the per-row edit permission
 * (roster_can_edit_area) already honoured.
 */
function daily_board_data(mysqli $conn, string $target_date): array
{
    $today = date('Y-m-d');
    if (!strtotime($target_date)) $target_date = $today;

    $is_future = $target_date > $today;
    $is_today  = $target_date === $today;
    $esc       = $conn->real_escape_string($target_date);

    // Shift definitions, ordered by start time (drives grouping order)
    $shift_rows = [];
    $sq = $conn->query("SELECT id, description, start_time, end_time, is_graveyard FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
    while ($sq && $s = $sq->fetch_assoc()) $shift_rows[] = $s;

    $holiday = daily_board_holiday($conn, $target_date);

    // Every visible active employee + whichever shift was in effect ON the
    // target date (not just "current")
    $emp_q = $conn->query("
        SELECT e.id, e.firstname, e.lastname, e.middlename, e.employee_no, e.area_id,
               p.name AS pname, d.name AS dept_name, c.clasification,
               es.schedule_id AS shift_id, ws.description AS shift_desc,
               ws.start_time AS shift_start, ws.end_time AS shift_end, ws.is_graveyard,
               es.rest_days
        FROM employee e
        INNER JOIN position p ON e.position_id = p.id
        INNER JOIN clasification c ON e.clasification_id = c.id
        LEFT JOIN department d ON e.department_id = d.id
        LEFT JOIN employee_schedules es ON es.employee_id = e.id
             AND es.effective_from <= '$esc'
             AND (es.effective_to IS NULL OR es.effective_to >= '$esc')
        LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
        WHERE e.status = 1" . dept_scope_sql('e.department_id') . "
        ORDER BY e.lastname ASC, e.firstname ASC
    ");
    $employees = $emp_q ? $emp_q->fetch_all(MYSQLI_ASSOC) : [];

    // Published duty-roster day for the target date wins over the period roster —
    // the more specific answer for rotating staff, same precedence
    // resolve_employee_schedule() applies (db_connect.php). Batched for the whole
    // board instead of resolved per employee to avoid an N+1 on this page.
    $duty_by_emp = [];
    $dutyq = $conn->query("
        SELECT eds.employee_id, eds.schedule_id, eds.is_rest_day,
               ws.description AS shift_desc, ws.start_time AS shift_start,
               ws.end_time AS shift_end, ws.is_graveyard
        FROM employee_day_schedule eds
        LEFT JOIN work_schedules ws ON ws.id = eds.schedule_id
        WHERE eds.work_date = '$esc' AND eds.status = 1
    ");
    while ($dutyq && $d = $dutyq->fetch_assoc()) $duty_by_emp[$d['employee_id']] = $d;

    // The lock freezes every roster write board-wide (mirrors admin_class's
    // dutyRosterLockDeny — that check is private to the class, so it's re-read
    // here directly for the UI gate; duty_roster_save() still enforces it).
    $duty_locked = false;
    $lkq = $conn->query("SELECT setting_value FROM pay_settings WHERE setting_key = 'duty_roster_locked'");
    if ($lkq && ($lkr = $lkq->fetch_assoc())) $duty_locked = ((float) $lkr['setting_value']) >= 1;

    $leave_by_emp   = [];
    $pending_by_emp = [];
    if ($employees) {
        $empIdList = implode(',', array_map(fn($e) => (int) $e['id'], $employees));

        // Approved leave covering the target date — same source (leave_requests,
        // status = 1 approved) duty_roster_publish's leave-conflict check reads
        // (dutyLeaveMap, admin_class.php), scoped here to just the one board date.
        $lvq = $conn->query("
            SELECT lr.employee_id, lr.dates, lr.is_half_day, lr.half_date, lr.half_period,
                   COALESCE(lt.name, 'Leave') AS type_name
            FROM leave_requests lr
            LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.status = 1
              AND lr.employee_id IN ($empIdList)
              AND lr.date_from <= '$esc'
              AND lr.date_to   >= '$esc'
        ");
        while ($lvq && $lv = $lvq->fetch_assoc()) {
            // Non-contiguous leave (an explicit day list) must actually name this
            // date — date_from/date_to alone only bracket the outer range.
            if (!empty($lv['dates'])) {
                $days = json_decode($lv['dates'], true);
                if (is_array($days)) {
                    $named = false;
                    foreach ($days as $dy) if (date('Y-m-d', strtotime($dy)) === $target_date) { $named = true; break; }
                    if (!$named) continue;
                }
            }
            $half = ((int) $lv['is_half_day'] === 1 && !empty($lv['half_date'])
                     && date('Y-m-d', strtotime($lv['half_date'])) === $target_date);
            $leave_by_emp[$lv['employee_id']] = [
                'name' => (string) $lv['type_name'],
                'half' => $half ? 1 : 0,
                'part' => $half ? (string) ($lv['half_period'] ?? '') : '',
            ];
        }

        // Attendance corrections still awaiting review for this date (status 0 =
        // pending). Surfacing them here is the point of a daily board: the
        // approver sees the disputed days next to the status being disputed.
        $prq = $conn->query("
            SELECT employee_id, COUNT(*) AS n,
                   GROUP_CONCAT(DISTINCT REPLACE(request_type, '_', ' ') ORDER BY request_type SEPARATOR ', ') AS types
            FROM attendance_requests
            WHERE request_date = '$esc' AND status = 0 AND employee_id IN ($empIdList)
            GROUP BY employee_id
        ");
        while ($prq && $pr = $prq->fetch_assoc()) {
            $pending_by_emp[$pr['employee_id']] = ['n' => (int) $pr['n'], 'types' => (string) $pr['types']];
        }
    }

    // Actual attendance rows for that date, keyed by employee_id
    $dtr_by_emp = [];
    $dq = $conn->query("SELECT employee_id, work_hours, late, undertime, overtime, logs, is_complete
                        FROM DTR_details WHERE date_time = '$esc'");
    while ($dq && $d = $dq->fetch_assoc()) $dtr_by_emp[$d['employee_id']] = $d;

    // Weekday number for the target date, 0=Sunday..6=Saturday — same convention
    // employee_schedules.rest_days is written in (dtr_compute_day, db_connect.php).
    $target_weekday = (int) date('w', strtotime($target_date));

    foreach ($employees as &$r) {
        $r['is_rest_day'] = 0;
        $duty = $duty_by_emp[$r['id']] ?? null;
        if ($duty) {
            // Rostered day: the grid said so explicitly, for THIS date — a
            // rotating employee's day off moves through the week, so the fixed
            // weekday CSV below cannot answer for them and must not be consulted.
            $r['is_rest_day'] = (int) $duty['is_rest_day'];
            if ($duty['schedule_id'] !== null) {
                $r['shift_id']     = (int) $duty['schedule_id'];
                $r['shift_desc']   = $duty['shift_desc'];
                $r['shift_start']  = $duty['shift_start'];
                $r['shift_end']    = $duty['shift_end'];
                $r['is_graveyard'] = $duty['is_graveyard'];
            }
        } else {
            // Not on the day grid at all (fixed staff, or a rotating employee with
            // no row this date) — fall back to the period roster's weekday rest
            // days, same rule dtr_compute_day() applies for an unstamped day.
            $rest_csv = (string) ($r['rest_days'] ?? '');
            if ($rest_csv !== '' && in_array($target_weekday, array_map('intval', explode(',', $rest_csv)), true)) {
                $r['is_rest_day'] = 1;
            }
        }
        $r['leave']   = $leave_by_emp[$r['id']] ?? null;
        $r['pending'] = $pending_by_emp[$r['id']] ?? null;
        // roster_can_edit_area() already covers the unscoped-role case (returns
        // true), so this one call is the whole permission check for this row.
        $r['can_adjust'] = !$duty_locked && roster_can_edit_area((int) ($r['area_id'] ?? 0));
        $r['att'] = daily_board_status(
            $dtr_by_emp[$r['id']] ?? null, $r['shift_start'] ?? null,
            $is_future, $is_today, !empty($r['is_rest_day']), $r['leave'], $holiday,
            $r['shift_end'] ?? null
        );
    }
    unset($r);

    // Day summary strip
    $summary = array_fill_keys(array_keys(DAILY_BOARD_STATUSES), 0);
    foreach ($employees as $r) $summary[$r['att']['label']]++;

    // Attendance rate: who clocked in vs who was expected to (today excludes
    // shifts not yet due; days off, leave and holidays are never "expected")
    $attended = 0;
    foreach (DAILY_BOARD_IN_STATUSES as $s) $attended += $summary[$s];
    $expected = count($employees) - ($is_today ? $summary['Not Yet Due'] : 0);
    foreach (DAILY_BOARD_OFF_STATUSES as $s) $expected -= $summary[$s];
    $att_rate = $expected > 0 ? (int) round($attended / $expected * 100) : 0;

    return [
        'date'        => $target_date,
        'is_today'    => $is_today,
        'is_future'   => $is_future,
        'prev_date'   => date('Y-m-d', strtotime($target_date . ' -1 day')),
        'next_date'   => date('Y-m-d', strtotime($target_date . ' +1 day')),
        // Cutoff half this date falls in, in the "Y-m-<1|2>" shape dutyPeriodRange()
        // (admin_class.php) expects — duty_roster_save/recompute are period-scoped.
        'duty_period' => date('Y-m', strtotime($target_date)) . '-' . ((int) date('j', strtotime($target_date)) <= 15 ? '1' : '2'),
        'shift_rows'  => $shift_rows,
        'employees'   => $employees,
        'summary'     => $summary,
        'attended'    => $attended,
        'expected'    => max(0, $expected),
        'att_rate'    => $att_rate,
        'holiday'     => $holiday,
        'duty_locked' => $duty_locked,
    ];
}
