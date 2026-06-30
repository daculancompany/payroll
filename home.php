<?php
if ($login_role === 6) {
    echo "<script>location.href='dtr';</script>";
    exit;
}

// ── Stat counters ──────────────────────────────────────────────
function db_count($conn, $sql) {
    $r = $conn->query($sql);
    return ($r && $r !== true) ? (int)$r->fetch_assoc()['c'] : 0;
}
$total_employees = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE status=1");
$total_inactive  = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE status=0");
$total_clusters  = db_count($conn, "SELECT COUNT(*) AS c FROM department");
$total_positions = db_count($conn, "SELECT COUNT(*) AS c FROM position");
$total_users     = db_count($conn, "SELECT COUNT(*) AS c FROM users WHERE role!=1 AND status=1");
$total_payrolls  = db_count($conn, "SELECT COUNT(*) AS c FROM payroll");
$pending_dtr     = db_count($conn, "SELECT COUNT(*) AS c FROM DTR WHERE status=1");
$approved_dtr    = db_count($conn, "SELECT COUNT(*) AS c FROM DTR WHERE status=2");

// ── Payroll status breakdown ────────────────────────────────────
$pay_new        = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=0");
$pay_calculated = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=1");
$pay_locked     = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=2");

// ── Employee by payroll type ────────────────────────────────────
$emp_weekly  = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE weekly_payroll=1 AND status=1");
$emp_monthly = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE weekly_payroll=0 AND status=1");

// ── Loan balance summary ────────────────────────────────────────
$loan_res      = $conn->query("SELECT COUNT(DISTINCT employee_id) AS borrowers, COALESCE(SUM(loan_balance),0) AS total FROM loans WHERE loan_status=0 AND loan_balance>0");
$loan_data     = $loan_res ? $loan_res->fetch_assoc() : ['borrowers'=>0,'total'=>0];

