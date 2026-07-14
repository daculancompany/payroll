<?php
session_start();
// Admin / staff sessions must NOT use the employee self-service portal.
if (isset($_SESSION['is_login']) && $_SESSION['is_login']) {
    header('location:index.php?page=home'); exit;
}
if (!isset($_SESSION['emp_is_login']) || !$_SESSION['emp_is_login']) {
    header('location:login.php'); exit;
}
include 'db_connect.php';

$emp_id = (int)$_SESSION['emp_id'];
if (isset($_GET['logout'])) { session_destroy(); header('location:login.php'); exit; }

// Leave eligibility: only Regular / Executive employees may request / hold credits.
$elig_q = $conn->query("SELECT UPPER(COALESCE(cl.clasification,'')) AS c FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id WHERE e.id = " . $emp_id);
$elig_r = $elig_q ? $elig_q->fetch_assoc() : null;
$portal_leave_eligible = $elig_r && in_array($elig_r['c'], LEAVE_ELIGIBLE_CLASSIFICATIONS, true);

// Leave, LWOP, and attendance/OT requests are submitted via AJAX (emp-portal-ajax.php:
// submit_leave_request / submit_attendance_request) so the page never reloads on save.

// The Requests tab now loads its history via infinite scroll from
// attendance-requests-portal-server.php (server-side, paginated) so we no longer
// prefetch rows here — only the pending count is needed for the tab badge.
$att_req_pending_count = 0;
$arpc = $conn->prepare("SELECT COUNT(*) AS c FROM attendance_requests WHERE employee_id = ? AND status = 0");
$arpc->bind_param('i', $emp_id);
$arpc->execute();
$att_req_pending_count = (int)($arpc->get_result()->fetch_assoc()['c'] ?? 0);

