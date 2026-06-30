<?php
if (!isset($_GET['id'])) {
    return;
}
$id = (int) $_GET['id'];


$filter_query = "";
$sid = '';
if (isset($_GET['site_id'])  && $_GET['site_id'] !== 'all') {
    $sid = $_GET['site_id'];
    $filter_query = " AND a.site_id = $sid  ";
}
// LEFT JOIN payroll g ON g.id = a.payroll_id 
// LEFT JOIN employers  h ON g.employer_id = h.id

$query = "SELECT  employer_name, category,  clusters.cluster FROM payroll  
        LEFT JOIN employers  ON payroll.employer_id = employers.id  
        LEFT JOIN clusters  ON clusters.id = payroll.category 
        WHERE payroll.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll_r = $result->fetch_assoc();

?>
<?php
$query2 = "SELECT * FROM payroll   WHERE id = ?";
$stmt = $conn->prepare($query2);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll = $result->fetch_assoc();
$site_ids = json_decode($payroll['site_ids'], true);
$site_ids = isset($site_ids) ? $site_ids : [];
$commaSeparatedSites = implode(',', $site_ids);
$status = $payroll['status'];

$i = 0;
$query = $conn->query("SELECT  a.*, f.site_code,f.site_name,f.site_address, e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay, d.name as department, p.name as position
FROM payroll_items a 
INNER JOIN employee e ON a.employee_id = e.id 
LEFT JOIN department d ON e.department_id = d.id 
LEFT JOIN position p ON e.position_id = p.id 
LEFT JOIN sites f ON f.id = a.site_id 
WHERE  a.payroll_id = $id $filter_query  ORDER BY lastname ASC ");

// ── Summary stats (pre-aggregated) ──
$sum_q = $conn->prepare("SELECT COUNT(*) as emp_count, SUM(net) as total_net, SUM(absent) as total_absent, SUM(late) as total_late FROM payroll_items WHERE payroll_id = ?");
$sum_q->bind_param("i", $id);
$sum_q->execute();
$summary = $sum_q->get_result()->fetch_assoc();

$contributions_settings = json_decode($payroll['settings'], true) ?: [];

$refunds_settings  = array_filter($contributions_settings, function ($item) {
    return $item["type"] === 4;
});

$contributions_settings = array_filter($contributions_settings, function ($item) {
    return $item["type"] !== 4;
});

$contributions_settings = array_values($contributions_settings);



$payroll_type = $payroll['type'];

?>
<link rel="stylesheet" href="assets2/css/my-style.css">
<style>
/* ════════════════════════════════════════════
   Payroll Details — Excel-style enhancements
   ════════════════════════════════════════════ */

/* ── Employee name cell (avatar + name + ID) ── */
.emp-cell { display: flex; align-items: flex-start; gap: 9px; }
.emp-avatar {
    width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%;
    background: linear-gradient(135deg, #107c41, #0b5e31); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; letter-spacing: .5px; text-decoration: none;
    box-shadow: 0 1px 4px rgba(16,124,65,.3); transition: transform .15s, box-shadow .15s;
}
.emp-avatar:hover { transform: scale(1.06); box-shadow: 0 2px 8px rgba(16,124,65,.45); color: #fff; }
.emp-cell-info { min-width: 0; }
.emp-name-link { display: inline-flex; align-items: center; gap: 4px; color: #107c41; text-decoration: none; border-bottom: 1px dotted #8fc7a6; transition: color .15s, border-color .15s; }
.emp-name-link:hover { color: #0b5e31; border-bottom-color: #107c41; }
.emp-name-link b { font-weight: 700; }
.emp-name-link i { font-size: 13px; color: #8fc7a6; }
.emp-no { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-family: 'Segoe UI', monospace; color: #6b7280; margin-top: 2px; font-weight: 600; }
.emp-no i { font-size: 12px; color: #107c41; }

/* ── Summary strip ── */
.pay-stats-strip {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-bottom: 10px; padding: 10px 14px;
    background: #fff; border: 1px solid #e1dfdd;
    border-radius: 6px; border-left: 4px solid #219688;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.pay-stat {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 14px; border-radius: 5px;
    background: #f8f9fa; border: 1px solid #eee;
    min-width: 140px; flex: 1;
}
.pay-stat-icon {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.pay-stat-body { line-height: 1.2; }
.pay-stat-val { font-size: 14px; font-weight: 800; color: #323130; }
.pay-stat-lbl { font-size: 10px; color: #a19f9d; text-transform: uppercase; letter-spacing: .4px; }
.pay-stat.employees .pay-stat-icon { background:#e6f5f3; color:#219688; }
.pay-stat.gross     .pay-stat-icon { background:#e8f5e9; color:#2e7d32; }
.pay-stat.deduct    .pay-stat-icon { background:#fce4ec; color:#c62828; }
.pay-stat.net       .pay-stat-icon { background:#e3f2fd; color:#1565c0; }
.pay-stat.absent    .pay-stat-icon { background:#fff8e1; color:#c98a00; }
.pay-stat.late      .pay-stat-icon { background:#fbe9e7; color:#bf360c; }

/* ── Excel table overrides ── */
/* keep border-collapse:separate — sticky columns require it */

/* Header row 1: section banners */
#table-1 thead tr:first-child th {
    background: #1a6b5f !important;
    color: #fff !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .4px !important;
    border: 1px solid #145049 !important;
    padding: 6px 8px !important;
}
#table-1 thead tr:first-child th.info-header    { background: #2563eb !important; border-color: #1d4ed8 !important; }
#table-1 thead tr:first-child th.success-header { background: #16a34a !important; border-color: #15803d !important; }
#table-1 thead tr:first-child th.danger-header  { background: #dc2626 !important; border-color: #b91c1c !important; }

/* Header row 2: column labels */
#table-1 thead tr:nth-child(2) th {
    background: #219688 !important;
    color: #fff !important;
    font-size: 10px !important;
    font-weight: 600 !important;
    border: 1px solid #176358 !important;
    white-space: nowrap !important;
    padding: 5px 8px !important;
}
#table-1 thead tr:nth-child(2) th.info-header    { background: #3b82f6 !important; border-color: #2563eb !important; }
#table-1 thead tr:nth-child(2) th.success-header { background: #22c55e !important; border-color: #16a34a !important; }
#table-1 thead tr:nth-child(2) th.danger-header  { background: #ef4444 !important; border-color: #dc2626 !important; }

/* Body rows */
#table-1 tbody tr td {
    font-size: 11px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 4px 8px !important;
    vertical-align: middle !important;
}
#table-1 tbody tr:nth-child(even) td { background: #f0faf9 !important; }
#table-1 tbody tr:nth-child(odd) td  { background: #ffffff !important; }
#table-1 tbody tr:hover td { background: #d4f3ee !important; }

/* Frozen cols (checkbox + No.) */
#table-1 tbody td:nth-child(1),
#table-1 tbody td:nth-child(2) {
    background: #e6f5f3 !important;
    border-right: 1px solid #c8e6e2 !important;
    font-weight: 600 !important;
}
#table-1 tbody tr:hover td:nth-child(1),
#table-1 tbody tr:hover td:nth-child(2) { background: #b2e4de !important; }
/* drop the col-2 edge shadow — the Name column now closes the frozen group */
#table-1 tbody td:nth-child(2),
#table-1 tfoot th:nth-child(2),
#table-1 thead tr:first-child th:nth-child(2) { box-shadow: none !important; }

/* ── Frozen Name column (col 3) — left offset set by JS ── */
#table-1 tbody td:nth-child(3),
#table-1 tfoot th:nth-child(3),
#table-1 thead tr:first-child th:nth-child(3) {
    position: sticky !important;
    z-index: 12;
    border-right: 2px solid #219688 !important;
    box-shadow: 3px 0 6px -3px rgba(0,0,0,.22);
    transform: translateZ(0);
    text-align: left !important;
}
#table-1 tbody td:nth-child(3) { background: #eef6ea !important; }
#table-1 tfoot th:nth-child(3) { background: #1a6b5f !important; }
#table-1 thead tr:first-child th:nth-child(3) { z-index: 14 !important; }
#table-1 tbody tr:nth-child(even) td:nth-child(3) { background: #e7f3ec !important; }
#table-1 tbody tr:hover td:nth-child(3) { background: #d4f3ee !important; }

/* Tfoot totals row */
#table-1 tfoot tr th {
    background: #1a6b5f !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border: 1px solid #145049 !important;
    padding: 6px 8px !important;
    position: sticky; bottom: 0; z-index: 10;
}

/* Net pay column — highlight */
td.net-content { background: #e3f2fd !important; color: #1565c0 !important; font-weight: 800 !important; border-left: 2px solid #2563eb !important; }
#table-1 tbody tr:hover td.net-content { background: #bfdbfe !important; }

/* Absent/late badge chips */
.pay-badge { display:inline-block; font-size:9px; font-weight:700; border-radius:3px; padding:1px 5px; margin-left:4px; vertical-align:middle; }
.pay-badge.absent { background:#fff3cd; color:#856404; border:1px solid #ffe69c; }
.pay-badge.late   { background:#fce8e6; color:#c62828; border:1px solid #f5c6cb; }

/* Input cells */
#table-1 .input-group { margin-bottom:0 !important; }
#table-1 .input-class { font-size:11px !important; padding:2px 6px !important; height:26px !important; }

/* Payslip checkbox column */
.ps-chk-cell { width:32px; text-align:center !important; padding:4px 6px !important; }
.ps-chk { width:15px; height:15px; cursor:pointer; accent-color:#219688; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <!-- start page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <div class="page-title-enhanced">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h4 class="mb-0 fw-bold">Payroll Details</h4>
                                    <?php if ($status == 2): ?>
                                        <span class="payroll-status-badge bg-danger text-white"><i class="ri-lock-fill me-1"></i>Locked</span>
                                    <?php else: ?>
                                        <span class="payroll-status-badge bg-success text-white"><i class="ri-lock-unlock-line me-1"></i>Open</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="ri-calendar-2-line me-1"></i>
                                    <?= date('M d', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($payroll['date_to'])) ?>
                                    &nbsp;&bull;&nbsp;
                                    <i class="ri-user-2-line me-1"></i><?= htmlspecialchars($payroll_r['employer_name']) ?>
                                    <?php if ($payroll_r['category'] != 0): ?>
                                        &nbsp;&bull;&nbsp;<i class="ri-global-line me-1"></i><?= htmlspecialchars($payroll_r['cluster']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Payroll</a></li>
                                <li class="breadcrumb-item active">Payroll Details</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div class="xl-panel" id="myDiv">
                    <div class="xl-ribbon">
                        <span class="xl-ribbon-title">
                            <i class="ri-file-excel-2-line"></i> Payroll List
                        </span>
                        <div class="xl-ribbon-actions">
                            <button data-toggle="tooltip" id="sf" title="Fullscreen" onclick="openFullscreen()" class="xl-btn"><i class="ri-fullscreen-line"></i></button>
                            <button style="display:none;" id="hf" data-toggle="tooltip" title="Exit Fullscreen" onclick="closeFullscreen()" class="xl-btn"><i class="ri-fullscreen-exit-line"></i></button>
                            <div class="xl-ribbon-sep"></div>
                            <button data-toggle="tooltip" title="Print Settings" data-bs-toggle="modal" data-bs-target="#modal-print-settings" class="xl-btn"><i class="ri-settings-3-line"></i> Print Settings</button>
                            <button data-toggle="tooltip" title="Sites" onclick="view_site()" class="xl-btn"><i class="ri-building-line"></i> Sites</button>
                            <div class="xl-ribbon-sep"></div>
                            <?php if ($payroll_type == 5) { ?>
                                <a data-toggle="tooltip" title="Print" href="print-montly.php?id=<?= $id ?>" class="xl-btn"><i class="ri-printer-line"></i> Print</a>
                            <?php } else { ?>
                                <a data-toggle="tooltip" title="Print Payroll" href="print-payroll.php?id=<?= $id ?>&site_id=<?= $sid ?>" class="xl-btn" onclick="window.open(this.href,'_blank','width=1100,height=750,scrollbars=yes'); return false;"><i class="ri-printer-line"></i> Print</a>
                                <a data-toggle="tooltip" title="Print By Summary" href="print-payroll-employer.php?id=<?= $id ?>&type=all" class="xl-btn" onclick="window.open(this.href,'_blank','width=1100,height=750,scrollbars=yes'); return false;"><i class="ri-printer-fill"></i> Summary</a>
                                <a data-toggle="tooltip" title="Print Summary by Department" href="print-payroll-dept.php?id=<?= $id ?>" class="xl-btn" onclick="window.open(this.href,'_blank','width=800,height=700,scrollbars=yes'); return false;"><i class="ri-building-2-line"></i> Dept. Summary</a>
                            <?php } ?>
                            <button id="btn-print-payslips" title="Check rows to select employees, then click to print their payslips" onclick="printSelectedPayslips()" class="xl-btn">
                                <i class="ri-file-text-line"></i> Payslips <span id="ps-count" style="background:#c8e6e2;color:#176358;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:2px;font-weight:700;">0</span>
                            </button>
                            <?php if ($status !== 2) { ?>
                                <div class="xl-ribbon-sep"></div>
                                <button data-toggle="tooltip" title="Lock Payroll" onclick="lockPayroll(<?= $id ?>)" class="xl-btn xl-btn-danger"><i class="ri-lock-line"></i> Lock</button>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="saveUnsaved()" id="btn-unsaved" title="Save Changes" class="xl-btn xl-btn-save" style="display:none;">
                                    <i class="bx bx-save"></i> Save
                                    <span id="counter-unsaved">0</span>
                                </button>
                            <?php } ?>
                            <div class="xl-ribbon-sep"></div>
                            <button data-toggle="tooltip" title="Version History" onclick="openPayrollHistory(<?= $id ?>)" class="xl-btn"><i class="ri-history-line"></i> History</button>
                        </div>
                    </div>
                    <div class="xl-panel-body">
                        <!-- ── Summary stats strip ── -->
                        <div class="pay-stats-strip">
                            <div class="pay-stat employees">
                                <div class="pay-stat-icon"><i class="ri-group-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['emp_count']) ?></div>
                                    <div class="pay-stat-lbl">Employees</div>
                                </div>
                            </div>
                            <div class="pay-stat gross">
                                <div class="pay-stat-icon"><i class="ri-money-dollar-circle-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val" id="stat-gross">—</div>
                                    <div class="pay-stat-lbl">Total Gross</div>
                                </div>
                            </div>
                            <div class="pay-stat deduct">
                                <div class="pay-stat-icon"><i class="ri-subtract-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val" id="stat-deduct">—</div>
                                    <div class="pay-stat-lbl">Total Deductions</div>
                                </div>
                            </div>
                            <div class="pay-stat net">
                                <div class="pay-stat-icon"><i class="ri-wallet-3-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= '₱ ' . number_format($summary['total_net'], 2) ?></div>
                                    <div class="pay-stat-lbl">Total Net Pay</div>
                                </div>
                            </div>
                            <div class="pay-stat absent">
                                <div class="pay-stat-icon"><i class="ri-user-unfollow-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['total_absent']) ?></div>
                                    <div class="pay-stat-lbl">Total Absences</div>
                                </div>
                            </div>
                            <div class="pay-stat late">
                                <div class="pay-stat-icon"><i class="ri-alarm-warning-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['total_late']) ?> min</div>
                                    <div class="pay-stat-lbl">Total Late</div>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div class="xl-search-wrap">
                                <i class="ri-search-2-line"></i>
                                <input id="myInput" type="text" placeholder="Search...">
                            </div>
                        </div>
                        <form id="form-payroll">
                            <div class="table-responsive2" id="table-responsive2">
                                <?php if ($payroll_type == 5) { ?>

                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <!-- ═══ ROW 1 : Section group banners ═══ -->
                                            <tr>
                                                <th rowspan="2" class="text-center primary-header ps-chk-cell"><input type="checkbox" class="ps-chk" id="chk-all" title="Select all"></th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                                <th rowspan="2" class="text-center primary-header">Name</th>
                                                <th rowspan="2" class="text-center primary-header">Position</th>
                                                <!-- Basic Earnings (3 cols) -->
                                                <th colspan="3" class="text-center primary-header">Basic Earnings</th>
                                                <!-- Allowance (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Allowance</th>
                                                <!-- Attendance (4 cols) -->
                                                <th colspan="4" class="text-center primary-header">Attendance</th>
                                                <!-- Holidays & Extra Duties (6 cols) -->
                                                <th colspan="6" class="text-center info-header">Holidays &amp; Extra Duties</th>
                                                <!-- Overtime (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Overtime</th>
                                                <!-- Late (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Late</th>
                                                <th rowspan="2" class="text-center success-header">Total Gross Pay</th>
                                                <!-- Deductions -->
                                                <th colspan="<?= count($contributions_settings) + 4 ?>" class="text-center danger-header">Deductions</th>
                                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                                <?php if (count($refunds_settings) > 0) { ?>
                                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center success-header">Refunds</th>
                                                <?php } ?>
                                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                                <th rowspan="2" class="text-center primary-header">Actions</th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                            </tr>
                                            <!-- ═══ ROW 2 : Individual column labels ═══ -->
                                            <tr>
                                                <!-- Basic Earnings -->
                                                <th class="text-center primary-header">Monthly Basic Pay</th>
                                                <th class="text-center primary-header">Quinsena Pay</th>
                                                <th class="text-center success-header">Basic Daily Rate</th>
                                                <!-- Allowance -->
                                                <th class="text-center info-header">No. Days</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Attendance -->
                                                <th class="text-center primary-header">No. of Duty</th>
                                                <th class="text-center primary-header">Absences</th>
                                                <th class="text-center primary-header">Amt. Absences</th>
                                                <th class="text-center success-header">Total Amount</th>
                                                <!-- Holidays & Extra Duties -->
                                                <th class="text-center info-header">Legal Holiday</th>
                                                <th class="text-center info-header">Amount</th>
                                                <th class="text-center info-header">Sunday Duty</th>
                                                <th class="text-center info-header">Amount</th>
                                                <th class="text-center info-header">Special Holiday</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Overtime -->
                                                <th class="text-center info-header">No. Hrs</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Late -->
                                                <th class="text-center info-header">Minutes</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Deduction sub-headers -->
                                                <?php if (count($contributions_settings) > 0) {
                                                    foreach ($contributions_settings as $k) {
                                                        if ($k['type'] == 1) {
                                                            $query_con = "SELECT * FROM contributions WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['contribution'];
                                                        } elseif ($k['type'] == 3) {
                                                            $query_con = "SELECT * FROM contribution_loan_types WHERE clt_id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['loan_type'];
                                                        } elseif ($k['type'] == 2) {
                                                            $query_con = "SELECT * FROM deductions WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['deduction'];
                                                        }
                                                ?>
                                                        <th class="text-center danger-header"><?= htmlspecialchars($name_deduction) ?></th>
                                                <?php } } ?>
                                                <th class="text-center danger-header">SSS Provident Fund</th>
                                                <th class="text-center danger-header">JEI Advance</th>
                                                <th class="text-center danger-header">JCC Advances</th>
                                                <th class="text-center danger-header">Tax</th>
                                                <!-- Refund sub-headers -->
                                                <?php if (count($refunds_settings) > 0) {
                                                    foreach ($refunds_settings as $k) {
                                                        $query_con = "SELECT * FROM refunds WHERE id = ?";
                                                        $stmt_con = $conn->prepare($query_con);
                                                        $stmt_con->bind_param("i", $k['id']);
                                                        $stmt_con->execute();
                                                        $rfund = $stmt_con->get_result()->fetch_assoc();
                                                ?>
                                                        <th class="text-center success-header"><?= htmlspecialchars($rfund['refunds']) ?></th>
                                                <?php } } ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_number_days = 0;
                                            $t_basic_rate = 0;
                                            $t_per_day = 0;
                                            $t_allowance = 0;
                                            $t_gross = 0;
                                            $t_deduction = 0;
                                            $t_net = 0;
                                            $t_perDay = 0;
                                            $t_total_amount = 0;
                                            $t_ot_rate = 0;
                                            $t_late = 0;
                                            // ── full column totals ──
                                            $t_monthly = 0; $t_quinsena = 0;
                                            $t_allow_days = 0; $t_allow_rate = 0; $t_allow_total = 0;
                                            $t_absent_days = 0; $t_absent_amt = 0;
                                            $t_legal_d = 0; $t_legal_amt = 0;
                                            $t_sun_d = 0; $t_sun_amt = 0;
                                            $t_spc_d = 0; $t_spc_amt = 0;
                                            $t_ot_hrs = 0; $t_ot_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_sss_fund = 0; $t_jei = 0; $t_jcc = 0; $t_tax = 0;
                                            $t_contrib = []; $t_refund = [];

                                            while ($row = $query->fetch_assoc()) {
                                                $i++;
                                                $minutesPerDay = 8 * 60;
                                                $perMinute = $row['per_day'] / $minutesPerDay;
                                                $employee_id = $row['employee_id'];
                                                $total_basic_rate = $row['basic_pay'];
                                                $overtime_amount = $row['ot'] * $row['ot_rate'];
                                                $t_ot_rate += $row['ot_rate'];
                                                $late_amount = $row['late'] * $perMinute;
                                                $t_late += $perMinute;
                                                $undertime_amount = $row['under_time'] * $perMinute;
                                                $allowance_amount = $row['allowance_amount'];
                                                $allowance_days = $row['allowance_days'];
                                                $total_allowance = $allowance_amount *  $allowance_days;
                                                $tax = $row['tax'];
                                                $absent_amount = $row['absent'] *  $row['per_day'];
                                                $jei_advances = $row['jei_advances'];
                                                $jcc_advances = $row['jcc_advances'];
                                                $sss_fund = $row['sss_fund'];
                                                $perDay = $row['per_day'];
                                                $t_perDay += $perDay;
                                                $legal_holiday = $row['legal_holiday'];
                                                $legal_holiday_amount =  $legal_holiday * $perDay;
                                                $sunday_duty = $row['sunday_duty'];
                                                $sunday_duty_amount =  $sunday_duty * $perDay;
                                                $special_holiday = $row['special_holiday'];
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                $total_amount =  ($total_basic_rate    +  $total_allowance - $absent_amount) / 2;
                                                $t_total_amount += $total_amount;
                                                $gross_salary =  ($total_amount +   $overtime_amount   +  $legal_holiday_amount + $sunday_duty_amount +  $special_holiday_amount - $late_amount);

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;



                                                $total_number_days += $row['present'];
                                                $t_basic_rate += $total_basic_rate;
                                                $t_per_day += $perDay;
                                                $t_allowance += $allowance_amount;
                                                $t_gross += $gross_salary;
                                                // ── per-column totals ──
                                                $t_monthly     += $row['basic_pay'];
                                                $t_quinsena    += $row['basic_pay'] / 2;
                                                $t_allow_days  += $allowance_days;
                                                $t_allow_rate  += $allowance_amount;
                                                $t_allow_total += $total_allowance;
                                                $t_absent_days += $row['absent'];
                                                $t_absent_amt  += $absent_amount;
                                                $t_legal_d     += $legal_holiday;
                                                $t_legal_amt   += $legal_holiday_amount;
                                                $t_sun_d       += $sunday_duty;
                                                $t_sun_amt     += $sunday_duty_amount;
                                                $t_spc_d       += $special_holiday;
                                                $t_spc_amt     += $special_holiday_amount;
                                                $t_ot_hrs      += $row['ot'];
                                                $t_ot_amt      += $overtime_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_sss_fund    += $sss_fund;
                                                $t_jei         += $jei_advances;
                                                $t_jcc         += $jcc_advances;
                                                $t_tax         += $tax;

                                            ?>
                                                <tr class="name-<?= $row['id'] ?>" data-row-id="<?= $row['id'] ?>">
                                                    <td class="ps-chk-cell"><input type="checkbox" class="ps-chk ps-row-chk" value="<?= $row['id'] ?>"></td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>
                                                    <td style="min-width:220px;">
                                                        <?php $emp_initials = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1)); ?>
                                                        <div class="emp-cell">
                                                            <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-avatar" title="View employee details"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-name-link" title="View employee details">
                                                                    <i class="ri-user-3-line"></i><b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                                                                </a>
                                                                <div class="emp-no"><i class="ri-bank-card-line"></i><?= htmlspecialchars($row['employee_no']) ?></div>
                                                                <div data-badges="<?= $row['id'] ?>">
                                                                    <?php if ($row['absent'] > 0): ?><span class="pay-badge absent"><?= $row['absent'] ?> absent</span><?php endif; ?>
                                                                    <?php if ($row['late'] > 0): ?><span class="pay-badge late"><?= number_format($row['late']) ?> min late</span><?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="min-width: 200px;" class="text-center">
                                                        <?= $row['position'] ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b><?= number_format($row['basic_pay'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b><?= number_format($row['basic_pay'] / 2, 2) ?></b>
                                                    </td>
                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class" placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_days')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['allowance_days'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b data-computed="allowance_amount"><?= number_format($allowance_amount, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="allowance_total"><?= number_format($total_allowance, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <!-- <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $allowance_amount ?>" class="form-control input-class" placeholder="Allowance" aria-label="Allowance" aria-describedby="basic-addon2">
                                                                <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_amount')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div>
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($allowance_amount, 2) ?></b>
                                                        <?php } ?>
                                                    </td> -->
                                                    <!-- <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($allowance_amount / 2, 2) ?></b>
                                                    </td> -->
                                                    <!-- Basic Daily Rate -->
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['per_day'], 2) ?></b>
                                                    </td>
                                                    <!-- Allowance Daily Rate -->
                                                    <!-- <td class="text-right"><?= number_format($allowance_amount / 26, 2) ?></td> -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class" placeholder="No. of Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'present')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <?= $row['present'] ?>
                                                        <?php } ?>
                                                    </td>

                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['absent'] ?>" data-id="<?= $row['id'] ?>" data-type="absent" class="form-control input-class" placeholder="absent" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'absent')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['absent'] ?></b>
                                                        <?php } ?>
                                                    </td>

                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="absent_amount"><?= number_format($absent_amount, 2) ?></b>
                                                    </td>
                                                    <!-- total amount -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="total_amount"><?= number_format($total_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['legal_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="legal_holiday" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'legal_holiday')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['legal_holiday'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                         <b data-computed="legal_amount"><?= number_format($legal_holiday_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['sunday_duty'] ?>" data-id="<?= $row['id'] ?>" data-type="sunday_duty" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'sunday_duty')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['sunday_duty'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="sunday_amount"><?= number_format($sunday_duty_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['special_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="special_holiday" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'special_holiday')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['special_holiday'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="special_amount"><?= number_format($special_holiday_amount, 2) ?></b>
                                                    </td>
                                                    <!-- ot -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'ot')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['ot'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['ot_rate'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="overtime_amount"><?= number_format($overtime_amount, 2) ?></b>
                                                    </td>

                                                    <!-- /ot -->

                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'late')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['late'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b><?= number_format($perMinute, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="late_amount"><?= number_format($late_amount, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="gross"><?= number_format($gross_salary, 2) ?></b>
                                                    </td>
                                                    <?php

                                                    if (count($contributions_settings) > 0) {
                                                        foreach ($contributions_settings as $i2 =>  $k) {
                                                            $deduction_amount = 0;
                                                            if ($k['type'] == 1) {
                                                                foreach ($contributions as $kd) {
                                                                    if ($kd["contribution_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 2) {
                                                                foreach ($deductions as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 3) {
                                                                foreach ($loans as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }

                                                            $total_deductions += $deduction_amount;
                                                            $t_contrib[$k['id']] = ($t_contrib[$k['id']] ?? 0) + $deduction_amount;


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                            <button
                                                                                onclick="updateData(this, <?= $row['id'] ?>, '<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>', <?= $k['id'] ?>)"
                                                                                class="btn btn-success"
                                                                                data-toggle="tooltip"
                                                                                title="Save Changes"
                                                                                type="button">
                                                                                <i class="ri-save-fill"></i>
                                                                            </button>
                                                                        </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($deduction_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                        <?php } ?>
                                                    <?php  } else { ?>

                                                    <?php } ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $sss_fund ?>" data-id="<?= $row['id'] ?>" data-type="sss_fund" class="form-control input-class" placeholder="Enter Amount" aria-label="sss_fund" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'sss_fund')"   class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($sss_fund, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jei_advances ?>" data-id="<?= $row['id'] ?>" data-type="jei_advances" class="form-control input-class" placeholder="Enter Amount" aria-label="jei_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jei_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jei_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jcc_advances ?>" data-id="<?= $row['id'] ?>" data-type="jcc_advances" class="form-control input-class" placeholder="Enter Amount" aria-label="jcc_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jcc_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jcc_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>

                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $tax ?>" class="form-control input-class" data-id="<?= $row['id'] ?>" data-type="tax" placeholder="Enter Amount" aria-label="Other Deduction" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'tax')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($tax, 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <?php $total_deductions = $total_deductions   + $tax + $jei_advances + $jcc_advances + $sss_fund;
                                                    $t_deduction += $total_deductions;  ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($total_deductions, 2) ?></b>
                                                    </td>
                                                    <!-- refunds -->
                                                    <?php if (count($refunds_settings) > 0) {;
                                                        foreach ($refunds_settings as $kd) {
                                                            $refund_amount = 0;
                                                            foreach ($refunds as $cd) {
                                                                if ($cd["refund_id"] == $kd["id"]) {
                                                                    $refund_amount = $cd["amount"];
                                                                }
                                                            }
                                                            $total_refunds += $refund_amount;
                                                            $t_refund[$kd['id']] = ($t_refund[$kd['id']] ?? 0) + $refund_amount;





                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                        <button
                                                                            onclick="updateData(this, <?= $row['id'] ?>, 'refund', <?= $kd['id'] ?>)"
                                                                            class="btn btn-success"
                                                                            data-toggle="tooltip"
                                                                            title="Save Changes"
                                                                            type="button">
                                                                            <i class="ri-save-fill"></i>
                                                                        </button>
                                                                    </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($refund_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                    <?php
                                                        }
                                                    } ?>
                                                    <?php

                                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                                    $t_net += $net;
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <a href="view_payslip.php?id=<?= $row['id'] ?>" class="xl-btn" data-toggle="tooltip" title="View Payslip" onclick="window.open(this.href,'_blank','width=900,height=700,scrollbars=yes'); return false;">
                                                            <i class="ri-file-text-line"></i> View
                                                        </a>
                                                    </td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />
                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th></th>
                                                <th></th>
                                                <th class="text-center">TOTAL</th>
                                                <th></th>
                                                <th class="text-right"><?= number_format($t_monthly, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_quinsena, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_allow_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_total, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_perDay, 2) ?></th>
                                                <th class="text-center"><?= number_format($total_number_days, 0) ?></th>
                                                <th class="text-center"><?= number_format($t_absent_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_absent_amt, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_total_amount, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_legal_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_legal_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_sun_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_sun_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_spc_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_spc_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ot_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_late_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_late_amt, 2) ?></th>
                                                <th class="text-right" id="tfoot-gross"><?= number_format($t_gross, 2) ?></th>
                                                <?php foreach ($contributions_settings as $k): ?>
                                                <th class="text-right"><?= number_format($t_contrib[$k['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_sss_fund, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jei, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jcc, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_tax, 2) ?></th>
                                                <th class="text-right" id="tfoot-deduct"><?= number_format($t_deduction, 2) ?></th>
                                                <?php foreach ($refunds_settings as $kd): ?>
                                                <th class="text-right"><?= number_format($t_refund[$kd['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>




                                <?php } else { ?>
                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="text-center primary-header ps-chk-cell"><input type="checkbox" class="ps-chk" id="chk-all" title="Select all"></th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                                <th rowspan="2" class="text-center primary-header">Name</th>
                                                <th rowspan="2" class="text-center primary-header">Position</th>
                                                <th rowspan="2" class="text-center  primary-header">No. of Days</th>
                                                <th rowspan="2" class="text-center  primary-header">Basic Rate</th>
                                                <th rowspan="2" class="text-center  primary-header">Total Basic Rate</th>
                                                <th colspan="3" class="text-center  info-header">Allowance</th>
                                                <th colspan="3" class="text-center info-header">Overtime</th>
                                                <th colspan="3" class="text-center info-header">Late</th>
                                                <th rowspan="2" class="text-center success-header">GROSS SALARY</th>
                                                <th colspan="<?= count($contributions_settings) ?>" class="text-center danger-header">Deduction</th>
                                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                                <?php if (count($refunds_settings) > 0) { ?>
                                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center primary-header">Refunds</th>
                                                <?php } ?>
                                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                                <th rowspan="2" class="text-center  primary-header">Actions</th>
                                                <th rowspan="2" class="text-center  primary-header">No.</th>
                                            </tr>
                                            <tr>
                                                <th class="text-center  info-header">No. dys</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <th class="text-center  info-header">No. hr</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <th class="text-center  info-header">Min</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <?php if (count($contributions_settings) > 0) {
                                                    foreach ($contributions_settings as $k) {
                                                        if ($k['type'] == 1) {
                                                            $query_con = "SELECT * FROM contributions   WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction = $contribution['contribution'];
                                                        } else if ($k['type'] == 3) {
                                                            $query_con = "SELECT * FROM contribution_loan_types   WHERE clt_id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction =  $contribution['loan_type'];
                                                        } else if ($k['type'] == 2) {
                                                            $query_con = "SELECT * FROM deductions   WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction =  $contribution['deduction'];
                                                        }


                                                ?>
                                                        <th class="text-center danger-header"><?= $name_deduction ?></th>
                                                    <?php } ?>
                                                <?php } else { ?>
                                                <?php } ?>
                                                <!-- <th class="text-center  danger-header">SSS PROVIDENT FUND </th>
                                                <th class="text-center  danger-header">JEI ADVANCE</th>
                                                <th class="text-center  danger-header">JCC ADVANCES</th> -->
                                                <?php if (count($refunds_settings) > 0) {
                                                    foreach ($refunds_settings as $k) {
                                                        $query_con = "SELECT * FROM refunds   WHERE id = ?";
                                                        $stmt_con = $conn->prepare($query_con);
                                                        $stmt_con->bind_param("i", $k['id']);
                                                        $stmt_con->execute();
                                                        $result_con = $stmt_con->get_result();
                                                        $rfund = $result_con->fetch_assoc();
                                                        $name_refunds =  $rfund['refunds'];

                                                ?>
                                                        <th class="text-center success-header"><?= $name_refunds ?></th>
                                                <?php }
                                                } ?>

                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_number_days = 0;
                                            $t_basic_rate = 0;
                                            $t_per_day = 0;
                                            $t_allowance = 0;
                                            $t_gross = 0;
                                            $t_deduction = 0;
                                            $t_net = 0;
                                            $t_perDay = 0;
                                            $t_total_amount = 0;
                                            $t_ot_rate = 0;
                                            $t_late = 0;
                                            // ── full column totals ──
                                            $t_monthly = 0; $t_quinsena = 0;
                                            $t_allow_days = 0; $t_allow_rate = 0; $t_allow_total = 0;
                                            $t_absent_days = 0; $t_absent_amt = 0;
                                            $t_legal_d = 0; $t_legal_amt = 0;
                                            $t_sun_d = 0; $t_sun_amt = 0;
                                            $t_spc_d = 0; $t_spc_amt = 0;
                                            $t_ot_hrs = 0; $t_ot_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_sss_fund = 0; $t_jei = 0; $t_jcc = 0; $t_tax = 0;
                                            $t_contrib = []; $t_refund = [];

                                            while ($row = $query->fetch_assoc()) {
                                                $i++;
                                                $minutesPerDay = 8 * 60;
                                                $perMinute = $row['per_day'] / $minutesPerDay;
                                                $employee_id = $row['employee_id'];
                                                $total_basic_rate = $row['present'] * $row['per_day'];
                                                $overtime_amount = $row['ot'] * $row['ot_rate'];
                                                $t_ot_rate += $row['ot_rate'];
                                                $late_amount = $row['late'] * $perMinute;
                                                $t_late += $perMinute;
                                                $undertime_amount = $row['under_time'] * $perMinute;
                                                $allowance_amount = $row['allowance_amount'];
                                                $allowance_days = $row['allowance_days'];
                                                $total_allowance = $allowance_amount *  $allowance_days;
                                                $tax = $row['tax'];
                                                $absent_amount = $row['absent'] *  $row['per_day'];
                                                $jei_advances = $row['jei_advances'];
                                                $jcc_advances = $row['jcc_advances'];
                                                $sss_fund = $row['sss_fund'];
                                                $perDay = $row['per_day'];
                                                $t_perDay += $perDay;
                                                $legal_holiday = $row['legal_holiday'];
                                                $legal_holiday_amount =  $legal_holiday * $perDay;
                                                $sunday_duty = $row['sunday_duty'];
                                                $sunday_duty_amount =  $sunday_duty * $perDay;
                                                $special_holiday = $row['special_holiday'];
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                $total_amount =  ($total_basic_rate    +  $total_allowance - $absent_amount) / 2;
                                                $t_total_amount += $total_amount;
                                                $gross_salary =  (($total_basic_rate +   $overtime_amount   +  $total_allowance)   - $late_amount);

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;


                                                $total_number_days += $row['present'];
                                                $t_basic_rate += $total_basic_rate;
                                                $t_per_day += $perDay;
                                                $t_allowance += $allowance_amount;
                                                $t_gross += $gross_salary;
                                                // ── per-column totals ──
                                                $t_monthly     += $row['basic_pay'];
                                                $t_quinsena    += $row['basic_pay'] / 2;
                                                $t_allow_days  += $allowance_days;
                                                $t_allow_rate  += $allowance_amount;
                                                $t_allow_total += $total_allowance;
                                                $t_absent_days += $row['absent'];
                                                $t_absent_amt  += $absent_amount;
                                                $t_legal_d     += $legal_holiday;
                                                $t_legal_amt   += $legal_holiday_amount;
                                                $t_sun_d       += $sunday_duty;
                                                $t_sun_amt     += $sunday_duty_amount;
                                                $t_spc_d       += $special_holiday;
                                                $t_spc_amt     += $special_holiday_amount;
                                                $t_ot_hrs      += $row['ot'];
                                                $t_ot_amt      += $overtime_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_sss_fund    += $sss_fund;
                                                $t_jei         += $jei_advances;
                                                $t_jcc         += $jcc_advances;
                                                $t_tax         += $tax;

                                            ?>
                                                <tr class="name-<?= $row['id'] ?>" data-row-id="<?= $row['id'] ?>">
                                                    <td class="ps-chk-cell"><input type="checkbox" class="ps-chk ps-row-chk" value="<?= $row['id'] ?>"></td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>
                                                    <td style="min-width:220px;">
                                                        <?php $emp_initials = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1)); ?>
                                                        <div class="emp-cell">
                                                            <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-avatar" title="View employee details"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>"  class="emp-name-link" title="View employee details">
                                                                    <i class="ri-user-3-line"></i><b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                                                                </a>
                                                                <div class="emp-no"><i class="ri-bank-card-line"></i><?= htmlspecialchars($row['employee_no']) ?></div>
                                                                <div data-badges="<?= $row['id'] ?>">
                                                                    <?php if ($row['absent'] > 0): ?><span class="pay-badge absent"><?= $row['absent'] ?> absent</span><?php endif; ?>
                                                                    <?php if ($row['late'] > 0): ?><span class="pay-badge late"><?= number_format($row['late']) ?> min late</span><?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="min-width: 200px;" class="text-center">
                                                        <?= $row['position'] ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class" placeholder="No. of Days" aria-describedby="basic-addon2">
                                                            </div>
                                                        <?php } else { ?>
                                                            <?= $row['present'] ?>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['per_day'] ?>" data-id="<?= $row['id'] ?>" data-type="per_day" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($row['per_day'], 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b data-computed="total_basic_rate"><?= number_format($total_basic_rate, 2) ?></b>
                                                    </td>
                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class" placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_days')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['allowance_days'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b data-computed="allowance_amount"><?= number_format($allowance_amount, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="allowance_total"><?= number_format($total_allowance, 2) ?></b>
                                                    </td>


                                                    <!-- ot -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'ot')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['ot'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['ot_rate'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="overtime_amount"><?= number_format($overtime_amount, 2) ?></b>
                                                    </td>

                                                    <!-- /ot -->

                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'late')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['late'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b><?= number_format($perMinute, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="late_amount"><?= number_format($late_amount, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="gross"><?= number_format($gross_salary, 2) ?></b>
                                                    </td>
                                                    <?php

                                                    if (count($contributions_settings) > 0) {
                                                        foreach ($contributions_settings as $i2 =>  $k) {
                                                            $deduction_amount = 0;
                                                            if ($k['type'] == 1) {
                                                                foreach ($contributions as $kd) {
                                                                    if ($kd["contribution_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 2) {
                                                                foreach ($deductions as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 3) {
                                                                foreach ($loans as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }

                                                            $total_deductions += $deduction_amount;
                                                            $t_contrib[$k['id']] = ($t_contrib[$k['id']] ?? 0) + $deduction_amount;


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                            <button
                                                                                onclick="updateData(this, <?= $row['id'] ?>, '<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>', <?= $k['id'] ?>)"
                                                                                class="btn btn-success"
                                                                                data-toggle="tooltip"
                                                                                title="Save Changes"
                                                                                type="button">
                                                                                <i class="ri-save-fill"></i>
                                                                            </button>
                                                                        </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($deduction_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                        <?php } ?>
                                                    <?php  } else { ?>

                                                    <?php } ?>
                                                    <?php $total_deductions = $total_deductions;
                                                    $t_deduction += $total_deductions;  ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($total_deductions, 2) ?></b>
                                                    </td>
                                                    <!-- refunds -->
                                                    <?php if (count($refunds_settings) > 0) {;
                                                        foreach ($refunds_settings as $kd) {
                                                            $refund_amount = 0;
                                                            foreach ($refunds as $cd) {
                                                                if ($cd["refund_id"] == $kd["id"]) {
                                                                    $refund_amount = $cd["amount"];
                                                                }
                                                            }
                                                            $total_refunds += $refund_amount;
                                                            $t_refund[$kd['id']] = ($t_refund[$kd['id']] ?? 0) + $refund_amount;

                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                    <?php } else { ?>
                                                                        <b><?= number_format($refund_amount, 2) ?></b>
                                                                    <?php } ?>
                                                            </td>
                                                    <?php
                                                        }
                                                    } ?>
                                                    <?php

                                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                                    $t_net += $net;
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <a href="view_payslip.php?id=<?= $row['id'] ?>" class="xl-btn" data-toggle="tooltip" title="View Payslip" onclick="window.open(this.href,'_blank','width=900,height=700,scrollbars=yes'); return false;">
                                                            <i class="ri-file-text-line"></i> View
                                                        </a>
                                                    </td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />

                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th></th>
                                                <th></th>
                                                <th class="text-center">TOTAL</th>
                                                <th></th>
                                                <th class="text-center"><?= number_format($total_number_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_per_day, 2) ?></th>
                                                <th class="text-right" id="tfoot-basic-rate"><?= number_format($t_basic_rate, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_allow_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_total, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ot_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_late_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_late_amt, 2) ?></th>
                                                <th class="text-right" id="tfoot-gross"><?= number_format($t_gross, 2) ?></th>
                                                <?php foreach ($contributions_settings as $k): ?>
                                                <th class="text-right"><?= number_format($t_contrib[$k['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right" id="tfoot-deduct"><?= number_format($t_deduction, 2) ?></th>
                                                <?php foreach ($refunds_settings as $kd): ?>
                                                <th class="text-right"><?= number_format($t_refund[$kd['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                <?php } ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- end page title -->
        </div>
        <!-- container-fluid -->
    </div>
    <!-- End Page-content -->

</div>

<!-- ── Version History Offcanvas ──────────────────────── -->
<style>
#offcanvas-history { width: 420px; }
.vh-header {
    background: linear-gradient(135deg, #176358 0%, #219688 100%);
    padding: 16px 18px 0;
    color: #fff;
}
.vh-header-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px; }
.vh-title { font-size:15px; font-weight:800; letter-spacing:.2px; }
.vh-subtitle { font-size:11px; color:rgba(255,255,255,.7); margin-top:2px; }
.vh-count-badge {
    background:rgba(255,255,255,.2); color:#fff;
    font-size:11px; font-weight:700;
    border-radius:20px; padding:3px 10px;
    backdrop-filter:blur(4px);
}
.vh-tabs {
    display:flex; gap:0; border-bottom: none; margin-top:4px;
}
.vh-tab {
    padding:8px 14px; font-size:11px; font-weight:600;
    color:rgba(255,255,255,.65); cursor:pointer;
    border-bottom:2px solid transparent;
    transition:all .15s; white-space:nowrap;
}
.vh-tab.active { color:#fff; border-bottom-color:#fff; }
.vh-tab:hover:not(.active) { color:rgba(255,255,255,.9); }
.vh-search-bar {
    padding:10px 14px; background:#f8fffe;
    border-bottom:1px solid #e1dfdd;
}
.vh-search-input {
    width:100%; border:1px solid #c8e6e2; border-radius:6px;
    padding:6px 10px 6px 32px; font-size:12px; color:#323130;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23a19f9d' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
    outline:none;
}
.vh-search-input:focus { border-color:#219688; }
.vh-date-group-label {
    padding:8px 16px 4px;
    font-size:10px; font-weight:800;
    color:#219688; text-transform:uppercase; letter-spacing:.6px;
    background:#f4fbfa; border-bottom:1px solid #e6f5f3;
}
.vh-entry {
    display:flex; gap:12px; padding:12px 16px;
    border-bottom:1px solid #f0f0f0;
    transition:background .12s; position:relative;
}
.vh-entry:hover { background:#f9fffe; }
.vh-entry.is-latest { background:#f0fdf9; }
.vh-entry.is-latest::before {
    content:''; position:absolute; left:0; top:0; bottom:0;
    width:3px; border-radius:0 2px 2px 0;
    background:#219688;
}
.vh-timeline {
    display:flex; flex-direction:column; align-items:center;
    padding-top:3px; flex-shrink:0;
}
.vh-dot {
    width:11px; height:11px; border-radius:50%; flex-shrink:0;
    border:2px solid transparent; transition:transform .15s;
}
.vh-entry:hover .vh-dot { transform:scale(1.2); }
.vh-line { width:2px; flex:1; min-height:18px; background:#e8e8e8; margin-top:4px; border-radius:2px; }
.vh-content { flex:1; min-width:0; }
.vh-event-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:5px; }
.vh-event-icon {
    width:28px; height:28px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; font-size:13px;
}
.vh-event-text { font-size:12px; font-weight:600; color:#323130; flex:1; min-width:0; }
.vh-latest-badge {
    font-size:9px; font-weight:800; letter-spacing:.3px;
    background:#219688; color:#fff;
    border-radius:20px; padding:2px 7px;
    flex-shrink:0;
}
.vh-meta { display:flex; align-items:center; gap:8px; }
.vh-avatar {
    width:20px; height:20px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:8px; font-weight:800; flex-shrink:0;
}
.vh-user { font-size:11px; color:#605e5c; }
.vh-time-block { margin-left:auto; text-align:right; flex-shrink:0; }
.vh-rel-time { font-size:11px; font-weight:600; color:#323130; }
.vh-abs-time { font-size:10px; color:#a19f9d; }
.vh-empty { padding:48px 24px; text-align:center; color:#a19f9d; }
.vh-empty i { font-size:40px; display:block; margin-bottom:10px; opacity:.4; }
.vh-empty-title { font-size:13px; font-weight:600; color:#605e5c; margin-bottom:4px; }
</style>

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas-history">
    <!-- Header -->
    <div class="vh-header">
        <div class="vh-header-top">
            <div>
                <div class="vh-title"><i class="ri-history-line me-2"></i>Version History</div>
                <div class="vh-subtitle" id="vh-subtitle">Payroll change log</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="vh-count-badge" id="vh-count">—</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
            </div>
        </div>
        <!-- Filter tabs -->
        <div class="vh-tabs" id="vh-tabs">
            <div class="vh-tab active" data-filter="all" onclick="vhFilter('all',this)">All</div>
            <div class="vh-tab" data-filter="calc"   onclick="vhFilter('calc',this)">Calculate</div>
            <div class="vh-tab" data-filter="lock"   onclick="vhFilter('lock',this)">Lock</div>
            <div class="vh-tab" data-filter="update" onclick="vhFilter('update',this)">Updates</div>
        </div>
    </div>

    <!-- Search -->
    <div class="vh-search-bar">
        <input class="vh-search-input" id="vh-search" placeholder="Search history…" oninput="vhSearch(this.value)">
    </div>

    <!-- Body -->
    <div class="offcanvas-body p-0" id="offcanvas-history-body" style="overflow-y:auto; background:#fff;"></div>
</div>

<script>
var _vhData = [];
var _vhFilter = 'all';
var _vhSearch = '';

var actionMeta = {
    created:    { color:'#219688', bg:'#e6f5f3', icon:'ri-add-circle-line',       label:'Created'    },
    calculated: { color:'#2563eb', bg:'#dbeafe', icon:'ri-calculator-line',        label:'Calculated' },
    locked:     { color:'#7c3aed', bg:'#ede9fe', icon:'ri-lock-line',              label:'Locked'     },
    unlocked:   { color:'#059669', bg:'#d1fae5', icon:'ri-lock-unlock-line',       label:'Unlocked'   },
    approved:   { color:'#16a34a', bg:'#dcfce7', icon:'ri-checkbox-circle-line',   label:'Approved'   },
    updated:    { color:'#d97706', bg:'#fef3c7', icon:'ri-edit-line',              label:'Updated'    },
    printed:    { color:'#db2777', bg:'#fce7f3', icon:'ri-printer-line',           label:'Printed'    },
    def:        { color:'#6b7280', bg:'#f3f4f6', icon:'ri-time-line',             label:'Event'      }
};

function vhGetMeta(d) {
    d = (d || '').toLowerCase();
    if (d.includes('creat'))   return actionMeta.created;
    if (d.includes('calc'))    return actionMeta.calculated;
    if (d.includes('unlock'))  return actionMeta.unlocked;
    if (d.includes('lock'))    return actionMeta.locked;
    if (d.includes('approv'))  return actionMeta.approved;
    if (d.includes('print'))   return actionMeta.printed;
    if (d.includes('updat') || d.includes('edit') || d.includes('save')) return actionMeta.updated;
    return actionMeta.def;
}

function vhRelTime(dateStr) {
    var diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)     return 'Just now';
    if (diff < 3600)   return Math.floor(diff/60) + 'm ago';
    if (diff < 86400)  return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}

function vhDateGroup(dateStr) {
    var d = new Date(dateStr), now = new Date();
    var diffDays = Math.floor((now - d) / 86400000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7)  return 'This Week';
    if (diffDays < 30) return 'This Month';
    return d.toLocaleDateString('en-US',{month:'long',year:'numeric'});
}

function vhFilter(f, el) {
    _vhFilter = f;
    document.querySelectorAll('.vh-tab').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    vhRender();
}

function vhSearch(q) {
    _vhSearch = q.toLowerCase();
    vhRender();
}

function vhRender() {
    var body = document.getElementById('offcanvas-history-body');
    var data = _vhData.filter(function(row) {
        var d = (row.details || '').toLowerCase();
        var matchFilter = true;
        if (_vhFilter === 'calc')   matchFilter = d.includes('calc');
        if (_vhFilter === 'lock')   matchFilter = d.includes('lock');
        if (_vhFilter === 'update') matchFilter = d.includes('updat') || d.includes('edit') || d.includes('save');
        var matchSearch = !_vhSearch || d.includes(_vhSearch) || (row.name||'').toLowerCase().includes(_vhSearch);
        return matchFilter && matchSearch;
    });

    if (data.length === 0) {
        body.innerHTML = '<div class="vh-empty"><i class="ri-search-line"></i><div class="vh-empty-title">No events found</div><div>Try a different filter or search term</div></div>';
        return;
    }

    var html = '';
    var lastGroup = null;

    data.forEach(function(row, i) {
        var m = vhGetMeta(row.details);
        var initials = (row.name || '?').trim().split(' ').map(function(w){return w[0]||'';}).join('').substring(0,2).toUpperCase();
        var isFirst = (i === 0 && _vhFilter === 'all' && !_vhSearch);
        var ts = new Date(row.created_at);
        var timeStr = ts.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
        var absDate = ts.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        var group = vhDateGroup(row.created_at);

        if (group !== lastGroup) {
            html += '<div class="vh-date-group-label"><i class="ri-calendar-line me-1"></i>' + group + '</div>';
            lastGroup = group;
        }

        html += '<div class="vh-entry' + (isFirst ? ' is-latest' : '') + '">';

        // Timeline dot
        html += '<div class="vh-timeline">';
        html += '<div class="vh-dot" style="background:' + m.color + ';border-color:' + m.color + '44;"></div>';
        if (i < data.length - 1) html += '<div class="vh-line"></div>';
        html += '</div>';

        // Content
        html += '<div class="vh-content">';

        // Event row
        html += '<div class="vh-event-row">';
        html += '<div class="vh-event-icon" style="background:' + m.bg + ';"><i class="' + m.icon + '" style="color:' + m.color + ';"></i></div>';
        html += '<div class="vh-event-text">' + (row.details || '—') + '</div>';
        if (isFirst) html += '<span class="vh-latest-badge">LATEST</span>';
        html += '</div>';

        // Meta: avatar + user + time
        html += '<div class="vh-meta">';
        html += '<div class="vh-avatar" style="background:' + m.color + '22;border:1px solid ' + m.color + '44;color:' + m.color + ';">' + initials + '</div>';
        html += '<span class="vh-user">' + (row.name || '—') + '</span>';
        html += '<div class="vh-time-block" title="' + absDate + ' ' + timeStr + '">';
        html += '<div class="vh-rel-time">' + vhRelTime(row.created_at) + '</div>';
        html += '<div class="vh-abs-time">' + absDate + ' · ' + timeStr + '</div>';
        html += '</div>';
        html += '</div>';

        html += '</div></div>';
    });

    body.innerHTML = html;
}

function openPayrollHistory(id) {
    _vhFilter = 'all';
    _vhSearch = '';
    document.querySelectorAll('.vh-tab').forEach(function(t){ t.classList.remove('active'); });
    var allTab = document.querySelector('.vh-tab[data-filter="all"]');
    if (allTab) allTab.classList.add('active');
    var searchEl = document.getElementById('vh-search');
    if (searchEl) searchEl.value = '';

    var body = document.getElementById('offcanvas-history-body');
    body.innerHTML = '<div class="vh-empty"><i class="ri-loader-4-line" style="animation:spin 1s linear infinite;"></i><div class="vh-empty-title">Loading history…</div></div>';
    new bootstrap.Offcanvas(document.getElementById('offcanvas-history')).show();

    $.ajax({
        url: 'ajax.php?action=payroll_history_details',
        method: 'POST',
        dataType: 'JSON',
        data: { id: id },
        success: function(res) {
            _vhData = res || [];
            document.getElementById('vh-count').textContent = _vhData.length + ' event' + (_vhData.length !== 1 ? 's' : '');
            vhRender();
        },
        error: function() {
            body.innerHTML = '<div class="vh-empty"><i class="ri-error-warning-line" style="color:#dc2626;"></i><div class="vh-empty-title" style="color:#dc2626;">Failed to load</div><div>Please try again.</div></div>';
        }
    });
}
</script>

<!-- Print Settings Modal -->
<div class="modal fade" id="modal-print-settings" tabindex="-1" role="dialog" aria-labelledby="modalPrintSettingsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title" id="modalPrintSettingsLabel"><i class="ri-settings-3-line me-2"></i>Print Settings</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form class="form-auth-small" id="form-print-settings" method="post" novalidate>
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2 fw-semibold"><i class="ri-edit-line me-1"></i>Prepared By</div>
                                <div class="card-body">
                                    <div class="form-group mb-3">
                                        <label class="form-label small text-muted">Name</label>
                                        <input class="form-control" value="<?= htmlspecialchars($payroll['prepared_by']) ?>" name="prepared_by" type="text" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label small text-muted">Position</label>
                                        <input class="form-control" name="prepared_by_role" type="text" value="<?= htmlspecialchars(isset($payroll['prepared_by_role']) && trim($payroll['prepared_by_role']) !== '' ? $payroll['prepared_by_role'] : 'PAYROLL OFFICER') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2 fw-semibold"><i class="ri-checkbox-circle-line me-1"></i>Verified By</div>
                                <div class="card-body">
                                    <div class="form-group mb-3">
                                        <label class="form-label small text-muted">Name</label>
                                        <input class="form-control" name="verified_by" value="<?= htmlspecialchars($payroll['verified_by']) ?>" type="text" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label small text-muted">Position</label>
                                        <input class="form-control" name="verified_by_role" type="text" value="<?= htmlspecialchars(isset($payroll['verified_by_role']) && trim($payroll['verified_by_role']) !== '' ? $payroll['verified_by_role'] : 'Compensation & Benefits Supervisor') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border h-100">
                                <div class="card-header bg-light py-2 fw-semibold"><i class="ri-shield-check-line me-1"></i>Approved By</div>
                                <div class="card-body">
                                    <div class="form-group mb-3">
                                        <label class="form-label small text-muted">Name</label>
                                        <input class="form-control" value="<?= htmlspecialchars(isset($payroll['approved_by']) && trim($payroll['approved_by']) !== '' ? $payroll['approved_by'] : 'Jordan G. Tiu/Jerry G. Tiu/Jean T. Chin') ?>" name="approved_by" type="text" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label small text-muted">Position</label>
                                        <input class="form-control" name="approved_by_role" type="text" value="<?= htmlspecialchars(isset($payroll['approved_by_role']) && trim($payroll['approved_by_role']) !== '' ? $payroll['approved_by_role'] : 'MANAGEMENT') ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info"><i class="ri-save-line me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modal-sites-2" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-sitesModalLabel">Sites</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table id="data-table" class="table table-hover dataTable table-custom table-striped m-b-0 c_list">
                    <thead class="thead-dark">
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Timekeeper</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (is_array($site_ids)) {
                            foreach ($site_ids as $k) {  ?>

                                <?php
                                $query_site = "SELECT A.*,  C.name AS timekeeper 
                                        FROM sites AS A 
                                        LEFT JOIN users AS C ON A.timekeeper_id = C.id 
                           
                                        WHERE A.id = ?
                                    ";
                                $stmt_site = $conn->prepare($query_site);
                                $stmt_site->bind_param("i", $k);
                                $stmt_site->execute();
                                $result_site = $stmt_site->get_result();
                                $site_details = $result_site->fetch_assoc();
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($site_details['site_code']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['site_name']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['site_address']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['timekeeper']); ?></td>
                                    <td class="text-center" width="100">
                                        <a data-toggle="tooltip" title="Print Payroll on this Site" href="print-payroll.php?id=<?= $id ?>&type=site&site_id=<?= $site_details['id'] ?>" class="btn btn-sm btn-outline-info mr-1" title="Print" onclick="window.open(this.href,'_blank','width=1100,height=750,scrollbars=yes'); return false;"><span class="sr-only">Print</span> <i class="fa fa-print"></i></a>
                                    </td>
                                </tr>
                        <?php }
                        } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
    let id = "<?= $id ?>";
    let status = "<?= $status ?>";

    function fitTableToViewport() {
        const container = document.getElementById('table-responsive2');
        if (!container) return;
        const top = container.getBoundingClientRect().top + window.scrollY;
        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const visibleTop = top - scrollTop;
        const available = window.innerHeight - visibleTop - 24;
        container.style.height = Math.max(available, 200) + 'px';
    }

    function fixStickyHeaderGap() {
        const thead = document.querySelector('#table-1 thead');
        if (!thead) return;
        const row1 = thead.querySelector('tr:first-child');
        if (!row1) return;
        const row1Height = row1.getBoundingClientRect().height;
        thead.querySelectorAll('tr:nth-child(2) th').forEach(th => {
            th.style.top = row1Height + 'px';
        });
    }

    function fixFrozenColumns() {
        const table = document.getElementById('table-1');
        if (!table) return;
        // Measure the rendered width of the first body/header cells
        const col1Ref = table.querySelector('tbody tr td:nth-child(1)')
                     || table.querySelector('thead tr:first-child th:nth-child(1)');
        const col2Ref = table.querySelector('tbody tr td:nth-child(2)')
                     || table.querySelector('thead tr:first-child th:nth-child(2)');
        if (!col1Ref) return;
        const col1W = col1Ref.getBoundingClientRect().width;
        // Col 2 (No.) sits right after the checkbox column
        table.querySelectorAll(
            'tbody td:nth-child(2), tfoot th:nth-child(2), thead tr:first-child th:nth-child(2)'
        ).forEach(el => { el.style.left = col1W + 'px'; });
        // Col 3 (Name) sits right after checkbox + No. columns
        if (col2Ref) {
            const leftFor3 = col1W + col2Ref.getBoundingClientRect().width;
            table.querySelectorAll(
                'tbody td:nth-child(3), tfoot th:nth-child(3), thead tr:first-child th:nth-child(3)'
            ).forEach(el => { el.style.left = leftFor3 + 'px'; });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        fitTableToViewport();
        fixStickyHeaderGap();
        fixFrozenColumns();

        // Pull gross + deduction totals from tfoot into summary strip
        var gross  = document.getElementById('tfoot-gross');
        var deduct = document.getElementById('tfoot-deduct');
        if (gross)  document.getElementById('stat-gross').textContent  = '₱ ' + gross.textContent.trim();
        if (deduct) document.getElementById('stat-deduct').textContent = '₱ ' + deduct.textContent.trim();
    });
    window.addEventListener('resize', () => {
        fitTableToViewport();
        fixStickyHeaderGap();
        fixFrozenColumns();
    });
</script>
<?php include 'component/add_payroll.php'; ?>