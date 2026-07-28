<?php
// Reports hub — landing page linking every accounting/HRIS report.
// Included via index.php (?page=reports).
$report_groups = [
    'Accounting' => [
        ['payroll-register',       'Payroll Register',        'ri-file-list-3-line',       '#673bb6', 'Per-run register: gross, contributions, deductions, loans, tax and net pay for every employee.'],
        ['loan-deduction-ledger',  'Loan & Deduction Ledger', 'ri-bank-card-line',         '#d32f2f', 'Running balances and full payment history for loans and amortizing deductions.'],
        ['remittance-report',      'Government Remittance',    'ri-government-line',         '#1976d2', 'SSS, PhilHealth, Pag-IBIG, provident fund and withholding tax per period.'],
        ['payroll-comparison',     'Payroll Comparison',       'ri-git-commit-line',        '#6f42c1', 'Compare two payroll periods side by side to spot variances.'],
        ['payroll-report',         'Payroll List Report',      'ri-list-check-2',           '#f57c00', 'List of all payroll runs with status and totals.'],
        ['bank-payout',            'Bank Payout',              'ri-bank-line',              '#57339d', 'Per-payroll net pay with each employee\'s bank and account number — CSV for bank upload.'],
        ['bir-alphalist',          'BIR Alphalist',            'ri-file-shield-2-line',     '#5d4037', 'Annual per-employee compensation, non-taxable items and tax withheld — annex to Form 1604-C.'],
    ],
    'HRIS' => [
        ['employee-masterlist',    'Employee Masterlist',      'ri-team-line',              '#673bb6', 'Full roster: IDs, position, department, government numbers, salary and status.'],
        ['attendance-summary',     'Attendance / DTR Summary', 'ri-time-line',              '#1976d2', 'Present days, hours, late, undertime and overtime per employee for a period.'],
        ['leave-balances-report',  'Leave Balances',           'ri-coins-line',             '#8e24aa', 'Entitled, used, pending and remaining leave days per employee and per leave type — with Excel and PDF export.'],
    ],
];
?>
<style>
    .rep-hub-card { display:block; background:#fff; border:1px solid #e6eaf0; border-radius:12px; padding:18px; height:100%; text-decoration:none; transition:.15s; border-top:3px solid transparent; }
    .rep-hub-card:hover { box-shadow:0 6px 18px rgba(0,0,0,.09); transform:translateY(-2px); }
    .rep-hub-ic { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:12px; }
    .rep-hub-title { font-size:14px; font-weight:800; color:#2b2f36; margin-bottom:4px; }
    .rep-hub-desc { font-size:12px; color:#7a828c; line-height:1.4; }
    .rep-group-title { font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:#673bb6; font-weight:800; margin:18px 0 10px; }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-2"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-bar-chart-box-line me-2" style="color:#673bb6;"></i>Reports</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </div>
    </div></div>

    <?php foreach ($report_groups as $group => $items): ?>
        <div class="rep-group-title"><?= htmlspecialchars($group) ?></div>
        <div class="row g-3">
            <?php foreach ($items as $it): ?>
            <div class="col-md-6 col-lg-4">
                <a class="rep-hub-card" href="index.php?page=<?= $it[0] ?>" style="border-top-color:<?= $it[3] ?>;">
                    <div class="rep-hub-ic" style="background:<?= $it[3] ?>1a;color:<?= $it[3] ?>;"><i class="<?= $it[2] ?>"></i></div>
                    <div class="rep-hub-title"><?= htmlspecialchars($it[1]) ?></div>
                    <div class="rep-hub-desc"><?= htmlspecialchars($it[4]) ?></div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

</div>
</div>
</div>
