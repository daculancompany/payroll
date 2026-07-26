<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include 'db_connect.php';

// Auth: staff sessions may view any payslip; employee-portal sessions only their own
// (ownership enforced after the fetch below). Anonymous access is refused.
$is_staff_session    = !empty($_SESSION['is_login']);
$is_employee_session = !empty($_SESSION['emp_is_login']);
if (!$is_staff_session && !$is_employee_session) {
    http_response_code(403);
    exit('Not authorized.');
}

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

if (!$payroll) {
    http_response_code(404);
    exit('Payslip not found.');
}
// An employee session may only view its own payslip (IDOR guard).
if (!$is_staff_session && (int)$payroll['employee_id'] !== (int)($_SESSION['emp_id'] ?? 0)) {
    http_response_code(403);
    exit('Not authorized.');
}

$contributions_settings = json_decode($payroll['settings'], true) ?: [];
// Per-minute rate = daily rate / (8h × 60m). (Matches payroll_calculations.php /
// admin_class.php; NOT the stored per_minute column, which is per_day/1440.)
$perMinute        = $payroll['per_day'] / (8 * 60);
$overtime_amount  = $payroll['ot'] * $payroll['ot_rate'];
$undertime_amount = $payroll['under_time'] * $perMinute;   // informational only — not deducted in semi-monthly payroll
$late_amount      = $payroll['late'] * $perMinute;
$absent_amount    = $payroll['absent'] * $payroll['per_day'];
$allowance_amount = $payroll['allowance_amount'] * $payroll['allowance_days'];
$monthly_basic    = $payroll['basic_pay'];

$legal_holiday_amt   = $payroll['legal_holiday']   * $payroll['per_day'];
$sunday_duty_amt     = $payroll['sunday_duty']      * $payroll['per_day'];
$special_holiday_amt = (($payroll['per_day'] / 8) * 2.4) * $payroll['special_holiday'];

