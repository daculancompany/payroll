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

// ── Handle a leave request submitted from the portal (self-service) ──────
// Processed in-page because ajax.php is gated to admin sessions only.
$leave_flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_leave') {
    $lt_id_check = (int)($_POST['leave_type_id'] ?? 0);
    $is_lwop_req = false;
    if ($lt_id_check > 0) {
        $lt_check = $conn->query("SELECT is_paid FROM leave_types WHERE id = $lt_id_check LIMIT 1")->fetch_assoc();
        $is_lwop_req = $lt_check && $lt_check['is_paid'] == 0;
    }
    if (!$portal_leave_eligible && !$is_lwop_req) {
        $leave_flash = ['err', 'Only Regular and Executive employees are entitled to leave.'];
    } else {
    $lt_id      = (int)($_POST['leave_type_id'] ?? 0);
    $lreason    = trim($_POST['reason'] ?? '');
    $is_half    = intval($_POST['is_half_day'] ?? 0);
    $half_per   = in_array($_POST['half_period'] ?? '', ['AM','PM']) ? $_POST['half_period'] : null;
    $dates_raw  = trim($_POST['dates'] ?? '');

    // Build date list from multi-date picker
    $days = array_filter(array_map('trim', explode(',', $dates_raw)));
    if ($lt_id <= 0 || empty($days) || $lreason === '') {
        $leave_flash = ['err', 'Please complete all fields and select at least one date.'];
    } else {
        sort($days);
        $d_from = $days[0];
        $d_to   = end($days);
        $dur    = $is_half ? count($days) * 0.5 : (float)count($days);
        $today  = date('Y-m-d');
        $dates_json = json_encode($days);
        $ins = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, date_applied, date_from, date_to, duration, is_half_day, half_period, dates, reason, status) VALUES (?,?,?,?,?,?,?,?,?,?,0)");
        $ins->bind_param('iisssdisss', $emp_id, $lt_id, $today, $d_from, $d_to, $dur, $is_half, $half_per, $dates_json, $lreason);
        if ($ins->execute()) {
            $tname_q = $conn->query("SELECT name FROM leave_types WHERE id = $lt_id");
            $tname   = ($tname_q && $tr = $tname_q->fetch_assoc()) ? $tr['name'] : 'leave';
            $erow    = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
            $ename   = $erow['n'] ?? 'Employee';
            $durLabel = $is_half ? ($dur . ' day — ' . $half_per . ' half') : $dur . ' day/s';
            $msg   = $conn->real_escape_string("$ename requested $tname ($durLabel) via portal. Needs HR review.");
            $title = $conn->real_escape_string('New leave request');
            $hrs = $conn->query("SELECT id FROM users WHERE role = 9 AND status = 1");
            if ($hrs) while ($hu = $hrs->fetch_assoc()) {
                $uid = (int)$hu['id'];
                $conn->query("INSERT INTO notifications (user_id, title, message, icon, color, link) VALUES ($uid,'$title','$msg','ri-calendar-event-line','warning','index.php?page=leaves')");
            }
            $leave_flash = ['ok', 'Leave request submitted! HR will review it shortly.'];
        } else {
            $leave_flash = ['err', 'Could not submit your request. Please try again.'];
        }
    }
    } // end eligible
}

// ── Handle an attendance request (incident report / OT filing) ──────────
$att_flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_attendance') {
    $req_type  = trim($_POST['request_type'] ?? '');
    $req_date  = trim($_POST['request_date'] ?? '');
    $reason    = trim($_POST['reason'] ?? '');
    $time_in   = trim($_POST['claimed_time_in'] ?? '') ?: null;
    $time_out  = trim($_POST['claimed_time_out'] ?? '') ?: null;
    $ot_hours  = trim($_POST['ot_hours_requested'] ?? '') !== '' ? (float)$_POST['ot_hours_requested'] : null;
    $att_notes = trim($_POST['notes'] ?? '');

    if (!in_array($req_type, ['incident', 'overtime'], true) || !$req_date || !$reason) {
        $att_flash = ['err', 'Please complete all required fields.'];
    } elseif ($req_type === 'incident' && (!$time_in || !$time_out)) {
        $att_flash = ['err', 'Please provide your claimed time in and time out.'];
    } elseif ($req_type === 'overtime' && !$ot_hours) {
        $att_flash = ['err', 'Please provide the number of OT hours requested.'];
    } else {
        $ins = $conn->prepare("INSERT INTO attendance_requests (employee_id, request_type, request_date, reason, claimed_time_in, claimed_time_out, ot_hours_requested, notes) VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param('isssssds', $emp_id, $req_type, $req_date, $reason, $time_in, $time_out, $ot_hours, $att_notes);
        if ($ins->execute()) {
            $erow  = $conn->query("SELECT CONCAT(firstname,' ',lastname) AS n FROM employee WHERE id = $emp_id")->fetch_assoc();
            $ename = $erow['n'] ?? 'Employee';
            $label = $req_type === 'incident' ? 'attendance incident report' : 'overtime request';
            $msg   = $conn->real_escape_string("$ename filed a $label for " . date('M d, Y', strtotime($req_date)) . '.');
            $title = $conn->real_escape_string('New ' . $label);
            $reviewers = $conn->query("SELECT id FROM users WHERE role IN (1,8,9) AND status = 1");
            if ($reviewers) while ($ru = $reviewers->fetch_assoc()) {
                $uid = (int)$ru['id'];
                $conn->query("INSERT INTO notifications (user_id, title, message, icon, color, link) VALUES ($uid, '$title', '$msg', 'ri-error-warning-line', 'warning', 'index.php?page=attendance-requests')");
            }
            $att_flash = ['ok', 'Request submitted! It will be reviewed shortly.'];
        } else {
            $att_flash = ['err', 'Could not submit your request. Please try again.'];
        }
    }
}

