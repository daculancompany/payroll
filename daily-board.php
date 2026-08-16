<?php
// Daily Attendance Board — pick a date, see who's on which shift and whether they've
// actually clocked in yet, grouped by Shift (default) or Department.

require_once 'dept-scope.php';

$today = date('Y-m-d');
$target_date = isset($_GET['date']) ? trim($_GET['date']) : $today;
if (!strtotime($target_date)) $target_date = $today;
$is_future = $target_date > $today;
$is_today  = $target_date === $today;

$prev_date = date('Y-m-d', strtotime($target_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($target_date . ' +1 day'));

// Cutoff half this date falls in, in the "Y-m-<1|2>" shape dutyPeriodRange()
// (admin_class.php) expects — duty_roster_save/recompute are period-scoped.
$duty_period = date('Y-m', strtotime($target_date)) . '-' . ((int) date('j', strtotime($target_date)) <= 15 ? '1' : '2');

// Shift definitions, ordered by start time (drives both grouping order and the header legend)
$shift_rows = [];
$sq = $conn->query("SELECT id, description, start_time, end_time, is_graveyard FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
while ($sq && $s = $sq->fetch_assoc()) $shift_rows[] = $s;

$target_date_esc = $conn->real_escape_string($target_date);

// Every active employee + whichever shift was in effect ON the target date (not just "current")
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
         AND es.effective_from <= '$target_date_esc'
         AND (es.effective_to IS NULL OR es.effective_to >= '$target_date_esc')
    LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
    WHERE e.status = 1
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
    WHERE eds.work_date = '$target_date_esc' AND eds.status = 1
");
while ($dutyq && $d = $dutyq->fetch_assoc()) $duty_by_emp[$d['employee_id']] = $d;

// The lock freezes every roster write board-wide (mirrors admin_class's
// dutyRosterLockDeny — that check is private to the class, so it's re-read
// here directly for the UI gate; duty_roster_save() still enforces it).
$duty_locked = false;
$lkq = $conn->query("SELECT setting_value FROM pay_settings WHERE setting_key = 'duty_roster_locked'");
if ($lkq && ($lkr = $lkq->fetch_assoc())) $duty_locked = ((float) $lkr['setting_value']) >= 1;

// Approved leave covering the target date — same source (leave_requests,
// status = 1 approved) duty_roster_publish's leave-conflict check reads
// (dutyLeaveMap, admin_class.php), scoped here to just the one board date.
$leave_by_emp = [];
if ($employees) {
    $empIdList = implode(',', array_map(fn($e) => (int) $e['id'], $employees));
    $lvq = $conn->query("
        SELECT lr.employee_id, lr.dates, lr.is_half_day, lr.half_date, lr.half_period,
               COALESCE(lt.name, 'Leave') AS type_name
        FROM leave_requests lr
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
        WHERE lr.status = 1
          AND lr.employee_id IN ($empIdList)
          AND lr.date_from <= '$target_date_esc'
          AND lr.date_to   >= '$target_date_esc'
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
}

// Weekday number for the target date, 0=Sunday..6=Saturday — same convention
// employee_schedules.rest_days is written in (dtr_compute_day, db_connect.php:1556-1561).
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
    $r['leave'] = $leave_by_emp[$r['id']] ?? null;
    // roster_can_edit_area() already covers the unscoped-role case (returns
    // true), so this one call is the whole permission check for this row.
    $r['can_adjust'] = !$duty_locked && roster_can_edit_area((int) ($r['area_id'] ?? 0));
}
unset($r);

// Actual attendance rows for that date, keyed by employee_id
$dtr_by_emp = [];
$dq = $conn->query("SELECT employee_id, work_hours, late, overtime, logs, is_complete
                     FROM DTR_details WHERE date_time = '" . $conn->real_escape_string($target_date) . "'");
while ($dq && $d = $dq->fetch_assoc()) $dtr_by_emp[$d['employee_id']] = $d;

// Work out an attendance status for one employee on the target date.
function board_attendance_status($dtrRow, $shiftStart, $isFuture, $isToday, $isRestDay = false, $leave = null)
{
    $leaveStatus = function () use ($leave) {
        return [
            'label' => 'On Leave', 'class' => 'leave', 'icon' => 'ri-suitcase-line', 'in' => null, 'out' => null,
            'leave_name' => $leave['name'], 'leave_half' => $leave['half'], 'leave_part' => $leave['part'],
        ];
    };
    if ($dtrRow) {
        $logs = json_decode($dtrRow['logs'] ?? '[]', true) ?: [];
        usort($logs, fn($a, $b) => strcmp($a['dateTime'] ?? '', $b['dateTime'] ?? ''));
        $in  = $logs[0]['dateTime'] ?? null;
        $out = count($logs) > 1 ? end($logs)['dateTime'] : null;
        $ot  = (float)($dtrRow['overtime'] ?? 0);
        if ((float)($dtrRow['late'] ?? 0) > 0) {
            return ['label' => 'Late', 'class' => 'warning', 'icon' => 'ri-alarm-warning-line', 'in' => $in, 'out' => $out, 'late' => (float)$dtrRow['late'], 'ot' => $ot];
        }
        if (!empty($logs)) {
            return ['label' => 'Present', 'class' => 'success', 'icon' => 'ri-checkbox-circle-line', 'in' => $in, 'out' => $out, 'ot' => $ot];
        }
        if ($leave) return $leaveStatus();
        if ($isRestDay) {
            return ['label' => 'Day Off', 'class' => 'secondary', 'icon' => 'ri-moon-line', 'in' => null, 'out' => null];
        }
        return ['label' => 'No Record', 'class' => 'secondary', 'icon' => 'ri-question-line', 'in' => null, 'out' => null];
    }
    // Approved leave and a published roster rest day are both facts, not
    // guesses, when nobody punched — leave is the more specific of the two
    // (it says WHY, a rest day only says "not expected"), so it wins.
    if ($leave) return $leaveStatus();
    if ($isRestDay) {
        return ['label' => 'Day Off', 'class' => 'secondary', 'icon' => 'ri-moon-line', 'in' => null, 'out' => null];
    }
    if ($isFuture) {
        return ['label' => 'Scheduled', 'class' => 'secondary', 'icon' => 'ri-calendar-line', 'in' => null, 'out' => null];
    }
    if ($isToday && $shiftStart && strtotime(date('Y-m-d') . ' ' . $shiftStart) > time()) {
        return ['label' => 'Not Yet Due', 'class' => 'info', 'icon' => 'ri-time-line', 'in' => null, 'out' => null];
    }
    return ['label' => 'Absent', 'class' => 'danger', 'icon' => 'ri-close-circle-line', 'in' => null, 'out' => null];
}

// Attach a computed 'att' status to each employee row
foreach ($employees as &$r) {
    $r['att'] = board_attendance_status($dtr_by_emp[$r['id']] ?? null, $r['shift_start'] ?? null, $is_future, $is_today, !empty($r['is_rest_day']), $r['leave'] ?? null);
}
unset($r);

// Day summary strip
$summary = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Not Yet Due' => 0, 'Scheduled' => 0, 'Day Off' => 0, 'On Leave' => 0, 'No Record' => 0];
foreach ($employees as $r) $summary[$r['att']['label']] = ($summary[$r['att']['label']] ?? 0) + 1;

// Attendance rate: who has clocked in vs who was expected to (today excludes
// shifts not yet due, and days off / leave are never "expected" to clock in)
$attended = $summary['Present'] + $summary['Late'];
$expected = count($employees) - $summary['Scheduled'] - $summary['Day Off'] - $summary['On Leave'] - ($is_today ? $summary['Not Yet Due'] : 0);
$att_rate = $expected > 0 ? (int)round($attended / $expected * 100) : 0;

// Group by shift (ordered by start time, unassigned last)
$by_shift = [];
foreach ($shift_rows as $s) $by_shift[$s['id']] = ['label' => $s['description'], 'time' => date('h:i A', strtotime($s['start_time'])) . ' – ' . date('h:i A', strtotime($s['end_time'])), 'employees' => []];
$by_shift['__unassigned__'] = ['label' => 'Unassigned', 'time' => '', 'employees' => []];
foreach ($employees as $r) {
    $key = ($r['shift_id'] && isset($by_shift[$r['shift_id']])) ? $r['shift_id'] : '__unassigned__';
    $by_shift[$key]['employees'][] = $r;
}

// Group by department (alphabetical, unassigned last)
$by_dept = [];
foreach ($employees as $r) {
    $key = $r['dept_name'] ?: '__unassigned__';
    if (!isset($by_dept[$key])) $by_dept[$key] = ['label' => $r['dept_name'] ?: 'Unassigned', 'employees' => []];
    $by_dept[$key]['employees'][] = $r;
}
ksort($by_dept);
if (isset($by_dept['__unassigned__'])) { $u = $by_dept['__unassigned__']; unset($by_dept['__unassigned__']); $by_dept['__unassigned__'] = $u; }

function board_initials($r)
{
    return strtoupper(substr($r['firstname'], 0, 1)) . strtoupper(substr($r['lastname'], 0, 1));
}
function board_name($r)
{
    return htmlspecialchars($r['lastname'] . ', ' . $r['firstname'] . ($r['middlename'] ? ' ' . substr($r['middlename'], 0, 1) . '.' : ''));
}
?>
<style>
    .db-toolbar { background:linear-gradient(135deg,#f2f0f6 0%,#ebe7f2 100%); border:1px solid #dad4e5; border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .db-nav-btn { width:34px; height:34px; border-radius:50%; border:1px solid #dad4e5; background:#fff; color:#673bb6; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .15s,color .15s,box-shadow .15s; }
    .db-nav-btn:hover { background:#673bb6; color:#fff; box-shadow:0 2px 6px rgba(103,59,182,.35); }
    .db-date-pill { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #dad4e5; border-radius:20px; padding:6px 16px; font-weight:700; color:#57339d; cursor:pointer; font-size:14px; min-width:190px; justify-content:center; transition:border-color .15s, box-shadow .15s; }
    .db-date-pill:hover { border-color:#673bb6; box-shadow:0 2px 6px rgba(103,59,182,.15); }
    .db-today-badge { font-size:10px; background:#673bb6; color:#fff; border-radius:10px; padding:1px 8px; margin-left:6px; vertical-align:middle; }
    /* daterangepicker theme now lives globally in assets2/css/custom-select.css */
    .db-group-toggle .btn { padding:4px 10px; }
    .db-group-toggle .btn.active { background:#673bb6; border-color:#673bb6; color:#fff; }

    .db-search { position:relative; }
    .db-search i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#a397ba; font-size:14px; pointer-events:none; }
    .db-search input { border:1px solid #dad4e5; border-radius:20px; padding:6px 12px 6px 32px; font-size:12.5px; width:220px; outline:none; background:#fff; transition:border-color .15s, box-shadow .15s; }
    .db-search input:focus { border-color:#673bb6; box-shadow:0 0 0 3px rgba(103,59,182,.12); }

    .db-rate { display:flex; align-items:center; gap:10px; margin:12px 2px 0; }
    .db-rate-track { flex:1; height:7px; background:#eceff3; border-radius:4px; overflow:hidden; }
    .db-rate-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#6f47b5,#6339af); transition:width .4s ease; }
    .db-rate-lbl { font-size:11px; font-weight:700; color:#57339d; white-space:nowrap; }

    .db-summary { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0 16px; }
    .db-sum-card { flex:1; min-width:120px; border-radius:10px; padding:10px 12px; text-align:center; border:1px solid; cursor:pointer; user-select:none; position:relative; transition:transform .12s, box-shadow .12s; }
    .db-sum-card:hover { transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,.08); }
    .db-sum-card.filter-on { box-shadow:0 0 0 2px currentColor inset, 0 4px 10px rgba(0,0,0,.08); }
    .db-sum-ico { font-size:15px; opacity:.75; }
    .db-sum-val { font-size:21px; font-weight:800; line-height:1.1; }
    .db-sum-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.4px; font-weight:700; margin-top:2px; }
    .db-sum-card.success { background:#f0fbf5; border-color:#b7ebc6; color:#1a7f37; }
    .db-sum-card.warning { background:#fffbe6; border-color:#ffe58f; color:#ad6800; }
    .db-sum-card.danger  { background:#fff1f0; border-color:#ffccc7; color:#cf1322; }
    .db-sum-card.info    { background:#e6f7ff; border-color:#91d5ff; color:#096dd9; }
    .db-sum-card.secondary { background:#f5f5f5; border-color:#e0e0e0; color:#666; }
    .db-sum-card.leave { background:#f3e8ff; border-color:#dcc6fa; color:#7c3aed; }

    .db-group { margin-bottom:16px; }
    .db-group-head { position:relative; display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#f2f0f6,#edeaf3); border:1px solid #dad4e5; border-radius:8px; padding:7px 12px 9px; cursor:pointer; user-select:none; overflow:hidden; transition:border-color .15s, box-shadow .15s, background .15s; }
    .db-group-head:hover { border-color:#c3b3e2; box-shadow:0 2px 8px rgba(103,59,182,.12); }
    .db-group-head:focus-visible { outline:2px solid #673bb6; outline-offset:2px; }
    .db-group-chevron { color:#8b7bb0; font-size:16px; line-height:1; flex-shrink:0; transition:transform .25s ease, color .15s; }
    .db-group-head:hover .db-group-chevron { color:#673bb6; }
    .db-group.collapsed .db-group-chevron { transform:rotate(-90deg); }
    .db-group-title { font-size:13px; font-weight:700; color:#57339d; }
    .db-group-time { font-size:11px; color:#746491; white-space:nowrap; }
    .db-group-in { font-size:11px; font-weight:700; color:#1a7f37; white-space:nowrap; }
    .db-group-count { flex-shrink:0; }
    .db-group-stats { display:flex; align-items:center; gap:5px; margin-left:auto; flex-wrap:wrap; justify-content:flex-end; }
    .db-stat-chip { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; line-height:1; padding:3px 8px; border-radius:10px; background:#fff; border:1px solid #e3ddee; color:#6b6580; white-space:nowrap; }
    .db-stat-chip .db-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .db-stat-chip.success .db-dot { background:#2eb872; } .db-stat-chip.success { color:#1a7f37; border-color:#c9ecd8; }
    .db-stat-chip.warning .db-dot { background:#f0a800; } .db-stat-chip.warning { color:#ad6800; border-color:#f6e4b5; }
    .db-stat-chip.danger  .db-dot { background:#e5484d; } .db-stat-chip.danger  { color:#cf1322; border-color:#f7cfcf; }
    .db-stat-chip.info    .db-dot { background:#3a9bdc; } .db-stat-chip.info    { color:#096dd9; border-color:#c9e4f7; }
    .db-stat-chip.secondary .db-dot { background:#b9bec9; }
    .db-stat-chip.leave .db-dot { background:#7c3aed; } .db-stat-chip.leave { color:#7c3aed; border-color:#dcc6fa; }
    .db-group-bar { position:absolute; left:0; right:0; bottom:0; height:3px; background:rgba(103,59,182,.10); }
    .db-group-bar-fill { height:100%; background:linear-gradient(90deg,#6f47b5,#2eb872); transition:width .4s ease; }

    /* Collapse animation — 1fr → 0fr keeps the natural height without measuring it in JS */
    .db-group-body { display:grid; grid-template-rows:1fr; margin-top:8px; transition:grid-template-rows .28s ease, opacity .2s ease, margin-top .28s ease; }
    .db-group-body > .db-group-body-inner { overflow:hidden; min-height:0; }
    .db-group.collapsed .db-group-body { grid-template-rows:0fr; opacity:0; margin-top:0; }
    .db-group.no-anim .db-group-body { transition:none; }

    .db-collapse-toggle .btn { padding:4px 10px; }

    .db-card { border:1px solid #e2e5ee; border-left:3px solid #e2e5ee; border-radius:8px; padding:10px 12px; background:#fff; height:100%; transition:box-shadow .15s, transform .15s; }
    .db-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.10); transform:translateY(-1px); }
    .db-card.st-success { border-left-color:#2eb872; }
    .db-card.st-warning { border-left-color:#f0a800; }
    .db-card.st-danger  { border-left-color:#e5484d; }
    .db-card.st-info    { border-left-color:#3a9bdc; }
    .db-card.st-secondary { border-left-color:#c5c9d3; }
    .db-card.st-leave { border-left-color:#7c3aed; }
    .db-card-top { display:flex; align-items:center; gap:8px; }
    .db-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#6f47b5,#5d36a6); color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .db-card.st-danger .db-avatar { background:linear-gradient(135deg,#adb5bd,#868e96); }
    .db-name { font-size:12.5px; font-weight:700; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .db-name-link { color:#1a1a1a; text-decoration:none; }
    .db-name-link:hover { color:#5d36a6; text-decoration:underline; }
    a.db-avatar:hover { box-shadow:0 0 0 3px rgba(103,59,182,.25); text-decoration:none; color:#fff; }
    .db-sub { font-size:10.5px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .db-tag { font-size:9.5px; color:#1976d2; }
    .db-status-row { margin-top:8px; padding-top:7px; border-top:1px dashed #eef0f8; display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:wrap; }
    .db-status-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; display:inline-flex; align-items:center; gap:3px; }
    .db-status-badge.success { background:#e6f9ee; color:#1a7f37; }
    .db-status-badge.warning { background:#fff7e0; color:#ad6800; }
    .db-status-badge.danger  { background:#ffefee; color:#cf1322; }
    .db-status-badge.info    { background:#e6f4ff; color:#096dd9; }
    .db-status-badge.secondary { background:#f0f0f0; color:#666; }
    .db-status-badge.leave { background:#f3e8ff; color:#7c3aed; }
    .db-ot-chip { font-size:9.5px; font-weight:700; background:#f3e8ff; color:#7c3aed; padding:1px 6px; border-radius:8px; }
    .db-leave-chip { font-size:9.5px; font-weight:700; background:#f0f0f0; color:#666; padding:1px 6px; border-radius:8px; white-space:nowrap; }
    .db-in-out { font-size:10px; color:#666; white-space:nowrap; }
    .db-no-match { display:none; text-align:center; color:#999; padding:28px 0; font-size:13px; }

    .db-adjust-btn { flex-shrink:0; width:24px; height:24px; border-radius:6px; border:1px solid #e2ddef; background:#faf9fc; color:#8b7bb0; display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:background .15s,color .15s,border-color .15s; }
    .db-adjust-btn:hover { background:#673bb6; color:#fff; border-color:#673bb6; }
    .db-lock-note { font-size:11px; color:#ad6800; background:#fffbe6; border:1px solid #ffe58f; border-radius:20px; padding:4px 12px; display:flex; align-items:center; gap:5px; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-dashboard-3-line me-2 text-success"></i>Daily Attendance Board</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Daily Board</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex gap-2 flex-wrap">
                        <h4 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-check-line me-2 text-success"></i>Shift Lineup</h4>
                        <div class="db-search">
                            <i class="ri-search-line"></i>
                            <input type="text" id="db-search-input" placeholder="Search name, ID, dept…" autocomplete="off">
                        </div>
                        <div class="btn-group db-group-toggle" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-group-shift"><i class="ri-time-line"></i> Shift</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-group-dept"><i class="ri-building-3-line"></i> Department</button>
                        </div>
                        <div class="btn-group db-collapse-toggle" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-expand-all" title="Expand all groups"><i class="ri-arrow-down-s-line"></i> Expand</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-collapse-all" title="Collapse all groups"><i class="ri-arrow-up-s-line"></i> Collapse</button>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- Date navigation -->
                        <div class="db-toolbar mb-2">
                            <a href="index.php?page=daily-board&date=<?= $prev_date ?>" class="db-nav-btn" title="Previous day"><i class="ri-arrow-left-s-line"></i></a>
                            <div class="db-date-pill" style="min-width: 250px;" id="db-date-pill" title="Click to pick a date">
                                <i class="ri-calendar-2-line"></i>
                                <span id="db-date-label"><?= date('l, F j, Y', strtotime($target_date)) ?></span>
                                <?php if ($is_today): ?><span class="db-today-badge">TODAY</span><?php endif; ?>
                            </div>
                            <a href="index.php?page=daily-board&date=<?= $next_date ?>" class="db-nav-btn" title="Next day"><i class="ri-arrow-right-s-line"></i></a>
                            <input type="hidden" id="db-date-input" value="<?= htmlspecialchars($target_date) ?>">
                            <?php if (!$is_today): ?>
                            <a href="index.php?page=daily-board" class="btn btn-sm btn-outline-secondary"><i class="ri-calendar-check-line me-1"></i>Today</a>
                            <?php endif; ?>
                            <?php if ($duty_locked): ?>
                            <span class="db-lock-note"><i class="ri-lock-2-line"></i>Duty roster is locked by the administrator</span>
                            <?php endif; ?>
                            <div class="ms-auto d-flex gap-3" style="font-size:11px;color:#666;">
                                <span><i class="ri-team-line me-1"></i><?= count($employees) ?> active employees</span>
                            </div>
                        </div>

                        <?php if (!$is_future): ?>
                        <!-- Attendance rate -->
                        <div class="db-rate">
                            <div class="db-rate-track"><div class="db-rate-fill" style="width:<?= $att_rate ?>%;"></div></div>
                            <span class="db-rate-lbl"><?= $attended ?> of <?= $expected ?> clocked in · <?= $att_rate ?>%</span>
                        </div>
                        <?php endif; ?>

                        <!-- Day summary (click a card to filter the board by that status) -->
                        <div class="db-summary">
                            <?php if ($is_future): ?>
                                <div class="db-sum-card secondary" data-filter="Scheduled"><div class="db-sum-ico"><i class="ri-calendar-line"></i></div><div class="db-sum-val"><?= $summary['Scheduled'] ?></div><div class="db-sum-lbl">Scheduled</div></div>
                                <?php if ($summary['Day Off'] > 0): ?>
                                <div class="db-sum-card secondary" data-filter="Day Off"><div class="db-sum-ico"><i class="ri-moon-line"></i></div><div class="db-sum-val"><?= $summary['Day Off'] ?></div><div class="db-sum-lbl">Day Off</div></div>
                                <?php endif; ?>
                                <?php if ($summary['On Leave'] > 0): ?>
                                <div class="db-sum-card leave" data-filter="On Leave"><div class="db-sum-ico"><i class="ri-suitcase-line"></i></div><div class="db-sum-val"><?= $summary['On Leave'] ?></div><div class="db-sum-lbl">On Leave</div></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="db-sum-card success" data-filter="Present"><div class="db-sum-ico"><i class="ri-checkbox-circle-line"></i></div><div class="db-sum-val"><?= $summary['Present'] ?></div><div class="db-sum-lbl">Present</div></div>
                                <div class="db-sum-card warning" data-filter="Late"><div class="db-sum-ico"><i class="ri-alarm-warning-line"></i></div><div class="db-sum-val"><?= $summary['Late'] ?></div><div class="db-sum-lbl">Late</div></div>
                                <div class="db-sum-card danger" data-filter="Absent"><div class="db-sum-ico"><i class="ri-close-circle-line"></i></div><div class="db-sum-val"><?= $summary['Absent'] ?></div><div class="db-sum-lbl">Absent</div></div>
                                <?php if ($is_today): ?>
                                <div class="db-sum-card info" data-filter="Not Yet Due"><div class="db-sum-ico"><i class="ri-time-line"></i></div><div class="db-sum-val"><?= $summary['Not Yet Due'] ?></div><div class="db-sum-lbl">Not Yet Due</div></div>
                                <?php endif; ?>
                                <?php if ($summary['Day Off'] > 0): ?>
                                <div class="db-sum-card secondary" data-filter="Day Off"><div class="db-sum-ico"><i class="ri-moon-line"></i></div><div class="db-sum-val"><?= $summary['Day Off'] ?></div><div class="db-sum-lbl">Day Off</div></div>
                                <?php endif; ?>
                                <?php if ($summary['On Leave'] > 0): ?>
                                <div class="db-sum-card leave" data-filter="On Leave"><div class="db-sum-ico"><i class="ri-suitcase-line"></i></div><div class="db-sum-val"><?= $summary['On Leave'] ?></div><div class="db-sum-lbl">On Leave</div></div>
                                <?php endif; ?>
                                <?php if ($summary['No Record'] > 0): ?>
                                <div class="db-sum-card secondary" data-filter="No Record"><div class="db-sum-ico"><i class="ri-question-line"></i></div><div class="db-sum-val"><?= $summary['No Record'] ?></div><div class="db-sum-lbl">No Record</div></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php
                        // Renders one grouped board (used for both Shift and Department groupings)
                        function board_render_group($gkey, $group, $isShiftGroup)
                        {
                            if (empty($group['employees'])) return;
                            $total = count($group['employees']);
                            $in_count = 0;
                            $counts = [];
                            foreach ($group['employees'] as $e) {
                                $lbl = $e['att']['label'];
                                $counts[$lbl] = ($counts[$lbl] ?? 0) + 1;
                                if (in_array($lbl, ['Present', 'Late'])) $in_count++;
                            }
                            // Status chips stay visible when the group is collapsed, so the head alone tells the story
                            $chip_order = ['Present' => 'success', 'Late' => 'warning', 'Absent' => 'danger', 'Not Yet Due' => 'info', 'No Record' => 'secondary', 'Scheduled' => 'secondary', 'Day Off' => 'secondary', 'On Leave' => 'leave'];
                            $bar_pct = $total > 0 ? round($in_count / $total * 100) : 0;
                            ?>
                            <div class="db-group" data-group-key="<?= htmlspecialchars($gkey) ?>">
                                <div class="db-group-head" role="button" tabindex="0" aria-expanded="true" title="Click to collapse / expand">
                                    <i class="ri-arrow-down-s-line db-group-chevron"></i>
                                    <i class="ri-<?= $isShiftGroup ? 'time' : 'building-3' ?>-line" style="color:#673bb6;"></i>
                                    <span class="db-group-title"><?= htmlspecialchars($group['label']) ?></span>
                                    <?php if (!empty($group['time'])): ?><span class="db-group-time"><?= htmlspecialchars($group['time']) ?></span><?php endif; ?>
                                    <span class="db-group-stats">
                                        <?php foreach ($chip_order as $lbl => $cls): if (empty($counts[$lbl])) continue; ?>
                                        <span class="db-stat-chip <?= $cls ?>" data-chip-status="<?= htmlspecialchars($lbl) ?>" title="<?= htmlspecialchars($lbl) ?>"><span class="db-dot"></span><span class="db-chip-val"><?= $counts[$lbl] ?></span> <?= htmlspecialchars($lbl) ?></span>
                                        <?php endforeach; ?>
                                        <span class="db-group-in"><i class="ri-user-follow-line"></i> <?= $in_count ?> in</span>
                                        <span class="badge bg-secondary db-group-count"><?= $total ?></span>
                                    </span>
                                    <span class="db-group-bar"><span class="db-group-bar-fill" style="width:<?= $bar_pct ?>%;"></span></span>
                                </div>
                                <div class="db-group-body"><div class="db-group-body-inner">
                                <div class="row g-2">
                                    <?php foreach ($group['employees'] as $r): $att = $r['att'];
                                        $search = strtolower($r['lastname'] . ' ' . $r['firstname'] . ' ' . $r['employee_no'] . ' ' . ($r['dept_name'] ?? '') . ' ' . ($r['shift_desc'] ?? '')); ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-2 db-card-col" data-status="<?= htmlspecialchars($att['label']) ?>" data-search="<?= htmlspecialchars($search) ?>">
                                        <div class="db-card st-<?= $att['class'] ?>">
                                            <div class="db-card-top">
                                                <!-- Quick-view drawer first; the full employee-details page
                                                     is one more click away inside the drawer. -->
                                                <a href="javascript:void(0);" data-emp-quickview="<?= (int)$r['id'] ?>" class="db-avatar" title="Employee quick view"><?= board_initials($r) ?></a>
                                                <div style="min-width:0;flex:1;">
                                                    <div class="db-name" title="<?= board_name($r) ?>"><a href="javascript:void(0);" data-emp-quickview="<?= (int)$r['id'] ?>" class="db-name-link" title="Employee quick view"><?= board_name($r) ?></a></div>
                                                    <div class="db-sub">
                                                        <?php if ($isShiftGroup): ?>
                                                            <?= !empty($r['dept_name']) ? htmlspecialchars($r['dept_name']) : '<span class="text-muted">No dept</span>' ?>
                                                        <?php else: ?>
                                                            <?= $r['shift_desc'] ? htmlspecialchars($r['shift_desc']) : '<span class="text-muted">No shift</span>' ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="db-tag"><?= htmlspecialchars($r['employee_no']) ?></div>
                                                </div>
                                                <?php if (!empty($r['can_adjust'])): ?>
                                                <button type="button" class="db-adjust-btn" data-adjust-emp="<?= (int)$r['id'] ?>" data-adjust-shift="<?= (int)($r['shift_id'] ?? 0) ?>" data-adjust-rest="<?= (int)$r['is_rest_day'] ?>" data-adjust-name="<?= board_name($r) ?>" title="Adjust duty for this date"><i class="ri-edit-2-line"></i></button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="db-status-row">
                                                <span class="db-status-badge <?= $att['class'] ?>"><i class="<?= $att['icon'] ?>"></i><?= htmlspecialchars($att['label']) ?><?php if (!empty($att['late'])): ?> <?= (int)$att['late'] ?>m<?php endif; ?></span>
                                                <?php if (!empty($att['ot'])): ?><span class="db-ot-chip">OT <?= rtrim(rtrim(number_format((float)$att['ot'], 1), '0'), '.') ?>h</span><?php endif; ?>
                                                <?php if (!empty($att['leave_name'])): ?><span class="db-leave-chip"><?= htmlspecialchars($att['leave_name']) ?><?= !empty($att['leave_half']) ? ' (' . htmlspecialchars($att['leave_part']) . ')' : '' ?></span><?php endif; ?>
                                                <?php if ($att['in']): ?>
                                                <span class="db-in-out"><?= date('h:i A', strtotime($att['in'])) ?><?= $att['out'] ? ' – ' . date('h:i A', strtotime($att['out'])) : '' ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                </div></div>
                            </div>
                            <?php
                        }
                        ?>

                        <!-- Grouped by Shift (default) -->
                        <div id="db-board-shift">
                            <?php foreach ($by_shift as $gkey => $group) board_render_group($gkey, $group, true); ?>
                            <?php if (empty($employees)): ?><div class="text-center text-muted py-4">No active employees found.</div><?php endif; ?>
                        </div>

                        <!-- Grouped by Department (hidden by default) -->
                        <div id="db-board-dept" class="d-none">
                            <?php foreach ($by_dept as $gkey => $group) board_render_group($gkey, $group, false); ?>
                            <?php if (empty($employees)): ?><div class="text-center text-muted py-4">No active employees found.</div><?php endif; ?>
                        </div>

                        <div class="db-no-match" id="db-no-match"><i class="ri-search-eye-line me-1"></i>No employees match the current search / filter.</div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Adjust-duty modal: one employee, one date. Saves through the same
     duty_roster_save/duty_roster_recompute endpoints the full grid uses. -->
<div class="modal fade" id="db-adjust-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#673bb6;">
                <h6 class="modal-title text-white"><i class="ri-edit-2-line me-2"></i>Adjust Duty</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2" style="font-size:12.5px;color:#666;">
                    <span id="db-adjust-name" style="font-weight:700;color:#333;"></span>
                    &middot; <?= htmlspecialchars(date('l, F j, Y', strtotime($target_date))) ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;">Shift</label>
                    <select class="form-select form-select-sm" id="db-adjust-shift">
                        <option value="">— No shift —</option>
                        <?php foreach ($shift_rows as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['description']) ?> (<?= date('h:i A', strtotime($s['start_time'])) ?>–<?= date('h:i A', strtotime($s['end_time'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="db-adjust-rest">
                    <label class="form-check-label" for="db-adjust-rest" style="font-size:12.5px;">Day off (rest day)</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="db-adjust-save"><i class="ri-save-line me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
<script>
    window.DB_ADJUST = {
        date: <?= json_encode($target_date) ?>,
        period: <?= json_encode($duty_period) ?>
    };
</script>
<!-- Employee quick-view drawer is included globally by index.php -->

