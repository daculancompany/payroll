<?php
// Central shift roster: view & reassign every employee's work schedule without opening each detail page.

// Shift definitions (used for the bulk selector, the edit modal, and the filter list)
$shift_rows = [];
$sq = $conn->query("SELECT id, description, start_time, end_time FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
while ($sq && $s = $sq->fetch_assoc()) {
    $s['label'] = $s['description'] . ' (' . date('h:i A', strtotime($s['start_time'])) . ' – ' . date('h:i A', strtotime($s['end_time'])) . ')';
    $shift_rows[] = $s;
}

// Every employee + the shift in effect TODAY. This used to join on
// `effective_to IS NULL`, which is the LAST period, not the current one — so a
// change scheduled in advance would display as the employee's current shift
// (and group them under it in the grid) from the moment it was saved, weeks
// before it takes effect. The upcoming period is selected separately so it can
// be shown as what it is.
$emp_q = $conn->query("
    SELECT e.id, e.firstname, e.lastname, e.middlename, e.employee_no, e.status,
           p.name AS pname, d.name AS dept_name, c.clasification,
           es.schedule_id AS cur_schedule_id, es.effective_from AS cur_from, es.rest_days AS cur_rest_days,
           -- What the edit modal pre-fills. Same fallback the server uses when a
           -- caller omits rest_days, so a gap in the periods can't make the modal
           -- open blank and quietly clear rest days that are still on file.
           COALESCE(es.rest_days,
                    (SELECT x.rest_days FROM employee_schedules x
                      WHERE x.employee_id = e.id AND x.effective_from <= CURDATE()
                      ORDER BY x.effective_from DESC LIMIT 1),
                    (SELECT x2.rest_days FROM employee_schedules x2
                      WHERE x2.employee_id = e.id
                      ORDER BY x2.effective_from ASC LIMIT 1),
                    '') AS pref_rest_days,
           ws.description AS cur_desc,
           nxt.effective_from AS nxt_from, nws.description AS nxt_desc
    FROM employee e
    INNER JOIN position p ON e.position_id = p.id
    INNER JOIN clasification c ON e.clasification_id = c.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN employee_schedules es ON es.employee_id = e.id
         AND es.effective_from <= CURDATE()
         AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
    LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
    LEFT JOIN employee_schedules nxt ON nxt.id = (
        SELECT id FROM employee_schedules
        WHERE employee_id = e.id AND effective_from > CURDATE()
        ORDER BY effective_from ASC LIMIT 1
    )
    LEFT JOIN work_schedules nws ON nws.id = nxt.schedule_id
    ORDER BY e.lastname ASC, e.firstname ASC
");
// Fetched once into an array so we can render both the table and the card grid from the same rows.
$employees = $emp_q ? $emp_q->fetch_all(MYSQLI_ASSOC) : [];

// Group employees by their current shift id (for the Grid view) — ordered by shift start time,
// with an "Unassigned" bucket last for employees with no active shift.
$roster_groups = [];
foreach ($shift_rows as $s) {
    $roster_groups[$s['id']] = ['label' => $s['label'], 'employees' => []];
}
$roster_groups['__unassigned__'] = ['label' => 'Unassigned', 'employees' => []];
foreach ($employees as $r) {
    $key = ($r['cur_schedule_id'] && isset($roster_groups[$r['cur_schedule_id']])) ? $r['cur_schedule_id'] : '__unassigned__';
    $roster_groups[$key]['employees'][] = $r;
}

// Distinct departments for the filter
$dept_list = [];
$dq = $conn->query("SELECT DISTINCT d.name FROM department d INNER JOIN employee e ON e.department_id = d.id ORDER BY d.name ASC");
while ($dq && $dr = $dq->fetch_assoc()) $dept_list[] = $dr['name'];
$class_list = [];
$cq = $conn->query("SELECT DISTINCT clasification FROM clasification ORDER BY clasification ASC");
while ($cq && $cr = $cq->fetch_assoc()) $class_list[] = $cr['clasification'];

// Rest days are stored as a CSV of weekday numbers (0=Sun … 6=Sat), matching PHP date('w').
$RD_LABELS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
$RD_NAMES  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Render a compact read-only set of day pills for a rest_days CSV (used in the list/grid).
if (!function_exists('rest_days_pills')) {
    function rest_days_pills($csv, $labels)
    {
        $set = array_filter(array_map('intval', $csv === '' || $csv === null ? [] : explode(',', $csv)), function ($d) { return $d >= 0 && $d <= 6; });
        if (empty($set)) return '<span class="text-muted" style="font-size:11px;">—</span>';
        $out = '<span class="rd-view">';
        foreach ($labels as $i => $lb) {
            $on = in_array($i, $set, true);
            $out .= '<span class="rd-view-day' . ($on ? ' on' : '') . '">' . $lb . '</span>';
        }
        return $out . '</span>';
    }
}
?>
<style>
    .roster-avatar { width:30px; height:30px; border-radius:50%; background:#673bb6; color:#fff; font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .roster-emp-name { font-weight:600; font-size:13px; line-height:1.2; color:#1a1a1a; }
    .roster-emp-no { font-size:11px; color:#1976d2; font-family:monospace; font-weight:600; }
    .roster-emp-link { text-decoration:none; }
    .roster-emp-link:hover .roster-emp-name { color:#673bb6; text-decoration:underline; }
    .filter-label { font-size:11px; color:#666; font-weight:600; margin-bottom:3px; }
    .roster-toolbar { background:#f2f0f6; border:1px solid #dad4e5; border-radius:6px; padding:10px 12px; }
    .roster-toolbar .form-label { font-size:11px; font-weight:600; color:#555; margin-bottom:3px; }
    .sel-count-badge { font-size:11px; }
    .roster-edit-btn { padding:2px 8px; font-size:12px; flex-shrink:0; }

    /* ---- Grid (card) view, grouped by shift, compact cards ---- */
    .roster-group-head { display:flex; align-items:center; gap:8px; background:#f2f0f6; border:1px solid #dad4e5; border-radius:6px; padding:6px 10px; margin-bottom:8px; }
    .roster-group-head .form-check-label { font-size:12px; font-weight:700; color:#57339d; }
    .roster-group-count { font-size:11px; margin-left:auto; }
    .roster-card { border:1px solid #e2e5ee; border-radius:6px; padding:6px 7px; background:#fff; height:100%; transition:box-shadow .15s, border-color .15s; }
    .roster-card:hover { box-shadow:0 1px 6px rgba(0,0,0,.08); border-color:#c5cde8; }
    .roster-card-head { display:flex; align-items:center; gap:5px; }
    .roster-card-head .row-chk { flex-shrink:0; margin:0; }
    .roster-emp-link { display:flex; align-items:center; gap:5px; min-width:0; flex:1; }
    .roster-card .roster-avatar { width:22px; height:22px; font-size:9px; flex-shrink:0; }
    .roster-emp-text { min-width:0; }
    .roster-card .roster-emp-name { font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .roster-card .roster-emp-no { font-size:9.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .roster-card-select { display:flex; align-items:center; justify-content:space-between; gap:4px; margin-top:5px; }
    .roster-card-shift-text { font-size:10.5px; color:#444; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .roster-card-select .roster-edit-btn { padding:1px 6px; font-size:11px; }
    .roster-card-col.d-none { display:none !important; }
    #roster-grid.d-none, #roster-view-table.d-none { display:none !important; }
    .view-toggle .btn { padding:4px 10px; }
    .view-toggle .btn.active { background:#673bb6; border-color:#673bb6; color:#fff; }

    /* ---- Planner (staging) panel ---- */
    .planner-card { border:1px solid #ffd591; border-radius:8px; overflow:hidden; box-shadow:0 1px 6px rgba(250,173,20,.12); }
    .planner-head { display:flex; align-items:center; gap:8px; padding:9px 14px; background:linear-gradient(90deg,#fff7e6,#fffbf0); border-bottom:1px solid #ffe7ba; }
    .planner-title { font-size:13px; font-weight:700; color:#ad6800; }
    .planner-hint { font-size:11px; color:#8c6d1f; }

    /* ---- Bootstrap date picker pill (same pattern as Daily Board) ---- */
    .rp-date-pill { display:flex; align-items:center; gap:6px; width:100%; padding:6px 11px; border:1px solid #d9d3e4; border-radius:6px; background:#fff; font-size:13px; font-weight:600; color:#57339d; cursor:pointer; transition:border-color .15s; }
    .rp-date-pill:hover { border-color:#673bb6; }
    .rp-date-pill i { color:#673bb6; }
    /* daterangepicker theme override, same purple used on attendance.php / daily-board.php */
    .daterangepicker td.active, .daterangepicker td.active:hover { background-color:#673bb6 !important; }
    .daterangepicker td.start-date, .daterangepicker td.end-date { background-color:#5d36a6 !important; }
    .daterangepicker .drp-buttons .btn.applyBtn { background-color:#673bb6 !important; border-color:#5d36a6 !important; }
    #plan-table { font-size:12px; }
    #plan-table thead th { background:#fffbf0; color:#8c6d1f; font-size:11px; text-transform:uppercase; letter-spacing:.3px; }
    #plan-table td { vertical-align:middle; }
    .plan-shift-badge { background:#f4f1fa; border:1px solid #bca9df; color:#006d75; border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; }
    .plan-cur-old { color:#999; text-decoration:line-through; font-size:11px; }

    /* ---- Rest-day picker (editable) ---- */
    .rd-picker { display:inline-flex; gap:3px; }
    .rd-day { width:26px; height:26px; padding:0; border:1px solid #d9d3e4; border-radius:50%; background:#fff; color:#888; font-size:11px; font-weight:700; line-height:1; cursor:pointer; transition:all .12s; }
    .rd-day:hover { border-color:#673bb6; }
    .rd-day.active { background:#673bb6; border-color:#5d36a6; color:#fff; }
    /* ---- Rest-day pills (read-only, in list/grid/plan) ---- */
    .rd-view { display:inline-flex; gap:2px; }
    .rd-view-day { width:16px; height:16px; border-radius:50%; font-size:9px; font-weight:700; line-height:16px; text-align:center; background:#eef1f5; color:#c2c8d0; }
    .rd-view-day.on { background:#673bb6; color:#fff; }

    /* ---- Selection action bar (slides up only when employees are selected) ---- */
    .page-content { padding-bottom: 130px; }              /* keep last rows above the floating bar */
    #roster-table tbody tr, #roster-grid .roster-card { cursor:pointer; }  /* whole row/card is tappable to select */
    .roster-actionbar {
        position:fixed; left:50%; bottom:18px; transform:translate(-50%,170%);
        width:min(920px,calc(100% - 40px)); background:#fff; border:1px solid #e4e3e9;
        border-radius:16px; box-shadow:0 14px 40px -12px rgba(47,35,73,.30); z-index:1030;
        transition:transform .28s cubic-bezier(.2,.9,.3,1.2);
    }
    .roster-actionbar.show { transform:translate(-50%,0); }
    .rab-top { display:flex; align-items:center; gap:10px; padding:10px 14px; flex-wrap:wrap; }
    .rab-count { font-weight:700; font-size:14px; display:flex; align-items:center; gap:8px; color:#28223b; }
    .rab-n { background:#673bb6; color:#fff; border-radius:8px; min-width:26px; height:26px; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; padding:0 6px; }
    .rab-tabs { display:inline-flex; background:#f4f3f6; border:1px solid #e4e3e9; border-radius:11px; padding:3px; }
    .rab-tabs button { border:0; background:transparent; color:#625e6e; font-weight:700; font-size:13px; padding:7px 12px; border-radius:8px; cursor:pointer; display:inline-flex; gap:6px; align-items:center; }
    .rab-tabs button.on { background:#fff; color:#673bb6; box-shadow:0 1px 3px rgba(0,0,0,.1); }
    .rab-clear { margin-left:auto; background:transparent; border:0; color:#9895a3; font-weight:600; font-size:13px; cursor:pointer; }
    .rab-panel { display:none; border-top:1px solid #efeef1; background:#f8f8fa; padding:12px 14px; border-radius:0 0 16px 16px; }
    .rab-panel.on { display:block; }
    .rab-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .rab-field { display:flex; flex-direction:column; }
    .rab-hint { font-size:11.5px; color:#9895a3; margin-top:8px; }
    @media (max-width:640px){ .rab-clear{ margin-left:0; } }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-calendar-todo-line me-2 text-success"></i>Shift Roster</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Shift Roster</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex gap-2">
                        <h4 class="card-title mb-0 flex-grow-1"><i class="ri-team-line me-2 text-success"></i>Assign Shifts</h4>
                        <div class="btn-group view-toggle" role="group" aria-label="View style">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-view-list" title="List view">
                                <i class="ri-list-check-2"></i> List
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-view-grid" title="Grid view">
                                <i class="ri-layout-grid-line"></i> Grid
                            </button>
                        </div>
                        <a href="work-schedules" class="btn btn-sm btn-outline-secondary"><i class="ri-time-line me-1"></i>Manage Shifts</a>
                    </div>
                    <div class="card-body">

                        <!-- Planner (staging) panel — shown only when there are pending drafts -->
                        <div class="planner-card mb-3 d-none" id="planner-card">
                            <div class="planner-head">
                                <i class="ri-calendar-schedule-line" style="color:#ad6800;font-size:16px;"></i>
                                <span class="planner-title">Planned Changes</span>
                                <span class="badge bg-dark" id="plan-count">0</span>
                                <span class="planner-hint d-none d-md-inline ms-1">— staged &amp; hidden from employees until you apply</span>
                                <div class="ms-auto d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-plan-clear"><i class="ri-delete-bin-line me-1"></i>Clear</button>
                                    <button type="button" class="btn btn-sm btn-success" id="btn-plan-apply"><i class="ri-check-double-line me-1"></i>Apply All</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0 align-middle" id="plan-table">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Current</th>
                                            <th>Planned Shift</th>
                                            <th>Effective</th>
                                            <th>Rest Days</th>
                                            <th>Notes</th>
                                            <th style="width:40px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="plan-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Tip shown above the roster -->
                        <div class="text-muted mb-2" style="font-size:12.5px;">
                            <i class="ri-cursor-line me-1 text-success"></i>Tap a row to select employees, then choose an action at the bottom.
                        </div>

                        <!-- Selection action bar — slides up only when employees are selected.
                             All the same controls/IDs the old toolbar used, so nothing behind them changed. -->
                        <div class="roster-actionbar" id="roster-actionbar">
                            <div class="rab-top">
                                <div class="rab-count"><span class="rab-n" id="sel-count">0</span> selected</div>
                                <div class="rab-tabs" id="rab-tabs">
                                    <button type="button" class="on" data-tab="shift"><i class="ri-time-line"></i> Assign shift</button>
                                    <button type="button" data-tab="rest"><i class="ri-moon-line"></i> Rest days</button>
                                    <button type="button" data-tab="rate"><i class="ri-money-dollar-circle-line"></i> Rate type</button>
                                </div>
                                <button type="button" class="rab-clear" id="btn-sel-clear"><i class="ri-close-line me-1"></i>Clear</button>
                            </div>

                            <!-- Assign shift -->
                            <div class="rab-panel on" data-panel="shift">
                                <div class="rab-row">
                                    <div class="rab-field" style="flex:1;min-width:190px;">
                                        <div class="form-label"><i class="ri-time-line me-1"></i>Shift</div>
                                        <select class="form-select form-select-sm" id="bulk-shift">
                                            <option value="">— Select shift —</option>
                                            <?php foreach ($shift_rows as $s): ?>
                                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="rab-field" style="min-width:150px;">
                                        <div class="form-label"><i class="ri-calendar-2-line me-1"></i>Effective from</div>
                                        <div class="rp-date-pill" id="eff-from-pill">
                                            <i class="ri-calendar-2-line"></i>
                                            <span id="eff-from-label"><?= date('M j, Y') ?></span>
                                        </div>
                                        <input type="hidden" id="eff-from" value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="rab-field" style="flex:1;min-width:170px;">
                                        <div class="form-label"><i class="ri-sticky-note-line me-1"></i>Notes</div>
                                        <input type="text" class="form-control form-control-sm" id="bulk-notes" placeholder="Optional reason for change">
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-warning text-dark" id="btn-bulk-plan" title="Queue for later — hidden from employees until applied">
                                        <i class="ri-add-line me-1"></i>Add to Plan
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success" id="btn-bulk-apply" title="Apply now &amp; notify employees">
                                        <i class="ri-check-double-line me-1"></i>Apply now
                                    </button>
                                </div>
                                <div class="rab-hint">Shift only — rest days are left as they are (change those in the Rest days tab). Apply now updates immediately and notifies employees. Add to Plan stages it, hidden until you apply.</div>
                            </div>

                            <!-- Rest days -->
                            <div class="rab-panel" data-panel="rest">
                                <div class="rab-row">
                                    <div class="rab-field">
                                        <div class="form-label"><i class="ri-moon-line me-1"></i>Days off</div>
                                        <div class="rd-picker" id="bulk-rest">
                                            <?php foreach ($RD_LABELS as $i => $lb): ?>
                                                <button type="button" class="rd-day<?= $i === 0 ? ' active' : '' ?>" data-day="<?= $i ?>" title="<?= $RD_NAMES[$i] ?>"><?= $lb ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                        <input type="hidden" id="bulk-rest-val" value="0">
                                    </div>
                                    <div class="flex-grow-1"></div>
                                    <button type="button" class="btn btn-sm btn-success" id="btn-bulk-rest" title="Update only the rest days of selected employees — no shift change">
                                        <i class="ri-check-line me-1"></i>Save rest days
                                    </button>
                                </div>
                                <div class="rab-hint">Updates only the day-off for selected employees — the shift stays the same.</div>
                            </div>

                            <!-- Rate type -->
                            <div class="rab-panel" data-panel="rate">
                                <div class="rab-row">
                                    <div class="rab-field" style="min-width:250px;">
                                        <div class="form-label"><i class="ri-money-dollar-circle-line me-1"></i>Pay rate type</div>
                                        <select class="form-select form-select-sm" id="bulk-rate-type">
                                            <option value="">— Choose rate —</option>
                                            <option value="daily">Daily — per day present</option>
                                            <option value="monthly">Monthly — salary minus absences</option>
                                            <option value="fixed">Fixed — full salary, no attendance</option>
                                        </select>
                                    </div>
                                    <div class="flex-grow-1"></div>
                                    <button type="button" class="btn btn-sm btn-success" id="btn-bulk-rate-type" title="Set the pay rate type for selected employees (payroll setting — not a schedule change)">
                                        <i class="ri-check-line me-1"></i>Set rate type
                                    </button>
                                </div>
                                <div class="rab-hint">A payroll setting — affects the next payroll calculation, not the schedule.</div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="row g-2 mb-2">
                            <div class="col-sm-3">
                                <div class="filter-label"><i class="ri-building-3-line me-1"></i>Department</div>
                                <select class="form-select form-select-sm" id="f-dept">
                                    <option value="">ALL</option>
                                    <?php foreach ($dept_list as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <div class="filter-label"><i class="ri-shield-check-line me-1"></i>Classification</div>
                                <select class="form-select form-select-sm" id="f-class">
                                    <option value="">ALL</option>
                                    <?php foreach ($class_list as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <div class="filter-label"><i class="ri-time-line me-1"></i>Current Shift</div>
                                <select class="form-select form-select-sm" id="f-shift">
                                    <option value="">ALL</option>
                                    <option value="None">— Unassigned —</option>
                                    <?php foreach ($shift_rows as $s): ?><option value="<?= htmlspecialchars($s['description']) ?>"><?= htmlspecialchars($s['description']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <div class="filter-label"><i class="ri-pulse-line me-1"></i>Status</div>
                                <select class="form-select form-select-sm" id="f-status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="">ALL</option>
                                </select>
                            </div>
                            <div class="col-sm-3" id="grid-search-wrap" style="display:none;">
                                <div class="filter-label"><i class="ri-search-line me-1"></i>Search</div>
                                <input type="text" class="form-control form-control-sm" id="grid-search" placeholder="Name, employee no., position...">
                            </div>
                        </div>

                        <!-- ============ LIST VIEW ============ -->
                        <div class="table-responsive" id="roster-view-table">
                            <table id="roster-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:34px;" class="text-center"><input type="checkbox" id="chk-all" class="form-check-input"></th>
                                        <th><i class="ri-user-3-line me-1"></i>Employee</th>
                                        <th><i class="ri-building-3-line me-1"></i>Department</th>
                                        <th><i class="ri-shield-check-line me-1"></i>Classification</th>
                                        <th><i class="ri-time-line me-1"></i>Current Shift</th>
                                        <th class="text-center"><i class="ri-calendar-2-line me-1"></i>Since</th>
                                        <th class="d-none">shift</th>
                                        <th class="d-none">status</th>
                                        <th class="text-center"><i class="ri-moon-line me-1"></i>Rest Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employees as $r):
                                        $initials = strtoupper(substr($r['firstname'], 0, 1)) . strtoupper(substr($r['lastname'], 0, 1));
                                        $name = htmlspecialchars($r['lastname'] . ', ' . $r['firstname'] . ($r['middlename'] ? ' ' . substr($r['middlename'], 0, 1) . '.' : ''));
                                        $status_txt = $r['status'] == 1 ? 'Active' : 'Inactive';
                                        $cur_shift_txt = $r['cur_desc'] ?: 'None';
                                    ?>
                                    <tr>
                                        <td class="text-center"><input type="checkbox" class="form-check-input row-chk" value="<?= $r['id'] ?>"></td>
                                        <td>
                                            <a href="index.php?page=employee-details&id=<?= $r['id'] ?>" class="roster-emp-link d-flex align-items-center gap-2" title="View Employee Details">
                                                <span class="roster-avatar"><?= $initials ?></span>
                                                <div>
                                                    <div class="roster-emp-name"><?= $name ?></div>
                                                    <div class="roster-emp-no"><?= htmlspecialchars($r['employee_no']) ?> &middot; <?= htmlspecialchars(ucwords($r['pname'])) ?></div>
                                                </div>
                                            </a>
                                        </td>
                                        <td><?= !empty($r['dept_name']) ? htmlspecialchars($r['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                                        <td>
                                            <span class="badge" style="<?= clasif_badge_style($r['clasification']) ?>"><?= htmlspecialchars($r['clasification']) ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-between gap-2">
                                                <span>
                                                    <?= $cur_shift_txt !== 'None' ? htmlspecialchars($cur_shift_txt) : '<span class="text-muted">— None —</span>' ?>
                                                    <?php if (!empty($r['nxt_desc'])): /* a change already scheduled ahead — shown as pending, never as the current shift */ ?>
                                                        <div style="font-size:10.5px;color:#1565c0;white-space:nowrap;margin-top:1px;"
                                                             title="Takes effect <?= date('F j, Y', strtotime($r['nxt_from'])) ?>">
                                                            <i class="ri-arrow-right-line"></i>
                                                            <?= htmlspecialchars($r['nxt_desc']) ?>
                                                            <span style="color:#7a8aa0;">from <?= date('M j', strtotime($r['nxt_from'])) ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-primary roster-edit-btn"
                                                        data-emp="<?= $r['id'] ?>" data-name="<?= $name ?>" data-current="<?= (int)$r['cur_schedule_id'] ?>" data-rest="<?= htmlspecialchars($r['pref_rest_days'] ?? '') ?>"
                                                        title="Change shift"><i class="ri-edit-line"></i></button>
                                            </div>
                                        </td>
                                        <td class="text-center" style="font-size:12px;white-space:nowrap;">
                                            <?= $r['cur_from'] ? date('M d, Y', strtotime($r['cur_from'])) : '<span class="text-muted">—</span>' ?>
                                        </td>
                                        <td class="d-none"><?= htmlspecialchars($cur_shift_txt) ?></td>
                                        <td class="d-none"><?= $status_txt ?></td>
                                        <td class="text-center"><?= rest_days_pills($r['cur_rest_days'] ?? '', $RD_LABELS) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ============ GRID VIEW (grouped by current shift) ============ -->
                        <div id="roster-grid" class="d-none">
                            <?php foreach ($roster_groups as $gkey => $group):
                                if (empty($group['employees'])) continue;
                                $gid = 'grp-' . md5($gkey);
                            ?>
                            <div class="roster-group mb-3" data-group="<?= htmlspecialchars($gkey) ?>">
                                <div class="roster-group-head">
                                    <div class="form-check mb-0">
                                        <input type="checkbox" class="form-check-input roster-group-chk" id="<?= $gid ?>">
                                        <label class="form-check-label" for="<?= $gid ?>">
                                            <i class="ri-time-line me-1 text-success"></i><?= htmlspecialchars($group['label']) ?>
                                        </label>
                                    </div>
                                    <span class="badge bg-secondary roster-group-count"><?= count($group['employees']) ?></span>
                                </div>
                                <div class="row g-2 roster-group-cards">
                                    <?php foreach ($group['employees'] as $r):
                                        $initials = strtoupper(substr($r['firstname'], 0, 1)) . strtoupper(substr($r['lastname'], 0, 1));
                                        $name = htmlspecialchars($r['lastname'] . ', ' . $r['firstname'] . ($r['middlename'] ? ' ' . substr($r['middlename'], 0, 1) . '.' : ''));
                                        $status_txt = $r['status'] == 1 ? 'Active' : 'Inactive';
                                        $cur_shift_txt = $r['cur_desc'] ?: 'None';
                                        $search_blob = strtolower($r['lastname'] . ' ' . $r['firstname'] . ' ' . $r['employee_no'] . ' ' . $r['pname']);
                                    ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-2 roster-card-col"
                                         data-dept="<?= htmlspecialchars($r['dept_name'] ?? '') ?>"
                                         data-class="<?= htmlspecialchars($r['clasification']) ?>"
                                         data-shift="<?= htmlspecialchars($cur_shift_txt) ?>"
                                         data-status="<?= $status_txt ?>"
                                         data-search="<?= htmlspecialchars($search_blob) ?>">
                                        <div class="roster-card">
                                            <div class="roster-card-head">
                                                <input type="checkbox" class="form-check-input row-chk" value="<?= $r['id'] ?>">
                                                <a href="index.php?page=employee-details&id=<?= $r['id'] ?>" class="roster-emp-link" title="View Employee Details">
                                                    <span class="roster-avatar"><?= $initials ?></span>
                                                    <div class="roster-emp-text">
                                                        <div class="roster-emp-name"><?= $name ?></div>
                                                        <div class="roster-emp-no"><?= htmlspecialchars($r['employee_no']) ?></div>
                                                    </div>
                                                </a>
                                            </div>
                                            <div class="roster-card-select">
                                                <span class="roster-card-shift-text"><?= $cur_shift_txt !== 'None' ? htmlspecialchars($cur_shift_txt) : '—' ?></span>
                                                <button type="button" class="btn btn-sm btn-outline-primary roster-edit-btn"
                                                        data-emp="<?= $r['id'] ?>" data-name="<?= $name ?>" data-current="<?= (int)$r['cur_schedule_id'] ?>" data-rest="<?= htmlspecialchars($r['pref_rest_days'] ?? '') ?>"
                                                        title="Change shift"><i class="ri-edit-line"></i></button>
                                            </div>
                                            <div class="roster-card-rest mt-1"><?= rest_days_pills($r['cur_rest_days'] ?? '', $RD_LABELS) ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($employees)): ?>
                                <div class="text-center text-muted py-4">No employees found.</div>
                            <?php endif; ?>
                            <div class="text-center text-muted py-3 d-none" id="roster-grid-empty">No employees match the current filters.</div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Shift Modal (single employee — same fields as the employee-details "Assign Schedule" form) -->
    <div class="modal fade" id="modal-roster-edit-shift" tabindex="-1">
        <div class="modal-dialog">
            <form id="form-roster-edit-shift">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title" id="redit-title">
                            <i class="ri-time-line me-2 text-success"></i>Change Shift
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="redit-emp-id" name="employee_id" value="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Shift <span class="text-danger">*</span></label>
                            <select class="form-select" id="redit-shift" name="schedule_id" required>
                                <option value="">— Select shift —</option>
                                <?php foreach ($shift_rows as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Effective From <span class="text-danger">*</span></label>
                            <div class="rp-date-pill" id="redit-effective-pill">
                                <i class="ri-calendar-2-line"></i>
                                <span id="redit-effective-label">Select date…</span>
                            </div>
                            <input type="hidden" id="redit-effective" name="effective_from">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="ri-moon-line me-1 text-success"></i>Rest Days</label>
                            <div class="rd-picker" id="redit-rest">
                                <?php foreach ($RD_LABELS as $i => $lb): ?>
                                    <button type="button" class="rd-day" data-day="<?= $i ?>" title="<?= $RD_NAMES[$i] ?>"><?= $lb ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="redit-rest-val" name="rest_days" value="">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" id="redit-notes" name="notes" placeholder="Optional reason for change">
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark" id="redit-add-plan"><i class="ri-add-line me-1"></i>Add to Plan</button>
                            <button type="submit" class="btn btn-sm btn-success"><i class="ri-check-double-line me-1"></i>Apply now</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
