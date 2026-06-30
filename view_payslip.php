<?php
include 'db_connect.php';
if (!isset($_GET['id'])) { return; }
$id = (int)$_GET['id'];

$query = "SELECT a.*,
    l.date_from, l.date_to, l.settings, l.ref_no,
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
$payroll = $stmt->get_result()->fetch_assoc();

$contributions_settings = json_decode($payroll['settings'], true) ?: [];
$perMinute        = $payroll['per_minute'];
$overtime_amount  = $payroll['ot'] * $payroll['ot_rate'];
$undertime_amount = $payroll['under_time'] * $perMinute;
$late_amount      = $payroll['late'] * $perMinute;
$absent_amount    = $payroll['absent'] * $payroll['per_day'];
$allowance_amount = $payroll['allowance_amount'] * $payroll['allowance_days'];
$total_basic_rate = $payroll['present'] * $payroll['per_day'];

$legal_holiday_amt   = $payroll['legal_holiday']   * $payroll['per_day'];
$sunday_duty_amt     = $payroll['sunday_duty']      * $payroll['per_day'];
$special_holiday_amt = (($payroll['per_day'] / 8) * 2.4) * $payroll['special_holiday'];

$gross_salary = ($total_basic_rate + $allowance_amount - $absent_amount)
              + $overtime_amount + $legal_holiday_amt + $sunday_duty_amt + $special_holiday_amt
              - $late_amount - $undertime_amount;

// Build deductions breakdown
$contributions_list = [];
$deductions_list    = [];
$loans_list         = [];
$total_contributions = 0;
$total_deductions    = 0;
$total_loans         = 0;

$contributions_raw = json_decode($payroll['contributions'], true) ?: [];
$deductions_raw    = json_decode($payroll['deductions'],    true) ?: [];
$loans_raw         = json_decode($payroll['loans'],         true) ?: [];

foreach ($contributions_settings as $k) {
    $amt = 0;
    if ($k['type'] == 1) {
        $r = $conn->query("SELECT contribution FROM contributions WHERE id={$k['id']}")->fetch_assoc();
        $name = $r['contribution'] ?? '—';
        foreach ($contributions_raw as $kd) { if ($kd['contribution_id'] == $k['id']) $amt = $kd['amount']; }
        $contributions_list[] = ['name' => $name, 'amount' => $amt];
        $total_contributions += $amt;
    } elseif ($k['type'] == 2) {
        $r = $conn->query("SELECT deduction FROM deductions WHERE id={$k['id']}")->fetch_assoc();
        $name = $r['deduction'] ?? '—';
        foreach ($deductions_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt = $kd['amount']; }
        $deductions_list[] = ['name' => $name, 'amount' => $amt];
        $total_deductions += $amt;
    } elseif ($k['type'] == 3) {
        $r = $conn->query("SELECT loan_type FROM contribution_loan_types WHERE clt_id={$k['id']}")->fetch_assoc();
        $name = $r['loan_type'] ?? '—';
        foreach ($loans_raw as $kd) { if ($kd['deduction_id'] == $k['id']) $amt = $kd['amount']; }
        $loans_list[] = ['name' => $name, 'amount' => $amt];
        $total_loans += $amt;
    }
}

$other_deduction = floatval($payroll['other_deduction']);
$tax             = floatval($payroll['tax']);
$jei_advances    = floatval($payroll['jei_advances']);
$jcc_advances    = floatval($payroll['jcc_advances']);
$sss_fund        = floatval($payroll['sss_fund']);
$total_all_deductions = $total_contributions + $total_deductions + $total_loans
                      + $other_deduction + $tax + $jei_advances + $jcc_advances + $sss_fund;
$net_pay = $payroll['net'];