// DTRs awaiting this employee's review (status 3) and not yet signed off.
$dtr_review_pending_count = 0;
$drpc = $conn->prepare("SELECT COUNT(DISTINCT DTR.id) AS c
    FROM DTR_details dd
    INNER JOIN DTR ON DTR.id = dd.ddtr_id
    LEFT JOIN dtr_employee_reviews r ON r.ddtr_id = DTR.id AND r.employee_id = ?
    WHERE dd.employee_id = ? AND DTR.status = 3 AND r.id IS NULL");
$drpc->bind_param('ii', $emp_id, $emp_id);
$drpc->execute();
$dtr_review_pending_count = (int)($drpc->get_result()->fetch_assoc()['c'] ?? 0);

// Blocked (holiday) dates + upcoming calendar events for the portal.
$blocked_dates = [];
$bdq = $conn->query("SELECT title, start_date, end_date FROM calendar_events WHERE blocks_leave = 1 AND COALESCE(end_date,start_date) >= CURDATE()");
if ($bdq) while ($b = $bdq->fetch_assoc()) {
    $d = strtotime($b['start_date']); $e = strtotime($b['end_date'] ?: $b['start_date']);
    while ($d <= $e) { $blocked_dates[] = date('Y-m-d', $d); $d = strtotime('+1 day', $d); }
}
$calendar_events_portal = [];
$ceq = $conn->query("SELECT title, start_date, end_date, type, color, note FROM calendar_events WHERE COALESCE(end_date,start_date) >= CURDATE() ORDER BY start_date ASC LIMIT 40");
if ($ceq) while ($c = $ceq->fetch_assoc()) $calendar_events_portal[] = $c;

// ── Leave data for the portal Leave tab ─────────────────────────────────
$leave_types_list = [];
$lwop_types_list  = [];
$ltq = $conn->query("SELECT id, name, days_allowed, is_paid FROM leave_types WHERE status = 1 ORDER BY name ASC");
if ($ltq) while ($r = $ltq->fetch_assoc()) {
    if ($r['is_paid'] == 0) $lwop_types_list[] = $r;
    else $leave_types_list[] = $r;
}

$leave_balance = [];
$lbq = $conn->query("
    SELECT lt.id, lt.name,
        COALESCE(c.credits, lt.days_allowed) AS credits,
        COALESCE(u.used, 0) AS used
    FROM leave_types lt
    LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $emp_id
    LEFT JOIN (
        SELECT leave_type_id, SUM(duration) AS used
        FROM leave_requests WHERE employee_id = $emp_id AND status = 1 GROUP BY leave_type_id
    ) u ON u.leave_type_id = lt.id
    WHERE lt.status = 1
    ORDER BY lt.name ASC
");
if ($lbq) while ($r = $lbq->fetch_assoc()) $leave_balance[] = $r;

$mlq = $conn->prepare("
    SELECT lr.*, lt.name AS leave_type_name, hu.name AS hr_name, au.name AS admin_name
    FROM leave_requests lr
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN users hu ON hu.id = lr.hr_by
    LEFT JOIN users au ON au.id = lr.admin_by
    WHERE lr.employee_id = ?
    ORDER BY lr.date_applied DESC, lr.id DESC
");
$mlq->bind_param('i', $emp_id); $mlq->execute();
$my_leaves = $mlq->get_result()->fetch_all(MYSQLI_ASSOC);
$leave_pending_count = 0;
foreach ($my_leaves as $ml) if ($ml['status'] == 0) $leave_pending_count++;

// ── Employee info ───────────────────────────────────────────────
$s = $conn->prepare("
    SELECT e.*, COALESCE(d.name,'—') AS dept_name, COALESCE(p.name,'—') AS pos_name,
           COALESCE(cl.clasification,'—') AS clasification_name
    FROM employee e
    LEFT JOIN department   d  ON e.department_id   = d.id
    LEFT JOIN position     p  ON e.position_id     = p.id
    LEFT JOIN clasification cl ON e.clasification_id = cl.id
    WHERE e.id = ?
");
$s->bind_param('i', $emp_id); $s->execute();
$emp = $s->get_result()->fetch_assoc();

// ── All payroll items ───────────────────────────────────────────
// Only payroll batches that are Ready for Review (3) or Locked (2) are visible here —
// employees shouldn't see draft/unfinished numbers before HR sends them for review.
$s2 = $conn->prepare("
    SELECT pi.id AS item_id, pi.net, pi.basic_pay, pi.present, pi.per_day,
           pi.allowance_amount, pi.allowance_days, pi.absent, pi.late, pi.ot, pi.ot_rate,
           pi.deduction_amount, pi.other_deduction, pi.tax,
           pi.jei_advances, pi.jcc_advances, pi.sss_fund, pi.under_time,
           pi.legal_holiday, pi.sunday_duty, pi.special_holiday,
           p.ref_no, p.date_from, p.date_to, p.id AS payroll_id, p.status AS payroll_status,
           r.status AS review_status, r.comment AS review_comment, r.reviewed_at AS review_reviewed_at,
           r.admin_reply AS review_admin_reply, r.resolved_at AS review_resolved_at
    FROM payroll_items pi
    INNER JOIN payroll p ON pi.payroll_id = p.id
    LEFT JOIN payroll_employee_reviews r ON r.payroll_id = p.id AND r.employee_id = ?
    WHERE pi.employee_id = ? AND p.status IN (2, 3)
    ORDER BY p.date_from DESC
    LIMIT 24
");
$s2->bind_param('ii', $emp_id, $emp_id); $s2->execute();
$payslips = $s2->get_result()->fetch_all(MYSQLI_ASSOC);
$latest   = $payslips[0] ?? null;

// Payslips awaiting this employee's review (status 3) and not yet signed off.
$payroll_review_pending_count = 0;
foreach ($payslips as $ps) {
    if ((int)$ps['payroll_status'] === 3 && $ps['review_status'] === null) $payroll_review_pending_count++;
}

// ── Career summary from all payslips ───────────────────────────
$total_net = 0; $total_present = 0; $total_ot = 0; $total_absent = 0; $total_late = 0;
$ytd_net = 0; $ytd_gross = 0; $ytd_ded = 0; $ytd_count = 0;
$cur_year = date('Y');
foreach ($payslips as $ps) {
    $total_net     += $ps['net'];
    $total_present += $ps['present'];
    $total_ot      += $ps['ot'];
    $total_absent  += $ps['absent'];
    $total_late    += $ps['late'];

    // Year-to-date (current calendar year)
    if (date('Y', strtotime($ps['date_from'])) == $cur_year) {
        $_pm   = $ps['per_day'] / 480;
        $_at   = $ps['allowance_amount'] * $ps['allowance_days'];
        $_ot   = $ps['ot'] * $ps['ot_rate'];
        $_la   = $ps['late'] * $_pm;
        $_ab   = $ps['absent'] * $ps['per_day'];
        $_lgl  = $ps['legal_holiday'] * $ps['per_day'];
        $_sun  = $ps['sunday_duty']   * $ps['per_day'];
        $_spc  = ($ps['per_day']/8*2.4) * $ps['special_holiday'];
        $_sub  = ($ps['basic_pay'] + $_at - $_ab) / 2;
        $_gr   = $_sub + $_ot + $_lgl + $_sun + $_spc - $_la;
        $_dd   = $ps['deduction_amount'] + $ps['other_deduction'] + $ps['tax'] + $ps['jei_advances'] + $ps['jcc_advances'] + $ps['sss_fund'];
        $ytd_gross += $_gr;
        $ytd_ded   += $_dd;
        $ytd_net   += $ps['net'];
        $ytd_count++;
    }
}

// ── Net-pay trend (last 8 periods, chronological) ──────────────
$trend = array_slice($payslips, 0, 8);          // latest first
$trend = array_reverse($trend);                 // oldest → newest
$trend_max = 0;
foreach ($trend as $t) { if ($t['net'] > $trend_max) $trend_max = $t['net']; }

// ── Chart dataset (last 10 periods, chronological) ─────────────
$chart_src = array_reverse(array_slice($payslips, 0, 10));   // oldest → newest
$chart = ['labels'=>[], 'net'=>[], 'gross'=>[], 'late'=>[], 'ot'=>[], 'absent'=>[], 'present'=>[]];
foreach ($chart_src as $cp) {
    $c_at  = $cp['allowance_amount'] * $cp['allowance_days'];
    $c_ot  = $cp['ot'] * $cp['ot_rate'];
    $c_la  = $cp['late'] * ($cp['per_day'] / 480);
    $c_ab  = $cp['absent'] * $cp['per_day'];
    $c_lgl = $cp['legal_holiday'] * $cp['per_day'];
    $c_sun = $cp['sunday_duty'] * $cp['per_day'];
    $c_spc = ($cp['per_day']/8*2.4) * $cp['special_holiday'];
    $c_sub = ($cp['basic_pay'] + $c_at - $c_ab) / 2;
    $c_gr  = $c_sub + $c_ot + $c_lgl + $c_sun + $c_spc - $c_la;
    $chart['labels'][]  = date('M d', strtotime($cp['date_to']));
    $chart['net'][]     = round($cp['net'], 2);
    $chart['gross'][]   = round($c_gr, 2);
    $chart['late'][]    = (int)$cp['late'];
    $chart['ot'][]      = round($cp['ot'], 1);
    $chart['absent'][]  = (float)$cp['absent'];
    $chart['present'][] = (float)$cp['present'];
}

// ── Per-payslip data for the Comparison tab (computed breakdown) ─
$cmp_data = [];
foreach ($payslips as $cp) {
    $pmn = $cp['per_day'] / 480;
    $att = $cp['allowance_amount'] * $cp['allowance_days'];
    $otv = $cp['ot'] * $cp['ot_rate'];
    $lav = $cp['late'] * $pmn;
    $utv = $cp['under_time'] * $pmn;
    $abv = $cp['absent'] * $cp['per_day'];
    $lgl = $cp['legal_holiday'] * $cp['per_day'];
    $sun = $cp['sunday_duty'] * $cp['per_day'];
    $spc = ($cp['per_day']/8*2.4) * $cp['special_holiday'];
    $sub = ($cp['basic_pay'] + $att - $abv) / 2;
    // Gross mirrors the admin semi-monthly (type 5) formula — no undertime term.
    $grs = $sub + $otv + $lgl + $sun + $spc - $lav;
    $ded = $cp['deduction_amount'] + $cp['other_deduction'] + $cp['tax']
         + $cp['jei_advances'] + $cp['jcc_advances'] + $cp['sss_fund'];
    $cmp_data[] = [
        'id'    => $cp['item_id'],
        'label' => date('M d', strtotime($cp['date_from'])).' – '.date('M d, Y', strtotime($cp['date_to'])),
        'ref'   => $cp['ref_no'],
        'basic' => round($cp['basic_pay'],2), 'allowance'=>round($att,2), 'ot'=>round($otv,2),
        'absent'=> round($abv,2), 'late'=>round($lav,2),
        'gross' => round($grs,2),
        'contrib'=> round($cp['deduction_amount'],2), 'sss_fund'=>round($cp['sss_fund'],2),
        'tax'   => round($cp['tax'],2), 'jei'=>round($cp['jei_advances'],2), 'jcc'=>round($cp['jcc_advances'],2),
        'other' => round($cp['other_deduction'],2),
        'ded'   => round($ded,2),
        'net'   => round($cp['net'],2),
        'present'=> (float)$cp['present'], 'absent_d'=>(float)$cp['absent'], 'late_m'=>(int)$cp['late'], 'ot_h'=>round($cp['ot'],1),
    ];
}

// ── Overview pay insights (derived from the visible payslips) ──
$net_vals  = array_column($payslips, 'net');
$ps_count  = count($net_vals);
$avg_net   = $ps_count ? array_sum($net_vals) / $ps_count : 0;
$best_net  = $ps_count ? max($net_vals) : 0;
$net_delta = $net_delta_pct = null;              // latest vs the period before it
if ($ps_count >= 2 && (float)$payslips[1]['net'] != 0) {
    $net_delta     = $payslips[0]['net'] - $payslips[1]['net'];
    $net_delta_pct = $net_delta / abs($payslips[1]['net']) * 100;
}
$yr_present = 0; $yr_absent = 0;                  // attendance rate for the current year
foreach ($payslips as $ps) {
    if (date('Y', strtotime($ps['date_from'])) == $cur_year) {
        $yr_present += (float)$ps['present'];
        $yr_absent  += (float)$ps['absent'];
    }
}
$att_rate = ($yr_present + $yr_absent) > 0 ? $yr_present / ($yr_present + $yr_absent) * 100 : null;

// ── Contributions / statutory remittances (YTD + lifetime, from payslips) ──
// deduction_amount = mandatory contributions, sss_fund = SSS provident fund, tax = withholding.
$contrib_hist = [];
$ytd_contrib = $ytd_sssfund = $ytd_tax = 0;
$life_contrib = $life_sssfund = $life_tax = 0;
foreach ($payslips as $ps) {
    $c = (float)$ps['deduction_amount']; $sf = (float)$ps['sss_fund']; $tx = (float)$ps['tax'];
    $life_contrib += $c; $life_sssfund += $sf; $life_tax += $tx;
    if (date('Y', strtotime($ps['date_from'])) == $cur_year) {
        $ytd_contrib += $c; $ytd_sssfund += $sf; $ytd_tax += $tx;
    }
    if ($c > 0 || $sf > 0 || $tx > 0) {
        $contrib_hist[] = [
            'period'  => date('M d', strtotime($ps['date_from'])).' – '.date('M d, Y', strtotime($ps['date_to'])),
            'ref'     => $ps['ref_no'],
            'contrib' => $c, 'sssfund' => $sf, 'tax' => $tx, 'total' => $c + $sf + $tx,
        ];
    }
}
$ytd_contrib_total  = $ytd_contrib + $ytd_sssfund + $ytd_tax;
$life_contrib_total = $life_contrib + $life_sssfund + $life_tax;

// ── Attendance (DTR_details) — rows are loaded via the server-side DataTable
// (attendance-portal-server.php); we only need a total count here for the tab badge.
$s3 = $conn->prepare("SELECT COUNT(*) AS c FROM DTR_details WHERE employee_id = ?");
$s3->bind_param('i', $emp_id); $s3->execute();
$attendance_count = (int)($s3->get_result()->fetch_assoc()['c'] ?? 0);

// ── This-month attendance summary (Overview stat strip) ─────────
$mo_from = date('Y-m-01'); $mo_to = date('Y-m-t');
$asq = $conn->prepare("
    SELECT COUNT(DISTINCT DATE(date_time))                    AS days,
           COALESCE(SUM(work_hours), 0)                        AS hours,
           COALESCE(SUM(overtime), 0)                          AS ot,
           COALESCE(SUM(CASE WHEN is_complete = 1 THEN 1 ELSE 0 END), 0) AS complete
    FROM DTR_details
    WHERE employee_id = ? AND date_time BETWEEN ? AND ?");
$asq->bind_param('iss', $emp_id, $mo_from, $mo_to);
$asq->execute();
$att_summary = $asq->get_result()->fetch_assoc() ?: ['days'=>0,'hours'=>0,'ot'=>0,'complete'=>0];
$att_avg_hrs = ((float)$att_summary['days'] > 0) ? (float)$att_summary['hours'] / (float)$att_summary['days'] : 0;

// ── Today's attendance (Overview hero card) — first/last punch + hours ──
$today_str = date('Y-m-d');
$tdq = $conn->prepare("SELECT work_hours, overtime, logs, attendance_type
                       FROM DTR_details WHERE employee_id = ? AND DATE(date_time) = ?
                       ORDER BY date_time DESC LIMIT 1");
$tdq->bind_param('is', $emp_id, $today_str);
$tdq->execute();
$today_att = $tdq->get_result()->fetch_assoc();
$td_in = $td_out = null; $td_hours = 0.0; $td_ot = 0.0; $td_live = false;
$td_type = ''; $td_type_cls = 'att-P';
if ($today_att) {
    $td_logs = $today_att['logs'] ? json_decode($today_att['logs']) : [];
    if (!is_array($td_logs)) $td_logs = [];
    if (!empty($td_logs)) {
        $td_in = date('g:i A', strtotime($td_logs[0]->dateTime ?? ''));
        if (count($td_logs) > 1) $td_out = date('g:i A', strtotime(end($td_logs)->dateTime ?? ''));
    }
    $td_hours = (float)$today_att['work_hours'];
    $td_ot    = (float)$today_att['overtime'];
    if ($td_hours <= 0 && $td_in && !$td_out && !empty($td_logs[0]->dateTime)) {
        // still on duty — show hours elapsed since the first punch
        $td_hours = max(0, (time() - strtotime($td_logs[0]->dateTime)) / 3600);
        $td_live  = true;
    }
    $td_type = $today_att['attendance_type'] ?: 'Present';
    $td_t1   = strtoupper(substr($td_type, 0, 1));
    $td_type_cls = in_array($td_t1, ['P','A','H','S','O']) ? 'att-'.$td_t1 : 'att-P';
}

// ── Active loans ────────────────────────────────────────────────
$s4 = $conn->prepare("
    SELECT l.*, COALESCE(clt.loan_type,'Loan') AS type_name
    FROM loans l LEFT JOIN contribution_loan_types clt ON l.loan_type = clt.clt_id
    WHERE l.employee_id = ? AND l.loan_status = 0 AND l.loan_balance > 0
");
$s4->bind_param('i', $emp_id); $s4->execute();
$loans = $s4->get_result()->fetch_all(MYSQLI_ASSOC);
$total_loan_balance = array_sum(array_column($loans, 'loan_balance'));

// ── Latest payslip computed values ─────────────────────────────
$pm = $late_amt = $ut_amt = $all_tot = $abs_amt = $ot_amt = 0;
$lgl_amt = $sun_amt = $spc_amt = $sub_tot = $gross = $tot_ded = 0;
if ($latest) {
    $pm       = $latest['per_day'] / 480;
    $late_amt = $latest['late'] * $pm;
    $ut_amt   = $latest['under_time'] * $pm;
    $all_tot  = $latest['allowance_amount'] * $latest['allowance_days'];
    $abs_amt  = $latest['absent'] * $latest['per_day'];
    $ot_amt   = $latest['ot'] * $latest['ot_rate'];
    $lgl_amt  = $latest['legal_holiday'] * $latest['per_day'];
    $sun_amt  = $latest['sunday_duty']   * $latest['per_day'];
    $spc_amt  = ($latest['per_day'] / 8 * 2.4) * $latest['special_holiday'];
    $sub_tot  = ($latest['basic_pay'] + $all_tot - $abs_amt) / 2;
    // Gross mirrors the admin semi-monthly (type 5) formula — no undertime term.
    $gross    = $sub_tot + $ot_amt + $lgl_amt + $sun_amt + $spc_amt - $late_amt;
    $tot_ded  = $latest['deduction_amount'] + $latest['other_deduction']
              + $latest['tax'] + $latest['jei_advances'] + $latest['jcc_advances'] + $latest['sss_fund'];
}

// ── Deduction composition of the latest payslip (donut chart) ──
$ded_breakdown = [];
if ($latest) {
    $ded_src = [
        'Contributions'      => $latest['deduction_amount'],
        'SSS Provident Fund' => $latest['sss_fund'],
        'Withholding Tax'    => $latest['tax'],
        'JEI Advances'       => $latest['jei_advances'],
        'JCC Advances'       => $latest['jcc_advances'],
        'Other'              => $latest['other_deduction'],
    ];
    foreach ($ded_src as $k => $v) {
        if ($v > 0) $ded_breakdown[] = ['label' => $k, 'value' => round((float)$v, 2)];
    }
}

function n2($v) { return number_format((float)$v, 2); }
function n0($v) { return number_format((float)$v, 0); }
function nd($v) { return rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); } // trim trailing .0
$initials = strtoupper(substr($emp['firstname'],0,1).substr($emp['lastname'],0,1));
$full_name = strtoupper($emp['lastname'].', '.$emp['firstname']);
$hr = (int)date('H');
$greeting = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>My Portal — <?= htmlspecialchars($emp['firstname']) ?></title>

<!-- ── PWA: installable home-screen app (Android + iOS) ── -->
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#219688">
<link rel="icon" type="image/png" href="assets2/images/pwa/icon-192.png">
<!-- iOS: no manifest install prompt — it reads these tags on "Add to Home Screen" -->
<link rel="apple-touch-icon" href="assets2/images/pwa/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="COMC Portal">
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#2b3330;
    /* clean, cool off-white backdrop with a faint teal wash */
    background-color:#eef2f1;
    background-image:
        radial-gradient(circle at 20% 0%, rgba(33,150,136,.06) 0, transparent 42%),
        radial-gradient(circle at 100% 100%, rgba(33,150,136,.05) 0, transparent 40%);
    background-attachment:fixed;
}
/* Paper sheet helper — warm white with a hairline edge + layered shadow */
.paper{
    background:#ffffff;
    border:1px solid #e4ecea;
    box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);
}

/* Top bar */
.ptop{background:#fff;padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:56px;position:sticky;top:0;z-index:200;border-bottom:1px solid #e4ecea;box-shadow:0 1px 3px rgba(16,55,50,.06);}
.ptop-brand{color:#176358;font-size:14px;font-weight:800;display:flex;align-items:center;gap:9px;letter-spacing:.2px;}
.ptop-logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#219688,#176358);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#fff;box-shadow:0 3px 8px rgba(33,150,136,.28);}
.ptop-logout{background:#f0f7f5;color:#176358;border:1px solid #d5e8e4;border-radius:9px;padding:5px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .18s;}
.ptop-logout:hover{background:#e0f0ec;color:#176358;border-color:#bfe0d9;}

/* Layout — wide on desktop, fluid below */
.portal-wrap{max-width:1280px;margin:0 auto;padding:22px 18px 50px;}
@media(min-width:1500px){.portal-wrap{max-width:1400px;}}

/* Employee header card */
.emp-hdr{background:#ffffff;border:1px solid #e4ecea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 10px 26px -14px rgba(16,55,50,.22);margin-bottom:18px;}
.emp-hdr-top{background:linear-gradient(135deg,#219688,#176358);padding:20px 22px;display:flex;align-items:center;gap:16px;}
.emp-av{width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,.4);}
.emp-nm{font-size:17px;font-weight:900;color:#fff;line-height:1.2;}
.emp-sub{font-size:11px;color:rgba(255,255,255,.78);margin-top:3px;}
.emp-hdr-right{margin-left:auto;display:flex;align-items:center;gap:10px;}
.emp-no-badge{background:rgba(0,0,0,.18);color:#fff;border-radius:8px;padding:5px 13px;font-size:11px;font-family:monospace;font-weight:800;white-space:nowrap;}
/* Notification bell */
.emp-bell{position:relative;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;color:#fff;font-size:19px;cursor:pointer;transition:background .15s;}
.emp-bell:hover{background:rgba(255,255,255,.32);}
.emp-bell-dot{position:absolute;top:7px;right:8px;width:9px;height:9px;background:#ffcf33;border:2px solid #176358;border-radius:50%;}
.emp-notif-panel{position:absolute;top:64px;right:14px;width:340px;max-width:calc(100vw - 28px);background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(0,0,0,.22);z-index:1200;overflow:hidden;display:none;}
.emp-notif-panel.open{display:block;}
.emp-notif-head{display:flex;align-items:center;justify-content:space-between;padding:11px 15px;border-bottom:1px solid #eef3f2;font-size:13px;font-weight:800;color:#176358;}
.emp-notif-allread{background:none;border:0;color:#219688;font-size:11px;font-weight:700;cursor:pointer;}
.emp-notif-list{max-height:380px;overflow-y:auto;}
.emp-notif-empty{padding:26px 14px;text-align:center;color:#aaa;font-size:12px;}
.emp-notif-item{display:flex;gap:10px;padding:11px 15px;border-bottom:1px solid #f4f7f6;cursor:pointer;transition:background .12s;}
.emp-notif-item:hover{background:#f7fbfa;}
.emp-notif-item.unread{background:#f0faf8;}
.emp-notif-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.emp-notif-primary{background:#e6f0fb;color:#2563eb;} .emp-notif-success{background:#eafaf0;color:#0f9d58;}
.emp-notif-warning{background:#fff6e0;color:#c98a00;} .emp-notif-danger{background:#fdecea;color:#c62828;} .emp-notif-info{background:#e6f7fb;color:#0891b2;}
.emp-notif-title{font-size:12.5px;font-weight:800;color:#333;}
.emp-notif-msg{font-size:11.5px;color:#666;margin-top:1px;line-height:1.35;}
.emp-notif-time{font-size:10px;color:#aaa;margin-top:3px;}
/* My DTR tab */
.mydtr-intro{background:#f0faf8;border:1px solid #cdeeda;border-radius:12px;padding:12px 15px;font-size:12.5px;color:#4a6b5f;line-height:1.5;margin-bottom:14px;}
.mydtr-empty{padding:34px 14px;text-align:center;color:#aaa;font-size:13px;}
.mydtr-card{display:flex;justify-content:space-between;gap:14px;background:#fff;border:1px solid #eef3f2;border-radius:14px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.mydtr-period{font-size:14px;font-weight:800;color:#176358;display:flex;align-items:center;gap:6px;}
.mydtr-site{font-size:12px;color:#666;margin-top:3px;}
.mydtr-meta{font-size:11px;color:#999;margin-top:4px;}
.mydtr-card-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;}
.mydtr-badge{font-size:10px;font-weight:800;padding:3px 10px;border-radius:11px;white-space:nowrap;}
.mydtr-badge.review{background:#fff6e0;color:#c98a00;} .mydtr-badge.ok{background:#eafaf0;color:#0f9d58;}
.mydtr-badge.dispute{background:#fdecea;color:#c62828;} .mydtr-badge.done{background:#eef3f2;color:#666;}
.mydtr-btn{border:0;border-radius:9px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.mydtr-btn.primary{background:linear-gradient(135deg,#219688,#176358);color:#fff;}
.mydtr-btn.ghost{background:#f0f5f4;color:#176358;}
/* Review modal table */
.drev-tbl-wrap{border:1px solid #eef3f2;border-radius:10px;overflow:hidden;}
.drev-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.drev-tbl th{background:#f3f8f7;color:#176358;font-weight:800;padding:7px 9px;text-align:left;font-size:11px;}
.drev-tbl td{padding:6px 9px;border-top:1px solid #f1f5f4;color:#444;}
.drev-tbl .tc{text-align:center;}
.drev-tbl tfoot th{background:#e9f5f2;}
.drow-flag{font-size:9px;font-weight:800;padding:1px 7px;border-radius:8px;}
.drow-flag.ok{background:#eafaf0;color:#0f9d58;} .drow-flag.dis{background:#fdecea;color:#c62828;}
.drev-prev{font-size:12px;font-weight:700;padding:9px 12px;border-radius:10px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.drev-prev.ok{background:#eafaf0;color:#0f9d58;} .drev-prev.dis{background:#fdecea;color:#c62828;}
.emp-stats{display:grid;grid-template-columns:repeat(5,1fr);}
.est{padding:12px 14px;border-right:1px solid #eef3f2;text-align:center;}
.est:last-child{border-right:none;}
.est-v{font-size:16px;font-weight:800;color:#219688;line-height:1;}
.est-l{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;}

/* Tabs */
.tab-strip{display:flex;gap:4px;background:#ffffff;border:1px solid #e4ecea;border-radius:12px;padding:5px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 6px 18px -12px rgba(16,55,50,.18);margin-bottom:16px;flex-wrap:wrap;}
.tab-btn{flex:1;padding:9px 6px;border:none;background:transparent;border-radius:8px;font-size:12px;font-weight:700;color:#888;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;transition:all .18s;}
.tab-btn.active{background:linear-gradient(135deg,#219688,#176358);color:#fff;box-shadow:0 2px 8px rgba(33,150,136,.3);}
.tab-btn:not(.active):hover{background:#f0f7f5;color:#176358;}
.tab-btn .badge-count{background:rgba(255,255,255,.25);color:#fff;border-radius:10px;padding:0 6px;font-size:10px;font-weight:800;}
.tab-btn:not(.active) .badge-count{background:#e8f7f5;color:#219688;}
.tab-panel{display:none;} .tab-panel.active{display:block;}
.tab-more{display:none;}   /* only surfaces in the mobile bottom nav */

/* More sheet (mobile only) */
.more-backdrop{display:none;position:fixed;inset:0;background:rgba(20,30,55,.4);z-index:450;}
.more-backdrop.open{display:block;}
/* Centered popup card (not a bottom sheet) */
.more-sheet{position:fixed;left:50%;top:50%;z-index:500;width:calc(100% - 44px);max-width:360px;
    background:#fff;border-radius:22px;box-shadow:0 24px 60px rgba(20,30,55,.28);
    padding:20px 18px;transform:translate(-50%,-50%) scale(.92);opacity:0;pointer-events:none;
    transition:transform .24s cubic-bezier(.4,0,.2,1),opacity .24s;}
.more-sheet.open{transform:translate(-50%,-50%) scale(1);opacity:1;pointer-events:auto;}
.more-grip{display:none;}
.more-head{font-size:15px;font-weight:800;color:#2b3330;margin-bottom:16px;text-align:center;}
.more-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;justify-content:center;}
.more-item{position:relative;display:flex;flex-direction:column;align-items:center;gap:7px;background:#f7f8fa;border:1px solid #eef0f2;border-radius:16px;padding:15px 6px;cursor:pointer;}
.more-item:active{background:#eef0f2;}
.more-ic{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;}
.more-lbl{font-size:11px;font-weight:700;color:#2b3330;}
.more-dot{position:absolute;top:9px;right:16px;background:#dc3545;color:#fff;border-radius:9px;min-width:16px;height:16px;padding:0 4px;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;}

/* Section title */
.sec{font-size:11px;font-weight:800;color:#219688;text-transform:uppercase;letter-spacing:.7px;margin:18px 0 10px;display:flex;align-items:center;gap:8px;}
.sec::after{content:'';flex:1;height:1px;background:#ddecea;}

/* Latest payslip */
.ps-card{background:#ffffff;border:1px solid #e4ecea;border-radius:14px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);overflow:hidden;margin-bottom:14px;}
.ps-period{background:#176358;color:#fff;padding:10px 18px;font-size:12px;font-weight:700;display:flex;justify-content:space-between;}
.ps-body{display:grid;grid-template-columns:1fr 1fr;gap:0;}
.ps-col{padding:14px 18px;}
.ps-col:first-child{border-right:1px solid #f0f5f4;}
.ps-col-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
.ps-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #f8f8f8;}
.ps-row:last-child{border-bottom:none;}
.ps-lbl{font-size:11px;color:#888;}
.ps-val{font-size:12px;font-weight:600;}
.earn{color:#219688;} .ded{color:#dc3545;} .dim{color:#bbb;}
.ps-net{background:linear-gradient(135deg,#219688,#176358);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;}
.ps-net-lbl{color:rgba(255,255,255,.75);font-size:11px;text-transform:uppercase;letter-spacing:.5px;}
.ps-net-val{color:#fff;font-size:24px;font-weight:900;}
.ps-net-period{color:rgba(255,255,255,.6);font-size:10px;margin-top:2px;}

/* Payslip history table */
.ps-hist-table{width:100%;border-collapse:collapse;font-size:12px;}
.ps-hist-table thead th{background:#219688;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;text-align:left;border:none;}
.ps-hist-table thead th.r{text-align:right;}
.ps-hist-table tbody tr{border-bottom:1px solid #f0f5f4;cursor:pointer;transition:background .14s;}
.ps-hist-table tbody tr:hover{background:#f4fbfa;}
.ps-hist-table tbody td{padding:10px 12px;vertical-align:middle;}
.ps-hist-table tbody td.r{text-align:right;}
.ps-hist-table tfoot td{background:#f4fbfa;padding:9px 12px;font-weight:800;color:#219688;border-top:2px solid #ddecea;}
.ps-hist-table tfoot td.r{text-align:right;}
.net-badge{font-size:13px;font-weight:900;color:#176358;}
.present-pill{background:#e8f7f5;color:#176358;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}
.absent-pill{background:#fff0f0;color:#dc3545;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}
.late-pill{background:#fff8e8;color:#fd7e14;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}

/* ── Payslips — dedicated mobile card list (shown < 600px, hidden on desktop) ── */
.ps-mlist{display:none;padding:12px 0 2px;}
.psm-card{position:relative;background:#ffffff;border:1px solid #e4ecea;border-left:3px solid #219688;border-radius:14px;
    padding:13px 14px 0;margin:0 12px 12px;overflow:hidden;cursor:pointer;
    box-shadow:0 1px 2px rgba(16,55,50,.05),0 8px 20px -14px rgba(16,55,50,.28);}
.psm-chk{position:absolute;top:14px;right:12px;width:17px;height:17px;z-index:2;}
.psm-period{font-size:15px;font-weight:800;color:#176358;line-height:1.2;padding-right:30px;}
.psm-period small{display:block;font-size:10px;font-weight:600;color:#aaa;margin-top:1px;}
.psm-ref{font-family:monospace;font-size:11px;font-weight:700;color:#219688;margin:3px 0 2px;}
.psm-stats{display:flex;gap:2px;border-top:1px solid #eef3f2;margin-top:10px;}
.psm-stats>div{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 2px;}
.psm-stats span{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.psm-stats b{font-size:13px;font-weight:800;color:#176358;}
.psm-stats b.mut{color:#ccc;font-weight:600;}
.psm-stats b.abs{color:#dc3545;} .psm-stats b.lt{color:#fd7e14;} .psm-stats b.ot{color:#fd7e14;}
.psm-money{display:flex;border-top:1px solid #eef3f2;}
.psm-money>div{flex:1;display:flex;flex-direction:column;gap:2px;padding:11px 0 12px;}
.psm-money .lbl{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.psm-money .val{font-size:15px;font-weight:800;color:#219688;}
.psm-money .ded{align-items:flex-end;text-align:right;}
.psm-money .ded .val{color:#dc3545;}
.psm-action{border-top:1px solid #eef3f2;padding:11px 0 12px;text-align:center;}
.psm-action .mydtr-badge{display:inline-block;margin-bottom:8px;}
.psm-action .mydtr-btn{width:100%;padding:10px;font-size:13px;text-align:center;}
.psm-net{display:flex;align-items:center;justify-content:space-between;
    background:linear-gradient(135deg,#219688,#176358);margin:0 -14px;padding:12px 14px;}
.psm-net span{font-size:10px;font-weight:800;color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:.4px;}
.psm-net b{font-size:19px;font-weight:900;color:#fff;}
.psm-total{margin:2px 12px 6px;background:linear-gradient(135deg,#219688,#176358);border-radius:12px;padding:10px 14px;color:#fff;}
.psm-total .rowt{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:12px;font-weight:700;}
.psm-total .rowt span{color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:.3px;font-size:10px;}
.psm-total .rowt.net b{font-size:15px;font-weight:900;}

/* Attendance */
.att-table{width:100%;min-width:620px;border-collapse:collapse;font-size:12px;}
.att-table thead th{background:#219688;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;border:none;text-align:left;white-space:nowrap;}
.att-table tbody tr{border-bottom:1px solid #f0f5f4;transition:background .14s;}
.att-table tbody tr:hover{background:#f4fbfa;}
.att-table tbody td{padding:9px 12px;vertical-align:middle;white-space:nowrap;}
.att-table td:last-child{white-space:normal;}
.att-type{border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;}
.att-P{background:#e8f7f5;color:#176358;}
.att-A{background:#fff0f0;color:#dc3545;}
.att-OT{background:#fff8e8;color:#fd7e14;}
.att-H{background:#eef0f8;color:#6f42c1;}
.att-S{background:#fdf0f6;color:#e83e8c;}
.hrs-bar{height:5px;border-radius:3px;background:#e0eeec;overflow:hidden;margin-top:4px;}
.hrs-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#219688,#176358);}

/* ── Attendance mobile card feed (infinite scroll) — hidden on desktop ── */
.att-mlist-wrap{display:none;padding:12px 12px 14px;}
.attm-card{position:relative;background:#ffffff;border:1px solid #e4ecea;border-left:3px solid #219688;
    border-radius:14px;margin-bottom:10px;padding:13px 14px 2px;
    box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 20px -14px rgba(16,55,50,.28);}
.attm-card:last-child{margin-bottom:0;}
.attm-head{padding:0 104px 11px 0;}
.attm-head .attm-d1{font-size:15px;font-weight:800;color:#176358;}
.attm-head .attm-d2{font-size:10.5px;color:#8a9794;font-weight:600;margin-top:1px;}
.attm-card>.att-type{position:absolute;top:12px;right:12px;}
.attm-stats{display:flex;border-top:1px solid #eef3f2;}
.attm-stat{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;
    padding:11px 4px;text-align:center;}
.attm-stat+.attm-stat{border-left:1px solid #f2f6f5;}
.attm-stat .attm-sl{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.attm-stat .attm-sv{font-size:15px;font-weight:800;color:#176358;width:100%;max-width:110px;}
.attm-io{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:11px 0 10px;border-top:1px solid #eef3f2;}
.attm-io .attm-sl{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.attm-notes{display:flex;justify-content:space-between;align-items:center;gap:12px;
    padding:9px 0 12px;border-top:1px dashed #e4ecea;text-align:right;}
.attm-notes::before{content:"Notes";font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.attm-foot{text-align:center;padding:14px 0 6px;font-size:11px;color:#8a9794;font-weight:700;}
.attm-foot .attm-spin{display:inline-block;width:16px;height:16px;border:2px solid #cfe3e0;border-top-color:#219688;
    border-radius:50%;vertical-align:-3px;margin-right:7px;animation:attmSpin .7s linear infinite;}
@keyframes attmSpin{to{transform:rotate(360deg);}}
.attm-empty{text-align:center;padding:26px 14px;font-size:12px;color:#7a8783;font-weight:600;}
.attm-empty i{display:block;font-size:26px;color:#b3c0bc;margin-bottom:6px;}

/* ── Requests (OT / incident) mobile card feed (infinite scroll) — hidden on desktop ── */
.areq-mlist-wrap{display:none;padding:12px 12px 14px;}
.areq-card{position:relative;background:#ffffff;border:1px solid #e4ecea;border-left:3px solid #219688;
    border-radius:14px;margin-bottom:10px;padding:13px 14px;
    box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 20px -14px rgba(16,55,50,.28);}
.areq-card:last-child{margin-bottom:0;}
.areq-card.st-pending{border-left-color:#e6a817;}
.areq-card.st-approved{border-left-color:#219688;}
.areq-card.st-rejected{border-left-color:#c62828;}
.areq-head{padding:0 92px 4px 0;}
.areq-head .areq-d1{font-size:15px;font-weight:800;color:#176358;display:flex;align-items:center;gap:6px;}
.areq-head .areq-d1 i{color:#219688;font-size:15px;}
.areq-type{display:inline-flex;align-items:center;gap:4px;border-radius:8px;padding:3px 9px;font-size:10px;font-weight:700;margin-top:8px;}
.areq-type.t-incident{background:#fff3cd;color:#856404;}
.areq-type.t-overtime{background:#cff4fc;color:#055160;}
.areq-status{position:absolute;top:12px;right:12px;border-radius:10px;padding:3px 11px;font-size:10px;font-weight:800;color:#fff;letter-spacing:.2px;}
.areq-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;
    margin-top:9px;padding-top:9px;border-top:1px solid #eef3f2;font-size:12px;}
.areq-row .areq-l{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;
    display:flex;align-items:center;gap:5px;flex-shrink:0;padding-top:1px;}
.areq-row .areq-l i{font-size:12px;color:#b3c0bc;}
.areq-row .areq-v{text-align:right;color:#414a46;font-weight:600;word-break:break-word;}
.areq-rev{margin-top:9px;padding-top:9px;border-top:1px dashed #e4ecea;font-size:11px;color:#7a8783;
    display:flex;gap:6px;align-items:flex-start;}
.areq-rev i{color:#b3c0bc;font-size:13px;flex-shrink:0;margin-top:1px;}

/* Mobile: every data table becomes a stacked list of cards (label : value rows)
   instead of a horizontally-scrolling table. Cells carry data-label="…" —
   a data-label="" cell (icons / narrow chips) collapses its label row. */
@media(max-width:600px){
    .ps-hist-table, .att-table, .drev-tbl{width:100%;min-width:0;border-collapse:separate;border-spacing:0;}
    .ps-hist-table thead, .att-table thead, .drev-tbl thead{display:none;}
    .ps-hist-table tbody, .att-table tbody, .drev-tbl tbody,
    .ps-hist-table tbody tr, .att-table tbody tr, .drev-tbl tbody tr{display:block;width:100%;}
    .ps-hist-table tbody tr, .att-table tbody tr, .drev-tbl tbody tr{
        background:#ffffff;border:1px solid #e4ecea;border-left:3px solid #219688;border-radius:14px;
        margin-bottom:10px;padding:2px 13px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 20px -14px rgba(16,55,50,.28);}
    .ps-hist-table tbody tr:last-child, .att-table tbody tr:last-child, .drev-tbl tbody tr:last-child{margin-bottom:0;}
    .ps-hist-table tbody td, .att-table tbody td, .drev-tbl tbody td{
        display:flex;align-items:center;justify-content:space-between;gap:12px;
        padding:8px 0;border-top:1px solid #f2f6f5;white-space:normal;text-align:right;width:auto;}
    .ps-hist-table tbody td:first-child, .att-table tbody td:first-child, .drev-tbl tbody td:first-child{border-top:none;}
    .ps-hist-table tbody td::before, .att-table tbody td::before, .drev-tbl tbody td::before{
        content:attr(data-label);font-size:9.5px;font-weight:800;color:#8a9794;
        text-transform:uppercase;letter-spacing:.4px;text-align:left;flex-shrink:0;}
    .ps-hist-table tbody td[data-label=""], .att-table tbody td[data-label=""]{justify-content:center;padding:6px 0;}
    .ps-hist-table tbody td[data-label=""]::before, .att-table tbody td[data-label=""]::before{content:none;}
    /* Payslip totals footer becomes its own summary card */
    .ps-hist-table tfoot, .ps-hist-table tfoot tr{display:block;width:100%;}
    .ps-hist-table tfoot tr{background:linear-gradient(135deg,#219688,#176358);border-radius:12px;padding:2px 13px;margin-top:2px;}
    .ps-hist-table tfoot td{display:flex;justify-content:space-between;align-items:center;gap:12px;
        color:#fff;background:transparent;border-top:1px solid rgba(255,255,255,.18);padding:8px 0;}
    .ps-hist-table tfoot td:first-child{border-top:none;justify-content:flex-start;font-size:12px;font-weight:800;}
    .ps-hist-table tfoot td::before{content:attr(data-label);font-size:9.5px;font-weight:800;
        color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.4px;}
    .ps-hist-table tfoot td[data-label=""]{display:none;}
    /* ── Payslips tab — drop the desktop table entirely; render the
       dedicated .ps-mlist card list instead (separate mobile markup). ── */
    #tab-payslips .table-responsive{display:none;}
    #tab-payslips .ps-mlist{display:block;}

    /* ── Attendance Records — drop the DataTable entirely on mobile; the
       dedicated .att-mlist infinite-scroll card feed replaces it. ── */
    #tab-attendance .table-responsive{display:none;}
    #tab-attendance .att-mlist-wrap{display:block;}

    /* ── My Requests (OT / incident) — drop the DataTable entirely on mobile;
       the dedicated .areq-mlist infinite-scroll card feed replaces it. ── */
    #tab-att-requests .table-responsive{display:none;}
    #tab-att-requests .areq-mlist-wrap{display:block;}

    /* ── My Leave Requests — leave card ── */
    #leave-list-wrap .ps-hist-table tbody tr{display:flex;flex-wrap:wrap;position:relative;padding:12px 13px 6px;}
    #leave-list-wrap .ps-hist-table tbody td{border-top:none;padding:0;}
    #leave-list-wrap .ps-hist-table tbody td::before{content:none;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Type"]{
        order:1;flex:0 0 100%;display:block;text-align:left;padding:0 0 1px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Type"] span{font-size:14px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Date Applied"]{
        order:2;flex:0 0 100%;display:block;text-align:left;font-size:10px;color:#8a9794;padding:0 0 10px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Date Applied"]::before{
        content:"Filed ";font-size:10px;font-weight:700;color:#b3c0bc;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Period"]{
        order:3;flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:8px 0;border-top:1px solid #eef3f2;text-align:right;font-size:12px !important;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Period"]::before{
        content:"Period";font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Days"],
    #leave-list-wrap .ps-hist-table tbody td[data-label="HR"],
    #leave-list-wrap .ps-hist-table tbody td[data-label="Final"]{
        order:4;flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border-top:1px solid #eef3f2;text-align:center;font-size:14px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Days"]::before,
    #leave-list-wrap .ps-hist-table tbody td[data-label="HR"]::before,
    #leave-list-wrap .ps-hist-table tbody td[data-label="Final"]::before{
        content:attr(data-label);display:block;order:-1;font-size:9px;font-weight:800;color:#8a9794;
        text-transform:uppercase;letter-spacing:.3px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Status"]{
        order:5;flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:9px 0;border-top:1px solid #eef3f2;text-align:right;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Status"]::before{
        content:"Status";font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}

    /* ── Contributions (.con-tbl) — remittance card ── */
    .con-tbl{min-width:0;width:100%;border-collapse:separate;border-spacing:0;}
    .con-tbl thead{display:none;}
    .con-tbl tbody, .con-tbl tbody tr{display:block;width:100%;}
    .con-tbl tbody tr{
        display:flex;flex-wrap:wrap;background:#ffffff;border:1px solid #e4ecea;border-left:3px solid #219688;
        border-radius:14px;margin-bottom:10px;padding:12px 13px 6px;
        box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 20px -14px rgba(16,55,50,.28);}
    .con-tbl tbody td{border-top:none;padding:0;}
    .con-tbl tbody td[data-label="Pay Period"]{flex:0 0 100%;display:block;text-align:left;padding-bottom:10px;}
    .con-tbl tbody td[data-label="Contributions"],
    .con-tbl tbody td[data-label="SSS Provident"],
    .con-tbl tbody td[data-label="Tax"]{
        flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border-top:1px solid #eef3f2;text-align:center;font-size:12px;}
    .con-tbl tbody td[data-label="Contributions"]::before,
    .con-tbl tbody td[data-label="SSS Provident"]::before,
    .con-tbl tbody td[data-label="Tax"]::before{
        content:attr(data-label);display:block;font-size:8.5px;font-weight:800;color:#8a9794;
        text-transform:uppercase;letter-spacing:.2px;}
    .con-tbl tbody td[data-label="Total"]{
        flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:9px 0;border-top:1px solid #eef3f2;text-align:right;font-size:14px;}
    .con-tbl tbody td[data-label="Total"]::before{
        content:"Total";font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
    /* Lifetime totals footer becomes its own gradient summary card */
    .con-tbl tfoot, .con-tbl tfoot tr{display:block;width:100%;}
    .con-tbl tfoot tr{background:linear-gradient(135deg,#219688,#176358);border-radius:12px;padding:2px 13px;margin-top:2px;}
    .con-tbl tfoot td{display:flex;justify-content:space-between;align-items:center;gap:12px;
        color:#fff !important;background:transparent;border-top:1px solid rgba(255,255,255,.18);padding:8px 0;font-size:12px;}
    .con-tbl tfoot td:first-child{border-top:none;justify-content:flex-start;font-size:12px;font-weight:800;}
    .con-tbl tfoot td::before{content:attr(data-label);font-size:9.5px;font-weight:800;
        color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.4px;}
    .con-tbl tfoot td:first-child::before{content:none;}
    /* DTR review modal total row (uses <th> instead of <td>) */
    .drev-tbl tfoot, .drev-tbl tfoot tr{display:block;width:100%;}
    .drev-tbl tfoot tr{background:#e9f5f2;border-radius:10px;padding:2px 12px;margin-top:6px;}
    .drev-tbl tfoot th{display:flex;justify-content:space-between;align-items:center;gap:12px;
        text-align:left;padding:7px 0;border-top:1px solid #dcece8;color:#176358;}
    .drev-tbl tfoot th:first-child{border-top:none;justify-content:flex-start;}
    .drev-tbl tfoot th[data-label]:not([data-label=""])::before{content:attr(data-label);font-size:9.5px;
        font-weight:800;color:#7fa89f;text-transform:uppercase;letter-spacing:.4px;}
    .drev-tbl tfoot th[data-label=""]{display:none;}

    /* Review modals: sticky, thumb-friendly action bar on phones */
    #modal-dtr-review .modal-footer, #modal-payroll-review .modal-footer{
        position:sticky;bottom:0;box-shadow:0 -4px 14px rgba(0,0,0,.08);z-index:5;}
    #modal-dtr-review .modal-footer .d-flex, #modal-payroll-review .modal-footer .d-flex{
        flex-direction:column-reverse;gap:8px;}
    #modal-dtr-review .modal-footer .btn, #modal-payroll-review .modal-footer .btn{
        width:100%;padding:13px 14px;font-size:15px;border-radius:12px;}

    /* ── Payslip review breakdown — full mobile relayout (list, not table) ── */
    /* Attendance strip: 6-col table → labelled list rows (Label ··· value) */
    #payroll-review-body .prev-stats .drev-tbl tbody tr{
        border-left:3px solid #219688;padding:4px 14px;}
    #payroll-review-body .prev-stats .drev-tbl tbody td{
        justify-content:space-between;text-align:right;font-weight:800;color:#176358;font-size:13px;padding:9px 0;}
    #payroll-review-body .prev-stats .drev-tbl tbody td::before{
        content:attr(data-label);font-size:10px;font-weight:800;color:#8a9794;
        text-transform:uppercase;letter-spacing:.3px;}
    /* Earnings / Deductions: two columns → stacked full-width sections */
    #payroll-review-body .ps-body{grid-template-columns:1fr;}
    #payroll-review-body .ps-col{padding:14px 16px;}
    #payroll-review-body .ps-col:first-child{
        border-right:none;border-bottom:1px solid #f0f5f4;}
    #payroll-review-body .ps-row{padding:7px 0;}
    #payroll-review-body .ps-lbl{font-size:12.5px;}
    #payroll-review-body .ps-val{font-size:13px;}
    /* Net-pay strip keeps its emphasis, a touch more padding for thumbs */
    #payroll-review-body .ps-net{padding:16px 18px;}
    #payroll-review-body .ps-net-val{font-size:22px;}
}

/* DataTables chrome — pared down to fit the paper theme */
#att-tbl_wrapper .dataTables_processing{background:#ffffff;color:#219688;font-weight:700;font-size:12px;border:none;box-shadow:none;}
#att-tbl_wrapper .dataTables_info{font-size:11px;color:#aaa;padding:10px 14px;}
#att-tbl_wrapper .dataTables_paginate{padding:8px 14px;}
#att-tbl_wrapper .dataTables_paginate .paginate_button{padding:4px 10px;margin-left:3px;border-radius:7px;font-size:11px;border:1px solid transparent !important;background:transparent !important;color:#888 !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button:hover{background:#f0f5f4 !important;color:#176358 !important;border:1px solid transparent !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button.current{background:linear-gradient(135deg,#219688,#176358) !important;color:#fff !important;border:none !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button.disabled{opacity:.4;}
#att-tbl thead th{white-space:nowrap;}

/* Date-range picker trigger */
.att-range-picker{display:flex;align-items:center;gap:6px;width:210px;flex-shrink:0;padding:5px 11px;border:1px solid #cfe3e0;border-radius:8px;background:#f8fdfc;font-size:12px;font-weight:600;color:#176358;cursor:pointer;transition:border-color .15s,box-shadow .15s;}
.att-range-picker:hover{border-color:#219688;}
.att-range-picker i:first-child{color:#219688;flex-shrink:0;}
/* Fixed-width trigger: the label truncates instead of stretching the box */
.att-range-picker #att-range-label{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.att-range-picker i:last-child{flex-shrink:0;margin-left:0 !important;}
/* daterangepicker theme override → brand teal */
.daterangepicker td.active,.daterangepicker td.active:hover{background-color:#219688 !important;}
.daterangepicker td.in-range{background-color:#e6f5f3 !important;color:#176358 !important;}
.daterangepicker .ranges li.active{background-color:#219688 !important;}
.daterangepicker .drp-buttons .btn.applyBtn{background-color:#219688 !important;border-color:#176358 !important;}
.daterangepicker td.start-date,.daterangepicker td.end-date{background-color:#176358 !important;}

/* Loan cards */
.loan-c{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);padding:16px 18px;margin-bottom:10px;}
.loan-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.loan-type-lbl{font-size:12px;font-weight:800;color:#176358;}
.loan-bal-val{font-size:18px;font-weight:900;color:#e83e8c;}
.loan-prog{height:7px;border-radius:4px;background:#f0f0f0;overflow:hidden;margin-bottom:8px;}
.loan-prog-bar{height:100%;border-radius:4px;background:linear-gradient(90deg,#219688,#176358);}
.loan-meta{display:flex;justify-content:space-between;font-size:11px;color:#888;}
.loan-est{font-size:11px;color:#219688;font-weight:700;margin-top:6px;}

/* DTR time chips — matches dtr-details.php */
.dtr-time-chip{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;font-family:'Segoe UI',Arial,sans-serif;white-space:nowrap;}
.dtr-time-chip.in {background:#e6f5f3;color:#219688;border:1px solid #aad5d0;}
.dtr-time-chip.out{background:#fce4ec;color:#c62828;border:1px solid #f9a8b5;}
.dtr-time-chip.na {background:#f5f5f5;color:#888;border:1px solid #ddd;}
.dtr-logs-pill{display:inline-flex;align-items:center;gap:1px;cursor:pointer;line-height:1.3;margin-top:4px;}
.dtr-logs-count{font-size:10px;color:#219688;font-weight:700;text-decoration:underline;text-decoration-style:dotted;white-space:nowrap;}
.dtr-log-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;}
.dtr-log-chip.bio   {background:#e6f5f3;color:#219688;border:1px solid #aad5d0;}
.dtr-log-chip.manual{background:#fff8e1;color:#c98a00;border:1px solid #ffe082;}
.time-io{display:flex;align-items:center;gap:5px;flex-wrap:nowrap;}

/* Today's attendance card (Overview) */
.today-att{background:#ffffff;border:1px solid #e4ecea;border-top:3px solid #219688;border-radius:14px;
    box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);
    padding:13px 16px 14px;margin-bottom:16px;}
.today-att-head{display:flex;align-items:center;gap:8px;margin-bottom:11px;}
.today-att-title{display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:800;color:#176358;}
.today-att-title i{color:#219688;font-size:15px;}
.today-att-date{font-size:10.5px;font-weight:700;color:#8a9794;}
.today-att-head .att-type{margin-left:auto;}
.today-att-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.tda-box{display:flex;flex-direction:column;align-items:center;gap:3px;text-align:center;
    background:#faf8f1;border:1px solid #eef3f2;border-radius:12px;padding:11px 6px 10px;}
.tda-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:2px;}
.tda-ic.in {background:#e6f5f3;color:#219688;}
.tda-ic.out{background:#fce4ec;color:#c62828;}
.tda-ic.hrs{background:#eef0f8;color:#4a5bbf;}
.tda-ic.ot {background:#fff6e0;color:#c98a00;}
.tda-l{font-size:9px;font-weight:800;color:#8a9794;text-transform:uppercase;letter-spacing:.3px;}
.tda-v{font-size:14px;font-weight:900;color:#176358;line-height:1.15;}
.tda-duty{display:inline-block;background:#e6f5f3;color:#219688;border:1px solid #aad5d0;border-radius:10px;
    padding:2px 9px;font-size:10px;font-weight:800;}
.today-att-empty{display:flex;align-items:center;gap:9px;font-size:12px;color:#7a8783;font-weight:600;
    background:#faf8f1;border:1px dashed #e0d8c4;border-radius:12px;padding:12px 14px;line-height:1.45;}
.today-att-empty i{font-size:19px;color:#b7b1a4;flex-shrink:0;}
@media(max-width:600px){
    .today-att-grid{grid-template-columns:repeat(2,1fr);}
    .tda-v{font-size:15px;}
}

/* Year-to-date strip */
.ytd-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.ytd-box{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);padding:14px 16px;border-top:3px solid #219688;}
.ytd-box.g{border-top-color:#219688;}
.ytd-box.d{border-top-color:#dc3545;}
.ytd-box.n{border-top-color:#176358;}
.ytd-box.c{border-top-color:#6f42c1;}
.ytd-val{font-size:18px;font-weight:900;line-height:1;color:#176358;}
.ytd-box.d .ytd-val{color:#dc3545;}
.ytd-box.c .ytd-val{color:#6f42c1;}
.ytd-lbl{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:5px;}
/* Subtle hover lift on stat + chart cards (style polish) */
.ytd-box{transition:transform .16s,box-shadow .16s;}
.ytd-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(16,55,50,.05), 0 14px 28px -14px rgba(16,55,50,.3);}

/* Pay Insights strip (Overview) */
.ins-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.ins-box{display:flex;align-items:center;gap:12px;background:#ffffff;border:1px solid #e4ecea;border-radius:12px;padding:13px 15px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);transition:transform .16s,box-shadow .16s;}
.ins-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(16,55,50,.05), 0 14px 28px -14px rgba(16,55,50,.28);}
.ins-ic{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;background:#e8f7f5;color:#219688;}
.ins-box.up .ins-ic{background:#eafaf0;color:#0f9d58;} .ins-box.down .ins-ic{background:#fdecea;color:#dc3545;}
.ins-box.gold .ins-ic{background:#fff6e0;color:#c98a00;} .ins-box.purple .ins-ic{background:#f2edfb;color:#6f42c1;}
.ins-v{font-size:16px;font-weight:900;line-height:1.05;color:#176358;}
.ins-box.up .ins-v{color:#0f9d58;} .ins-box.down .ins-v{color:#dc3545;}
.ins-l{font-size:10px;color:#8a9794;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;font-weight:700;}
.ins-sub{font-size:10px;color:#b7b1a4;margin-top:1px;}

/* Contributions tab */
.con-hero{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.con-box{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);padding:14px 16px;border-top:3px solid #219688;transition:transform .16s,box-shadow .16s;}
.con-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(16,55,50,.05), 0 14px 28px -14px rgba(16,55,50,.28);}
.con-box.b2{border-top-color:#4a5bbf;} .con-box.b3{border-top-color:#b26a00;} .con-box.b4{border-top-color:#176358;}
.con-cap{font-size:10px;color:#8a9794;text-transform:uppercase;letter-spacing:.4px;font-weight:700;display:flex;align-items:center;gap:5px;}
.con-val{font-size:19px;font-weight:900;line-height:1;color:#176358;margin-top:7px;}
.con-box.b2 .con-val{color:#4a5bbf;} .con-box.b3 .con-val{color:#b26a00;}
.con-note{font-size:10px;color:#b7b1a4;margin-top:5px;}
.con-intro{background:#f0faf8;border:1px solid #cdeeda;border-radius:12px;padding:12px 15px;font-size:12.5px;color:#4a6b5f;line-height:1.5;margin-bottom:14px;}
.con-tbl{width:100%;min-width:560px;border-collapse:collapse;font-size:12px;}
.con-tbl thead th{background:#219688;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;text-align:left;border:none;}
.con-tbl thead th.r{text-align:right;}
.con-tbl tbody tr{border-bottom:1px solid #f0f5f4;transition:background .14s;}
.con-tbl tbody tr:hover{background:#f4fbfa;}
.con-tbl tbody td{padding:9px 12px;vertical-align:middle;}
.con-tbl tbody td.r{text-align:right;}
.con-tbl tfoot td{background:#f4fbfa;padding:9px 12px;font-weight:800;color:#219688;border-top:2px solid #ddecea;}
.con-tbl tfoot td.r{text-align:right;}

/* Card entrance animation on tab reveal (respects reduced-motion) */
@keyframes portalFadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.tab-panel.active>*{animation:portalFadeUp .38s ease;}
@media(prefers-reduced-motion:reduce){.tab-panel.active>*{animation:none;}}
@media(max-width:600px){.ins-strip,.con-hero{grid-template-columns:repeat(2,1fr);}}

/* Net-pay trend mini chart */
.trend-card{background:#ffffff;border:1px solid #e4ecea;border-radius:14px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);padding:16px 18px 12px;margin-bottom:14px;}
.trend-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.trend-title{font-size:12px;font-weight:800;color:#176358;}
.trend-bars{display:flex;align-items:flex-end;gap:8px;height:120px;}
.trend-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:5px;}
.trend-amt{font-size:9px;font-weight:700;color:#219688;white-space:nowrap;}
.trend-bar{width:100%;max-width:30px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#27b09f,#176358);min-height:4px;transition:opacity .15s;}
.trend-col:last-child .trend-bar{background:linear-gradient(180deg,#f7b84b,#e8920a);}
.trend-col:hover .trend-bar{opacity:.82;}
.trend-lbl{font-size:9px;color:#aaa;text-align:center;line-height:1.2;}

/* Chart layout grids — 1 column on mobile, split on desktop */
.chart-grid{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:12px;}
.chart-grid>.trend-card{margin-bottom:0;height:100%;}
@media(min-width:992px){
    .chart-grid.main{grid-template-columns:2fr 1fr;}
    .chart-grid.trio{grid-template-columns:repeat(3,1fr);}
    .chart-grid.duo{grid-template-columns:1fr 1fr;}
}

/* Needs Your Action */
.needs-action{background:#fff8ee;border:1px solid #f3e0bf;border-left:4px solid #e6a817;border-radius:12px;padding:12px 14px;margin-bottom:16px;box-shadow:0 8px 22px -16px rgba(120,90,20,.35);}
.na-head{font-size:13px;font-weight:800;color:#b7791f;display:flex;align-items:center;gap:6px;margin-bottom:8px;}
.na-items{display:flex;flex-direction:column;gap:7px;}
.na-item{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #f0e6d2;border-radius:10px;padding:9px 11px;cursor:pointer;text-align:left;width:100%;transition:transform .12s,box-shadow .12s;}
.na-item:hover{transform:translateX(2px);box-shadow:0 6px 16px -10px rgba(120,90,20,.4);}
.na-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.na-ic.pay{background:#eafaf0;color:#0f9d58;} .na-ic.dtr{background:#fff6e0;color:#c98a00;}
.na-ic.leave{background:#fdecea;color:#c62828;} .na-ic.req{background:#e6f7fb;color:#0891b2;}
.na-txt{flex:1;font-size:12.5px;color:#444;font-weight:600;} .na-txt b{color:#111;}
.na-go{color:#c9a24a;font-size:18px;}

/* Quick actions */
.qa-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.qa-btn{display:flex;align-items:center;gap:10px;background:#ffffff;border:1px solid #e4ecea;border-radius:12px;padding:12px 14px;cursor:pointer;font-size:12px;font-weight:700;color:#176358;text-align:left;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);transition:transform .15s,box-shadow .15s;}
.qa-btn:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(16,55,50,.05), 0 14px 28px -14px rgba(16,55,50,.28);}
.qa-btn i{width:34px;height:34px;border-radius:9px;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.qa-badge{margin-left:auto;background:#e6a817;color:#fff;border-radius:10px;padding:1px 8px;font-size:10px;font-weight:800;}

/* Upcoming events (Overview) */
.evt-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px dashed #ece4d2;}
.evt-row:last-child{border-bottom:none;}
.evt-date{width:44px;flex-shrink:0;text-align:center;background:#f3f8f7;border:1px solid #ddecea;border-radius:9px;padding:4px 0;}
.evt-date .d{font-size:15px;font-weight:900;color:#176358;line-height:1.1;}
.evt-date .m{font-size:9px;font-weight:800;color:#219688;text-transform:uppercase;letter-spacing:.5px;}
.evt-title{font-size:12px;font-weight:700;color:#2b3330;line-height:1.3;}
.evt-note{font-size:10.5px;color:#999;margin-top:1px;}
.evt-pill{margin-left:auto;flex-shrink:0;border-radius:10px;padding:2px 9px;font-size:10px;font-weight:700;}
.evt-pill.hol{background:#fff0f0;color:#c62828;}
.evt-pill.act{background:#e8f0ff;color:#0d6efd;}

/* Leave credit mini bars (Overview) */
.lvc-row{padding:7px 0;border-bottom:1px dashed #ece4d2;}
.lvc-row:last-child{border-bottom:none;}
.lvc-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;}
.lvc-name{font-size:11.5px;font-weight:700;color:#2b3330;}
.lvc-num{font-size:11px;font-weight:800;color:#176358;}
.lvc-num .dim2{color:#aaa;font-weight:600;}
.lvc-bar{height:6px;border-radius:3px;background:#e0eeec;overflow:hidden;}
.lvc-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#219688,#176358);}
.lvc-row.spent .lvc-fill{background:#dc3545;}

/* Sticky tab strip on desktop so navigation stays reachable on the wide page */
@media(min-width:601px){
    .tab-strip{position:sticky;top:62px;z-index:150;}
}

@media(max-width:600px){
    .ytd-strip{grid-template-columns:repeat(2,1fr);}
    .qa-strip{grid-template-columns:repeat(2,1fr);}
    .qa-btn{flex-direction:column;text-align:center;gap:6px;padding:10px 8px;font-size:11px;}
    .qa-badge{margin-left:0;}
}

/* Basic info grid */
.info-section{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);overflow:hidden;margin-bottom:14px;}
.info-sec-title{background:#219688;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:8px 16px;display:flex;align-items:center;gap:7px;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;}
.info-item{padding:10px 16px;border-bottom:1px solid #f0f5f4;border-right:1px solid #f0f5f4;}
.info-item:last-child{border-right:none;}
.info-lbl{font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.info-val{font-size:13px;font-weight:600;color:#222;}
.info-val.mono{font-family:monospace;font-size:12px;}
.info-val.teal{color:#219688;}

/* Empty state — uniform card across every tab */
.empty-state{text-align:center;padding:34px 22px;background:#ffffff;border:1px solid #e4ecea;border-radius:14px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);}
.empty-ic{width:52px;height:52px;border-radius:50%;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:23px;margin:0 auto 12px;}
.empty-state p{font-size:12.5px;color:#7a8783;margin:0;font-weight:600;line-height:1.5;}
.empty-state p strong{color:#2b3330;}
.empty-state.success .empty-ic{background:#e8f7f5;color:#219688;}
.empty-state.success p{color:#176358;font-weight:800;}
.empty-state.warn .empty-ic{background:#fff3cd;color:#c98a00;}
.empty-state.warn p{color:#8a6d1a;}

/* bootstrap-select — make the dropdown + search box visible on the paper theme */
.bootstrap-select .dropdown-toggle{background:#fff !important;border:1px solid #cfe3e0 !important;color:#2b3330 !important;font-size:13px;border-radius:8px;padding:7px 11px;box-shadow:none !important;}
.bootstrap-select .dropdown-toggle:focus{outline:none !important;border-color:#219688 !important;box-shadow:0 0 0 2px rgba(33,150,136,.15) !important;}
.bootstrap-select .dropdown-menu{font-size:13px;border:1px solid #e4ecea;box-shadow:0 8px 22px -10px rgba(16,55,50,.3);}
.bootstrap-select .dropdown-menu li a{color:#2b3330;}
.bootstrap-select .dropdown-menu li.selected a,.bootstrap-select .dropdown-menu li a:hover{background:#e6f5f3 !important;color:#176358 !important;}
.bootstrap-select .bs-searchbox{padding:8px;}
.bootstrap-select .bs-searchbox .form-control{
    background:#fff !important;color:#2b3330 !important;
    border:1px solid #cfe3e0 !important;border-radius:6px;font-size:13px;padding:6px 10px;
    -webkit-text-fill-color:#2b3330;
}
.bootstrap-select .bs-searchbox .form-control::placeholder{color:#9aa3a0 !important;-webkit-text-fill-color:#9aa3a0;}
.bootstrap-select .bs-searchbox .form-control:focus{border-color:#219688 !important;box-shadow:0 0 0 2px rgba(33,150,136,.15) !important;}

/* ── Help tab ── */
.help-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#219688,#176358);border-radius:16px;padding:20px 22px;color:#fff;margin-bottom:6px;box-shadow:0 10px 26px -14px rgba(23,99,88,.6);}
.help-hero-ic{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
.help-hero-t{font-size:17px;font-weight:900;line-height:1.2;}
.help-hero-s{font-size:11.5px;color:rgba(255,255,255,.82);margin-top:4px;}
.help-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:6px;}
.help-card{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;padding:14px 15px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);transition:transform .15s,box-shadow .15s;}
.help-card:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(16,55,50,.05), 0 14px 28px -14px rgba(16,55,50,.28);}
.help-card-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:9px;}
.help-card-t{font-size:13px;font-weight:800;color:#2b3330;margin-bottom:3px;}
.help-card-d{font-size:11.5px;color:#7a8783;line-height:1.45;}
/* glossary rows */
.gloss{display:flex;gap:12px;padding:8px 16px;border-bottom:1px dashed #ece4d2;}
.gloss:last-child{border-bottom:none;}
.gloss-t{font-size:12px;font-weight:800;color:#176358;min-width:150px;flex-shrink:0;}
.gloss-d{font-size:12px;color:#66706c;line-height:1.4;}
/* FAQ accordion */
.faq{margin-bottom:6px;}
.faq-item{background:#ffffff;border:1px solid #e4ecea;border-radius:12px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 2px rgba(16,55,50,.04);}
.faq-q{width:100%;border:none;background:transparent;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;font-size:12.5px;font-weight:700;color:#2b3330;cursor:pointer;text-align:left;}
.faq-q i{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:15px;transition:transform .2s;}
.faq-item.open .faq-q i{transform:rotate(45deg);background:#219688;color:#fff;}
.faq-a{max-height:0;overflow:hidden;transition:max-height .25s ease;}
.faq-item.open .faq-a{max-height:400px;}
.faq-a p{margin:0;padding:0 16px 14px;font-size:12px;color:#66706c;line-height:1.55;}
.faq-a code{background:#eef3f2;color:#176358;padding:1px 6px;border-radius:4px;font-size:11.5px;font-weight:700;}
/* contact card */
.contact-card{display:flex;align-items:center;gap:14px;background:#ffffff;border:1px solid #e4ecea;border-left:4px solid #219688;border-radius:12px;padding:16px 18px;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -14px rgba(16,55,50,.18);flex-wrap:wrap;}
.contact-ic{width:46px;height:46px;border-radius:50%;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:23px;flex-shrink:0;}
.contact-t{font-size:13.5px;font-weight:800;color:#176358;}
.contact-d{font-size:11.5px;color:#7a8783;margin-top:2px;}
.contact-meta{font-size:11px;color:#66706c;font-weight:600;text-align:right;}
.contact-meta i{color:#219688;}

/* Footer */
.portal-foot{text-align:center;font-size:11px;color:#8a9794;margin-top:30px;}

@media(max-width:600px){
    .portal-wrap{padding:14px 10px 40px;}
    .ptop{padding:0 12px;}
    .emp-hdr-top{padding:16px 14px;gap:12px;}
    .emp-av{width:46px;height:46px;font-size:18px;}
    .emp-nm{font-size:15px;}
    .emp-no-badge{padding:4px 8px;font-size:10px;}
    .emp-stats{grid-template-columns:repeat(3,1fr);}
    .est:nth-child(n+4){border-top:1px solid #eef3f2;}
    .ps-body{grid-template-columns:1fr;}
    .ps-col:first-child{border-right:none;border-bottom:1px solid #f0f5f4;}
    .ps-net-val{font-size:20px;}
    .ytd-val{font-size:16px;}

    /* Clean white app-like surface on mobile (drop the warm paper texture) */
    body{background-color:#f2f4f7;background-image:none;}
    .portal-wrap{padding:12px 12px 88px;}   /* room for the fixed bottom nav */

    /* ── Mobile bottom navigation bar (app style) ── */
    .tab-strip{
        position:fixed;left:0;right:0;bottom:0;top:auto;margin:0;z-index:400;
        background:#fff;border:none;border-top:1px solid #eef0f2;
        border-radius:20px 20px 0 0;
        box-shadow:0 -6px 24px rgba(20,30,55,.08);
        padding:8px 4px calc(8px + env(safe-area-inset-bottom,0px));
        gap:2px;flex-wrap:nowrap;overflow:hidden;   /* 5 items fill the bar — no scroll, no right gap */
    }
    .tab-btn{
        flex:1 1 0;min-width:0;width:auto;max-width:none;flex-direction:column;gap:4px;
        padding:8px 2px;font-size:9px;position:relative;
        color:#9aa1ac;background:transparent;border-radius:14px;
    }
    .tab-btn i{font-size:21px;line-height:1;display:block;transition:transform .18s;}
    .tab-btn span.tab-label{display:block;font-size:9px;font-weight:700;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
    /* Active item: soft teal tint + brand color icon/label (no heavy filled block) */
    .tab-btn.active{background:#e8f7f5;color:#176358;box-shadow:none;}
    .tab-btn.active i{transform:translateY(-1px);}
    .tab-btn .badge-count{position:absolute;top:4px;right:9px;font-size:8px;padding:0 4px;}
    /* On the light mobile "active" background, the translucent-white desktop badge is
       unreadable — force a solid, high-contrast pill instead. */
    .tab-btn.active .badge-count{background:#219688;color:#fff;}
    /* Only the 5 primary items live in the bottom nav; the rest go to the More sheet */
    .tab-strip .tab-secondary{display:none;}
    .tab-more{display:flex;}

    .help-grid{grid-template-columns:1fr;}
    .gloss-t{min-width:115px;}
}

/* ── Modal stacking — keep Bootstrap dialogs above ALL portal chrome ──
   In an installed / fullscreen PWA (display:standalone) there is no browser
   chrome to mask stacking quirks, so app overlays (notif panel z-index:1200,
   bottom nav, sticky header, more-sheet) could render on top of a modal and
   the dialog appeared "behind" the page. Pin the modal + backdrop above them. */
.modal-backdrop { z-index: 1990 !important; }
.modal          { z-index: 2000 !important; }

/* ── Modal form controls — ensure visible borders and readable text ── */
.modal .form-control,
.modal .form-select,
.modal select.form-control,
.modal input.form-control,
.modal textarea.form-control {
    border: 1.5px solid #b0c4c0 !important;
    background: #fff !important;
    color: #2d2d2d !important;
    border-radius: 8px;
    font-size: 13px;
    padding: 8px 11px;
}
.modal .form-control:focus,
.modal select.form-control:focus,
.modal input.form-control:focus,
.modal textarea.form-control:focus {
    border-color: #219688 !important;
    box-shadow: 0 0 0 2px rgba(33,150,136,.15) !important;
    outline: none;
}
.modal label {
    color: #333;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 4px;
    display: block;
}

/* ── Parsley validation — same look as the admin panel ── */
.parsley-errors-list { margin-top: 4px; padding: 0; list-style: none; }
.parsley-errors-list li { color: #de4848; font-size: 11px; margin-top: 2px; }
.parsley-error, select.parsley-error, textarea.parsley-error {
    background-color: #fbf5f5 !important;
    border-color: #de4848 !important;
}
.parsley-error:focus, select.parsley-error:focus, textarea.parsley-error:focus {
    border-color: #e1b3b3 !important;
    box-shadow: 0 0 0 2px rgba(222,72,72,.15) !important;
}

/* ── Pull-to-refresh (mobile) ── */
html, body { overscroll-behavior-y: contain; } /* let our own indicator handle the pull, not the browser's native one */
#ptr-indicator {
    position: fixed; top: 56px; left: 50%; margin-left: -19px; z-index: 500;
    width: 38px; height: 38px; border-radius: 50%; background: #fff;
    box-shadow: 0 2px 10px rgba(0,0,0,.18);
    display: flex; align-items: center; justify-content: center;
    color: #219688; font-size: 19px;
    transform: translateY(-100px);
    transition: transform .18s ease, color .15s;
    pointer-events: none;
}
#ptr-indicator.ready { color: #176358; }
#ptr-indicator.spin i { animation: ptrSpin .7s linear infinite; }
@keyframes ptrSpin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="ptop">
    <div class="ptop-brand">
        <div class="ptop-logo">CP</div>
        COMC Employee Portal
    </div>
    <a href="?logout=1" class="ptop-logout"><i class="ri-logout-box-line me-1"></i>Logout</a>
</div>

<div class="portal-wrap">

    <!-- Tabs. On mobile only the .tab-primary items + a "More" button show in the
         bottom nav; .tab-secondary items live in the More sheet. Desktop shows all. -->
    <?php $more_badge = $dtr_review_pending_count + $att_req_pending_count + $payroll_review_pending_count; ?>
    <div class="tab-strip">
        <button class="tab-btn tab-primary active" onclick="switchTab('overview',this)">
            <i class="ri-home-5-line"></i><span class="tab-label">Overview</span>
        </button>
        <button class="tab-btn tab-primary" id="tabbtn-payslips" onclick="switchTab('payslips',this)">
            <i class="ri-file-list-3-line"></i><span class="tab-label">Payslips</span>
            <?php if ($payroll_review_pending_count): ?><span class="badge-count" style="background:#e6a817;"><?= $payroll_review_pending_count ?></span>
            <?php else: ?><span class="badge-count"><?= count($payslips) ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-primary" onclick="switchTab('attendance',this)">
            <i class="ri-calendar-check-line"></i><span class="tab-label">Attendance</span>
            <span class="badge-count"><?= $attendance_count ?></span>
        </button>
        <button class="tab-btn tab-primary" id="tabbtn-leave" onclick="switchTab('leave',this)">
            <i class="ri-calendar-event-line"></i><span class="tab-label">Leave</span>
            <?php if ($leave_pending_count): ?><span class="badge-count"><?= $leave_pending_count ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-secondary" id="tabbtn-mydtr" onclick="switchTab('mydtr',this)">
            <i class="ri-draft-line"></i><span class="tab-label">My DTR</span>
            <?php if ($dtr_review_pending_count): ?><span class="badge-count" style="background:#e6a817;"><?= $dtr_review_pending_count ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-secondary" id="tabbtn-att-requests" onclick="switchTab('att-requests',this)">
            <i class="ri-timer-flash-line"></i><span class="tab-label">Requests</span>
            <?php if ($att_req_pending_count): ?><span class="badge-count"><?= $att_req_pending_count ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('compare',this)">
            <i class="ri-arrow-left-right-line"></i><span class="tab-label">Compare</span>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('loans',this)">
            <i class="ri-bank-line"></i><span class="tab-label">Loans</span>
            <?php if (count($loans)): ?><span class="badge-count"><?= count($loans) ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('contrib',this)">
            <i class="ri-shield-check-line"></i><span class="tab-label">Contributions</span>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('info',this)">
            <i class="ri-profile-line"></i><span class="tab-label">My Info</span>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('help',this)">
            <i class="ri-question-line"></i><span class="tab-label">Help</span>
        </button>
        <!-- Mobile-only "More" launcher -->
        <button type="button" class="tab-btn tab-more" id="tabbtn-more" onclick="openMore()">
            <i class="ri-apps-2-line"></i><span class="tab-label">More</span>
            <?php if ($more_badge): ?><span class="badge-count" id="more-badge"><?= $more_badge ?></span><?php endif; ?>
        </button>
    </div>

    <!-- More sheet (mobile) — houses the secondary sections -->
    <div class="more-backdrop" id="more-backdrop" onclick="closeMore()"></div>
    <div class="more-sheet" id="more-sheet" role="dialog" aria-label="More sections">
        <div class="more-grip"></div>
        <div class="more-head">More</div>
        <div class="more-grid">
            <button type="button" class="more-item" id="moreitem-mydtr" onclick="goMore('mydtr')">
                <span class="more-ic" style="background:#fff6e0;color:#c98a00;"><i class="ri-draft-line"></i></span>
                <span class="more-lbl">My DTR</span>
                <?php if ($dtr_review_pending_count): ?><span class="more-dot"><?= $dtr_review_pending_count ?></span><?php endif; ?>
            </button>
            <button type="button" class="more-item" id="moreitem-att-requests" onclick="goMore('att-requests')">
                <span class="more-ic" style="background:#e6f7fb;color:#0891b2;"><i class="ri-timer-flash-line"></i></span>
                <span class="more-lbl">Requests</span>
                <?php if ($att_req_pending_count): ?><span class="more-dot"><?= $att_req_pending_count ?></span><?php endif; ?>
            </button>
            <button type="button" class="more-item" onclick="goMore('compare')">
                <span class="more-ic" style="background:#eef0f8;color:#4a5bbf;"><i class="ri-arrow-left-right-line"></i></span>
                <span class="more-lbl">Compare</span>
            </button>
            <button type="button" class="more-item" onclick="goMore('loans')">
                <span class="more-ic" style="background:#fdf0f6;color:#e83e8c;"><i class="ri-bank-line"></i></span>
                <span class="more-lbl">Loans</span>
                <?php if (count($loans)): ?><span class="more-dot"><?= count($loans) ?></span><?php endif; ?>
            </button>
            <button type="button" class="more-item" onclick="goMore('contrib')">
                <span class="more-ic" style="background:#eef7f5;color:#176358;"><i class="ri-shield-check-line"></i></span>
                <span class="more-lbl">Contributions</span>
            </button>
            <button type="button" class="more-item" onclick="goMore('info')">
                <span class="more-ic" style="background:#e8f7f5;color:#219688;"><i class="ri-profile-line"></i></span>
                <span class="more-lbl">My Info</span>
            </button>
            <button type="button" class="more-item" onclick="goMore('help')">
                <span class="more-ic" style="background:#eafaf0;color:#0f9d58;"><i class="ri-question-line"></i></span>
                <span class="more-lbl">Help</span>
            </button>
        </div>
    </div>

    <!-- ── Tab: Overview ── -->
    <div class="tab-panel active" id="tab-overview">

        <!-- Needs Your Action -->
        <?php $needs_total = $payroll_review_pending_count + $dtr_review_pending_count + $leave_pending_count + $att_req_pending_count; ?>
        <?php if ($needs_total): ?>
        <div class="needs-action">
            <div class="na-head"><i class="ri-alarm-warning-line"></i> Needs Your Action</div>
            <div class="na-items">
                <?php if ($payroll_review_pending_count): ?>
                <button type="button" class="na-item" data-na-key="pay" onclick="switchTab('payslips',null)">
                    <span class="na-ic pay"><i class="ri-file-list-3-line"></i></span>
                    <span class="na-txt"><b><?= $payroll_review_pending_count ?></b> payslip<?= $payroll_review_pending_count == 1 ? '' : 's' ?> to review</span>
                    <i class="ri-arrow-right-s-line na-go"></i>
                </button>
                <?php endif; ?>
                <?php if ($dtr_review_pending_count): ?>
                <button type="button" class="na-item" data-na-key="dtr" onclick="switchTab('mydtr',null)">
                    <span class="na-ic dtr"><i class="ri-draft-line"></i></span>
                    <span class="na-txt"><b><?= $dtr_review_pending_count ?></b> DTR<?= $dtr_review_pending_count == 1 ? '' : 's' ?> to review</span>
                    <i class="ri-arrow-right-s-line na-go"></i>
                </button>
                <?php endif; ?>
                <?php if ($leave_pending_count): ?>
                <button type="button" class="na-item" data-na-key="leave" onclick="switchTab('leave',null)">
                    <span class="na-ic leave"><i class="ri-calendar-event-line"></i></span>
                    <span class="na-txt"><b><?= $leave_pending_count ?></b> leave request<?= $leave_pending_count == 1 ? '' : 's' ?> pending</span>
                    <i class="ri-arrow-right-s-line na-go"></i>
                </button>
                <?php endif; ?>
                <?php if ($att_req_pending_count): ?>
                <button type="button" class="na-item" data-na-key="req" onclick="switchTab('att-requests',null)">
                    <span class="na-ic req"><i class="ri-timer-flash-line"></i></span>
                    <span class="na-txt"><b><?= $att_req_pending_count ?></b> OT / incident request<?= $att_req_pending_count == 1 ? '' : 's' ?> pending</span>
                    <i class="ri-arrow-right-s-line na-go"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Employee header -->
        <div class="emp-hdr" id="emp-hdr">
            <div class="emp-hdr-top">
                <div class="emp-av"><?= $initials ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:10px;color:rgba(255,255,255,.7);font-weight:600;text-transform:uppercase;letter-spacing:.5px;"><?= $greeting ?>, <?= htmlspecialchars(ucfirst(strtolower($emp['firstname']))) ?>! &middot; <?= date('D, M d, Y') ?></div>
                    <div class="emp-nm"><?= htmlspecialchars($full_name) ?></div>
                    <div class="emp-sub"><?= htmlspecialchars($emp['pos_name']) ?> &bull; <?= htmlspecialchars($emp['dept_name']) ?></div>
                </div>
                <div class="emp-hdr-right">
                    <div class="emp-bell" id="emp-bell" onclick="toggleEmpBell(event)">
                        <i class="ri-notification-3-line"></i>
                        <span class="emp-bell-dot" id="emp-bell-dot" style="display:none;"></span>
                    </div>
                    <div class="emp-no-badge"><?= htmlspecialchars($emp['employee_no']) ?></div>
                </div>
            </div>

            <!-- Notification dropdown -->
            <div class="emp-notif-panel" id="emp-notif-panel">
                <div class="emp-notif-head">
                    <span><i class="ri-notification-3-line me-1"></i>Notifications</span>
                    <button type="button" onclick="empMarkAllRead()" class="emp-notif-allread">Mark all read</button>
                </div>
                <div class="emp-notif-list" id="emp-notif-list">
                    <div class="emp-notif-empty">Loading…</div>
                </div>
            </div>
            <div class="emp-stats">
                <div class="est">
                    <div class="est-v"><?= count($payslips) ?></div>
                    <div class="est-l">Payrolls</div>
                </div>
                <div class="est">
                    <div class="est-v"><?= n0($total_present) ?></div>
                    <div class="est-l">Days Present</div>
                </div>
                <div class="est">
                    <div class="est-v" style="color:#dc3545;"><?= n0($total_absent) ?></div>
                    <div class="est-l">Days Absent</div>
                </div>
                <div class="est">
                    <div class="est-v" style="color:#fd7e14;"><?= n0($total_ot) ?></div>
                    <div class="est-l">OT Hours</div>
                </div>
                <div class="est">
                    <div class="est-v" style="color:#e83e8c;">₱<?= number_format($total_loan_balance, 0) ?></div>
                    <div class="est-l">Loan Balance</div>
                </div>
            </div>
        </div>

        <!-- Today's attendance -->
        <div class="today-att">
            <div class="today-att-head">
                <span class="today-att-title"><i class="ri-time-line"></i>Today's Attendance</span>
                <span class="today-att-date"><?= date('D, M d') ?></span>
                <?php if ($today_att): ?>
                <span class="att-type <?= $td_type_cls ?>"><?= htmlspecialchars($td_type) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($today_att): ?>
            <div class="today-att-grid">
                <div class="tda-box">
                    <div class="tda-ic in"><i class="ri-login-circle-line"></i></div>
                    <div class="tda-l">Time In</div>
                    <div class="tda-v"><?= $td_in ?: '—' ?></div>
                </div>
                <div class="tda-box">
                    <div class="tda-ic out"><i class="ri-logout-circle-line"></i></div>
                    <div class="tda-l">Time Out</div>
                    <div class="tda-v"><?= $td_out ?: ($td_in ? '<span class="tda-duty">On duty</span>' : '—') ?></div>
                </div>
                <div class="tda-box">
                    <div class="tda-ic hrs"><i class="ri-timer-2-line"></i></div>
                    <div class="tda-l">Hours<?= $td_live ? ' so far' : '' ?></div>
                    <div class="tda-v"><?= $td_hours > 0 ? nd(round($td_hours, 1)).'h' : '—' ?></div>
                </div>
                <div class="tda-box">
                    <div class="tda-ic ot"><i class="ri-timer-flash-line"></i></div>
                    <div class="tda-l">OT Hours</div>
                    <div class="tda-v"><?= $td_ot > 0 ? nd(round($td_ot, 1)).'h' : '—' ?></div>
                </div>
            </div>
            <?php else: ?>
            <div class="today-att-empty">
                <i class="ri-fingerprint-line"></i>
                No punches recorded yet today — your time in will show here once you clock in.
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick actions -->
        <div class="qa-strip">
            <button type="button" class="qa-btn" id="qa-payslips" onclick="switchTab('payslips',null)">
                <i class="ri-file-list-3-line"></i><span>View My<br>Payslips</span>
                <?php if ($payroll_review_pending_count): ?><span class="qa-badge"><?= $payroll_review_pending_count ?></span><?php endif; ?>
            </button>
            <?php if ($portal_leave_eligible): ?>
            <button type="button" class="qa-btn" onclick="switchTab('leave',null)">
                <i class="ri-calendar-event-line"></i><span>Request<br>a Leave</span>
            </button>
            <?php else: ?>
            <button type="button" class="qa-btn" onclick="switchTab('attendance',null)">
                <i class="ri-calendar-check-line"></i><span>My<br>Attendance</span>
            </button>
            <?php endif; ?>
            <button type="button" class="qa-btn" onclick="switchTab('att-requests',null)">
                <i class="ri-timer-flash-line"></i><span>File OT /<br>Incident</span>
            </button>
            <button type="button" class="qa-btn" id="qa-mydtr" onclick="switchTab('mydtr',null)">
                <i class="ri-draft-line"></i><span>Review<br>My DTR</span>
                <?php if ($dtr_review_pending_count): ?><span class="qa-badge"><?= $dtr_review_pending_count ?></span><?php endif; ?>
            </button>
        </div>

        <!-- My Work Schedule (current + upcoming) -->
        <?php
        $sched_cur = null; $sched_upcoming = [];
        $scq = $conn->query("SELECT ws.description, ws.start_time, ws.end_time, ws.total_hours, ws.is_graveyard, es.effective_from
            FROM employee_schedules es INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $emp_id AND es.effective_from <= CURDATE()
              AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
            ORDER BY es.effective_from DESC LIMIT 1");
        if ($scq) $sched_cur = $scq->fetch_assoc();
        $suq = $conn->query("SELECT ws.description, ws.start_time, ws.end_time, es.effective_from
            FROM employee_schedules es INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $emp_id AND es.effective_from > CURDATE()
            ORDER BY es.effective_from ASC");
        if ($suq) while ($u = $suq->fetch_assoc()) $sched_upcoming[] = $u;
        ?>
        <div class="sec"><i class="ri-time-line"></i>My Work Schedule</div>
        <div style="background:#fff;border:1px solid #e6ebe9;border-radius:14px;padding:14px 16px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <?php if ($sched_cur): ?>
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#e6fffb;color:#009688;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="ri-time-line" style="font-size:20px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:10px;color:#8a9a95;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Current Shift</div>
                    <div style="font-size:15px;font-weight:700;color:#1b2b27;"><?= htmlspecialchars($sched_cur['description']) ?>
                        <?php if ($sched_cur['is_graveyard']): ?><span style="font-size:10px;background:#2b2b3a;color:#fff;padding:1px 6px;border-radius:6px;vertical-align:middle;"><i class="ri-moon-line"></i> Night</span><?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#5c6b66;">
                        <?= date('h:i A', strtotime($sched_cur['start_time'])) ?> – <?= date('h:i A', strtotime($sched_cur['end_time'])) ?>
                        &nbsp;·&nbsp; <?= rtrim(rtrim($sched_cur['total_hours'], '0'), '.') ?> hrs
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#8a9a95;"><i class="ri-information-line me-1"></i>No work schedule assigned yet.</div>
            <?php endif; ?>

            <?php if (count($sched_upcoming)): ?>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e6ebe9;">
                <div style="font-size:10px;color:#ad6800;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;"><i class="ri-calendar-schedule-line me-1"></i>Upcoming Changes</div>
                <?php foreach ($sched_upcoming as $up): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 0;">
                    <div style="font-size:11px;font-weight:700;color:#ad6800;background:#fff7e6;border:1px solid #ffe7ba;border-radius:8px;padding:3px 9px;white-space:nowrap;">
                        <?= date('M j', strtotime($up['effective_from'])) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:#1b2b27;"><?= htmlspecialchars($up['description']) ?></div>
                        <div style="font-size:11px;color:#8a9a95;"><?= date('h:i A', strtotime($up['start_time'])) ?> – <?= date('h:i A', strtotime($up['end_time'])) ?> &nbsp;·&nbsp; from <?= date('F j, Y', strtotime($up['effective_from'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- This-month attendance summary -->
        <div class="sec"><i class="ri-calendar-check-line"></i><?= date('F Y') ?> Attendance</div>
        <div class="ytd-strip">
            <div class="ytd-box g">
                <div class="ytd-val"><?= nd($att_summary['days']) ?></div>
                <div class="ytd-lbl">Days Present</div>
            </div>
            <div class="ytd-box n">
                <div class="ytd-val"><?= nd(round($att_summary['hours'], 1)) ?></div>
                <div class="ytd-lbl">Hours Worked</div>
            </div>
            <div class="ytd-box c">
                <div class="ytd-val"><?= nd(round($att_summary['ot'], 1)) ?></div>
                <div class="ytd-lbl">OT Hours</div>
            </div>
            <div class="ytd-box g">
                <div class="ytd-val"><?= nd(round($att_avg_hrs, 1)) ?></div>
                <div class="ytd-lbl">Avg Hrs / Day</div>
            </div>
        </div>

        <!-- Year-to-date summary -->
        <div class="sec"><i class="ri-calendar-2-line"></i><?= $cur_year ?> Year-to-Date</div>
        <div class="ytd-strip">
            <div class="ytd-box g">
                <div class="ytd-val">₱<?= n0($ytd_gross) ?></div>
                <div class="ytd-lbl">Gross Earned</div>
            </div>
            <div class="ytd-box d">
                <div class="ytd-val">₱<?= n0($ytd_ded) ?></div>
                <div class="ytd-lbl">Deductions</div>
            </div>
            <div class="ytd-box n">
                <div class="ytd-val">₱<?= n0($ytd_net) ?></div>
                <div class="ytd-lbl">Net Take-Home</div>
            </div>
            <div class="ytd-box c">
                <div class="ytd-val"><?= $ytd_count ?></div>
                <div class="ytd-lbl">Pay Periods</div>
            </div>
        </div>

        <!-- Pay insights -->
        <?php if ($latest): ?>
        <div class="sec"><i class="ri-lightbulb-flash-line"></i>Pay Insights</div>
        <div class="ins-strip">
            <?php
            $d_cls = 'gold'; $d_ic = 'ri-subtract-line';
            $d_val = '₱'.n0($latest['net']); $d_sub = 'your first payslip';
            if ($net_delta !== null) {
                if     ($net_delta > 0) { $d_cls = 'up';   $d_ic = 'ri-arrow-up-line'; }
                elseif ($net_delta < 0) { $d_cls = 'down'; $d_ic = 'ri-arrow-down-line'; }
                else                    { $d_cls = 'gold'; $d_ic = 'ri-subtract-line'; }
                $d_val = ($net_delta >= 0 ? '+₱' : '−₱').n0(abs($net_delta));
                $d_sub = ($net_delta_pct >= 0 ? '+' : '−').number_format(abs($net_delta_pct), 1).'% vs last period';
            }
            ?>
            <div class="ins-box <?= $d_cls ?>">
                <div class="ins-ic"><i class="<?= $d_ic ?>"></i></div>
                <div><div class="ins-v"><?= $d_val ?></div><div class="ins-l">Net Change</div><div class="ins-sub"><?= $d_sub ?></div></div>
            </div>
            <div class="ins-box">
                <div class="ins-ic"><i class="ri-scales-3-line"></i></div>
                <div><div class="ins-v">₱<?= n0($avg_net) ?></div><div class="ins-l">Average Net</div><div class="ins-sub">across <?= $ps_count ?> payslip<?= $ps_count == 1 ? '' : 's' ?></div></div>
            </div>
            <div class="ins-box gold">
                <div class="ins-ic"><i class="ri-trophy-line"></i></div>
                <div><div class="ins-v">₱<?= n0($best_net) ?></div><div class="ins-l">Highest Net</div><div class="ins-sub">best pay period</div></div>
            </div>
            <div class="ins-box purple">
                <div class="ins-ic"><i class="ri-checkbox-circle-line"></i></div>
                <div><div class="ins-v"><?= $att_rate !== null ? number_format($att_rate, 0).'%' : '—' ?></div><div class="ins-l">Attendance <?= $cur_year ?></div><div class="ins-sub"><?= $att_rate !== null ? nd($yr_present).'d present · '.nd($yr_absent).'d absent' : 'no records yet' ?></div></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts -->
        <?php $has_trend = count($chart['labels']) > 1; $has_ded_chart = count($ded_breakdown) > 0; ?>
        <?php if ($has_trend || $has_ded_chart): ?>
        <div class="sec"><i class="ri-bar-chart-2-line"></i>My Payroll Charts</div>
        <div class="chart-grid <?= ($has_trend && $has_ded_chart) ? 'main' : '' ?>">
            <?php if ($has_trend): ?>
            <div class="trend-card">
                <div class="trend-head">
                    <span class="trend-title"><i class="ri-line-chart-line me-1"></i>Net Pay vs Gross Pay</span>
                    <span style="font-size:10px;color:#aaa;">Last <?= count($chart['labels']) ?> periods</span>
                </div>
                <div id="chart-pay"></div>
            </div>
            <?php endif; ?>
            <?php if ($has_ded_chart): ?>
            <div class="trend-card">
                <div class="trend-head">
                    <span class="trend-title"><i class="ri-pie-chart-2-line me-1"></i>Where Deductions Go</span>
                    <span style="font-size:10px;color:#aaa;">Latest payslip</span>
                </div>
                <div id="chart-deduct"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($has_trend): ?>
        <div class="chart-grid trio" style="margin-bottom:14px;">
            <div class="trend-card">
                <div class="trend-head"><span class="trend-title"><i class="ri-alarm-warning-line me-1"></i>Late (minutes)</span></div>
                <div id="chart-late"></div>
            </div>
            <div class="trend-card">
                <div class="trend-head"><span class="trend-title"><i class="ri-timer-flash-line me-1"></i>Overtime (hours)</span></div>
                <div id="chart-ot"></div>
            </div>
            <div class="trend-card">
                <div class="trend-head"><span class="trend-title"><i class="ri-calendar-check-line me-1"></i>Days Present vs Absent</span></div>
                <div id="chart-attend"></div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Upcoming events + leave credits at a glance -->
        <?php $show_lvc = $portal_leave_eligible && count($leave_balance) > 0; ?>
        <?php if (count($calendar_events_portal) || $show_lvc): ?>
        <div class="sec"><i class="ri-compass-3-line"></i>At a Glance</div>
        <div class="chart-grid <?= (count($calendar_events_portal) && $show_lvc) ? 'duo' : '' ?>" style="margin-bottom:14px;">
            <?php if (count($calendar_events_portal)): ?>
            <div class="trend-card">
                <div class="trend-head">
                    <span class="trend-title"><i class="ri-calendar-2-line me-1"></i>Upcoming Holidays &amp; Activities</span>
                    <a href="javascript:void(0)" onclick="switchTab('leave',null)" style="font-size:10px;color:#219688;font-weight:700;text-decoration:none;">See all →</a>
                </div>
                <?php foreach (array_slice($calendar_events_portal, 0, 5) as $ev):
                    $isHol = $ev['type'] == 1; $st = strtotime($ev['start_date']); ?>
                <div class="evt-row">
                    <div class="evt-date"><div class="d"><?= date('d', $st) ?></div><div class="m"><?= date('M', $st) ?></div></div>
                    <div style="min-width:0;">
                        <div class="evt-title"><?= htmlspecialchars($ev['title']) ?></div>
                        <?php if ($ev['end_date'] && $ev['end_date'] != $ev['start_date']): ?>
                        <div class="evt-note">until <?= date('M d, Y', strtotime($ev['end_date'])) ?></div>
                        <?php elseif ($ev['note']): ?>
                        <div class="evt-note"><?= htmlspecialchars(mb_strimwidth($ev['note'], 0, 50, '…')) ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="evt-pill <?= $isHol ? 'hol' : 'act' ?>"><?= $isHol ? 'Holiday' : 'Activity' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($show_lvc): ?>
            <div class="trend-card">
                <div class="trend-head">
                    <span class="trend-title"><i class="ri-coins-line me-1"></i>My Leave Credits</span>
                    <a href="javascript:void(0)" onclick="switchTab('leave',null)" style="font-size:10px;color:#219688;font-weight:700;text-decoration:none;">Request →</a>
                </div>
                <?php foreach ($leave_balance as $b):
                    $avail = (float)$b['credits']; $used = (float)$b['used']; $rem = max(0, $avail - $used);
                    $pct = $avail > 0 ? round($rem / $avail * 100) : 0;
                    $fmtn = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
                ?>
                <div class="lvc-row <?= $rem <= 0 ? 'spent' : '' ?>">
                    <div class="lvc-top">
                        <span class="lvc-name"><?= htmlspecialchars($b['name']) ?></span>
                        <span class="lvc-num"><?= $fmtn($rem) ?> <span class="dim2">/ <?= $fmtn($avail) ?> days left</span></span>
                    </div>
                    <div class="lvc-bar"><div class="lvc-fill" style="width:<?= $pct ?>%;"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($latest): ?>
        <div class="sec"><i class="ri-file-text-line"></i>Latest Payslip</div>
        <div class="ps-card">
            <div class="ps-period">
                <span><i class="ri-calendar-line me-1"></i><?= date('M d', strtotime($latest['date_from'])) ?> – <?= date('M d, Y', strtotime($latest['date_to'])) ?></span>
                <span style="opacity:.7;"><?= htmlspecialchars($latest['ref_no']) ?></span>
            </div>
            <div class="ps-body">
                <!-- Earnings -->
                <div class="ps-col">
                    <div class="ps-col-title earn">Earnings</div>
                    <div class="ps-row"><span class="ps-lbl">Basic Pay</span><span class="ps-val earn">₱<?= n2($latest['basic_pay']) ?></span></div>
                    <div class="ps-row"><span class="ps-lbl">Days Present</span><span class="ps-val"><?= $latest['present'] ?> days</span></div>
                    <?php if ($all_tot > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Allowance</span><span class="ps-val earn">₱<?= n2($all_tot) ?></span></div>
                    <?php endif; ?>
                    <?php if ($abs_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Absent (<?= $latest['absent'] ?> day<?= $latest['absent']>1?'s':'' ?>)</span><span class="ps-val ded">−₱<?= n2($abs_amt) ?></span></div>
                    <?php endif; ?>
                    <div class="ps-row"><span class="ps-lbl" style="font-weight:700;">Sub-Total</span><span class="ps-val earn" style="font-weight:800;">₱<?= n2($sub_tot) ?></span></div>
                    <?php if ($ot_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Overtime (<?= $latest['ot'] ?> hrs)</span><span class="ps-val earn">₱<?= n2($ot_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($lgl_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Legal Holiday (<?= $latest['legal_holiday'] ?>)</span><span class="ps-val earn">₱<?= n2($lgl_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($sun_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Sunday Duty (<?= $latest['sunday_duty'] ?>)</span><span class="ps-val earn">₱<?= n2($sun_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($spc_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Special Holiday (<?= $latest['special_holiday'] ?>)</span><span class="ps-val earn">₱<?= n2($spc_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($late_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Late (<?= number_format($latest['late']) ?> min)</span><span class="ps-val ded">−₱<?= n2($late_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['under_time'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl dim">Undertime (<?= number_format($latest['under_time']) ?> min)</span><span class="ps-val dim">not deducted</span></div>
                    <?php endif; ?>
                    <div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#219688;">Gross Pay</span><span class="ps-val earn" style="font-size:15px;font-weight:900;">₱<?= n2($gross) ?></span></div>
                </div>
                <!-- Deductions -->
                <div class="ps-col">
                    <div class="ps-col-title ded">Deductions</div>
                    <?php if ($latest['deduction_amount'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Contributions</span><span class="ps-val ded">₱<?= n2($latest['deduction_amount']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['sss_fund'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">SSS Provident Fund</span><span class="ps-val ded">₱<?= n2($latest['sss_fund']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['tax'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Withholding Tax</span><span class="ps-val ded">₱<?= n2($latest['tax']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['jei_advances'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">JEI Advances</span><span class="ps-val ded">₱<?= n2($latest['jei_advances']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['jcc_advances'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">JCC Advances</span><span class="ps-val ded">₱<?= n2($latest['jcc_advances']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['other_deduction'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Other Deductions</span><span class="ps-val ded">₱<?= n2($latest['other_deduction']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($tot_ded == 0): ?>
                    <div class="ps-row"><span class="ps-lbl dim">No deductions</span></div>
                    <?php endif; ?>
                    <div style="flex:1;"></div>
                    <div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#dc3545;">Total Deductions</span><span class="ps-val ded" style="font-size:15px;font-weight:900;">₱<?= n2($tot_ded) ?></span></div>
                </div>
            </div>
            <div class="ps-net">
                <div>
                    <div class="ps-net-lbl">Net Pay</div>
                    <div class="ps-net-period"><?= date('M d', strtotime($latest['date_from'])) ?> – <?= date('M d, Y', strtotime($latest['date_to'])) ?></div>
                </div>
                <div class="ps-net-val">₱<?= n2($latest['net']) ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-file-text-line"></i></div><p>No payslip records yet.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Payslips ── -->
    <div class="tab-panel" id="tab-payslips">
        <div class="sec"><i class="ri-file-list-3-line"></i>All Payslips</div>
        <?php if (count($payslips)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f0f5f4;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;color:#888;"><?= count($payslips) ?> payroll period<?= count($payslips)>1?'s':'' ?></span>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" id="ps-print-selected" class="mydtr-btn primary" onclick="printSelectedMyPayslips()" style="display:none;">
                        <i class="ri-printer-line me-1"></i>Print Selected (<span id="ps-sel-count">0</span>)
                    </button>
                    <input type="text" id="ps-search" class="form-control form-control-sm" placeholder="Search period…" style="max-width:160px;">
                </div>
            </div>
            <div class="table-responsive">
            <table class="ps-hist-table" id="ps-hist">
                <thead>
                    <tr>
                        <th style="width:30px;text-align:center;"><input type="checkbox" id="ps-check-all" title="Select all"></th>
                        <th>Pay Period</th>
                        <th>Ref No.</th>
                        <th class="r">Present</th>
                        <th class="r">Absent</th>
                        <th class="r">Late</th>
                        <th class="r">OT</th>
                        <th class="r">Gross</th>
                        <th class="r">Deductions</th>
                        <th class="r">Net Pay</th>
                        <th class="r"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $t_net=0; $t_gross=0; $t_ded=0;
                $payrollReviewJs = [];
                $psMobile = [];
                foreach ($payslips as $ps):
                    $pm2    = $ps['per_day'] / 480;
                    $at2    = $ps['allowance_amount'] * $ps['allowance_days'];
                    $ot2    = $ps['ot'] * $ps['ot_rate'];
                    $la2    = $ps['late'] * $pm2;
                    $ab2    = $ps['absent'] * $ps['per_day'];
                    $lgl2   = $ps['legal_holiday'] * $ps['per_day'];
                    $sun2   = $ps['sunday_duty']   * $ps['per_day'];
                    $spc2   = ($ps['per_day']/8*2.4) * $ps['special_holiday'];
                    $ut2    = $ps['under_time'] * $pm2;   // shown as info only; not part of gross
                    $sub2   = ($ps['basic_pay'] + $at2 - $ab2) / 2;
                    // Gross mirrors the admin semi-monthly (type 5) formula — no undertime term.
                    $gr2    = $sub2 + $ot2 + $lgl2 + $sun2 + $spc2 - $la2;
                    $ded2   = $ps['deduction_amount'] + $ps['other_deduction'] + $ps['tax'] + $ps['jei_advances'] + $ps['jcc_advances'] + $ps['sss_fund'];
                    $t_net+=$ps['net']; $t_gross+=$gr2; $t_ded+=$ded2;
                    $psStatus = (int) $ps['payroll_status'];
                    $psReview = $ps['review_status'] === null ? null : (int) $ps['review_status'];
                    $payrollReviewJs[(int)$ps['payroll_id']] = [
                        'period'  => date('M d', strtotime($ps['date_from'])) . ' – ' . date('M d, Y', strtotime($ps['date_to'])),
                        'ref_no'  => $ps['ref_no'],
                        'present' => $ps['present'], 'absent' => $ps['absent'], 'late' => $ps['late'], 'ot' => $ps['ot'],
                        'gross'   => n2($gr2), 'deductions' => n2($ded2), 'net' => n2($ps['net']),
                        // Full earnings breakdown (mirrors the Latest Payslip card)
                        'per_day'    => n2($ps['per_day']),
                        'basic'      => n2($ps['basic_pay']),
                        'allow_days' => nd($ps['allowance_days']), 'allow_rate' => n2($ps['allowance_amount']), 'allow_amt' => n2($at2),
                        'absent_amt' => n2($ab2),
                        'subtotal'   => n2($sub2),
                        'ot_hrs'     => nd($ps['ot']), 'ot_rate' => n2($ps['ot_rate']), 'ot_amt' => n2($ot2),
                        'lgl_days'   => nd($ps['legal_holiday']),   'lgl_amt' => n2($lgl2),
                        'sun_days'   => nd($ps['sunday_duty']),     'sun_amt' => n2($sun2),
                        'spc_days'   => nd($ps['special_holiday']), 'spc_amt' => n2($spc2),
                        'late_min'   => number_format($ps['late']), 'late_amt' => n2($la2),
                        'ut_min'     => number_format($ps['under_time']), 'ut_amt' => n2($ut2),
                        // Full deductions breakdown
                        'd_contrib'  => n2($ps['deduction_amount']),
                        'd_sssfund'  => n2($ps['sss_fund']),
                        'd_tax'      => n2($ps['tax']),
                        'd_jei'      => n2($ps['jei_advances']),
                        'd_jcc'      => n2($ps['jcc_advances']),
                        'd_other'    => n2($ps['other_deduction']),
                        'review_status'  => $psReview,
                        'review_comment' => $ps['review_comment'],
                        'admin_reply'    => $ps['review_admin_reply'],
                        'resolved_at'    => $ps['review_resolved_at'],
                    ];
                    $psMobile[] = [
                        'item_id'    => (int)$ps['item_id'],
                        'payroll_id' => (int)$ps['payroll_id'],
                        'period'     => date('M d', strtotime($ps['date_from'])) . ' – ' . date('M d, Y', strtotime($ps['date_to'])),
                        'year'       => date('Y', strtotime($ps['date_from'])),
                        'ref'        => $ps['ref_no'],
                        'present'    => $ps['present'], 'absent' => $ps['absent'], 'late' => $ps['late'], 'ot' => $ps['ot'],
                        'gross'      => n2($gr2), 'ded' => n2($ded2), 'net' => n2($ps['net']),
                        'status'     => $psStatus, 'review' => $psReview,
                    ];
                ?>
                <tr data-payroll-id="<?= (int)$ps['payroll_id'] ?>" onclick="openPayslipPreview(<?= (int)$ps['item_id'] ?>)" title="Click to preview payslip">
                    <td class="ps-chk-td" data-label="" style="text-align:center;" onclick="event.stopPropagation();">
                        <input type="checkbox" class="ps-sel-check" value="<?= (int)$ps['item_id'] ?>">
                    </td>
                    <td data-label="Pay Period">
                        <div style="font-weight:700;font-size:12px;"><?= date('M d', strtotime($ps['date_from'])) ?> – <?= date('M d, Y', strtotime($ps['date_to'])) ?></div>
                        <div style="font-size:10px;color:#aaa;"><?= date('Y', strtotime($ps['date_from'])) ?></div>
                    </td>
                    <td data-label="Ref No."><span style="font-family:monospace;font-size:11px;font-weight:700;color:#219688;"><?= htmlspecialchars($ps['ref_no']) ?></span></td>
                    <td class="r" data-label="Present"><span class="present-pill"><?= $ps['present'] ?>d</span></td>
                    <td class="r" data-label="Absent"><?= $ps['absent'] > 0 ? '<span class="absent-pill">'.$ps['absent'].'d</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r" data-label="Late"><?= $ps['late'] > 0 ? '<span class="late-pill">'.number_format($ps['late']).'m</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r" data-label="OT"><?= $ps['ot'] > 0 ? '<span style="color:#fd7e14;font-weight:700;">'.$ps['ot'].'h</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r" data-label="Gross" style="font-weight:700;color:#219688;">₱<?= n2($gr2) ?></td>
                    <td class="r" data-label="Deductions" style="color:#dc3545;">₱<?= n2($ded2) ?></td>
                    <td class="r" data-label="Net Pay"><span class="net-badge">₱<?= n2($ps['net']) ?></span></td>
                    <td class="r" data-label="">
                        <?php if ($psStatus === 3): ?>
                            <?php if ($psReview === null): ?>
                                <div class="d-flex flex-column align-items-end gap-1">
                                    <span class="mydtr-badge review">Awaiting review</span>
                                    <button type="button" class="mydtr-btn primary" onclick="event.stopPropagation(); openPayrollReview(<?= (int)$ps['payroll_id'] ?>)">Review</button>
                                </div>
                            <?php elseif ($psReview === 1): ?>
                                <div class="d-flex flex-column align-items-end gap-1">
                                    <span class="mydtr-badge ok">Confirmed</span>
                                    <button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(<?= (int)$ps['payroll_id'] ?>)">Update</button>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column align-items-end gap-1">
                                    <span class="mydtr-badge dispute">Disputed</span>
                                    <button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(<?= (int)$ps['payroll_id'] ?>)">Update</button>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <i class="ri-eye-line"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">TOTAL (<?= count($payslips) ?> periods)</td>
                        <td class="r" data-label="Total Gross">₱<?= n2($t_gross) ?></td>
                        <td class="r" data-label="Total Deductions" style="color:#dc3545;">₱<?= n2($t_ded) ?></td>
                        <td class="r" data-label="Total Net Pay" style="color:#219688;font-size:14px;">₱<?= n2($t_net) ?></td>
                        <td data-label=""></td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <!-- Mobile-only card list (separate markup — no table on phones) -->
            <div class="ps-mlist">
                <?php foreach ($psMobile as $m): ?>
                <div class="psm-card" data-payroll-id="<?= $m['payroll_id'] ?>" onclick="openPayslipPreview(<?= $m['item_id'] ?>)">
                    <input type="checkbox" class="ps-sel-check psm-chk" value="<?= $m['item_id'] ?>" title="Select" onclick="event.stopPropagation();">
                    <div class="psm-period"><?= htmlspecialchars($m['period']) ?><small><?= $m['year'] ?></small></div>
                    <div class="psm-ref"><?= htmlspecialchars($m['ref']) ?></div>
                    <div class="psm-stats">
                        <div><span>Present</span><b><?= $m['present'] ?>d</b></div>
                        <div><span>Absent</span><b class="<?= $m['absent']>0?'abs':'mut' ?>"><?= $m['absent']>0 ? $m['absent'].'d' : '—' ?></b></div>
                        <div><span>Late</span><b class="<?= $m['late']>0?'lt':'mut' ?>"><?= $m['late']>0 ? number_format($m['late']).'m' : '—' ?></b></div>
                        <div><span>OT</span><b class="<?= $m['ot']>0?'ot':'mut' ?>"><?= $m['ot']>0 ? $m['ot'].'h' : '—' ?></b></div>
                    </div>
                    <div class="psm-money">
                        <div><span class="lbl">Gross</span><span class="val">₱<?= $m['gross'] ?></span></div>
                        <div class="ded"><span class="lbl">Deductions</span><span class="val">₱<?= $m['ded'] ?></span></div>
                    </div>
                    <?php if ($m['status'] === 3): ?>
                    <div class="psm-action">
                        <?php if ($m['review'] === null): ?>
                            <span class="mydtr-badge review">Awaiting review</span>
                            <button type="button" class="mydtr-btn primary" onclick="event.stopPropagation(); openPayrollReview(<?= $m['payroll_id'] ?>)">Review</button>
                        <?php elseif ($m['review'] === 1): ?>
                            <span class="mydtr-badge ok">Confirmed</span>
                            <button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(<?= $m['payroll_id'] ?>)">Update</button>
                        <?php else: ?>
                            <span class="mydtr-badge dispute">Disputed</span>
                            <button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(<?= $m['payroll_id'] ?>)">Update</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="psm-net"><span>Net Pay</span><b>₱<?= $m['net'] ?></b></div>
                </div>
                <?php endforeach; ?>
                <div class="psm-total">
                    <div class="rowt"><span>Total Gross</span><b>₱<?= n2($t_gross) ?></b></div>
                    <div class="rowt"><span>Total Deductions</span><b>₱<?= n2($t_ded) ?></b></div>
                    <div class="rowt net"><span>Total Net (<?= count($payslips) ?> periods)</span><b>₱<?= n2($t_net) ?></b></div>
                </div>
            </div>
        </div>
        <script>var PAYROLL_REVIEW_DATA = <?= json_encode($payrollReviewJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-file-list-3-line"></i></div><p>No payslip records found.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: My DTR (review & sign-off) ── -->
    <div class="tab-panel" id="tab-mydtr">
        <div class="sec"><i class="ri-file-list-3-line"></i>My DTR — Review &amp; Confirm</div>
        <div class="mydtr-intro">
            When your timekeeper marks a DTR <b>Ready for Review</b>, it appears here. Please check your attendance
            for the period and <b>Confirm</b> if everything is correct, or <b>Dispute</b> with a note if something looks wrong.
            Your response is sent to HR before payroll is processed.
        </div>
        <div id="mydtr-list">
            <div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>
        </div>
    </div>

    <!-- ── Tab: Attendance ── -->
    <div class="tab-panel" id="tab-attendance">
        <div class="sec"><i class="ri-calendar-check-line"></i>Attendance Records</div>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f0f5f4;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12px;color:#888;"><span id="att-count">0</span> records</span>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div id="att-range" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="att-range-label">Today</span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <button onclick="clearAttFilter()" class="btn btn-sm" style="background:#f0f5f4;color:#888;padding:5px 10px;font-size:11px;border:none;border-radius:7px;">Today</button>
                </div>
            </div>
            <div class="table-responsive">
            <table class="att-table" id="att-tbl" style="width:100%;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Work Hours</th>
                        <th>OT Hours</th>
                        <th>Time In / Out</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            </div>
            <!-- Mobile: infinite-scroll card feed (replaces the table ≤600px) -->
            <div class="att-mlist-wrap">
                <div id="att-mlist"></div>
                <div class="attm-foot" id="att-mfoot" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- ── Tab: Attendance Requests ── -->
    <div class="tab-panel" id="tab-att-requests">
        <!-- Request a Request Button -->
        <div class="d-flex gap-2 mb-3">
            <button type="button" onclick="openAttRequestModal()"
                style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
                <i class="ri-add-circle-line me-1"></i>File a Request
            </button>
        </div>

        <!-- My Request History — server-side DataTable on desktop; on mobile a
             dedicated infinite-scroll card feed (#areq-mlist) hits the same endpoint. -->
        <div class="sec"><i class="ri-history-line"></i>My Requests
            <span style="font-weight:600;color:#8a9794;font-size:12px;">(<span id="areq-count">0</span>)</span>
        </div>
        <div id="att-req-list-wrap">
            <div class="paper" style="border-radius:14px;overflow:hidden;">
                <div class="table-responsive">
                <table class="att-table" id="att-req-tbl" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Filed</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th>Details</th>
                            <th class="text-center">Status</th>
                            <th>Reviewer Notes</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                </div>
                <!-- Mobile: infinite-scroll card feed (replaces the table ≤600px) -->
                <div class="areq-mlist-wrap">
                    <div id="areq-mlist"></div>
                    <div class="attm-foot" id="areq-mfoot" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Tab: Compare ── -->
    <div class="tab-panel" id="tab-compare">
        <div class="sec"><i class="ri-arrow-left-right-line"></i>Compare My Payslips</div>
        <?php if (count($cmp_data) > 1): ?>
        <div class="paper" style="border-radius:14px;padding:14px;margin-bottom:14px;">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="info-lbl" style="margin-bottom:4px;">Period A</label>
                    <select id="cmp-a"></select>
                </div>
                <div class="col-12 col-md-2 text-center" style="padding-bottom:4px;">
                    <span style="display:inline-flex;width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:800;font-size:11px;align-items:center;justify-content:center;">VS</span>
                </div>
                <div class="col-12 col-md-5">
                    <label class="info-lbl" style="margin-bottom:4px;">Period B</label>
                    <select id="cmp-b"></select>
                </div>
            </div>
        </div>
        <div id="cmp-result"></div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-arrow-left-right-line"></i></div><p>You need at least two payslips to compare.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Loans ── -->
    <div class="tab-panel" id="tab-loans">
        <div class="sec"><i class="ri-bank-line"></i>Active Loans</div>
        <?php if (count($loans)): ?>
        <?php foreach ($loans as $loan):
            $paid = max(0, $loan['loan_amount'] - $loan['loan_balance']);
            $pct  = $loan['loan_amount'] > 0 ? round($paid / $loan['loan_amount'] * 100, 1) : 0;
            $periods_left = $loan['damount'] > 0 ? ceil($loan['loan_balance'] / $loan['damount']) : '?';
        ?>
        <div class="loan-c">
            <div class="loan-head">
                <div>
                    <div class="loan-type-lbl"><?= htmlspecialchars($loan['type_name']) ?></div>
                    <div style="font-size:11px;color:#aaa;margin-top:2px;">Since <?= date('M d, Y', strtotime($loan['loan_date'])) ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:10px;color:#888;">Remaining Balance</div>
                    <div class="loan-bal-val">₱<?= n2($loan['loan_balance']) ?></div>
                </div>
            </div>
            <div class="loan-prog"><div class="loan-prog-bar" style="width:<?= $pct ?>%;"></div></div>
            <div class="loan-meta">
                <span>₱<?= n2($paid) ?> paid of ₱<?= n2($loan['loan_amount']) ?> <strong>(<?= $pct ?>%)</strong></span>
                <span>₱<?= n2($loan['damount']) ?> / period</span>
            </div>
            <?php if (is_numeric($periods_left) && $periods_left > 0): ?>
            <div class="loan-est"><i class="ri-time-line me-1"></i>~<?= $periods_left ?> payroll period<?= $periods_left>1?'s':'' ?> remaining</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <!-- Loan total -->
        <div style="background:linear-gradient(135deg,#219688,#176358);border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
            <span style="color:rgba(255,255,255,.8);font-size:12px;font-weight:700;">TOTAL LOAN BALANCE</span>
            <span style="color:#fff;font-size:20px;font-weight:900;">₱<?= n2($total_loan_balance) ?></span>
        </div>
        <?php else: ?>
        <div class="empty-state success"><div class="empty-ic"><i class="ri-check-double-line"></i></div><p>No active loans. You're debt-free!</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Contributions ── -->
    <div class="tab-panel" id="tab-contrib">
        <div class="sec"><i class="ri-shield-check-line"></i>Contributions &amp; Withholding</div>
        <?php if (count($contrib_hist)): ?>
        <div class="con-intro"><i class="ri-information-line me-1"></i>Statutory contributions and taxes deducted and remitted on your behalf. Keep these figures for your own records &mdash; the totals below cover <?= $cur_year ?> so far.</div>
        <div class="con-hero">
            <div class="con-box">
                <div class="con-cap"><i class="ri-hand-coin-line"></i>Contributions</div>
                <div class="con-val">₱<?= n0($ytd_contrib) ?></div>
                <div class="con-note"><?= $cur_year ?> · SSS / PhilHealth / Pag-IBIG</div>
            </div>
            <div class="con-box b2">
                <div class="con-cap"><i class="ri-safe-2-line"></i>SSS Provident</div>
                <div class="con-val">₱<?= n0($ytd_sssfund) ?></div>
                <div class="con-note"><?= $cur_year ?> · provident fund</div>
            </div>
            <div class="con-box b3">
                <div class="con-cap"><i class="ri-government-line"></i>Withholding Tax</div>
                <div class="con-val">₱<?= n0($ytd_tax) ?></div>
                <div class="con-note"><?= $cur_year ?> · income tax</div>
            </div>
            <div class="con-box b4">
                <div class="con-cap"><i class="ri-stack-line"></i><?= $cur_year ?> Total</div>
                <div class="con-val">₱<?= n0($ytd_contrib_total) ?></div>
                <div class="con-note">all remittances this year</div>
            </div>
        </div>

        <div class="sec"><i class="ri-history-line"></i>Remittance History</div>
        <div style="background:#ffffff;border:1px solid #e4ecea;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(16,55,50,.05), 0 8px 22px -12px rgba(16,55,50,.18);">
            <div style="overflow-x:auto;">
                <table class="con-tbl">
                    <thead>
                        <tr>
                            <th>Pay Period</th>
                            <th class="r">Contributions</th>
                            <th class="r">SSS Provident</th>
                            <th class="r">Tax</th>
                            <th class="r">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contrib_hist as $ch): ?>
                        <tr>
                            <td data-label="Pay Period">
                                <div style="font-weight:700;color:#176358;"><?= htmlspecialchars($ch['period']) ?></div>
                                <div style="font-size:10px;color:#aaa;font-family:monospace;"><?= htmlspecialchars($ch['ref']) ?></div>
                            </td>
                            <td class="r" data-label="Contributions"><?= $ch['contrib'] > 0 ? '₱'.n2($ch['contrib']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="SSS Provident"><?= $ch['sssfund'] > 0 ? '₱'.n2($ch['sssfund']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="Tax"><?= $ch['tax'] > 0 ? '₱'.n2($ch['tax']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="Total" style="font-weight:800;color:#176358;">₱<?= n2($ch['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td data-label="">Lifetime total (<?= count($contrib_hist) ?> period<?= count($contrib_hist) == 1 ? '' : 's' ?>)</td>
                            <td class="r" data-label="Contributions">₱<?= n2($life_contrib) ?></td>
                            <td class="r" data-label="SSS Provident">₱<?= n2($life_sssfund) ?></td>
                            <td class="r" data-label="Tax">₱<?= n2($life_tax) ?></td>
                            <td class="r" data-label="Total">₱<?= n2($life_contrib_total) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div style="font-size:11px;color:#8a9794;margin-top:10px;line-height:1.5;"><i class="ri-error-warning-line me-1"></i>These figures come from your reviewed and locked payslips. For official contribution records, please coordinate with HR.</div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-shield-check-line"></i></div><p>No contributions recorded yet.<br>They'll appear here once your payslips are released.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Leave ── -->
    <div class="tab-panel" id="tab-leave">

        <?php if (!$portal_leave_eligible): ?>
        <div style="border-radius:12px;padding:14px 16px;margin-bottom:16px;background:#fff8e8;color:#8a6d1a;border:1px solid #f0d98a;font-size:12.5px;display:flex;align-items:center;gap:10px;">
            <i class="ri-information-line" style="font-size:20px;"></i>
            <div>Leave credits apply to <b>Regular</b> and <b>Executive</b> employees only. Your records below are shown for reference.</div>
        </div>
        <?php endif; ?>

        <!-- Company calendar (holidays / activities) -->
        <div class="sec"><i class="ri-calendar-2-line"></i>Holidays &amp; Activities</div>
        <?php if (count($calendar_events_portal)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;margin-bottom:18px;">
            <div class="table-responsive">
            <table class="ps-hist-table">
                <thead><tr><th>Date</th><th>Event</th><th>Type</th></tr></thead>
                <tbody>
                <?php foreach ($calendar_events_portal as $ev):
                    $isHol = $ev['type'] == 1;
                    $range = date('M d, Y', strtotime($ev['start_date'])) . ($ev['end_date'] && $ev['end_date'] != $ev['start_date'] ? ' – ' . date('M d, Y', strtotime($ev['end_date'])) : '');
                ?>
                <tr>
                    <td data-label="Date" style="white-space:nowrap;"><span style="border-left:4px solid <?= htmlspecialchars($ev['color']) ?>;padding-left:8px;"><?= $range ?></span></td>
                    <td data-label="Event"><b><?= $isHol ? '🛑' : '📌' ?> <?= htmlspecialchars($ev['title']) ?></b><?php if ($ev['note']): ?><div style="font-size:11px;color:#999;"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?></td>
                    <td data-label="Type"><?php if ($isHol): ?><span style="background:#fff0f0;color:#c62828;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;">Holiday</span><?php else: ?><span style="background:#e8f0ff;color:#0d6efd;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;">Activity</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-calendar-2-line"></i></div><p>No upcoming holidays or activities.</p></div>
        <?php endif; ?>

        <?php if ($portal_leave_eligible): ?>
        <!-- Leave balance -->
        <div class="sec"><i class="ri-coins-line"></i>My Leave Balance</div>
        <?php if (count($leave_balance)): ?>
        <div class="ytd-strip" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));">
            <?php foreach ($leave_balance as $b):
                $avail = (float)$b['credits']; $used = (float)$b['used']; $rem = $avail - $used;
                $fmt = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
            ?>
            <div class="ytd-box <?= $rem <= 0 ? 'd' : 'g' ?>">
                <div class="ytd-val"><?= $fmt($rem) ?><span style="font-size:11px;color:#aaa;font-weight:600;"> / <?= $fmt($avail) ?></span></div>
                <div class="ytd-lbl"><?= htmlspecialchars($b['name']) ?></div>
                <div style="font-size:10px;color:#bbb;margin-top:3px;">Used <?= $fmt($used) ?> day(s)</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-coins-line"></i></div><p>No leave types configured yet.</p></div>
        <?php endif; ?>

        <!-- Request Leave Button -->
        <div class="d-flex gap-2 mb-3">
            <button type="button" onclick="openLeaveModal()"
                style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
                <i class="ri-add-circle-line me-1"></i>Request a Leave
            </button>
            <?php if (!empty($lwop_types_list)): ?>
            <button type="button" onclick="openLwopModal()"
                style="background:linear-gradient(135deg,#c62828,#8b0000);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
                <i class="ri-close-circle-line me-1"></i>File LWOP
            </button>
            <?php endif; ?>
        </div>
        <?php endif; // end eligible: balance + request form ?>

        <?php if (!empty($lwop_types_list) && !$portal_leave_eligible): ?>
        <div class="d-flex mb-3">
            <button type="button" onclick="openLwopModal()"
                style="background:linear-gradient(135deg,#c62828,#8b0000);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
                <i class="ri-close-circle-line me-1"></i>File LWOP
            </button>
        </div>
        <?php endif; ?>

        <!-- My leave history -->
        <div class="sec"><i class="ri-history-line"></i>My Leave Requests</div>
        <div id="leave-list-wrap">
        <?php if (count($my_leaves)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div class="table-responsive">
            <table class="ps-hist-table">
                <thead>
                    <tr>
                        <th>Date Applied</th>
                        <th>Type</th>
                        <th>Period</th>
                        <th class="r">Days</th>
                        <th>HR</th>
                        <th>Final</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stMap = [0 => ['Pending','#fd7e14','#fff8e8'], 1 => ['Approved','#176358','#e8f7f5'], 2 => ['Rejected','#dc3545','#fff0f0']];
                $stageChip = function ($s) {
                    if ($s == 1) return '<span style="color:#176358;" title="Approved"><i class="ri-checkbox-circle-fill"></i></span>';
                    if ($s == 2) return '<span style="color:#dc3545;" title="Rejected"><i class="ri-close-circle-fill"></i></span>';
                    return '<span style="color:#fd7e14;" title="Pending"><i class="ri-time-fill"></i></span>';
                };
                foreach ($my_leaves as $ml):
                    [$slabel, $scol, $sbg] = $stMap[$ml['status']] ?? ['Unknown','#888','#eee'];
                    $rej = $ml['admin_remarks'] ?: $ml['hr_remarks'];
                ?>
                <tr>
                    <td data-label="Date Applied"><?= date('M d, Y', strtotime($ml['date_applied'])) ?></td>
                    <td data-label="Type"><span style="font-weight:700;color:#176358;"><?= htmlspecialchars($ml['leave_type_name']) ?></span></td>
                    <td data-label="Period" style="font-size:11px;"><?= date('M d', strtotime($ml['date_from'])) ?> – <?= date('M d, Y', strtotime($ml['date_to'])) ?></td>
                    <td class="r" data-label="Days"><b><?= rtrim(rtrim(number_format($ml['duration'], 1), '0'), '.') ?></b></td>
                    <td data-label="HR"><?= $stageChip($ml['hr_status']) ?></td>
                    <td data-label="Final"><?= $stageChip($ml['admin_status']) ?></td>
                    <td data-label="Status">
                        <span style="background:<?= $sbg ?>;color:<?= $scol ?>;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;"><?= $slabel ?></span>
                        <?php if ($ml['status'] == 2 && $rej): ?>
                            <div style="font-size:10px;color:#dc3545;margin-top:2px;" title="<?= htmlspecialchars($rej) ?>"><i class="ri-information-line"></i> <?= htmlspecialchars(mb_strimwidth($rej, 0, 30, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-calendar-event-line"></i></div><p>You haven't filed any leave requests yet.</p></div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Tab: My Info ── -->
    <div class="tab-panel" id="tab-info">
        <div class="sec"><i class="ri-profile-line"></i>My Information</div>

        <!-- Personal -->
        <div class="info-section">
            <div class="info-sec-title"><i class="ri-user-3-line"></i> Personal Details</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">Full Name</div>
                    <div class="info-val"><?= htmlspecialchars(trim($emp['firstname'].' '.($emp['middlename'] ?? '').' '.$emp['lastname'].' '.($emp['ext'] ?? ''))) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Employee No.</div>
                    <div class="info-val mono teal"><?= htmlspecialchars($emp['employee_no']) ?></div>
                </div>
                <?php if (!empty($emp['employee_code'])): ?>
                <div class="info-item">
                    <div class="info-lbl">Employee Code</div>
                    <div class="info-val mono"><?= htmlspecialchars($emp['employee_code']) ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-lbl">Birthday</div>
                    <div class="info-val"><?= !empty($emp['bday']) ? date('F d, Y', strtotime($emp['bday'])) : '—' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Department</div>
                    <div class="info-val"><?= htmlspecialchars($emp['dept_name']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Position</div>
                    <div class="info-val"><?= htmlspecialchars($emp['pos_name']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Classification</div>
                    <div class="info-val"><?= htmlspecialchars($emp['clasification_name']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Status</div>
                    <div class="info-val"><?= ((int)$emp['status'] === 1) ? '<span style="color:#219688;">● Active</span>' : '<span style="color:#dc3545;">● Inactive</span>' ?></div>
                </div>
            </div>
        </div>

        <!-- Government IDs -->
        <div class="info-section">
            <div class="info-sec-title"><i class="ri-government-line"></i> Government IDs</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">SSS No.</div>
                    <div class="info-val mono"><?= !empty($emp['sss_no']) ? htmlspecialchars($emp['sss_no']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">PhilHealth No.</div>
                    <div class="info-val mono"><?= !empty($emp['ph_no']) ? htmlspecialchars($emp['ph_no']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Pag-IBIG (HDMF) No.</div>
                    <div class="info-val mono"><?= !empty($emp['hdmf_no']) ? htmlspecialchars($emp['hdmf_no']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">TIN</div>
                    <div class="info-val mono"><?= !empty($emp['tin_no']) ? htmlspecialchars($emp['tin_no']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
            </div>
        </div>

        <!-- Compensation -->
        <div class="info-section">
            <div class="info-sec-title"><i class="ri-money-dollar-circle-line"></i> Compensation & Schedule</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">Basic Pay</div>
                    <div class="info-val teal">₱<?= n2($emp['basic_pay']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Daily Rate</div>
                    <div class="info-val teal">₱<?= n2($emp['salary']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">OT Rate</div>
                    <div class="info-val">₱<?= n2($emp['ot_rate']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Allowance Rate</div>
                    <div class="info-val">₱<?= n2($emp['allowance_rate']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">SSS Provident Fund</div>
                    <div class="info-val">₱<?= n2($emp['sss_fund']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Payroll Type</div>
                    <div class="info-val"><?= ((int)$emp['weekly_payroll'] === 1) ? 'Weekly' : 'Semi-Monthly' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Schedule In</div>
                    <div class="info-val"><?= !empty($emp['time_in']) ? htmlspecialchars($emp['time_in']) : '—' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Schedule Out</div>
                    <div class="info-val"><?= !empty($emp['time_out']) ? htmlspecialchars($emp['time_out']) : '—' ?></div>
                </div>
            </div>
        </div>

        <div style="text-align:center;font-size:11px;color:#bbb;margin-top:6px;">
            <i class="ri-information-line"></i> To update any of these details, please contact your HR / Payroll department.
        </div>
    </div>

    <!-- ── Tab: Help ── -->
    <div class="tab-panel" id="tab-help">

        <!-- Welcome banner -->
        <div class="help-hero">
            <div class="help-hero-ic"><i class="ri-lifebuoy-line"></i></div>
            <div>
                <div class="help-hero-t">How can we help, <?= htmlspecialchars(ucfirst(strtolower($emp['firstname']))) ?>?</div>
                <div class="help-hero-s">A quick guide to your Self-Service Portal — payslips, attendance, loans &amp; more.</div>
            </div>
        </div>

        <!-- Quick guide cards -->
        <div class="sec"><i class="ri-compass-3-line"></i>Getting Around</div>
        <div class="help-grid">
            <div class="help-card">
                <div class="help-card-ic" style="background:#e8f7f5;color:#219688;"><i class="ri-dashboard-line"></i></div>
                <div class="help-card-t">Overview</div>
                <div class="help-card-d">Your year-to-date earnings, a net-pay trend, and your most recent payslip at a glance.</div>
            </div>
            <div class="help-card">
                <div class="help-card-ic" style="background:#eef0f8;color:#4a5bbf;"><i class="ri-file-list-3-line"></i></div>
                <div class="help-card-t">Payslips</div>
                <div class="help-card-d">Every pay period you've received. Tap any row to open the full printable payslip.</div>
            </div>
            <div class="help-card">
                <div class="help-card-ic" style="background:#fff8e8;color:#e8920a;"><i class="ri-calendar-check-line"></i></div>
                <div class="help-card-t">Attendance</div>
                <div class="help-card-d">Your daily time records. Filter by a date range and view your in/out punches.</div>
            </div>
            <div class="help-card">
                <div class="help-card-ic" style="background:#fdf0f6;color:#e83e8c;"><i class="ri-bank-line"></i></div>
                <div class="help-card-t">Loans</div>
                <div class="help-card-d">Track each active loan's balance, how much you've paid, and periods remaining.</div>
            </div>
        </div>

        <!-- How to read your payslip -->
        <div class="sec"><i class="ri-book-open-line"></i>Understanding Your Payslip</div>
        <div class="info-section">
            <div class="info-sec-title" style="background:#176358;"><i class="ri-arrow-up-circle-line"></i> Earnings — money added</div>
            <div style="padding:4px 0;">
                <div class="gloss"><span class="gloss-t">Basic Pay</span><span class="gloss-d">Your contracted rate for the pay period.</span></div>
                <div class="gloss"><span class="gloss-t">Allowance</span><span class="gloss-d">Extra pay such as daily allowance × number of days.</span></div>
                <div class="gloss"><span class="gloss-t">Overtime (OT)</span><span class="gloss-d">Hours worked beyond your schedule × your OT rate.</span></div>
                <div class="gloss"><span class="gloss-t">Legal / Special Holiday</span><span class="gloss-d">Premium pay for working on a declared holiday.</span></div>
                <div class="gloss"><span class="gloss-t">Sunday Duty</span><span class="gloss-d">Premium for rendering duty on a Sunday.</span></div>
            </div>
        </div>
        <div class="info-section">
            <div class="info-sec-title" style="background:#b02a37;"><i class="ri-arrow-down-circle-line"></i> Deductions — money subtracted</div>
            <div style="padding:4px 0;">
                <div class="gloss"><span class="gloss-t">Absent</span><span class="gloss-d">Days not worked × your daily rate.</span></div>
                <div class="gloss"><span class="gloss-t">Late / Undertime</span><span class="gloss-d">Minutes late or short × your per-minute rate.</span></div>
                <div class="gloss"><span class="gloss-t">SSS / PhilHealth / Pag-IBIG</span><span class="gloss-d">Mandatory government contributions.</span></div>
                <div class="gloss"><span class="gloss-t">Tax</span><span class="gloss-d">Withholding tax based on your taxable income.</span></div>
                <div class="gloss"><span class="gloss-t">JEI / JCC Advances</span><span class="gloss-d">Repayment of cash advances or loans.</span></div>
            </div>
        </div>
        <div style="background:linear-gradient(135deg,#219688,#176358);border-radius:12px;padding:14px 18px;color:#fff;display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <i class="ri-calculator-line" style="font-size:26px;opacity:.85;"></i>
            <div>
                <div style="font-size:12px;font-weight:800;">Net Pay = Earnings − Deductions</div>
                <div style="font-size:11px;color:rgba(255,255,255,.8);margin-top:2px;">This is your final take-home amount for the period.</div>
            </div>
        </div>

        <!-- FAQ accordion -->
        <div class="sec"><i class="ri-questionnaire-line"></i>Frequently Asked</div>
        <div class="faq">
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)"><span>How do I log in next time?</span><i class="ri-add-line"></i></button>
                <div class="faq-a"><p>Use your <strong>Employee Number</strong> as your username. Your default password is your <strong>birthdate</strong> in <strong>MMDDYYYY</strong> format (e.g. a birthday of Jan 1, 1990 → <code>01011990</code>).</p></div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)"><span>My pay looks wrong — what do I do?</span><i class="ri-add-line"></i></button>
                <div class="faq-a"><p>Open the <strong>Payslips</strong> tab and tap the period in question to review the full breakdown. If something still looks off, contact your HR / Payroll department with the pay-period dates.</p></div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)"><span>Why is my attendance missing a day?</span><i class="ri-add-line"></i></button>
                <div class="faq-a"><p>Attendance comes from the biometric / timekeeping device. If a punch is missing, it may not have synced. Report it to your site timekeeper so it can be corrected.</p></div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)"><span>How do I update my SSS, TIN or other details?</span><i class="ri-add-line"></i></button>
                <div class="faq-a"><p>Government IDs and personal details are managed by HR. Submit the correct details to your HR / Payroll department and they'll update your record.</p></div>
            </div>
            <div class="faq-item">
                <button class="faq-q" onclick="toggleFaq(this)"><span>How do I print a payslip?</span><i class="ri-add-line"></i></button>
                <div class="faq-a"><p>Open the <strong>Payslips</strong> tab, tap a period to open the printable view, then use your browser's print option (or Ctrl/Cmd + P).</p></div>
            </div>
        </div>

        <!-- Contact -->
        <div class="sec"><i class="ri-customer-service-2-line"></i>Still Need Help?</div>
        <div class="contact-card">
            <div class="contact-ic"><i class="ri-customer-service-2-line"></i></div>
            <div style="flex:1;">
                <div class="contact-t">HR / Payroll Department</div>
                <div class="contact-d">Reach out for anything about your pay, attendance, loans, or personal records.</div>
            </div>
            <div class="contact-meta">
                <div><i class="ri-time-line"></i> Mon–Sat, 8:00 AM – 5:00 PM</div>
            </div>
        </div>
    </div>

    <div class="portal-foot">COMC &bull; Employee Self-Service Portal<br>For concerns contact your HR / Payroll department.</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/parsley.js/2.9.2/parsley.min.js"></script>
<script src="assets/libs/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.2.9/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Firebase Cloud Messaging: browser push (works with the portal closed) -->
<!-- PWA: register the service worker on load, independent of the notification
     opt-in, so Android/Chrome offers "Install app". Same SW file fcm-client.js
     uses later, so this is idempotent. Needs HTTPS (or localhost) to run. -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('firebase-messaging-sw.js')
            .catch(function (e) { console.warn('[PWA] SW registration failed:', e); });
    });
}
</script>
<script>window.FCM_SAVE_URL = 'emp-portal-ajax.php?action=save_fcm_token';</script>
<script type="module" src="assets2/js/fcm-client.js"></script>
<script>
function toggleAttFields(type) {
    document.querySelectorAll('.att-incident-field').forEach(function(el){
        el.style.display = type === 'incident' ? '' : 'none';
        var input = el.querySelector('.form-control');
        if (input) { if (type === 'incident') input.setAttribute('required', 'required'); else input.removeAttribute('required'); }
    });
    document.querySelectorAll('.att-ot-field').forEach(function(el){
        el.style.display = type === 'overtime' ? '' : 'none';
        var input = el.querySelector('.form-control');
        if (input) { if (type === 'overtime') input.setAttribute('required', 'required'); else input.removeAttribute('required'); }
    });
    var form = document.getElementById('att-request-form');
    if (form && window.jQuery && jQuery(form).parsley) { jQuery(form).parsley().reset(); }
}

// FAQ accordion — expand the clicked question, collapse the others in its group.
function toggleFaq(btn) {
    var item = btn.closest('.faq-item');
    if (!item) return;
    var willOpen = !item.classList.contains('open');
    var group = item.closest('.faq');
    if (group) group.querySelectorAll('.faq-item.open').forEach(function (el) {
        if (el !== item) el.classList.remove('open');
    });
    item.classList.toggle('open', willOpen);
}

function switchTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-'+id).classList.add('active');
    var activeBtn = btn;
    if (activeBtn) activeBtn.classList.add('active');
    else {
        activeBtn = document.querySelector('.tab-btn[onclick*="\'' + id + '\'"]');
        if (activeBtn) activeBtn.classList.add('active');
    }
    // On mobile the secondary tabs live behind "More" — keep the More item lit
    // (and use it as the scroll target) whenever a secondary section is active.
    var scrollTarget = activeBtn;
    if (activeBtn && activeBtn.classList.contains('tab-secondary')) {
        var moreBtn = document.querySelector('.tab-more');
        if (moreBtn) { moreBtn.classList.add('active'); scrollTarget = moreBtn; }
    }
    // Only recentre when the strip actually scrolls horizontally (avoids page jumps
    // now that the mobile bottom bar fits all items without scrolling).
    var strip = document.querySelector('.tab-strip');
    if (scrollTarget && strip && strip.scrollWidth > strip.clientWidth + 1) {
        scrollTarget.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
    // DataTables mis-measures column widths while its container is display:none —
    // fix it up once the Attendance tab actually becomes visible.
    if (id === 'attendance' && window.attMKick) window.attMKick();
    if (id === 'attendance' && window.attTable) {
        window.attTable.columns.adjust();
    }
    if (id === 'att-requests' && window.areqMKick) window.areqMKick();
    if (id === 'att-requests' && window.areqTable) {
        window.areqTable.columns.adjust();
    }
}

// ── More sheet (mobile) ──────────────────────────────────────────────────────
function openMore() {
    document.getElementById('more-backdrop').classList.add('open');
    document.getElementById('more-sheet').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMore() {
    document.getElementById('more-backdrop').classList.remove('open');
    document.getElementById('more-sheet').classList.remove('open');
    document.body.style.overflow = '';
}
function goMore(id) {
    closeMore();
    switchTab(id, null);
    window.scrollTo(0, 0);
}

// ── Live badge / "Needs Your Action" sync ────────────────────────────────────
// Keeps every pending-count indicator in sync after an AJAX submit, so nothing
// on the page ever needs a full reload to reflect a save.
var PENDING = {
    dtr:     <?= (int) $dtr_review_pending_count ?>,
    att:     <?= (int) $att_req_pending_count ?>,
    payroll: <?= (int) $payroll_review_pending_count ?>,
    leave:   <?= (int) $leave_pending_count ?>
};
var PAYSLIPS_TOTAL = <?= (int) count($payslips) ?>;
var NA_OPTS = {
    pay:   { icon:'ri-file-list-3-line',    icClass:'pay',   tabId:'payslips',     singular:'payslip to review',              plural:'payslips to review' },
    dtr:   { icon:'ri-draft-line',          icClass:'dtr',   tabId:'mydtr',        singular:'DTR to review',                  plural:'DTRs to review' },
    leave: { icon:'ri-calendar-event-line', icClass:'leave', tabId:'leave',        singular:'leave request pending',          plural:'leave requests pending' },
    req:   { icon:'ri-timer-flash-line',    icClass:'req',   tabId:'att-requests', singular:'OT / incident request pending',  plural:'OT / incident requests pending' }
};

function setBadge(id, count, color) {
    var el = document.getElementById(id);
    if (!el) return;
    var badge = el.querySelector('.badge-count');
    if (count > 0) {
        if (!badge) { badge = document.createElement('span'); badge.className = 'badge-count'; el.appendChild(badge); }
        badge.style.background = color || '';
        badge.textContent = count;
    } else if (badge) badge.remove();
}
function setDot(id, count) {
    var el = document.getElementById(id);
    if (!el) return;
    var dot = el.querySelector('.more-dot');
    if (count > 0) {
        if (!dot) { dot = document.createElement('span'); dot.className = 'more-dot'; el.appendChild(dot); }
        dot.textContent = count;
    } else if (dot) dot.remove();
}
function setQaBadge(id, count) {
    var el = document.getElementById(id);
    if (!el) return;
    var badge = el.querySelector('.qa-badge');
    if (count > 0) {
        if (!badge) { badge = document.createElement('span'); badge.className = 'qa-badge'; el.appendChild(badge); }
        badge.textContent = count;
    } else if (badge) badge.remove();
}
function setNeedsAction(key, count) {
    var opts = NA_OPTS[key];
    var wrap = document.querySelector('.needs-action');
    if (!wrap && count > 0) {
        wrap = document.createElement('div');
        wrap.className = 'needs-action';
        wrap.innerHTML = '<div class="na-head"><i class="ri-alarm-warning-line"></i> Needs Your Action</div><div class="na-items"></div>';
        var hdr = document.getElementById('emp-hdr');
        hdr.parentNode.insertBefore(wrap, hdr);
    }
    if (!wrap) return;
    var items = wrap.querySelector('.na-items');
    var item = items.querySelector('[data-na-key="' + key + '"]');
    if (count <= 0) {
        if (item) item.remove();
        if (!items.children.length) wrap.remove();
        return;
    }
    if (!item) {
        item = document.createElement('button');
        item.type = 'button'; item.className = 'na-item'; item.dataset.naKey = key;
        item.onclick = function () { switchTab(opts.tabId, null); };
        items.appendChild(item);
    }
    item.innerHTML = '<span class="na-ic ' + opts.icClass + '"><i class="' + opts.icon + '"></i></span>'
        + '<span class="na-txt"><b>' + count + '</b> ' + (count === 1 ? opts.singular : opts.plural) + '</span>'
        + '<i class="ri-arrow-right-s-line na-go"></i>';
}
function refreshMoreBadge() { setBadge('tabbtn-more', PENDING.dtr + PENDING.att + PENDING.payroll); }
function refreshPayslipsBadge() {
    var el = document.getElementById('tabbtn-payslips');
    if (!el) return;
    var badge = el.querySelector('.badge-count');
    if (!badge) { badge = document.createElement('span'); badge.className = 'badge-count'; el.appendChild(badge); }
    if (PENDING.payroll > 0) { badge.style.background = '#e6a817'; badge.textContent = PENDING.payroll; }
    else { badge.style.background = ''; badge.textContent = PAYSLIPS_TOTAL; }
}
function updatePayslipRow(payrollId, decision) {
    var badgeCls = decision === 1 ? 'ok' : 'dispute';
    var badgeLbl = decision === 1 ? 'Confirmed' : 'Disputed';
    // Desktop table row
    var tr = document.querySelector('#ps-hist tbody tr[data-payroll-id="' + payrollId + '"]');
    if (tr) {
        var td = tr.querySelector('td:last-child');
        if (td) td.innerHTML = '<div class="d-flex flex-column align-items-end gap-1">'
            + '<span class="mydtr-badge ' + badgeCls + '">' + badgeLbl + '</span>'
            + '<button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(' + payrollId + ')">Update</button>'
            + '</div>';
    }
    // Mobile card
    var card = document.querySelector('.ps-mlist .psm-card[data-payroll-id="' + payrollId + '"] .psm-action');
    if (card) card.innerHTML = '<span class="mydtr-badge ' + badgeCls + '">' + badgeLbl + '</span>'
        + '<button type="button" class="mydtr-btn ghost" onclick="event.stopPropagation(); openPayrollReview(' + payrollId + ')">Update</button>';
}

// ── In-place row builders for freshly-submitted Leave / Attendance requests ──
var REASON_LABELS = { forgot_scan:'Forgot to Scan', device_error:'Device Error', system_down:'System Down', overtime:'Overtime', other:'Other' };
function fmtMDY(s) { var d = new Date((s || '').replace(' ', 'T')); if (isNaN(d)) return s || ''; return d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' }); }
function fmtMD(s)  { var d = new Date((s || '').replace(' ', 'T')); if (isNaN(d)) return s || ''; return d.toLocaleDateString('en-US', { month:'short', day:'2-digit' }); }
function fmtTimeHM(t) {
    var parts = (t || '').split(':'); if (parts.length < 2) return t || '';
    var h = parseInt(parts[0], 10), m = parts[1];
    var ap = h >= 12 ? 'PM' : 'AM', h12 = h % 12; if (h12 === 0) h12 = 12;
    return h12 + ':' + m + ' ' + ap;
}
function trimNum(n) { n = Number(n); return (Math.round(n * 10) / 10).toString().replace(/\.0$/, ''); }

// A new request was just filed — pull the fresh, paginated first page from the
// server rather than hand-building a row, so both the desktop table and the
// mobile infinite-scroll feed stay consistent. (areqReload is defined with the
// Requests-tab feed JS further down.)
function prependAttRequestRow(req) {
    if (window.areqReload) window.areqReload();
}

function prependLeaveRow(req) {
    var wrap = document.getElementById('leave-list-wrap');
    if (!wrap) return;
    var tbody = wrap.querySelector('table.ps-hist-table tbody');
    if (!tbody) {
        wrap.innerHTML = '<div class="paper" style="border-radius:14px;overflow:hidden;"><div class="table-responsive">'
            + '<table class="ps-hist-table"><thead><tr><th>Date Applied</th><th>Type</th><th>Period</th>'
            + '<th class="r">Days</th><th>HR</th><th>Final</th><th>Status</th></tr></thead><tbody></tbody></table></div></div>';
        tbody = wrap.querySelector('tbody');
    }
    var pendingChip = '<span style="color:#fd7e14;" title="Pending"><i class="ri-time-fill"></i></span>';
    var row = '<tr>'
        + '<td data-label="Date Applied">' + fmtMDY(req.date_applied) + '</td>'
        + '<td data-label="Type"><span style="font-weight:700;color:#176358;">' + escapeHtml(req.leave_type_name) + '</span></td>'
        + '<td data-label="Period" style="font-size:11px;">' + fmtMD(req.date_from) + ' – ' + fmtMDY(req.date_to) + '</td>'
        + '<td class="r" data-label="Days"><b>' + trimNum(req.duration) + '</b></td>'
        + '<td data-label="HR">' + pendingChip + '</td>'
        + '<td data-label="Final">' + pendingChip + '</td>'
        + '<td data-label="Status"><span style="background:#fff8e8;color:#fd7e14;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;">Pending</span></td>'
        + '</tr>';
    tbody.insertAdjacentHTML('afterbegin', row);
}

// ── Leave modals ─────────────────────────────────────────────────────────────
var BLOCKED = <?= json_encode(array_values(array_unique($blocked_dates))) ?>;

function openLeaveModal() {
    var m = new bootstrap.Modal(document.getElementById('modal-leave-request'));
    m.show();
    document.getElementById('modal-leave-request').addEventListener('shown.bs.modal', function () {
        initLeavePicker();
    }, { once: true });
}

function openLwopModal() {
    var m = new bootstrap.Modal(document.getElementById('modal-lwop-request'));
    m.show();
    document.getElementById('modal-lwop-request').addEventListener('shown.bs.modal', function () {
        initLwopPicker();
    }, { once: true });
}

function openAttRequestModal() {
    var m = new bootstrap.Modal(document.getElementById('modal-att-request'));
    m.show();
}

var _lvPicker = null;
var _lvIsHalf = false;

function setLvDuration(val) {
    _lvIsHalf = (val !== 'full');
    document.getElementById('lv-is-half').value   = _lvIsHalf ? '1' : '0';
    document.getElementById('lv-half-period').value = _lvIsHalf ? val : '';
    document.querySelectorAll('.lv-dur-btn').forEach(function(b) {
        var active = b.dataset.val === val;
        b.style.background  = active ? '#219688' : '#fff';
        b.style.color       = active ? '#fff' : '#555';
        b.style.borderColor = active ? '#219688' : '#b0c4c0';
    });
    document.getElementById('lv-half-hint').textContent = _lvIsHalf ? '(Half-day: pick 1 date only)' : '';
    // Reinit picker with correct mode
    if (_lvPicker) { _lvPicker.destroy(); _lvPicker = null; }
    document.getElementById('lv-dates').value = '';
    document.getElementById('lv-dates-hidden').value = '';
    document.getElementById('lv-dur').style.display = 'none';
    initLeavePicker();
}

function initLeavePicker() {
    var inp = document.getElementById('lv-dates');
    if (!inp) return;
    if (_lvPicker) return;
    _lvPicker = flatpickr(inp, {
        mode: _lvIsHalf ? 'single' : 'multiple',
        dateFormat: 'Y-m-d',
        minDate: 'today',
        disable: BLOCKED,
        onChange: function (sel) {
            document.getElementById('lv-dates-hidden').value = sel.map(function(d){ return flatpickr.formatDate(d,'Y-m-d'); }).join(',');
            var box = document.getElementById('lv-dur');
            if (sel.length) {
                var days = _lvIsHalf ? sel.length * 0.5 : sel.length;
                document.getElementById('lv-dur-val').textContent = days;
                box.style.display = 'block';
            } else box.style.display = 'none';
        }
    });
}

function initLwopPicker() {
    var inp = document.getElementById('lwop-dates');
    if (!inp || inp._flatpickr) return;
    flatpickr(inp, {
        mode: 'multiple',
        dateFormat: 'Y-m-d',
        minDate: 'today',
        disable: BLOCKED,
        onChange: function (sel) {
            document.getElementById('lwop-dates-hidden').value = sel.map(function(d){ return flatpickr.formatDate(d,'Y-m-d'); }).join(',');
        }
    });
}

// Leave/LWOP "at least one day" validation is handled by Parsley via the
// required, readonly #lv-dates / #lwop-dates inputs that flatpickr populates.

// ── AJAX submit: Leave / LWOP / Attendance requests (no page reload) ─────────
function ajaxSubmitForm(form, action, onSuccess) {
    var fd = new FormData(form);
    var params = new URLSearchParams();
    fd.forEach(function (v, k) { params.append(k, v); });
    fetch('emp-portal-ajax.php?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (res.result) {
            Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 2500, showConfirmButton: false });
            onSuccess(res);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
        }
    }).catch(function () {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
    });
}
// Parsley's documented AJAX hook: form:submit only fires once validation passes,
// and returning false stops it from letting the native (page-reloading) submit continue.
// NOTE: the request modals are rendered *after* this <script> block, so the form
// won't exist yet if wireAjaxForm runs at parse time — defer to DOMContentLoaded so
// getElementById actually finds it (otherwise the form falls back to a native submit
// that reloads the page and never saves).
function wireAjaxForm(formId, action, onSuccess) {
    var bind = function () {
        var form = document.getElementById(formId);
        if (!form) return;
        if (window.jQuery && jQuery.fn.parsley) {
            jQuery(form).parsley().on('form:submit', function () {
                ajaxSubmitForm(form, action, onSuccess);
                return false;
            });
        } else {
            form.addEventListener('submit', function (e) { e.preventDefault(); ajaxSubmitForm(form, action, onSuccess); });
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
}

wireAjaxForm('leave-request-form', 'submit_leave_request', function (res) {
    bootstrap.Modal.getInstance(document.getElementById('modal-leave-request')).hide();
    var form = document.getElementById('leave-request-form');
    form.reset();
    if (window.jQuery && jQuery.fn.parsley) jQuery(form).parsley().reset();
    if (_lvPicker) { _lvPicker.destroy(); _lvPicker = null; }
    document.getElementById('lv-dur').style.display = 'none';
    prependLeaveRow(res.request);
    PENDING.leave = res.leave_pending_count;
    setBadge('tabbtn-leave', PENDING.leave);
    setNeedsAction('leave', PENDING.leave);
});

wireAjaxForm('lwop-request-form', 'submit_leave_request', function (res) {
    bootstrap.Modal.getInstance(document.getElementById('modal-lwop-request')).hide();
    var form = document.getElementById('lwop-request-form');
    form.reset();
    if (window.jQuery && jQuery.fn.parsley) jQuery(form).parsley().reset();
    document.getElementById('lwop-dates-hidden').value = '';
    prependLeaveRow(res.request);
    PENDING.leave = res.leave_pending_count;
    setBadge('tabbtn-leave', PENDING.leave);
    setNeedsAction('leave', PENDING.leave);
});

wireAjaxForm('att-request-form', 'submit_attendance_request', function (res) {
    bootstrap.Modal.getInstance(document.getElementById('modal-att-request')).hide();
    document.getElementById('att-request-form').reset();
    document.getElementById('att-req-date').value = '';
    document.getElementById('att-req-date-hidden').value = '';
    toggleAttFields('');
    prependAttRequestRow(res.request);
    PENDING.att = res.att_req_pending_count;
    setBadge('tabbtn-att-requests', PENDING.att);
    setDot('moreitem-att-requests', PENDING.att);
    setNeedsAction('req', PENDING.att);
    refreshMoreBadge();
});

<?php
// Deep-link support for staff notification links (employee-portal.php?tab=mydtr etc).
// Whitelisted so a stray query value can never be interpolated unsafely into the script.
$valid_portal_tabs = ['overview','payslips','attendance','leave','mydtr','att-requests','compare','loans','contrib','info','help'];
$req_tab = $_GET['tab'] ?? null;
if ($req_tab !== null && in_array($req_tab, $valid_portal_tabs, true)):
?>
document.addEventListener('DOMContentLoaded', function () { switchTab('<?= $req_tab ?>', null); window.scrollTo(0, 0); });
<?php endif; ?>

// ── Notification bell ────────────────────────────────────────────────────────
function empRenderNotif(data) {
    var dot  = document.getElementById('emp-bell-dot');
    var list = document.getElementById('emp-notif-list');
    if (dot) dot.style.display = (data.unread > 0) ? 'block' : 'none';
    if (!list) return;
    if (!data.items || !data.items.length) {
        list.innerHTML = '<div class="emp-notif-empty">No notifications yet.</div>';
        return;
    }
    list.innerHTML = data.items.map(function (n) {
        var unread = (parseInt(n.is_read, 10) === 0);
        return '<div class="emp-notif-item' + (unread ? ' unread' : '') + '" data-id="' + n.id + '" data-link="' + (n.link || '') + '">'
            + '<span class="emp-notif-ic emp-notif-' + (n.color || 'primary') + '"><i class="' + (n.icon || 'ri-notification-3-line') + '"></i></span>'
            + '<div class="emp-notif-txt"><div class="emp-notif-title">' + escapeHtml(n.title) + '</div>'
            + '<div class="emp-notif-msg">' + escapeHtml(n.message || '') + '</div>'
            + '<div class="emp-notif-time">' + timeAgo(n.created_at) + '</div></div></div>';
    }).join('');
}
function escapeHtml(s) { return (s || '').replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function timeAgo(ts) {
    if (!ts) return '';
    var d = new Date(ts.replace(' ', 'T')), diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return d.toLocaleDateString();
}
function empLoadNotif() {
    fetch('emp-portal-ajax.php?action=emp_notifications', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(empRenderNotif).catch(function(){});
}
function toggleEmpBell(e) {
    if (e) e.stopPropagation();
    var p = document.getElementById('emp-notif-panel');
    p.classList.toggle('open');
    if (p.classList.contains('open')) empLoadNotif();
}
function empMarkAllRead() {
    fetch('emp-portal-ajax.php?action=emp_mark_all_read', { method: 'POST', credentials: 'same-origin' })
        .then(function () { empLoadNotif(); });
}
document.addEventListener('click', function (e) {
    var panel = document.getElementById('emp-notif-panel');
    var bell  = document.getElementById('emp-bell');
    if (panel && panel.classList.contains('open') && !panel.contains(e.target) && !bell.contains(e.target)) {
        panel.classList.remove('open');
    }
    var item = e.target.closest('.emp-notif-item');
    if (item) {
        var id = item.dataset.id, link = item.dataset.link;
        fetch('emp-portal-ajax.php?action=emp_mark_read', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        }).then(function () {
            if (link && link.indexOf('tab=mydtr') !== -1) { switchTab('mydtr', null); loadMyDtr(); document.getElementById('emp-notif-panel').classList.remove('open'); }
            else if (link) { window.location.href = link; }
            else { empLoadNotif(); }
        });
    }
});
document.addEventListener('DOMContentLoaded', function () { empLoadNotif(); setInterval(empLoadNotif, 60000); });

// ── My DTR review ────────────────────────────────────────────────────────────
var _dtrReviewId = null;
function loadMyDtr() {
    var box = document.getElementById('mydtr-list');
    fetch('emp-portal-ajax.php?action=my_dtr_list', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.result || !res.rows.length) {
                box.innerHTML = '<div class="mydtr-empty"><i class="ri-inbox-line"></i> No DTRs to review right now.</div>';
                return;
            }
            box.innerHTML = res.rows.map(function (d) {
                var period = fmtPeriod(d.date_from, d.date_to);
                var isReview = parseInt(d.status, 10) === 3;
                var rv = d.review_status === null ? null : parseInt(d.review_status, 10);
                var badge, action;
                if (isReview && rv === null) {
                    badge = '<span class="mydtr-badge review">Awaiting your review</span>';
                    action = '<button class="mydtr-btn primary" onclick="openDtrReview(' + d.id + ')"><i class="ri-eye-line me-1"></i>Review &amp; Confirm</button>';
                } else if (rv === 1) {
                    badge = '<span class="mydtr-badge ok">You confirmed</span>';
                    action = '<button class="mydtr-btn ghost" onclick="openDtrReview(' + d.id + ')">View</button>';
                } else if (rv === 2) {
                    badge = '<span class="mydtr-badge dispute">You disputed</span>';
                    action = '<button class="mydtr-btn ghost" onclick="openDtrReview(' + d.id + ')">View</button>';
                } else {
                    badge = '<span class="mydtr-badge done">Approved</span>';
                    action = '<button class="mydtr-btn ghost" onclick="openDtrReview(' + d.id + ')">View</button>';
                }
                return '<div class="mydtr-card">'
                    + '<div class="mydtr-card-main">'
                    + '<div class="mydtr-period"><i class="ri-calendar-2-line"></i> ' + period + '</div>'
                    + '<div class="mydtr-site">' + escapeHtml((d.site_code ? d.site_code + ' — ' : '') + (d.site_name || '')) + '</div>'
                    + '<div class="mydtr-meta">' + (d.day_count || 0) + ' day(s) · ' + (Number(d.total_hours || 0).toFixed(2)) + ' hrs · OT ' + (Number(d.total_ot || 0).toFixed(2)) + '</div>'
                    + '</div>'
                    + '<div class="mydtr-card-side">' + badge + action + '</div>'
                    + '</div>';
            }).join('');
        });
}
function fmtPeriod(f, t) {
    var o = { month: 'short', day: 'numeric' };
    var df = new Date((f || '').replace(' ', 'T')), dt = new Date((t || '').replace(' ', 'T'));
    if (isNaN(df) || isNaN(dt)) return (f || '') + ' – ' + (t || '');
    return df.toLocaleDateString('en-US', o) + ' – ' + dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function openDtrReview(id) {
    _dtrReviewId = id;
    var m = new bootstrap.Modal(document.getElementById('modal-dtr-review'));
    m.show();
    document.getElementById('dtr-review-body').innerHTML = '<div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>';
    document.getElementById('dtr-review-comment').value = '';
    document.getElementById('dtr-review-sub').textContent = '';
    fetch('emp-portal-ajax.php?action=my_dtr_details', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ddtr_id=' + encodeURIComponent(id)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.result) { document.getElementById('dtr-review-body').innerHTML = '<div class="mydtr-empty">' + escapeHtml(res.message || 'Error') + '</div>'; return; }
        renderDtrReview(res);
    });
}
function renderDtrReview(res) {
    document.getElementById('dtr-review-sub').textContent = res.dtr.period + '  ·  ' + res.dtr.site;
    var totH = 0, totOT = 0;
    var rows = res.days.map(function (d) {
        totH += d.work_hours; totOT += d.overtime;
        var io = (d.time_in || '—') + ' → ' + (d.time_out || '—');
        var flag = d.status === 2 ? '<span class="drow-flag dis">disapproved</span>' : (d.status === 1 ? '<span class="drow-flag ok">approved</span>' : '');
        return '<tr><td data-label="Date">' + d.date + '</td><td data-label="Time In/Out">' + io + '</td><td class="tc" data-label="Hrs">' + d.work_hours.toFixed(2) + '</td>'
            + '<td class="tc" data-label="OT">' + d.overtime.toFixed(2) + '</td><td class="tc" data-label="Late">' + d.late.toFixed(0) + '</td><td data-label="Status">' + (flag || '—') + '</td></tr>';
    }).join('');
    var reviewedNote = '';
    if (res.review) {
        var s = parseInt(res.review.status, 10);
        reviewedNote = '<div class="drev-prev ' + (s === 1 ? 'ok' : 'dis') + '">'
            + '<i class="' + (s === 1 ? 'ri-checkbox-circle-line' : 'ri-error-warning-line') + '"></i> You already '
            + (s === 1 ? 'confirmed' : 'disputed') + ' this DTR' + (res.review.comment ? ': “' + escapeHtml(res.review.comment) + '”' : '') + '. You may resubmit to update it.</div>';
        if (res.review.resolved_at && res.review.admin_reply) {
            reviewedNote += '<div class="drev-prev ok"><i class="ri-chat-check-line"></i> HR replied to your dispute: “' + escapeHtml(res.review.admin_reply) + '”</div>';
        }
    }
    document.getElementById('dtr-review-body').innerHTML =
        reviewedNote +
        '<div class="drev-tbl-wrap"><table class="drev-tbl"><thead><tr><th>Date</th><th>Time In/Out</th><th class="tc">Hrs</th><th class="tc">OT</th><th class="tc">Late</th><th>Status</th></tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '<tfoot><tr><th colspan="2">Total</th><th class="tc" data-label="Total Hrs">' + totH.toFixed(2) + '</th><th class="tc" data-label="Total OT">' + totOT.toFixed(2) + '</th><th colspan="2" data-label=""></th></tr></tfoot></table></div>';

    // read-only view once approved (status 2) — hide the action footer
    var footer = document.getElementById('dtr-review-footer');
    footer.style.display = (res.dtr.status === 3) ? 'flex' : 'none';
}
function submitDtrReview(decision) {
    var comment = document.getElementById('dtr-review-comment').value.trim();
    if (decision === 2 && !comment) {
        Swal.fire({ icon: 'warning', title: 'Add a note', text: 'Please describe what looks wrong before disputing.' });
        return;
    }
    Swal.fire({
        title: decision === 1 ? 'Confirm your DTR?' : 'Submit dispute?',
        text: decision === 1 ? 'This tells HR your attendance is correct.' : 'HR will be notified to review your concern.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: decision === 1 ? '#219688' : '#c62828',
        confirmButtonText: decision === 1 ? 'Yes, confirm' : 'Yes, dispute',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        fetch('emp-portal-ajax.php?action=submit_dtr_review', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ddtr_id=' + encodeURIComponent(_dtrReviewId) + '&decision=' + decision + '&comment=' + encodeURIComponent(comment)
        }).then(function (res) { return res.json(); }).then(function (res) {
            if (res.result) {
                bootstrap.Modal.getInstance(document.getElementById('modal-dtr-review')).hide();
                Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 2200, showConfirmButton: false });
                loadMyDtr();
                empLoadNotif();
                if (typeof res.dtr_review_pending_count !== 'undefined') {
                    PENDING.dtr = res.dtr_review_pending_count;
                    setBadge('tabbtn-mydtr', PENDING.dtr, '#e6a817');
                    setDot('moreitem-mydtr', PENDING.dtr);
                    setQaBadge('qa-mydtr', PENDING.dtr);
                    setNeedsAction('dtr', PENDING.dtr);
                    refreshMoreBadge();
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed' });
            }
        });
    });
}
document.addEventListener('DOMContentLoaded', loadMyDtr);

// ── Payslip review / sign-off ───────────────────────────────────────────────
var _payrollReviewId = null;
function openPayrollReview(payrollId) {
    var d = (typeof PAYROLL_REVIEW_DATA !== 'undefined') ? PAYROLL_REVIEW_DATA[payrollId] : null;
    if (!d) return;
    _payrollReviewId = payrollId;
    document.getElementById('payroll-review-sub').textContent = d.period + '  ·  Ref# ' + d.ref_no;
    document.getElementById('payroll-review-comment').value = '';

    var reviewedNote = '';
    if (d.review_status !== null) {
        var s = parseInt(d.review_status, 10);
        reviewedNote = '<div class="drev-prev ' + (s === 1 ? 'ok' : 'dis') + '">'
            + '<i class="' + (s === 1 ? 'ri-checkbox-circle-line' : 'ri-error-warning-line') + '"></i> You already '
            + (s === 1 ? 'confirmed' : 'disputed') + ' this payslip' + (d.review_comment ? ': “' + escapeHtml(d.review_comment) + '”' : '') + '. You may resubmit to update it.</div>';
    }
    if (d.resolved_at && d.admin_reply) {
        reviewedNote += '<div class="drev-prev ok"><i class="ri-chat-check-line"></i> HR replied to your dispute: “' + escapeHtml(d.admin_reply) + '”</div>';
    }
    document.getElementById('payroll-review-body').innerHTML = reviewedNote + buildPayrollReviewBreakdown(d);

    var m = new bootstrap.Modal(document.getElementById('modal-payroll-review'));
    m.show();
}

// Full itemised payslip breakdown for the review modal — mirrors the
// "Latest Payslip" card so employees can verify every earning & deduction
// (OT, holidays, contributions, tax, advances, etc.) before confirming.
function buildPayrollReviewBreakdown(d) {
    var pos = function (v) { return parseFloat(String(v).replace(/,/g, '')) > 0; };
    var eRow = function (lbl, amt, neg) {
        return '<div class="ps-row"><span class="ps-lbl">' + lbl + '</span>'
            + '<span class="ps-val ' + (neg ? 'ded' : 'earn') + '">' + (neg ? '−₱' : '₱') + amt + '</span></div>';
    };

    // ── Attendance stats strip ──
    var stats = '<div class="drev-tbl-wrap prev-stats" style="margin-bottom:12px;"><table class="drev-tbl"><thead><tr>'
        + '<th>Present</th><th>Absent</th><th>Late</th><th>Undertime</th><th>OT</th><th>Daily Rate</th>'
        + '</tr></thead><tbody><tr>'
        + '<td class="tc" data-label="Present">' + d.present + 'd</td>'
        + '<td class="tc" data-label="Absent">' + d.absent + 'd</td>'
        + '<td class="tc" data-label="Late">' + d.late_min + 'm</td>'
        + '<td class="tc" data-label="Undertime">' + d.ut_min + 'm</td>'
        + '<td class="tc" data-label="OT">' + d.ot_hrs + 'h</td>'
        + '<td class="tc" data-label="Daily Rate">₱' + d.per_day + '</td>'
        + '</tr></tbody></table></div>';

    // ── Earnings column ──
    var earn = '<div class="ps-col-title earn">Earnings</div>'
        + eRow('Basic Pay', d.basic)
        + (pos(d.allow_amt) ? eRow('Allowance (' + d.allow_days + 'd × ₱' + d.allow_rate + ')', d.allow_amt) : '')
        + (pos(d.absent_amt) ? eRow('Absent', d.absent_amt, true) : '')
        + '<div class="ps-row"><span class="ps-lbl" style="font-weight:700;">Sub-Total</span>'
        + '<span class="ps-val earn" style="font-weight:800;">₱' + d.subtotal + '</span></div>'
        + (pos(d.ot_amt) ? eRow('Overtime (' + d.ot_hrs + ' hrs × ₱' + d.ot_rate + ')', d.ot_amt) : '')
        + (pos(d.lgl_amt) ? eRow('Legal Holiday (' + d.lgl_days + ')', d.lgl_amt) : '')
        + (pos(d.sun_amt) ? eRow('Sunday Duty (' + d.sun_days + ')', d.sun_amt) : '')
        + (pos(d.spc_amt) ? eRow('Special Holiday (' + d.spc_days + ')', d.spc_amt) : '')
        + (pos(d.late_amt) ? eRow('Late (' + d.late_min + ' min)', d.late_amt, true) : '')
        + '<div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#219688;">Gross Pay</span>'
        + '<span class="ps-val earn" style="font-size:15px;font-weight:900;">₱' + d.gross + '</span></div>';

    // ── Deductions column ──
    var dedItems = ''
        + (pos(d.d_contrib) ? eRow('Contributions', d.d_contrib, false).replace('earn', 'ded') : '')
        + (pos(d.d_sssfund) ? eRow('SSS Provident Fund', d.d_sssfund, false).replace('earn', 'ded') : '')
        + (pos(d.d_tax) ? eRow('Withholding Tax', d.d_tax, false).replace('earn', 'ded') : '')
        + (pos(d.d_jei) ? eRow('JEI Advances', d.d_jei, false).replace('earn', 'ded') : '')
        + (pos(d.d_jcc) ? eRow('JCC Advances', d.d_jcc, false).replace('earn', 'ded') : '')
        + (pos(d.d_other) ? eRow('Other Deductions', d.d_other, false).replace('earn', 'ded') : '');
    if (!dedItems) dedItems = '<div class="ps-row"><span class="ps-lbl dim">No deductions</span></div>';
    var ded = '<div class="ps-col-title ded">Deductions</div>' + dedItems
        + '<div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#dc3545;">Total Deductions</span>'
        + '<span class="ps-val ded" style="font-size:15px;font-weight:900;">₱' + d.deductions + '</span></div>';

    // ── Assemble ──
    return stats
        + '<div class="ps-card" style="margin-bottom:0;">'
        + '<div class="ps-body">'
        + '<div class="ps-col">' + earn + '</div>'
        + '<div class="ps-col">' + ded + '</div>'
        + '</div>'
        + '<div class="ps-net"><div><div class="ps-net-lbl">Net Pay</div>'
        + '<div class="ps-net-period">' + escapeHtml(d.period) + '</div></div>'
        + '<div class="ps-net-val">₱' + d.net + '</div></div>'
        + '</div>';
}
function submitPayrollReview(decision) {
    var comment = document.getElementById('payroll-review-comment').value.trim();
    if (decision === 2 && !comment) {
        Swal.fire({ icon: 'warning', title: 'Add a note', text: 'Please describe what looks wrong before disputing.' });
        return;
    }
    Swal.fire({
        title: decision === 1 ? 'Confirm your payslip?' : 'Submit dispute?',
        text: decision === 1 ? 'This tells HR your payslip is correct.' : 'HR will be notified to review your concern.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: decision === 1 ? '#107c41' : '#c62828',
        confirmButtonText: decision === 1 ? 'Yes, confirm' : 'Yes, dispute',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        fetch('emp-portal-ajax.php?action=submit_payroll_review', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'payroll_id=' + encodeURIComponent(_payrollReviewId) + '&decision=' + decision + '&comment=' + encodeURIComponent(comment)
        }).then(function (res) { return res.json(); }).then(function (res) {
            if (res.result) {
                bootstrap.Modal.getInstance(document.getElementById('modal-payroll-review')).hide();
                Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 2200, showConfirmButton: false });
                if (PAYROLL_REVIEW_DATA[_payrollReviewId]) {
                    PAYROLL_REVIEW_DATA[_payrollReviewId].review_status = decision;
                    PAYROLL_REVIEW_DATA[_payrollReviewId].review_comment = comment;
                }
                updatePayslipRow(_payrollReviewId, decision);
                if (typeof res.payroll_review_pending_count !== 'undefined') {
                    PENDING.payroll = res.payroll_review_pending_count;
                    refreshPayslipsBadge();
                    setQaBadge('qa-payslips', PENDING.payroll);
                    setNeedsAction('pay', PENDING.payroll);
                    refreshMoreBadge();
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed' });
            }
        });
    });
}

// ── Payslip multi-select + bulk print ───────────────────────────────────────
function refreshPsSelection() {
    var n = document.querySelectorAll('.ps-sel-check:checked').length;
    var cnt = document.getElementById('ps-sel-count');
    var btn = document.getElementById('ps-print-selected');
    if (cnt) cnt.textContent = n;
    if (btn) btn.style.display = n > 0 ? '' : 'none';
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'ps-check-all') {
        // only toggle rows currently visible (respects the period search filter)
        document.querySelectorAll('#ps-hist tbody tr').forEach(function (tr) {
            if (tr.style.display === 'none') return;
            var c = tr.querySelector('.ps-sel-check');
            if (c) c.checked = e.target.checked;
        });
        refreshPsSelection();
    } else if (e.target && e.target.classList.contains('ps-sel-check')) {
        refreshPsSelection();
    }
});
function printSelectedMyPayslips() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.ps-sel-check:checked'), function (c) { return c.value; });
    if (!ids.length) return;
    window.open('print-my-payslips.php?ids=' + ids.join(','), '_blank', 'width=960,height=760,scrollbars=yes');
}

// ── Payslip preview: render the payslip as a dompdf PDF inside the modal, same
// flow as the payroll prints — preview inline, download from the modal. ──
function openPayslipPreview(itemId) {
    var frame = document.getElementById('payslip-preview-frame');
    if (!frame) return;
    var url = 'pdf-payroll.php?src=payslip&id=' + encodeURIComponent(itemId);
    frame.src = url;
    var dl = document.getElementById('payslip-preview-download');
    if (dl) dl.href = url + '&download=1';
    new bootstrap.Modal(document.getElementById('modal-payslip-preview')).show();
}

// ── Payroll charts (ApexCharts) ──────────────────────────────
var CHART  = <?= json_encode($chart) ?>;
var CMP    = <?= json_encode($cmp_data) ?>;
var DED    = <?= json_encode($ded_breakdown) ?>;
var TOTDED = <?= json_encode(round($tot_ded, 2)) ?>;
function peso(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

document.addEventListener('DOMContentLoaded', function () {
    initCompare();
    if (typeof ApexCharts === 'undefined') return;
    var base = { chart:{ fontFamily:'Segoe UI,Arial,sans-serif', toolbar:{show:false}, parentHeightOffset:0 },
                 grid:{ borderColor:'#eef3f2', strokeDashArray:0 },
                 xaxis:{ categories:CHART.labels, labels:{ style:{ fontSize:'10px', colors:'#999' } } },
                 dataLabels:{ enabled:false }, legend:{ fontSize:'11px', markers:{ width:9, height:9 } } };

    if (CHART.labels && CHART.labels.length > 1) {
        // Net vs Gross — area, two series
        new ApexCharts(document.querySelector('#chart-pay'), Object.assign({}, base, {
            chart: Object.assign({ type:'area', height:240 }, base.chart),
            colors:['#219688','#4a5bbf'],
            stroke:{ curve:'smooth', width:[3,2] },
            fill:{ type:'gradient', gradient:{ opacityFrom:0.35, opacityTo:0.04 } },
            series:[ { name:'Net Pay', data:CHART.net }, { name:'Gross Pay', data:CHART.gross } ],
            yaxis:{ labels:{ formatter:function(v){ return '₱'+Math.round(v/1000)+'k'; }, style:{ fontSize:'10px', colors:'#999' } } },
            tooltip:{ y:{ formatter:peso } }
        })).render();

        // Late minutes — its own axis (was a misleading dual-axis chart)
        new ApexCharts(document.querySelector('#chart-late'), Object.assign({}, base, {
            chart: Object.assign({ type:'bar', height:200 }, base.chart),
            colors:['#b26a00'],
            plotOptions:{ bar:{ columnWidth:'45%', borderRadius:3 } },
            series:[ { name:'Late (min)', data:CHART.late } ],
            yaxis:{ labels:{ style:{ fontSize:'10px', colors:'#999' } } },
            legend:{ show:false }
        })).render();

        // Overtime hours — its own axis
        new ApexCharts(document.querySelector('#chart-ot'), Object.assign({}, base, {
            chart: Object.assign({ type:'bar', height:200 }, base.chart),
            colors:['#4a5bbf'],
            plotOptions:{ bar:{ columnWidth:'45%', borderRadius:3 } },
            series:[ { name:'OT (hrs)', data:CHART.ot } ],
            yaxis:{ labels:{ style:{ fontSize:'10px', colors:'#999' } } },
            legend:{ show:false }
        })).render();

        // Present vs Absent — grouped column
        new ApexCharts(document.querySelector('#chart-attend'), Object.assign({}, base, {
            chart: Object.assign({ type:'bar', height:200 }, base.chart),
            colors:['#219688','#dc3545'],
            plotOptions:{ bar:{ columnWidth:'55%', borderRadius:3 } },
            series:[ { name:'Present', data:CHART.present }, { name:'Absent', data:CHART.absent } ],
            yaxis:{ labels:{ style:{ fontSize:'10px', colors:'#999' } } }
        })).render();
    }

    // Deduction composition — donut (values also listed in the payslip card below)
    if (DED.length && document.querySelector('#chart-deduct')) {
        new ApexCharts(document.querySelector('#chart-deduct'), {
            chart:{ type:'donut', height:262, fontFamily:'Segoe UI,Arial,sans-serif' },
            colors:['#219688','#4a5bbf','#b26a00','#c9366f','#5e35b1','#7a7f2a'],
            series: DED.map(function(d){ return d.value; }),
            labels: DED.map(function(d){ return d.label; }),
            stroke:{ width:2, colors:['#ffffff'] },
            dataLabels:{ enabled:false },
            legend:{ position:'bottom', fontSize:'11px', markers:{ width:9, height:9 } },
            plotOptions:{ pie:{ donut:{ size:'70%', labels:{ show:true,
                value:{ fontSize:'14px', fontWeight:800, color:'#2b3330', formatter:function(v){ return peso(v); } },
                total:{ show:true, label:'Total', fontSize:'10px', color:'#999', formatter:function(){ return peso(TOTDED); } }
            } } } },
            tooltip:{ y:{ formatter:peso } }
        }).render();
    }
});

function initCompare(){
    var selA=document.getElementById('cmp-a'), selB=document.getElementById('cmp-b');
    if(!selA||!selB||CMP.length<2) return;
    var opts=CMP.map(function(c,i){ return '<option value="'+i+'">'+c.label+' ('+c.ref+')</option>'; }).join('');
    selA.innerHTML=opts; selB.innerHTML=opts;
    selA.value=1; selB.value=0;   // previous vs latest
    // searchable bootstrap-select
    if (window.jQuery && jQuery.fn.selectpicker) {
        jQuery(selA).add(selB).addClass('selectpicker')
            .attr({'data-live-search':'true','data-size':'8','data-width':'100%'})
            .selectpicker();
    }
    function row(label, va, vb, isDed){
        var d=(vb-va), cls=d>0?(isDed?'ded':'earn'):(d<0?(isDed?'earn':'ded'):'');
        var sign=d>0?'+':'';
        return '<tr><td style="padding:7px 12px;color:#666;">'+label+'</td>'+
               '<td style="padding:7px 12px;text-align:right;">'+peso(va)+'</td>'+
               '<td style="padding:7px 12px;text-align:right;">'+peso(vb)+'</td>'+
               '<td style="padding:7px 12px;text-align:right;font-weight:700;" class="'+(d>0?'earn':d<0?'ded':'dim')+'">'+(d===0?'—':sign+peso(d))+'</td></tr>';
    }
    function render(){
        if(selA.value!==''&&selA.value===selB.value){
            document.getElementById('cmp-result').innerHTML=
                '<div class="empty-state warn"><div class="empty-ic"><i class="ri-error-warning-line"></i></div><p>Please select two <strong>different</strong> payroll periods to compare.</p></div>';
            return;
        }
        var a=CMP[selA.value], b=CMP[selB.value];
        if(!a||!b) return;
        var h='<div class="paper" style="border-radius:14px;overflow:hidden;">'+
            '<table style="width:100%;border-collapse:collapse;font-size:12px;">'+
            '<thead><tr style="background:#176358;color:#fff;">'+
            '<th style="padding:9px 12px;text-align:left;">Item</th>'+
            '<th style="padding:9px 12px;text-align:right;">A</th>'+
            '<th style="padding:9px 12px;text-align:right;">B</th>'+
            '<th style="padding:9px 12px;text-align:right;">Diff (B−A)</th></tr>'+
            '<tr style="background:#1f7d70;color:rgba(255,255,255,.85);font-size:10px;">'+
            '<td style="padding:4px 12px;">Period</td><td style="padding:4px 12px;text-align:right;">'+a.label+'</td>'+
            '<td style="padding:4px 12px;text-align:right;">'+b.label+'</td><td></td></tr></thead><tbody>'+
            '<tr><td colspan="4" style="padding:6px 12px;background:#eef6f4;font-weight:800;color:#176358;font-size:10px;text-transform:uppercase;">Earnings</td></tr>'+
            row('Basic Pay',a.basic,b.basic)+
            row('Allowance',a.allowance,b.allowance)+
            row('Overtime',a.ot,b.ot)+
            row('Absent (−)',a.absent,b.absent,true)+
            row('Late (−)',a.late,b.late,true)+
            '<tr style="border-top:1px solid #eee;"><td style="padding:7px 12px;font-weight:800;color:#219688;">Gross Pay</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(a.gross)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(b.gross)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;" class="'+((b.gross-a.gross)>=0?'earn':'ded')+'">'+((b.gross-a.gross)>0?'+':'')+peso(b.gross-a.gross)+'</td></tr>'+
            '<tr><td colspan="4" style="padding:6px 12px;background:#fdecec;font-weight:800;color:#b02a37;font-size:10px;text-transform:uppercase;">Deductions</td></tr>'+
            row('Contributions',a.contrib,b.contrib,true)+
            row('SSS Fund',a.sss_fund,b.sss_fund,true)+
            row('Tax',a.tax,b.tax,true)+
            row('JEI Advances',a.jei,b.jei,true)+
            row('JCC Advances',a.jcc,b.jcc,true)+
            row('Other',a.other,b.other,true)+
            '<tr style="border-top:1px solid #eee;"><td style="padding:7px 12px;font-weight:800;color:#dc3545;">Total Deductions</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(a.ded)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(b.ded)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;" class="'+((b.ded-a.ded)<=0?'earn':'ded')+'">'+((b.ded-a.ded)>0?'+':'')+peso(b.ded-a.ded)+'</td></tr>'+
            '</tbody></table>'+
            '<div style="background:linear-gradient(135deg,#219688,#176358);padding:14px 18px;display:flex;justify-content:space-between;align-items:center;color:#fff;">'+
            '<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;opacity:.8;">Net Pay Difference</div>'+
            '<div style="font-size:11px;opacity:.7;margin-top:2px;">A '+peso(a.net)+' → B '+peso(b.net)+'</div></div>'+
            '<div style="font-size:22px;font-weight:900;">'+((b.net-a.net)>0?'+':'')+peso(b.net-a.net)+'</div></div>'+
            '</div>';
        document.getElementById('cmp-result').innerHTML=h;
    }
    selA.addEventListener('change',render);
    selB.addEventListener('change',render);
    render();
}
document.getElementById('ps-search') && document.getElementById('ps-search').addEventListener('input', function(){
    var q=this.value.toLowerCase();
    document.querySelectorAll('#ps-hist tbody tr, .ps-mlist .psm-card').forEach(function(el){
        el.style.display=el.textContent.toLowerCase().includes(q)?'':'none';
    });
});

// ── Attendance Records — server-side DataTable on desktop; on mobile a
// dedicated infinite-scroll card feed (#att-mlist) hits the same endpoint. ──
var attToday = moment().format('YYYY-MM-DD');
var attFrom  = attToday, attTo = attToday;
var attMobileMQ = window.matchMedia('(max-width:600px)');

// (Re)binds Bootstrap popovers on the log-detail pills just drawn (table or feed).
function initAttPopovers() {
    document.querySelectorAll('#att-tbl [data-bs-toggle="popover"], #att-mlist [data-bs-toggle="popover"]').forEach(function (el) {
        var existing = bootstrap.Popover.getInstance(el);
        if (existing) existing.dispose();
        // 'left' (from the server markup) falls off-screen on phones — pin to top there.
        new bootstrap.Popover(el, { sanitize: false, placement: attMobileMQ.matches ? 'top' : 'left' });
        el.addEventListener('shown.bs.popover', function () {
            document.querySelectorAll('#att-tbl [data-bs-toggle="popover"], #att-mlist [data-bs-toggle="popover"]').forEach(function (other) {
                if (other !== el) bootstrap.Popover.getInstance(other) && bootstrap.Popover.getInstance(other).hide();
            });
        });
    });
}
// Click outside closes any open popover
document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover')) {
        document.querySelectorAll('#att-tbl [data-bs-toggle="popover"], #att-mlist [data-bs-toggle="popover"]').forEach(function (el) {
            var inst = bootstrap.Popover.getInstance(el);
            if (inst) inst.hide();
        });
    }
});

// ── Mobile infinite-scroll feed ──────────────────────────────────
var attM = { start: 0, pageSize: 15, total: null, loading: false, done: false, started: false };

function attMCard(r) {
    // Server cells arrive as HTML fragments; recompose them into an app-style card.
    var card = document.createElement('div');
    card.className = 'attm-card';
    var noteText = (r.notes || '').replace(/<[^>]*>/g, '').trim();
    card.innerHTML =
        '<div class="attm-head">' + r.date + '</div>' +
        r.type +
        '<div class="attm-stats">' +
            '<div class="attm-stat"><span class="attm-sl">Work Hours</span><div class="attm-sv">' + r.work_hours + '</div></div>' +
            '<div class="attm-stat"><span class="attm-sl">OT Hours</span><div class="attm-sv">' + r.ot_hours + '</div></div>' +
        '</div>' +
        '<div class="attm-io"><span class="attm-sl">Time In / Out</span>' + r.time_io + '</div>' +
        (noteText && noteText !== '—' ? '<div class="attm-notes">' + r.notes + '</div>' : '<div style="padding-bottom:11px;"></div>');
    // Date cell arrives as two stacked <div>s — retag them for the card typography.
    var dd = card.querySelectorAll('.attm-head > div');
    if (dd[0]) dd[0].className = 'attm-d1';
    if (dd[1]) dd[1].className = 'attm-d2';
    return card;
}

function attMLoad() {
    if (attM.loading || attM.done) return;
    attM.loading = true;
    attM.started = true;
    var list = document.getElementById('att-mlist');
    var foot = document.getElementById('att-mfoot');
    foot.style.display = '';
    foot.innerHTML = '<span class="attm-spin"></span>Loading…';
    jQuery.post('attendance-portal-server.php', {
        draw: 1, start: attM.start, length: attM.pageSize,
        from: attFrom, to: attTo,
        'order[0][column]': 0, 'order[0][dir]': 'desc',
    }, function (res) {
        attM.total = res.recordsFiltered;
        var c = document.getElementById('att-count');
        if (c) c.textContent = res.recordsFiltered;
        (res.data || []).forEach(function (r) { list.appendChild(attMCard(r)); });
        attM.start += (res.data || []).length;
        attM.done = attM.start >= res.recordsFiltered || !(res.data || []).length;
        if (!attM.start) {
            list.innerHTML = '<div class="attm-empty"><i class="ri-calendar-close-line"></i>No attendance records found for this range.</div>';
            foot.style.display = 'none';
        } else if (attM.done) {
            foot.innerHTML = 'All ' + attM.total + ' record' + (attM.total == 1 ? '' : 's') + ' loaded';
        } else {
            foot.innerHTML = '<span class="attm-spin"></span>Loading…';
        }
        initAttPopovers();
        attM.loading = false;
        // If the footer is still on screen (short first page), keep filling.
        // offsetParent check: never chain-load while the tab itself is hidden.
        if (!attM.done && foot.offsetParent !== null
            && foot.getBoundingClientRect().top < window.innerHeight + 200) attMLoad();
    }, 'json').fail(function () {
        attM.loading = false;
        foot.innerHTML = 'Could not load records — pull down to retry.';
    });
}

function attMReset() {
    attM.start = 0; attM.total = null; attM.done = false; attM.loading = false;
    document.getElementById('att-mlist').innerHTML = '';
    attMLoad();
}

// Loads the next page whenever the feed footer scrolls into view.
if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && attMobileMQ.matches && attM.started) attMLoad();
    }, { rootMargin: '200px' }).observe(document.getElementById('att-mfoot'));
}

// Called on tab switch: starts the feed on first visit (the tab is display:none
// at page load, so the observer can't fire until it's visible).
window.attMKick = function () {
    if (attMobileMQ.matches && !attM.started) attMLoad();
};

// Desktop → keep the server-side DataTable exactly as before.
function attInitTable($) {
    if (window.attTable) return;
    window.attTable = $('#att-tbl').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        lengthChange: false,
        pageLength: 15,
        order: [[0, 'desc']],
        ajax: {
            url: 'attendance-portal-server.php',
            type: 'POST',
            data: function (d) {
                d.from = attFrom;
                d.to   = attTo;
            },
        },
        columns: [
            { data: 'date' },
            { data: 'type' },
            { data: 'work_hours' },
            { data: 'ot_hours' },
            { data: 'time_io', orderable: false },
            { data: 'notes' },
        ],
        language: {
            emptyTable: 'No attendance records found for this range.',
            processing: 'Loading…',
        },
        drawCallback: function (settings) {
            var json = settings.json;
            var c = document.getElementById('att-count');
            if (c && json) c.textContent = json.recordsFiltered;
            initAttPopovers();
        },
    });
}

jQuery(function ($) {
    if (!attMobileMQ.matches) attInitTable($);
    // Viewport crossed the breakpoint (rotation / window resize): bring up the
    // view that hasn't been initialised yet.
    attMobileMQ.addEventListener('change', function () {
        if (attMobileMQ.matches) { window.attMKick(); }
        else attInitTable($);
    });

    var $picker = $('#att-range');
    if (!$picker.length) return;
    $picker.daterangepicker({
        autoUpdateInput: false,
        opens: 'left',
        showDropdowns: true,
        startDate: moment(),
        endDate: moment(),
        locale: { format: 'MMM D, YYYY', cancelLabel: 'Clear', applyLabel: 'Apply' },
        ranges: {
            'Today':        [moment(), moment()],
            'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
            'Last 30 Days': [moment().subtract(29, 'days'), moment()],
            'This Month':   [moment().startOf('month'), moment().endOf('month')],
            'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });
    $picker.on('apply.daterangepicker', function (ev, picker) {
        attFrom = picker.startDate.format('YYYY-MM-DD');
        attTo   = picker.endDate.format('YYYY-MM-DD');
        var lbl = (attFrom === attToday && attTo === attToday)
            ? 'Today'
            : picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY');
        $('#att-range-label').text(lbl);
        if (window.attTable) window.attTable.ajax.reload();
        if (attM.started || attMobileMQ.matches) attMReset();
    });
    $picker.on('cancel.daterangepicker', function () {
        clearAttFilter();
    });
});

// ── "File a Request" date field — single-date bootstrap-daterangepicker ───────
// Mirrors the themed picker used for the attendance filter. The readonly text
// input shows a friendly date; the hidden #att-req-date-hidden carries the
// Y-m-d value the backend (submit_attendance_request) expects.
$(function () {
    var $rd = $('#att-req-date');
    if (!$rd.length) return;
    $rd.daterangepicker({
        singleDatePicker: true,
        autoUpdateInput: false,
        showDropdowns: true,
        maxDate: moment(),
        locale: { format: 'MMM D, YYYY', cancelLabel: 'Clear' }
    });
    $rd.on('apply.daterangepicker', function (ev, picker) {
        $rd.val(picker.startDate.format('MMM D, YYYY'));
        $('#att-req-date-hidden').val(picker.startDate.format('YYYY-MM-DD'));
        if (window.jQuery && jQuery.fn.parsley) jQuery('#att-request-form').parsley().validate();
    });
    $rd.on('cancel.daterangepicker', function () {
        $rd.val('');
        $('#att-req-date-hidden').val('');
    });
});

function clearAttFilter() {
    attFrom = attTo = attToday;
    var lbl = document.getElementById('att-range-label');
    if (lbl) lbl.textContent = 'Today';
    if (window.attTable) window.attTable.ajax.reload();
    if (attM.started || attMobileMQ.matches) attMReset();
}

// ── My Requests (OT / incident) — server-side DataTable on desktop; on mobile a
// dedicated infinite-scroll card feed (#areq-mlist) hits the same endpoint. This
// mirrors the Attendance Records tab so a long request history never lags. ──
var areqMobileMQ = window.matchMedia('(max-width:600px)');
var areqM = { start: 0, pageSize: 15, total: null, loading: false, done: false, started: false };
var AREQ_ENDPOINT = 'attendance-requests-portal-server.php';

function areqCard(r) {
    var card = document.createElement('div');
    card.className = 'areq-card st-' + (r.status_slug || 'pending');
    var typeIcon = r.type_key === 'incident' ? 'ri-error-warning-line' : 'ri-timer-flash-line';
    var html =
        '<span class="areq-status" style="background:' + r.status_color + ';">' + r.status_label + '</span>' +
        '<div class="areq-head">' +
            '<div class="areq-d1"><i class="ri-calendar-event-line"></i>' + r.date_plain + '</div>' +
            '<span class="areq-type t-' + r.type_key + '"><i class="' + typeIcon + '"></i>' + r.type_label + '</span>' +
        '</div>' +
        '<div class="areq-row"><span class="areq-l"><i class="ri-question-line"></i>Reason</span><span class="areq-v">' + r.reason_plain + '</span></div>' +
        '<div class="areq-row"><span class="areq-l"><i class="ri-time-line"></i>Details</span><span class="areq-v">' + r.details_html + '</span></div>' +
        '<div class="areq-row"><span class="areq-l"><i class="ri-upload-2-line"></i>Filed</span><span class="areq-v">' + r.filed + '</span></div>';
    if (r.reviewer_html) {
        html += '<div class="areq-rev"><i class="ri-chat-1-line"></i><span>' + r.reviewer_html + '</span></div>';
    }
    card.innerHTML = html;
    return card;
}

function areqMLoad() {
    if (areqM.loading || areqM.done) return;
    areqM.loading = true;
    areqM.started = true;
    var list = document.getElementById('areq-mlist');
    var foot = document.getElementById('areq-mfoot');
    foot.style.display = '';
    foot.innerHTML = '<span class="attm-spin"></span>Loading…';
    jQuery.post(AREQ_ENDPOINT, {
        draw: 1, start: areqM.start, length: areqM.pageSize,
        'order[0][column]': 0, 'order[0][dir]': 'desc',
    }, function (res) {
        areqM.total = res.recordsFiltered;
        var c = document.getElementById('areq-count');
        if (c) c.textContent = res.recordsFiltered;
        (res.data || []).forEach(function (r) { list.appendChild(areqCard(r)); });
        areqM.start += (res.data || []).length;
        areqM.done = areqM.start >= res.recordsFiltered || !(res.data || []).length;
        if (!areqM.start) {
            list.innerHTML = '<div class="attm-empty"><i class="ri-file-list-3-line"></i>No requests filed yet.</div>';
            foot.style.display = 'none';
        } else if (areqM.done) {
            foot.innerHTML = 'All ' + areqM.total + ' request' + (areqM.total == 1 ? '' : 's') + ' loaded';
        } else {
            foot.innerHTML = '<span class="attm-spin"></span>Loading…';
        }
        areqM.loading = false;
        // Keep filling while the footer is still on screen (short first page).
        if (!areqM.done && foot.offsetParent !== null
            && foot.getBoundingClientRect().top < window.innerHeight + 200) areqMLoad();
    }, 'json').fail(function () {
        areqM.loading = false;
        foot.innerHTML = 'Could not load requests — pull down to retry.';
    });
}

function areqMReset() {
    areqM.start = 0; areqM.total = null; areqM.done = false; areqM.loading = false;
    document.getElementById('areq-mlist').innerHTML = '';
    areqMLoad();
}

// Loads the next page whenever the feed footer scrolls into view.
if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && areqMobileMQ.matches && areqM.started) areqMLoad();
    }, { rootMargin: '200px' }).observe(document.getElementById('areq-mfoot'));
}

// Called on tab switch: starts the feed on first visit (the tab is display:none
// at page load, so the observer can't fire until it's visible).
window.areqMKick = function () {
    if (areqMobileMQ.matches && !areqM.started) areqMLoad();
};

// Desktop → server-side DataTable (paginated), same look as Attendance Records.
function areqInitTable($) {
    if (window.areqTable) return;
    window.areqTable = $('#att-req-tbl').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        lengthChange: false,
        pageLength: 15,
        order: [[0, 'desc']],
        ajax: { url: AREQ_ENDPOINT, type: 'POST' },
        columns: [
            { data: 'filed' },
            { data: 'type', orderable: false },
            { data: 'date' },
            { data: 'reason', orderable: false },
            { data: 'details', orderable: false },
            { data: 'status', className: 'text-center', orderable: false },
            { data: 'reviewer', orderable: false },
        ],
        language: {
            emptyTable: 'No requests filed yet.',
            processing: 'Loading…',
        },
        drawCallback: function (settings) {
            var json = settings.json;
            var c = document.getElementById('areq-count');
            if (c && json) c.textContent = json.recordsFiltered;
        },
    });
}

// Reloads both views after a new request is filed via the AJAX form.
function areqReload() {
    if (window.areqTable) window.areqTable.ajax.reload(null, false);
    if (areqM.started || areqMobileMQ.matches) areqMReset();
}

jQuery(function ($) {
    if (!areqMobileMQ.matches) areqInitTable($);
    areqMobileMQ.addEventListener('change', function () {
        if (areqMobileMQ.matches) { window.areqMKick(); }
        else areqInitTable($);
    });
});
</script>

<!-- Modal: DTR Review / Sign-off -->
<div class="modal fade" id="modal-dtr-review" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;border:0;">
                <div>
                    <h5 class="modal-title mb-0"><i class="ri-file-list-3-line me-1"></i>Review My DTR</h5>
                    <div id="dtr-review-sub" style="font-size:12px;opacity:.85;"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dtr-review-body" style="background:#f7fbfa;">
                <div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>
            </div>
            <div class="modal-footer" id="dtr-review-footer" style="background:#fff;flex-direction:column;align-items:stretch;gap:8px;">
                <textarea id="dtr-review-comment" class="form-control" rows="2"
                    placeholder="Add a comment (required if disputing)…" style="font-size:13px;border-radius:10px;"></textarea>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn" style="background:#fdecea;color:#c62828;font-weight:700;border-radius:10px;"
                        onclick="submitDtrReview(2)"><i class="ri-error-warning-line me-1"></i>Dispute</button>
                    <button type="button" class="btn" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border-radius:10px;"
                        onclick="submitDtrReview(1)"><i class="ri-checkbox-circle-line me-1"></i>Confirm — Looks Correct</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Payslip Review / Sign-off -->
<div class="modal fade" id="modal-payroll-review" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#176358;border-bottom:1px solid #eef3f2;">
                <div>
                    <h5 class="modal-title mb-0" style="color:#176358;"><i class="ri-file-list-3-line me-1" style="color:#219688;"></i>Review My Payslip</h5>
                    <div id="payroll-review-sub" style="font-size:12px;color:#8a9a95;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="payroll-review-body" style="background:#f7fbfa;">
                <div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>
            </div>
            <div class="modal-footer" id="payroll-review-footer" style="background:#fff;flex-direction:column;align-items:stretch;gap:8px;">
                <textarea id="payroll-review-comment" class="form-control" rows="2"
                    placeholder="Add a comment (required if disputing)…" style="font-size:13px;border-radius:10px;"></textarea>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn" style="background:#fdecea;color:#c62828;font-weight:700;border-radius:10px;"
                        onclick="submitPayrollReview(2)"><i class="ri-error-warning-line me-1"></i>Dispute</button>
                    <button type="button" class="btn" style="background:linear-gradient(135deg,#107c41,#0e6b37);color:#fff;font-weight:700;border-radius:10px;"
                        onclick="submitPayrollReview(1)"><i class="ri-checkbox-circle-line me-1"></i>Confirm — Looks Correct</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Payslip Preview (view before printing) -->
<div class="modal fade" id="modal-payslip-preview" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#176358;border-bottom:1px solid #eef3f2;">
                <h5 class="modal-title mb-0" style="color:#176358;"><i class="ri-file-text-line me-1" style="color:#219688;"></i>Payslip Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background:#525659;padding:0;">
                <iframe id="payslip-preview-frame" title="Payslip preview" style="width:100%;height:70vh;border:0;display:block;background:#525659;"></iframe>
            </div>
            <div class="modal-footer" style="background:#fff;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <a id="payslip-preview-download" href="#" class="btn btn-sm" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;">
                    <i class="ri-download-2-line me-1"></i>Download PDF
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Request a Leave -->
<div class="modal fade" id="modal-leave-request" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="leave-request-form" data-parsley-validate novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" style="color:#176358;font-weight:700;">
                        <i class="ri-calendar-event-line me-2"></i>Request a Leave
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Type of Leave <span style="color:red">*</span></label>
                            <select name="leave_type_id" class="form-control" data-parsley-required-message="Please select a leave type." required>
                                <option value="">Select leave type…</option>
                                <?php foreach ($leave_types_list as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Duration <span style="color:red">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="lv-dur-btn active" data-val="full" onclick="setLvDuration('full')"
                                    style="flex:1;padding:7px;border:1.5px solid #219688;background:#219688;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    Full Day
                                </button>
                                <button type="button" class="lv-dur-btn" data-val="AM" onclick="setLvDuration('AM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b0c4c0;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    AM Half
                                </button>
                                <button type="button" class="lv-dur-btn" data-val="PM" onclick="setLvDuration('PM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b0c4c0;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    PM Half
                                </button>
                            </div>
                            <input type="hidden" name="is_half_day" id="lv-is-half" value="0">
                            <input type="hidden" name="half_period" id="lv-half-period" value="">
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Leave Day(s) <span style="color:red">*</span></label>
                            <input type="text" id="lv-dates" class="form-control" placeholder="Pick one or more days…" readonly
                                data-parsley-required-message="Please select at least one leave day." required>
                            <input type="hidden" name="dates" id="lv-dates-hidden">
                            <div style="font-size:10.5px;color:#999;margin-top:3px;" id="lv-date-hint">
                                <i class="ri-information-line"></i> Holidays are disabled. <span id="lv-half-hint"></span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6" id="lv-dur" style="display:none;font-size:12px;color:#176358;font-weight:700;align-self:flex-end;">
                            <i class="ri-time-line"></i> Total: <span id="lv-dur-val">0</span> day(s)
                        </div>
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Reason / Purpose <span style="color:red">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="State the reason for your leave"
                                data-parsley-required-message="Please state your reason for leave." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;">
                        <i class="ri-send-plane-line me-1"></i>Submit Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: LWOP -->
<?php if (!empty($lwop_types_list)): ?>
<div class="modal fade" id="modal-lwop-request" tabindex="-1">
    <div class="modal-dialog">
        <form id="lwop-request-form" data-parsley-validate novalidate>
            <?php foreach ($lwop_types_list as $lt): ?>
            <input type="hidden" name="leave_type_id" value="<?= $lt['id'] ?>">
            <?php endforeach; ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" style="color:#c62828;font-weight:700;">
                        <i class="ri-close-circle-line me-2"></i>Leave Without Pay (LWOP)
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2" style="font-size:12.5px;">
                        <i class="ri-information-line me-1"></i>Approved LWOP days are <b>deducted from your salary</b>. No leave credits required.
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Leave Day(s) <span style="color:red">*</span></label>
                            <input type="text" id="lwop-dates" class="form-control" placeholder="Pick one or more days…" readonly
                                data-parsley-required-message="Please select at least one LWOP day." required>
                            <input type="hidden" name="dates" id="lwop-dates-hidden">
                        </div>
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Reason <span style="color:red">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="State the reason for LWOP"
                                data-parsley-required-message="Please state your reason for LWOP." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#c62828,#8b0000);color:#fff;font-weight:700;border:none;">
                        <i class="ri-send-plane-line me-1"></i>Submit LWOP Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Attendance Request -->
<div class="modal fade" id="modal-att-request" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="att-request-form" data-parsley-validate novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" style="color:#176358;font-weight:700;">
                        <i class="ri-timer-flash-line me-2"></i>File a Request
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Request Type <span style="color:red;">*</span></label>
                            <select name="request_type" class="form-control" id="att-req-type" onchange="toggleAttFields(this.value)"
                                data-parsley-required-message="Please select a request type." required>
                                <option value="">— Select type —</option>
                                <option value="incident">Incident Report (missed/wrong scan)</option>
                                <option value="overtime">Overtime Authorization Request</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Date <span style="color:red;">*</span></label>
                            <input type="text" id="att-req-date" class="form-control" placeholder="Select a date…" readonly
                                data-parsley-required-message="Please select a date." required>
                            <input type="hidden" name="request_date" id="att-req-date-hidden">
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Reason <span style="color:red;">*</span></label>
                            <select name="reason" class="form-control" data-parsley-required-message="Please select a reason." required>
                                <option value="">— Select reason —</option>
                                <option value="forgot_scan">Forgot to Scan</option>
                                <option value="device_error">Device / Scanner Error</option>
                                <option value="system_down">System Down</option>
                                <option value="overtime">Overtime Authorization</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <!-- Incident fields -->
                        <div class="col-6 att-incident-field" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Claimed Time In <span style="color:red;">*</span></label>
                            <input type="time" name="claimed_time_in" class="form-control" data-parsley-required-message="Please enter your claimed time in.">
                        </div>
                        <div class="col-6 att-incident-field" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Claimed Time Out <span style="color:red;">*</span></label>
                            <input type="time" name="claimed_time_out" class="form-control" data-parsley-required-message="Please enter your claimed time out.">
                        </div>

                        <!-- OT fields -->
                        <div class="col-12 col-md-6 att-ot-field" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">OT Hours Requested <span style="color:red;">*</span></label>
                            <input type="number" name="ot_hours_requested" class="form-control" min="0.5" max="12" step="0.5" placeholder="e.g. 2.5"
                                data-parsley-type="number" data-parsley-required-message="Please enter the OT hours requested.">
                        </div>

                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Notes / Explanation</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Describe what happened…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;">
                        <i class="ri-send-plane-line me-1"></i>Submit Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Pull-to-refresh (mobile, every tab) ──────────────────────────────────────
// Reloads the page and lands back on the same tab via the existing ?tab= deep-link
// mechanism (see the whitelisted $valid_portal_tabs handler near the top of this file).
(function () {
    if (!('ontouchstart' in window)) return; // touch devices only
    var THRESHOLD = 70, MAX_PULL = 110;
    var startY = null, pulling = false, refreshing = false;

    var indicator = document.createElement('div');
    indicator.id = 'ptr-indicator';
    indicator.innerHTML = '<i class="ri-refresh-line"></i>';
    document.body.appendChild(indicator);

    function blocked(target) {
        return !!(target.closest && (target.closest('.more-sheet.open') || target.closest('.modal.show') || target.closest('.emp-notif-panel.open')));
    }

    document.addEventListener('touchstart', function (e) {
        if (refreshing || window.scrollY > 0 || blocked(e.target)) { startY = null; pulling = false; return; }
        startY = e.touches[0].clientY;
        pulling = true;
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!pulling || startY === null || refreshing) return;
        var dy = e.touches[0].clientY - startY;
        if (dy <= 0 || window.scrollY > 0) { indicator.style.transform = 'translateY(-100px)'; indicator.classList.remove('ready'); return; }
        var pull = Math.min(dy * 0.5, MAX_PULL);
        indicator.style.transform = 'translateY(' + (pull - 100) + 'px)';
        indicator.classList.toggle('ready', pull >= THRESHOLD);
    }, { passive: true });

    document.addEventListener('touchend', function () {
        if (!pulling) return;
        pulling = false;
        var pulled = indicator.classList.contains('ready');
        if (pulled) {
            refreshing = true;
            indicator.classList.add('spin');
            indicator.style.transform = 'translateY(16px)';
            var activePanel = document.querySelector('.tab-panel.active');
            var tabId = activePanel ? activePanel.id.replace(/^tab-/, '') : 'overview';
            setTimeout(function () { location.href = 'employee-portal.php?tab=' + encodeURIComponent(tabId); }, 2000);
        } else {
            indicator.style.transform = 'translateY(-100px)';
            indicator.classList.remove('ready');
        }
        startY = null;
    });
})();
</script>
</body>
</html>
