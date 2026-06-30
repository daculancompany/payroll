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

// Assign values dynamically using variable variables ($$)
foreach ($emp as $k => $v) {
    $$k = $v;
}

// Close statement
$stmt->close();

$initials = strtoupper(substr($firstname, 0, 1)) . strtoupper(substr($lastname, 0, 1));
$fullname  = htmlspecialchars($lastname . ', ' . $firstname . ($middlename ? ' ' . substr($middlename, 0, 1) . '.' : ''));
?>
<style>
    .emp-profile-bar { display:flex; align-items:center; gap:16px; padding:14px 0 12px; border-bottom:2px solid #d0d7ee; margin-bottom:14px; flex-wrap:wrap; }
    .emp-big-avatar { width:52px; height:52px; border-radius:50%; background:#009688; color:#fff; font-size:20px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; letter-spacing:1px; }
    .emp-profile-name { font-size:17px; font-weight:700; color:#009688; line-height:1.2; }
    .emp-profile-sub { font-size:12px; color:#555; margin-top:3px; }
    .emp-profile-stats { display:flex; gap:20px; margin-left:auto; flex-wrap:wrap; }
    .emp-profile-stat { text-align:right; }
    .emp-profile-stat-val { font-size:13px; font-weight:700; color:#009688; font-family:'Segoe UI',monospace; }
    .emp-profile-stat-lbl { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.3px; }
    .detail-section { border:1px solid #d0d7ee; border-radius:4px; margin-bottom:10px; overflow:hidden; }
    .detail-section-title { background:#009688; color:#fff; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:5px 12px; display:flex; align-items:center; gap:6px; }
    .detail-row { display:flex; flex-wrap:wrap; }
    .detail-item { padding:7px 14px; border-bottom:1px solid #eef0f8; border-right:1px solid #eef0f8; flex:1; min-width:200px; }
    .detail-item:last-child { border-right:none; }
    .detail-label { font-size:10px; color:#888; font-weight:700; text-transform:uppercase; letter-spacing:.3px; margin-bottom:2px; }
    .detail-value { font-size:13px; font-weight:600; color:#1a1a1a; }
    .emp-currency-val { font-weight:700; color:#009688; font-family:'Segoe UI',monospace; }
    .cn-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(210px,1fr)); gap:10px; margin-top:4px; }
    .cn-item { border:1px solid #c5cde8; border-radius:4px; padding:10px 12px; background:#eef0f8; }
    .cn-item label { font-size:10px; color:#009688; font-weight:700; text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:5px; }
    .cn-item .form-control { font-size:13px; font-weight:600; border-color:#c5cde8; }
    .barcode-wrap { background:#f8f9fa; border:1px solid #d0d7ee; border-radius:4px; padding:10px 16px; display:inline-block; margin-top:4px; }
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
                        <!-- Employee Profile Bar -->
                        <div class="emp-profile-bar">
                            <div class="emp-big-avatar"><?= $initials ?></div>
                            <div>
                                <div class="emp-profile-name"><?= $fullname ?></div>
                                <div class="emp-profile-sub">
                                    <i class="ri-briefcase-4-line me-1 text-success"></i><?= htmlspecialchars(ucwords($pname)) ?>
                                    <?php if (!empty($dept_name)): ?>
                                    &nbsp;&bull;&nbsp;
                                    <i class="ri-building-3-line me-1 text-success"></i><?= htmlspecialchars($dept_name) ?>
                                    <?php endif; ?>
                                    &nbsp;&bull;&nbsp;
                                    <span class="badge" style="display:inline-flex;align-items:center;gap:2px;vertical-align:middle;<?= clasif_badge_style($clasification) ?>">
                                        <i class="mdi mdi-circle-medium"></i><?= htmlspecialchars($clasification) ?>
                                    </span>
                                    &nbsp;&bull;&nbsp;
                                    <span class="emp-id" style="font-family:monospace;color:#1976d2;font-weight:700;"><?= htmlspecialchars($employee_no) ?></span>
                                </div>
                            </div>
                            <div class="emp-profile-stats">
                                <div class="emp-profile-stat">
                                    <div class="emp-profile-stat-val">&#8369;<?= number_format($basic_pay, 2) ?></div>
                                    <div class="emp-profile-stat-lbl">Basic Pay</div>
                                </div>
                                <div class="emp-profile-stat">
                                    <div class="emp-profile-stat-val">&#8369;<?= number_format($salary, 2) ?></div>
                                    <div class="emp-profile-stat-lbl">Daily Rate</div>
                                </div>
                                <div class="emp-profile-stat">
                                    <div class="emp-profile-stat-val">&#8369;<?= number_format($ot_rate, 2) ?></div>
                                    <div class="emp-profile-stat-lbl">OT Rate</div>
                                </div>
                                <div class="emp-profile-stat">
                                    <?php if ($weekly_payroll == 1): ?>
                                        <div><span class="badge bg-primary">Weekly</span></div>
                                    <?php else: ?>
                                        <div><span class="badge bg-dark">Monthly</span></div>
                                    <?php endif; ?>
                                    <div class="emp-profile-stat-lbl">Payroll Type</div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <ul class="nav nav-pills arrow-navtabs nav-success bg-light mb-3" role="tablist">
                            <li class="nav-item" role="presentation">
                                <a class="nav-link active" data-bs-toggle="tab" href="#arrow-overview" role="tab">
                                    <i class="ri-user-3-line me-1"></i><span class="d-none d-sm-inline">Overview</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-profile" role="tab">
                                    <i class="ri-bank-card-line me-1"></i><span class="d-none d-sm-inline">Loans</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-contact" role="tab">
                                    <i class="ri-hand-coin-line me-1"></i><span class="d-none d-sm-inline">Contributions</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-cn" role="tab">
                                    <i class="ri-id-card-line me-1"></i><span class="d-none d-sm-inline">Contribution Numbers</span>
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-deductions" role="tab">
                                    <i class="ri-subtract-line me-1"></i><span class="d-none d-sm-inline">Deductions</span>
                                </a>
                            </li>
                            <!-- <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-sites" role="tab">
                                    <i class="ri-map-pin-2-line me-1"></i><span class="d-none d-sm-inline">Sites</span>
                                </a>
                            </li> -->
                            <li class="nav-item" role="presentation">
                                <a class="nav-link" data-bs-toggle="tab" href="#arrow-leave" role="tab">
                                    <i class="ri-calendar-event-line me-1"></i><span class="d-none d-sm-inline">Leave</span>
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
                                    <div class="detail-row">
                                        <div class="detail-item" style="border-right:none;">
                                            <div class="detail-label">Employee ID / Barcode</div>
                                            <div class="barcode-wrap">
                                                <img alt="<?= htmlspecialchars($employee_no) ?>" src="includes/barcode.php?codetype=Code39&size=40&text=<?= urlencode($employee_no) ?>&print=true" />
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
                                            <div class="detail-label">Payroll Type</div>
                                            <div class="detail-value">
                                                <?php if ($weekly_payroll == 1): ?>
                                                    <span class="badge bg-primary"><i class="ri-calendar-2-line me-1"></i>Weekly</span>
                                                <?php else: ?>
                                                    <span class="badge bg-dark"><i class="ri-calendar-check-line me-1"></i>Monthly</span>
                                                <?php endif; ?>
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
                                <div class="table-responsive mt-2">
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
                                            ?>
                                                <tr>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['loan_type']) ?></span></td>
                                                    <td><span style="font-size:12px;color:#555;"><i class="ri-calendar-2-line me-1 text-muted"></i><?= htmlspecialchars($row['loan_date']) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['loan_amount'], 2) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['loan_balance'], 2) ?></span></td>
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
                                        </tbody>
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
                                <div class="table-responsive mt-2">
                                    <table id="table-deductions" class="table table-hover table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th><i class="ri-subtract-line me-1"></i>Deduction Name</th>
                                                <th class="text-end" style="width:200px;"><i class="ri-money-dollar-circle-line me-1"></i>Amount</th>
                                                <th class="text-center" style="width:100px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $deductions = $conn->query("SELECT ea.*,d.deduction as dname FROM employee_deductions ea INNER JOIN deductions d ON d.id = ea.deduction_id WHERE ea.employee_id=" . $emp_id . " ORDER BY ea.type ASC, DATE(ea.effective_date) ASC, d.deduction ASC");
                                            while ($row = $deductions->fetch_assoc()):
                                            ?>
                                                <tr>
                                                    <td><span style="font-weight:600;"><?= htmlspecialchars($row['dname']) ?></span></td>
                                                    <td class="text-end"><span class="emp-currency-val">&#8369; <?= number_format($row['amount'], 2) ?></span></td>
                                                    <td class="text-center">
                                                        <button type="button" data-id="<?= $row['id'] ?>"
                                                            class="btn btn-sm btn-outline-danger remove_deduction"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Deduction">
                                                            <i class="ri-delete-bin-line"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
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
                                if ($bh && $bh->num_rows): ?>
                                <div class="card border mb-3">
                                    <div class="card-header bg-light py-2"><h6 class="mb-0"><i class="ri-history-line me-2 text-success"></i>Balance Change History</h6></div>
                                    <div class="card-body p-0" style="max-height:240px;overflow-y:auto;">
                                        <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                                            <thead class="table-light"><tr><th>When</th><th>Type</th><th class="text-center">Change</th><th>By</th></tr></thead>
                                            <tbody>
                                            <?php while ($h = $bh->fetch_assoc()):
                                                $f = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
                                                $up = (float)$h['new_credits'] >= (float)$h['old_credits'];
                                            ?>
                                                <tr>
                                                    <td><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                                                    <td><?= htmlspecialchars($h['type_name']) ?></td>
                                                    <td class="text-center"><span class="text-muted"><?= $f($h['old_credits']) ?></span> <i class="ri-arrow-right-line <?= $up ? 'text-success' : 'text-danger' ?>"></i> <b class="<?= $up ? 'text-success' : 'text-danger' ?>"><?= $f($h['new_credits']) ?></b></td>
                                                    <td><?= htmlspecialchars($h['changed_name'] ?? 'System') ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>

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
                                        if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', text: j.message, timer: 1000, showConfirmButton: false }).then(() => location.reload());
                                        else location.reload();
                                    } else {
                                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to save.' });
                                        else alert((j && j.message) || 'Failed to save.');
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
