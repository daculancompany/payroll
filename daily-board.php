<?php
// Daily Attendance Board — pick a date, see who's on which shift and whether they've
// actually clocked in yet, grouped by Shift (default) or Department.

$today = date('Y-m-d');
$target_date = isset($_GET['date']) ? trim($_GET['date']) : $today;
if (!strtotime($target_date)) $target_date = $today;
$is_future = $target_date > $today;
$is_today  = $target_date === $today;

$prev_date = date('Y-m-d', strtotime($target_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($target_date . ' +1 day'));

// Shift definitions, ordered by start time (drives both grouping order and the header legend)
$shift_rows = [];
$sq = $conn->query("SELECT id, description, start_time, end_time, is_graveyard FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
while ($sq && $s = $sq->fetch_assoc()) $shift_rows[] = $s;

// Every active employee + whichever shift was in effect ON the target date (not just "current")
$emp_q = $conn->query("
    SELECT e.id, e.firstname, e.lastname, e.middlename, e.employee_no,
           p.name AS pname, d.name AS dept_name, c.clasification,
           es.schedule_id AS shift_id, ws.description AS shift_desc,
           ws.start_time AS shift_start, ws.end_time AS shift_end, ws.is_graveyard
    FROM employee e
    INNER JOIN position p ON e.position_id = p.id
    INNER JOIN clasification c ON e.clasification_id = c.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN employee_schedules es ON es.employee_id = e.id
         AND es.effective_from <= '" . $conn->real_escape_string($target_date) . "'
         AND (es.effective_to IS NULL OR es.effective_to >= '" . $conn->real_escape_string($target_date) . "')
    LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
    WHERE e.status = 1
    ORDER BY e.lastname ASC, e.firstname ASC
");
$employees = $emp_q ? $emp_q->fetch_all(MYSQLI_ASSOC) : [];

// Actual attendance rows for that date, keyed by employee_id
$dtr_by_emp = [];
$dq = $conn->query("SELECT employee_id, work_hours, late, overtime, logs, is_complete
                     FROM DTR_details WHERE date_time = '" . $conn->real_escape_string($target_date) . "'");
while ($dq && $d = $dq->fetch_assoc()) $dtr_by_emp[$d['employee_id']] = $d;

// Work out an attendance status for one employee on the target date.
function board_attendance_status($dtrRow, $shiftStart, $isFuture, $isToday)
{
    if ($isFuture) {
        return ['label' => 'Scheduled', 'class' => 'secondary', 'icon' => 'ri-calendar-line', 'in' => null, 'out' => null];
    }
    if ($dtrRow) {
        $logs = json_decode($dtrRow['logs'] ?? '[]', true) ?: [];
        usort($logs, fn($a, $b) => strcmp($a['dateTime'] ?? '', $b['dateTime'] ?? ''));
        $in  = $logs[0]['dateTime'] ?? null;
        $out = count($logs) > 1 ? end($logs)['dateTime'] : null;
        if ((float)($dtrRow['late'] ?? 0) > 0) {
            return ['label' => 'Late', 'class' => 'warning', 'icon' => 'ri-alarm-warning-line', 'in' => $in, 'out' => $out, 'late' => (float)$dtrRow['late']];
        }
        if (!empty($logs)) {
            return ['label' => 'Present', 'class' => 'success', 'icon' => 'ri-checkbox-circle-line', 'in' => $in, 'out' => $out];
        }
        return ['label' => 'No Record', 'class' => 'secondary', 'icon' => 'ri-question-line', 'in' => null, 'out' => null];
    }
    if ($isToday && $shiftStart && strtotime(date('Y-m-d') . ' ' . $shiftStart) > time()) {
        return ['label' => 'Not Yet Due', 'class' => 'info', 'icon' => 'ri-time-line', 'in' => null, 'out' => null];
    }
    return ['label' => 'Absent', 'class' => 'danger', 'icon' => 'ri-close-circle-line', 'in' => null, 'out' => null];
}

// Attach a computed 'att' status to each employee row
foreach ($employees as &$r) {
    $r['att'] = board_attendance_status($dtr_by_emp[$r['id']] ?? null, $r['shift_start'] ?? null, $is_future, $is_today);
}
unset($r);

// Day summary strip
$summary = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'Not Yet Due' => 0, 'Scheduled' => 0, 'No Record' => 0];
foreach ($employees as $r) $summary[$r['att']['label']] = ($summary[$r['att']['label']] ?? 0) + 1;

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
    .db-toolbar { background:#eef6f5; border:1px solid #cfe5e2; border-radius:8px; padding:10px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .db-nav-btn { width:34px; height:34px; border-radius:50%; border:1px solid #cfe5e2; background:#fff; color:#009688; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .db-nav-btn:hover { background:#009688; color:#fff; }
    .db-date-pill { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #cfe5e2; border-radius:20px; padding:6px 16px; font-weight:700; color:#00695c; cursor:pointer; font-size:14px; min-width:190px; justify-content:center; }
    .db-date-pill:hover { border-color:#009688; }
    .db-today-badge { font-size:10px; background:#009688; color:#fff; border-radius:10px; padding:1px 8px; margin-left:6px; vertical-align:middle; }
    /* daterangepicker theme override, same teal used on attendance.php */
    .daterangepicker td.active, .daterangepicker td.active:hover { background-color:#009688 !important; }
    .daterangepicker td.start-date, .daterangepicker td.end-date { background-color:#00796b !important; }
    .daterangepicker .drp-buttons .btn.applyBtn { background-color:#009688 !important; border-color:#00796b !important; }
    .db-group-toggle .btn { padding:4px 10px; }
    .db-group-toggle .btn.active { background:#009688; border-color:#009688; color:#fff; }

    .db-summary { display:flex; gap:10px; flex-wrap:wrap; margin:14px 0; }
    .db-sum-card { flex:1; min-width:110px; border-radius:8px; padding:10px 12px; text-align:center; border:1px solid; }
    .db-sum-val { font-size:20px; font-weight:800; line-height:1.1; }
    .db-sum-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.4px; font-weight:700; margin-top:2px; }
    .db-sum-card.success { background:#f0fbf5; border-color:#b7ebc6; color:#1a7f37; }
    .db-sum-card.warning { background:#fffbe6; border-color:#ffe58f; color:#ad6800; }
    .db-sum-card.danger  { background:#fff1f0; border-color:#ffccc7; color:#cf1322; }
    .db-sum-card.info    { background:#e6f7ff; border-color:#91d5ff; color:#096dd9; }
    .db-sum-card.secondary { background:#f5f5f5; border-color:#e0e0e0; color:#666; }

    .db-group { margin-bottom:16px; }
    .db-group-head { display:flex; align-items:center; gap:8px; background:#eef6f5; border:1px solid #cfe5e2; border-radius:6px; padding:7px 12px; margin-bottom:8px; }
    .db-group-title { font-size:13px; font-weight:700; color:#00695c; }
    .db-group-time { font-size:11px; color:#5c8b86; }
    .db-group-count { margin-left:auto; }

    .db-card { border:1px solid #e2e5ee; border-radius:8px; padding:10px 12px; background:#fff; height:100%; transition:box-shadow .15s; }
    .db-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.08); }
    .db-card-top { display:flex; align-items:center; gap:8px; }
    .db-avatar { width:32px; height:32px; border-radius:50%; background:#009688; color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .db-name { font-size:12.5px; font-weight:700; color:#1a1a1a; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .db-sub { font-size:10.5px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .db-tag { font-size:9.5px; color:#1976d2; }
    .db-status-row { margin-top:8px; padding-top:7px; border-top:1px dashed #eef0f8; display:flex; align-items:center; justify-content:space-between; gap:6px; }
    .db-status-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; display:inline-flex; align-items:center; gap:3px; }
    .db-status-badge.success { background:#e6f9ee; color:#1a7f37; }
    .db-status-badge.warning { background:#fff7e0; color:#ad6800; }
    .db-status-badge.danger  { background:#ffefee; color:#cf1322; }
    .db-status-badge.info    { background:#e6f4ff; color:#096dd9; }
    .db-status-badge.secondary { background:#f0f0f0; color:#666; }
    .db-in-out { font-size:10px; color:#666; white-space:nowrap; }
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
                    <div class="card-header align-items-center d-flex gap-2">
                        <h4 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-check-line me-2 text-success"></i>Shift Lineup</h4>
                        <div class="btn-group db-group-toggle" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-group-shift"><i class="ri-time-line"></i> Shift</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-group-dept"><i class="ri-building-3-line"></i> Department</button>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- Date navigation -->
                        <div class="db-toolbar mb-2">
                            <a href="index.php?page=daily-board&date=<?= $prev_date ?>" class="db-nav-btn" title="Previous day"><i class="ri-arrow-left-s-line"></i></a>
                            <div class="db-date-pill" style="min-width: 250px;" id="db-date-pill">
                                <i class="ri-calendar-2-line"></i>
                                <span id="db-date-label"><?= date('l, F j, Y', strtotime($target_date)) ?></span>
                                <?php if ($is_today): ?><span class="db-today-badge">TODAY</span><?php endif; ?>
                            </div>
                            <a href="index.php?page=daily-board&date=<?= $next_date ?>" class="db-nav-btn" title="Next day"><i class="ri-arrow-right-s-line"></i></a>
                            <input type="hidden" id="db-date-input" value="<?= htmlspecialchars($target_date) ?>">
                            <?php if (!$is_today): ?>
                            <a href="index.php?page=daily-board" class="btn btn-sm btn-outline-secondary"><i class="ri-calendar-check-line me-1"></i>Today</a>
                            <?php endif; ?>
                            <div class="ms-auto d-flex gap-3" style="font-size:11px;color:#666;">
                                <span><i class="ri-team-line me-1"></i><?= count($employees) ?> active employees</span>
                            </div>
                        </div>

                        <!-- Day summary -->
                        <div class="db-summary">
                            <?php if ($is_future): ?>
                                <div class="db-sum-card secondary"><div class="db-sum-val"><?= $summary['Scheduled'] ?></div><div class="db-sum-lbl">Scheduled</div></div>
                            <?php else: ?>
                                <div class="db-sum-card success"><div class="db-sum-val"><?= $summary['Present'] ?></div><div class="db-sum-lbl">Present</div></div>
                                <div class="db-sum-card warning"><div class="db-sum-val"><?= $summary['Late'] ?></div><div class="db-sum-lbl">Late</div></div>
                                <div class="db-sum-card danger"><div class="db-sum-val"><?= $summary['Absent'] ?></div><div class="db-sum-lbl">Absent</div></div>
                                <?php if ($is_today): ?>
                                <div class="db-sum-card info"><div class="db-sum-val"><?= $summary['Not Yet Due'] ?></div><div class="db-sum-lbl">Not Yet Due</div></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php
                        // Renders one grouped board (used for both Shift and Department groupings)
                        function board_render_group($gkey, $group, $showDept)
                        {
                            if (empty($group['employees'])) return;
                            ?>
                            <div class="db-group" data-group-key="<?= htmlspecialchars($gkey) ?>">
                                <div class="db-group-head">
                                    <i class="ri-<?= $showDept ? 'building-3' : 'time' ?>-line" style="color:#009688;"></i>
                                    <span class="db-group-title"><?= htmlspecialchars($group['label']) ?></span>
                                    <?php if (!empty($group['time'])): ?><span class="db-group-time"><?= htmlspecialchars($group['time']) ?></span><?php endif; ?>
                                    <span class="badge bg-secondary db-group-count ms-auto"><?= count($group['employees']) ?></span>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($group['employees'] as $r): $att = $r['att']; ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                                        <div class="db-card">
                                            <div class="db-card-top">
                                                <div class="db-avatar"><?= board_initials($r) ?></div>
                                                <div style="min-width:0;flex:1;">
                                                    <div class="db-name" title="<?= board_name($r) ?>"><?= board_name($r) ?></div>
                                                    <div class="db-sub">
                                                        <?php if ($showDept): ?>
                                                            <?= $r['shift_desc'] ? htmlspecialchars($r['shift_desc']) : '<span class="text-muted">No shift</span>' ?>
                                                        <?php else: ?>
                                                            <?= !empty($r['dept_name']) ? htmlspecialchars($r['dept_name']) : '<span class="text-muted">No dept</span>' ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="db-tag"><?= htmlspecialchars($r['employee_no']) ?></div>
                                                </div>
                                            </div>
                                            <div class="db-status-row">
                                                <span class="db-status-badge <?= $att['class'] ?>"><i class="<?= $att['icon'] ?>"></i><?= htmlspecialchars($att['label']) ?><?php if (!empty($att['late'])): ?> <?= (int)$att['late'] ?>m<?php endif; ?></span>
                                                <?php if ($att['in']): ?>
                                                <span class="db-in-out"><?= date('h:i A', strtotime($att['in'])) ?><?= $att['out'] ? ' – ' . date('h:i A', strtotime($att['out'])) : '' ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
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

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
