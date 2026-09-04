<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include 'db_connect.php';

// Bulk payslips had no access control at all: ?ids=1,2,3 printed anyone's pay.
// Staff sessions may print any payslip; an employee-portal session is narrowed
// to its OWN items below, so a guessed id cannot leak a colleague's pay.
$is_staff_session    = !empty($_SESSION['is_login']);
$is_employee_session = !empty($_SESSION['emp_is_login']);
if (!$is_staff_session && !$is_employee_session) {
    http_response_code(403);
    exit('Not authorized.');
}

if (!isset($_GET['ids'])) { return; }
$raw_ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
if (empty($raw_ids)) { return; }

if (!$is_staff_session) {
    $__eid = (int) ($_SESSION['emp_id'] ?? 0);
    $__in  = implode(',', array_map('intval', $raw_ids));
    $__own = [];
    $__q = $conn->query("SELECT id FROM payroll_items WHERE id IN ($__in) AND employee_id = $__eid");
    if ($__q) while ($__r = $__q->fetch_assoc()) $__own[] = (int) $__r['id'];
    $raw_ids = $__own;
    if (empty($raw_ids)) {
        http_response_code(403);
        exit('Not authorized.');
    }
}

// Build payslip data for each ID
function buildPayslip($conn, $id) {
    $query = "SELECT a.*,
        l.date_from, l.date_to, l.settings,
        f.site_code, f.site_name, f.site_address,
        e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay,
        d.name as department, p.name as position
    FROM payroll_items AS a
    INNER JOIN payroll l ON l.id = a.payroll_id
    INNER JOIN employee e ON a.employee_id = e.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN position p ON e.position_id = p.id
    LEFT JOIN sites f ON f.id = a.site_id
    WHERE a.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    if (!$p) return null;

    $settings = json_decode($p['settings'], true) ?: [];
    // Per-minute = daily rate / (day hours × 60m), the day length being the
    // employee's shift frozen on the item — matches the payroll page and
    // view_payslip.php (NOT the stored per_minute column, which is /1440).
    $dayHours         = day_hours_or_default($p['day_hours'] ?? null);
    $perMinute        = $p['per_day'] / ($dayHours * 60);
    // The ONE shared formula (payroll_earnings, db_connect.php). The copy that
    // lived here halved absences and the allowance on the monthly basis, and its
    // daily branch dropped holiday, rest-day and night-differential pay — so a
    // bulk-printed payslip could show a different gross than the same employee's
    // individually printed one.
    $__e = payroll_earnings($p);
    $overtime_amount  = $__e['overtime'];
    $undertime_amount = $__e['under_amt'];
    $late_amount      = $__e['late_amt'];
    $absent_amount    = $__e['absent_amt'];
    $allowance_amount = $__e['allowance'];
    $legal_amt        = $__e['legal_amt'];
    $sunday_amt       = $__e['rest_amt'];
    $special_amt      = $__e['special_amt'];
    $rate_type        = $p['rate_type'] ?? 'daily';
    $is_monthly       = $__e['is_monthly'];
    $total_basic_rate = $__e['is_monthly'] ? $p['basic_pay'] : $__e['basic'];
    $gross            = $__e['gross'];

    $contributions_list = []; $deductions_list = []; $loans_list = [];
    $total_cont = 0; $total_ded = 0; $total_loan = 0;
    $contrib_raw = json_decode($p['contributions'], true) ?: [];
    $deduct_raw  = json_decode($p['deductions'],    true) ?: [];
    $loans_raw   = json_decode($p['loans'],         true) ?: [];

    foreach ($settings as $k) {
        $amt = 0;
        if ($k['type'] == 1) {
            $r = $conn->query("SELECT contribution FROM contributions WHERE id={$k['id']}")->fetch_assoc();
            $name = $r['contribution'] ?? '—';
            foreach ($contrib_raw as $kd) { if ($kd['contribution_id'] == $k['id']) $amt = $kd['amount']; }
            $contributions_list[] = ['name' => $name, 'amount' => $amt];
            $total_cont += $amt;
        } elseif ($k['type'] == 2) {
            $r = $conn->query("SELECT deduction FROM deductions WHERE id={$k['id']}")->fetch_assoc();
            $name = $r['deduction'] ?? '—';
            foreach ($deduct_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt = $kd['amount']; }
            $deductions_list[] = ['name' => $name, 'amount' => $amt];
            $total_ded += $amt;
        } elseif ($k['type'] == 3) {
            $r = $conn->query("SELECT loan_type FROM contribution_loan_types WHERE clt_id={$k['id']}")->fetch_assoc();
            $name = $r['loan_type'] ?? '—';
            foreach ($loans_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt = $kd['amount']; }
            $loans_list[] = ['name' => $name, 'amount' => $amt];
            $total_loan += $amt;
        }
    }

    $other  = floatval($p['other_deduction']);
    $tax    = floatval($p['tax']);
    $jei    = floatval($p['jei_advances']);
    $jcc    = floatval($p['jcc_advances']);
    $sss    = floatval($p['sss_fund']);
    // Named one-off items for this employee: kind 1 deducts, kind 2 adds.
    $ps_extras = []; $ps_x_add = $ps_x_less = 0.0;
    if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
        $xq = $conn->query("SELECT kind, label, amount FROM payroll_item_extras WHERE payroll_item_id = " . (int)$p['id'] . " ORDER BY id ASC");
        if ($xq) while ($x = $xq->fetch_assoc()) {
            $ps_extras[] = $x;
            if ((int)$x['kind'] === 2) $ps_x_add += (float)$x['amount']; else $ps_x_less += (float)$x['amount'];
        }
    }
    $gross += $ps_x_add;
    $total_all_ded = $total_cont + $total_ded + $total_loan + $other + $tax + $jei + $jcc + $sss + $ps_x_less;
    // Manual signed adjustment (+ addition / − recovery), applied to net pay.
    $adjustment  = floatval($p['adjustment'] ?? 0);
    $adj_remarks = trim((string) ($p['adjustment_remarks'] ?? ''));

    return compact('ps_extras','ps_x_add','ps_x_less','p','gross','allowance_amount','total_basic_rate','absent_amount',
                   'overtime_amount','late_amount','undertime_amount',
                   'legal_amt','sunday_amt','special_amt',
                   'contributions_list','deductions_list','loans_list',
                   'total_cont','total_ded','total_loan',
                   'other','tax','jei','jcc','sss','total_all_ded',
                   'adjustment','adj_remarks');
}

