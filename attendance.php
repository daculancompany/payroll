<?php
// Backward-compat: read URL params for initial filter state.
// Defaults to today so the table (and its COUNT/SUM aggregates) never
// scans the entire attendance history on an unfiltered page load.
$today             = date('Y-m-d');
$init_from         = isset($_GET['from'])        ? htmlspecialchars($_GET['from'])  : $today;
$init_to           = isset($_GET['to'])          ? htmlspecialchars($_GET['to'])    : $today;
$init_employee_ids = isset($_GET['employee_id']) ? implode(',', array_map('intval', (array)$_GET['employee_id'])) : '';
$init_site_id      = isset($_GET['site_id'])     ? intval($_GET['site_id'])         : '';
$init_range_label  = ($init_from === $today && $init_to === $today)
    ? 'Today'
    : date('M j, Y', strtotime($init_from)) . ' – ' . date('M j, Y', strtotime($init_to));
?>
<style>
    .att-stat-box { flex:1; min-width:120px; border:1px solid #d0d7ee; border-radius:4px; background:#eef0f8; padding:9px 12px; display:flex; align-items:center; gap:10px; }
    .att-stat-box .ico { font-size:22px; color:#673bb6; flex-shrink:0; }
    .att-stat-box .val { font-size:17px; font-weight:700; color:#673bb6; font-family:'Segoe UI',monospace; line-height:1.1; }
    .att-stat-box .lbl { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.3px; margin-top:1px; }
    .att-stats-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .att-filter-panel { background:#f8f6fb; border:1px solid #dbd5e7; border-left:3px solid #673bb6; border-radius:6px; padding:12px 14px; margin-bottom:14px; }
    .att-filter-panel .att-flabel { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#673bb6; margin-bottom:4px; display:block; }
    /* date-range trigger — mirrors employee-portal.php */
    .att-range-picker { display:flex; align-items:center; gap:6px; width:100%; padding:6px 11px; border:1px solid #d9d3e4; border-radius:6px; background:#fff; font-size:13px; font-weight:600; color:#0c5460; cursor:pointer; transition:border-color .15s,box-shadow .15s; }
    .att-range-picker:hover { border-color:#673bb6; }
    .att-range-picker i:first-child { color:#673bb6; }
    /* daterangepicker theme now lives globally in assets2/css/custom-select.css */
    .att-filter-bar { background:#673bb6; color:#fff; border-radius:4px; padding:10px 16px; margin-bottom:10px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .att-filter-bar .lbl { font-size:12px; opacity:.8; }
    .att-filter-bar .val { font-weight:700; font-size:13px; }
    .att-site-badge { background:#673bb6; color:#fff; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:700; display:inline-block; margin-bottom:2px; }
    .att-site-name { font-size:12px; font-weight:600; }
    .att-date-main { font-weight:700; color:#673bb6; font-size:13px; }
    .att-emp-id { font-family:monospace; color:#673bb6; font-size:11px; }
    .att-pill { padding:3px 9px; border-radius:5px; font-size:12px; font-weight:600; display:inline-block; }
    .att-pill-work { background:#d4edda; color:#155724; }
    .att-pill-ot   { background:#fff3cd; color:#856404; }
    .att-pill-ut   { background:#d1ecf1; color:#0c5460; }
    .att-pill-late { background:#f8d7da; color:#721c24; }
    .att-log-bio   { background:#673bb6; color:#fff; font-size:10px; }
    .att-log-manual{ background:#dc3545; color:#fff; font-size:10px; }
    .att-log-dir { font-size:10px; font-weight:700; padding:1px 0; border-radius:3px; min-width:34px; text-align:center; display:inline-block; }
    .att-log-in  { background:#d4edda; color:#155724; }
    .att-log-out { background:#f8d7da; color:#721c24; }
    #modal-time-logs .tl-meta-label { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.3px; }
    #modal-time-logs .tl-meta-value { font-size:13px; font-weight:700; color:#673bb6; }
  
    .att-empty { text-align:center; padding:3.5rem 1rem 3rem; background:#f7f8fc; border-radius:8px; border:2px dashed #c5cde8; }
    .att-empty .att-empty-icon { width:64px; height:64px; border-radius:50%; background:#eef0f8; border:2px solid #d0d7ee; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; }
    .att-empty .att-empty-icon i { font-size:28px; color:#673bb6; opacity:.6; }
    .att-empty h6 { color:#673bb6; font-weight:700; margin-bottom:6px; font-size:15px; }
    .att-empty p { color:#888; font-size:12px; margin-bottom:14px; }
    .att-empty .att-empty-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 16px; border-radius:4px; background:#673bb6; color:#fff; font-size:12px; font-weight:600; text-decoration:none; border:none; cursor:pointer; }
    .att-empty .att-empty-btn:hover { background:#2d3d66; color:#fff; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-calendar-check-line me-2 text-primary"></i>Attendance Record</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Attendance Record</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card" style="border-top:3px solid #673bb6;">
                    <div class="card-header align-items-center d-flex py-2">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-calendar-check-line me-2" style="color:#673bb6;"></i>Attendance Records
                        </h4>
                    </div>

                    <div class="card-body">
                        <!-- Inline Filter -->
                        <form id="form-filter" class="att-filter-panel" novalidate>
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md-4 col-lg-3">
                                    <label class="att-flabel"><i class="ri-calendar-range-line me-1"></i>Date Range</label>
                                    <div id="att-daterange" class="att-range-picker">
                                        <i class="ri-calendar-2-line"></i>
                                        <span id="att-range-label"><?= htmlspecialchars($init_range_label) ?></span>
                                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                                    </div>
                                    <input type="hidden" name="from" id="from" value="<?= $init_from ?>">
                                    <input type="hidden" name="to" id="to" value="<?= $init_to ?>">
                                </div>
                                <div class="col-12 col-md-5 col-lg-5">
                                    <label class="att-flabel"><i class="ri-user-line me-1"></i>Employee</label>
                                    <select id="employee-select" name="employee_id[]" class="form-control form-control-sm" multiple
                                        data-placeholder="Select one or more employees…" required
                                        data-parsley-required-message="Please select at least one employee.">
                                        <?php
                                        $employee = $conn->query("SELECT id, employee_no, CONCAT(lastname,', ',firstname,' ',middlename) AS ename FROM employee WHERE status=1 ORDER BY lastname, firstname ASC");
                                        while ($row = $employee->fetch_assoc()):
                                        ?>
                                            <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['ename']) ?> | <?= htmlspecialchars($row['employee_no']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3 col-lg-4 d-flex gap-2">
                                    <button type="submit" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;">
                                        <i class="ri-search-line me-1"></i>Apply Filter
                                    </button>
                                    <button type="button" id="btn-clear-filter" class="btn btn-sm btn-outline-secondary">
                                        <i class="ri-restart-line me-1"></i>Reset to Today
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- Stats Row -->
                        <div class="att-stats-row" id="att-stats-row" style="display:none;">
                            <div class="att-stat-box">
                                <div class="ico"><i class="ri-file-list-line"></i></div>
                                <div><div class="val" id="stat-total">0</div><div class="lbl">Total Records</div></div>
                            </div>
                            <div class="att-stat-box">
                                <div class="ico"><i class="ri-time-line"></i></div>
                                <div><div class="val" id="stat-hours">0</div><div class="lbl">Total Hours</div></div>
                            </div>
                            <div class="att-stat-box" style="border-color:#ffc10740;background:#fffdf0;">
                                <div class="ico" style="color:#856404;"><i class="ri-flashlight-line"></i></div>
                                <div><div class="val" id="stat-ot" style="color:#856404;">0</div><div class="lbl">Overtime Hrs</div></div>
                            </div>
                            <div class="att-stat-box" style="border-color:#17a2b840;background:#f0fbfc;">
                                <div class="ico" style="color:#0c5460;"><i class="ri-timer-line"></i></div>
                                <div><div class="val" id="stat-ut" style="color:#0c5460;">0m</div><div class="lbl">Avg Undertime</div></div>
                            </div>
                            <div class="att-stat-box" style="border-color:#dc354540;background:#fdf0f1;">
                                <div class="ico" style="color:#721c24;"><i class="ri-alarm-warning-line"></i></div>
                                <div><div class="val" id="stat-late" style="color:#721c24;">0m</div><div class="lbl">Avg Late</div></div>
                            </div>
                            <div class="att-stat-box" style="border-color:#6c757d40;background:#f8f9fa;">
                                <div class="ico" style="color:#6c757d;"><i class="ri-group-line"></i></div>
                                <div><div class="val" id="stat-emps" style="color:#6c757d;">0</div><div class="lbl">Employees</div></div>
                            </div>
                        </div>

                        <!-- DataTable -->
                        <div class="table-responsive">
                            <table id="attendance-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:110px;"><i class="ri-calendar-line me-1"></i>Date</th>
                                        <th style="width:190px;"><i class="ri-user-line me-1"></i>Employee</th>
                                        <th><i class="ri-building-line me-1"></i>Site</th>
                                        <th class="text-center" style="width:95px;"><i class="ri-time-line me-1"></i>Work Hrs</th>
                                        <th class="text-center" style="width:90px;"><i class="ri-flashlight-line me-1"></i>OT</th>
                                        <th class="text-center" style="width:90px;"><i class="ri-timer-line me-1"></i>Undertime</th>
                                        <th class="text-center" style="width:80px;"><i class="ri-alarm-warning-line me-1"></i>Late</th>
                                        <th style="width:130px;"><i class="ri-history-line me-1"></i>Time Logs</th>
                                        <th class="text-center" style="width:95px;"><i class="ri-checkbox-circle-line me-1"></i>Status</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include 'component/add_attendance.php'; ?>

<!-- Time Log Details Modal -->
<div class="modal fade" id="modal-time-logs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#673bb6;">
                <h6 class="modal-title text-white"><i class="ri-history-line me-2"></i>Time Log Details</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <div class="tl-meta-label"><i class="ri-user-line me-1"></i>Employee</div>
                        <div class="tl-meta-value" id="tl-employee"></div>
                    </div>
                    <div class="text-end">
                        <div class="tl-meta-label"><i class="ri-calendar-line me-1"></i>Date</div>
                        <div class="tl-meta-value" id="tl-date"></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" id="tl-table">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width:40px;">#</th>
                                <th><i class="ri-time-line me-1"></i>Time</th>
                                <th class="text-center" style="width:80px;">Direction</th>
                                <th class="text-center" style="width:80px;">Source</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Plain JS only — jQuery not yet available at this point
var attFilter = {
    from:         '<?= $init_from ?>',
    to:           '<?= $init_to ?>',
    employee_ids: '<?= $init_employee_ids ?>',
    site_id:      '<?= $init_site_id ?>',
    emp_label:    '',
    site_label:   '',
};
</script>