$my_attendance_requests = [];
$marq = $conn->prepare("SELECT * FROM attendance_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 30");
$marq->bind_param('i', $emp_id);
$marq->execute();
$mar_res = $marq->get_result();
while ($r = $mar_res->fetch_assoc()) $my_attendance_requests[] = $r;

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
$s2 = $conn->prepare("
    SELECT pi.id AS item_id, pi.net, pi.basic_pay, pi.present, pi.per_day,
           pi.allowance_amount, pi.allowance_days, pi.absent, pi.late, pi.ot, pi.ot_rate,
           pi.deduction_amount, pi.other_deduction, pi.tax,
           pi.jei_advances, pi.jcc_advances, pi.sss_fund, pi.under_time,
           pi.legal_holiday, pi.sunday_duty, pi.special_holiday,
           p.ref_no, p.date_from, p.date_to, p.id AS payroll_id
    FROM payroll_items pi
    INNER JOIN payroll p ON pi.payroll_id = p.id
    WHERE pi.employee_id = ?
    ORDER BY p.date_from DESC
    LIMIT 24
");
$s2->bind_param('i', $emp_id); $s2->execute();
$payslips = $s2->get_result()->fetch_all(MYSQLI_ASSOC);
$latest   = $payslips[0] ?? null;

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
    $grs = $sub + $otv + $lgl + $sun + $spc - $lav - $utv;
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

// ── Attendance (DTR_details) ────────────────────────────────────
$s3 = $conn->prepare("
    SELECT date_time, work_hours, logs, attendance_type, overtime, notes
    FROM DTR_details
    WHERE employee_id = ?
    ORDER BY date_time DESC
    LIMIT 60
");
$s3->bind_param('i', $emp_id); $s3->execute();
$attendance = $s3->get_result()->fetch_all(MYSQLI_ASSOC);

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
    $gross    = $sub_tot + $ot_amt + $lgl_amt + $sun_amt + $spc_amt - $late_amt - $ut_amt;
    $tot_ded  = $latest['deduction_amount'] + $latest['other_deduction']
              + $latest['tax'] + $latest['jei_advances'] + $latest['jcc_advances'] + $latest['sss_fund'];
}

function n2($v) { return number_format((float)$v, 2); }
function n0($v) { return number_format((float)$v, 0); }
$initials = strtoupper(substr($emp['firstname'],0,1).substr($emp['lastname'],0,1));
$full_name = strtoupper($emp['lastname'].', '.$emp['firstname']);
$hr = (int)date('H');
$greeting = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Portal — <?= htmlspecialchars($emp['firstname']) ?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#33312c;
    /* warm paper backdrop with a faint fibre texture */
    background-color:#ece7da;
    background-image:
        radial-gradient(circle at 25% 15%, rgba(255,255,255,.55) 0, transparent 45%),
        radial-gradient(circle at 80% 80%, rgba(255,255,255,.35) 0, transparent 40%),
        repeating-linear-gradient(0deg, rgba(120,110,90,.025) 0 1px, transparent 1px 4px),
        repeating-linear-gradient(90deg, rgba(120,110,90,.025) 0 1px, transparent 1px 4px);
    background-attachment:fixed;
}
/* Paper sheet helper — warm white with a hairline edge + layered shadow */
.paper{
    background:#fffdf8;
    border:1px solid #e7e0d0;
    box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);
}