// Gross per the employee's pay basis — MUST mirror get_payroll_rows_data() /
// the payroll details table, or the payslip's Gross − Deductions ≠ Net.
//   monthly/fixed: (monthly basic + allowance − absent) / 2, + OT + holiday
//                  premiums, − late. Undertime is NOT deducted.
//   daily:         (days present + paid leave) × daily rate, + OT + allowance,
//                  − late. (No absent line: daily staff are paid per day worked.)
$rate_type  = $payroll['rate_type'] ?? 'daily';
$is_monthly = in_array($rate_type, ['monthly', 'fixed'], true);
$semi_monthly_amount = ($monthly_basic + $allowance_amount - $absent_amount) / 2;
$daily_basic_amount  = ($payroll['present'] + (float)($payroll['paid_leave'] ?? 0)) * $payroll['per_day'];
if ($is_monthly) {
    $gross_salary = $semi_monthly_amount
                  + $overtime_amount + $legal_holiday_amt + $sunday_duty_amt + $special_holiday_amt
                  - $late_amount;
} else {
    $gross_salary = $daily_basic_amount + $overtime_amount + $allowance_amount
                  - $late_amount;
}

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
// Manual signed adjustment (+ addition / − recovery), applied to net pay.
$adjustment      = floatval($payroll['adjustment'] ?? 0);
$adj_remarks     = trim((string) ($payroll['adjustment_remarks'] ?? ''));
$tax             = floatval($payroll['tax']);
$jei_advances    = floatval($payroll['jei_advances']);
$jcc_advances    = floatval($payroll['jcc_advances']);
$sss_fund        = floatval($payroll['sss_fund']);
// ── Named one-off items for this employee (payroll_item_extras) ──
// kind 1 deducts, kind 2 adds. Printed as their own lines so the payslip says
// what the money was for instead of burying it in a total. Guarded so a
// database without the migration simply has none.
$ps_extras = [];
$ps_extra_add = $ps_extra_less = 0.0;
if ($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows) {
    $xq = $conn->query("SELECT kind, label, amount FROM payroll_item_extras
                        WHERE payroll_item_id = " . (int)$payroll['id'] . " ORDER BY id ASC");
    if ($xq) while ($x = $xq->fetch_assoc()) {
        $ps_extras[] = $x;
        if ((int)$x['kind'] === 2) $ps_extra_add  += (float)$x['amount'];
        else                       $ps_extra_less += (float)$x['amount'];
    }
}
$gross_salary += $ps_extra_add;

$total_all_deductions = $total_contributions + $total_deductions + $total_loans
                      + $other_deduction + $tax + $jei_advances + $jcc_advances + $sss_fund
                      + $ps_extra_less;
$net_pay = $payroll['net'];

// ── Employee Rate box ──
$hourly_rate = $payroll['per_day'] / 8;

// ── Year-to-Date Taxable Pay (option 1: accumulated gross earnings) ──
// Gross isn't stored, so recompute it per payroll item with the same rate-type
// formula, summed over the employee's payrolls this year up to this period
// (adjustments/bonuses included, matching the client's "gross" definition).
$eid  = (int)$payroll['employee_id'];
$yy   = (int)date('Y', strtotime($payroll['date_from']));
$curTo = $conn->real_escape_string($payroll['date_to']);
$ytd_taxable = 0;
$ytdq = $conn->query("SELECT pi.present, pi.paid_leave, pi.basic_pay, pi.allowance_amount, pi.allowance_days,
        pi.absent, pi.per_day, pi.ot, pi.ot_rate, pi.late, pi.legal_holiday, pi.sunday_duty,
        pi.special_holiday, pi.rate_type, pi.adjustment
    FROM payroll_items pi INNER JOIN payroll p ON p.id = pi.payroll_id
    WHERE pi.employee_id = $eid AND YEAR(p.date_from) = $yy AND p.date_to <= '$curTo'");
if ($ytdq) while ($yr = $ytdq->fetch_assoc()) {
    $yPerDay  = (float)$yr['per_day'];
    $yAllow   = (float)$yr['allowance_amount'] * (float)$yr['allowance_days'];
    $yAbsent  = (float)$yr['absent'] * $yPerDay;
    $yOt      = (float)$yr['ot'] * (float)$yr['ot_rate'];
    $yLate    = (float)$yr['late'] * ($yPerDay / 480);
    $yLegal   = (float)$yr['legal_holiday'] * $yPerDay;
    $ySunday  = (float)$yr['sunday_duty'] * $yPerDay;
    $ySpecial = (($yPerDay / 8) * 2.4) * (float)$yr['special_holiday'];
    if (in_array($yr['rate_type'] ?? 'daily', ['monthly', 'fixed'], true)) {
        $yGross = (($yr['basic_pay'] + $yAllow - $yAbsent) / 2) + $yOt + $yLegal + $ySunday + $ySpecial - $yLate;
    } else {
        $yGross = (($yr['present'] + (float)($yr['paid_leave'] ?? 0)) * $yPerDay) + $yOt + $yAllow - $yLate;
    }
    $ytd_taxable += $yGross + (float)($yr['adjustment'] ?? 0);
}

// ── Leave balances (LEAVES box): credits, used, remaining for the year ──
$leave_rows = [];
$lq = $conn->query("SELECT lt.id AS ltid, lt.name, COALESCE(c.credits, 0) AS credits
    FROM leave_types lt
    LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $eid AND c.year = $yy
    WHERE lt.status = 1 ORDER BY lt.name");
if ($lq) while ($lr = $lq->fetch_assoc()) {
    $used = (float)($conn->query("SELECT COALESCE(SUM(duration),0) AS u FROM leave_requests
        WHERE employee_id = $eid AND leave_type_id = {$lr['ltid']} AND status = 1 AND YEAR(date_from) = $yy")->fetch_assoc()['u'] ?? 0);
    $cr = (float)$lr['credits'];
    if ($cr > 0 || $used > 0) $leave_rows[] = ['name' => $lr['name'], 'used' => $used, 'bal' => max(0, $cr - $used)];
}
$nsd_hours = (float)($payroll['nsd_hours'] ?? 0);

// ── Net pay amount in words (Philippine Peso) ──
// Guarded so this page can be include()'d multiple times (bulk payslip printing).
if (!function_exists('jp_num_to_words')):
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
endif;
$net_in_words = jp_peso_words($net_pay);
?>
<?php if (empty($GLOBALS['PAYSLIP_EMBED'])): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payslip — <?= htmlspecialchars($payroll['lastname'].', '.$payroll['firstname']) ?></title>
<link href="assets/css/icons.min.css" rel="stylesheet">
<?php endif; ?>
<?php if (empty($GLOBALS['PAYSLIP_STYLES_DONE'])): $GLOBALS['PAYSLIP_STYLES_DONE'] = true; ?>
<style>
/* ── Simple black-on-white payslip: no fill colors, just borders + logo ── */
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

@media print {
    body { background: #fff; }
    .ps-wrap { box-shadow: none !important; margin: 0 !important; width: 100% !important; }
}

/* ── Outer wrapper ── */
.ps-wrap {
    width: 780px;
    margin: 16px auto;
    border: 1px solid #333;
    background: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
}

/* ─────────────── HEADER ─────────────── */
.ps-hdr {
    display: table;
    width: 100%;
    border-bottom: 2px solid #333;
}
.ps-hdr-logo-cell {
    display: table-cell;
    width: 68px;
    padding: 14px 10px 14px 16px;
    vertical-align: middle;
}
.ps-hdr-logo-cell img {
    width: 52px; height: 52px;
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
    color: #111;
    letter-spacing: .3px;
    line-height: 1.2;
}
.ps-hdr-addr {
    font-size: 8pt;
    color: #555;
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
    border: 1.5px solid #333;
    color: #111;
    font-size: 11pt;
    font-weight: 900;
    letter-spacing: 3px;
    padding: 5px 10px;
    display: inline-block;
}

/* ─────────────── PERIOD BAR ─────────────── */
.ps-period {
    display: table;
    width: 100%;
    border-bottom: 1px solid #999;
}
.ps-period td {
    font-size: 8pt;
    color: #333;
    padding: 5px 16px;
    white-space: nowrap;
}
.ps-period td strong { color: #111; }
.ps-period .sep { color: #ccc; padding: 5px 4px; }

/* ─────────────── EMPLOYEE INFO ─────────────── */
.ps-emp {
    display: table;
    width: 100%;
    border-bottom: 1px solid #333;
}
.ps-emp td {
    display: table-cell;
    width: 50%;
    padding: 10px 16px;
    vertical-align: top;
    border-right: 1px solid #ccc;
}
.ps-emp td:last-child { border-right: none; }
.emp-lbl { font-size: 7.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #555; margin-bottom: 2px; }
.emp-val { font-size: 11pt; font-weight: 800; color: #111; line-height: 1.2; }
.emp-sub { font-size: 8pt; color: #333; margin-top: 2px; }

/* ─────────────── SECTION LABEL ─────────────── */
.ps-sec-lbl {
    border-top: 1px solid #999;
    border-bottom: 1px solid #999;
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
    border-bottom: 1px solid #333;
}
.ps-body > tbody > tr > td {
    width: 50%;
    vertical-align: top;
    padding: 10px 14px;
    border-right: 1px solid #ccc;
}
.ps-body > tbody > tr > td:last-child { border-right: none; }

.col-title {
    font-size: 8pt; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    color: #111; margin-bottom: 8px;
    padding-bottom: 4px;
    border-bottom: 1px solid #333;
}
.grp-lbl {
    font-size: 7.5pt; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    color: #333; margin: 7px 0 3px;
    padding-top: 4px;
    border-top: 1px dotted #ccc;
}
.grp-lbl:first-child { border-top: none; margin-top: 0; }
.grp-lbl.red { color: #333; border-top-color: #ccc; }

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
.item .sub-lbl { font-size: 8pt; color: #333; padding-left: 16px; }
.item .sub-amt { font-size: 8pt; color: #333; font-weight: 600; text-align: right; width: 90px; white-space: nowrap; }
.item .sub-amt.red { color: #333; }

/* ─────────────── TOTALS ─────────────── */
.ps-totals {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1px solid #333;
}
.ps-totals td {
    width: 33.33%;
    padding: 10px 16px;
    text-align: center;
    border-right: 1px solid #ccc;
    vertical-align: middle;
}
.ps-totals td:last-child { border-right: none; }
.tot-lbl { font-size: 7.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: #555; margin-bottom: 3px; }
.tot-val { font-size: 14pt; font-weight: 900; color: #111; }
.tot-val.red { color: #111; }
.ps-totals td:last-child .tot-lbl { color: #111; }
.ps-totals td:last-child .tot-val { color: #111; font-size: 16pt; }

/* ─────────────── SIGNATURE ─────────────── */
.ps-sig {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1px solid #ccc;
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
.date-lbl { font-size: 7.5pt; color: #333; }
.date-val { font-size: 10pt; font-weight: 700; color: #111; margin-top: 2px; }

/* ─────────────── FOOTER ─────────────── */
.ps-foot {
    border-top: 1px solid #ccc;
    display: table;
    width: 100%;
}
.ps-foot td {
    display: table-cell;
    font-size: 7.5pt;
    color: #333;
    padding: 5px 16px;
    vertical-align: middle;
}
.ps-foot td:last-child { text-align: right; }

/* ─────────────── ATTENDANCE / COMPUTATION SUMMARY ─────────────── */
.ps-summary {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 1px solid #333;
}
.ps-summary td {
    text-align: center;
    padding: 7px 4px;
    border-right: 1px solid #ccc;
    vertical-align: middle;
}
.ps-summary td:last-child { border-right: none; }
.sum-val { font-size: 12pt; font-weight: 900; color: #111; line-height: 1; }
.sum-val.warn { color: #111; }
.sum-val.amber { color: #111; }
.sum-lbl { font-size: 6.8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #555; margin-top: 3px; }

/* ─────────────── AMOUNT IN WORDS ─────────────── */
.ps-words {
    border-bottom: 1px solid #333;
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
    color: #555;
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
.ps-page-break { break-after: page; page-break-after: always; }
.ps-page-break:last-child { break-after: auto; page-break-after: auto; }

/* ─────────────── MOBILE (screen only — print stays A4) ─────────────── */
@media screen and (max-width: 600px) {
    body { background: #e8e8e8; }
    .ps-wrap { width: 100% !important; margin: 0 !important; border-width: 1px; box-shadow: none; }

    /* Period bar → stacked */
    .ps-period, .ps-period tr, .ps-period td { display: block; width: 100%; white-space: normal; }
    .ps-period .sep { display: none; }
    .ps-period td { padding: 3px 14px; }

    /* Employee info → stacked full-width cells */
    .ps-emp, .ps-emp tr, .ps-emp td { display: block; width: 100% !important; }
    .ps-emp td { border-right: none; border-bottom: 1px solid #c8e6e2; }
    .ps-emp td:last-child { border-bottom: none; }

    /* Attendance summary: 7-col table → labelled list rows (Label ··· value) */
    .ps-summary, .ps-summary tbody, .ps-summary tr, .ps-summary td { display: block; width: 100% !important; }
    .ps-summary td {
        display: flex; align-items: center; justify-content: space-between;
        text-align: left; padding: 9px 16px;
        border-right: none; border-bottom: 1px solid #d9ece9;
    }
    .ps-summary td:last-child { border-bottom: none; }
    .ps-summary .sum-lbl { order: -1; margin-top: 0; font-size: 9.5pt; }
    .ps-summary .sum-val { font-size: 12pt; }

    /* Earnings / Deductions: two columns → stacked full-width sections */
    .ps-body, .ps-body > tbody, .ps-body > tbody > tr { display: block; width: 100%; }
    .ps-body > tbody > tr > td { display: block; width: 100% !important; border-right: none; }
    .ps-body > tbody > tr > td:first-child { border-bottom: 2px solid #219688; }

    /* Totals: 3-col → stacked label ··· value rows */
    .ps-totals, .ps-totals tbody, .ps-totals tr, .ps-totals td { display: block; width: 100% !important; }
    .ps-totals td {
        display: flex; align-items: center; justify-content: space-between;
        text-align: left; padding: 11px 16px; border-right: none;
        border-bottom: 1px solid #c8e6e2;
    }
    .ps-totals td:last-child { border-bottom: none; }
    .ps-totals .tot-lbl { margin-bottom: 0; }

    /* Signature → stacked */
    .ps-sig, .ps-sig tr, .ps-sig td { display: block; width: 100% !important; }
    .ps-sig td { border-right: none; text-align: left; }
    .ps-sig td:last-child { text-align: left; border-top: 1px solid #e8e8e8; }

    /* Footer → stacked */
    .ps-foot, .ps-foot tr, .ps-foot td { display: block; width: 100%; }
    .ps-foot td:last-child { text-align: left; }
}
</style>
<?php endif; ?>
<?php if (empty($GLOBALS['PAYSLIP_EMBED'])): ?>
</head>
<body class="<?= empty($_GET['preview']) ? 'has-toolbar' : '' ?>">

<?php if (empty($_GET['preview'])): // hidden when shown inside the portal preview modal ?>
<!-- ══ SCREEN TOOLBAR (not printed) ══ -->
<div class="ps-toolbar">
    <div class="tb-title"><i class="ri-file-text-line"></i> Payslip <small><?= htmlspecialchars($payroll['ref_no'] ?? '') ?></small></div>
    <button class="tb-btn print" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
    <a class="tb-btn close" href="javascript:void(0);" onclick="window.close(); history.back();"><i class="ri-close-line"></i> Close</a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($GLOBALS['PAYSLIP_EMBED'])): ?><div class="ps-page-break"><?php endif; ?>
<div class="ps-wrap">

<!-- ══ HEADER ══ -->
<div class="ps-hdr">
    <div class="ps-hdr-logo-cell"><img src="assets/images/logo.jpeg" alt="Logo"></div>
    <div class="ps-hdr-text-cell">
        <div class="ps-hdr-company">COMC</div>
        <div class="ps-hdr-addr">Tiano Brothers Street, Nacalaban Street, Cagayan De Oro City, 9000 Misamis Oriental</div>
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
    <td>Pay Basis: <strong><?= $is_monthly ? ucfirst($rate_type) : 'Daily' ?></strong></td>
    <?php if (!empty($payroll['ref_no'])): ?>
    <td class="sep">|</td>
    <td>Ref No: <strong><?= htmlspecialchars($payroll['ref_no']) ?></strong></td>
    <?php endif; ?>
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
    <?php if ($is_monthly): ?>
    <td><div class="sum-val">₱<?= number_format($monthly_basic, 0) ?></div><div class="sum-lbl">Monthly Rate</div></td>
    <?php else: ?>
    <td><div class="sum-val">₱<?= number_format($payroll['per_day'], 0) ?></div><div class="sum-lbl">Daily Rate</div></td>
    <?php endif; ?>
</tr>
</table>

<!-- ══ EMPLOYEE RATE · YEAR TO DATE · LEAVES ══ -->
<table class="ps-info" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin-top:8px;table-layout:fixed;">
<tr style="vertical-align:top;">
    <td style="width:33.33%;border:1px solid #d8e6e2;background:#fafdfc;padding:8px 11px;">
        <div style="font-weight:800;font-size:8.5pt;letter-spacing:.5px;color:#176358;border-bottom:2px solid #219688;padding-bottom:3px;margin-bottom:6px;">EMPLOYEE RATE</div>
        <?php if ($is_monthly): ?>
        <table style="width:100%;font-size:9.5pt;"><tr><td>Monthly Rate</td><td style="text-align:right;font-weight:700;">₱ <?= number_format($monthly_basic, 2) ?></td></tr></table>
        <?php else: ?>
        <table style="width:100%;font-size:9.5pt;"><tr><td>Daily Rate</td><td style="text-align:right;font-weight:700;">₱ <?= number_format($payroll['per_day'], 2) ?></td></tr></table>
        <table style="width:100%;font-size:9.5pt;"><tr><td>Hourly Rate</td><td style="text-align:right;font-weight:700;">₱ <?= number_format($hourly_rate, 2) ?></td></tr></table>
        <?php endif; ?>
    </td>
    <td style="width:33.33%;border:1px solid #d8e6e2;background:#fafdfc;padding:8px 11px;">
        <div style="font-weight:800;font-size:8.5pt;letter-spacing:.5px;color:#176358;border-bottom:2px solid #219688;padding-bottom:3px;margin-bottom:6px;">YEAR TO DATE</div>
        <table style="width:100%;font-size:9.5pt;"><tr><td>Taxable Pay</td><td style="text-align:right;font-weight:700;">₱ <?= number_format($ytd_taxable, 2) ?></td></tr></table>
        <table style="width:100%;font-size:9.5pt;"><tr><td>Tax</td><td style="text-align:right;font-weight:700;">₱ <?= number_format($tax, 2) ?></td></tr></table>
    </td>
    <td style="width:33.34%;border:1px solid #d8e6e2;background:#fafdfc;padding:8px 11px;">
        <div style="font-weight:800;font-size:8.5pt;letter-spacing:.5px;color:#176358;border-bottom:2px solid #219688;padding-bottom:3px;margin-bottom:6px;">LEAVES</div>
        <?php if (!empty($leave_rows)): ?>
        <table style="width:100%;font-size:8.5pt;border-collapse:collapse;">
            <tr style="color:#999;"><td>Type</td><td style="text-align:right;">Used</td><td style="text-align:right;">Bal</td></tr>
            <?php foreach ($leave_rows as $lv): $f = fn($n) => rtrim(rtrim(number_format($n, 2), '0'), '.'); ?>
            <tr><td><?= htmlspecialchars($lv['name']) ?></td><td style="text-align:right;"><?= $f($lv['used']) ?></td><td style="text-align:right;font-weight:700;"><?= $f($lv['bal']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <div style="font-size:8.5pt;color:#999;">No leave credits.</div>
        <?php endif; ?>
    </td>
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

    <?php if ($is_monthly): ?>
    <div class="grp-lbl">Basic Pay (Semi-Monthly)</div>
    <table class="item"><tr><td class="sub-lbl">Days Present</td><td class="sub-amt"><?= number_format($payroll['present'], 1) ?> days</td></tr></table>
    <table class="item"><tr><td class="sub-lbl">Daily Rate</td><td class="sub-amt">₱ <?= number_format($payroll['per_day'], 2) ?></td></tr></table>
    <table class="item bold"><tr><td>Monthly Basic</td><td class="amt">₱ <?= number_format($monthly_basic, 2) ?></td></tr></table>
    <?php if ($allowance_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Allowance (<?= $payroll['allowance_days'] ?>d × ₱<?= number_format($payroll['allowance_amount'],2) ?>)</td><td class="sub-amt">₱ <?= number_format($allowance_amount, 2) ?></td></tr></table>
    <?php endif; if ($payroll['absent'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Less: Absences (<?= $payroll['absent'] ?> day)</td><td class="sub-amt red">– ₱ <?= number_format($absent_amount, 2) ?></td></tr></table>
    <?php endif; ?>
    <table class="item bold"><tr><td>Semi-Monthly Amount (÷2)</td><td class="amt">₱ <?= number_format($semi_monthly_amount, 2) ?></td></tr></table>
    <?php else: ?>
    <div class="grp-lbl">Basic Pay (Daily Rate)</div>
    <table class="item"><tr><td class="sub-lbl">Days Present</td><td class="sub-amt"><?= number_format($payroll['present'], 1) ?> days</td></tr></table>
    <?php if ((float)($payroll['paid_leave'] ?? 0) > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Paid Leave</td><td class="sub-amt"><?= number_format($payroll['paid_leave'], 1) ?> days</td></tr></table>
    <?php endif; ?>
    <table class="item"><tr><td class="sub-lbl">Daily Rate</td><td class="sub-amt">₱ <?= number_format($payroll['per_day'], 2) ?></td></tr></table>
    <table class="item bold"><tr><td>Basic Pay (days × rate)</td><td class="amt">₱ <?= number_format($daily_basic_amount, 2) ?></td></tr></table>
    <?php if ($allowance_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Allowance (<?= $payroll['allowance_days'] ?>d × ₱<?= number_format($payroll['allowance_amount'],2) ?>)</td><td class="sub-amt">₱ <?= number_format($allowance_amount, 2) ?></td></tr></table>
    <?php endif; ?>
    <?php endif; ?>

    <?php // Holiday premiums are part of gross only on the monthly/fixed formula.
    if ($is_monthly && ($payroll['legal_holiday'] > 0 || $payroll['sunday_duty'] > 0 || $payroll['special_holiday'] > 0)): ?>
    <div class="grp-lbl">Holidays &amp; Extra</div>
    <?php if ($payroll['legal_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Legal Holiday (<?= $payroll['legal_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($legal_holiday_amt, 2) ?></td></tr></table>
    <?php endif; if ($payroll['sunday_duty'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Rest Day Duty (<?= $payroll['sunday_duty'] ?> day)</td><td class="sub-amt">₱ <?= number_format($sunday_duty_amt, 2) ?></td></tr></table>
    <?php endif; if ($payroll['special_holiday'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Special Holiday (<?= $payroll['special_holiday'] ?> day)</td><td class="sub-amt">₱ <?= number_format($special_holiday_amt, 2) ?></td></tr></table>
    <?php endif; endif; ?>

    <?php if ($payroll['ot'] > 0): ?>
    <div class="grp-lbl">Overtime</div>
    <table class="item"><tr><td class="sub-lbl"><?= number_format($payroll['ot'], 2) ?> hrs × ₱<?= number_format($payroll['ot_rate'],2) ?>/hr</td><td class="sub-amt">₱ <?= number_format($overtime_amount, 2) ?></td></tr></table>
    <?php endif; ?>

    <?php /* One-off allowances added for this employee alone */ ?>
    <?php if ($ps_extra_add > 0): ?>
    <div class="grp-lbl">One-off Allowances</div>
    <?php foreach ($ps_extras as $x): if ((int)$x['kind'] !== 2) continue; ?>
    <table class="item"><tr><td class="sub-lbl"><?= htmlspecialchars($x['label']) ?></td><td class="sub-amt">₱ <?= number_format($x['amount'],2) ?></td></tr></table>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($late_amount > 0 || $payroll['under_time'] > 0): ?>
    <div class="grp-lbl red">Adjustments</div>
    <?php if ($late_amount > 0): ?>
    <table class="item"><tr><td class="sub-lbl">Late (<?= number_format($payroll['late']) ?> min)</td><td class="sub-amt red">– ₱ <?= number_format($late_amount, 2) ?></td></tr></table>
    <?php endif; if ($payroll['under_time'] > 0): ?>
    <table class="item"><tr><td class="sub-lbl" style="color:#999;">Undertime (<?= number_format($payroll['under_time']) ?> min)</td><td class="sub-amt" style="color:#999;">not deducted</td></tr></table>
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

    <?php /* One-off deductions added for this employee alone */ ?>
    <?php if ($ps_extra_less > 0): ?>
    <div class="grp-lbl">One-off Items</div>
    <?php foreach ($ps_extras as $x): if ((int)$x['kind'] !== 1) continue; ?>
    <table class="item"><tr><td class="sub-lbl"><?= htmlspecialchars($x['label']) ?></td><td class="sub-amt red">₱ <?= number_format($x['amount'],2) ?></td></tr></table>
    <?php endforeach; ?>
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
    <?php if ($adjustment != 0): ?>
    <td>
        <div class="tot-lbl">Adjustment<?= $adj_remarks !== '' ? ' — ' . htmlspecialchars($adj_remarks) : '' ?></div>
        <div class="tot-val <?= $adjustment < 0 ? 'red' : '' ?>"><?= $adjustment < 0 ? '−' : '+' ?> ₱ <?= number_format(abs($adjustment), 2) ?></div>
    </td>
    <?php endif; ?>
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
    <td>COMC — Confidential Payroll Document</td>
    <td>Generated: <?= date('M d, Y g:i A') ?></td>
</tr>
</table>

</div><!-- .ps-wrap -->
<?php if (!empty($GLOBALS['PAYSLIP_EMBED'])): ?></div><!-- .ps-page-break --><?php endif; ?>

<?php if (empty($GLOBALS['PAYSLIP_EMBED'])): ?>
<?php if (empty($_GET['preview'])): // no auto-print inside the portal preview modal ?>
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
<?php endif; ?>
</body>
</html>
<?php endif; ?>