// ── Monthly payroll count – last 7 months ──────────────────────
$monthly_labels = []; $monthly_data = [];
$monthly_res = $conn->query("
    SELECT DATE_FORMAT(date_from,'%b %Y') AS lbl, COUNT(*) AS cnt
    FROM payroll WHERE date_from >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
    GROUP BY DATE_FORMAT(date_from,'%Y-%m') ORDER BY MIN(date_from) ASC
");
while ($r = $monthly_res->fetch_assoc()) { $monthly_labels[] = $r['lbl']; $monthly_data[] = (int)$r['cnt']; }

// ── Monthly net pay trend – last 7 months ─────────────────────
$netpay_labels = []; $netpay_data = [];
$netpay_res = $conn->query("
    SELECT DATE_FORMAT(p.date_from,'%b %Y') AS lbl, COALESCE(SUM(pi.net),0) AS total_net
    FROM payroll p
    INNER JOIN payroll_items pi ON pi.payroll_id = p.id
    WHERE p.date_from >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
    GROUP BY DATE_FORMAT(p.date_from,'%Y-%m') ORDER BY MIN(p.date_from) ASC
");
while ($r = $netpay_res->fetch_assoc()) { $netpay_labels[] = $r['lbl']; $netpay_data[] = (float)$r['total_net']; }

// ── Employees by department ─────────────────────────────────────
$dept_labels = []; $dept_data = [];
$dept_res = $conn->query("
    SELECT COALESCE(d.name,'No Dept') AS dept, COUNT(e.id) AS cnt
    FROM employee e LEFT JOIN department d ON e.department_id = d.id
    WHERE e.status=1 GROUP BY d.id, d.name ORDER BY cnt DESC LIMIT 10
");
while ($r = $dept_res->fetch_assoc()) { $dept_labels[] = $r['dept']; $dept_data[] = (int)$r['cnt']; }

// ── Top 5 positions by employee count ──────────────────────────
$pos_labels = []; $pos_data = [];
$pos_res = $conn->query("
    SELECT p.name, COUNT(e.id) AS cnt
    FROM employee e LEFT JOIN position p ON e.position_id = p.id
    WHERE e.status=1 GROUP BY p.id ORDER BY cnt DESC LIMIT 6
");
while ($r = $pos_res->fetch_assoc()) { $pos_labels[] = $r['name'] ?: 'Unassigned'; $pos_data[] = (int)$r['cnt']; }

// ── Latest locked payroll snapshot ─────────────────────────────
$snap_res = $conn->query("
    SELECT p.id, p.ref_no, p.date_from, p.date_to, e.employer_name,
        COUNT(pi.id) AS emp_count,
        COALESCE(SUM(pi.net), 0) AS total_net,
        COALESCE(SUM(
            ((pi.basic_pay + (pi.allowance_amount * pi.allowance_days) - (pi.absent * pi.per_day)) / 2)
            + (pi.ot * pi.ot_rate)
            + (pi.legal_holiday * pi.per_day)
            + (pi.sunday_duty * pi.per_day)
            + ((pi.per_day / 8 * 2.4) * pi.special_holiday)
            - (pi.late * (pi.per_day / 480))
        ), 0) AS total_gross
    FROM payroll p
    LEFT JOIN employers e ON p.employer_id = e.id
    LEFT JOIN payroll_items pi ON pi.payroll_id = p.id
    WHERE p.status = 2
    GROUP BY p.id ORDER BY p.id DESC LIMIT 1
");
$snap = $snap_res ? $snap_res->fetch_assoc() : null;

// ── Payroll by department (latest locked payroll) ──────────────
$deptpay_labels = []; $deptpay_net = []; $deptpay_emp = [];
$deptpay_gross = []; $deptpay_ded = []; $deptpay_avg = [];
if ($snap && !empty($snap['id'])) {
    $dp_res = $conn->query("
        SELECT COALESCE(d.name,'No Dept') AS dept,
               COUNT(pi.id) AS emp,
               COALESCE(SUM(pi.net),0) AS net,
               COALESCE(SUM(
                   ((pi.basic_pay + (pi.allowance_amount * pi.allowance_days) - (pi.absent * pi.per_day)) / 2)
                   + (pi.ot * pi.ot_rate)
                   + (pi.legal_holiday * pi.per_day)
                   + (pi.sunday_duty * pi.per_day)
                   + ((pi.per_day / 8 * 2.4) * pi.special_holiday)
                   - (pi.late * (pi.per_day / 480))
               ),0) AS gross,
               COALESCE(SUM(
                   pi.deduction_amount + pi.other_deduction + pi.tax
                   + pi.jei_advances + pi.jcc_advances + pi.sss_fund
               ),0) AS ded
        FROM payroll_items pi
        INNER JOIN employee e ON pi.employee_id = e.id
        LEFT JOIN department d ON e.department_id = d.id
        WHERE pi.payroll_id = " . (int)$snap['id'] . "
        GROUP BY d.id, d.name ORDER BY net DESC LIMIT 12
    ");
    while ($r = $dp_res->fetch_assoc()) {
        $emp = max(1, (int)$r['emp']);
        $deptpay_labels[] = $r['dept'];
        $deptpay_emp[]    = (int)$r['emp'];
        $deptpay_net[]    = round((float)$r['net'], 2);
        $deptpay_gross[]  = round((float)$r['gross'], 2);
        $deptpay_ded[]    = round((float)$r['ded'], 2);
        $deptpay_avg[]    = round((float)$r['net'] / $emp, 2);
    }
}

// ── Birthdays this month ────────────────────────────────────────
$bday_res = $conn->query("
    SELECT e.firstname, e.lastname, e.bday, COALESCE(d.name,'—') AS dept
    FROM employee e LEFT JOIN department d ON e.department_id = d.id
    WHERE e.status=1 AND MONTH(e.bday) = MONTH(CURDATE())
    ORDER BY DAY(e.bday) ASC LIMIT 12
");
$bdays = [];
while ($r = $bday_res->fetch_assoc()) { $bdays[] = $r; }

// ── Recent payrolls ─────────────────────────────────────────────
$recent_payrolls = $conn->query("
    SELECT p.*, e.employer_name FROM payroll p
    LEFT JOIN employers e ON p.employer_id = e.id
    ORDER BY p.id DESC LIMIT 6
");

// ── Recent DTR uploads ──────────────────────────────────────────
$recent_dtr = $conn->query("
    SELECT d.*, COALESCE(b.site_name,'—') AS site_name, COALESCE(b.site_code,'—') AS site_code, u.name AS uploader
    FROM DTR d LEFT JOIN sites b ON d.site_id = b.id LEFT JOIN users u ON d.uploaded_by = u.id
    ORDER BY d.id DESC LIMIT 6
");
?>
<style>
    .dash-stat { border-top:3px solid #009688; border-radius:6px; background:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(57,75,124,.07); transition:box-shadow .2s; }
    .dash-stat:hover { box-shadow:0 4px 16px rgba(57,75,124,.13); }
    .dash-stat .ds-icon { width:44px; height:44px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:21px; flex-shrink:0; }
    .dash-stat .ds-val { font-size:22px; font-weight:800; color:#009688; line-height:1; }
    .dash-stat .ds-lbl { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
    .dash-stat .ds-sub { font-size:11px; color:#aaa; margin-top:2px; }
    .pay-status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:4px; }
    .snap-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f0f0f0; }
    .snap-row:last-child { border-bottom:none; }
    .snap-lbl { font-size:11px; color:#888; }
    .snap-val { font-size:14px; font-weight:800; }
    .bday-item { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f5f5f5; }
    .bday-item:last-child { border-bottom:none; }
    .bday-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#219688,#176358); color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; flex-shrink:0; }
    .bday-day { margin-left:auto; background:#e8f7f5; color:#176358; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:700; white-space:nowrap; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-dashboard-line me-2" style="color:#009688;"></i>Dashboard</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Dashboard</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 1: Stat cards ── -->
            <div class="row g-3 mb-3">
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat">
                        <div class="ds-icon" style="background:#eef9f8;"><i class="ri-group-line" style="color:#009688;"></i></div>
                        <div>
                            <div class="ds-val"><?= $total_employees ?></div>
                            <div class="ds-lbl">Employees</div>
                            <div class="ds-sub"><?= $total_inactive ?> inactive</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#28a745;">
                        <div class="ds-icon" style="background:#e8f8ee;"><i class="ri-building-3-line" style="color:#28a745;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#28a745;"><?= $total_clusters ?></div>
                            <div class="ds-lbl">Departments</div>
                            <div class="ds-sub"><?= $total_clusters ?> branches</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#6f42c1;">
                        <div class="ds-icon" style="background:#f2eefb;"><i class="ri-money-dollar-circle-line" style="color:#6f42c1;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#6f42c1;"><?= $total_payrolls ?></div>
                            <div class="ds-lbl">Payrolls</div>
                            <div class="ds-sub"><?= $pay_locked ?> locked</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#fd7e14;">
                        <div class="ds-icon" style="background:#fff4ec;"><i class="ri-time-line" style="color:#fd7e14;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#fd7e14;"><?= $pending_dtr ?></div>
                            <div class="ds-lbl">Pending DTR</div>
                            <div class="ds-sub"><?= $approved_dtr ?> approved</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#17a2b8;">
                        <div class="ds-icon" style="background:#e8f7fa;"><i class="ri-shield-user-line" style="color:#17a2b8;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#17a2b8;"><?= $total_users ?></div>
                            <div class="ds-lbl">Active Users</div>
                            <div class="ds-sub"><?= $total_positions ?> positions</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#e83e8c;">
                        <div class="ds-icon" style="background:#fdf0f6;"><i class="ri-bank-line" style="color:#e83e8c;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#e83e8c;">₱<?= number_format((float)$loan_data['total'], 0) ?></div>
                            <div class="ds-lbl">Loan Balance</div>
                            <div class="ds-sub"><?= $loan_data['borrowers'] ?> borrowers</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 2: Monthly Payroll Bar + Status Donuts ── -->
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card h-100" style="border-top:3px solid #009688;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-bar-chart-2-line me-2" style="color:#009688;"></i>Payroll Activity (Last 7 Months)
                            </h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-payroll-monthly" style="min-height:240px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card mb-3" style="border-top:3px solid #009688;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-pie-chart-line me-2" style="color:#009688;"></i>Payroll Status</h6>
                        </div>
                        <div class="card-body py-2">
                            <div id="chart-payroll-status" style="min-height:130px;"></div>
                            <div class="d-flex justify-content-center gap-3 mt-1" style="font-size:11px;">
                                <span><span class="pay-status-dot" style="background:#009688;"></span>New (<?= $pay_new ?>)</span>
                                <span><span class="pay-status-dot" style="background:#28a745;"></span>Calc. (<?= $pay_calculated ?>)</span>
                                <span><span class="pay-status-dot" style="background:#dc3545;"></span>Locked (<?= $pay_locked ?>)</span>
                            </div>
                        </div>
                    </div>
                    <div class="card" style="border-top:3px solid #009688;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-donut-chart-line me-2" style="color:#009688;"></i>Employee Payroll Type</h6>
                        </div>
                        <div class="card-body py-2">
                            <div id="chart-emp-type" style="min-height:110px;"></div>
                            <div class="d-flex justify-content-center gap-3 mt-1" style="font-size:11px;">
                                <span><span class="pay-status-dot" style="background:#009688;"></span>Monthly (<?= $emp_monthly ?>)</span>
                                <span><span class="pay-status-dot" style="background:#17a2b8;"></span>Weekly (<?= $emp_weekly ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 3: Net Pay Trend + Employees by Dept ── -->
            <div class="row g-3 mb-3">
                <div class="col-xl-7">
                    <div class="card h-100" style="border-top:3px solid #219688;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-line-chart-line me-2" style="color:#219688;"></i>Net Pay Disbursed (Last 7 Months)
                            </h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-netpay-trend" style="min-height:240px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #219688;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0">
                                <i class="ri-building-2-line me-2" style="color:#219688;"></i>Employees by Department
                            </h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-emp-dept" style="min-height:240px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 3b: Payroll by Department (latest locked payroll) ── -->
            <?php if (count($deptpay_labels)): ?>
            <div class="row g-3 mb-3">
                <!-- Gross vs Deductions vs Net (stacked) -->
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #009688;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-funds-box-line me-2" style="color:#009688;"></i>Gross · Deductions · Net by Department
                            </h6>
                            <span class="badge bg-light text-muted">
                                <?= htmlspecialchars($snap['ref_no'] ?? '') ?> &bull; <?= date('M d', strtotime($snap['date_from'])) ?>–<?= date('M d, Y', strtotime($snap['date_to'])) ?>
                            </span>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-deptstack" style="min-height:320px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <!-- Average net per employee -->
                <div class="col-xl-6">
                    <div class="card h-100" style="border-top:3px solid #6f42c1;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-user-star-line me-2" style="color:#6f42c1;"></i>Average Net Pay per Employee</h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-deptavg" style="min-height:280px;"></div>
                        </div>
                    </div>
                </div>
                <!-- Headcount vs payroll cost (dual axis) -->
                <div class="col-xl-6">
                    <div class="card h-100" style="border-top:3px solid #e83e8c;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-scales-3-line me-2" style="color:#e83e8c;"></i>Headcount vs Payroll Cost</h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-deptcost" style="min-height:280px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── ROW 4: Latest Payroll Snapshot + Recent Payrolls ── -->
            <div class="row g-3 mb-3">

                <!-- Latest Locked Payroll Snapshot -->
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #219688;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0">
                                <i class="ri-lock-line me-2" style="color:#219688;"></i>Latest Locked Payroll
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($snap): ?>
                            <div style="background:linear-gradient(135deg,#219688,#176358);border-radius:8px;padding:12px 14px;color:#fff;margin-bottom:14px;">
                                <div style="font-size:13px;font-weight:800;letter-spacing:.3px;"><?= htmlspecialchars($snap['ref_no']) ?></div>
                                <div style="font-size:11px;opacity:.85;margin-top:2px;"><?= htmlspecialchars($snap['employer_name']) ?></div>
                                <div style="font-size:11px;opacity:.75;margin-top:2px;">
                                    <?= date('M d', strtotime($snap['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($snap['date_to'])) ?>
                                </div>
                                <div style="margin-top:8px;font-size:11px;opacity:.8;"><?= number_format($snap['emp_count']) ?> employees</div>
                            </div>
                            <div class="snap-row">
                                <span class="snap-lbl">Gross Pay</span>
                                <span class="snap-val" style="color:#176358;">₱ <?= number_format((float)$snap['total_gross'], 2) ?></span>
                            </div>
                            <div class="snap-row">
                                <span class="snap-lbl">Total Deductions</span>
                                <span class="snap-val" style="color:#dc3545;">₱ <?= number_format((float)$snap['total_gross'] - (float)$snap['total_net'], 2) ?></span>
                            </div>
                            <div class="snap-row">
                                <span class="snap-lbl">Net Pay Disbursed</span>
                                <span class="snap-val" style="color:#009688;font-size:18px;">₱ <?= number_format((float)$snap['total_net'], 2) ?></span>
                            </div>
                            <a href="payroll_calculations?id=<?= $snap['id'] ?>" class="btn btn-sm w-100 mt-3" style="background:#e8f7f5;color:#176358;font-weight:700;border:1px solid #c8e6e2;">
                                <i class="ri-eye-line me-1"></i>View Payroll
                            </a>
                            <?php else: ?>
                            <div class="text-center py-4" style="color:#aaa;">
                                <i class="ri-lock-unlock-line" style="font-size:32px;"></i>
                                <div style="font-size:12px;margin-top:6px;">No locked payrolls yet</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Payrolls -->
                <div class="col-xl-8">
                    <div class="card h-100" style="border-top:3px solid #009688;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-money-dollar-circle-line me-2" style="color:#009688;"></i>Recent Payrolls
                            </h6>
                            <a href="payroll" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">View All <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;">Ref No.</th>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;">Employer</th>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;">Period</th>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;text-align:center;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($r = $recent_payrolls->fetch_assoc()): ?>
                                        <tr>
                                            <td style="padding:7px 12px;font-size:12px;">
                                                <span style="font-family:monospace;font-weight:700;color:#009688;"><?= htmlspecialchars($r['ref_no']) ?></span>
                                            </td>
                                            <td style="padding:7px 12px;font-size:12px;font-weight:600;"><?= htmlspecialchars($r['employer_name']) ?></td>
                                            <td style="padding:7px 12px;font-size:11px;color:#555;">
                                                <?= date('M d', strtotime($r['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($r['date_to'])) ?>
                                            </td>
                                            <td style="padding:7px 12px;text-align:center;">
                                                <?php if ($r['status'] == 0): ?>
                                                    <span class="badge bg-primary" style="font-size:10px;">New</span>
                                                <?php elseif ($r['status'] == 1): ?>
                                                    <span class="badge bg-success" style="font-size:10px;">Calculated</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger" style="font-size:10px;"><i class="ri-lock-fill me-1"></i>Locked</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 5: Recent DTR + Birthdays This Month ── -->
            <div class="row g-3 mb-3">

                <!-- Recent DTR -->
                <div class="col-xl-7">
                    <div class="card h-100" style="border-top:3px solid #009688;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-time-line me-2" style="color:#009688;"></i>Recent DTR Uploads
                            </h6>
                            <a href="dtr" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">View All <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;">Site</th>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;">Uploaded By</th>
                                            <th style="background:#009688;color:#fff;padding:8px 12px;font-size:11px;border:none;text-align:center;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($recent_dtr && $r = $recent_dtr->fetch_assoc()): ?>
                                        <tr>
                                            <td style="padding:7px 12px;">
                                                <span style="background:#009688;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:700;font-family:monospace;"><?= htmlspecialchars($r['site_code']) ?></span>
                                                <div style="font-size:12px;font-weight:600;margin-top:2px;"><?= htmlspecialchars($r['site_name']) ?></div>
                                            </td>
                                            <td style="padding:7px 12px;font-size:12px;"><?= htmlspecialchars($r['uploader'] ?? '—') ?></td>
                                            <td style="padding:7px 12px;text-align:center;">
                                                <?php if ($r['status'] == 2): ?>
                                                    <span class="badge bg-success" style="font-size:10px;"><i class="ri-check-line me-1"></i>Approved</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark" style="font-size:10px;">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Birthdays This Month -->
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #e83e8c;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-cake-line me-2" style="color:#e83e8c;"></i>Birthdays This Month
                            </h6>
                            <span class="badge" style="background:#fdf0f6;color:#e83e8c;font-size:11px;font-weight:700;"><?= count($bdays) ?></span>
                        </div>
                        <div class="card-body py-2" style="max-height:260px;overflow-y:auto;">
                            <?php if (count($bdays) > 0): ?>
                                <?php foreach ($bdays as $b): ?>
                                <div class="bday-item">
                                    <div class="bday-avatar"><?= strtoupper(substr($b['firstname'],0,1).substr($b['lastname'],0,1)) ?></div>
                                    <div>
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($b['lastname'].', '.$b['firstname']) ?></div>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($b['dept']) ?></div>
                                    </div>
                                    <div class="bday-day"><?= date('M d', strtotime($b['bday'])) ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4" style="color:#aaa;">
                                    <i class="ri-cake-line" style="font-size:30px;"></i>
                                    <div style="font-size:12px;margin-top:6px;">No birthdays this month</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ROW 6: Quick Access ── -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #009688;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-apps-line me-2" style="color:#009688;"></i>Quick Access</h6>
                        </div>
                        <div class="card-body py-3">
                            <div class="d-flex flex-wrap gap-2">
                                <a href="employee" class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-group-line me-1"></i>Employees</a>
                                <a href="payroll"  class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-money-dollar-circle-line me-1"></i>Payroll</a>
                                <a href="dtr"      class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-time-line me-1"></i>DTR</a>
                                <a href="attendance" class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-calendar-check-line me-1"></i>Attendance</a>
                                <a href="branch"   class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-building-2-line me-1"></i>Branches</a>
                                <a href="department" class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-building-3-line me-1"></i>Departments</a>
                                <a href="users"    class="btn btn-sm" style="background:#eef0f8;color:#009688;border:1px solid #d0d7ee;font-weight:600;"><i class="ri-shield-user-line me-1"></i>Users</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="assets/libs/apexcharts/apexcharts.min.js"></script>
<script>
(function () {
    var primary = '#009688', dark = '#176358';

    // ── Monthly Payroll Bar Chart ───────────────────────────────
    new ApexCharts(document.getElementById('chart-payroll-monthly'), {
        chart: { type:'bar', height:240, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary],
        series: [{ name:'Payrolls', data: <?= json_encode($monthly_data) ?> }],
        xaxis: { categories: <?= json_encode($monthly_labels) ?>, labels:{style:{fontSize:'11px'}} },
        yaxis: { labels:{style:{fontSize:'11px'}}, min:0, tickAmount:4, forceNiceScale:true },
        plotOptions: { bar:{borderRadius:4, columnWidth:'45%'} },
        dataLabels: { enabled:true, style:{fontSize:'11px', colors:['#fff']} },
        grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
        tooltip: { y:{ formatter: function(v){ return v+' payroll(s)'; } } },
    }).render();

    // ── Net Pay Trend Area Chart ────────────────────────────────
    new ApexCharts(document.getElementById('chart-netpay-trend'), {
        chart: { type:'area', height:240, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary],
        fill: { type:'gradient', gradient:{ shadeIntensity:1, opacityFrom:.4, opacityTo:.05 } },
        stroke: { curve:'smooth', width:2 },
        series: [{ name:'Net Pay', data: <?= json_encode($netpay_data) ?> }],
        xaxis: { categories: <?= json_encode($netpay_labels) ?>, labels:{style:{fontSize:'11px'}} },
        yaxis: { labels:{ style:{fontSize:'10px'}, formatter: function(v){ return '₱'+Number(v).toLocaleString(); } } },
        dataLabels: { enabled:false },
        grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
        tooltip: { y:{ formatter: function(v){ return '₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2}); } } },
        markers: { size:4, colors:[primary], strokeColors:'#fff', strokeWidth:2 },
    }).render();

    // ── Employees by Department Horizontal Bar ──────────────────
    new ApexCharts(document.getElementById('chart-emp-dept'), {
        chart: { type:'bar', height:240, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary],
        plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'60%' } },
        series: [{ name:'Employees', data: <?= json_encode($dept_data) ?> }],
        xaxis: { categories: <?= json_encode($dept_labels) ?>, labels:{style:{fontSize:'10px'}} },
        yaxis: { labels:{style:{fontSize:'10px'}, maxWidth:110} },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']} },
        grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
        tooltip: { y:{ formatter: function(v){ return v+' employee(s)'; } } },
    }).render();

    // ── Payroll by Department (latest locked payroll) ───────────
    var deptLabels = <?= json_encode($deptpay_labels) ?>;
    var dpEmp = <?= json_encode($deptpay_emp) ?>;
    var peso0 = function(v){ return '₱'+Number(v).toLocaleString('en-PH',{maximumFractionDigits:0}); };
    var peso2 = function(v){ return '₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2}); };

    // 1) Gross vs Deductions vs Net — stacked horizontal bar
    if (document.getElementById('chart-deptstack')) {
        new ApexCharts(document.getElementById('chart-deptstack'), {
            chart: { type:'bar', height:320, stacked:true, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#28a745', '#dc3545', primary],
            plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'65%' } },
            series: [
                { name:'Gross',      data: <?= json_encode($deptpay_gross) ?> },
                { name:'Deductions', data: <?= json_encode($deptpay_ded) ?> },
                { name:'Net',        data: <?= json_encode($deptpay_net) ?> }
            ],
            xaxis: { categories: deptLabels, labels:{ style:{fontSize:'10px'}, formatter:function(v){ return '₱'+Math.round(v/1000)+'k'; } } },
            yaxis: { labels:{ style:{fontSize:'11px'}, maxWidth:140 } },
            dataLabels: { enabled:false },
            legend: { position:'top', fontSize:'12px' },
            grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
            tooltip: { y:{ formatter: peso2 } },
        }).render();
    }

    // 2) Average Net Pay per Employee — horizontal bar
    if (document.getElementById('chart-deptavg')) {
        new ApexCharts(document.getElementById('chart-deptavg'), {
            chart: { type:'bar', height:280, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#6f42c1'],
            plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'60%' } },
            series: [{ name:'Avg Net', data: <?= json_encode($deptpay_avg) ?> }],
            xaxis: { categories: deptLabels, labels:{ style:{fontSize:'10px'}, formatter:function(v){ return '₱'+Math.round(v/1000)+'k'; } } },
            yaxis: { labels:{ style:{fontSize:'10px'}, maxWidth:130 } },
            dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']}, formatter: peso0 },
            grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
            tooltip: { y:{ formatter: function(v, opts){ var e=dpEmp[opts.dataPointIndex]||0; return peso2(v)+' / employee ('+e+')'; } } },
        }).render();
    }

    // 3) Headcount vs Payroll Cost — dual-axis (column + line)
    if (document.getElementById('chart-deptcost')) {
        new ApexCharts(document.getElementById('chart-deptcost'), {
            chart: { type:'line', height:280, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#e83e8c', primary],
            stroke: { width:[0,3], curve:'smooth' },
            plotOptions: { bar:{ columnWidth:'45%', borderRadius:3 } },
            series: [
                { name:'Headcount',    type:'column', data: dpEmp },
                { name:'Net Cost',     type:'line',   data: <?= json_encode($deptpay_net) ?> }
            ],
            xaxis: { categories: deptLabels, labels:{ style:{fontSize:'9px'}, rotate:-35, trim:true, hideOverlappingLabels:false } },
            yaxis: [
                { title:{ text:'Employees', style:{fontSize:'10px'} }, labels:{ style:{fontSize:'10px'} } },
                { opposite:true, title:{ text:'Net Cost', style:{fontSize:'10px'} }, labels:{ style:{fontSize:'9px'}, formatter:function(v){ return '₱'+Math.round(v/1000)+'k'; } } }
            ],
            dataLabels: { enabled:false },
            legend: { position:'top', fontSize:'12px' },
            grid: { borderColor:'#f0f0f0', strokeDashArray:4 },
            tooltip: { shared:true, intersect:false, y:[
                { formatter:function(v){ return v+' employees'; } },
                { formatter: peso2 }
            ] },
        }).render();
    }

    // ── Payroll Status Pie ──────────────────────────────────────
    new ApexCharts(document.getElementById('chart-payroll-status'), {
        chart: { type:'pie', height:130, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary, '#28a745', '#dc3545'],
        series: [<?= $pay_new ?>, <?= $pay_calculated ?>, <?= $pay_locked ?>],
        labels: ['New','Calculated','Locked'],
        legend: { show:false },
        dataLabels: { enabled:true, style:{fontSize:'11px', colors:['#333','#333','#333']}, dropShadow:{enabled:false} },
        stroke: { width:1, colors:['#fff'] },
        tooltip: { y:{ formatter: function(v){ return v+' payroll(s)'; } } },
    }).render();

    // ── Employee Type Pie ───────────────────────────────────────
    new ApexCharts(document.getElementById('chart-emp-type'), {
        chart: { type:'pie', height:110, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary, '#17a2b8'],
        series: [<?= $emp_monthly ?>, <?= $emp_weekly ?>],
        labels: ['Monthly','Weekly'],
        legend: { show:false },
        dataLabels: { enabled:true, style:{fontSize:'11px', colors:['#333','#333']}, dropShadow:{enabled:false} },
        stroke: { width:1, colors:['#fff'] },
        tooltip: { y:{ formatter: function(v){ return v+' employee(s)'; } } },
    }).render();
})();
</script>