// ── Net pay amount in words (Philippine Peso) ──
function jp_num_to_words($num) {
    $num = (int)$num;
    if ($num == 0) return 'zero';
    $ones = ['','one','two','three','four','five','six','seven','eight','nine','ten',
        'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $w = '';
    if ($num >= 1000000) { $w .= jp_num_to_words(intdiv($num,1000000)).' million '; $num %= 1000000; }
    if ($num >= 1000)    { $w .= jp_num_to_words(intdiv($num,1000)).' thousand '; $num %= 1000; }
    if ($num >= 100)     { $w .= $ones[intdiv($num,100)].' hundred '; $num %= 100; }
    if ($num >= 20)      { $w .= $tens[intdiv($num,10)]; if ($num%10) $w .= '-'.$ones[$num%10]; $w .= ' '; }
    elseif ($num > 0)    { $w .= $ones[$num].' '; }
    return trim($w);
}
function jp_peso_words($amount) {
    $amount = round($amount, 2);
    $whole  = floor($amount);
    $cents  = (int)round(($amount - $whole) * 100);
    $words  = ucwords(jp_num_to_words($whole)).' Peso'.($whole == 1 ? '' : 's');
    if ($cents > 0) $words .= ' and '.ucwords(jp_num_to_words($cents)).' Centavo'.($cents == 1 ? '' : 's');
    return $words.' Only';
}
$net_in_words = jp_peso_words($net_pay);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip — <?= htmlspecialchars($payroll['lastname'].', '.$payroll['firstname']) ?></title>
<link href="assets/css/icons.min.css" rel="stylesheet">
<style>
/* ── Force backgrounds & colors to print in ALL browsers ── */
* {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    box-sizing: border-box;
    margin: 0; padding: 0;
}

@page {
    size: A4 portrait;
    margin: 8mm 10mm;
}

body {
    font-family: 'Segoe UI', Calibri, Arial, sans-serif;
    font-size: 10pt;
    color: #111;
    background: #e8e8e8;
}

/* ── Re-declare backgrounds in @media print as fallback ── */
@media print {
    body { background: #fff; }
    .ps-wrap { box-shadow: none !important; margin: 0 !important; width: 100% !important; }

    .ps-hdr              { background: #219688 !important; }
    .ps-period           { background: #176358 !important; }
    .ps-period td        { color: rgba(255,255,255,.85) !important; }
    .ps-period td strong { color: #fff !important; }
    .ps-emp              { background: #f4fbfa !important; }
    .ps-sec-lbl          { background: #eaf6f4 !important; }
    .ps-totals td:last-child { background: #219688 !important; }
    .ps-totals td:last-child .tot-lbl { color: #fff !important; }
    .ps-totals td:last-child .tot-val { color: #fff !important; }
    .ps-foot             { background: #f4fbfa !important; }
    .ps-hdr-company      { color: #fff !important; }
    .ps-hdr-addr         { color: rgba(255,255,255,.80) !important; }
    .ps-hdr-badge        { color: #fff !important; border-color: rgba(255,255,255,.7) !important; }
}

/* ── Outer wrapper ── */
.ps-wrap {
    width: 780px;
    margin: 16px auto;
    border: 2px solid #176358;
    background: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
}

/* ─────────────── HEADER ─────────────── */
.ps-hdr {
    background: #219688;
    display: table;
    width: 100%;
    border-bottom: 3px solid #176358;
}
.ps-hdr-logo-cell {
    display: table-cell;
    width: 68px;
    padding: 14px 10px 14px 16px;
    vertical-align: middle;
}
.ps-hdr-logo-cell img {
    width: 52px; height: 52px;
    border-radius: 6px;
    background: #fff;
    padding: 3px;
    display: block;
}
.ps-hdr-text-cell {
    display: table-cell;
    vertical-align: middle;
    padding: 14px 10px;
}
.ps-hdr-company {
    font-size: 15pt;
    font-weight: 900;
    color: #fff;
    letter-spacing: .3px;
    line-height: 1.2;
}
.ps-hdr-addr {
    font-size: 8pt;
    color: rgba(255,255,255,.80);
    margin-top: 3px;
    line-height: 1.4;
}
.ps-hdr-badge-cell {
    display: table-cell;
    width: 110px;
    text-align: center;
    vertical-align: middle;
    padding: 14px 16px 14px 10px;
}
.ps-hdr-badge {
    border: 2px solid rgba(255,255,255,.6);
    color: #fff;
    font-size: 11pt;
    font-weight: 900;
    letter-spacing: 3px;
    padding: 5px 10px;
    display: inline-block;
}

/* ─────────────── PERIOD BAR ─────────────── */
.ps-period {
    background: #176358;
    display: table;
    width: 100%;
    border-bottom: 1px solid #219688;
}
.ps-period td {
    font-size: 8pt;
    color: rgba(255,255,255,.85);
    padding: 5px 16px;
    white-space: nowrap;
}
.ps-period td strong { color: #fff; }
.ps-period .sep { color: rgba(255,255,255,.3); padding: 5px 4px; }

/* ─────────────── EMPLOYEE INFO ─────────────── */
.ps-emp {
    display: table;
    width: 100%;
    border-bottom: 2px solid #219688;
    background: #f4fbfa;
}
.ps-emp td {
    display: table-cell;
    width: 50%;
    padding: 10px 16px;
    vertical-align: top;
    border-right: 1px solid #c8e6e2;
}
.ps-emp td:last-child { border-right: none; }
.emp-lbl { font-size: 7.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #111; margin-bottom: 2px; }
.emp-val { font-size: 11pt; font-weight: 800; color: #111; line-height: 1.2; }
.emp-sub { font-size: 8pt; color: #111; margin-top: 2px; }

/* ─────────────── SECTION LABEL ─────────────── */
.ps-sec-lbl {
    background: #eaf6f4;
    border-top: 1px solid #b2dfdb;
    border-bottom: 1px solid #b2dfdb;
    padding: 4px 16px;
    font-size: 8pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: #111;
}

/* ─────────────── BODY (earnings / deductions) ─────────────── */
.ps-body {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 2px solid #219688;
}
.ps-body > tbody > tr > td {
    width: 50%;
    vertical-align: top;
    padding: 10px 14px;
    border-right: 1px solid #c8e6e2;
}
.ps-body > tbody > tr > td:last-child { border-right: none; }

.col-title {
    font-size: 8pt; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    color: #111; margin-bottom: 8px;
    padding-bottom: 4px;
    border-bottom: 1.5px solid #219688;
}
.grp-lbl {
    font-size: 7.5pt; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    color: #111; margin: 7px 0 3px;
    padding-top: 4px;
    border-top: 1px dotted #ddd;
}
.grp-lbl:first-child { border-top: none; margin-top: 0; }
.grp-lbl.red { color: #111; border-top-color: #ddd; }

.item {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1px;
}
.item td {
    font-size: 9pt;
    padding: 2.5px 2px;
    vertical-align: top;
    border-bottom: 1px solid #f3f3f3;
    color: #111;
}
.item tr:last-child td { border-bottom: none; }
.item .lbl { padding-left: 8px; }
.item .amt { text-align: right; font-weight: 700; color: #111; white-space: nowrap; width: 90px; }
.item .amt.red { color: #111; }
.item.bold td { font-weight: 700; color: #111; }
.item .sub-lbl { font-size: 8pt; color: #111; padding-left: 16px; }
.item .sub-amt { font-size: 8pt; color: #111; font-weight: 600; text-align: right; width: 90px; white-space: nowrap; }
.item .sub-amt.red { color: #111; }

/* ─────────────── TOTALS ─────────────── */
.ps-totals {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 2px solid #176358;
}
.ps-totals td {
    width: 33.33%;
    padding: 10px 16px;
    text-align: center;
    border-right: 1px solid #c8e6e2;
    vertical-align: middle;
}
.ps-totals td:last-child { border-right: none; background: #219688; }
.tot-lbl { font-size: 7.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: #111; margin-bottom: 3px; }
.tot-val { font-size: 14pt; font-weight: 900; color: #111; }
.tot-val.red { color: #111; }
.ps-totals td:last-child .tot-lbl { color: #fff; }
.ps-totals td:last-child .tot-val { color: #fff; font-size: 16pt; }

/* ─────────────── SIGNATURE ─────────────── */
.ps-sig {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1px solid #c8e6e2;
}
.ps-sig td {
    padding: 12px 16px;
    vertical-align: bottom;
    border-right: 1px solid #e8e8e8;
}
.ps-sig td:last-child { border-right: none; text-align: right; width: 160px; }
.ack-text { font-size: 8.5pt; color: #111; line-height: 1.5; margin-bottom: 28px; }
.sig-line { border-top: 1px solid #333; margin-top: 28px; }
.sig-lbl  { font-size: 7.5pt; color: #111; text-align: center; margin-top: 2px; }
.date-lbl { font-size: 7.5pt; color: #111; }
.date-val { font-size: 10pt; font-weight: 700; color: #111; margin-top: 2px; }

/* ─────────────── FOOTER ─────────────── */
.ps-foot {
    background: #f4fbfa;
    border-top: 1px solid #c8e6e2;
    display: table;
    width: 100%;
}
.ps-foot td {
    display: table-cell;
    font-size: 7.5pt;
    color: #111;
    padding: 5px 16px;
    vertical-align: middle;
}
.ps-foot td:last-child { text-align: right; }

/* ─────────────── ATTENDANCE / COMPUTATION SUMMARY ─────────────── */
.ps-summary {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 2px solid #219688;
    background: #f9fdfc;
}
.ps-summary td {
    text-align: center;
    padding: 7px 4px;
    border-right: 1px solid #d9ece9;
    vertical-align: middle;
}
.ps-summary td:last-child { border-right: none; }
.sum-val { font-size: 12pt; font-weight: 900; color: #176358; line-height: 1; }
.sum-val.warn { color: #c0392b; }
.sum-val.amber { color: #b8860b; }
.sum-lbl { font-size: 6.8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #555; margin-top: 3px; }

/* ─────────────── AMOUNT IN WORDS ─────────────── */
.ps-words {
    background: #eaf6f4;
    border-bottom: 2px solid #219688;
    padding: 7px 16px;
    display: table;
    width: 100%;
}
.ps-words .w-lbl {
    display: table-cell;
    width: 92px;
    font-size: 7.5pt;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #176358;
    vertical-align: middle;
}
.ps-words .w-val {
    display: table-cell;
    font-size: 9.5pt;
    font-weight: 700;
    font-style: italic;
    color: #111;
    vertical-align: middle;
}

/* ─────────────── SCREEN TOOLBAR (hidden in print) ─────────────── */
.ps-toolbar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #176358;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 9px 16px; z-index: 9999;
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
}
.ps-toolbar .tb-title { color: #fff; font-size: 11pt; font-weight: 800; margin-right: auto; display: flex; align-items: center; gap: 8px; }
.ps-toolbar .tb-title small { font-weight: 400; opacity: .8; font-size: 8.5pt; }
.tb-btn {
    border: none; border-radius: 6px; padding: 7px 16px;
    font-size: 9.5pt; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
}
.tb-btn.print { background: #fff; color: #176358; }
.tb-btn.print:hover { background: #e6f5f3; }
.tb-btn.close { background: rgba(255,255,255,.16); color: #fff; }
.tb-btn.close:hover { background: rgba(255,255,255,.28); }
body.has-toolbar { padding-top: 50px; }
@media print { .ps-toolbar { display: none !important; } body.has-toolbar { padding-top: 0 !important; } }
</style>
</head>
<body class="has-toolbar">

<!-- ══ SCREEN TOOLBAR (not printed) ══ -->
<div class="ps-toolbar">
    <div class="tb-title"><i class="ri-file-text-line"></i> Payslip <small><?= htmlspecialchars($payroll['ref_no'] ?? '') ?></small></div>
    <button class="tb-btn print" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
    <a class="tb-btn close" href="javascript:void(0);" onclick="window.close(); history.back();"><i class="ri-close-line"></i> Close</a>
</div>

<div class="ps-wrap">

<!-- ══ HEADER ══ -->
<div class="ps-hdr">
    <div class="ps-hdr-logo-cell"><img src="assets/images/logo.jpeg" alt="Logo"></div>
    <div class="ps-hdr-text-cell">
        <div class="ps-hdr-company">JEJORS CONSTRUCTION CORPORATION</div>
        <div class="ps-hdr-addr">Tiu Sons, Building Barangay 33, Guillermo Cogon, Cagayan de Oro City</div>
    </div>
    <div class="ps-hdr-badge-cell">
        <div class="ps-hdr-badge">PAYSLIP</div>
        <?php if (!empty($payroll['ref_no'])): ?>
        <div style="font-size:7pt;color:rgba(255,255,255,.85);margin-top:4px;letter-spacing:.5px;"><?= htmlspecialchars($payroll['ref_no']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ PERIOD BAR ══ -->
<table class="ps-period" cellspacing="0" cellpadding="0">
<tr>
    <td>Pay Period: <strong><?= date('M d', strtotime($payroll['date_from'])) ?> – <?= date('M d, Y', strtotime($payroll['date_to'])) ?></strong></td>
    <td class="sep">|</td>
    <td>Site: <strong><?= htmlspecialchars($payroll['site_name'] ?? '—') ?></strong></td>
    <td class="sep">|</td>
    <td>Code: <strong><?= htmlspecialchars($payroll['site_code'] ?? '—') ?></strong></td>
</tr>
</table>

<!-- ══ EMPLOYEE INFO ══ -->
<table class="ps-emp" cellspacing="0" cellpadding="0">
<tr>
    <td>
        <div class="emp-lbl">Employee</div>
        <div class="emp-val"><?= htmlspecialchars($payroll['lastname'].', '.$payroll['firstname'].' '.$payroll['middlename']) ?></div>
        <div class="emp-sub">Employee No: <?= htmlspecialchars($payroll['employee_no']) ?></div>
    </td>
    <td>
        <div class="emp-lbl">Position / Department</div>
        <div class="emp-val"><?= htmlspecialchars($payroll['position'] ?? '—') ?></div>
        <div class="emp-sub"><?= htmlspecialchars($payroll['department'] ?? '—') ?></div>
    </td>
</tr>
</table>

<!-- ══ ATTENDANCE / COMPUTATION SUMMARY ══ -->
<table class="ps-summary" cellspacing="0" cellpadding="0">
<tr>
    <td><div class="sum-val"><?= number_format($payroll['present'], 1) ?></div><div class="sum-lbl">Days Present</div></td>
    <td><div class="sum-val warn"><?= number_format($payroll['absent'], 1) ?></div><div class="sum-lbl">Days Absent</div></td>
    <td><div class="sum-val amber"><?= number_format($payroll['ot'], 1) ?></div><div class="sum-lbl">OT Hours</div></td>
    <td><div class="sum-val warn"><?= number_format($payroll['late']) ?></div><div class="sum-lbl">Late (min)</div></td>
    <td><div class="sum-val warn"><?= number_format($payroll['under_time']) ?></div><div class="sum-lbl">Undertime (min)</div></td>
    <td><div class="sum-val"><?= number_format($payroll['legal_holiday'] + $payroll['sunday_duty'] + $payroll['special_holiday'], 0) ?></div><div class="sum-lbl">Holiday/Special</div></td>
    <td><div class="sum-val">₱<?= number_format($payroll['per_day'], 0) ?></div><div class="sum-lbl">Daily Rate</div></td>
</tr>
</table>

<!-- ══ SECTION LABEL ══ -->
<div class="ps-sec-lbl">Earnings &amp; Deductions</div>

<!-- ══ BODY (two-column table) ══ -->
<table class="ps-body" cellspacing="0" cellpadding="0">
<tbody><tr>

<!-- LEFT: EARNINGS -->
<td>
    <div class="col-title">Earnings</div>

    <div class="grp-lbl">Basic Pay</div>
    <table class="item bold"><tr><td>Days Present</td><td class="amt"><?= number_format($payroll['present'], 1) ?> days</td></tr></table>
    <table class="item bold"><tr><td>Daily Rate</td><td class="amt">₱ <?= number_format($payroll['per_day'], 2) ?></td></tr></table>
    <table class="item bold"><tr><td>Basic Salary</td><td class="amt">₱ <?= number_format($total_basic_rate, 2) ?></td></tr></table>
    <?php if ($allowance_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Allowance (<?= $payroll['allowance_days'] ?>d × ₱<?= number_format($payroll['allowance_amount'],2) ?>)</td><td class="sub-amt">₱ <?= number_format($allowance_amount, 2) ?></td></tr></table>
    <?php endif; ?>

    <?php if ($payroll['legal_holiday'] > 0 || $payroll['sunday_duty'] > 0 || $payroll['special_holiday'] > 0): ?>
    <div class="grp-lbl">Holidays &amp; Extra</div>
    <?php if ($payroll['legal_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Legal Holiday (<?= $payroll['legal_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($legal_holiday_amt, 2) ?></td></tr></table>
    <?php endif; if ($payroll['sunday_duty'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Sunday Duty (<?= $payroll['sunday_duty'] ?> day)</td><td class="sub-amt">₱ <?= number_format($sunday_duty_amt, 2) ?></td></tr></table>
    <?php endif; if ($payroll['special_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Special Holiday (<?= $payroll['special_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($special_holiday_amt, 2) ?></td></tr></table>
    <?php endif; endif; ?>

    <?php if ($payroll['ot'] > 0): ?>
    <div class="grp-lbl">Overtime</div>
    <table class="item"><tr><td class="sub-lbl"><?= number_format($payroll['ot'], 2) ?> hrs × ₱<?= number_format($payroll['ot_rate'],2) ?>/hr</td><td class="sub-amt">₱ <?= number_format($overtime_amount, 2) ?></td></tr></table>
    <?php endif; ?>

    <?php if ($payroll['absent'] > 0 || $late_amount > 0 || $undertime_amount > 0): ?>
    <div class="grp-lbl red">Adjustments (deducted)</div>
    <?php if ($payroll['absent'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Absences (<?= $payroll['absent'] ?> day)</td><td class="sub-amt red">– ₱ <?= number_format($absent_amount, 2) ?></td></tr></table>
    <?php endif; if ($late_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Late (<?= number_format($payroll['late']) ?> min)</td><td class="sub-amt red">– ₱ <?= number_format($late_amount, 2) ?></td></tr></table>
    <?php endif; if ($undertime_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Undertime (<?= number_format($payroll['under_time']) ?> min)</td><td class="sub-amt red">– ₱ <?= number_format($undertime_amount, 2) ?></td></tr></table>
    <?php endif; endif; ?>
</td>

<!-- RIGHT: DEDUCTIONS -->
<td>
    <div class="col-title">Deductions</div>

    <?php if (!empty($contributions_list)): ?>
    <div class="grp-lbl">Government Contributions</div>
    <?php foreach ($contributions_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr>
        <td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td>
        <td class="sub-amt red">₱ <?= number_format($c['amount'], 2) ?></td>
    </tr></table>
    <?php endforeach; endif; ?>

    <?php if (!empty($deductions_list)): ?>
    <div class="grp-lbl">Other Deductions</div>
    <?php foreach ($deductions_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr>
        <td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td>
        <td class="sub-amt red">₱ <?= number_format($c['amount'], 2) ?></td>
    </tr></table>
    <?php endforeach; endif; ?>

    <?php if (!empty($loans_list)): ?>
    <div class="grp-lbl">Loans</div>
    <?php foreach ($loans_list as $c): ?>
    <table class="item" style="<?= $c['amount']==0?'opacity:.45':'' ?>"><tr>
        <td class="sub-lbl"><?= htmlspecialchars($c['name']) ?></td>
        <td class="sub-amt red">₱ <?= number_format($c['amount'], 2) ?></td>
    </tr></table>
    <?php endforeach; endif; ?>

    <?php if ($sss_fund > 0 || $jei_advances > 0 || $jcc_advances > 0 || $tax > 0 || $other_deduction > 0): ?>
    <div class="grp-lbl">Miscellaneous</div>
    <?php if ($sss_fund > 0): ?><table class="item"><tr><td class="sub-lbl">SSS Provident Fund</td><td class="sub-amt red">₱ <?= number_format($sss_fund,2) ?></td></tr></table><?php endif; ?>
    <?php if ($jei_advances > 0): ?><table class="item"><tr><td class="sub-lbl">JEI Advances</td><td class="sub-amt red">₱ <?= number_format($jei_advances,2) ?></td></tr></table><?php endif; ?>
    <?php if ($jcc_advances > 0): ?><table class="item"><tr><td class="sub-lbl">JCC Advances</td><td class="sub-amt red">₱ <?= number_format($jcc_advances,2) ?></td></tr></table><?php endif; ?>
    <?php if ($tax > 0): ?><table class="item"><tr><td class="sub-lbl">Withholding Tax</td><td class="sub-amt red">₱ <?= number_format($tax,2) ?></td></tr></table><?php endif; ?>
    <?php if ($other_deduction > 0): ?><table class="item"><tr><td class="sub-lbl">Other Deduction</td><td class="sub-amt red">₱ <?= number_format($other_deduction,2) ?></td></tr></table><?php endif; ?>
    <?php endif; ?>
</td>

</tr></tbody>
</table>

<!-- ══ TOTALS ══ -->
<table class="ps-totals" cellspacing="0" cellpadding="0">
<tr>
    <td>
        <div class="tot-lbl">Gross Pay</div>
        <div class="tot-val">₱ <?= number_format($gross_salary, 2) ?></div>
    </td>
    <td>
        <div class="tot-lbl">Total Deductions</div>
        <div class="tot-val red">₱ <?= number_format($total_all_deductions, 2) ?></div>
    </td>
    <td>
        <div class="tot-lbl">Net Pay</div>
        <div class="tot-val">₱ <?= number_format($net_pay, 2) ?></div>
    </td>
</tr>
</table>

<!-- ══ AMOUNT IN WORDS ══ -->
<div class="ps-words">
    <div class="w-lbl">Amount in Words</div>
    <div class="w-val"><?= htmlspecialchars($net_in_words) ?></div>
</div>

<!-- ══ SIGNATURE ══ -->
<table class="ps-sig" cellspacing="0" cellpadding="0">
<tr>
    <td>
        <div class="ack-text">I hereby acknowledge receipt of the net pay amount of <strong>₱ <?= number_format($net_pay, 2) ?></strong> for the pay period <strong><?= date('M d', strtotime($payroll['date_from'])) ?> – <?= date('M d, Y', strtotime($payroll['date_to'])) ?></strong>.</div>
        <div class="sig-line"></div>
        <div class="sig-lbl"><?= htmlspecialchars($payroll['lastname'].', '.$payroll['firstname'].' '.$payroll['middlename']) ?> — Signature over Printed Name</div>
    </td>
    <td>
        <div class="date-lbl">Date Received</div>
        <div class="date-val"><?= date('F d, Y') ?></div>
    </td>
</tr>
</table>

<!-- ══ FOOTER ══ -->
<table class="ps-foot" cellspacing="0" cellpadding="0">
<tr>
    <td>JEJORS CONSTRUCTION CORPORATION — Confidential Payroll Document</td>
    <td>Generated: <?= date('M d, Y g:i A') ?></td>
</tr>
</table>

</div><!-- .ps-wrap -->

<script>
// Go straight to the print dialog, then close the window automatically once
// the dialog is dismissed — whether the user printed OR cancelled.
(function () {
    var closed = false;
    function autoClose() {
        if (closed) return;
        closed = true;
        window.close();          // works for windows opened via window.open()
    }
    window.onafterprint = autoClose;

    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
})();
</script>
</body>
</html>
