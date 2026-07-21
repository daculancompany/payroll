<?php

// Check if 'id' parameter is set and is a valid integer
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Employee ID");
}

$emp_id = (int) $_GET['id']; // Cast to integer for security

// Prepare the SQL statement to prevent SQL injection
$stmt = $conn->prepare("
    SELECT e.*, p.name AS pname, c.clasification, d.name AS dept_name
    FROM employee e
    INNER JOIN position p ON e.position_id = p.id
    INNER JOIN clasification c ON e.clasification_id = c.id
    LEFT JOIN department d ON e.department_id = d.id
    WHERE e.id = ?
");

// Bind the parameter and execute the query
$stmt->bind_param("i", $emp_id);
$stmt->execute();
$result = $stmt->get_result();

// Fetch employee data
$emp = $result->fetch_assoc();

// Check if an employee was found
if (!$emp) {
    die("Employee not found.");
}

// Department Heads may only open employees from their own department.
require_once 'dept-scope.php';
if (dept_scope_id() > 0 && (int)$emp['department_id'] !== dept_scope_id()) {
    die("You don't have access to this employee's record.");
}

// Assign values dynamically using variable variables ($$)
foreach ($emp as $k => $v) {
    $$k = $v;
}

// Close statement
$stmt->close();

// Current rest days (day off) for this employee, from the active schedule period.
$cur_rest_days = '';
$__rdq = $conn->query("SELECT rest_days FROM employee_schedules
                       WHERE employee_id = $emp_id AND effective_to IS NULL
                       ORDER BY effective_from DESC LIMIT 1");
if ($__rdq && ($__rdr = $__rdq->fetch_assoc())) $cur_rest_days = $__rdr['rest_days'];

// Rest-day (day off) pills renderer — used by the profile panel, schedule card, and modal.
if (!function_exists('ed_rest_pills')) {
    function ed_rest_pills($csv)
    {
        $labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
        $set = array_filter(array_map('intval', $csv === '' || $csv === null ? [] : explode(',', $csv)), fn($d) => $d >= 0 && $d <= 6);
        if (empty($set)) return '<span class="text-muted" style="font-size:12px;">None</span>';
        $out = '';
        foreach ($labels as $i => $lb) {
            $on = in_array($i, $set, true);
            $out .= '<span style="display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:50%;font-size:10px;font-weight:700;margin-right:2px;'
                . ($on ? 'background:#009688;color:#fff;' : 'background:#eef1f5;color:#c2c8d0;') . '">' . $lb . '</span>';
        }
        return $out;
    }
}

// Human day-off label, e.g. "Sun, Sat" (for the profile stat).
$__day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$__day_off_set = array_filter(array_map('intval', $cur_rest_days === '' ? [] : explode(',', $cur_rest_days)), fn($d) => $d >= 0 && $d <= 6);
$day_off_label = empty($__day_off_set) ? 'None' : implode(', ', array_map(fn($d) => $__day_names[$d], $__day_off_set));

$initials = strtoupper(substr($firstname, 0, 1)) . strtoupper(substr($lastname, 0, 1));
$fullname  = htmlspecialchars($lastname . ', ' . $firstname . ($middlename ? ' ' . substr($middlename, 0, 1) . '.' : ''));

$age = null;
if (!empty($bday) && strtotime($bday)) {
    $age = (new DateTime($bday))->diff(new DateTime())->y;
}

// Aggregates for tab counters, sidebar and summary cards
$fetch_agg = function ($sql) use ($conn) {
    $r = $conn->query($sql);
    return $r ? $r->fetch_assoc() : [];
};
$loan_agg = $fetch_agg("SELECT COUNT(*) cnt,
        COALESCE(SUM(loan_amount),0) amt,
        COALESCE(SUM(CASE WHEN loan_status = 0 THEN loan_balance ELSE 0 END),0) bal,
        COALESCE(SUM(CASE WHEN loan_status = 0 THEN damount ELSE 0 END),0) ded,
        COALESCE(SUM(loan_status = 0),0) active
    FROM loans WHERE employee_id = $emp_id");
$contri_agg = $fetch_agg("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) amt
    FROM employee_contributions WHERE employee_id = $emp_id");
$deduct_agg = $fetch_agg("SELECT COUNT(*) cnt,
        COALESCE(SUM(CASE WHEN total_amount <= 0 OR status = 0 THEN amount ELSE 0 END),0) amt,
        COALESCE(SUM(CASE WHEN total_amount > 0 AND status = 0 THEN balance ELSE 0 END),0) bal,
        COALESCE(SUM(total_amount > 0 AND status = 0),0) active,
        COALESCE(SUM(total_amount <= 0),0) recurring
    FROM employee_deductions WHERE employee_id = $emp_id");
$leave_agg = $fetch_agg("SELECT COUNT(*) cnt, COALESCE(SUM(status = 0),0) pending
    FROM leave_requests WHERE employee_id = $emp_id");
?>
<style>
    :root { --emp-brand:#009688; --emp-brand-dark:#00776b; --emp-brand-soft:#eef0f8; --emp-brand-border:#c5cde8; }

    /* ---- Left profile sidebar ---- */
    .emp-sidebar { border:1px solid #d0d7ee; border-radius:10px; overflow:hidden; background:#fff; box-shadow:0 2px 10px rgba(20,30,60,.05); }
    .emp-sidebar-head { position:relative; background:linear-gradient(145deg, var(--emp-brand) 0%, var(--emp-brand-dark) 100%); color:#fff; text-align:center; padding:20px 14px 16px; overflow:hidden; }
    .emp-sidebar-head::before { content:""; position:absolute; top:-40px; right:-40px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,.08); }
    .emp-sidebar-head::after { content:""; position:absolute; bottom:-60px; left:-30px; width:120px; height:120px; border-radius:50%; background:rgba(255,255,255,.06); }
    .emp-sidebar-head > * { position:relative; z-index:1; }
    .emp-side-avatar { width:76px; height:76px; border-radius:50%; background:#fff; color:var(--emp-brand); font-size:27px; font-weight:700; display:flex; align-items:center; justify-content:center; margin:0 auto 10px; letter-spacing:1px; box-shadow:0 2px 8px rgba(0,0,0,.2), 0 0 0 4px rgba(255,255,255,.25); }
    .emp-side-name { font-size:16px; font-weight:700; line-height:1.25; }
    .emp-side-role { font-size:12px; opacity:.9; margin-top:3px; }
    .emp-side-empno { font-family:monospace; font-size:12px; font-weight:700; letter-spacing:.5px; margin-top:6px; background:rgba(255,255,255,.15); display:inline-block; padding:2px 10px; border-radius:20px; }
    .emp-side-meta { padding:10px 14px; border-bottom:1px solid #eef0f8; display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:center; }
    .emp-side-stats { padding:4px 0; }
    .emp-side-stat { display:flex; align-items:center; justify-content:space-between; padding:9px 16px; border-bottom:1px solid #f1f3f9; }
    .emp-side-stat:last-child { border-bottom:none; }
    .emp-side-stat-lbl { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.3px; font-weight:700; }
    .emp-side-stat-val { font-size:14px; font-weight:700; color:var(--emp-brand); font-family:'Segoe UI',monospace; }
    .emp-side-barcode { text-align:center; padding:12px 14px; border-top:1px solid #eef0f8; background:#f8f9fa; }
    @media (min-width:992px){ .emp-sidebar-sticky { position:sticky; top:80px; } }

    /* ---- Section cards (Overview) ---- */
    .detail-section { border:1px solid #d0d7ee; border-radius:6px; margin-bottom:12px; overflow:hidden; }
    .detail-section-title { background:var(--emp-brand); color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:6px 12px; display:flex; align-items:center; gap:6px; }
    .detail-row { display:flex; flex-wrap:wrap; }
    .detail-item { padding:7px 14px; border-bottom:1px solid #eef0f8; border-right:1px solid #eef0f8; flex:1; min-width:180px; transition:background .15s ease; }
    .detail-item:hover { background:#f6fbfa; }
    .detail-item:last-child { border-right:none; }
    .detail-label { font-size:10px; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:.3px; margin-bottom:2px; }
    .detail-value { font-size:13px; font-weight:600; color:#1a1a1a; }
    .emp-currency-val { font-weight:700; color:var(--emp-brand); font-family:'Segoe UI',monospace; }
    .cn-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(210px,1fr)); gap:10px; margin-top:4px; }
    .cn-item { border:1px solid var(--emp-brand-border); border-radius:4px; padding:10px 12px; background:var(--emp-brand-soft); }
    .cn-item label { font-size:10px; color:var(--emp-brand); font-weight:700; text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:5px; }
    .cn-item .form-control { font-size:13px; font-weight:600; border-color:var(--emp-brand-border); }
    .barcode-wrap { background:#fff; border:1px solid #d0d7ee; border-radius:4px; padding:8px 12px; display:inline-block; }

    /* ---- Custom segmented tab bar ---- */
    .emp-tabs-nav { display:flex; gap:4px; flex-wrap:nowrap; background:#fff; border:1px solid #d0d7ee; border-radius:10px; padding:6px; margin-bottom:14px; overflow-x:auto; scrollbar-width:thin; box-shadow:0 2px 8px rgba(20,30,60,.04); }
    .emp-tabs-nav::-webkit-scrollbar { height:4px; }
    .emp-tabs-nav::-webkit-scrollbar-thumb { background:#d0d7ee; border-radius:4px; }
    .emp-tabs-nav .nav-item { flex-shrink:0; }
    .emp-tabs-nav .nav-link { display:flex; align-items:center; gap:7px; padding:8px 14px; border-radius:7px; font-size:12.5px; font-weight:600; color:#5a6474; white-space:nowrap; border:1px solid transparent; transition:all .18s ease; }
    .emp-tabs-nav .nav-link i { font-size:15px; line-height:1; }
    .emp-tabs-nav .nav-link:hover { background:#e9f5f3; color:var(--emp-brand); transform:translateY(-1px); }
    .emp-tabs-nav .nav-link.active { background:linear-gradient(145deg, var(--emp-brand) 0%, var(--emp-brand-dark) 100%); color:#fff; box-shadow:0 3px 10px rgba(0,150,136,.3); }
    .tab-count { font-size:10px; font-weight:700; line-height:1; padding:3px 7px; border-radius:20px; background:#e8ecf5; color:#5a6474; transition:all .18s ease; }
    .emp-tabs-nav .nav-link:hover .tab-count { background:#d4ebe7; color:var(--emp-brand); }
    .emp-tabs-nav .nav-link.active .tab-count { background:rgba(255,255,255,.25); color:#fff; }
    .tab-count.tab-count-alert { background:#ffe4c4; color:#b45309; }

    /* ---- Summary stat cards ---- */
    .emp-stat-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:10px; margin:6px 0 14px; }
    .emp-stat-card { display:flex; align-items:center; gap:11px; background:#fff; border:1px solid #d0d7ee; border-radius:8px; padding:11px 14px; transition:all .18s ease; }
    .emp-stat-card:hover { border-color:var(--emp-brand); box-shadow:0 3px 10px rgba(0,150,136,.12); transform:translateY(-1px); }
    .emp-stat-ic { width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; background:var(--emp-brand-soft); color:var(--emp-brand); }
    .emp-stat-ic.ic-brand { background:#e0f2f1; color:var(--emp-brand); }
    .emp-stat-ic.ic-danger { background:#fdecec; color:#d63939; }
    .emp-stat-ic.ic-warn { background:#fff4e0; color:#b45309; }
    .emp-stat-ic.ic-dark { background:#eceff4; color:#39434f; }
    .emp-stat-lbl { font-size:10px; color:#889; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
    .emp-stat-val { font-size:16px; font-weight:700; color:#1a1a1a; line-height:1.2; font-family:'Segoe UI',monospace; }
    .emp-stat-val.text-brand { color:var(--emp-brand); }
    .emp-stat-val.text-loss { color:#d63939; }

    /* ---- Loan repayment progress ---- */
    .loan-progress { height:5px; background:#edf0f6; border-radius:4px; overflow:hidden; margin-top:5px; min-width:90px; }
    .loan-progress-bar { height:100%; border-radius:4px; background:linear-gradient(90deg, var(--emp-brand), #26bfae); transition:width .3s ease; }
    .loan-progress-pct { font-size:10px; color:#889; font-weight:600; }

    /* ---- Table empty states ---- */
    .emp-empty { text-align:center; padding:28px 10px; color:#98a1b0; }
    .emp-empty i { font-size:34px; display:block; margin-bottom:6px; opacity:.5; }
    .emp-empty b { display:block; font-size:13px; color:#6b7484; margin-bottom:2px; }
    .emp-empty span { font-size:11.5px; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">
                            <i class="ri-user-3-line me-2 text-success"></i>
                            <?= $fullname ?>
                            <?php if ($status == 1): ?>
                                <span class="badge bg-success ms-2" style="font-size:11px;vertical-align:middle;"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-2" style="font-size:11px;vertical-align:middle;"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                            <?php endif; ?>
                        </h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item"><a href="index.php?page=employee">Employee</a></li>
                                <li class="breadcrumb-item active">Details</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex py-2">
                        <div class="flex-grow-1">
                            <a href="javascript:void(0);" onclick="if(document.referrer){history.back();}else{location.href='index.php?page=employee';}" class="btn btn-sm btn-outline-secondary me-2">
                                <i class="ri-arrow-left-line me-1"></i>Back
                            </a>
                        </div>
                        <div class="flex-shrink-0 d-flex gap-2">
                            <?php if (in_array($login_role, $allowed_values)): ?>
                                <?php if (in_array($login_role, $allowed_values_2)): ?>
                                    <button type="button" class="btn btn-sm btn-info" onclick="edit_details()">
                                        <i class="ri-edit-line me-1"></i>Edit Details
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="add_loans()">
                                    <i class="ri-bank-card-line me-1"></i>Add Loan
                                </button>
                                <button type="button" class="btn btn-sm btn-warning text-dark" onclick="add_deductions()">
                                    <i class="ri-subtract-line me-1"></i>Add Deduction
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row g-3">

                        <!-- ============ LEFT PROFILE SIDEBAR ============ -->
                        <div class="col-lg-3">
                            <div class="emp-sidebar emp-sidebar-sticky">
                                <div class="emp-sidebar-head">
                                    <div class="emp-side-avatar"><?= $initials ?></div>
                                    <div class="emp-side-name"><?= $fullname ?></div>
                                    <div class="emp-side-role">
                                        <i class="ri-briefcase-4-line me-1"></i><?= htmlspecialchars(ucwords($pname)) ?>
                                        <?php if (!empty($dept_name)): ?>
                                            <br><i class="ri-building-3-line me-1"></i><?= htmlspecialchars($dept_name) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="emp-side-empno"><i class="ri-barcode-line me-1"></i><?= htmlspecialchars($employee_no) ?></div>
                                </div>
                                <div class="emp-side-meta">
                                    <span class="badge" style="display:inline-flex;align-items:center;gap:2px;<?= clasif_badge_style($clasification) ?>">
                                        <i class="mdi mdi-circle-medium"></i><?= htmlspecialchars($clasification) ?>
                                    </span>
                                    <?php if ($status == 1): ?>
                                        <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                                    <?php endif; ?>
                                    <?php /* Weekly payroll was removed — everyone is semi-monthly. */ ?>
                                    <span class="badge bg-dark"><i class="ri-calendar-check-line me-1"></i>Semi-Monthly</span>
                                    <?php if ($age !== null): ?>
                                        <span class="badge bg-light text-dark border"><i class="ri-cake-2-line me-1"></i><?= $age ?> yrs old</span>
                                    <?php endif; ?>
                                </div>
                                <div class="emp-side-stats">
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl">Basic Pay</span>
                                        <span class="emp-side-stat-val">&#8369;<?= number_format($basic_pay, 2) ?></span>
                                    </div>
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl">Daily Rate</span>
                                        <span class="emp-side-stat-val">&#8369;<?= number_format($salary, 2) ?></span>
                                    </div>
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl">OT Rate</span>
                                        <span class="emp-side-stat-val">&#8369;<?= number_format($ot_rate, 2) ?></span>
                                    </div>
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl">Allowance</span>
                                        <span class="emp-side-stat-val">&#8369;<?= number_format($allowance_rate, 2) ?></span>
                                    </div>
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl"><i class="ri-moon-line me-1"></i>Day Off</span>
                                        <span class="emp-side-stat-val d-flex align-items-center gap-2">
                                            <?= ed_rest_pills($cur_rest_days) ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" style="font-size:11px;"
                                                    data-bs-toggle="modal" data-bs-target="#modal-day-off" title="Update day off">
                                                <i class="ri-edit-line"></i>
                                            </button>
                                        </span>
                                    </div>
                                    <?php if ($loan_agg['bal'] > 0): ?>
                                    <div class="emp-side-stat">
                                        <span class="emp-side-stat-lbl">Loan Balance</span>
                                        <span class="emp-side-stat-val" style="color:#d63939;">&#8369;<?= number_format($loan_agg['bal'], 2) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <!-- <div class="emp-side-barcode">
                                    <div class="barcode-wrap">
                                        <img alt="<?= htmlspecialchars($employee_no) ?>" src="includes/barcode.php?codetype=Code39&size=40&text=<?= urlencode($employee_no) ?>&print=true" style="max-width:100%;" />
                                    </div>
                                </div> -->
                            </div>
                        </div>

                        <!-- ============ RIGHT CONTENT COLUMN ============ -->
                        <div class="col-lg-9">
                        <!-- Tabs -->
                        <ul id="emp-tabs" class="nav emp-tabs-nav" role="tablist">
                            <li class="nav-item" role="presentation">
                                <a class="nav-link active" data-bs-toggle="tab" href="#arrow-overview" role="tab">
                                    <i class="ri-user-3-line"></i><span class="d-none d-sm-inline">Overview</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-profile" role="tab">
                                    <i class="ri-bank-card-line"></i><span class="d-none d-sm-inline">Loans</span>
                                    <?php if ($loan_agg['cnt'] > 0): ?><span class="tab-count"><?= $loan_agg['cnt'] ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-contact" role="tab">
                                    <i class="ri-hand-coin-line"></i><span class="d-none d-sm-inline">Contributions</span>
                                    <?php if ($contri_agg['cnt'] > 0): ?><span class="tab-count"><?= $contri_agg['cnt'] ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-cn" role="tab">
                                    <i class="ri-id-card-line"></i><span class="d-none d-sm-inline">Gov&rsquo;t IDs</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-deductions" role="tab">
                                    <i class="ri-subtract-line"></i><span class="d-none d-sm-inline">Deductions</span>
                                    <?php if ($deduct_agg['cnt'] > 0): ?><span class="tab-count"><?= $deduct_agg['cnt'] ?></span><?php endif; ?>
                                </a>
                            </li>
                            <!-- <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-sites" role="tab">
                                    <i class="ri-map-pin-2-line"></i><span class="d-none d-sm-inline">Sites</span>
                                </a>
                            </li> -->
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-schedule" role="tab">
                                    <i class="ri-time-line"></i><span class="d-none d-sm-inline">Schedule</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-leave" role="tab">
                                    <i class="ri-calendar-event-line"></i><span class="d-none d-sm-inline">Leave</span>
                                    <?php if ($leave_agg['pending'] > 0): ?><span class="tab-count tab-count-alert"><?= $leave_agg['pending'] ?> pending</span><?php endif; ?>
                                </a>
                            </li>
                        </ul>

                        <div class="tab-content text-muted">

                            <!-- OVERVIEW TAB -->
                            <div class="tab-pane active" id="arrow-overview" role="tabpanel">

                                <!-- Personal Information -->
                                <div class="detail-section">
                                    <div class="detail-section-title"><i class="ri-user-3-line"></i>Personal Information</div>
                                    <div class="detail-row">
                                        <div class="detail-item">
                                            <div class="detail-label">First Name</div>
                                            <div class="detail-value"><?= htmlspecialchars($firstname) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Middle Name</div>
                                            <div class="detail-value"><?= htmlspecialchars($middlename) ?: '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Last Name</div>
                                            <div class="detail-value"><?= htmlspecialchars($lastname) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Extension</div>
                                            <div class="detail-value"><?= htmlspecialchars($ext) ?: '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-item">
                                            <div class="detail-label">Birthdate</div>
                                            <div class="detail-value"><?= $bday ? date('F d, Y', strtotime($bday)) : '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Age</div>
                                            <div class="detail-value"><?= $age !== null ? $age . ' years old' : '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Employee Code</div>
                                            <div class="detail-value" style="font-family:monospace;"><?= htmlspecialchars($employee_code) ?: '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Position</div>
                                            <div class="detail-value"><?= htmlspecialchars(ucwords($pname)) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Department</div>
                                            <div class="detail-value"><?= !empty($dept_name) ? htmlspecialchars($dept_name) : '<span class="text-muted">—</span>' ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Classification</div>
                                            <div class="detail-value">
                                                <span class="badge" style="display:inline-flex;align-items:center;gap:2px;<?= clasif_badge_style($clasification) ?>">
                                                    <i class="mdi mdi-circle-medium"></i><?= htmlspecialchars($clasification) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Compensation -->
                                <div class="detail-section">
                                    <div class="detail-section-title"><i class="ri-money-dollar-circle-line"></i>Compensation</div>
                                    <div class="detail-row">
                                        <div class="detail-item">
                                            <div class="detail-label">Basic Pay</div>
                                            <div class="detail-value emp-currency-val">&#8369; <?= number_format($basic_pay, 2) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Daily Rate</div>
                                            <div class="detail-value emp-currency-val">&#8369; <?= number_format($salary, 2) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Overtime Rate</div>
                                            <div class="detail-value emp-currency-val">&#8369; <?= number_format($ot_rate, 2) ?></div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Allowance Rate</div>
                                            <div class="detail-value emp-currency-val">&#8369; <?= number_format($allowance_rate, 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-item">
                                            <div class="detail-label">SSS Provident Fund</div>
                                            <div class="detail-value emp-currency-val">&#8369; <?= number_format($sss_fund, 2) ?></div>
                                        </div>
                                        <div class="detail-item" style="flex:3;"></div>
                                    </div>
                                </div>

                                <!-- Payroll Settings -->
                                <div class="detail-section">
                                    <div class="detail-section-title"><i class="ri-settings-3-line"></i>Payroll Settings</div>
                                    <div class="detail-row">
                                        <div class="detail-item">
                                            <div class="detail-label">Rate Type</div>
                                            <div class="detail-value">
                                                <?php
                                                $__rt = (isset($rate_type) && in_array($rate_type, ['daily', 'monthly', 'fixed'], true)) ? $rate_type : 'daily';
                                                $__rt_meta = [
                                                    'daily'   => ['bg-secondary', 'Daily (per day present)'],
                                                    'monthly' => ['bg-info', 'Monthly (salary − absences)'],
                                                    'fixed'   => ['bg-primary', 'Fixed (full salary, no attendance)'],
                                                ][$__rt];
                                                ?>
                                                <span class="badge <?= $__rt_meta[0] ?>">
                                                    <i class="ri-money-dollar-circle-line me-1"></i><?= $__rt_meta[1] ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Benefit Deductions (SSS/HDMF/PHIC)</div>
                                            <div class="detail-value">
                                                <?php if ($isAutoDeduct == 1): ?>
                                                    <span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>Auto Deduct</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="ri-close-circle-line me-1"></i>Manual</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-label">Status</div>
                                            <div class="detail-value">
                                                <?php if ($status == 1): ?>
                                                    <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="detail-item"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- LOANS TAB -->
                            <div class="tab-pane" id="arrow-profile" role="tabpanel">
                                <div class="emp-stat-cards">
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-brand"><i class="ri-bank-card-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Total Loaned</div>
                                            <div class="emp-stat-val">&#8369;<?= number_format($loan_agg['amt'], 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-danger"><i class="ri-scales-3-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Outstanding Balance</div>
                                            <div class="emp-stat-val <?= $loan_agg['bal'] > 0 ? 'text-loss' : 'text-brand' ?>">&#8369;<?= number_format($loan_agg['bal'], 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-warn"><i class="ri-subtract-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Per-Cutoff Deduction</div>
                                            <div class="emp-stat-val">&#8369;<?= number_format($loan_agg['ded'], 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-dark"><i class="ri-pulse-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Active Loans</div>
                                            <div class="emp-stat-val"><?= (int)$loan_agg['active'] ?> <span style="font-size:11px;color:#889;font-weight:600;">of <?= (int)$loan_agg['cnt'] ?></span></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table id="table-loan" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="ri-list-check-2 me-1"></i>Loan Type</th>
                                                <th><i class="ri-calendar-2-line me-1"></i>Loan Date</th>
                                                <th class="text-end"><i class="ri-money-dollar-circle-line me-1"></i>Amount</th>
                                                <th class="text-end"><i class="ri-scales-3-line me-1"></i>Balance</th>
                                                <th class="text-end"><i class="ri-subtract-line me-1"></i>Deduction</th>
                                                <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                                <th class="text-center" style="width:140px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $loans = $conn->query("SELECT loans.*, contribution_loan_types.loan_type, contribution_loan_types.clt_id AS loan_type_id FROM loans
                                            INNER JOIN employee ON loans.employee_id = employee.id
                                            INNER JOIN contribution_loan_types ON contribution_loan_types.clt_id = loans.loan_type
                                            WHERE loans.employee_id = $emp_id
                                            ORDER BY loan_id ASC");
                                            while ($row = $loans->fetch_assoc()):
                                                $paid_pct = (float)$row['loan_amount'] > 0
                                                    ? max(0, min(100, round((($row['loan_amount'] - $row['loan_balance']) / $row['loan_amount']) * 100)))
                                                    : 0;
                                            ?>
                                                <tr>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['loan_type']) ?></span></td>
                                                    <td><span style="font-size:12px;color:#555;"><i class="ri-calendar-2-line me-1 text-muted"></i><?= htmlspecialchars($row['loan_date']) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['loan_amount'], 2) ?></span></td>
                                                    <td class="text-end">
                                                        <span class="emp-currency-val">&#8369; <?= number_format($row['loan_balance'], 2) ?></span>
                                                        <div class="loan-progress" title="<?= $paid_pct ?>% repaid"><div class="loan-progress-bar" style="width:<?= $paid_pct ?>%;"></div></div>
                                                        <span class="loan-progress-pct"><?= $paid_pct ?>% repaid</span>
                                                    </td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['damount'], 2) ?></span></td>
                                                    <td class="text-center">
                                                        <?php if ($row['loan_status'] == 1): ?>
                                                            <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Paid</span>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill bg-danger"><i class="ri-time-line me-1"></i>Unpaid</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div style="display:flex;gap:4px;justify-content:center;">
                                                            <button class="btn btn-sm btn-outline-primary" type="button"
                                                                loan_id="<?= $row['loan_id'] ?>" employee_id="<?= $row['employee_id'] ?>"
                                                                loan_balance="<?= $row['loan_balance'] ?>" damount="<?= $row['damount'] ?>"
                                                                loan_amount="<?= $row['loan_amount'] ?>" loan_date="<?= $row['loan_date'] ?>"
                                                                loan_type="<?= $row['loan_type_id'] ?>" loan_status="<?= $row['loan_status'] ?>"
                                                                onclick="editLoan(this)"
                                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Loan">
                                                                <i class="ri-edit-line"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                                                onclick="loanHistory(<?= $row['loan_id'] ?>)"
                                                                data-bs-toggle="tooltip" data-bs-placement="top" title="View History">
                                                                <i class="ri-history-line"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php if (!$loans->num_rows): ?>
                                                <tr><td colspan="7">
                                                    <div class="emp-empty">
                                                        <i class="ri-bank-card-line"></i>
                                                        <b>No loans recorded</b>
                                                        <span>Use the &ldquo;Add Loan&rdquo; button above to record one.</span>
                                                    </div>
                                                </td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- CONTRIBUTIONS TAB -->
                            <div class="tab-pane" id="arrow-contact" role="tabpanel">
                                <div class="table-responsive mt-2">
                                    <table id="table-contributions" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="ri-hand-coin-line me-1"></i>Contribution</th>
                                                <th class="text-end" style="width:200px;"><i class="ri-money-dollar-circle-line me-1"></i>Amount</th>
                                                <th class="text-center" style="width:100px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $contributions = $conn->query("SELECT ea.*,c.contribution,ea.id AS contribution_unique FROM employee_contributions ea LEFT JOIN contributions c ON ea.contribution_id = c.id WHERE ea.employee_id=" . $emp_id);
                                            while ($row = $contributions->fetch_assoc()):
                                            ?>
                                                <tr>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['contribution']) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['amount'], 2) ?></span></td>
                                                    <td class="text-center">
                                                        <button type="button"
                                                            data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['contribution']) ?>" data-amount="<?= $row['amount'] ?>"
                                                            class="btn btn-sm btn-outline-primary"
                                                            onclick="editContriAmount(this)"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Amount">
                                                            <i class="ri-edit-line"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php if (!$contributions->num_rows): ?>
                                                <tr><td colspan="3">
                                                    <div class="emp-empty">
                                                        <i class="ri-hand-coin-line"></i>
                                                        <b>No contributions set</b>
                                                        <span>Government contributions will appear here once configured.</span>
                                                    </div>
                                                </td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if ($contributions->num_rows): ?>
                                        <tfoot>
                                            <tr class="table-light">
                                                <td class="text-end fw-bold" style="font-size:12px;text-transform:uppercase;letter-spacing:.3px;">Total per cutoff</td>
                                                <td class="text-end"><span class="emp-currency-val" style="font-size:14px;">&#8369; <?= number_format($contri_agg['amt'], 2) ?></span></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>

                            <!-- CONTRIBUTION NUMBERS TAB -->
                            <div class="tab-pane" id="arrow-cn" role="tabpanel">
                                <div class="cn-grid mt-2">
                                    <div class="cn-item">
                                        <label><i class="ri-id-card-line me-1"></i>SSS No.</label>
                                        <input type="text" class="form-control sss_no" data-id="sss_no" value="<?= htmlspecialchars($sss_no) ?>" name="sss_no" placeholder="Enter SSS No." />
                                    </div>
                                    <div class="cn-item">
                                        <label><i class="ri-id-card-line me-1"></i>HDMF No.</label>
                                        <input type="text" class="form-control sss_no" data-id="hdmf_no" value="<?= htmlspecialchars($hdmf_no) ?>" name="hdmf_no" placeholder="Enter HDMF No." />
                                    </div>
                                    <div class="cn-item">
                                        <label><i class="ri-id-card-line me-1"></i>PhilHealth No.</label>
                                        <input type="text" class="form-control sss_no" id="ph_no" data-id="ph_no" value="<?= htmlspecialchars($ph_no) ?>" placeholder="Enter PhilHealth No." />
                                    </div>
                                    <div class="cn-item">
                                        <label><i class="ri-id-card-line me-1"></i>TIN No.</label>
                                        <input type="text" class="form-control sss_no" data-id="tin_no" value="<?= htmlspecialchars($tin_no) ?>" placeholder="Enter TIN No." />
                                    </div>
                                </div>
                            </div>

                            <!-- DEDUCTIONS TAB -->
                            <div class="tab-pane" id="arrow-deductions" role="tabpanel">
                                <div class="emp-stat-cards">
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-warn"><i class="ri-subtract-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Per-Cutoff Total</div>
                                            <div class="emp-stat-val">&#8369;<?= number_format($deduct_agg['amt'], 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-danger"><i class="ri-wallet-3-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Remaining Balance</div>
                                            <div class="emp-stat-val <?= $deduct_agg['bal'] > 0 ? 'text-loss' : 'text-brand' ?>">&#8369;<?= number_format($deduct_agg['bal'], 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-brand"><i class="ri-refresh-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Recurring</div>
                                            <div class="emp-stat-val"><?= (int)$deduct_agg['recurring'] ?></div>
                                        </div>
                                    </div>
                                    <div class="emp-stat-card">
                                        <div class="emp-stat-ic ic-dark"><i class="ri-pulse-line"></i></div>
                                        <div>
                                            <div class="emp-stat-lbl">Amortizing Active</div>
                                            <div class="emp-stat-val"><?= (int)$deduct_agg['active'] ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table id="table-deductions" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="ri-subtract-line me-1"></i>Deduction Name</th>
                                                <th class="text-end"><i class="ri-money-dollar-circle-line me-1"></i>Amount</th>
                                                <th class="text-end"><i class="ri-bank-card-line me-1"></i>Total</th>
                                                <th class="text-end"><i class="ri-wallet-3-line me-1"></i>Balance</th>
                                                <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                                <th class="text-center" style="width:100px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $deductions = $conn->query("SELECT ea.*,d.deduction as dname FROM employee_deductions ea INNER JOIN deductions d ON d.id = ea.deduction_id WHERE ea.employee_id=" . $emp_id . " ORDER BY ea.type ASC, DATE(ea.effective_date) ASC, d.deduction ASC");
                                            while ($row = $deductions->fetch_assoc()):
                                            ?>
                                                <?php $amortizing = (float)$row['total_amount'] > 0; ?>
                                                <tr>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['dname']) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['amount'], 2) ?></span></td>
                                                    <td class="text-end"><?= $amortizing ? '<span class="emp-currency-val">&#8369; ' . number_format($row['total_amount'], 2) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                                    <td class="text-end"><?= $amortizing ? '<span class="emp-currency-val">&#8369; ' . number_format($row['balance'], 2) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                                    <td class="text-center">
                                                        <?php if (!$amortizing): ?>
                                                            <span class="badge bg-secondary">Recurring</span>
                                                        <?php elseif ((int)$row['status'] === 1): ?>
                                                            <span class="badge bg-success">Paid</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">Active</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" data-id="<?= $row['id'] ?>"
                                                            class="btn btn-sm btn-outline-danger remove_deduction"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Deduction">
                                                            <i class="ri-delete-bin-line"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            <?php if (!$deductions->num_rows): ?>
                                                <tr><td colspan="6">
                                                    <div class="emp-empty">
                                                        <i class="ri-subtract-line"></i>
                                                        <b>No deductions recorded</b>
                                                        <span>Use the &ldquo;Add Deduction&rdquo; button above to add one.</span>
                                                    </div>
                                                </td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- SITES TAB -->
                            <div class="tab-pane" id="arrow-sites" role="tabpanel">
                                <div class="table-responsive mt-2">
                                    <table id="table-sites" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th class="text-center" style="width:90px;"><i class="ri-qr-code-line me-1"></i>Code</th>
                                                <th class="text-center" style="width:100px;"><i class="ri-device-line me-1"></i>Device ID</th>
                                                <th><i class="ri-map-pin-2-line me-1"></i>Site</th>
                                                <th><i class="ri-global-line me-1"></i>Cluster</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $query = $conn->query("SELECT A.*, B.cluster, C.name AS timekeeper, D.employer_name AS employer, E.device_id, E.code
                                                FROM sites AS A
                                                INNER JOIN clusters AS B ON A.cluster_id = B.id
                                                LEFT JOIN users AS C ON A.timekeeper_id = C.id
                                                LEFT JOIN employers AS D ON C.employer_id = D.id
                                                INNER JOIN employee_bio AS E ON E.site_id = A.id
                                                WHERE E.employee_id=" . $emp_id . "
                                                GROUP BY E.site_id
                                                ORDER BY A.site_name ASC");
                                            while ($row = $query->fetch_assoc()):
                                            ?>
                                                <tr>
                                                    <td class="text-center"><span style="font-family:monospace;font-weight:700;color:#1976d2;"><?= htmlspecialchars($row['code']) ?></span></td>
                                                    <td class="text-center"><span style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($row['device_id']) ?></span></td>
                                                    <td>
                                                        <div style="font-weight:600;font-size:13px;"><i class="ri-radio-button-line text-success me-1"></i><?= htmlspecialchars($row['site_name']) ?></div>
                                                        <div style="font-size:11px;color:#666;"><i class="ri-hashtag text-muted me-1"></i><?= htmlspecialchars($row['site_code']) ?></div>
                                                        <div style="font-size:11px;color:#888;"><i class="ri-map-pin-line text-muted me-1"></i><?= htmlspecialchars($row['site_address']) ?></div>
                                                    </td>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['cluster']) ?></span></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- SCHEDULE TAB -->
                            <div class="tab-pane" id="arrow-schedule" role="tabpanel">
                                <?php
                                $cur_sched_q = $conn->query("
                                    SELECT es.effective_from, es.rest_days,
                                           ws.description, ws.start_time, ws.end_time,
                                           ws.total_hours, ws.break_minutes,
                                           ws.is_graveyard, ws.has_nsd, ws.nsd_rate
                                    FROM employee_schedules es
                                    INNER JOIN work_schedules ws ON ws.id = es.schedule_id
                                    WHERE es.employee_id = $emp_id
                                      AND es.effective_from <= CURDATE()
                                      AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
                                    ORDER BY es.effective_from DESC
                                    LIMIT 1
                                ");
                                $cur_sched = $cur_sched_q ? $cur_sched_q->fetch_assoc() : null;

                                // Rest-day pills (0=Sun … 6=Sat). Reused for the card and, below, the modal.
                                if (!function_exists('ed_rest_pills')) {
                                    function ed_rest_pills($csv)
                                    {
                                        $labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
                                        $set = array_filter(array_map('intval', $csv === '' || $csv === null ? [] : explode(',', $csv)), fn($d) => $d >= 0 && $d <= 6);
                                        if (empty($set)) return '<span class="text-muted" style="font-size:12px;">None</span>';
                                        $out = '';
                                        foreach ($labels as $i => $lb) {
                                            $on = in_array($i, $set, true);
                                            $out .= '<span style="display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:50%;font-size:10px;font-weight:700;margin-right:2px;'
                                                . ($on ? 'background:#009688;color:#fff;' : 'background:#eef1f5;color:#c2c8d0;') . '">' . $lb . '</span>';
                                        }
                                        return $out;
                                    }
                                }
                                ?>

                                <!-- Current Schedule Card -->
                                <div class="d-flex align-items-center justify-content-between mb-3 mt-2">
                                    <h6 class="mb-0 fw-semibold"><i class="ri-time-line me-1 text-success"></i>Current Schedule</h6>
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modal-assign-schedule">
                                        <i class="ri-edit-line me-1"></i>Change Schedule
                                    </button>
                                </div>

                                <?php if ($cur_sched): ?>
                                <div class="alert alert-success d-flex gap-4 align-items-center py-3">
                                    <i class="ri-time-fill fs-2"></i>
                                    <div>
                                        <div class="fw-bold fs-6"><?= htmlspecialchars($cur_sched['description']) ?></div>
                                        <div class="text-muted" style="font-size:13px;">
                                            <?= date('h:i A', strtotime($cur_sched['start_time'])) ?> –
                                            <?= date('h:i A', strtotime($cur_sched['end_time'])) ?>
                                            &nbsp;|&nbsp; <?= $cur_sched['total_hours'] ?> hrs
                                            <?php if ($cur_sched['is_graveyard']): ?>
                                                &nbsp;<span class="badge bg-dark"><i class="ri-moon-line me-1"></i>Graveyard</span>
                                            <?php endif; ?>
                                            <?php if ($cur_sched['has_nsd']): ?>
                                                &nbsp;<span class="badge bg-info">NSD <?= ($cur_sched['nsd_rate'] * 100) ?>%</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:12px;color:#666;">Effective: <?= date('F d, Y', strtotime($cur_sched['effective_from'])) ?></div>
                                        <div class="mt-1" style="font-size:12px;color:#666;">
                                            <i class="ri-moon-line me-1"></i>Rest days: <?= ed_rest_pills($cur_sched['rest_days'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-warning"><i class="ri-alert-line me-2"></i>No schedule assigned yet.</div>
                                <?php endif; ?>

                                <!-- History Table -->
                                <h6 class="fw-semibold mt-4 mb-2"><i class="ri-history-line me-1 text-muted"></i>Schedule History</h6>
                                <div class="table-responsive">
                                    <table id="table-schedule-history" class="table table-sm table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Shift</th>
                                                <th class="text-center">Time</th>
                                                <th class="text-center">Hours</th>
                                                <th class="text-center">Effective From</th>
                                                <th class="text-center">Effective To</th>
                                                <th>Changed By</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $hist = $conn->query("
                                                SELECT es.id, es.effective_from, es.effective_to,
                                                       es.notes, es.created_at,
                                                       ws.description, ws.start_time, ws.end_time,
                                                       ws.total_hours, ws.break_minutes,
                                                       ws.is_graveyard, ws.has_nsd, ws.nsd_rate,
                                                       u.name AS changed_by_name
                                                FROM employee_schedules es
                                                INNER JOIN work_schedules ws ON ws.id = es.schedule_id
                                                LEFT JOIN users u ON u.id = es.changed_by
                                                WHERE es.employee_id = $emp_id
                                                ORDER BY es.effective_from DESC
                                            ");
                                            if (!$hist) { echo '<!-- query error: ' . htmlspecialchars($conn->error) . ' -->'; }
                                            while ($hist && ($h = $hist->fetch_assoc())):
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold"><?= htmlspecialchars($h['description']) ?></span>
                                                    <?php if ($h['is_graveyard']): ?>
                                                        <span class="badge bg-dark ms-1"><i class="ri-moon-line"></i></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center" style="font-size:12px;white-space:nowrap;">
                                                    <?= date('h:i A', strtotime($h['start_time'])) ?> –
                                                    <?= date('h:i A', strtotime($h['end_time'])) ?>
                                                </td>
                                                <td class="text-center"><span class="badge bg-success"><?= $h['total_hours'] ?></span></td>
                                                <td class="text-center"><?= date('M d, Y', strtotime($h['effective_from'])) ?></td>
                                                <td class="text-center">
                                                    <?= $h['effective_to'] ? date('M d, Y', strtotime($h['effective_to'])) : '<span class="badge bg-success">Current</span>' ?>
                                                </td>
                                                <td><?= htmlspecialchars($h['changed_by_name'] ?? '—') ?></td>
                                                <td><small class="text-muted"><?= htmlspecialchars($h['notes'] ?? '') ?></small></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Registered Fingerprints (two-hand status) -->
                                <div class="mt-4">
                                    <?php
                                    require_once __DIR__ . '/component/finger_hands.php';
                                    echo render_finger_hands($conn, $emp_id);
                                    ?>
                                </div>
                            </div>

                            <!-- LEAVE TAB -->
                            <div class="tab-pane" id="arrow-leave" role="tabpanel">

                                <?php
                                $can_edit_credits = in_array($login_role, [1, 8, 9]);
                                $elig_row = $conn->query("SELECT UPPER(COALESCE(cl.clasification,'')) AS c FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id WHERE e.id = " . $emp_id)->fetch_assoc();
                                $emp_leave_eligible = $elig_row && in_array($elig_row['c'], LEAVE_ELIGIBLE_CLASSIFICATIONS, true);
                                ?>
                                <?php if (!$emp_leave_eligible): ?>
                                <div class="alert alert-warning d-flex align-items-center py-2" role="alert">
                                    <i class="ri-information-line fs-20 me-2"></i>
                                    <div style="font-size:12px;">Only <b>Regular</b> and <b>Executive</b> employees are entitled to leave credits.</div>
                                </div>
                                <?php endif; ?>
                                <!-- Leave Credits (HR / Admin can set available leaves) -->
                                <div class="card border mb-3">
                                    <div class="card-header bg-light d-flex align-items-center py-2">
                                        <h6 class="mb-0 flex-grow-1"><i class="ri-coins-line me-2 text-success"></i>Leave Credits / Available</h6>
                                        <?php if (!$can_edit_credits): ?>
                                            <span class="badge bg-secondary-subtle text-secondary">View only</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-0">
                                        <table class="table table-bordered align-middle mb-0" style="font-size:13px;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Leave Type</th>
                                                    <th class="text-center" style="width:150px;">Available</th>
                                                    <th class="text-center" style="width:90px;">Used</th>
                                                    <th class="text-center" style="width:110px;">Remaining</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $cr = $conn->query("
                                                    SELECT lt.id, lt.name, lt.days_allowed,
                                                        COALESCE(c.credits, lt.days_allowed) AS credits,
                                                        COALESCE(u.used, 0) AS used
                                                    FROM leave_types lt
                                                    LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = " . $emp_id . "
                                                    LEFT JOIN (
                                                        SELECT leave_type_id, SUM(duration) AS used
                                                        FROM leave_requests
                                                        WHERE employee_id = " . $emp_id . " AND status = 1
                                                        GROUP BY leave_type_id
                                                    ) u ON u.leave_type_id = lt.id
                                                    WHERE lt.status = 1
                                                    ORDER BY lt.name ASC
                                                ");
                                                if ($cr) while ($c = $cr->fetch_assoc()):
                                                    $avail = (float)$c['credits'];
                                                    $used  = (float)$c['used'];
                                                    $rem   = $avail - $used;
                                                ?>
                                                <tr>
                                                    <td><b><i class="ri-calendar-event-line me-1 text-success"></i><?= htmlspecialchars($c['name']) ?></b></td>
                                                    <td class="text-center">
                                                        <?php if ($can_edit_credits && $emp_leave_eligible): ?>
                                                            <div class="input-group input-group-sm" style="max-width:140px;margin:0 auto;">
                                                                <input type="number" min="0" step="0.5" class="form-control text-center leave-credit-input"
                                                                    value="<?= rtrim(rtrim(number_format($avail, 1), '0'), '.') ?>"
                                                                    data-employee="<?= $emp_id ?>" data-type="<?= $c['id'] ?>">
                                                                <button class="btn btn-success leave-credit-save" type="button" title="Save"><i class="ri-save-line"></i></button>
                                                            </div>
                                                        <?php else: ?>
                                                            <?= $emp_leave_eligible ? rtrim(rtrim(number_format($avail, 1), '0'), '.') : '<span class="text-muted">—</span>' ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center"><span class="badge bg-warning-subtle text-warning"><?= rtrim(rtrim(number_format($used, 1), '0'), '.') ?></span></td>
                                                    <td class="text-center"><span class="badge <?= $rem <= 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill"><?= rtrim(rtrim(number_format($rem, 1), '0'), '.') ?> day(s)</span></td>
                                                </tr>
                                                <?php endwhile; ?>
                                                <?php if (!$cr || !$cr->num_rows): ?>
                                                <tr><td colspan="4">
                                                    <div class="dt-empty-state">
                                                        <div class="dt-empty-ic"><i class="ri-coins-line"></i></div>
                                                        <p class="dt-empty-title">No leave types configured</p>
                                                        <span class="dt-empty-sub">Add leave types to assign credits.</span>
                                                    </div>
                                                </td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <?php
                                $bh = $conn->query("
                                    SELECT h.*, lt.name AS type_name, u.name AS changed_name
                                    FROM leave_credit_history h
                                    INNER JOIN leave_types lt ON lt.id = h.leave_type_id
                                    LEFT JOIN users u ON u.id = h.changed_by
                                    WHERE h.employee_id = " . $emp_id . "
                                    ORDER BY h.created_at DESC LIMIT 20
                                ");
                                ?>
                                <div class="card border mb-3">
                                    <div class="card-header bg-light py-2"><h6 class="mb-0"><i class="ri-history-line me-2 text-success"></i>Balance Change History</h6></div>
                                    <div class="card-body p-0" style="max-height:240px;overflow-y:auto;">
                                        <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                                            <thead class="table-light"><tr><th>When</th><th>Type</th><th class="text-center">Change</th><th>By</th></tr></thead>
                                            <tbody>
                                            <?php if ($bh && $bh->num_rows): while ($h = $bh->fetch_assoc()):
                                                $f = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
                                                $up = (float)$h['new_credits'] >= (float)$h['old_credits'];
                                            ?>
                                                <tr>
                                                    <td><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                                                    <td><?= htmlspecialchars($h['type_name']) ?></td>
                                                    <td class="text-center"><span class="text-muted"><?= $f($h['old_credits']) ?></span> <i class="ri-arrow-right-line <?= $up ? 'text-success' : 'text-danger' ?>"></i> <b class="<?= $up ? 'text-success' : 'text-danger' ?>"><?= $f($h['new_credits']) ?></b></td>
                                                    <td><?= htmlspecialchars($h['changed_name'] ?? 'System') ?></td>
                                                </tr>
                                            <?php endwhile; else: ?>
                                                <tr><td colspan="4">
                                                    <div class="dt-empty-state">
                                                        <div class="dt-empty-ic"><i class="ri-history-line"></i></div>
                                                        <p class="dt-empty-title">No balance changes yet</p>
                                                        <span class="dt-empty-sub">Leave credit adjustments will appear here.</span>
                                                    </div>
                                                </td></tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="table-responsive mt-2">
                                    <table id="table-leave" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="ri-calendar-line me-1"></i>Date Applied</th>
                                                <th><i class="ri-bookmark-line me-1"></i>Type</th>
                                                <th class="text-center"><i class="ri-time-line me-1"></i>Duration</th>
                                                <th><i class="ri-chat-1-line me-1"></i>Reason</th>
                                                <th class="text-center">HR</th>
                                                <th class="text-center">Final</th>
                                                <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $lv = $conn->query("
                                                SELECT lr.*, lt.name AS leave_type_name,
                                                    hu.name AS hr_name, au.name AS admin_name
                                                FROM leave_requests lr
                                                INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                                                LEFT JOIN users hu ON hu.id = lr.hr_by
                                                LEFT JOIN users au ON au.id = lr.admin_by
                                                WHERE lr.employee_id = " . $emp_id . "
                                                ORDER BY lr.date_applied DESC, lr.id DESC
                                            ");
                                            $lv_status = [0 => ['Pending','bg-warning'], 1 => ['Approved','bg-success'], 2 => ['Rejected','bg-danger']];
                                            $lv_stage = function ($s, $by, $rem) use ($lv_status) {
                                                if ($s == 1) return '<span class="badge bg-success-subtle text-success border border-success-subtle" title="' . htmlspecialchars($by ?? '') . '"><i class="ri-check-line"></i></span>';
                                                if ($s == 2) return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="' . htmlspecialchars($rem ?? '') . '"><i class="ri-close-line"></i></span>';
                                                return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-time-line"></i></span>';
                                            };
                                            if ($lv) while ($row = $lv->fetch_assoc()):
                                                [$slabel, $sclass] = $lv_status[$row['status']] ?? ['Unknown','bg-secondary'];
                                            ?>
                                                <tr>
                                                    <td><?= date('M d, Y', strtotime($row['date_applied'])) ?></td>
                                                    <td><span class="badge bg-info-subtle text-info border border-info-subtle"><?= htmlspecialchars($row['leave_type_name']) ?></span></td>
                                                    <td class="text-center">
                                                        <b><?= rtrim(rtrim(number_format($row['duration'], 1), '0'), '.') ?></b> day(s)
                                                        <div class="text-muted" style="font-size:11px;"><?= date('M d', strtotime($row['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($row['date_to'])) ?></div>
                                                    </td>
                                                    <td style="max-width:220px;"><span class="text-muted"><?= nl2br(htmlspecialchars($row['reason'] ?? '')) ?></span>
                                                        <?php if ($row['status'] == 2 && ($row['hr_remarks'] || $row['admin_remarks'])): ?>
                                                            <div class="text-danger" style="font-size:11px;"><i class="ri-information-line"></i> <?= htmlspecialchars($row['admin_remarks'] ?: $row['hr_remarks']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center"><?= $lv_stage($row['hr_status'], $row['hr_name'], $row['hr_remarks']) ?></td>
                                                    <td class="text-center"><?= $lv_stage($row['admin_status'], $row['admin_name'], $row['admin_remarks']) ?></td>
                                                    <td class="text-center"><span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div><!-- end tab-content -->
                        </div><!-- end col-lg-9 -->
                        </div><!-- end row -->

                        <script>
                        // Save per-employee leave credits (delegated → works before jQuery loads).
                        document.addEventListener('click', function (e) {
                            const btn = e.target.closest('.leave-credit-save');
                            if (!btn) return;
                            const grp = btn.closest('.input-group');
                            const input = grp.querySelector('.leave-credit-input');
                            const body = new URLSearchParams({
                                employee_id: input.dataset.employee,
                                leave_type_id: input.dataset.type,
                                credits: input.value
                            });
                            btn.disabled = true;
                            fetch('ajax.php?action=save_leave_credit', { method: 'POST', body: body })
                                .then(r => r.json())
                                .then(j => {
                                    btn.disabled = false;
                                    if (j && j.result) {
                                        Swal.fire({ icon: 'success', title: 'Saved', text: j.message, timer: 1000, showConfirmButton: false }).then(() => location.reload());
                                    } else {
                                        Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to save.' });
                                    }
                                })
                                .catch(() => { btn.disabled = false; });
                        });
                        </script>
                    </div><!-- end card-body -->
                </div>
            </div>
        </div>
    </div>

    <!-- Loan History Modal -->
    <div class="modal fade" id="modal-loan-history" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="ri-history-line me-2 text-success"></i>Loan History</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="loanHistoryDiv"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Schedule Modal -->
    <div class="modal fade" id="modal-assign-schedule" tabindex="-1">
        <div class="modal-dialog">
            <form id="form-assign-schedule">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="ri-time-line me-2 text-success"></i>Assign Work Schedule</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="employee_id" value="<?= $emp_id ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Shift <span class="text-danger">*</span></label>
                            <select class="form-select" name="schedule_id" required>
                                <option value="">— Select shift —</option>
                                <?php
                                $scheds = $conn->query("SELECT id, description, start_time, end_time FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
                                while ($s = $scheds->fetch_assoc()):
                                ?>
                                <option value="<?= $s['id'] ?>">
                                    <?= htmlspecialchars($s['description']) ?>
                                    (<?= date('h:i A', strtotime($s['start_time'])) ?> – <?= date('h:i A', strtotime($s['end_time'])) ?>)
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Effective From <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="effective_from"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><i class="ri-moon-line me-1 text-success"></i>Rest Days</label>
                            <?php
                            $__cur_rest = ($cur_sched['rest_days'] ?? '0');
                            $__cur_set  = array_map('intval', array_filter(explode(',', $__cur_rest), fn($x) => $x !== ''));
                            $__rd_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                            $__rd_lbl   = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
                            ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php foreach ($__rd_lbl as $i => $lb): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="rest_days[]" value="<?= $i ?>"
                                               id="asch_rd_<?= $i ?>" <?= in_array($i, $__cur_set, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="asch_rd_<?= $i ?>" title="<?= $__rd_names[$i] ?>"><?= $lb ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" name="notes" placeholder="Optional reason for change">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Day Off (rest days only — no shift change) -->
    <div class="modal fade" id="modal-day-off" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form id="form-day-off">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><i class="ri-moon-line me-2 text-success"></i>Update Day Off</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="employee_id" value="<?= $emp_id ?>">
                        <p class="text-muted" style="font-size:12px;">Tick the day(s) this employee is off. This updates their current schedule only — the shift stays the same.</p>
                        <?php
                        $__do_set = array_map('intval', array_filter(explode(',', $cur_rest_days), fn($x) => $x !== ''));
                        $__do_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                        $__do_lbl   = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
                        ?>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($__do_lbl as $i => $lb): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="rest_days[]" value="<?= $i ?>"
                                           id="do_rd_<?= $i ?>" <?= in_array($i, $__do_set, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="do_rd_<?= $i ?>" title="<?= $__do_names[$i] ?>"><?= $lb ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Save Day Off</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('form-assign-schedule').addEventListener('submit', async function(e) {
            e.preventDefault();
            const data = new FormData(this);
            try {
                const res  = await fetch('ajax.php?action=assign_employee_schedule', { method: 'POST', body: new URLSearchParams(data) });
                const json = await res.json();
                if (json?.result) {
                    bootstrap.Modal.getInstance(document.getElementById('modal-assign-schedule'))?.hide();
                    location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to assign schedule.' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to assign schedule. Please try again.' });
            }
        });

        // Update Day Off — rest days only, no shift change (roster_update_rest_days).
        document.getElementById('form-day-off').addEventListener('submit', async function(e) {
            e.preventDefault();
            const data = new FormData(this);
            try {
                const res  = await fetch('ajax.php?action=roster_update_rest_days', { method: 'POST', body: new URLSearchParams(data) });
                const json = await res.json();
                if (json?.result) {
                    bootstrap.Modal.getInstance(document.getElementById('modal-day-off'))?.hide();
                    location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to update day off.' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to update day off. Please try again.' });
            }
        });
    </script>

    <script>
        let employee_id = "<?= $emp_id ?>";

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                new bootstrap.Tooltip(el, { trigger: 'hover' });
            });
        });
    </script>
    <?php include 'component/add_employee_form.php'; ?>