/* Top bar */
.ptop{background:linear-gradient(135deg,#176358,#219688);padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:54px;position:sticky;top:0;z-index:200;box-shadow:0 2px 12px rgba(0,0,0,.18);}
.ptop-brand{color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;}
.ptop-logo{width:30px;height:30px;border-radius:7px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#fff;}
.ptop-logout{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:4px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;}
.ptop-logout:hover{background:rgba(255,255,255,.28);color:#fff;}

/* Layout */
.portal-wrap{max-width:900px;margin:0 auto;padding:22px 14px 50px;}

/* Employee header card */
.emp-hdr{background:#fffdf8;border:1px solid #e7e0d0;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 10px 26px -14px rgba(60,50,30,.22);margin-bottom:18px;}
.emp-hdr-top{background:linear-gradient(135deg,#219688,#176358);padding:20px 22px;display:flex;align-items:center;gap:16px;}
.emp-av{width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,.4);}
.emp-nm{font-size:17px;font-weight:900;color:#fff;line-height:1.2;}
.emp-sub{font-size:11px;color:rgba(255,255,255,.78);margin-top:3px;}
.emp-no-badge{margin-left:auto;background:rgba(0,0,0,.18);color:#fff;border-radius:8px;padding:5px 13px;font-size:11px;font-family:monospace;font-weight:800;white-space:nowrap;}
.emp-stats{display:grid;grid-template-columns:repeat(5,1fr);}
.est{padding:12px 14px;border-right:1px solid #f0ece0;text-align:center;}
.est:last-child{border-right:none;}
.est-v{font-size:16px;font-weight:800;color:#219688;line-height:1;}
.est-l{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;}

/* Tabs */
.tab-strip{display:flex;gap:4px;background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;padding:5px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 6px 18px -12px rgba(60,50,30,.18);margin-bottom:16px;flex-wrap:wrap;}
.tab-btn{flex:1;padding:9px 6px;border:none;background:transparent;border-radius:8px;font-size:12px;font-weight:700;color:#888;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;transition:all .18s;}
.tab-btn.active{background:linear-gradient(135deg,#219688,#176358);color:#fff;box-shadow:0 2px 8px rgba(33,150,136,.3);}
.tab-btn .badge-count{background:rgba(255,255,255,.25);color:#fff;border-radius:10px;padding:0 6px;font-size:10px;font-weight:800;}
.tab-btn:not(.active) .badge-count{background:#e8f7f5;color:#219688;}
.tab-panel{display:none;} .tab-panel.active{display:block;}

/* Section title */
.sec{font-size:11px;font-weight:800;color:#219688;text-transform:uppercase;letter-spacing:.7px;margin:18px 0 10px;display:flex;align-items:center;gap:8px;}
.sec::after{content:'';flex:1;height:1px;background:#ddecea;}

/* Latest payslip */
.ps-card{background:#fffdf8;border:1px solid #e7e0d0;border-radius:14px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);overflow:hidden;margin-bottom:14px;}
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

/* Date-range picker trigger */
.att-range-picker{display:flex;align-items:center;gap:6px;min-width:210px;padding:5px 11px;border:1px solid #cfe3e0;border-radius:8px;background:#f8fdfc;font-size:12px;font-weight:600;color:#176358;cursor:pointer;transition:border-color .15s,box-shadow .15s;}
.att-range-picker:hover{border-color:#219688;}
.att-range-picker i:first-child{color:#219688;}
/* daterangepicker theme override → brand teal */
.daterangepicker td.active,.daterangepicker td.active:hover{background-color:#219688 !important;}
.daterangepicker td.in-range{background-color:#e6f5f3 !important;color:#176358 !important;}
.daterangepicker .ranges li.active{background-color:#219688 !important;}
.daterangepicker .drp-buttons .btn.applyBtn{background-color:#219688 !important;border-color:#176358 !important;}
.daterangepicker td.start-date,.daterangepicker td.end-date{background-color:#176358 !important;}

/* Loan cards */
.loan-c{background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);padding:16px 18px;margin-bottom:10px;}
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

/* Year-to-date strip */
.ytd-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.ytd-box{background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);padding:14px 16px;border-top:3px solid #219688;}
.ytd-box.g{border-top-color:#219688;}
.ytd-box.d{border-top-color:#dc3545;}
.ytd-box.n{border-top-color:#176358;}
.ytd-box.c{border-top-color:#6f42c1;}
.ytd-val{font-size:18px;font-weight:900;line-height:1;color:#176358;}
.ytd-box.d .ytd-val{color:#dc3545;}
.ytd-box.c .ytd-val{color:#6f42c1;}
.ytd-lbl{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:5px;}

/* Net-pay trend mini chart */
.trend-card{background:#fffdf8;border:1px solid #e7e0d0;border-radius:14px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);padding:16px 18px 12px;margin-bottom:14px;}
.trend-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.trend-title{font-size:12px;font-weight:800;color:#176358;}
.trend-bars{display:flex;align-items:flex-end;gap:8px;height:120px;}
.trend-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:5px;}
.trend-amt{font-size:9px;font-weight:700;color:#219688;white-space:nowrap;}
.trend-bar{width:100%;max-width:30px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#27b09f,#176358);min-height:4px;transition:opacity .15s;}
.trend-col:last-child .trend-bar{background:linear-gradient(180deg,#f7b84b,#e8920a);}
.trend-col:hover .trend-bar{opacity:.82;}
.trend-lbl{font-size:9px;color:#aaa;text-align:center;line-height:1.2;}

@media(max-width:600px){
    .ytd-strip{grid-template-columns:repeat(2,1fr);}
}

/* Basic info grid */
.info-section{background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -12px rgba(60,50,30,.18);overflow:hidden;margin-bottom:14px;}
.info-sec-title{background:#219688;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:8px 16px;display:flex;align-items:center;gap:7px;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;}
.info-item{padding:10px 16px;border-bottom:1px solid #f0f5f4;border-right:1px solid #f0f5f4;}
.info-item:last-child{border-right:none;}
.info-lbl{font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.info-val{font-size:13px;font-weight:600;color:#222;}
.info-val.mono{font-family:monospace;font-size:12px;}
.info-val.teal{color:#219688;}

/* Empty state */
.empty-state{text-align:center;padding:40px 20px;color:#bbb;}
.empty-state i{font-size:38px;display:block;margin-bottom:10px;}
.empty-state p{font-size:12px;margin:0;}

/* bootstrap-select — make the dropdown + search box visible on the paper theme */
.bootstrap-select .dropdown-toggle{background:#fff !important;border:1px solid #cfe3e0 !important;color:#33312c !important;font-size:13px;border-radius:8px;padding:7px 11px;box-shadow:none !important;}
.bootstrap-select .dropdown-toggle:focus{outline:none !important;border-color:#219688 !important;box-shadow:0 0 0 2px rgba(33,150,136,.15) !important;}
.bootstrap-select .dropdown-menu{font-size:13px;border:1px solid #e7e0d0;box-shadow:0 8px 22px -10px rgba(60,50,30,.3);}
.bootstrap-select .dropdown-menu li a{color:#33312c;}
.bootstrap-select .dropdown-menu li.selected a,.bootstrap-select .dropdown-menu li a:hover{background:#e6f5f3 !important;color:#176358 !important;}
.bootstrap-select .bs-searchbox{padding:8px;}
.bootstrap-select .bs-searchbox .form-control{
    background:#fff !important;color:#33312c !important;
    border:1px solid #cfe3e0 !important;border-radius:6px;font-size:13px;padding:6px 10px;
    -webkit-text-fill-color:#33312c;
}
.bootstrap-select .bs-searchbox .form-control::placeholder{color:#9aa3a0 !important;-webkit-text-fill-color:#9aa3a0;}
.bootstrap-select .bs-searchbox .form-control:focus{border-color:#219688 !important;box-shadow:0 0 0 2px rgba(33,150,136,.15) !important;}

/* ── Help tab ── */
.help-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#219688,#176358);border-radius:16px;padding:20px 22px;color:#fff;margin-bottom:6px;box-shadow:0 10px 26px -14px rgba(23,99,88,.6);}
.help-hero-ic{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
.help-hero-t{font-size:17px;font-weight:900;line-height:1.2;}
.help-hero-s{font-size:11.5px;color:rgba(255,255,255,.82);margin-top:4px;}
.help-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:6px;}
.help-card{background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;padding:14px 15px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -14px rgba(60,50,30,.18);transition:transform .15s,box-shadow .15s;}
.help-card:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(60,50,30,.05), 0 14px 28px -14px rgba(60,50,30,.28);}
.help-card-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:9px;}
.help-card-t{font-size:13px;font-weight:800;color:#33312c;margin-bottom:3px;}
.help-card-d{font-size:11.5px;color:#8a857a;line-height:1.45;}
/* glossary rows */
.gloss{display:flex;gap:12px;padding:8px 16px;border-bottom:1px dashed #ece4d2;}
.gloss:last-child{border-bottom:none;}
.gloss-t{font-size:12px;font-weight:800;color:#176358;min-width:150px;flex-shrink:0;}
.gloss-d{font-size:12px;color:#6f6a60;line-height:1.4;}
/* FAQ accordion */
.faq{margin-bottom:6px;}
.faq-item{background:#fffdf8;border:1px solid #e7e0d0;border-radius:12px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 2px rgba(60,50,30,.04);}
.faq-q{width:100%;border:none;background:transparent;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;font-size:12.5px;font-weight:700;color:#33312c;cursor:pointer;text-align:left;}
.faq-q i{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:15px;transition:transform .2s;}
.faq-item.open .faq-q i{transform:rotate(45deg);background:#219688;color:#fff;}
.faq-a{max-height:0;overflow:hidden;transition:max-height .25s ease;}
.faq-item.open .faq-a{max-height:240px;}
.faq-a p{margin:0;padding:0 16px 14px;font-size:12px;color:#6f6a60;line-height:1.55;}
.faq-a code{background:#f0ece0;color:#176358;padding:1px 6px;border-radius:4px;font-size:11.5px;font-weight:700;}
/* contact card */
.contact-card{display:flex;align-items:center;gap:14px;background:#fffdf8;border:1px solid #e7e0d0;border-left:4px solid #219688;border-radius:12px;padding:16px 18px;box-shadow:0 1px 2px rgba(60,50,30,.05), 0 8px 22px -14px rgba(60,50,30,.18);flex-wrap:wrap;}
.contact-ic{width:46px;height:46px;border-radius:50%;background:#e8f7f5;color:#219688;display:flex;align-items:center;justify-content:center;font-size:23px;flex-shrink:0;}
.contact-t{font-size:13.5px;font-weight:800;color:#176358;}
.contact-d{font-size:11.5px;color:#8a857a;margin-top:2px;}
.contact-meta{font-size:11px;color:#6f6a60;font-weight:600;text-align:right;}
.contact-meta i{color:#219688;}

/* Footer */
.portal-foot{text-align:center;font-size:11px;color:#a59f90;margin-top:30px;}

@media(max-width:600px){
    .emp-stats{grid-template-columns:repeat(3,1fr);}
    .est:nth-child(n+4){border-top:1px solid #f0ece0;}
    .ps-body{grid-template-columns:1fr;}
    .ps-col:first-child{border-right:none;border-bottom:1px solid #f0f5f4;}
    /* Mobile tabs: bigger icon on top + tiny label below */
    .tab-strip{gap:2px;padding:4px;}
    .tab-btn{flex-direction:column;gap:3px;padding:7px 2px;font-size:9px;position:relative;min-width:0;}
    .tab-btn i{font-size:19px;line-height:1;display:block;}
    .tab-btn span.tab-label{display:block;font-size:8.5px;font-weight:700;line-height:1;}
    .tab-btn .badge-count{position:absolute;top:3px;right:6px;font-size:8px;padding:0 4px;}
    .help-grid{grid-template-columns:1fr;}
    .gloss-t{min-width:115px;}
}

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
</style>
</head>
<body>

<div class="ptop">
    <div class="ptop-brand">
        <div class="ptop-logo">JP</div>
        Jejors Employee Portal
    </div>
    <a href="?logout=1" class="ptop-logout"><i class="ri-logout-box-line me-1"></i>Logout</a>
</div>

<div class="portal-wrap">

    <!-- Employee header -->
    <div class="emp-hdr">
        <div class="emp-hdr-top">
            <div class="emp-av"><?= $initials ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:10px;color:rgba(255,255,255,.7);font-weight:600;text-transform:uppercase;letter-spacing:.5px;"><?= $greeting ?>, <?= htmlspecialchars(ucfirst(strtolower($emp['firstname']))) ?>! &middot; <?= date('D, M d, Y') ?></div>
                <div class="emp-nm"><?= htmlspecialchars($full_name) ?></div>
                <div class="emp-sub"><?= htmlspecialchars($emp['pos_name']) ?> &bull; <?= htmlspecialchars($emp['dept_name']) ?></div>
            </div>
            <div class="emp-no-badge"><?= htmlspecialchars($emp['employee_no']) ?></div>
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

    <!-- Tabs -->
    <div class="tab-strip">
        <button class="tab-btn active" onclick="switchTab('overview',this)">
            <i class="ri-dashboard-line"></i><span class="tab-label">Overview</span>
        </button>
        <button class="tab-btn" onclick="switchTab('payslips',this)">
            <i class="ri-file-list-3-line"></i><span class="tab-label">Payslips</span>
            <span class="badge-count"><?= count($payslips) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('attendance',this)">
            <i class="ri-calendar-check-line"></i><span class="tab-label">Attendance</span>
            <span class="badge-count"><?= count($attendance) ?></span>
        </button>
        <?php /* Requests tab hidden — managed via admin portal */ ?>
        <button class="tab-btn" onclick="switchTab('compare',this)">
            <i class="ri-arrow-left-right-line"></i><span class="tab-label">Compare</span>
        </button>
        <button class="tab-btn" onclick="switchTab('loans',this)">
            <i class="ri-bank-line"></i><span class="tab-label">Loans</span>
            <?php if (count($loans)): ?><span class="badge-count"><?= count($loans) ?></span><?php endif; ?>
        </button>
        <button class="tab-btn" onclick="switchTab('leave',this)">
            <i class="ri-calendar-event-line"></i><span class="tab-label">Leave</span>
            <?php if ($leave_pending_count): ?><span class="badge-count"><?= $leave_pending_count ?></span><?php endif; ?>
        </button>
        <button class="tab-btn" onclick="switchTab('info',this)">
            <i class="ri-profile-line"></i><span class="tab-label">My Info</span>
        </button>
        <button class="tab-btn" onclick="switchTab('help',this)">
            <i class="ri-question-line"></i><span class="tab-label">Help</span>
        </button>
    </div>

    <!-- ── Tab: Overview ── -->
    <div class="tab-panel active" id="tab-overview">

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

        <!-- Charts -->
        <?php if (count($chart['labels']) > 1): ?>
        <div class="sec"><i class="ri-bar-chart-2-line"></i>My Payroll Charts</div>
        <div class="trend-card">
            <div class="trend-head">
                <span class="trend-title"><i class="ri-line-chart-line me-1"></i>Net Pay vs Gross Pay</span>
                <span style="font-size:10px;color:#aaa;">Last <?= count($chart['labels']) ?> periods</span>
            </div>
            <div id="chart-pay"></div>
        </div>
        <div class="row g-3" style="margin:0 0 14px;">
            <div class="col-12 col-md-6" style="padding-left:0;">
                <div class="trend-card" style="margin-bottom:0;height:100%;">
                    <div class="trend-head"><span class="trend-title"><i class="ri-alarm-warning-line me-1"></i>Late (mins) &amp; OT (hrs)</span></div>
                    <div id="chart-lateot"></div>
                </div>
            </div>
            <div class="col-12 col-md-6" style="padding-right:0;">
                <div class="trend-card" style="margin-bottom:0;height:100%;">
                    <div class="trend-head"><span class="trend-title"><i class="ri-calendar-check-line me-1"></i>Days Present vs Absent</span></div>
                    <div id="chart-attend"></div>
                </div>
            </div>
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
                    <?php if ($ut_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Undertime (<?= number_format($latest['under_time']) ?> min)</span><span class="ps-val ded">−₱<?= n2($ut_amt) ?></span></div>
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
        <div class="empty-state"><i class="ri-file-text-line"></i><p>No payslip records yet.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Payslips ── -->
    <div class="tab-panel" id="tab-payslips">
        <div class="sec"><i class="ri-file-list-3-line"></i>All Payslips</div>
        <?php if (count($payslips)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f0f5f4;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12px;color:#888;"><?= count($payslips) ?> payroll period<?= count($payslips)>1?'s':'' ?></span>
                <input type="text" id="ps-search" class="form-control form-control-sm" placeholder="Search period…" style="max-width:160px;">
            </div>
            <div class="table-responsive">
            <table class="ps-hist-table" id="ps-hist">
                <thead>
                    <tr>
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
                foreach ($payslips as $ps):
                    $pm2    = $ps['per_day'] / 480;
                    $at2    = $ps['allowance_amount'] * $ps['allowance_days'];
                    $ot2    = $ps['ot'] * $ps['ot_rate'];
                    $la2    = $ps['late'] * $pm2;
                    $ab2    = $ps['absent'] * $ps['per_day'];
                    $lgl2   = $ps['legal_holiday'] * $ps['per_day'];
                    $sun2   = $ps['sunday_duty']   * $ps['per_day'];
                    $spc2   = ($ps['per_day']/8*2.4) * $ps['special_holiday'];
                    $sub2   = ($ps['basic_pay'] + $at2 - $ab2) / 2;
                    $gr2    = $sub2 + $ot2 + $lgl2 + $sun2 + $spc2 - $la2;
                    $ded2   = $ps['deduction_amount'] + $ps['other_deduction'] + $ps['tax'] + $ps['jei_advances'] + $ps['jcc_advances'] + $ps['sss_fund'];
                    $t_net+=$ps['net']; $t_gross+=$gr2; $t_ded+=$ded2;
                ?>
                <tr onclick="window.open('view_payslip.php?id=<?= $ps['item_id'] ?>','_blank','width=900,height=700,scrollbars=yes')" title="Click to view payslip">
                    <td>
                        <div style="font-weight:700;font-size:12px;"><?= date('M d', strtotime($ps['date_from'])) ?> – <?= date('M d, Y', strtotime($ps['date_to'])) ?></div>
                        <div style="font-size:10px;color:#aaa;"><?= date('Y', strtotime($ps['date_from'])) ?></div>
                    </td>
                    <td><span style="font-family:monospace;font-size:11px;font-weight:700;color:#219688;"><?= htmlspecialchars($ps['ref_no']) ?></span></td>
                    <td class="r"><span class="present-pill"><?= $ps['present'] ?>d</span></td>
                    <td class="r"><?= $ps['absent'] > 0 ? '<span class="absent-pill">'.$ps['absent'].'d</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r"><?= $ps['late'] > 0 ? '<span class="late-pill">'.number_format($ps['late']).'m</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r"><?= $ps['ot'] > 0 ? '<span style="color:#fd7e14;font-weight:700;">'.$ps['ot'].'h</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td class="r" style="font-weight:700;color:#219688;">₱<?= n2($gr2) ?></td>
                    <td class="r" style="color:#dc3545;">₱<?= n2($ded2) ?></td>
                    <td class="r"><span class="net-badge">₱<?= n2($ps['net']) ?></span></td>
                    <td class="r"><i class="ri-eye-line" style="color:#ccc;font-size:14px;"></i></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6">TOTAL (<?= count($payslips) ?> periods)</td>
                        <td class="r">₱<?= n2($t_gross) ?></td>
                        <td class="r" style="color:#dc3545;">₱<?= n2($t_ded) ?></td>
                        <td class="r" style="color:#219688;font-size:14px;">₱<?= n2($t_net) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="ri-file-list-3-line"></i><p>No payslip records found.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Attendance ── -->
    <div class="tab-panel" id="tab-attendance">
        <div class="sec"><i class="ri-calendar-check-line"></i>Attendance Records</div>
        <?php if (count($attendance)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div style="padding:10px 14px;border-bottom:1px solid #f0f5f4;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12px;color:#888;"><span id="att-count"><?= count($attendance) ?></span> records</span>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div id="att-range" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="att-range-label">All dates</span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <button onclick="clearAttFilter()" class="btn btn-sm" style="background:#f0f5f4;color:#888;padding:5px 10px;font-size:11px;border:none;border-radius:7px;">Clear</button>
                </div>
            </div>
            <div class="table-responsive">
            <table class="att-table" id="att-tbl">
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
                <tbody>
                <?php foreach ($attendance as $att):
                    $dt       = $att['date_time'];
                    $wh       = (float)$att['work_hours'];
                    $ot_h     = (float)$att['overtime'];
                    $atype    = strtoupper(substr($att['attendance_type'] ?? 'P', 0, 1));
                    $atype_lbl= $att['attendance_type'] ?? 'Present';
                    $pct_wh   = min(100, ($wh / 8) * 100);
                    $att_cls  = in_array($atype,['P','A','H','S','O']) ? 'att-'.$atype : 'att-P';

                    // Parse logs the same way dtr-details.php does: array of {dateTime, type} objects
                    $logs_obj = $att['logs'] ? json_decode($att['logs']) : [];
                    if (!is_array($logs_obj)) $logs_obj = [];
                    $timeIn  = '';
                    $timeOut = '';
                    if (!empty($logs_obj)) {
                        $timeIn  = date('g:i A', strtotime($logs_obj[0]->dateTime ?? ''));
                        $timeOut = count($logs_obj) > 1 ? date('g:i A', strtotime(end($logs_obj)->dateTime ?? '')) : '';
                    }
                    // Build popover HTML for all log entries
                    $popLines = '';
                    foreach ($logs_obj as $li => $lg) {
                        $isBio  = isset($lg->type) && $lg->type === 'bio';
                        $chip   = $isBio ? 'bio' : 'manual';
                        $icon   = $isBio ? 'ri-fingerprint-line' : 'ri-edit-line';
                        $lbl    = ($li === 0) ? 'IN' : (($li === count($logs_obj)-1) ? 'OUT' : '#'.($li+1));
                        $ltime  = date('g:i A', strtotime($lg->dateTime ?? ''));
                        $popLines .= '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;">'
                            .'<span style="font-size:10px;font-weight:700;color:#888;min-width:26px;">'.$lbl.'</span>'
                            .'<span class="dtr-log-chip '.$chip.'"><i class="'.$icon.'"></i> '.$ltime.'</span>'
                            .'</div>';
                    }
                    if (!$popLines) $popLines = '<span style="color:#aaa;font-size:11px;">No logs</span>';
                    $popContent = htmlspecialchars('<div style="min-width:150px;">'.$popLines.'</div>');
                    $totalLogs  = count($logs_obj);
                ?>
                <tr data-date="<?= date('Y-m-d', strtotime($dt)) ?>">
                    <td>
                        <div style="font-weight:700;"><?= date('M d, Y', strtotime($dt)) ?></div>
                        <div style="font-size:10px;color:#aaa;"><?= date('l', strtotime($dt)) ?></div>
                    </td>
                    <td><span class="att-type <?= $att_cls ?>"><?= htmlspecialchars($atype_lbl) ?></span></td>
                    <td>
                        <div style="font-weight:700;"><?= $wh > 0 ? $wh.'h' : '—' ?></div>
                        <?php if ($wh > 0): ?>
                        <div class="hrs-bar"><div class="hrs-fill" style="width:<?= $pct_wh ?>%;"></div></div>
                        <?php endif; ?>
                    </td>
                    <td><?= $ot_h > 0 ? '<span style="color:#fd7e14;font-weight:700;">'.$ot_h.'h</span>' : '<span style="color:#ccc;">—</span>' ?></td>
                    <td>
                        <?php if ($totalLogs > 0): ?>
                        <div class="time-io">
                            <span class="dtr-time-chip in"><?= $timeIn ?: '—' ?></span>
                            <?php if ($timeOut): ?>
                            <span style="color:#ccc;font-size:10px;">→</span>
                            <span class="dtr-time-chip out"><?= $timeOut ?></span>
                            <?php else: ?>
                            <span class="dtr-time-chip na">No Out</span>
                            <?php endif; ?>
                        </div>
                        <span class="dtr-logs-pill mt-1"
                            data-bs-toggle="popover"
                            data-bs-trigger="click"
                            data-bs-placement="left"
                            data-bs-html="true"
                            data-bs-content="<?= $popContent ?>"
                            title="All Logs">
                            <span class="dtr-logs-count"><i class="ri-list-check"></i> <?= $totalLogs ?> log<?= $totalLogs>1?'s':'' ?> — view details</span>
                        </span>
                        <?php else: ?>
                        <span class="dtr-time-chip na">No logs</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:#888;"><?= htmlspecialchars($att['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="ri-calendar-line"></i><p>No attendance records found.</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Attendance Requests ── -->
    <div class="tab-panel" id="tab-att-requests">
        <?php if ($att_flash): ?>
        <div style="border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:8px;
            <?= $att_flash[0]==='ok' ? 'background:#e8f7f5;color:#176358;border:1px solid #aad5d0;' : 'background:#fff0f0;color:#c62828;border:1px solid #f5b5b5;' ?>">
            <i class="<?= $att_flash[0]==='ok' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' ?>" style="font-size:18px;"></i>
            <?= htmlspecialchars($att_flash[1]) ?>
        </div>
        <?php endif; ?>

        <!-- File Request Form -->
        <div class="sec"><i class="ri-add-circle-line"></i>File a Request</div>
        <div class="paper" style="border-radius:14px;padding:18px;margin-bottom:18px;">
            <form method="post" action="employee-portal.php" id="att-request-form">
                <input type="hidden" name="action" value="request_attendance">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Request Type <span style="color:red;">*</span></label>
                        <select name="request_type" class="form-control" id="att-req-type" onchange="toggleAttFields(this.value)" required>
                            <option value="">— Select type —</option>
                            <option value="incident">Incident Report (missed/wrong scan)</option>
                            <option value="overtime">Overtime Authorization Request</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Date <span style="color:red;">*</span></label>
                        <input type="date" name="request_date" class="form-control" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Reason <span style="color:red;">*</span></label>
                        <select name="reason" class="form-control" required>
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
                        <input type="time" name="claimed_time_in" class="form-control">
                    </div>
                    <div class="col-6 att-incident-field" style="display:none;">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Claimed Time Out <span style="color:red;">*</span></label>
                        <input type="time" name="claimed_time_out" class="form-control">
                    </div>

                    <!-- OT fields -->
                    <div class="col-12 col-md-6 att-ot-field" style="display:none;">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">OT Hours Requested <span style="color:red;">*</span></label>
                        <input type="number" name="ot_hours_requested" class="form-control" min="0.5" max="12" step="0.5" placeholder="e.g. 2.5">
                    </div>

                    <div class="col-12">
                        <label style="font-size:11px;font-weight:700;color:#176358;text-transform:uppercase;letter-spacing:.4px;">Notes / Explanation</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Describe what happened…"></textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn" style="background:linear-gradient(135deg,#219688,#176358);color:#fff;font-weight:700;border:none;padding:8px 22px;border-radius:8px;">
                            <i class="ri-send-plane-line me-1"></i>Submit Request
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- My Request History -->
        <div class="sec"><i class="ri-history-line"></i>My Requests</div>
        <?php if (count($my_attendance_requests)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;">
            <div class="table-responsive">
            <table class="att-table">
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
                <tbody>
                <?php
                $reasonLabels = ['forgot_scan'=>'Forgot to Scan','device_error'=>'Device Error','system_down'=>'System Down','overtime'=>'Overtime','other'=>'Other'];
                foreach ($my_attendance_requests as $ar):
                    $statusMap = [0=>['Pending','#e6a817'],1=>['Approved','#219688'],2=>['Rejected','#c62828']];
                    [$slabel,$scolor] = $statusMap[$ar['status']] ?? ['Unknown','#aaa'];
                ?>
                <tr>
                    <td style="font-size:11px;"><?= date('M d, Y', strtotime($ar['created_at'])) ?></td>
                    <td>
                        <?php if ($ar['request_type']==='incident'): ?>
                            <span style="background:#fff3cd;color:#856404;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="ri-error-warning-line me-1"></i>Incident</span>
                        <?php else: ?>
                            <span style="background:#cff4fc;color:#055160;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="ri-timer-flash-line me-1"></i>OT Request</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;"><?= date('M d, Y', strtotime($ar['request_date'])) ?></td>
                    <td style="font-size:11px;"><?= htmlspecialchars($reasonLabels[$ar['reason']] ?? $ar['reason']) ?></td>
                    <td style="font-size:11px;">
                        <?php if ($ar['claimed_time_in']): ?>
                            <?= date('h:i A', strtotime($ar['claimed_time_in'])) ?> – <?= date('h:i A', strtotime($ar['claimed_time_out'])) ?>
                        <?php elseif ($ar['ot_hours_requested']): ?>
                            <?= $ar['ot_hours_requested'] ?> hrs OT
                        <?php endif; ?>
                        <?php if ($ar['notes']): ?><div style="color:#aaa;font-size:10px;"><?= htmlspecialchars(mb_strimwidth($ar['notes'],0,40,'…')) ?></div><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span style="background:<?= $scolor ?>;color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;"><?= $slabel ?></span>
                    </td>
                    <td style="font-size:11px;color:#888;"><?= htmlspecialchars($ar['reviewer_remarks'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="ri-file-list-3-line"></i><p>No requests filed yet.</p></div>
        <?php endif; ?>
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
        <div class="empty-state"><i class="ri-arrow-left-right-line"></i><p>You need at least two payslips to compare.</p></div>
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
        <div class="empty-state"><i class="ri-check-double-line" style="color:#219688;"></i><p style="color:#219688;font-weight:700;">No active loans. You're debt-free!</p></div>
        <?php endif; ?>
    </div>

    <!-- ── Tab: Leave ── -->
    <div class="tab-panel" id="tab-leave">

        <?php if ($leave_flash): ?>
        <div style="border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:12.5px;font-weight:700;display:flex;align-items:center;gap:8px;
            <?= $leave_flash[0] === 'ok' ? 'background:#e8f7f5;color:#176358;border:1px solid #aad5d0;' : 'background:#fff0f0;color:#c62828;border:1px solid #f5b5b5;' ?>">
            <i class="<?= $leave_flash[0] === 'ok' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' ?>" style="font-size:18px;"></i>
            <?= htmlspecialchars($leave_flash[1]) ?>
        </div>
        <?php endif; ?>

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
                    <td style="white-space:nowrap;"><span style="border-left:4px solid <?= htmlspecialchars($ev['color']) ?>;padding-left:8px;"><?= $range ?></span></td>
                    <td><b><?= $isHol ? '🛑' : '📌' ?> <?= htmlspecialchars($ev['title']) ?></b><?php if ($ev['note']): ?><div style="font-size:11px;color:#999;"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?></td>
                    <td><?php if ($isHol): ?><span style="background:#fff0f0;color:#c62828;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;">Holiday</span><?php else: ?><span style="background:#e8f0ff;color:#0d6efd;border-radius:10px;padding:2px 9px;font-size:11px;font-weight:700;">Activity</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:20px;"><i class="ri-calendar-2-line"></i><p>No upcoming holidays or activities.</p></div>
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
        <div class="empty-state" style="padding:20px;"><i class="ri-coins-line"></i><p>No leave types configured yet.</p></div>
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
                    <td><?= date('M d, Y', strtotime($ml['date_applied'])) ?></td>
                    <td><span style="font-weight:700;color:#176358;"><?= htmlspecialchars($ml['leave_type_name']) ?></span></td>
                    <td style="font-size:11px;"><?= date('M d', strtotime($ml['date_from'])) ?> – <?= date('M d, Y', strtotime($ml['date_to'])) ?></td>
                    <td class="r"><b><?= rtrim(rtrim(number_format($ml['duration'], 1), '0'), '.') ?></b></td>
                    <td><?= $stageChip($ml['hr_status']) ?></td>
                    <td><?= $stageChip($ml['admin_status']) ?></td>
                    <td>
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
        <div class="empty-state"><i class="ri-calendar-event-line"></i><p>You haven't filed any leave requests yet.</p></div>
        <?php endif; ?>
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

    <div class="portal-foot">JEJORS CONSTRUCTION CORPORATION &bull; Employee Self-Service Portal<br>For concerns contact your HR / Payroll department.</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
<script src="assets/libs/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
function toggleAttFields(type) {
    document.querySelectorAll('.att-incident-field').forEach(function(el){ el.style.display = type === 'incident' ? '' : 'none'; });
    document.querySelectorAll('.att-ot-field').forEach(function(el){ el.style.display = type === 'overtime' ? '' : 'none'; });
}

function switchTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-'+id).classList.add('active');
    if (btn) btn.classList.add('active');
    else {
        var b = document.querySelector('.tab-btn[onclick*="\'' + id + '\'"]');
        if (b) b.classList.add('active');
    }
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

document.addEventListener('DOMContentLoaded', function () {
    var lf = document.getElementById('leave-request-form');
    if (lf) lf.addEventListener('submit', function (e) {
        if (!document.getElementById('lv-dates-hidden').value) {
            e.preventDefault(); alert('Please select at least one leave day.');
        }
    });
    var lwf = document.getElementById('lwop-request-form');
    if (lwf) lwf.addEventListener('submit', function (e) {
        if (!document.getElementById('lwop-dates-hidden').value) {
            e.preventDefault(); alert('Please select at least one LWOP day.');
        }
    });
});

<?php if ($leave_flash || $att_flash || (isset($_GET['tab']) && $_GET['tab'] === 'leave')): ?>
document.addEventListener('DOMContentLoaded', function () { switchTab('leave', null); window.scrollTo(0, 0); });
<?php endif; ?>

// ── Payroll charts (ApexCharts) ──────────────────────────────
var CHART = <?= json_encode($chart) ?>;
var CMP   = <?= json_encode($cmp_data) ?>;
function peso(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

document.addEventListener('DOMContentLoaded', function () {
    if (typeof ApexCharts === 'undefined' || !CHART.labels || CHART.labels.length < 2) return;
    var base = { chart:{ fontFamily:'Segoe UI,Arial,sans-serif', toolbar:{show:false}, parentHeightOffset:0 },
                 grid:{ borderColor:'#eee', strokeDashArray:4 },
                 xaxis:{ categories:CHART.labels, labels:{ style:{ fontSize:'10px', colors:'#999' } } },
                 dataLabels:{ enabled:false }, legend:{ fontSize:'11px', markers:{ width:9, height:9 } } };

    // Net vs Gross — area
    new ApexCharts(document.querySelector('#chart-pay'), Object.assign({}, base, {
        chart: Object.assign({ type:'area', height:230 }, base.chart),
        colors:['#219688','#b9c4d6'],
        stroke:{ curve:'smooth', width:[3,2] },
        fill:{ type:'gradient', gradient:{ opacityFrom:0.4, opacityTo:0.05 } },
        series:[ { name:'Net Pay', data:CHART.net }, { name:'Gross Pay', data:CHART.gross } ],
        yaxis:{ labels:{ formatter:function(v){ return '₱'+Math.round(v/1000)+'k'; }, style:{ fontSize:'10px', colors:'#999' } } },
        tooltip:{ y:{ formatter:peso } }
    })).render();

    // Late (mins) column + OT (hrs) line — dual axis
    new ApexCharts(document.querySelector('#chart-lateot'), Object.assign({}, base, {
        chart: Object.assign({ type:'line', height:210 }, base.chart),
        colors:['#dc3545','#fd7e14'],
        stroke:{ width:[0,3], curve:'smooth' },
        plotOptions:{ bar:{ columnWidth:'45%', borderRadius:3 } },
        series:[ { name:'Late (min)', type:'column', data:CHART.late }, { name:'OT (hrs)', type:'line', data:CHART.ot } ],
        yaxis:[ { labels:{ style:{ fontSize:'10px', colors:'#999' } } },
                { opposite:true, labels:{ style:{ fontSize:'10px', colors:'#999' } } } ]
    })).render();

    // Present vs Absent — grouped column
    new ApexCharts(document.querySelector('#chart-attend'), Object.assign({}, base, {
        chart: Object.assign({ type:'bar', height:210 }, base.chart),
        colors:['#219688','#dc3545'],
        plotOptions:{ bar:{ columnWidth:'55%', borderRadius:3 } },
        series:[ { name:'Present', data:CHART.present }, { name:'Absent', data:CHART.absent } ],
        yaxis:{ labels:{ style:{ fontSize:'10px', colors:'#999' } } }
    })).render();

    // ── Comparison tab ──
    initCompare();
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
                '<div class="empty-state"><i class="ri-error-warning-line" style="color:#e8920a;"></i><p>Please select two <strong>different</strong> payroll periods to compare.</p></div>';
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
    document.querySelectorAll('#ps-hist tbody tr').forEach(function(tr){
        tr.style.display=tr.textContent.toLowerCase().includes(q)?'':'none';
    });
});

// ── Attendance date-range filter (daterangepicker) ──
var attFrom = null, attTo = null;   // 'YYYY-MM-DD' strings, or null = no bound
function filterAtt() {
    var rows = document.querySelectorAll('#att-tbl tbody tr');
    var visible = 0;
    rows.forEach(function(tr) {
        var d = tr.getAttribute('data-date');
        var show = (!attFrom || d >= attFrom) && (!attTo || d <= attTo);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var c = document.getElementById('att-count');
    if (c) c.textContent = visible;
}
function clearAttFilter() {
    attFrom = attTo = null;
    var lbl = document.getElementById('att-range-label');
    if (lbl) lbl.textContent = 'All dates';
    filterAtt();
}

jQuery(function ($) {
    var $picker = $('#att-range');
    if (!$picker.length) return;
    $picker.daterangepicker({
        autoUpdateInput: false,
        opens: 'left',
        showDropdowns: true,
        locale: { format: 'MMM D, YYYY', cancelLabel: 'Clear', applyLabel: 'Apply' },
        ranges: {
            'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
            'Last 30 Days': [moment().subtract(29, 'days'), moment()],
            'This Month':   [moment().startOf('month'), moment().endOf('month')],
            'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });
    $picker.on('apply.daterangepicker', function (ev, picker) {
        attFrom = picker.startDate.format('YYYY-MM-DD');
        attTo   = picker.endDate.format('YYYY-MM-DD');
        $('#att-range-label').text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
        filterAtt();
    });
    $picker.on('cancel.daterangepicker', function () {
        clearAttFilter();
    });
});

// ── Time-log popovers (Bootstrap) ──
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el, { sanitize: false });
        el.addEventListener('shown.bs.popover', function () {
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (other) {
                if (other !== el) bootstrap.Popover.getInstance(other) && bootstrap.Popover.getInstance(other).hide();
            });
        });
    });
    // Click outside closes any open popover
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover')) {
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                var inst = bootstrap.Popover.getInstance(el);
                if (inst) inst.hide();
            });
        }
    });
});
</script>

<!-- Modal: Request a Leave -->
<div class="modal fade" id="modal-leave-request" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" action="employee-portal.php" id="leave-request-form">
            <input type="hidden" name="action" value="request_leave">
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
                            <select name="leave_type_id" class="form-control" required>
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
                            <input type="text" id="lv-dates" class="form-control" placeholder="Pick one or more days…" readonly required>
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
                            <textarea name="reason" class="form-control" rows="3" placeholder="State the reason for your leave" required></textarea>
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
        <form method="post" action="employee-portal.php" id="lwop-request-form">
            <input type="hidden" name="action" value="request_leave">
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
                            <input type="text" id="lwop-dates" class="form-control" placeholder="Pick one or more days…" readonly required>
                            <input type="hidden" name="dates" id="lwop-dates-hidden">
                        </div>
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Reason <span style="color:red">*</span></label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="State the reason for LWOP" required></textarea>
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

</body>
</html>