$payslips = [];
foreach ($raw_ids as $rid) {
    $d = buildPayslip($conn, $rid);
    if ($d) $payslips[] = $d;
}
if (empty($payslips)) { echo "No data found."; return; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslips — Bulk Print</title>
<?php include __DIR__ . "/includes/favicon.php"; ?>
<style>
* { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; box-sizing:border-box; margin:0; padding:0; }
@page { size: A4 portrait; margin: 8mm 10mm; }
body { font-family:'Segoe UI',Calibri,Arial,sans-serif; font-size:10pt; color:#111; background:#e8e8e8; }
@media print {
    body { background:#fff; }
    .ps-wrap { box-shadow:none !important; margin:0 !important; width:100% !important; page-break-after:always; }
    .ps-wrap:last-child { page-break-after:auto; }
    .ps-hdr { background:#6642aa !important; }
    .ps-period { background:#4e3483 !important; }
    .ps-period td { color:rgba(255,255,255,.85) !important; }
    .ps-period td strong { color:#fff !important; }
    .ps-emp { background:#f8f6fb !important; }
    .ps-sec-lbl { background:#f0edf6 !important; }
    .ps-totals td:last-child { background:#6642aa !important; }
    .ps-totals td:last-child .tot-lbl,
    .ps-totals td:last-child .tot-val { color:#fff !important; }
    .ps-foot { background:#f8f6fb !important; }
    .ps-hdr-company,.ps-hdr-badge { color:#fff !important; }
    .ps-hdr-addr { color:rgba(255,255,255,.80) !important; }
}

.ps-wrap { width:780px; margin:16px auto 32px; border:2px solid #4e3483; background:#fff; box-shadow:0 4px 20px rgba(0,0,0,.18); }

/* Header */
.ps-hdr { background:#6642aa; display:table; width:100%; border-bottom:3px solid #4e3483; }
.ps-hdr-logo-cell { display:table-cell; width:68px; padding:14px 10px 14px 16px; vertical-align:middle; }
.ps-hdr-logo-cell img { width:52px; height:52px; border-radius:6px; background:#fff; padding:3px; display:block; }
.ps-hdr-text-cell { display:table-cell; vertical-align:middle; padding:14px 10px; }
.ps-hdr-company { font-size:15pt; font-weight:900; color:#fff; letter-spacing:.3px; line-height:1.2; }
.ps-hdr-addr { font-size:8pt; color:rgba(255,255,255,.80); margin-top:3px; line-height:1.4; }
.ps-hdr-badge-cell { display:table-cell; width:110px; text-align:center; vertical-align:middle; padding:14px 16px 14px 10px; }
.ps-hdr-badge { border:2px solid rgba(255,255,255,.6); color:#fff; font-size:11pt; font-weight:900; letter-spacing:3px; padding:5px 10px; display:inline-block; }

/* Period */
.ps-period { background:#4e3483; display:table; width:100%; border-bottom:1px solid #6642aa; }
.ps-period td { font-size:8pt; color:rgba(255,255,255,.85); padding:5px 16px; white-space:nowrap; }
.ps-period td strong { color:#fff; }
.ps-period .sep { color:rgba(255,255,255,.3); padding:5px 4px; }

/* Employee */
.ps-emp { display:table; width:100%; border-bottom:2px solid #6642aa; background:#f8f6fb; }
.ps-emp td { display:table-cell; width:50%; padding:10px 16px; vertical-align:top; border-right:1px solid #d7d0e6; }
.ps-emp td:last-child { border-right:none; }
.emp-lbl { font-size:7.5pt; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:#111; margin-bottom:2px; }
.emp-val { font-size:11pt; font-weight:800; color:#111; line-height:1.2; }
.emp-sub { font-size:8pt; color:#111; margin-top:2px; }

/* Section label */
.ps-sec-lbl { background:#f0edf6; border-top:1px solid #cabede; border-bottom:1px solid #cabede; padding:4px 16px; font-size:8pt; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#111; }

/* Body */
.ps-body { width:100%; border-collapse:collapse; border-bottom:2px solid #6642aa; }
.ps-body>tbody>tr>td { width:50%; vertical-align:top; padding:10px 14px; border-right:1px solid #d7d0e6; }
.ps-body>tbody>tr>td:last-child { border-right:none; }
.col-title { font-size:8pt; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#111; margin-bottom:8px; padding-bottom:4px; border-bottom:1.5px solid #6642aa; }
.grp-lbl { font-size:7.5pt; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#111; margin:7px 0 3px; padding-top:4px; border-top:1px dotted #ddd; }
.grp-lbl:first-child { border-top:none; margin-top:0; }
.item { width:100%; border-collapse:collapse; margin-bottom:1px; }
.item td { font-size:9pt; padding:2.5px 2px; vertical-align:top; border-bottom:1px solid #f3f3f3; color:#111; }
.item tr:last-child td { border-bottom:none; }
.item .sub-lbl { font-size:8pt; color:#111; padding-left:16px; }
.item .sub-amt { font-size:8pt; color:#111; font-weight:600; text-align:right; width:90px; white-space:nowrap; }
.item.bold td { font-weight:700; color:#111; }
.item .amt { text-align:right; font-weight:700; color:#111; white-space:nowrap; width:90px; }

/* Totals */
.ps-totals { width:100%; border-collapse:collapse; border-bottom:2px solid #4e3483; }
.ps-totals td { width:33.33%; padding:10px 16px; text-align:center; border-right:1px solid #d7d0e6; vertical-align:middle; }
.ps-totals td:last-child { border-right:none; background:#6642aa; }
.tot-lbl { font-size:7.5pt; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#111; margin-bottom:3px; }
.tot-val { font-size:14pt; font-weight:900; color:#111; }
.ps-totals td:last-child .tot-lbl { color:#fff; }
.ps-totals td:last-child .tot-val { color:#fff; font-size:16pt; }

/* Signature */
.ps-sig { width:100%; border-collapse:collapse; border-bottom:1px solid #d7d0e6; }
.ps-sig td { padding:12px 16px; vertical-align:bottom; border-right:1px solid #e8e8e8; }
.ps-sig td:last-child { border-right:none; text-align:right; width:160px; }
.ack-text { font-size:8.5pt; color:#111; line-height:1.5; margin-bottom:28px; }
.sig-line { border-top:1px solid #333; margin-top:28px; }
.sig-lbl { font-size:7.5pt; color:#111; text-align:center; margin-top:2px; }
.date-lbl { font-size:7.5pt; color:#111; }
.date-val { font-size:10pt; font-weight:700; color:#111; margin-top:2px; }

/* Footer */
.ps-foot { background:#f8f6fb; border-top:1px solid #d7d0e6; display:table; width:100%; }
.ps-foot td { display:table-cell; font-size:7.5pt; color:#111; padding:5px 16px; vertical-align:middle; }
.ps-foot td:last-child { text-align:right; }
</style>
</head>
<body>

<?php foreach ($payslips as $ps):
    $p = $ps['p'];
    // Named one-off items for this employee (see buildPayslip)
    $ps_extras = $ps['ps_extras'];
    $ps_x_add  = $ps['ps_x_add'];
    $ps_x_less = $ps['ps_x_less'];
    $gross            = $ps['gross'];
    $allowance_amount = $ps['allowance_amount'];
    $total_basic_rate = $ps['total_basic_rate'];
    $absent_amount    = $ps['absent_amount'];
    $overtime_amount  = $ps['overtime_amount'];
    $late_amount      = $ps['late_amount'];
    $undertime_amount = $ps['undertime_amount'];
    $legal_amt        = $ps['legal_amt'];
    $sunday_amt       = $ps['sunday_amt'];
    $special_amt      = $ps['special_amt'];
    $contributions_list = $ps['contributions_list'];
    $deductions_list    = $ps['deductions_list'];
    $loans_list         = $ps['loans_list'];
    $total_all_ded   = $ps['total_all_ded'];
    $other  = $ps['other'];
    $tax    = $ps['tax'];
    $jei    = $ps['jei'];
    $jcc    = $ps['jcc'];
    $sss    = $ps['sss'];
    $adjustment  = $ps['adjustment'];
    $adj_remarks = $ps['adj_remarks'];
    $net_pay = $p['net'];
?>

<div class="ps-wrap">

<!-- HEADER -->
<div class="ps-hdr">
    <div class="ps-hdr-logo-cell"><img src="assets/images/logo.jpeg" alt="Logo"></div>
    <div class="ps-hdr-text-cell">
        <div class="ps-hdr-company">COMC</div>
        <div class="ps-hdr-addr">Tiano Brothers Street, Nacalaban Street, Cagayan De Oro City, 9000 Misamis Oriental</div>
    </div>
    <div class="ps-hdr-badge-cell"><div class="ps-hdr-badge">PAYSLIP</div></div>
</div>

<!-- PERIOD BAR -->
<table class="ps-period" cellspacing="0" cellpadding="0"><tr>
    <td>Pay Period: <strong><?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?></strong></td>
    <td class="sep">|</td>
    <td>Site: <strong><?= htmlspecialchars($p['site_name'] ?? '—') ?></strong></td>
    <td class="sep">|</td>
    <td>Code: <strong><?= htmlspecialchars($p['site_code'] ?? '—') ?></strong></td>
</tr></table>

<!-- EMPLOYEE INFO -->
<table class="ps-emp" cellspacing="0" cellpadding="0"><tr>
    <td>
        <div class="emp-lbl">Employee</div>
        <div class="emp-val"><?= htmlspecialchars($p['lastname'].', '.$p['firstname'].' '.$p['middlename']) ?></div>
        <div class="emp-sub">Employee No: <?= htmlspecialchars($p['employee_no']) ?></div>
    </td>
    <td>
        <div class="emp-lbl">Position / Department</div>
        <div class="emp-val"><?= htmlspecialchars($p['position'] ?? '—') ?></div>
        <div class="emp-sub"><?= htmlspecialchars($p['department'] ?? '—') ?></div>
    </td>
</tr></table>

<div class="ps-sec-lbl">Earnings &amp; Deductions</div>

<!-- BODY -->
<table class="ps-body" cellspacing="0" cellpadding="0"><tbody><tr>
<td>
    <div class="col-title">Earnings</div>
    <div class="grp-lbl">Basic Pay</div>
    <table class="item bold"><tr><td>Days Present</td><td class="amt"><?= number_format($p['present'], 1) ?> days</td></tr></table>
    <table class="item bold"><tr><td>Daily Rate</td><td class="amt">₱ <?= number_format($p['per_day'], 2) ?></td></tr></table>
    <table class="item bold"><tr><td>Basic Salary</td><td class="amt">₱ <?= number_format($total_basic_rate, 2) ?></td></tr></table>
    <?php if ($allowance_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Allowance (<?= $p['allowance_days'] ?>d × ₱<?= number_format($p['allowance_amount'],2) ?>)</td><td class="sub-amt">₱ <?= number_format($allowance_amount, 2) ?></td></tr></table>
    <?php endif; ?>
    <?php if ($p['legal_holiday'] > 0 || $p['sunday_duty'] > 0 || $p['special_holiday'] > 0): ?>
    <div class="grp-lbl">Holidays &amp; Extra</div>
    <?php if ($p['legal_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Legal Holiday (<?= $p['legal_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($legal_amt, 2) ?></td></tr></table>
    <?php endif; if ($p['sunday_duty'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Rest Day Duty (<?= $p['sunday_duty'] ?> day)</td><td class="sub-amt">₱ <?= number_format($sunday_amt, 2) ?></td></tr></table>
    <?php endif; if ($p['special_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Special Holiday (<?= $p['special_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($special_amt, 2) ?></td></tr></table>
    <?php endif; endif; ?>
    <?php if ($p['ot'] > 0): ?>
    <div class="grp-lbl">Overtime</div>
    <table class="item"><tr><td class="sub-lbl"><?= number_format($p['ot'], 2) ?> hrs × ₱<?= number_format($p['ot_rate'],2) ?>/hr</td><td class="sub-amt">₱ <?= number_format($overtime_amount, 2) ?></td></tr></table>
    <?php endif; ?>
    <?php /* One-off allowances added for this employee alone */ ?>
    <?php if ($ps_x_add > 0): ?>
    <div class="grp-lbl">Other Earnings</div>
    <?php foreach ($ps_extras as $x): if ((int)$x['kind'] !== 2) continue; ?>
    <table class="item"><tr><td class="sub-lbl"><?= htmlspecialchars($x['label']) ?></td><td class="sub-amt">₱ <?= number_format($x['amount'],2) ?></td></tr></table>
    <?php endforeach; endif; ?>
    <?php if ($p['absent'] > 0 || $late_amount > 0 || $undertime_amount > 0): ?>
    <div class="grp-lbl">Adjustments (deducted)</div>
    <?php if ($p['absent'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Absences (<?= $p['absent'] ?> day)</td><td class="sub-amt">– ₱ <?= number_format($absent_amount, 2) ?></td></tr></table>
    <?php endif; if ($late_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Late (<?= number_format($p['late']) ?> min)</td><td class="sub-amt">– ₱ <?= number_format($late_amount, 2) ?></td></tr></table>
    <?php endif; if ($undertime_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Undertime (<?= number_format($p['under_time']) ?> min)</td><td class="sub-amt">– ₱ <?= number_format($undertime_amount, 2) ?></td></tr></table>
    <?php endif; endif; ?>
</td>
<td>
    <div class="col-title">Deductions</div>
    <?php if (!empty($contributions_list)): ?>
    <div class="grp-lbl">Government Contributions</div>
    <?php foreach ($contributions_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr><td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td><td class="sub-amt">₱ <?= number_format($c['amount'], 2) ?></td></tr></table>
    <?php endforeach; endif; ?>
    <?php if (!empty($deductions_list)): ?>
    <div class="grp-lbl">Other Deductions</div>
    <?php foreach ($deductions_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr><td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td><td class="sub-amt">₱ <?= number_format($c['amount'], 2) ?></td></tr></table>
    <?php endforeach; endif; ?>
    <?php if (!empty($loans_list)): ?>
    <div class="grp-lbl">Loans</div>
    <?php foreach ($loans_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr><td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td><td class="sub-amt">₱ <?= number_format($c['amount'], 2) ?></td></tr></table>
    <?php endforeach; endif; ?>
    <?php /* One-off items added for this employee alone */ ?>
    <?php if ($ps_x_less > 0): ?>
    <div class="grp-lbl">Other Deductions</div>
    <?php foreach ($ps_extras as $x): if ((int)$x['kind'] !== 1) continue; ?>
    <table class="item"><tr><td class="sub-lbl"><?= htmlspecialchars($x['label']) ?></td><td class="sub-amt">₱ <?= number_format($x['amount'],2) ?></td></tr></table>
    <?php endforeach; endif; ?>
    <?php if ($sss > 0 || $jei > 0 || $jcc > 0 || $tax > 0 || $other > 0): ?>
    <div class="grp-lbl">Miscellaneous</div>
    <?php if ($sss > 0): ?><table class="item"><tr><td class="sub-lbl">SSS Provident Fund</td><td class="sub-amt">₱ <?= number_format($sss,2) ?></td></tr></table><?php endif; ?>
    <?php if ($jei > 0): ?><table class="item"><tr><td class="sub-lbl">JEI Advances</td><td class="sub-amt">₱ <?= number_format($jei,2) ?></td></tr></table><?php endif; ?>
    <?php if ($jcc > 0): ?><table class="item"><tr><td class="sub-lbl">JCC Advances</td><td class="sub-amt">₱ <?= number_format($jcc,2) ?></td></tr></table><?php endif; ?>
    <?php if ($tax > 0): ?><table class="item"><tr><td class="sub-lbl">Withholding Tax</td><td class="sub-amt">₱ <?= number_format($tax,2) ?></td></tr></table><?php endif; ?>
    <?php if ($other > 0): ?><table class="item"><tr><td class="sub-lbl">Other Deduction</td><td class="sub-amt">₱ <?= number_format($other,2) ?></td></tr></table><?php endif; ?>
    <?php endif; ?>
</td>
</tr></tbody></table>

<!-- TOTALS -->
<table class="ps-totals" cellspacing="0" cellpadding="0"><tr>
    <td><div class="tot-lbl">Gross Pay</div><div class="tot-val">₱ <?= number_format($gross, 2) ?></div></td>
    <td><div class="tot-lbl">Total Deductions</div><div class="tot-val">₱ <?= number_format($total_all_ded, 2) ?></div></td>
    <?php if ($adjustment != 0): ?>
    <td><div class="tot-lbl">Adjustment<?= $adj_remarks !== '' ? ' — ' . htmlspecialchars($adj_remarks) : '' ?></div><div class="tot-val"><?= $adjustment < 0 ? '−' : '+' ?> ₱ <?= number_format(abs($adjustment), 2) ?></div></td>
    <?php endif; ?>
    <td><div class="tot-lbl">Net Pay</div><div class="tot-val">₱ <?= number_format($net_pay, 2) ?></div></td>
</tr></table>

<!-- SIGNATURE -->
<table class="ps-sig" cellspacing="0" cellpadding="0"><tr>
    <td>
        <div class="ack-text">I hereby acknowledge receipt of the net pay amount of <strong>₱ <?= number_format($net_pay, 2) ?></strong> for the pay period <strong><?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?></strong>.</div>
        <div class="sig-line"></div>
        <div class="sig-lbl"><?= htmlspecialchars($p['lastname'].', '.$p['firstname'].' '.$p['middlename']) ?> — Signature over Printed Name</div>
    </td>
    <td>
        <div class="date-lbl">Date Received</div>
        <div class="date-val"><?= date('F d, Y') ?></div>
    </td>
</tr></table>

<!-- FOOTER -->
<table class="ps-foot" cellspacing="0" cellpadding="0"><tr>
    <td>COMC — Confidential Payroll Document</td>
    <td>Generated: <?= date('M d, Y g:i A') ?></td>
</tr></table>

</div><!-- .ps-wrap -->

<?php endforeach; ?>

<?php if (empty($_GET['preview'])): // no auto-print when shown inside the preview modal ?>
<script>
window.print();
window.onafterprint = function() { window.close(); };
</script>
<?php endif; ?>
</body>
</html>
