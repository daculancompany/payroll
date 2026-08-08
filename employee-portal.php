<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/db_connect.php';
// The offline payroll machine (APP_ROLE=local) does not serve the employee
// portal at all — employees use the internet-facing deployment instead.
if (app_is_local()) {
    header('HTTP/1.1 404 Not Found');
    exit('Not available on this installation.');
}
// Admin / staff sessions must NOT use the employee self-service portal.
if (isset($_SESSION['is_login']) && $_SESSION['is_login']) {
    header('location:index.php?page=home'); exit;
}
if (!isset($_SESSION['emp_is_login']) || !$_SESSION['emp_is_login']) {
    header('location:login.php'); exit;
}
include_once 'db_connect.php';
require_once __DIR__ . '/includes/leave_timeline.php';

$emp_id = (int)$_SESSION['emp_id'];
if (isset($_GET['logout'])) { session_destroy(); header('location:login.php'); exit; }

// Leave eligibility: only Regular / Executive employees may request / hold credits.
$elig_q = $conn->query("SELECT UPPER(COALESCE(cl.clasification,'')) AS c, e.leave_override FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id WHERE e.id = " . $emp_id);
$elig_r = $elig_q ? $elig_q->fetch_assoc() : null;
$portal_leave_eligible = $elig_r && leave_eligibility_from($elig_r['c'], $elig_r['leave_override']);

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

// Days already covered by this employee's own pending/approved leaves — the
// leave and LWOP pickers disable them up front (matching the admin File Leave
// modal); the server-side duplicate guard still re-checks on submit.
$my_taken_leave_dates = [];
$mtq = $conn->query("SELECT dates, date_from, date_to FROM leave_requests WHERE employee_id = $emp_id AND status IN (0,1)");
if ($mtq) while ($mt = $mtq->fetch_assoc()) {
    $dd = [];
    if (!empty($mt['dates'])) { $j = json_decode($mt['dates'], true); if (is_array($j)) $dd = $j; }
    if (!$dd) { for ($s = strtotime($mt['date_from']); $s <= strtotime($mt['date_to']); $s = strtotime('+1 day', $s)) $dd[] = date('Y-m-d', $s); }
    foreach ($dd as $d1) $my_taken_leave_dates[date('Y-m-d', strtotime($d1))] = true;
}
$my_taken_leave_dates = array_keys($my_taken_leave_dates);
sort($my_taken_leave_dates);

// ── Leave data for the portal Leave tab ─────────────────────────────────
$leave_types_list = [];
$lwop_types_list  = [];
$ltq = $conn->query("SELECT id, name, days_allowed, is_paid FROM leave_types WHERE status = 1 ORDER BY name ASC");
if ($ltq) while ($r = $ltq->fetch_assoc()) {
    if ($r['is_paid'] == 0) $lwop_types_list[] = $r;
    else $leave_types_list[] = $r;
}

$leave_year = leave_current_year();   // credits are tracked per calendar year
$leave_balance = [];
$lbq = $conn->query("
    SELECT lt.id, lt.name,
        COALESCE(c.credits, lt.days_allowed) AS credits,
        COALESCE(u.used, 0) AS used
    FROM leave_types lt
    LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $emp_id AND c.year = $leave_year
    LEFT JOIN (
        SELECT leave_type_id, SUM(duration) AS used
        FROM leave_requests WHERE employee_id = $emp_id AND status = 1 AND YEAR(date_from) = $leave_year GROUP BY leave_type_id
    ) u ON u.leave_type_id = lt.id
    WHERE lt.status = 1 AND lt.is_paid = 1
    ORDER BY lt.name ASC
");
if ($lbq) while ($r = $lbq->fetch_assoc()) $leave_balance[] = $r;

// Remaining credits available for FILING (paid types): counts approved AND
// still-pending requests so stacked filings can't exceed the balance. Mirrors
// the server-side guard in emp-portal-ajax.php (submit_leave_request).
$lv_remaining_filing = [];
$lrf = $conn->query("
    SELECT lt.id, COALESCE(c.credits, lt.days_allowed) - COALESCE(u.used, 0) AS remaining
    FROM leave_types lt
    LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = $emp_id AND c.year = $leave_year
    LEFT JOIN (
        SELECT leave_type_id, SUM(duration) AS used
        FROM leave_requests WHERE employee_id = $emp_id AND status IN (0,1) AND YEAR(date_from) = $leave_year
        GROUP BY leave_type_id
    ) u ON u.leave_type_id = lt.id
    WHERE lt.status = 1 AND lt.is_paid = 1
");
if ($lrf) while ($r = $lrf->fetch_assoc()) $lv_remaining_filing[(int)$r['id']] = round(max(0, (float)$r['remaining']), 1);

$mlq = $conn->prepare("
    SELECT lr.*, lt.name AS leave_type_name, su.name AS sup_name, hu.name AS hr_name, au.name AS admin_name
    FROM leave_requests lr
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN users su ON su.id = lr.sup_by
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
           COALESCE(cl.clasification,'—') AS clasification_name,
           b.bank_name
    FROM employee e
    LEFT JOIN department   d  ON e.department_id   = d.id
    LEFT JOIN position     p  ON e.position_id     = p.id
    LEFT JOIN clasification cl ON e.clasification_id = cl.id
    LEFT JOIN banks        b  ON e.bank_id         = b.id
    WHERE e.id = ?
");
$s->bind_param('i', $emp_id); $s->execute();
$emp = $s->get_result()->fetch_assoc();

// ── Portal login (My Info → Login & Security) ───────────────────────────
// must_change = 1 means the account is still on the password HR handed out, so
// the portal nags until the employee picks their own. No row yet = a pre-portal
// employee signing in with the bday/employee_no fallback; the change-password
// form creates the account row on first use.
$portal_acct = null;
if ($conn->query("SHOW TABLES LIKE 'employee_portal_accounts'")->num_rows) {
    $pa = $conn->prepare("SELECT username, must_change, last_login FROM employee_portal_accounts WHERE employee_id = ? LIMIT 1");
    $pa->bind_param('i', $emp_id); $pa->execute();
    $portal_acct = $pa->get_result()->fetch_assoc() ?: null;
}
$portal_must_change = $portal_acct ? ((int)$portal_acct['must_change'] === 1) : true;

// ── All payroll items ───────────────────────────────────────────
// Only payroll batches that are Ready for Review (3) or Locked (2) are visible here —
// employees shouldn't see draft/unfinished numbers before HR sends them for review.
$s2 = $conn->prepare("
    SELECT pi.id AS item_id, pi.net, pi.basic_pay, pi.present, pi.per_day, pi.rate_type,
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

// ── Named one-off items on these payslips (payroll_item_extras) ──
// Ad-hoc lines an admin attached to this employee alone: kind 1 deducts,
// kind 2 adds. Attached to each payslip so the breakdown below can name them
// instead of silently folding them into the totals. Guarded — a database
// without the migration simply has none.
$ppExtrasHas = (bool)($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows ?? 0);
foreach ($payslips as &$__ps) { $__ps['extras'] = []; $__ps['extra_add'] = 0.0; $__ps['extra_less'] = 0.0; }
unset($__ps);
if ($ppExtrasHas && $payslips) {
    $__ids = implode(',', array_map(function ($p) { return (int)$p['item_id']; }, $payslips));
    $xq = $conn->query("SELECT payroll_item_id, kind, label, amount FROM payroll_item_extras
                        WHERE payroll_item_id IN ($__ids) ORDER BY id ASC");
    $__byItem = [];
    if ($xq) while ($x = $xq->fetch_assoc()) $__byItem[(int)$x['payroll_item_id']][] = $x;
    foreach ($payslips as &$__ps) {
        foreach ($__byItem[(int)$__ps['item_id']] ?? [] as $x) {
            $__ps['extras'][] = ['kind' => (int)$x['kind'], 'label' => $x['label'], 'amount' => (float)$x['amount']];
            if ((int)$x['kind'] === 2) $__ps['extra_add']  += (float)$x['amount'];
            else                       $__ps['extra_less'] += (float)$x['amount'];
        }
    }
    unset($__ps);
}

$latest   = $payslips[0] ?? null;

// Rate-aware pay math for a payslip row — mirrors admin get_payroll_rows_data so the
// portal's gross matches the payroll view for BOTH pay bases:
//   monthly → basic_pay/2 − absent×per_day + allowance + OT + holiday/rest premiums − late − undertime
//   daily   → days present × daily rate + allowance + OT + premiums − late − undertime
// Returns ['sub' => basic earnings, 'gross' => full gross].
if (!function_exists('pp_pay')) {
    function pp_pay($r)
    {
        // Delegates to the one shared formula (payroll_earnings, db_connect.php)
        // so the employee can never be shown a gross the payroll did not produce.
        // This used to be a private copy and had drifted: it dropped paid leave,
        // night differential and rest-day pay, and priced late on a flat 8-hour
        // day regardless of the employee's real shift.
        $e = payroll_earnings($r);
        // 'sub' is the figure this view labels as basic earnings, and it is NOT
        // the same quantity on both bases: monthly shows the half-month share,
        // daily shows the full days-worked total (not halved). Preserved exactly.
        return [
            'sub'   => $e['is_monthly'] ? $e['subtotal'] : $e['basic'],
            'gross' => $e['gross'],
            'parts' => $e,
        ];
    }
}
// Same figures with this payslip's one-off items folded in: allowance extras
// raise gross, deduction extras are added to the deductions side. Keeps the
// portal's breakdown matching the stored net the admin sheet produced.
if (!function_exists('pp_pay_x')) {
    function pp_pay_x($r)
    {
        $p = pp_pay($r);
        $p['gross'] += (float)($r['extra_add'] ?? 0);
        return $p;
    }
}

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
        $_pm   = payroll_per_minute($ps);
        $_at   = $ps['allowance_amount'] * $ps['allowance_days'];
        $_ot   = $ps['ot'] * $ps['ot_rate'];
        $_la   = $ps['late'] * $_pm;
        $_ab   = $ps['absent'] * $ps['per_day'];
        $_lgl  = $ps['legal_holiday'] * $ps['per_day'];
        $_sun  = $ps['sunday_duty']   * $ps['per_day'];
        $_spc  = ($ps['per_day']/8*2.4) * $ps['special_holiday'];
        $_p    = pp_pay_x($ps);
        $_sub  = $_p['sub'];
        $_gr   = $_p['gross'];
        $_dd   = $ps['deduction_amount'] + $ps['other_deduction'] + $ps['tax'] + $ps['jei_advances'] + $ps['jcc_advances'] + $ps['sss_fund'] + (float)($ps['extra_less'] ?? 0);
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
    $c_la  = $cp['late'] * payroll_per_minute($cp);
    $c_ab  = $cp['absent'] * $cp['per_day'];
    $c_lgl = $cp['legal_holiday'] * $cp['per_day'];
    $c_sun = $cp['sunday_duty'] * $cp['per_day'];
    $c_spc = ($cp['per_day']/8*2.4) * $cp['special_holiday'];
    $c_p   = pp_pay_x($cp);
    $c_sub = $c_p['sub'];
    $c_gr  = $c_p['gross'];
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
    $pmn = payroll_per_minute($cp);
    $att = $cp['allowance_amount'] * $cp['allowance_days'];
    $otv = $cp['ot'] * $cp['ot_rate'];
    $lav = $cp['late'] * $pmn;
    $utv = $cp['under_time'] * $pmn;
    $abv = $cp['absent'] * $cp['per_day'];
    $lgl = $cp['legal_holiday'] * $cp['per_day'];
    $sun = $cp['sunday_duty'] * $cp['per_day'];
    $spc = ($cp['per_day']/8*2.4) * $cp['special_holiday'];
    $pp  = pp_pay_x($cp);
    $sub = $pp['sub'];
    // Gross mirrors the admin payroll view, honoring the employee's rate basis.
    $grs = $pp['gross'];
    $ded = $cp['deduction_amount'] + $cp['other_deduction'] + $cp['tax']
         + $cp['jei_advances'] + $cp['jcc_advances'] + $cp['sss_fund'] + (float)($cp['extra_less'] ?? 0);
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
// (attendance-portal-server.php). The tab badge shows TODAY's records only
// (matches the list's default "Today" range); hidden when zero.
$s3 = $conn->prepare("SELECT COUNT(*) AS c FROM DTR_details WHERE employee_id = ? AND DATE(date_time) = CURDATE()");
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
    $pm       = payroll_per_minute($latest);
    $late_amt = $latest['late'] * $pm;
    $ut_amt   = $latest['under_time'] * $pm;
    $all_tot  = $latest['allowance_amount'] * $latest['allowance_days'];
    $abs_amt  = $latest['absent'] * $latest['per_day'];
    $ot_amt   = $latest['ot'] * $latest['ot_rate'];
    $lgl_amt  = $latest['legal_holiday'] * $latest['per_day'];
    $__rt_l   = $latest['rate_type'] ?? 'daily';
    $sun_amt  = ($__rt_l === 'monthly' || $__rt_l === 'fixed')
        ? $latest['sunday_duty'] * $latest['per_day']
        : rest_day_premium($latest['sunday_duty'], $latest['per_day']);
    $spc_amt  = ($latest['per_day'] / 8 * 2.4) * $latest['special_holiday'];
    $_pp      = pp_pay_x($latest);
    $sub_tot  = $_pp['sub'];
    // Gross mirrors the admin payroll view, honoring the employee's rate basis.
    $gross    = $_pp['gross'];
    $tot_ded  = $latest['deduction_amount'] + $latest['other_deduction']
              + $latest['tax'] + $latest['jei_advances'] + $latest['jcc_advances'] + $latest['sss_fund']
              + (float)($latest['extra_less'] ?? 0);
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

// Cache-bust local assets by mtime. A hand-maintained "?v=1" froze the
// installed app on an old portal-mobile.css for as long as the browser kept
// its copy — the payslip viewer and other sheets rendered with stale rules.
// filemtime moves on every edit, so a deploy can never serve a stale asset.
// Now defined globally in db_connect.php so the admin pages get it too;
// kept guarded here in case this file is ever loaded standalone.
if (!function_exists('av')) {
    function av($path)
    {
        $t = @filemtime(__DIR__ . '/' . $path);
        return $path . '?v=' . ($t ?: '1');
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
<!-- This portal is the internet-facing deployment; keep it out of search results. -->
<meta name="robots" content="noindex, nofollow">

<!-- CSRF: csrf.js reads this token and attaches it to every fetch() the portal
     makes, so it must load before any other script that issues a request. -->
<meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= av('assets2/js/csrf.js') ?>"></script>

<!-- ── PWA: installable home-screen app (Android + iOS) ── -->
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#6642aa">
<link rel="icon" type="image/png" href="assets2/images/pwa/icon-192.png">
<!-- iOS: no manifest install prompt — it reads these tags on "Add to Home Screen" -->
<link rel="apple-touch-icon" href="assets2/images/pwa/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="COMC Portal">
<!-- Loads early and un-deferred: it must catch beforeinstallprompt, which the
     browser fires once and never replays for a late listener. -->
<script src="<?= av('assets2/js/pwa-install.js') ?>"></script>
<!-- Warm up CDN connections early — faster first paint on mobile networks -->
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://cdn.datatables.net" crossorigin>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
<!-- Same bootstrap-datetimepicker the admin panel (index.php) uses -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/css/bootstrap-datetimepicker.min.css">
<link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap.min.css" rel="stylesheet">
<link href="<?= av('assets2/css/modal-stacking.css') ?>" rel="stylesheet">
<!-- GLOBAL theme tokens (primary purple + every scrollbar in the app) -->
<link href="<?= av('assets2/css/theme.css') ?>" rel="stylesheet">
<!-- Clock-face timepicker web component (File a Request claimed times) — https://github.com/loebi-ch/clock-timepicker -->
<script type="module" src="assets2/vendor/clock-timepicker.js"></script>
<style>
*{box-sizing:border-box;}
body{
    margin:0;
    font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#312f38;
    /* clean, cool off-white backdrop with a faint purple wash */
    background-color:#f0eff2;
    background-image:
        radial-gradient(circle at 20% 0%, rgba(102,66,170,.06) 0, transparent 42%),
        radial-gradient(circle at 100% 100%, rgba(102,66,170,.05) 0, transparent 40%);
    background-attachment:fixed;
}
/* Paper sheet helper — warm white with a hairline edge + layered shadow */
.paper{
    background:#ffffff;
    border:1px solid #e7e6ed;
    box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);
}

/* Top bar */
.ptop{background:#fff;padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:56px;position:sticky;top:0;z-index:200;border-bottom:1px solid #e7e6ed;box-shadow:0 1px 3px rgba(58,40,93,.06);}
.ptop-brand{color:#4e3483;font-size:14px;font-weight:800;display:flex;align-items:center;gap:9px;letter-spacing:.2px;}
.ptop-logo{width:30px;height:30px;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#fff;box-shadow:0 3px 8px rgba(102,66,170,.28);overflow:hidden;}
.ptop-logo img{width:100%;height:100%;object-fit:cover;}
.ptop-logout{background:#f3f2f7;color:#4e3483;border:1px solid #ded9e8;border-radius:9px;padding:5px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .18s;}
.ptop-logout:hover{background:#e7e4f0;color:#4e3483;border-color:#cec7e0;}
.ptop-logout i{margin-right:5px;}
.ptop-actions{display:flex;align-items:center;gap:8px;}
.ptop-icbtn{position:relative;width:38px;height:38px;border-radius:50%;background:#f3f2f7;border:1px solid #ded9e8;color:#4e3483;font-size:19px;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;transition:background .15s,transform .12s;-webkit-tap-highlight-color:transparent;}
.ptop-icbtn:hover{background:#e7e4f0;}
.ptop-icbtn:active{transform:scale(.92);}
.ptop-bell .emp-bell-dot{position:absolute;top:6px;right:7px;width:9px;height:9px;background:#ff4d4f;border:2px solid #fff;border-radius:50%;}
.ptop-icbtn.spinning i{animation:ptop-spin .7s linear infinite;}
/* Install button — tinted so it reads as an offer, not another utility icon */
.ptop-install{background:#ece5fb;border-color:#cdbcf0;color:#5b34a8;}
.ptop-install:hover{background:#e0d5f7;}
@keyframes ptop-spin{to{transform:rotate(360deg);}}
/* Per-screen title — only surfaces in the mobile app header */
.ptop-screen-title{display:none;}

/* Layout — wide on desktop, fluid below */
.portal-wrap{max-width:1280px;margin:0 auto;padding:22px 18px 50px;}
@media(min-width:1500px){.portal-wrap{max-width:1400px;}}

/* Employee header card */
.emp-hdr{background:#ffffff;border:1px solid #e7e6ed;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 10px 26px -14px rgba(58,40,93,.22);margin-bottom:18px;}
.emp-hdr-top{background:linear-gradient(135deg,#6642aa,#4e3483);padding:20px 22px;display:flex;align-items:center;gap:16px;}
.emp-av{width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#fff;flex-shrink:0;border:2px solid rgba(255,255,255,.4);}
.emp-nm{font-size:17px;font-weight:900;color:#fff;line-height:1.2;}
.emp-sub{font-size:11px;color:rgba(255,255,255,.78);margin-top:3px;}
.emp-hdr-right{margin-left:auto;display:flex;align-items:center;gap:10px;}
.emp-no-badge{background:rgba(0,0,0,.18);color:#fff;border-radius:8px;padding:5px 13px;font-size:11px;font-family:monospace;font-weight:800;white-space:nowrap;}
/* Notification bell */
.emp-bell{position:relative;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;color:#fff;font-size:19px;cursor:pointer;transition:background .15s;}
.emp-bell:hover{background:rgba(255,255,255,.32);}
.emp-bell-dot{position:absolute;top:7px;right:8px;width:9px;height:9px;background:#ffcf33;border:2px solid #4e3483;border-radius:50%;}
/* Notification panel — a top-bar dropdown on desktop, a bottom sheet on mobile */
.emp-notif-scrim{position:fixed;inset:0;background:rgba(46,35,70,.28);z-index:1199;opacity:0;visibility:hidden;transition:opacity .2s;}
.emp-notif-scrim.open{opacity:1;visibility:visible;}
.emp-notif-panel{position:fixed;top:52px;right:12px;width:360px;max-width:calc(100vw - 24px);background:#fff;border-radius:16px;box-shadow:0 14px 44px rgba(46,35,70,.24);z-index:1201;overflow:hidden;display:flex;flex-direction:column;
    opacity:0;visibility:hidden;transform:translateY(-8px) scale(.98);transform-origin:top right;transition:opacity .16s,transform .16s,visibility .16s;}
.emp-notif-panel.open{opacity:1;visibility:visible;transform:translateY(0) scale(1);}
.emp-notif-sheet-grip{display:none;}
.emp-notif-head{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid #f0eff3;font-size:14px;font-weight:800;color:#4e3483;}
.emp-notif-allread{background:none;border:0;color:#6642aa;font-size:11.5px;font-weight:700;cursor:pointer;padding:4px 6px;border-radius:7px;}
.emp-notif-allread:active{background:#f2f0f7;}
.emp-notif-list{max-height:min(70vh,440px);overflow-y:auto;-webkit-overflow-scrolling:touch;}
.emp-notif-empty{display:flex;flex-direction:column;align-items:center;text-align:center;padding:38px 16px;color:#aaa;}
.emp-notif-empty i{font-size:38px;color:#d5d2e1;margin-bottom:10px;}
.emp-notif-empty .net{font-size:14px;font-weight:800;color:#69617f;}
.emp-notif-empty .nes{font-size:11.5px;color:#a6a2b4;margin-top:3px;}
.emp-notif-item{display:flex;gap:11px;padding:13px 16px;border-bottom:1px solid #f5f5f7;cursor:pointer;transition:background .12s;-webkit-tap-highlight-color:transparent;}
.emp-notif-item:hover{background:#f9f8fb;}
.emp-notif-item:active{background:#f1f0f5;}
.emp-notif-item.unread{background:#f5f3fa;}
.emp-notif-item.unread .emp-notif-title::after{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:#6642aa;margin-left:6px;vertical-align:middle;}
.emp-notif-ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.emp-notif-primary{background:#e6f0fb;color:#2563eb;} .emp-notif-success{background:#eafaf0;color:#0f9d58;}
.emp-notif-warning{background:#fff6e0;color:#c98a00;} .emp-notif-danger{background:#fdecea;color:#c62828;} .emp-notif-info{background:#e6f7fb;color:#0891b2;}
.emp-notif-txt{flex:1;min-width:0;}
.emp-notif-title{font-size:13px;font-weight:800;color:#312f38;}
.emp-notif-msg{font-size:12px;color:#667;margin-top:2px;line-height:1.4;}
.emp-notif-time{font-size:10.5px;color:#aab;margin-top:4px;}
@media (max-width:600px){
    /* Bottom sheet — the native pattern for a phone */
    .emp-notif-panel{top:auto;left:0;right:0;bottom:0;width:100%;max-width:100%;border-radius:20px 20px 0 0;
        transform:translateY(100%);transform-origin:bottom center;transition:transform .26s cubic-bezier(.32,.72,0,1),opacity .2s,visibility .2s;
        box-shadow:0 -8px 34px rgba(46,35,70,.22);padding-bottom:env(safe-area-inset-bottom);}
    .emp-notif-panel.open{transform:translateY(0);}
    .emp-notif-sheet-grip{display:block;width:38px;height:4px;border-radius:3px;background:#dbd9e3;margin:8px auto 2px;}
    .emp-notif-list{max-height:66vh;}
    .emp-notif-head{padding:10px 18px 13px;}
}
/* My DTR tab */
.mydtr-intro{background:#f5f3fa;border:1px solid #cdeeda;border-radius:12px;padding:12px 15px;font-size:12.5px;color:#585272;line-height:1.5;margin-bottom:14px;}
.mydtr-empty{padding:34px 14px;text-align:center;color:#aaa;font-size:13px;}
/* The card itself opens the review sheet — a chevron replaces the old
   per-row "View" / "Review & Confirm" buttons. */
.mydtr-card{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff;border:1px solid #f0eff3;border-radius:14px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.04);cursor:pointer;transition:border-color .16s,box-shadow .16s;}
.mydtr-card:hover{border-color:#dad4e6;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 10px 24px -14px rgba(58,40,93,.3);}
.mydtr-card:focus-visible{outline:2px solid #6642aa;outline-offset:2px;}
/* The row that still needs a decision reads as the primary one */
.mydtr-card.needs-action{border-color:#f3d999;background:linear-gradient(180deg,#fffdf6,#fff);}
.mydtr-card-main{min-width:0;flex:1 1 auto;}
.mydtr-period{font-size:14px;font-weight:800;color:#4e3483;display:flex;align-items:center;gap:6px;}
.mydtr-meta{font-size:11px;color:#999;margin-top:4px;}
.mydtr-card-side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0;}
.mydtr-chev{flex-shrink:0;font-size:20px;color:#c7c4cd;line-height:1;}
.mydtr-card.needs-action .mydtr-chev{color:#c98a00;}
.mydtr-badge{font-size:10px;font-weight:800;padding:3px 10px;border-radius:11px;white-space:nowrap;}
.mydtr-badge.review{background:#fff6e0;color:#c98a00;} .mydtr-badge.ok{background:#eafaf0;color:#0f9d58;}
.mydtr-badge.dispute{background:#fdecea;color:#c62828;} .mydtr-badge.done{background:#f0eff3;color:#666;}
.mydtr-btn{border:0;border-radius:9px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.mydtr-btn.primary{background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;}
.mydtr-btn.ghost{background:#f2f1f5;color:#4e3483;}
/* Review modal table */
.drev-prev{font-size:12px;font-weight:700;padding:9px 12px;border-radius:10px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.drev-prev.ok{background:#eafaf0;color:#0f9d58;} .drev-prev.dis{background:#fdecea;color:#c62828;}
/* ── DTR detail view — mirrors the admin DTR "By Employee" card ── */
.drev-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;}
.drev-stat{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #f0eff3;border-radius:11px;padding:9px 11px;}
.drev-stat .ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.drev-stat .val{font-size:16px;font-weight:800;line-height:1.1;}
.drev-stat .lbl{font-size:10px;color:#908c9c;font-weight:600;text-transform:uppercase;letter-spacing:.02em;}
.drev-stat.wh .ic{background:#eeeaf5;color:#6642aa;} .drev-stat.wh .val{color:#6642aa;}
.drev-stat.ot .ic{background:#fff8e1;color:#f7b84b;} .drev-stat.ot .val{color:#c98a00;}
.drev-stat.ut .ic{background:#e3f2fd;color:#50a5f1;} .drev-stat.ut .val{color:#1565c0;}
.drev-stat.late .ic{background:#fce4ec;color:#f06548;} .drev-stat.late .val{color:#c62828;}
.drev-daygrp{border:1px solid #f0eff3;border-radius:12px;overflow:hidden;margin-bottom:10px;background:#fff;}
.drev-dayhead{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;background:#f5f3fa;border-bottom:1px solid #e8e5f0;}
.drev-daylabel{font-size:12px;font-weight:800;color:#4e3483;display:flex;align-items:center;gap:6px;}
.drev-daytot{display:flex;gap:10px;font-size:10.5px;font-weight:700;}
.drev-entry{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;flex-wrap:wrap;}
.drev-entry + .drev-entry{border-top:1px dashed #f3f2f5;}
.drev-entry.is-approved{background:#f4fbf6;} .drev-entry.is-disapproved{background:#fdf3f3;}
.drev-times{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.dtime-chip{font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:8px;white-space:nowrap;}
.dtime-chip.in{background:#eafaf0;color:#0f9d58;} .dtime-chip.out{background:#e3f2fd;color:#1565c0;}
.dtime-chip.na{background:#f3f4f6;color:#aaa;}
.dpunch{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-top:5px;}
.dpunch-chip{font-size:10px;font-weight:700;padding:2px 7px;border-radius:7px;display:inline-flex;align-items:center;gap:3px;}
.dpunch-chip.bio{background:#eeeaf5;color:#603ca4;} .dpunch-chip.manual{background:#fff4e0;color:#b57e12;}
.dpunch-chip .pl{color:#a29cac;font-weight:800;font-size:9px;margin-right:1px;}
.drev-mini{display:flex;gap:6px;flex-wrap:wrap;}
.dmini{display:flex;flex-direction:column;align-items:center;background:#f9f8fb;border-radius:8px;padding:3px 9px;min-width:42px;}
.dmini .k{font-size:9px;color:#a29cac;font-weight:700;text-transform:uppercase;}
.dmini .v{font-size:12.5px;font-weight:800;color:#3a3447;}
.dmini.ot .v{color:#c98a00;} .dmini.ut .v{color:#1565c0;} .dmini.late .v{color:#c62828;}
.dstat-badge{font-size:10px;font-weight:800;padding:3px 9px;border-radius:9px;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;}
.dstat-badge.ok{background:#eafaf0;color:#0f9d58;} .dstat-badge.dis{background:#fdecea;color:#c62828;} .dstat-badge.pend{background:#fff6e0;color:#c98a00;}
.dstat-note{font-size:11px;color:#a33;margin-top:3px;flex-basis:100%;}
@media (max-width:575.98px){
    .drev-stats{grid-template-columns:repeat(2,1fr);}
    .drev-entry{flex-direction:column;align-items:stretch;}
    .drev-mini{justify-content:space-between;}
}
.emp-stats{display:grid;grid-template-columns:repeat(5,1fr);}
.est{padding:12px 14px;border-right:1px solid #f0eff3;text-align:center;}
.est:last-child{border-right:none;}
.est-v{font-size:16px;font-weight:800;color:#6642aa;line-height:1;}
.est-l{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;}

/* Tabs */
.tab-strip{display:flex;gap:4px;background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;padding:5px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 6px 18px -12px rgba(58,40,93,.18);margin-bottom:16px;flex-wrap:wrap;}
.tab-btn{flex:1;padding:9px 6px;border:none;background:transparent;border-radius:8px;font-size:12px;font-weight:700;color:#888;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;transition:all .18s;}
.tab-btn.active{background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;box-shadow:0 2px 8px rgba(102,66,170,.3);}
.tab-btn:not(.active):hover{background:#f3f2f7;color:#4e3483;}
.tab-btn .badge-count{background:rgba(255,255,255,.25);color:#fff;border-radius:10px;padding:0 6px;font-size:10px;font-weight:800;}
.tab-btn:not(.active) .badge-count{background:#f0ecf6;color:#6642aa;}
.tab-panel{display:none;} .tab-panel.active{display:block;}
.tab-more{display:none;}   /* only surfaces in the mobile bottom nav */

/* More sheet (mobile only) */
.more-backdrop{display:block;position:fixed;inset:0;background:rgba(20,30,55,.42);z-index:450;
    opacity:0;pointer-events:none;transition:opacity .25s ease;}
.more-backdrop.open{opacity:1;pointer-events:auto;}
/* True bottom sheet — slides up from the bottom edge; drag down to dismiss */
.more-sheet{position:fixed;left:0;right:0;bottom:0;z-index:500;width:100%;
    background:#fff;border-radius:22px 22px 0 0;box-shadow:0 -14px 44px rgba(20,30,55,.26);
    padding:8px 16px calc(20px + env(safe-area-inset-bottom,0px));
    transform:translateY(102%);pointer-events:none;will-change:transform;
    transition:transform .3s cubic-bezier(.32,.72,.24,1);}
.more-sheet.open{transform:translateY(0);pointer-events:auto;}
.more-sheet.dragging{transition:none;}
.more-grip{display:block;width:42px;height:4px;border-radius:2px;background:#dad8df;margin:4px auto 12px;}
.more-head{font-size:15px;font-weight:800;color:#312f38;margin-bottom:14px;text-align:center;}
.more-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;justify-content:center;}
.more-item{position:relative;display:flex;flex-direction:column;align-items:center;gap:7px;background:#f7f8fa;border:1px solid #eef0f2;border-radius:16px;padding:15px 6px;cursor:pointer;}
.more-item:active{background:#eef0f2;}
.more-ic{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;}
.more-lbl{font-size:11px;font-weight:700;color:#312f38;}
.more-dot{position:absolute;top:9px;right:16px;background:#dc3545;color:#fff;border-radius:9px;min-width:16px;height:16px;padding:0 4px;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;}

/* Section title */
.sec{font-size:11px;font-weight:800;color:#6642aa;text-transform:uppercase;letter-spacing:.7px;margin:18px 0 10px;display:flex;align-items:center;gap:8px;}
.sec::after{content:'';flex:1;height:1px;background:#e4e0ec;}

/* Latest payslip */
.ps-card{background:#ffffff;border:1px solid #e7e6ed;border-radius:14px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);overflow:hidden;margin-bottom:14px;}
.ps-period{background:#4e3483;color:#fff;padding:10px 18px;font-size:12px;font-weight:700;display:flex;justify-content:space-between;}
.ps-body{display:grid;grid-template-columns:1fr 1fr;gap:0;}
.ps-col{padding:14px 18px;}
.ps-col:first-child{border-right:1px solid #f2f1f5;}
.ps-col-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
.ps-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #f8f8f8;}
.ps-row:last-child{border-bottom:none;}
.ps-lbl{font-size:11px;color:#888;}
.ps-val{font-size:12px;font-weight:600;}
.earn{color:#6642aa;} .ded{color:#dc3545;} .dim{color:#bbb;}
.ps-net{background:linear-gradient(135deg,#6642aa,#4e3483);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;}
.ps-net-lbl{color:rgba(255,255,255,.75);font-size:11px;text-transform:uppercase;letter-spacing:.5px;}
.ps-net-val{color:#fff;font-size:24px;font-weight:900;}
.ps-net-period{color:rgba(255,255,255,.6);font-size:10px;margin-top:2px;}

/* Payslip history table */
.ps-hist-table{width:100%;border-collapse:collapse;font-size:12px;}
.ps-hist-table thead th{background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;text-align:left;border:none;}
.ps-hist-table thead th.r{text-align:right;}
.ps-hist-table tbody tr{border-bottom:1px solid #f2f1f5;cursor:pointer;transition:background .14s;}
.ps-hist-table tbody tr:hover{background:#f8f6fb;}
/* Read-only variant — a plain, non-interactive item list (holidays/activities) */
.ps-hist-table.no-click tbody tr{cursor:default;}
.ps-hist-table.no-click tbody tr:hover{background:transparent;}
.ps-hist-table tbody td{padding:10px 12px;vertical-align:middle;}
.ps-hist-table tbody td.r{text-align:right;}
.ps-hist-table tfoot td{background:#f8f6fb;padding:9px 12px;font-weight:800;color:#6642aa;border-top:2px solid #e4e0ec;}
.ps-hist-table tfoot td.r{text-align:right;}
.net-badge{font-size:13px;font-weight:900;color:#4e3483;}
.present-pill{background:#f0ecf6;color:#4e3483;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}
.absent-pill{background:#fff0f0;color:#dc3545;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}
.late-pill{background:#fff8e8;color:#fd7e14;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;}

/* ══ All Payslips — one compact list for phone AND desktop ══
   Each row is a tappable summary (period · ref · status · net); the full
   itemised breakdown opens in the details sheet. */
.pslist-paper{border-radius:14px;overflow:hidden;}
.pslist-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;
    padding:11px 14px;border-bottom:1px solid #f2f1f5;background:#fcfcfe;}
.pslist-count{font-size:12px;color:#908c9c;font-weight:600;}
.pslist-search{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e4e1eb;
    border-radius:10px;padding:5px 10px;min-width:180px;}
.pslist-search i{color:#6642aa;font-size:14px;}
.pslist-search input{border:none;outline:none;font-size:12.5px;flex:1;min-width:0;background:transparent;}
.pslist{display:flex;flex-direction:column;}
.psrow{display:flex;align-items:center;gap:10px;width:100%;text-align:left;cursor:pointer;
    background:#fff;border:none;border-bottom:1px solid #f4f3f7;border-left:3px solid transparent;
    padding:12px 12px 12px 14px;transition:background .12s;}
.psrow:last-of-type{border-bottom:none;}
.psrow:hover{background:#f8f7fb;}
.psrow:active{background:#f2f0f7;}
.psrow.needs{border-left-color:#f0ad4e;background:#fffdf7;}
.psrow.needs:hover{background:#fff9ec;}
.psrow-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;}
.psrow-period{font-size:14px;font-weight:800;color:#4e3483;line-height:1.2;}
.psrow-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.psrow-ref{font-family:ui-monospace,Menlo,monospace;font-size:10.5px;font-weight:700;color:#a39fb2;}
.psbadge{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;white-space:nowrap;}
.psbadge.review{background:#fff4e0;color:#b76e00;border:1px solid #f3ddb4;}
.psbadge.ok{background:#e9f7ef;color:#178a4e;border:1px solid #bfe6cd;}
.psbadge.dispute{background:#fdecea;color:#c62828;border:1px solid #f5c6cb;}
.psrow-right{text-align:right;flex-shrink:0;}
.psrow-net{display:block;font-size:15px;font-weight:900;color:#4e3483;line-height:1.1;font-variant-numeric:tabular-nums;}
.psrow-sub{display:block;font-size:9.5px;color:#b0acbd;text-transform:uppercase;letter-spacing:.4px;margin-top:1px;}
.psrow-chev{color:#c9c5d4;font-size:20px;flex-shrink:0;}
.pslist-empty{padding:26px 14px;text-align:center;color:#a39fb2;font-size:12.5px;}
.pslist-total{display:flex;flex-wrap:wrap;gap:4px 18px;justify-content:flex-end;
    padding:11px 14px;background:#f9f8fb;border-top:1px solid #f0eff4;}
.pslist-total > div{display:flex;align-items:baseline;gap:6px;font-size:11.5px;color:#908c9c;}
.pslist-total b{font-size:13px;font-weight:800;color:#4e3483;font-variant-numeric:tabular-nums;}
.pslist-total b.ded{color:#dc3545;}
.pslist-total .net b{font-size:15px;font-weight:900;}
@media (max-width:575.98px){
    .pslist-head{padding:10px 12px;}
    .pslist-search{width:100%;}
    .psrow{padding:12px 10px 12px 12px;gap:8px;}
    .psrow-period{font-size:13.5px;}
    .psrow-net{font-size:14.5px;}
    .pslist-total{justify-content:space-between;}
    .pslist-total > div{width:100%;justify-content:space-between;}
}
/* Beats Bootstrap's .d-flex{display:flex!important} when hiding a footer block */
.prv-hide{display:none !important;}

/* ══ Attendance & Requests — the card feed is now the ONLY renderer, at every
   width (the desktop DataTables are retired). Cards stay compact on wide
   screens so the tabs read like the payslip list. ══ */
#tab-attendance .table-responsive,
#tab-att-requests .table-responsive{display:none;}
/* Attendance + Requests rows reuse the .psrow list shape */
.att-mlist-wrap, .areq-mlist-wrap{padding:0;}
/* No sideways scrolling anywhere in these tabs — the server sends the time /
   log cells as HTML fragments, so force every one of them to wrap instead of
   pushing the row wide. */
#tab-attendance, #tab-att-requests,
#tab-attendance .paper, #tab-att-requests .paper,
.att-mlist-wrap, .areq-mlist-wrap, #att-mlist, #areq-mlist{overflow-x:hidden;max-width:100%;}
.attrow{max-width:100%;}
.attrow .psrow-main, .attrow .psrow-meta{min-width:0;max-width:100%;flex-wrap:wrap;}
.attrow .psrow-meta > *{min-width:0;max-width:100%;}
.attrow .time-io{display:inline-flex;flex-wrap:wrap;gap:4px;align-items:center;max-width:100%;}
.attrow .attrow-note{overflow-wrap:anywhere;}
.attrow .hrs-bar{max-width:70px;}
.attrow{border-bottom:1px solid #f4f3f7;}
.attrow:last-of-type{border-bottom:none;}
.attrow .psrow-period{display:flex;align-items:center;gap:5px;}
.attrow-day{font-size:10.5px;font-weight:600;color:#8f8c98;}
.attrow-tic{color:#6642aa;font-size:14px;}
/* the server sends the type badge / time chips as HTML — keep them inline & small */
.attrow .psrow-meta .att-type,
.attrow .psrow-meta .att-type span{font-size:9.5px !important;}
.attrow-io{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;color:#666176;flex-wrap:wrap;}
.attrow-io .dtr-logs-pill{font-size:9.5px;}
.attrow-note{font-size:10px;color:#8f8c98;gap:4px;}
.attrow-note i{color:#bfbcc9;}
.attrow-filed{display:block;font-size:11px;font-weight:700;color:#666176;white-space:nowrap;}
.attm-foot{text-align:center;padding:12px 0 10px;}

/* Details sheet: on phones the review modal becomes a bottom sheet */
@media (max-width:575.98px){
    #modal-payroll-review .modal-dialog{margin:0;position:fixed;left:0;right:0;bottom:0;max-width:none;}
    #modal-payroll-review .modal-content{border-radius:18px 18px 0 0;max-height:92vh;}
}

/* ── Payslips — legacy mobile card list (superseded by .pslist) ── */
.ps-mlist{display:none;padding:12px 0 2px;}
.psm-card{position:relative;background:#ffffff;border:1px solid #e7e6ed;border-left:3px solid #6642aa;border-radius:14px;
    padding:13px 14px 0;margin:0 12px 12px;overflow:hidden;cursor:pointer;
    box-shadow:0 1px 2px rgba(58,40,93,.05),0 8px 20px -14px rgba(58,40,93,.28);}
.psm-chk{position:absolute;top:14px;right:12px;width:17px;height:17px;z-index:2;}
.psm-period{font-size:15px;font-weight:800;color:#4e3483;line-height:1.2;padding-right:30px;}
.psm-period small{display:block;font-size:10px;font-weight:600;color:#aaa;margin-top:1px;}
.psm-ref{font-family:monospace;font-size:11px;font-weight:700;color:#6642aa;margin:3px 0 2px;}
.psm-stats{display:flex;gap:2px;border-top:1px solid #f0eff3;margin-top:10px;}
.psm-stats>div{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 2px;}
.psm-stats span{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.psm-stats b{font-size:13px;font-weight:800;color:#4e3483;}
.psm-stats b.mut{color:#ccc;font-weight:600;}
.psm-stats b.abs{color:#dc3545;} .psm-stats b.lt{color:#fd7e14;} .psm-stats b.ot{color:#fd7e14;}
.psm-money{display:flex;border-top:1px solid #f0eff3;}
.psm-money>div{flex:1;display:flex;flex-direction:column;gap:2px;padding:11px 0 12px;}
.psm-money .lbl{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.psm-money .val{font-size:15px;font-weight:800;color:#6642aa;}
.psm-money .ded{align-items:flex-end;text-align:right;}
.psm-money .ded .val{color:#dc3545;}
.psm-action{border-top:1px solid #f0eff3;padding:11px 0 12px;text-align:center;}
.psm-action .mydtr-badge{display:inline-block;margin-bottom:8px;}
.psm-action .mydtr-btn{width:100%;padding:10px;font-size:13px;text-align:center;}
.psm-net{display:flex;align-items:center;justify-content:space-between;
    background:linear-gradient(135deg,#6642aa,#4e3483);margin:0 -14px;padding:12px 14px;}
.psm-net span{font-size:10px;font-weight:800;color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:.4px;}
.psm-net b{font-size:19px;font-weight:900;color:#fff;}
.psm-total{margin:2px 12px 6px;background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:12px;padding:10px 14px;color:#fff;}
.psm-total .rowt{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:12px;font-weight:700;}
.psm-total .rowt span{color:rgba(255,255,255,.82);text-transform:uppercase;letter-spacing:.3px;font-size:10px;}
.psm-total .rowt.net b{font-size:15px;font-weight:900;}

/* Attendance */
.att-table{width:100%;min-width:620px;border-collapse:collapse;font-size:12px;}
.att-table thead th{background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;border:none;text-align:left;white-space:nowrap;}
.att-table tbody tr{border-bottom:1px solid #f2f1f5;transition:background .14s;}
.att-table tbody tr:hover{background:#f8f6fb;}
.att-table tbody td{padding:9px 12px;vertical-align:middle;white-space:nowrap;}
.att-table td:last-child{white-space:normal;}
.att-type{border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;}
.att-P{background:#f0ecf6;color:#4e3483;}
.att-A{background:#fff0f0;color:#dc3545;}
.att-OT{background:#fff8e8;color:#fd7e14;}
.att-H{background:#eef0f8;color:#6f42c1;}
.att-S{background:#fdf0f6;color:#e83e8c;}
.hrs-bar{height:5px;border-radius:3px;background:#e7e3ee;overflow:hidden;margin-top:4px;}
.hrs-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#6642aa,#4e3483);}

/* ── Attendance mobile card feed (infinite scroll) — hidden on desktop ── */
/* Card feed is the only Attendance renderer now — shown at every width. */
.att-mlist-wrap{display:block;padding:12px 12px 14px;}
.attm-card{position:relative;background:#ffffff;border:1px solid #e7e6ed;border-left:3px solid #6642aa;
    border-radius:14px;margin-bottom:10px;padding:13px 14px 2px;
    box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 20px -14px rgba(58,40,93,.28);}
.attm-card:last-child{margin-bottom:0;}
.attm-head{padding:0 104px 11px 0;}
.attm-head .attm-d1{font-size:15px;font-weight:800;color:#4e3483;}
.attm-head .attm-d2{font-size:10.5px;color:#8f8c98;font-weight:600;margin-top:1px;}
.attm-card>.att-type{position:absolute;top:12px;right:12px;}
.attm-stats{display:flex;border-top:1px solid #f0eff3;}
.attm-stat{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;
    padding:11px 4px;text-align:center;}
.attm-stat+.attm-stat{border-left:1px solid #f4f3f6;}
.attm-stat .attm-sl{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.attm-stat .attm-sv{font-size:15px;font-weight:800;color:#4e3483;width:100%;max-width:110px;}
.attm-io{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:11px 0 10px;border-top:1px solid #f0eff3;}
.attm-io .attm-sl{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.attm-notes{display:flex;justify-content:space-between;align-items:center;gap:12px;
    padding:9px 0 12px;border-top:1px dashed #e7e6ed;text-align:right;}
.attm-notes::before{content:"Notes";font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.attm-foot{text-align:center;padding:14px 0 6px;font-size:11px;color:#8f8c98;font-weight:700;}
.attm-foot .attm-spin{display:inline-block;width:16px;height:16px;border:2px solid #d9d3e4;border-top-color:#6642aa;
    border-radius:50%;vertical-align:-3px;margin-right:7px;animation:attmSpin .7s linear infinite;}
@keyframes attmSpin{to{transform:rotate(360deg);}}
.attm-empty{text-align:center;padding:26px 14px;font-size:12px;color:#7f7c88;font-weight:600;}
.attm-empty i{display:block;font-size:26px;color:#b8b5c1;margin-bottom:6px;}

/* ── Requests (OT / incident) mobile card feed (infinite scroll) — hidden on desktop ── */
/* Card feed is the only Requests renderer now — shown at every width. */
.areq-mlist-wrap{display:block;padding:12px 12px 14px;}
.areq-card{position:relative;background:#ffffff;border:1px solid #e7e6ed;border-left:3px solid #6642aa;
    border-radius:14px;margin-bottom:10px;padding:13px 14px;
    box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 20px -14px rgba(58,40,93,.28);}
.areq-card:last-child{margin-bottom:0;}
.areq-card.st-pending{border-left-color:#e6a817;}
.areq-card.st-approved{border-left-color:#6642aa;}
.areq-card.st-rejected{border-left-color:#c62828;}
.areq-head{padding:0 92px 4px 0;}
.areq-head .areq-d1{font-size:15px;font-weight:800;color:#4e3483;display:flex;align-items:center;gap:6px;}
.areq-head .areq-d1 i{color:#6642aa;font-size:15px;}
.areq-type{display:inline-flex;align-items:center;gap:4px;border-radius:8px;padding:3px 9px;font-size:10px;font-weight:700;margin-top:8px;}
.areq-type.t-incident{background:#fff3cd;color:#856404;}
.areq-type.t-overtime{background:#cff4fc;color:#055160;}
.areq-status{position:absolute;top:12px;right:12px;border-radius:10px;padding:3px 11px;font-size:10px;font-weight:800;color:#fff;letter-spacing:.2px;}
.areq-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;
    margin-top:9px;padding-top:9px;border-top:1px solid #f0eff3;font-size:12px;}
.areq-row .areq-l{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;
    display:flex;align-items:center;gap:5px;flex-shrink:0;padding-top:1px;}
.areq-row .areq-l i{font-size:12px;color:#b8b5c1;}
.areq-row .areq-v{text-align:right;color:#414a46;font-weight:600;word-break:break-word;}
.areq-rev{margin-top:9px;padding-top:9px;border-top:1px dashed #e7e6ed;font-size:11px;color:#7f7c88;
    display:flex;gap:6px;align-items:flex-start;}
.areq-rev i{color:#b8b5c1;font-size:13px;flex-shrink:0;margin-top:1px;}

/* Mobile: every data table becomes a stacked list of cards (label : value rows)
   instead of a horizontally-scrolling table. Cells carry data-label="…" —
   a data-label="" cell (icons / narrow chips) collapses its label row. */
@media (max-width:767.98px), (pointer:coarse) and (max-height:500px){
    .ps-hist-table, .att-table, .drev-tbl{width:100%;min-width:0;border-collapse:separate;border-spacing:0;}
    .ps-hist-table thead, .att-table thead, .drev-tbl thead{display:none;}
    .ps-hist-table tbody, .att-table tbody, .drev-tbl tbody,
    .ps-hist-table tbody tr, .att-table tbody tr, .drev-tbl tbody tr{display:block;width:100%;}
    .ps-hist-table tbody tr, .att-table tbody tr, .drev-tbl tbody tr{
        background:#ffffff;border:1px solid #e7e6ed;border-left:3px solid #6642aa;border-radius:14px;
        margin-bottom:10px;padding:2px 13px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 20px -14px rgba(58,40,93,.28);}
    .ps-hist-table tbody tr:last-child, .att-table tbody tr:last-child, .drev-tbl tbody tr:last-child{margin-bottom:0;}
    .ps-hist-table tbody td, .att-table tbody td, .drev-tbl tbody td{
        display:flex;align-items:center;justify-content:space-between;gap:12px;
        padding:8px 0;border-top:1px solid #f4f3f6;white-space:normal;text-align:right;width:auto;}
    .ps-hist-table tbody td:first-child, .att-table tbody td:first-child, .drev-tbl tbody td:first-child{border-top:none;}
    .ps-hist-table tbody td::before, .att-table tbody td::before, .drev-tbl tbody td::before{
        content:attr(data-label);font-size:9.5px;font-weight:800;color:#8f8c98;
        text-transform:uppercase;letter-spacing:.4px;text-align:left;flex-shrink:0;}
    .ps-hist-table tbody td[data-label=""], .att-table tbody td[data-label=""]{justify-content:center;padding:6px 0;}
    .ps-hist-table tbody td[data-label=""]::before, .att-table tbody td[data-label=""]::before{content:none;}
    /* Payslip totals footer becomes its own summary card */
    .ps-hist-table tfoot, .ps-hist-table tfoot tr{display:block;width:100%;}
    .ps-hist-table tfoot tr{background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:12px;padding:2px 13px;margin-top:2px;}
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
        order:2;flex:0 0 100%;display:block;text-align:left;font-size:10px;color:#8f8c98;padding:0 0 10px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Date Applied"]::before{
        content:"Filed ";font-size:10px;font-weight:700;color:#b8b5c1;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Period"]{
        order:3;flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:8px 0;border-top:1px solid #f0eff3;text-align:right;font-size:12px !important;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Period"]::before{
        content:"Period";font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Days"]{
        order:4;flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border-top:1px solid #f0eff3;text-align:center;font-size:14px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Days"]::before{
        content:attr(data-label);display:block;order:-1;font-size:9px;font-weight:800;color:#8f8c98;
        text-transform:uppercase;letter-spacing:.3px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Progress"]{
        order:5;flex:0 0 100%;display:block;padding:8px 0 2px;border-top:1px solid #f0eff3;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Progress"]::before{
        content:"Progress";display:block;font-size:9px;font-weight:800;color:#8f8c98;
        text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Status"]{
        order:6;flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:9px 0;border-top:1px solid #f0eff3;text-align:right;}
    #leave-list-wrap .ps-hist-table tbody td[data-label="Status"]::before{
        content:"Status";font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}

    /* ── Holidays & Activities — event item, not a label/value stack ──
       The generic table→card transform turned each event into three
       "DATE / EVENT / TYPE" rows (~113px). Reflow the same three cells into a
       compact item: title + type pill on one line, date (and note) beneath. */
    /* Grid, not flex: a `flex:0 0 100%` cell makes the table's shrink-to-fit
       measure every cell on one line and overflow its wrapper. */
    #tab-holidays .table-responsive{overflow:visible;}
    #tab-holidays .ps-hist-table{table-layout:fixed;max-width:100%;}
    #tab-holidays .ps-hist-table tbody tr{
        display:grid;grid-template-columns:minmax(0,1fr) auto;
        align-items:start;gap:0 10px;padding:12px 14px;}
    #tab-holidays .ps-hist-table tbody td{border-top:none;padding:0;text-align:left;}
    #tab-holidays .ps-hist-table tbody td::before{content:none;}
    /* Date leads the item (it's a calendar list), then title + type pill */
    #tab-holidays .ps-hist-table tbody td[data-label="Date"]{
        grid-column:1/-1;display:block;margin-bottom:7px;
        white-space:normal !important;      /* beats the inline nowrap on long ranges */
        font-size:11px;font-weight:800;color:#6642aa;
        text-transform:uppercase;letter-spacing:.3px;}
    #tab-holidays .ps-hist-table tbody td[data-label="Event"]{
        grid-column:1;min-width:0;display:block;font-size:13.5px;line-height:1.35;}
    #tab-holidays .ps-hist-table tbody td[data-label="Type"]{
        grid-column:2;justify-self:end;}

    /* ── My DTR — tappable list item: text gets the full width, the status
       badge drops below it, and the chevron sits centred on the right. ── */
    #tab-mydtr .mydtr-card{
        display:grid;grid-template-columns:minmax(0,1fr) auto;
        align-items:center;column-gap:10px;padding:14px 14px 14px 16px;}
    #tab-mydtr .mydtr-card-main{grid-column:1;grid-row:1;}
    #tab-mydtr .mydtr-card-side{
        grid-column:1;grid-row:2;align-items:flex-start;margin-top:9px;}
    #tab-mydtr .mydtr-chev{grid-column:2;grid-row:1/span 2;font-size:22px;}
    #tab-mydtr .mydtr-period{font-size:14.5px;}

    /* ── Loans — roomier item, key figures as a 3-up chip row ── */
    #tab-loans .loan-c{padding:15px 16px;}
    #tab-loans .loan-type-lbl{font-size:13.5px;line-height:1.25;}
    #tab-loans .loan-bal-val{font-size:19px;line-height:1.15;}
    #tab-loans .loan-meta{gap:6px;margin-top:2px;}
    #tab-loans .loan-meta .lm-i{
        align-items:center;text-align:center;
        background:#f9f8fb;border:1px solid #f0eff3;border-radius:12px;padding:8px 4px;}
    #tab-loans .loan-meta .lm-i em{font-size:8.5px;}
    #tab-loans .loan-meta .lm-i b{font-size:12px;}
    #tab-loans .loan-est{
        margin-top:10px;padding-top:9px;border-top:1px solid #f4f3f6;font-size:11.5px;}
    /* Bigger chevron + hint on touch — the card is the tap target for the
       deduction-history sheet. */
    #tab-loans .loan-chev{font-size:23px;}
    #tab-loans .loan-tap-hint{font-size:11px;}
    #tab-loans .loan-est + .loan-tap-hint{margin-top:6px;}

    /* ── Contributions — same chip + total-band language as the loan item
       and the payslip review sheet, so the three tabs read as one system ── */
    #tab-contrib .con-tbl tbody tr{gap:6px;padding:12px 13px;}
    #tab-contrib .con-tbl tbody td[data-label="Contributions"],
    #tab-contrib .con-tbl tbody td[data-label="SSS Provident"],
    #tab-contrib .con-tbl tbody td[data-label="Tax"]{
        gap:3px;padding:8px 4px;border-top:none;
        background:#f9f8fb;border:1px solid #f0eff3;border-radius:12px;}
    /* Full-bleed total band: the parent is a flex container, so the basis has
       to grow by the row's 13px side padding — negative margins alone only
       shift the box, they don't widen it. */
    #tab-contrib .con-tbl tbody td[data-label="Total"]{
        flex:0 0 calc(100% + 26px);
        margin:8px -13px -12px;padding:11px 13px;background:#f8f6fb;
        border-top:1px solid #f0eff3;border-radius:0 0 12px 12px;}

    /* ── Contributions (.con-tbl) — remittance card ──
       The ID beats the desktop `.con-tbl{min-width:560px}` further down the
       sheet, which otherwise wins on source order and forces the "cards" to
       560px inside a 364px scroller. */
    .con-tbl, #tab-contrib .con-tbl{min-width:0;width:100%;border-collapse:separate;border-spacing:0;}
    .con-tbl thead{display:none;}
    .con-tbl tbody, .con-tbl tbody tr{display:block;width:100%;}
    .con-tbl tbody tr{
        display:flex;flex-wrap:wrap;background:#ffffff;border:1px solid #e7e6ed;border-left:3px solid #6642aa;
        border-radius:14px;margin-bottom:10px;padding:12px 13px 6px;
        box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 20px -14px rgba(58,40,93,.28);}
    .con-tbl tbody td{border-top:none;padding:0;}
    .con-tbl tbody td[data-label="Pay Period"]{flex:0 0 100%;display:block;text-align:left;padding-bottom:10px;}
    .con-tbl tbody td[data-label="Contributions"],
    .con-tbl tbody td[data-label="SSS Provident"],
    .con-tbl tbody td[data-label="Tax"]{
        flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;
        padding:9px 4px;border-top:1px solid #f0eff3;text-align:center;font-size:12px;}
    .con-tbl tbody td[data-label="Contributions"]::before,
    .con-tbl tbody td[data-label="SSS Provident"]::before,
    .con-tbl tbody td[data-label="Tax"]::before{
        content:attr(data-label);display:block;font-size:8.5px;font-weight:800;color:#8f8c98;
        text-transform:uppercase;letter-spacing:.2px;}
    .con-tbl tbody td[data-label="Total"]{
        flex:0 0 100%;display:flex;justify-content:space-between;align-items:center;gap:12px;
        padding:9px 0;border-top:1px solid #f0eff3;text-align:right;font-size:14px;}
    .con-tbl tbody td[data-label="Total"]::before{
        content:"Total";font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
    /* Lifetime totals footer becomes its own gradient summary card */
    .con-tbl tfoot, .con-tbl tfoot tr{display:block;width:100%;}
    .con-tbl tfoot tr{background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:12px;padding:2px 13px;margin-top:2px;}
    .con-tbl tfoot td{display:flex;justify-content:space-between;align-items:center;gap:12px;
        color:#fff !important;background:transparent;border-top:1px solid rgba(255,255,255,.18);padding:8px 0;font-size:12px;}
    .con-tbl tfoot td:first-child{border-top:none;justify-content:flex-start;font-size:12px;font-weight:800;}
    .con-tbl tfoot td::before{content:attr(data-label);font-size:9.5px;font-weight:800;
        color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.4px;}
    .con-tbl tfoot td:first-child::before{content:none;}
    /* DTR review modal total row (uses <th> instead of <td>) */
    .drev-tbl tfoot, .drev-tbl tfoot tr{display:block;width:100%;}
    .drev-tbl tfoot tr{background:#eeecf5;border-radius:10px;padding:2px 12px;margin-top:6px;}
    .drev-tbl tfoot th{display:flex;justify-content:space-between;align-items:center;gap:12px;
        text-align:left;padding:7px 0;border-top:1px solid #e3e0ec;color:#4e3483;}
    .drev-tbl tfoot th:first-child{border-top:none;justify-content:flex-start;}
    .drev-tbl tfoot th[data-label]:not([data-label=""])::before{content:attr(data-label);font-size:9.5px;
        font-weight:800;color:#9187aa;text-transform:uppercase;letter-spacing:.4px;}
    .drev-tbl tfoot th[data-label=""]{display:none;}

    /* Review modals: sticky, thumb-friendly action bar on phones */
    #modal-dtr-review .modal-footer, #modal-payroll-review .modal-footer{
        position:sticky;bottom:0;box-shadow:0 -4px 14px rgba(0,0,0,.08);z-index:5;}
    /* Same action-bar diet as the payslip sheet: the comment collapses to one
       line until tapped and the two decisions share a single row, instead of
       an 84px textarea over two stacked buttons eating a third of the screen. */
    #dtr-review-footer{gap:10px;}
    #dtr-review-footer textarea.form-control{
        min-height:46px;height:46px;padding:12px 14px;resize:none;}
    #dtr-review-footer textarea.form-control:focus{height:104px;}
    #modal-dtr-review .modal-footer .d-flex{flex-direction:row;gap:10px;}
    #modal-dtr-review .modal-footer .btn{
        width:auto;min-height:50px;padding:13px 10px;font-size:14.5px;
        border-radius:14px;display:flex;align-items:center;justify-content:center;gap:6px;}
    #modal-dtr-review .modal-footer .d-flex .btn:first-child{flex:0 0 36%;}
    #modal-dtr-review .modal-footer .d-flex .btn:last-child{flex:1 1 auto;}
    /* "Confirm — Looks Correct" won't fit beside Dispute; drop the tail */
    #modal-dtr-review .prv-btn-long{display:none;}

    /* ── Payslip review sheet — mobile card relayout ─────────────────────
       Body: a stack of discrete cards (stats chips → Earnings → Deductions →
       Net Pay) instead of one wide table + a split two-column card.
       Footer: compact comment field + one row of thumb-sized actions, so the
       action bar stops eating a third of the sheet. ── */

    /* Prior-decision / HR-reply notes read as cards, not thin strips */
    #payroll-review-body .drev-prev{
        border-radius:14px;padding:12px 14px;font-size:12.5px;line-height:1.45;
        align-items:flex-start;margin-bottom:12px;}
    #payroll-review-body .drev-prev i{font-size:16px;line-height:1.3;flex:0 0 auto;}

    /* Attendance strip: 6-col table → 3×2 grid of stat chips (~110px tall
       instead of ~240px of stacked label/value rows) */
    #payroll-review-body .prev-stats .drev-tbl tbody tr{
        display:grid;grid-template-columns:repeat(3,1fr);gap:8px;
        background:transparent;border:none;border-left:none;border-radius:0;
        box-shadow:none;padding:0;margin:0;}
    #payroll-review-body .prev-stats .drev-tbl tbody td{
        display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
        background:#fff;border:1px solid #e7e6ed;border-radius:14px;
        box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 20px -14px rgba(58,40,93,.28);
        min-height:56px;padding:9px 6px;
        font-size:14px;font-weight:800;color:#4e3483;text-align:center;}
    #payroll-review-body .prev-stats .drev-tbl tbody td::before{
        content:attr(data-label);order:-1;font-size:8.5px;font-weight:800;color:#8f8c98;
        text-transform:uppercase;letter-spacing:.3px;text-align:center;}

    /* Earnings / Deductions / Net Pay → three separate stacked cards.
       The wrapper .ps-card becomes a transparent container so each section
       carries its own surface, radius and shadow. */
    #payroll-review-body .ps-card{
        background:transparent;border:none;border-radius:0;box-shadow:none;overflow:visible;}
    #payroll-review-body .ps-body{grid-template-columns:1fr;gap:12px;}
    #payroll-review-body .ps-col,
    #payroll-review-body .ps-col:first-child{
        background:#fff;border:1px solid #e7e6ed;border-radius:16px;padding:14px 16px 0;
        box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);}
    /* Section heading: bigger, with a colour dot inheriting .earn / .ded */
    #payroll-review-body .ps-col-title{
        display:flex;align-items:center;gap:7px;
        font-size:11px;letter-spacing:.6px;
        padding-bottom:9px;margin-bottom:4px;border-bottom:1px solid #f2f1f5;}
    #payroll-review-body .ps-col-title::before{
        content:'';width:8px;height:8px;border-radius:50%;background:currentColor;flex:0 0 auto;}
    #payroll-review-body .ps-row{padding:9px 0;}
    #payroll-review-body .ps-lbl{font-size:12.5px;}
    #payroll-review-body .ps-val{font-size:13px;}
    /* Each card's total (Gross Pay / Total Deductions) becomes a tinted band
       bleeding to the card edges — the card's own footer row */
    #payroll-review-body .ps-col .ps-row:last-child{
        margin-left:-16px;margin-right:-16px;padding:12px 16px;
        border-top:1px solid #f0eff3;border-bottom:none;border-radius:0 0 15px 15px;
        background:#f9f8fb;}
    /* …and the row above it drops its rule, so the band edge isn't doubled */
    #payroll-review-body .ps-col .ps-row:nth-last-child(2){border-bottom:none;}
    /* Net pay: its own gradient card */
    #payroll-review-body .ps-net{
        margin-top:12px;border-radius:18px;padding:16px 18px;}
    #payroll-review-body .ps-net-val{font-size:22px;}

    /* Action bar: comment collapses to one line until tapped */
    #payroll-review-footer{gap:10px;}
    #payroll-review-footer textarea.form-control{
        min-height:46px;height:46px;padding:12px 14px;resize:none;}
    #payroll-review-footer textarea.form-control:focus{height:104px;}
    /* Decision buttons sit side by side: narrow secondary + wide primary */
    #modal-payroll-review .modal-footer .prv-review-only.d-flex{
        flex-direction:row;gap:10px;}
    #modal-payroll-review .modal-footer .btn{
        width:auto;min-height:50px;padding:13px 10px;font-size:14.5px;
        border-radius:14px;display:flex;align-items:center;justify-content:center;gap:6px;}
    #modal-payroll-review .modal-footer .prv-review-only .btn:first-child{flex:0 0 36%;}
    #modal-payroll-review .modal-footer .prv-review-only .btn:last-child{flex:1 1 auto;}
    /* "Confirm — Looks Correct" won't fit beside Dispute; drop the tail */
    #modal-payroll-review .prv-btn-long{display:none;}
    /* Read-only (closed payroll): note above, full-width PDF button below */
    #prv-readonly{flex-direction:column;align-items:stretch !important;gap:8px;}
    #prv-readonly span{text-align:center;}
    #prv-readonly .btn{width:100%;}
}

/* DataTables chrome — pared down to fit the paper theme */
#att-tbl_wrapper .dataTables_processing{background:#ffffff;color:#6642aa;font-weight:700;font-size:12px;border:none;box-shadow:none;}
#att-tbl_wrapper .dataTables_info{font-size:11px;color:#aaa;padding:10px 14px;}
#att-tbl_wrapper .dataTables_paginate{padding:8px 14px;}
#att-tbl_wrapper .dataTables_paginate .paginate_button{padding:4px 10px;margin-left:3px;border-radius:7px;font-size:11px;border:1px solid transparent !important;background:transparent !important;color:#888 !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button:hover{background:#f2f1f5 !important;color:#4e3483 !important;border:1px solid transparent !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button.current{background:linear-gradient(135deg,#6642aa,#4e3483) !important;color:#fff !important;border:none !important;}
#att-tbl_wrapper .dataTables_paginate .paginate_button.disabled{opacity:.4;}
#att-tbl thead th{white-space:nowrap;}

/* Date-range picker trigger */
.att-range-picker{display:flex;align-items:center;gap:6px;width:210px;flex-shrink:0;padding:5px 11px;border:1px solid #d9d3e4;border-radius:8px;background:#fafafd;font-size:12px;font-weight:600;color:#4e3483;cursor:pointer;transition:border-color .15s,box-shadow .15s;}
.att-range-picker:hover{border-color:#6642aa;}
.att-range-picker i:first-child{color:#6642aa;flex-shrink:0;}
/* Fixed-width trigger: the label truncates instead of stretching the box */
.att-range-picker #att-range-label{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.att-range-picker i:last-child{flex-shrink:0;margin-left:0 !important;}
/* bootstrap-datetimepicker (leave / LWOP dates) — brand purple + finger-sized */
.bootstrap-datetimepicker-widget{font-size:13px;border-radius:16px;box-shadow:0 12px 34px rgba(58,40,93,.2);border:1px solid #e7e6ed;padding:10px;}
.bootstrap-datetimepicker-widget table td.day{height:34px;line-height:34px;width:36px;border-radius:10px;color:#312f38;transition:background .12s;}
.bootstrap-datetimepicker-widget table th{height:34px;border-radius:10px;color:#4e3483;}
.bootstrap-datetimepicker-widget table th.picker-switch{font-weight:800;font-size:14px;}
.bootstrap-datetimepicker-widget table th.prev,
.bootstrap-datetimepicker-widget table th.next{width:38px;border-radius:50%;color:#6642aa;font-size:17px;}
.bootstrap-datetimepicker-widget table th.dow{color:#8f8c98;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;height:26px;}
.bootstrap-datetimepicker-widget table td.active,
.bootstrap-datetimepicker-widget table td.active:hover{
    background:linear-gradient(135deg,#6642aa,#4e3483) !important;color:#fff !important;
    text-shadow:none;box-shadow:0 3px 8px rgba(102,66,170,.35);font-weight:800;}
.bootstrap-datetimepicker-widget table td.today:not(.active){color:#4e3483;font-weight:800;}
.bootstrap-datetimepicker-widget table td.today:before{border-bottom-color:#6642aa;}
.bootstrap-datetimepicker-widget table td.disabled,
.bootstrap-datetimepicker-widget table td.disabled:hover{color:#cfcdd5;text-decoration:line-through;}
.bootstrap-datetimepicker-widget table td.day:hover,
.bootstrap-datetimepicker-widget table th:hover{background:#eeeaf5;}
/* month / year / decade grids share the brand look */
.bootstrap-datetimepicker-widget table td span{border-radius:10px;}
.bootstrap-datetimepicker-widget table td span.active{background:linear-gradient(135deg,#6642aa,#4e3483);text-shadow:none;}
.bootstrap-datetimepicker-widget table td span:hover{background:#eeeaf5;}
/* Toolbar: Clear / Done become labelled pill buttons */
.bootstrap-datetimepicker-widget a[data-action]{
    display:flex;align-items:center;justify-content:center;
    padding:9px 6px;margin:6px 3px 2px;border-radius:10px;width:auto;
    text-decoration:none;cursor:pointer;transition:opacity .12s;}
.bootstrap-datetimepicker-widget a[data-action]:active{opacity:.8;}
.bootstrap-datetimepicker-widget a[data-action] span{
    display:inline-flex;align-items:center;width:auto;height:auto;line-height:1;margin:0;font-size:15px;}
/* Attendance row/card badge: this date has a conversation with HR */
.att-msg-badge {
    display:inline-flex; align-items:center; gap:3px; margin-left:6px;
    padding:1px 6px; border-radius:10px; font-size:9px; font-weight:800;
    background:#fff3e0; color:#c98a00; border:1px solid #ffe0a3; vertical-align:1px;
}
.att-msg-badge i { font-size:10px; }

/* Thread header (title + refresh) shared by DTR review & attendance details */
.drev-thread-hd{display:flex;align-items:center;justify-content:space-between;margin:0 2px 4px;}
.drev-refresh{width:28px;height:28px;flex-shrink:0;border:1px solid #ded9e8;background:#f3f2f7;color:#4e3483;border-radius:8px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;padding:0;transition:background .15s;}
.drev-refresh:hover{background:#e7e4f0;}
.drev-refresh.spinning i{animation:ptop-spin .7s linear infinite;display:inline-block;}
.drev-thread-empty{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 14px;color:#948ea5;}
.drev-thread-empty i{font-size:30px;color:#cac7d7;margin-bottom:7px;}
.drev-thread-empty .det{font-size:12.5px;font-weight:800;color:#69617f;}
.drev-thread-empty .des{font-size:11px;color:#a6a2b4;margin-top:3px;line-height:1.4;max-width:230px;}
.drev-send.sending{opacity:.8;cursor:default;}
.drev-send.sending i{animation:ptop-spin .7s linear infinite;display:inline-block;}

/* DTR review — per-day conversation threads with HR */
.drev-msgs { margin-top: 12px; }
.drev-thread { background:#fff; border:1px solid #f0eff3; border-radius:12px; padding:10px 12px; margin-top:8px; }
.drev-thread-date { font-size:11px; font-weight:800; color:#4e3483; margin-bottom:6px; }
.drev-thread-date i { color:#6642aa; }
.drev-thread-list { display:flex; flex-direction:column; gap:5px; max-height:180px; overflow-y:auto; }
.drev-bub { max-width:85%; padding:6px 10px; border-radius:11px; font-size:12px; line-height:1.35; word-break:break-word; }
.drev-bub.me   { align-self:flex-end; background:#e1dcec; color:#4f3288; border-bottom-right-radius:3px; }
.drev-bub.them { align-self:flex-start; background:#f1f3f2; color:#312f38; border-bottom-left-radius:3px; }
.drev-bub .mm { font-size:9px; opacity:.7; margin-top:2px; }
/* Pinned composer bar — always at the bottom of the message screen */
.drev-composer { flex-shrink:0; background:#fff; border-top:1px solid #f0eff3;
    padding:10px 14px; box-shadow:0 -4px 14px rgba(58,40,93,.06); }
.drev-composer-to { font-size:10.5px; font-weight:700; color:#8f8c98; margin:0 4px 6px; }
.drev-composer-to b { color:#4e3483; }
.drev-composer-to i { color:#6642aa; }
.drev-composer-hint { font-size:10px; color:#a9a6b1; margin:6px 4px 0; }
.drev-composer-hint i { color:#cac7d7; margin-right:3px; }
/* Nothing to reply to → no composer at all */
#modal-dtr-messages.is-empty .drev-composer { display:none; }
/* Which conversation the composer is addressing */
.drev-thread { cursor:pointer; }
.drev-thread.is-active { border-color:#b9add5; box-shadow:0 0 0 2px rgba(102,66,170,.13); }

/* Empty message screen */
.drev-msg-empty { text-align:center; padding:38px 26px; }
.drev-msg-empty .dme-ic { width:64px; height:64px; margin:0 auto 14px; border-radius:50%;
    background:#f0ecf6; color:#6642aa; font-size:30px;
    display:flex; align-items:center; justify-content:center; }
.drev-msg-empty .dme-t { font-size:15px; font-weight:800; color:#4e3483; }
.drev-msg-empty .dme-d { font-size:12.5px; color:#7f7c88; line-height:1.5; margin:6px auto 0; max-width:280px; }
.drev-msg-empty .dme-hint { font-size:11.5px; color:#8f8c98; line-height:1.5;
    background:#fff; border:1px solid #f0eff3; border-radius:12px;
    padding:11px 13px; margin:18px auto 0; max-width:320px; text-align:left; }
.drev-msg-empty .dme-hint i { color:#6642aa; margin-right:5px; }

/* Composer: one pill holding the field and the send button, like a chat app */
.drev-thread-in { display:flex; align-items:center; gap:6px; margin-top:10px;
    border:1px solid #ddd9e7; border-radius:999px; background:#fff;
    padding:4px 4px 4px 6px; transition:border-color .15s, box-shadow .15s; }
.drev-thread-in:focus-within { border-color:#6642aa; box-shadow:0 0 0 3px rgba(102,66,170,.12); }
.drev-thread-in input { flex:1; min-width:0; border:none; background:transparent;
    border-radius:999px; padding:7px 8px; font-size:12.5px; outline:none; }
.drev-thread-in button { position:relative; width:34px; height:34px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    border:none; border-radius:50%; background:#6642aa; color:#fff; cursor:pointer;
    transition:background .15s; }
.drev-thread-in button:hover { background:#4e3483; }
.drev-thread-in button:disabled { cursor:default; }
/* Sending: the paper plane swaps for a spinner and the button locks */
.drev-thread-in button.is-loading { background:#a99cc5; }
.drev-thread-in button.is-loading i { visibility:hidden; }
.drev-thread-in button.is-loading::after { content:''; position:absolute;
    width:15px; height:15px; border-radius:50%;
    border:2px solid rgba(255,255,255,.45); border-top-color:#fff;
    animation:drevSpin .6s linear infinite; }
@keyframes drevSpin { to { transform:rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
    .drev-thread-in button.is-loading::after { animation-duration:1.6s; }
}
/* Form 48 card: the record grid scrolls sideways WITHIN the card. Without the
   wrapper the table (min ~340px of fixed columns) spills over the card's right
   padding and border on narrow screens. */
.drev-f48-card{background:#fff;border:1px solid #f0eff3;border-radius:12px;padding:16px 18px;overflow:hidden;}
.drev-f48-wrap{overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;
    overscroll-behavior-x:contain;}
.drev-f48-wrap .dtrf48{min-width:340px;}
/* Floating message button — sticks to the bottom of the DTR sheet's scroll
   area. height + equal negative margin means it adds no scroll length. */
.drev-fab-wrap{position:sticky;bottom:8px;z-index:6;display:flex;justify-content:flex-end;
    height:54px;margin-top:-54px;pointer-events:none;}
.drev-fab{pointer-events:auto;position:relative;width:52px;height:52px;flex-shrink:0;
    border:none;border-radius:50%;background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;
    font-size:23px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;
    box-shadow:0 6px 18px rgba(58,40,93,.32), 0 2px 5px rgba(58,40,93,.2);}
.drev-fab:hover{background:linear-gradient(135deg,#5e3e9f,#472f79);}
.drev-fab:active{transform:scale(.94);}
.drev-fab-badge{position:absolute;top:-3px;right:-3px;min-width:19px;height:19px;line-height:19px;
    padding:0 5px;border-radius:10px;background:#e8590c;color:#fff;
    font-size:10px;font-weight:800;border:2px solid #fff;}
/* ── Modal headers: one clean treatment everywhere ──────────────────────
   White surface, dark purple title, muted sub-line, hairline rule. No filled
   or gradient header bars anywhere in the portal. */
.modal .modal-header{
    background:#fff;
    border-bottom:1px solid #f0eff3;
    color:#4e3483;}
.modal .modal-header .modal-title{
    color:#4e3483;font-weight:700;}
.modal .modal-header .modal-title i{color:#6642aa;}
.modal .modal-header .btn-close{filter:none;opacity:.5;}
.modal .modal-header .btn-close:hover{opacity:.85;}

/* ── Floating message portal ────────────────────────────────────────────
   A normal solid panel — only the dimming overlay is gone (opened with
   Bootstrap's backdrop:false, see openDtrMessages) and it's inset from the
   edges rather than full-bleed, so it floats over the DTR sheet. */
/* Stacked above #modal-dtr-review (which the portal pins to z-index 2000) */
#modal-dtr-messages{z-index:2020 !important;background:transparent;}
#modal-dtr-messages .modal-dialog{
    height:calc(100dvh - 32px);margin:16px auto;align-items:stretch;
    max-width:560px;width:calc(100% - 32px);}
#modal-dtr-messages .modal-content{
    height:100%;max-height:100%;display:flex;flex-direction:column;
    background:#fff;border:0;border-radius:18px;overflow:hidden;
    box-shadow:0 18px 48px rgba(45,31,76,.28), 0 2px 8px rgba(45,31,76,.14);}
#modal-dtr-messages .modal-header{
    flex-shrink:0;align-items:center;gap:10px;}
#modal-dtr-messages .dtr-msg-titlewrap{min-width:0;}
#modal-dtr-messages .modal-title{font-size:15px;}
#modal-dtr-messages #dtr-msg-sub{font-size:12px;color:#908c9c;}
#modal-dtr-messages .btn-close{flex-shrink:0;margin:0;padding:12px;}
#modal-dtr-messages .modal-body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;
    display:flex;flex-direction:column;background:#f9f8fb;}
/* Conversations hug their content and rest against the composer — they float
   on the scrim rather than filling a solid panel. margin-top:auto (not
   justify-content:flex-end) keeps an overflowing top reachable. */
#modal-dtr-messages .drev-stream{flex:0 0 auto;margin-top:auto;display:flex;flex-direction:column;gap:10px;}
#modal-dtr-messages .drev-stream .drev-thread{margin-top:0;}
#modal-dtr-messages .drev-thread-list{max-height:none;}
/* Nothing to show → centre the empty state in the whole area */
#modal-dtr-messages .drev-msg-empty{margin:auto;}

/* clock-timepicker (File a Request claimed times) — brand purple accent.
   The picker itself works in 24h (its only unambiguous mode); an invisible
   overlay input catches the taps while .ctp-display shows friendly 12-hour
   text ("8:00 PM") and the named picker input carries the HH:mm value. */
clock-timepicker{
    display:block;width:100%;
    --clock-timepicker-accent-color:#6642aa;
    --clock-timepicker-popup-border-radius:16px;
    --clock-timepicker-popup-shadow:0 12px 34px rgba(58,40,93,.2);
    --clock-timepicker-font-family:inherit;
}
.ctp-12h{position:relative;}
.ctp-12h clock-timepicker{position:absolute;inset:0;}
.ctp-12h clock-timepicker input{width:100%;height:100%;opacity:0;border:0;padding:0;background:transparent;}
.ctp-12h:focus-within .ctp-display{border-color:#6642aa;box-shadow:0 0 0 .25rem rgba(102,66,170,.15);}
.bootstrap-datetimepicker-widget a[data-action] span::after{
    font-family:'Segoe UI',-apple-system,Arial,sans-serif;font-size:12.5px;font-weight:800;margin-left:6px;line-height:1;}
.bootstrap-datetimepicker-widget a[data-action="clear"]{background:#fdecea;color:#c62828;}
.bootstrap-datetimepicker-widget a[data-action="clear"] span::after{content:"Clear";}
.bootstrap-datetimepicker-widget a[data-action="close"]{background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;box-shadow:0 3px 10px rgba(102,66,170,.3);}
.bootstrap-datetimepicker-widget a[data-action="close"] span::after{content:"Done";}
/* Leave-day picker: enlarged, roomy full-height calendar. The Clear / Done
   footer actions stay visible (styled as pill buttons above). */
.bootstrap-datetimepicker-widget{min-width:340px;padding:14px;}
.bootstrap-datetimepicker-widget .table-condensed{width:100%;}
.bootstrap-datetimepicker-widget table td.day{height:46px;line-height:46px;width:46px;}
.bootstrap-datetimepicker-widget table th{height:40px;}
.bootstrap-datetimepicker-widget table th.dow{height:30px;}

/* daterangepicker theme override → brand purple */
.daterangepicker td.active,.daterangepicker td.active:hover{background-color:#6642aa !important;}
.daterangepicker td.in-range{background-color:#eeeaf5 !important;color:#4e3483 !important;}
.daterangepicker .ranges li.active{background-color:#6642aa !important;}
.daterangepicker .drp-buttons .btn.applyBtn{background-color:#6642aa !important;border-color:#4e3483 !important;}
.daterangepicker td.start-date,.daterangepicker td.end-date{background-color:#4e3483 !important;}

/* Loan cards */
.loan-c{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);padding:16px 18px;margin-bottom:10px;}
.loan-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.loan-type-lbl{font-size:12px;font-weight:800;color:#4e3483;}
.loan-bal-val{font-size:18px;font-weight:900;color:#e83e8c;}
.loan-progwrap{display:flex;align-items:center;gap:9px;margin-bottom:10px;}
.loan-prog{flex:1 1 auto;height:7px;border-radius:4px;background:#f0f0f0;overflow:hidden;}
.loan-prog-bar{height:100%;border-radius:4px;background:linear-gradient(90deg,#6642aa,#4e3483);}
.loan-pct{flex:0 0 auto;font-size:10.5px;font-weight:800;color:#4e3483;}
/* Key figures as a labelled 3-up — the old single meta line ran the paid /
   total / per-period numbers together and collided on narrow screens. */
.loan-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:11px;color:#888;}
.loan-meta .lm-i{display:flex;flex-direction:column;gap:2px;min-width:0;}
.loan-meta .lm-i em{font-style:normal;font-size:9px;font-weight:800;color:#a9a6b1;
    text-transform:uppercase;letter-spacing:.3px;}
.loan-meta .lm-i b{font-size:12.5px;font-weight:800;color:#312f38;}
.loan-est{font-size:11px;color:#6642aa;font-weight:700;margin-top:9px;}

/* Tap-to-open affordance — the whole card is the hit target, matching the
   payslip / leave / attendance lists. The chevron sits beside the balance so
   the head row still reads type-left / money-right. */
.loan-c.loan-tap{cursor:pointer;-webkit-tap-highlight-color:transparent;
    transition:box-shadow .15s, transform .12s, border-color .15s;}
.loan-c.loan-tap:hover{border-color:#d8d3e4;box-shadow:0 1px 2px rgba(58,40,93,.06), 0 12px 26px -12px rgba(58,40,93,.26);}
.loan-c.loan-tap:active{transform:scale(.995);}
.loan-c.loan-tap:focus-visible{outline:2px solid #6642aa;outline-offset:2px;}
.loan-bal-wrap{display:flex;align-items:center;gap:6px;}
.loan-chev{font-size:20px;color:#c9c5d4;line-height:1;flex:0 0 auto;margin-right:-4px;}
.loan-tap-hint{font-size:10.5px;color:#a9a6b1;font-weight:700;margin-top:9px;
    display:flex;align-items:center;gap:4px;}

/* ── Loan details modal: per-payroll deduction history ── */
.lnh-sum{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;}
.lnh-sum .lnh-box{background:#f9f8fb;border:1px solid #f0eff3;border-radius:10px;padding:9px 12px;}
.lnh-sum .lnh-box em{font-style:normal;display:block;font-size:9px;font-weight:800;color:#a9a6b1;
    text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;}
.lnh-sum .lnh-box b{font-size:14px;font-weight:900;color:#312f38;}
.lnh-sum .lnh-box.bal b{color:#e83e8c;}
.lnh-hd{font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;
    display:flex;align-items:center;justify-content:space-between;margin:14px 0 6px;}
.lnh-list{display:flex;flex-direction:column;gap:7px;}
.lnh-row{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #eceaf0;
    border-radius:10px;padding:9px 12px;}
.lnh-row .lnh-l{flex:1 1 auto;min-width:0;}
.lnh-row .lnh-per{font-size:12.5px;font-weight:800;color:#312f38;line-height:1.25;}
.lnh-row .lnh-ref{font-size:10.5px;color:#a9a6b1;font-weight:700;margin-top:1px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lnh-row .lnh-r{flex:0 0 auto;text-align:right;}
.lnh-row .lnh-amt{font-size:13px;font-weight:900;color:#4e3483;}
.lnh-row .lnh-bal{font-size:10px;color:#8f8c98;font-weight:700;margin-top:1px;}
.lnh-empty,.lnh-load{text-align:center;color:#a9a6b1;font-size:12px;padding:18px 10px;}
.lnh-empty i,.lnh-load i{display:block;font-size:24px;margin-bottom:6px;color:#d3d1db;}
.lnh-load i{animation:ptop-spin .8s linear infinite;}
.lnh-err{background:#fff0f0;color:#dc3545;border-radius:10px;padding:9px 12px;font-size:12px;}

/* DTR time chips — matches dtr-details.php */
.dtr-time-chip{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;font-family:'Segoe UI',Arial,sans-serif;white-space:nowrap;}
.dtr-time-chip.in {background:#eeeaf5;color:#6642aa;border:1px solid #c0b5d5;}
.dtr-time-chip.out{background:#fce4ec;color:#c62828;border:1px solid #f9a8b5;}
.dtr-time-chip.na {background:#f5f5f5;color:#888;border:1px solid #ddd;}
.dtr-logs-pill{display:inline-flex;align-items:center;gap:1px;cursor:pointer;line-height:1.3;margin-top:4px;}
.dtr-logs-count{font-size:10px;color:#6642aa;font-weight:700;text-decoration:underline;text-decoration-style:dotted;white-space:nowrap;}
.dtr-log-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;}
.dtr-log-chip.bio   {background:#eeeaf5;color:#6642aa;border:1px solid #c0b5d5;}
.dtr-log-chip.manual{background:#fff8e1;color:#c98a00;border:1px solid #ffe082;}
.time-io{display:flex;align-items:center;gap:5px;flex-wrap:nowrap;}

/* Today's attendance card (Overview) */
.today-att{background:#ffffff;border:1px solid #e7e6ed;border-top:3px solid #6642aa;border-radius:14px;
    box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);
    padding:13px 16px 14px;margin-bottom:16px;}
.today-att-head{display:flex;align-items:center;gap:8px;margin-bottom:11px;}
.today-att-title{display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:800;color:#4e3483;}
.today-att-title i{color:#6642aa;font-size:15px;}
.today-att-date{font-size:10.5px;font-weight:700;color:#8f8c98;}
.today-att-head .att-type{margin-left:auto;}
.today-att-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.tda-box{display:flex;flex-direction:column;align-items:center;gap:3px;text-align:center;
    background:#faf8f1;border:1px solid #f0eff3;border-radius:12px;padding:11px 6px 10px;}
.tda-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:2px;}
.tda-ic.in {background:#eeeaf5;color:#6642aa;}
.tda-ic.out{background:#fce4ec;color:#c62828;}
.tda-ic.hrs{background:#eef0f8;color:#4a5bbf;}
.tda-ic.ot {background:#fff6e0;color:#c98a00;}
.tda-l{font-size:9px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;}
.tda-v{font-size:14px;font-weight:900;color:#4e3483;line-height:1.15;}
.tda-duty{display:inline-block;background:#eeeaf5;color:#6642aa;border:1px solid #c0b5d5;border-radius:10px;
    padding:2px 9px;font-size:10px;font-weight:800;}
.today-att-empty{display:flex;align-items:center;gap:9px;font-size:12px;color:#7f7c88;font-weight:600;
    background:#faf8f1;border:1px dashed #e0d8c4;border-radius:12px;padding:12px 14px;line-height:1.45;}
.today-att-empty i{font-size:19px;color:#b7b1a4;flex-shrink:0;}
@media (max-width:767.98px), (pointer:coarse) and (max-height:500px){
    .today-att-grid{grid-template-columns:repeat(2,1fr);}
    .tda-v{font-size:15px;}
}

/* Year-to-date strip */
.ytd-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.ytd-box{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);padding:14px 16px;border-top:3px solid #6642aa;}
.ytd-box.g{border-top-color:#6642aa;}
.ytd-box.d{border-top-color:#dc3545;}
.ytd-box.n{border-top-color:#4e3483;}
.ytd-box.c{border-top-color:#6f42c1;}
.ytd-val{font-size:18px;font-weight:900;line-height:1;color:#4e3483;}
.ytd-box.d .ytd-val{color:#dc3545;}
.ytd-box.c .ytd-val{color:#6f42c1;}
.ytd-lbl{font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-top:5px;}
/* Subtle hover lift on stat + chart cards (style polish) */
.ytd-box{transition:transform .16s,box-shadow .16s;}
.ytd-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(58,40,93,.05), 0 14px 28px -14px rgba(58,40,93,.3);}

/* Pay Insights strip (Overview) */
.ins-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.ins-box{display:flex;align-items:center;gap:12px;background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;padding:13px 15px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);transition:transform .16s,box-shadow .16s;}
.ins-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(58,40,93,.05), 0 14px 28px -14px rgba(58,40,93,.28);}
.ins-ic{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;background:#f0ecf6;color:#6642aa;}
.ins-box.up .ins-ic{background:#eafaf0;color:#0f9d58;} .ins-box.down .ins-ic{background:#fdecea;color:#dc3545;}
.ins-box.gold .ins-ic{background:#fff6e0;color:#c98a00;} .ins-box.purple .ins-ic{background:#f2edfb;color:#6f42c1;}
.ins-v{font-size:16px;font-weight:900;line-height:1.05;color:#4e3483;}
.ins-box.up .ins-v{color:#0f9d58;} .ins-box.down .ins-v{color:#dc3545;}
.ins-l{font-size:10px;color:#8f8c98;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;font-weight:700;}
.ins-sub{font-size:10px;color:#b7b1a4;margin-top:1px;}

/* Contributions tab */
.con-hero{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.con-box{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);padding:14px 16px;border-top:3px solid #6642aa;transition:transform .16s,box-shadow .16s;}
.con-box:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(58,40,93,.05), 0 14px 28px -14px rgba(58,40,93,.28);}
.con-box.b2{border-top-color:#4a5bbf;} .con-box.b3{border-top-color:#b26a00;} .con-box.b4{border-top-color:#4e3483;}
.con-cap{font-size:10px;color:#8f8c98;text-transform:uppercase;letter-spacing:.4px;font-weight:700;display:flex;align-items:center;gap:5px;}
.con-val{font-size:19px;font-weight:900;line-height:1;color:#4e3483;margin-top:7px;}
.con-box.b2 .con-val{color:#4a5bbf;} .con-box.b3 .con-val{color:#b26a00;}
.con-note{font-size:10px;color:#b7b1a4;margin-top:5px;}
.con-intro{background:#f5f3fa;border:1px solid #cdeeda;border-radius:12px;padding:12px 15px;font-size:12.5px;color:#585272;line-height:1.5;margin-bottom:14px;}
.con-tbl{width:100%;min-width:560px;border-collapse:collapse;font-size:12px;}
.con-tbl thead th{background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;font-weight:700;text-align:left;border:none;}
.con-tbl thead th.r{text-align:right;}
.con-tbl tbody tr{border-bottom:1px solid #f2f1f5;transition:background .14s;}
.con-tbl tbody tr:hover{background:#f8f6fb;}
.con-tbl tbody td{padding:9px 12px;vertical-align:middle;}
.con-tbl tbody td.r{text-align:right;}
.con-tbl tfoot td{background:#f8f6fb;padding:9px 12px;font-weight:800;color:#6642aa;border-top:2px solid #e4e0ec;}
.con-tbl tfoot td.r{text-align:right;}

/* Card entrance animation on tab reveal (respects reduced-motion) */
@keyframes portalFadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.tab-panel.active>*{animation:portalFadeUp .38s ease;}
@media(prefers-reduced-motion:reduce){.tab-panel.active>*{animation:none;}}
@media (max-width:767.98px), (pointer:coarse) and (max-height:500px){.ins-strip,.con-hero{grid-template-columns:repeat(2,1fr);}}

/* Net-pay trend mini chart */
.trend-card{background:#ffffff;border:1px solid #e7e6ed;border-radius:14px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);padding:16px 18px 12px;margin-bottom:14px;}
.trend-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.trend-title{font-size:12px;font-weight:800;color:#4e3483;}
.trend-bars{display:flex;align-items:flex-end;gap:8px;height:120px;}
.trend-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:5px;}
.trend-amt{font-size:9px;font-weight:700;color:#6642aa;white-space:nowrap;}
.trend-bar{width:100%;max-width:30px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#724dba,#4e3483);min-height:4px;transition:opacity .15s;}
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
.qa-btn{display:flex;align-items:center;gap:10px;background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;padding:12px 14px;cursor:pointer;font-size:12px;font-weight:700;color:#4e3483;text-align:left;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);transition:transform .15s,box-shadow .15s;}
.qa-btn:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(58,40,93,.05), 0 14px 28px -14px rgba(58,40,93,.28);}
.qa-btn i{width:34px;height:34px;border-radius:9px;background:#f0ecf6;color:#6642aa;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.qa-badge{margin-left:auto;background:#e6a817;color:#fff;border-radius:10px;padding:1px 8px;font-size:10px;font-weight:800;}

/* Upcoming events (Overview) */
.evt-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px dashed #ece4d2;}
.evt-row:last-child{border-bottom:none;}
.evt-date{width:44px;flex-shrink:0;text-align:center;background:#f5f4f8;border:1px solid #e4e0ec;border-radius:9px;padding:4px 0;}
.evt-date .d{font-size:15px;font-weight:900;color:#4e3483;line-height:1.1;}
.evt-date .m{font-size:9px;font-weight:800;color:#6642aa;text-transform:uppercase;letter-spacing:.5px;}
.evt-title{font-size:12px;font-weight:700;color:#312f38;line-height:1.3;}
.evt-note{font-size:10.5px;color:#999;margin-top:1px;}
.evt-pill{margin-left:auto;flex-shrink:0;border-radius:10px;padding:2px 9px;font-size:10px;font-weight:700;}
.evt-pill.hol{background:#fff0f0;color:#c62828;}
.evt-pill.act{background:#e8f0ff;color:#0d6efd;}

/* Leave credit mini bars (Overview) */
.lvc-row{padding:7px 0;border-bottom:1px dashed #ece4d2;}
.lvc-row:last-child{border-bottom:none;}
.lvc-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;}
.lvc-name{font-size:11.5px;font-weight:700;color:#312f38;}
.lvc-num{font-size:11px;font-weight:800;color:#4e3483;}
.lvc-num .dim2{color:#aaa;font-weight:600;}
.lvc-bar{height:6px;border-radius:3px;background:#e7e3ee;overflow:hidden;}
.lvc-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#6642aa,#4e3483);}
.lvc-row.spent .lvc-fill{background:#dc3545;}

/* Sticky tab strip on desktop so navigation stays reachable on the wide page */
@media(min-width:768px){
    .tab-strip{position:sticky;top:62px;z-index:150;}
}

@media (max-width:767.98px), (pointer:coarse) and (max-height:500px){
    .ytd-strip{grid-template-columns:repeat(2,1fr);}
    .qa-strip{grid-template-columns:repeat(2,1fr);}
    .qa-btn{flex-direction:column;text-align:center;gap:6px;padding:10px 8px;font-size:11px;}
    .qa-badge{margin-left:0;}
}

/* Basic info grid */
.info-section{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);overflow:hidden;margin-bottom:14px;}
.info-sec-title{background:#6642aa;color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:8px 16px;display:flex;align-items:center;gap:7px;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;}
.info-item{padding:10px 16px;border-bottom:1px solid #f2f1f5;border-right:1px solid #f2f1f5;}
.info-item:last-child{border-right:none;}
.info-lbl{font-size:10px;color:#aaa;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.info-val{font-size:13px;font-weight:600;color:#222;}
.info-val.mono{font-family:monospace;font-size:12px;}
.info-val.accent{color:#6642aa;}

/* Login & Security — change-password form (My Info tab) */
.pw-warn{display:flex;align-items:flex-start;gap:8px;background:#fff6e5;border:1px solid #ffd591;color:#8a5a00;border-radius:10px;padding:9px 11px;font-size:12px;font-weight:600;line-height:1.35;margin:12px 0 4px;}
.pw-warn i{font-size:15px;line-height:1.2;}
.pw-field{margin-top:12px;}
.pw-field label{display:block;font-size:10px;color:#8b8796;font-weight:800;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.pw-input{position:relative;}
.pw-input input.form-control{width:100%;height:42px;padding:0 42px 0 12px;border:1px solid #d9d5e2;border-radius:10px;font-size:14px;color:#222;background:#fff;box-shadow:none;}
.pw-input input.form-control:focus{border-color:#6642aa;box-shadow:0 0 0 2px rgba(102,66,170,.15);outline:none;}
.pw-eye{position:absolute;top:0;right:0;width:42px;height:42px;border:none;background:transparent;color:#9d9ba4;font-size:17px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
.pw-eye:hover{color:#6642aa;}
.pw-meter{height:4px;border-radius:3px;background:#eeecf3;margin-top:6px;overflow:hidden;}
.pw-meter span{display:block;height:100%;width:0;border-radius:3px;background:#dc3545;transition:width .2s, background .2s;}
.pw-hint{font-size:11px;color:#98a2ad;margin-top:4px;}
.pw-hint.ok{color:#1e7e34;font-weight:700;}
.pw-hint.bad{color:#c62828;font-weight:700;}
.pw-save{margin-top:14px;width:100%;height:44px;border:none;border-radius:11px;background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-size:13px;font-weight:800;letter-spacing:.3px;display:flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 4px 14px -6px rgba(78,52,131,.65);cursor:pointer;}
.pw-save:disabled{opacity:.6;cursor:not-allowed;}

/* Empty state — uniform card across every tab */
.empty-state{text-align:center;padding:34px 22px;background:#ffffff;border:1px solid #e7e6ed;border-radius:14px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);}
.empty-ic{width:52px;height:52px;border-radius:50%;background:#f0ecf6;color:#6642aa;display:flex;align-items:center;justify-content:center;font-size:23px;margin:0 auto 12px;}
.empty-state p{font-size:12.5px;color:#7f7c88;margin:0;font-weight:600;line-height:1.5;}
.empty-state p strong{color:#312f38;}
.empty-state.success .empty-ic{background:#f0ecf6;color:#6642aa;}
.empty-state.success p{color:#4e3483;font-weight:800;}
.empty-state.warn .empty-ic{background:#fff3cd;color:#c98a00;}
.empty-state.warn p{color:#8a6d1a;}

/* bootstrap-select — make the dropdown + search box visible on the paper theme */
.bootstrap-select .dropdown-toggle{background:#fff !important;border:1px solid #d9d3e4 !important;color:#312f38 !important;font-size:13px;border-radius:8px;padding:7px 11px;box-shadow:none !important;}
.bootstrap-select .dropdown-toggle:focus{outline:none !important;border-color:#6642aa !important;box-shadow:0 0 0 2px rgba(102,66,170,.15) !important;}
.bootstrap-select .dropdown-menu{font-size:13px;border:1px solid #e7e6ed;box-shadow:0 8px 22px -10px rgba(58,40,93,.3);}
.bootstrap-select .dropdown-menu li a{color:#312f38;}
.bootstrap-select .dropdown-menu li.selected a,.bootstrap-select .dropdown-menu li a:hover{background:#eeeaf5 !important;color:#4e3483 !important;}
.bootstrap-select .bs-searchbox{padding:8px;}
.bootstrap-select .bs-searchbox .form-control{
    background:#fff !important;color:#312f38 !important;
    border:1px solid #d9d3e4 !important;border-radius:6px;font-size:13px;padding:6px 10px;
    -webkit-text-fill-color:#312f38;
}
.bootstrap-select .bs-searchbox .form-control::placeholder{color:#9d9ba4 !important;-webkit-text-fill-color:#9d9ba4;}
.bootstrap-select .bs-searchbox .form-control:focus{border-color:#6642aa !important;box-shadow:0 0 0 2px rgba(102,66,170,.15) !important;}

/* ── Help tab ── */
.help-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:16px;padding:20px 22px;color:#fff;margin-bottom:6px;box-shadow:0 10px 26px -14px rgba(78,52,131,.6);}
.help-hero-ic{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
.help-hero-t{font-size:17px;font-weight:900;line-height:1.2;}
.help-hero-s{font-size:11.5px;color:rgba(255,255,255,.82);margin-top:4px;}
.help-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:6px;}
.help-card{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;padding:14px 15px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);transition:transform .15s,box-shadow .15s;}
.help-card:hover{transform:translateY(-2px);box-shadow:0 1px 2px rgba(58,40,93,.05), 0 14px 28px -14px rgba(58,40,93,.28);}
.help-card-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;margin-bottom:9px;}
.help-card-t{font-size:13px;font-weight:800;color:#312f38;margin-bottom:3px;}
.help-card-d{font-size:11.5px;color:#7f7c88;line-height:1.45;}
/* glossary rows */
.gloss{display:flex;gap:12px;padding:8px 16px;border-bottom:1px dashed #ece4d2;}
.gloss:last-child{border-bottom:none;}
.gloss-t{font-size:12px;font-weight:800;color:#4e3483;min-width:150px;flex-shrink:0;}
.gloss-d{font-size:12px;color:#66706c;line-height:1.4;}
/* FAQ accordion */
.faq{margin-bottom:6px;}
.faq-item{background:#ffffff;border:1px solid #e7e6ed;border-radius:12px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 2px rgba(58,40,93,.04);}
.faq-q{width:100%;border:none;background:transparent;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 16px;font-size:12.5px;font-weight:700;color:#312f38;cursor:pointer;text-align:left;}
.faq-q i{flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#f0ecf6;color:#6642aa;display:flex;align-items:center;justify-content:center;font-size:15px;transition:transform .2s;}
.faq-item.open .faq-q i{transform:rotate(45deg);background:#6642aa;color:#fff;}
.faq-a{max-height:0;overflow:hidden;transition:max-height .25s ease;}
.faq-item.open .faq-a{max-height:400px;}
.faq-a p{margin:0;padding:0 16px 14px;font-size:12px;color:#66706c;line-height:1.55;}
.faq-a code{background:#f0eff3;color:#4e3483;padding:1px 6px;border-radius:4px;font-size:11.5px;font-weight:700;}
/* contact card */
.contact-card{display:flex;align-items:center;gap:14px;background:#ffffff;border:1px solid #e7e6ed;border-left:4px solid #6642aa;border-radius:12px;padding:16px 18px;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -14px rgba(58,40,93,.18);flex-wrap:wrap;}
.contact-ic{width:46px;height:46px;border-radius:50%;background:#f0ecf6;color:#6642aa;display:flex;align-items:center;justify-content:center;font-size:23px;flex-shrink:0;}
.contact-t{font-size:13.5px;font-weight:800;color:#4e3483;}
.contact-d{font-size:11.5px;color:#7f7c88;margin-top:2px;}
.contact-meta{font-size:11px;color:#66706c;font-weight:600;text-align:right;}
.contact-meta i{color:#6642aa;}

/* Footer */
.portal-foot{text-align:center;font-size:11px;color:#8f8c98;margin-top:30px;}

@media (max-width:767.98px), (pointer:coarse) and (max-height:500px){
    .portal-wrap{padding:14px 10px 40px;}
    .ptop{padding:0 12px;}
    .emp-hdr-top{padding:16px 14px;gap:12px;}
    .emp-av{width:46px;height:46px;font-size:18px;}
    .emp-nm{font-size:15px;}
    .emp-no-badge{padding:4px 8px;font-size:10px;}
    .emp-stats{grid-template-columns:repeat(3,1fr);}
    .est:nth-child(n+4){border-top:1px solid #f0eff3;}
    .ps-body{grid-template-columns:1fr;}
    .ps-col:first-child{border-right:none;border-bottom:1px solid #f2f1f5;}
    .ps-net-val{font-size:20px;}
    .ytd-val{font-size:16px;}

    /* Clean white app-like surface on mobile (drop the warm paper texture) */
    body{background-color:#f2f4f7;background-image:none;}
    .portal-wrap{padding:12px 12px 88px;}   /* room for the fixed bottom nav */

    /* ── Mobile bottom navigation bar (app style) ──
       Statically fixed to the bottom edge: no transforms, no transitions,
       no motion of any kind on the bar or its items. */
    .tab-strip{
        position:fixed;left:0;right:0;bottom:0;top:auto;margin:0;z-index:400;
        background:#fff;border:none;border-top:1px solid #eef0f2;
        border-radius:20px 20px 0 0;
        box-shadow:0 -6px 24px rgba(20,30,55,.08);
        padding:8px 4px calc(8px + env(safe-area-inset-bottom,0px));
        gap:2px;flex-wrap:nowrap;overflow:hidden;   /* 5 items fill the bar — no scroll, no right gap */
        transform:none;transition:none;animation:none;
    }
    .tab-btn{
        flex:1 1 0;min-width:0;width:auto;max-width:none;flex-direction:column;gap:4px;
        padding:8px 2px;font-size:9px;position:relative;
        color:#9aa1ac;background:transparent;border-radius:14px;
        transition:none;animation:none;
    }
    .tab-btn i{font-size:21px;line-height:1;display:block;transition:none;transform:none;}
    .tab-btn span.tab-label{display:block;font-size:9px;font-weight:700;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;}
    /* Active item: soft purple tint + brand color icon/label (no heavy filled block) */
    .tab-btn.active{background:#f0ecf6;color:#4e3483;box-shadow:none;}
    .tab-btn .badge-count{position:absolute;top:4px;right:9px;font-size:8px;padding:0 4px;}
    /* On the light mobile "active" background, the translucent-white desktop badge is
       unreadable — force a solid, high-contrast pill instead. */
    .tab-btn.active .badge-count{background:#6642aa;color:#fff;}
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
/* .swal2-container is pinned above these in assets2/css/modal-stacking.css. */

/* ── Modal form controls — ensure visible borders and readable text ── */
.modal .form-control,
.modal .form-select,
.modal select.form-control,
.modal input.form-control,
.modal textarea.form-control {
    border: 1.5px solid #b9b4c5 !important;
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
    border-color: #6642aa !important;
    box-shadow: 0 0 0 2px rgba(102,66,170,.15) !important;
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
    color: #6642aa; font-size: 19px;
    transform: translateY(-100px);
    transition: transform .18s ease, color .15s;
    pointer-events: none;
}
#ptr-indicator.ready { color: #4e3483; }
#ptr-indicator.spin i { animation: ptrSpin .7s linear infinite; }
@keyframes ptrSpin { to { transform: rotate(360deg); } }
</style>
<!-- Mobile-first native-app layer — must load AFTER the inline styles it refines -->
<link href="<?= av('assets2/css/portal-mobile.css') ?>" rel="stylesheet">
<!-- Global DTR (Form 48) template — shared with the admin DTR Documents page -->
<link href="<?= av('assets2/css/dtr-form48.css') ?>" rel="stylesheet">
<script src="<?= av('assets2/js/dtr-form48.js') ?>"></script>
<script>window.DTR_LOG_MODE = <?= json_encode(defined('DTR_LOG_MODE') ? DTR_LOG_MODE : 'single') ?>;</script>

    <!-- App-wide custom <select> control (also loaded globally from includes/header.php
         for pages routed through index.php; this page renders standalone). -->
    <link rel="stylesheet" href="assets2/css/custom-select.css">
    <script defer src="assets2/js/custom-select.js"></script>
</head>
<body>

<div class="ptop">
    <div class="ptop-brand">
        <div class="ptop-logo"><img src="assets2/images/logo.jpeg" alt="COMC"></div>
        <span class="ptop-brand-txt">COMC Employee Portal</span>
        <span class="ptop-screen-title" id="ptop-screen-title">Home</span>
    </div>
    <div class="ptop-actions">
        <button type="button" class="ptop-icbtn" id="emp-reload" onclick="portalReload(this)" title="Refresh" aria-label="Refresh">
            <i class="ri-refresh-line"></i>
        </button>
        <button type="button" class="ptop-icbtn ptop-bell" id="emp-bell" onclick="toggleEmpBell(event)" title="Notifications" aria-label="Notifications">
            <i class="ri-notification-3-line"></i>
            <span class="emp-bell-dot" id="emp-bell-dot" style="display:none;"></span>
        </button>
        <!-- Hidden until assets2/js/pwa-install.js confirms the app can be installed
             (on iOS it stays visible and opens the Add-to-Home-Screen steps). -->
        <button type="button" class="ptop-icbtn ptop-install" data-pwa-install style="display:none;"
                title="Install app" aria-label="Install app">
            <i class="ri-download-2-line"></i>
        </button>
        <a href="?logout=1" class="ptop-logout"><i class="ri-logout-box-line"></i><span class="ptop-logout-txt">Logout</span></a>
    </div>
</div>

<!-- Notification dropdown (anchored to the top-bar bell) -->
<div class="emp-notif-panel" id="emp-notif-panel">
    <div class="emp-notif-sheet-grip"></div>
    <div class="emp-notif-head">
        <span><i class="ri-notification-3-line me-1"></i>Notifications</span>
        <button type="button" onclick="empMarkAllRead()" class="emp-notif-allread">Mark all read</button>
    </div>
    <div class="emp-notif-list" id="emp-notif-list">
        <div class="emp-notif-empty">Loading…</div>
    </div>
</div>
<div class="emp-notif-scrim" id="emp-notif-scrim" onclick="toggleEmpBell()"></div>

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
            <?php if ($payroll_review_pending_count): ?><span class="badge-count" style="background:#e6a817;"><?= $payroll_review_pending_count ?></span><?php endif; ?>
        </button>
        <button class="tab-btn tab-primary" onclick="switchTab('attendance',this)">
            <i class="ri-calendar-check-line"></i><span class="tab-label">Attendance</span>
            <?php if ($attendance_count): ?><span class="badge-count"><?= $attendance_count ?></span><?php endif; ?>
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
        <button class="tab-btn tab-secondary" onclick="switchTab('holidays',this)">
            <i class="ri-calendar-2-line"></i><span class="tab-label">Holidays</span>
        </button>
        <button class="tab-btn tab-secondary" onclick="switchTab('info',this)">
            <i class="ri-profile-line"></i><span class="tab-label">My Info</span>
        </button>
        <?php /* Help tab hidden for now — restore by uncommenting.
        <button class="tab-btn tab-secondary" onclick="switchTab('help',this)">
            <i class="ri-question-line"></i><span class="tab-label">Help</span>
        </button>
        */ ?>
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
                <span class="more-ic" style="background:#f2f0f7;color:#4e3483;"><i class="ri-shield-check-line"></i></span>
                <span class="more-lbl">Contributions</span>
            </button>
            <button type="button" class="more-item" onclick="goMore('holidays')">
                <span class="more-ic" style="background:#fff0f0;color:#c62828;"><i class="ri-calendar-2-line"></i></span>
                <span class="more-lbl">Holidays</span>
            </button>
            <button type="button" class="more-item" onclick="goMore('info')">
                <span class="more-ic" style="background:#f0ecf6;color:#6642aa;"><i class="ri-profile-line"></i></span>
                <span class="more-lbl">My Info</span>
            </button>
            <?php /* Help hidden for now — restore by uncommenting.
            <button type="button" class="more-item" onclick="goMore('help')">
                <span class="more-ic" style="background:#eafaf0;color:#0f9d58;"><i class="ri-question-line"></i></span>
                <span class="more-lbl">Help</span>
            </button>
            */ ?>
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
                    <div class="emp-no-badge"><?= htmlspecialchars($emp['employee_no']) ?></div>
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
        $scq = $conn->query("SELECT ws.description, ws.start_time, ws.end_time, ws.total_hours, ws.is_graveyard, es.effective_from, es.rest_days
            FROM employee_schedules es INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $emp_id AND es.effective_from <= CURDATE()
              AND (es.effective_to IS NULL OR es.effective_to >= CURDATE())
            ORDER BY es.effective_from DESC LIMIT 1");
        if ($scq) $sched_cur = $scq->fetch_assoc();

        // Day-off (rest days) renderer for the schedule card — 0=Sun … 6=Sat.
        $portal_day_off = function ($csv) {
            $labels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
            $names  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $set = array_filter(array_map('intval', $csv === '' || $csv === null ? [] : explode(',', $csv)), fn($d) => $d >= 0 && $d <= 6);
            $pills = '';
            foreach ($labels as $i => $lb) {
                $on = in_array($i, $set, true);
                $pills .= '<span style="display:inline-block;width:19px;height:19px;line-height:19px;text-align:center;border-radius:50%;font-size:9.5px;font-weight:700;margin-right:2px;'
                    . ($on ? 'background:#673bb6;color:#fff;' : 'background:#eef1f5;color:#c2c8d0;') . '">' . $lb . '</span>';
            }
            $text = empty($set) ? 'None' : implode(', ', array_map(fn($d) => $names[$d], $set));
            return [$pills, $text];
        };
        $suq = $conn->query("SELECT ws.description, ws.start_time, ws.end_time, es.effective_from
            FROM employee_schedules es INNER JOIN work_schedules ws ON ws.id = es.schedule_id
            WHERE es.employee_id = $emp_id AND es.effective_from > CURDATE()
            ORDER BY es.effective_from ASC");
        if ($suq) while ($u = $suq->fetch_assoc()) $sched_upcoming[] = $u;
        ?>
        <div class="sec"><i class="ri-time-line"></i>My Work Schedule</div>
        <div style="background:#fff;border:1px solid #e8e7eb;border-radius:14px;padding:14px 16px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <?php if ($sched_cur): ?>
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#f4f1fa;color:#673bb6;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="ri-time-line" style="font-size:20px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:10px;color:#908c9c;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Current Shift</div>
                    <div style="font-size:15px;font-weight:700;color:#2b2639;"><?= htmlspecialchars($sched_cur['description']) ?>
                        <?php if ($sched_cur['is_graveyard']): ?><span style="font-size:10px;background:#2b2b3a;color:#fff;padding:1px 6px;border-radius:6px;vertical-align:middle;"><i class="ri-moon-line"></i> Night</span><?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#625f6e;">
                        <?= date('h:i A', strtotime($sched_cur['start_time'])) ?> – <?= date('h:i A', strtotime($sched_cur['end_time'])) ?>
                        &nbsp;·&nbsp; <?= rtrim(rtrim($sched_cur['total_hours'], '0'), '.') ?> hrs
                    </div>
                    <?php list($__do_pills, $__do_text) = $portal_day_off($sched_cur['rest_days'] ?? ''); ?>
                    <div style="font-size:12px;color:#625f6e;margin-top:6px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                        <span style="font-weight:600;color:#908c9c;"><i class="ri-moon-line me-1"></i>Day Off:</span>
                        <span><?= $__do_pills ?></span>
                        <span style="color:#2b2639;font-weight:600;"><?= htmlspecialchars($__do_text) ?></span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#908c9c;"><i class="ri-information-line me-1"></i>No work schedule assigned yet.</div>
            <?php endif; ?>

            <?php if (count($sched_upcoming)): ?>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e8e7eb;">
                <div style="font-size:10px;color:#ad6800;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px;"><i class="ri-calendar-schedule-line me-1"></i>Upcoming Changes</div>
                <?php foreach ($sched_upcoming as $up): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 0;">
                    <div style="font-size:11px;font-weight:700;color:#ad6800;background:#fff7e6;border:1px solid #ffe7ba;border-radius:8px;padding:3px 9px;white-space:nowrap;">
                        <?= date('M j', strtotime($up['effective_from'])) ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:#2b2639;"><?= htmlspecialchars($up['description']) ?></div>
                        <div style="font-size:11px;color:#908c9c;"><?= date('h:i A', strtotime($up['start_time'])) ?> – <?= date('h:i A', strtotime($up['end_time'])) ?> &nbsp;·&nbsp; from <?= date('F j, Y', strtotime($up['effective_from'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Fingerprints (two-hand status) -->
        <div class="sec"><i class="ri-fingerprint-line"></i>My Fingerprints</div>
        <?php require_once __DIR__ . '/component/finger_hands.php'; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-bottom:14px;">
            <?php /* Mobile Kiosk card (incl. face registration) — hidden for now.
            <?= render_finger_hands($conn, $emp_id, ['title' => 'Enrolled Fingerprints (Mobile Kiosk)']) ?>
            */ ?>
            <?= render_finger_hands($conn, $emp_id, ['source' => 'device', 'title' => 'Enrolled Fingerprints (Scanner Device)']) ?>
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
                    <a href="javascript:void(0)" onclick="switchTab('holidays',null)" style="font-size:10px;color:#6642aa;font-weight:700;text-decoration:none;">See all →</a>
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
                    <a href="javascript:void(0)" onclick="switchTab('leave',null)" style="font-size:10px;color:#6642aa;font-weight:700;text-decoration:none;">Request →</a>
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
                    <div class="ps-row"><span class="ps-lbl">Days Present</span><span class="ps-val"><?= n2($latest['present']) ?> days</span></div>
                    <?php if ($all_tot > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Allowance</span><span class="ps-val earn">₱<?= n2($all_tot) ?></span></div>
                    <?php endif; ?>
                    <?php if ($abs_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Absent (<?= n2($latest['absent']) ?> day<?= $latest['absent']>1?'s':'' ?>)</span><span class="ps-val ded">−₱<?= n2($abs_amt) ?></span></div>
                    <?php endif; ?>
                    <div class="ps-row"><span class="ps-lbl" style="font-weight:700;">Sub-Total</span><span class="ps-val earn" style="font-weight:800;">₱<?= n2($sub_tot) ?></span></div>
                    <?php if ($ot_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Overtime (<?= n2($latest['ot']) ?> hrs)</span><span class="ps-val earn">₱<?= n2($ot_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($lgl_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Legal Holiday (<?= $latest['legal_holiday'] ?>)</span><span class="ps-val earn">₱<?= n2($lgl_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($sun_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Rest Day Premium (<?= $latest['sunday_duty'] ?> × 30%)</span><span class="ps-val earn">₱<?= n2($sun_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($spc_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Special Holiday (<?= $latest['special_holiday'] ?>)</span><span class="ps-val earn">₱<?= n2($spc_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($late_amt > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Late (<?= number_format($latest['late']) ?> min)</span><span class="ps-val ded">−₱<?= n2($late_amt) ?></span></div>
                    <?php endif; ?>
                    <?php if ($latest['under_time'] > 0): ?>
                    <div class="ps-row"><span class="ps-lbl">Undertime (<?= number_format($latest['under_time']) ?> min)</span><span class="ps-val ded">−₱<?= n2($ut_amt) ?></span></div>
                    <?php endif; ?>
                    <?php /* One-off allowances added for this employee alone */ ?>
                    <?php foreach (($latest['extras'] ?? []) as $__x): if ($__x['kind'] !== 2) continue; ?>
                    <div class="ps-row"><span class="ps-lbl"><?= htmlspecialchars($__x['label']) ?></span><span class="ps-val earn">₱<?= n2($__x['amount']) ?></span></div>
                    <?php endforeach; ?>
                    <div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#6642aa;">Gross Pay</span><span class="ps-val earn" style="font-size:15px;font-weight:900;">₱<?= n2($gross) ?></span></div>
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
                    <?php /* One-off deductions applied to this employee alone */ ?>
                    <?php foreach (($latest['extras'] ?? []) as $__x): if ($__x['kind'] !== 1) continue; ?>
                    <div class="ps-row"><span class="ps-lbl"><?= htmlspecialchars($__x['label']) ?></span><span class="ps-val ded">₱<?= n2($__x['amount']) ?></span></div>
                    <?php endforeach; ?>
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
        <div class="paper pslist-paper">
            <div class="pslist-head">
                <span class="pslist-count"><?= count($payslips) ?> payroll period<?= count($payslips) > 1 ? 's' : '' ?></span>
                <div class="pslist-search">
                    <i class="ri-search-line"></i>
                    <input type="text" id="ps-search" placeholder="Search period or ref…" autocomplete="off">
                </div>
            </div>

            <!-- Compact list — one row per payslip, tap/click opens full details -->
            <div class="pslist" id="ps-hist">
                <?php
                $t_net=0; $t_gross=0; $t_ded=0;
                $payrollReviewJs = [];
                foreach ($payslips as $ps):
                    $pm2    = payroll_per_minute($ps);
                    $at2    = $ps['allowance_amount'] * $ps['allowance_days'];
                    $ot2    = $ps['ot'] * $ps['ot_rate'];
                    $la2    = $ps['late'] * $pm2;
                    $ab2    = $ps['absent'] * $ps['per_day'];
                    $lgl2   = $ps['legal_holiday'] * $ps['per_day'];
                    $__rt2  = $ps['rate_type'] ?? 'daily';
                    $sun2   = ($__rt2 === 'monthly' || $__rt2 === 'fixed')
                        ? $ps['sunday_duty'] * $ps['per_day']
                        : rest_day_premium($ps['sunday_duty'], $ps['per_day']);
                    $spc2   = ($ps['per_day']/8*2.4) * $ps['special_holiday'];
                    $ut2    = $ps['under_time'] * $pm2;   // deducted from gross, like late
                    $_pp2   = pp_pay_x($ps);
                    $sub2   = $_pp2['sub'];
                    // Gross mirrors the admin payroll view, honoring the employee's rate basis.
                    $gr2    = $_pp2['gross'];
                    $ded2   = $ps['deduction_amount'] + $ps['other_deduction'] + $ps['tax'] + $ps['jei_advances'] + $ps['jcc_advances'] + $ps['sss_fund'];
                    $t_net+=$ps['net']; $t_gross+=$gr2; $t_ded+=$ded2;
                    $psStatus = (int) $ps['payroll_status'];
                    $psReview = $ps['review_status'] === null ? null : (int) $ps['review_status'];
                    $payrollReviewJs[(int)$ps['payroll_id']] = [
                        'period'  => date('M d', strtotime($ps['date_from'])) . ' – ' . date('M d, Y', strtotime($ps['date_to'])),
                        'ref_no'  => $ps['ref_no'],
                        'item_id' => (int)$ps['item_id'],
                        'status'  => $psStatus,
                        // 2 decimals like the admin payroll table — these are doubles and
                        // printed straight into the review sheet's stats strip.
                        'present' => n2($ps['present']), 'absent' => n2($ps['absent']), 'late' => $ps['late'], 'ot' => $ps['ot'],
                        'rate_type' => $__rt2,   // drives the rest-day label (premium vs full day)
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
                    // Needs action = out for review and not yet answered
                    $needs = ($psStatus === 3 && $psReview === null);
                ?>
                <button type="button" class="psrow<?= $needs ? ' needs' : '' ?>"
                    data-payroll-id="<?= (int)$ps['payroll_id'] ?>"
                    data-search="<?= htmlspecialchars(strtolower(date('M d Y', strtotime($ps['date_from'])) . ' ' . date('M d Y', strtotime($ps['date_to'])) . ' ' . $ps['ref_no']), ENT_QUOTES) ?>"
                    onclick="openPayslipDetails(<?= (int)$ps['payroll_id'] ?>)">
                    <span class="psrow-main">
                        <span class="psrow-period"><?= date('M d', strtotime($ps['date_from'])) ?> – <?= date('M d, Y', strtotime($ps['date_to'])) ?></span>
                        <span class="psrow-meta">
                            <span class="psrow-ref"><?= htmlspecialchars($ps['ref_no']) ?></span>
                            <?php if ($psStatus === 3 && $psReview === null): ?>
                                <span class="psbadge review">Awaiting review</span>
                            <?php elseif ($psReview === 1): ?>
                                <span class="psbadge ok">Confirmed</span>
                            <?php elseif ($psReview === 2): ?>
                                <span class="psbadge dispute">Disputed</span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="psrow-right">
                        <span class="psrow-net">₱<?= n2($ps['net']) ?></span>
                        <span class="psrow-sub">net pay</span>
                    </span>
                    <i class="ri-arrow-right-s-line psrow-chev"></i>
                </button>
                <?php endforeach; ?>
                <div class="pslist-empty" id="ps-no-match" style="display:none;">No payslip matches that search.</div>
            </div>

            <div class="pslist-total">
                <div><span>Total Gross</span><b><?= '₱' . n2($t_gross) ?></b></div>
                <div><span>Total Deductions</span><b class="ded"><?= '₱' . n2($t_ded) ?></b></div>
                <div class="net"><span>Total Net · <?= count($payslips) ?> period<?= count($payslips) > 1 ? 's' : '' ?></span><b><?= '₱' . n2($t_net) ?></b></div>
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
            <div style="padding:10px 14px;border-bottom:1px solid #f2f1f5;display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:12px;color:#888;"><span id="att-count">0</span> records</span>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div id="att-range" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="att-range-label">Last 7 Days</span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <button onclick="clearAttFilter()" class="btn btn-sm" style="background:#f2f1f5;color:#888;padding:5px 10px;font-size:11px;border:none;border-radius:7px;">Today</button>
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
            <!-- Mobile: infinite-scroll card feed (replaces the table on mobile) -->
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
                style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
                <i class="ri-add-circle-line me-1"></i>File a Request
            </button>
        </div>

        <!-- My Request History — server-side DataTable on desktop; on mobile a
             dedicated infinite-scroll card feed (#areq-mlist) hits the same endpoint. -->
        <div class="sec"><i class="ri-history-line"></i>My Requests
            <span style="font-weight:600;color:#8f8c98;font-size:12px;">(<span id="areq-count">0</span>)</span>
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
                <!-- Mobile: infinite-scroll card feed (replaces the table on mobile) -->
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
                    <?php /* data-no-cs: these two are handed to bootstrap-select at
                             runtime (see the .selectpicker() call further down), so the
                             global custom-select control must leave them alone. */ ?>
                    <select id="cmp-a" data-no-cs></select>
                </div>
                <div class="col-12 col-md-2 text-center" style="padding-bottom:4px;">
                    <span style="display:inline-flex;width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:800;font-size:11px;align-items:center;justify-content:center;">VS</span>
                </div>
                <div class="col-12 col-md-5">
                    <label class="info-lbl" style="margin-bottom:4px;">Period B</label>
                    <select id="cmp-b" data-no-cs></select>
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
        <div class="loan-c loan-tap" role="button" tabindex="0" title="Tap to view payment history"
             onclick="openLoanDetail(<?= (int)$loan['loan_id'] ?>)"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openLoanDetail(<?= (int)$loan['loan_id'] ?>);}">
            <div class="loan-head">
                <div>
                    <div class="loan-type-lbl"><?= htmlspecialchars($loan['type_name']) ?></div>
                    <div style="font-size:11px;color:#aaa;margin-top:2px;">Since <?= date('M d, Y', strtotime($loan['loan_date'])) ?></div>
                </div>
                <div class="loan-bal-wrap">
                    <div style="text-align:right;">
                        <div style="font-size:10px;color:#888;">Remaining Balance</div>
                        <div class="loan-bal-val">₱<?= n2($loan['loan_balance']) ?></div>
                    </div>
                    <i class="ri-arrow-right-s-line loan-chev"></i>
                </div>
            </div>
            <div class="loan-progwrap">
                <div class="loan-prog"><div class="loan-prog-bar" style="width:<?= $pct ?>%;"></div></div>
                <span class="loan-pct"><?= $pct ?>% paid</span>
            </div>
            <div class="loan-meta">
                <span class="lm-i"><em>Paid</em><b>₱<?= n2($paid) ?></b></span>
                <span class="lm-i"><em>Loan amount</em><b>₱<?= n2($loan['loan_amount']) ?></b></span>
                <span class="lm-i"><em>Per period</em><b>₱<?= n2($loan['damount']) ?></b></span>
            </div>
            <?php if (is_numeric($periods_left) && $periods_left > 0): ?>
            <div class="loan-est"><i class="ri-time-line me-1"></i>~<?= $periods_left ?> payroll period<?= $periods_left>1?'s':'' ?> remaining</div>
            <?php endif; ?>
            <div class="loan-tap-hint"><i class="ri-history-line"></i>Tap to see every deduction for this loan</div>
        </div>
        <?php endforeach; ?>
        <!-- Loan total -->
        <div style="background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
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
        <div style="background:#ffffff;border:1px solid #e7e6ed;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(58,40,93,.05), 0 8px 22px -12px rgba(58,40,93,.18);">
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
                                <div style="font-weight:700;color:#4e3483;"><?= htmlspecialchars($ch['period']) ?></div>
                                <div style="font-size:10px;color:#aaa;font-family:monospace;"><?= htmlspecialchars($ch['ref']) ?></div>
                            </td>
                            <td class="r" data-label="Contributions"><?= $ch['contrib'] > 0 ? '₱'.n2($ch['contrib']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="SSS Provident"><?= $ch['sssfund'] > 0 ? '₱'.n2($ch['sssfund']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="Tax"><?= $ch['tax'] > 0 ? '₱'.n2($ch['tax']) : '<span class="dim">—</span>' ?></td>
                            <td class="r" data-label="Total" style="font-weight:800;color:#4e3483;">₱<?= n2($ch['total']) ?></td>
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
        <div style="font-size:11px;color:#8f8c98;margin-top:10px;line-height:1.5;"><i class="ri-error-warning-line me-1"></i>These figures come from your reviewed and locked payslips. For official contribution records, please coordinate with HR.</div>
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

        <!-- Holidays & Activities now lives in its own tab (#tab-holidays). -->

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
                style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;padding:9px 20px;border-radius:10px;font-size:13px;cursor:pointer;">
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
        <?php leave_timeline_css(); ?>
        <style>
        /* Attendance Details modal shows the full log list inline — the
           "view details" popover pill would be redundant there. */
        #att-detail-body .dtr-logs-pill{display:none;}
        .lv-chips{display:inline-flex;gap:8px;font-size:16px;align-items:center;}
        .lv-chip i{vertical-align:middle;}
        .lv-tl-details{margin-top:6px;}
        .lv-tl-details>summary{cursor:pointer;list-style:none;font-size:11px;font-weight:700;color:#6642aa;
            display:inline-flex;align-items:center;gap:4px;padding:2px 0;}
        .lv-tl-details>summary::-webkit-details-marker{display:none;}
        .lv-tl-details[open]>summary{margin-bottom:2px;}
        </style>
        <div class="sec"><i class="ri-history-line"></i>My Leave Requests</div>
        <div id="leave-list-wrap">
        <?php if (count($my_leaves)): ?>
        <div class="paper pslist-paper">
            <div class="pslist">
                <?php
                $stMap = [0 => ['Pending','#fd7e14','#fff8e8'], 1 => ['Approved','#4e3483','#f0ecf6'], 2 => ['Rejected','#dc3545','#fff0f0']];
                // Compact per-stage chip (icon coloured by status), labelled by tooltip.
                $stageChip = function ($s, $label) {
                    if ($s == 1) return '<span class="lv-chip" style="color:#4e3483;" title="' . htmlspecialchars($label) . ': Approved"><i class="ri-checkbox-circle-fill"></i></span>';
                    if ($s == 2) return '<span class="lv-chip" style="color:#dc3545;" title="' . htmlspecialchars($label) . ': Rejected"><i class="ri-close-circle-fill"></i></span>';
                    return '<span class="lv-chip" style="color:#fd7e14;" title="' . htmlspecialchars($label) . ': Pending"><i class="ri-time-fill"></i></span>';
                };
                $lv_details = [];   // per-request payload for the click-to-open details modal
                foreach ($my_leaves as $ml):
                    [$slabel, $scol, $sbg] = $stMap[$ml['status']] ?? ['Unknown','#888','#eee'];
                    // Most relevant rejection remark (last stage that rejected).
                    $rej = '';
                    foreach (array_reverse(leave_stages(), true) as $rk => $rd) {
                        if ((int)$ml[$rk . '_status'] === 2 && !empty($ml[$rk . '_remarks'])) { $rej = $ml[$rk . '_remarks']; break; }
                    }
                    $lv_details[$ml['id']] = [
                        'type'     => $ml['leave_type_name'],
                        'applied'  => date('M d, Y', strtotime($ml['date_applied'])),
                        'period'   => date('M d', strtotime($ml['date_from'])) . ' – ' . date('M d, Y', strtotime($ml['date_to'])),
                        'days'     => rtrim(rtrim(number_format($ml['duration'], 1), '0'), '.'),
                        'half'     => $ml['is_half_day'] ? ($ml['half_period'] . ' half' . (!empty($ml['half_date']) ? ' · ' . date('M j', strtotime($ml['half_date'])) : '')) : '',
                        'reason'   => (string)($ml['reason'] ?? ''),
                        'status'   => (int)$ml['status'],
                        'rej'      => $rej,
                        'timeline' => leave_timeline_html($ml),
                    ];
                ?>
                <button type="button" class="psrow<?= $ml['status'] == 0 ? ' needs' : '' ?>"
                    onclick="openLeaveDetail(<?= (int)$ml['id'] ?>)" title="Tap to view details">
                    <span class="psrow-main">
                        <span class="psrow-period"><?= htmlspecialchars($ml['leave_type_name']) ?></span>
                        <span class="psrow-meta">
                            <span class="psrow-ref"><?= date('M d', strtotime($ml['date_from'])) ?> – <?= date('M d, Y', strtotime($ml['date_to'])) ?></span>
                            <span class="psbadge <?= $ml['status'] == 1 ? 'ok' : ($ml['status'] == 2 ? 'dispute' : 'review') ?>"><?= $slabel ?></span>
                        </span>
                        <span class="psrow-meta lv-chips">
                            <?php foreach (leave_stages() as $ck => $cd): ?>
                                <?= $stageChip($ml[$ck . '_status'], $cd['label']) ?>
                            <?php endforeach; ?>
                            <?php if ($ml['status'] == 2 && $rej): ?>
                                <span class="psrow-rej" title="<?= htmlspecialchars($rej) ?>"><i class="ri-information-line"></i> <?= htmlspecialchars(mb_strimwidth($rej, 0, 28, '…')) ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="psrow-right">
                        <span class="psrow-net"><?= rtrim(rtrim(number_format($ml['duration'], 1), '0'), '.') ?></span>
                        <span class="psrow-sub">day<?= $ml['duration'] > 1 ? 's' : '' ?></span>
                    </span>
                    <i class="ri-arrow-right-s-line psrow-chev"></i>
                </button>
                <?php endforeach; ?>
                <div class="pslist-empty" id="lv-no-match" style="display:none;">No leave request matches.</div>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="empty-ic"><i class="ri-calendar-event-line"></i></div><p>You haven't filed any leave requests yet.</p></div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Tab: Holidays & Activities ── -->
    <div class="tab-panel" id="tab-holidays">
        <div class="sec"><i class="ri-calendar-2-line"></i>Holidays &amp; Activities</div>
        <?php if (count($calendar_events_portal)): ?>
        <div class="paper" style="border-radius:14px;overflow:hidden;margin-bottom:18px;">
            <div class="table-responsive">
            <table class="ps-hist-table no-click">
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
                    <div class="info-val mono accent"><?= htmlspecialchars($emp['employee_no']) ?></div>
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
                    <div class="info-val"><?= ((int)$emp['status'] === 1) ? '<span style="color:#6642aa;">● Active</span>' : '<span style="color:#dc3545;">● Inactive</span>' ?></div>
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

        <!-- Bank / Payout — where the salary is sent. Contact HR if wrong. -->
        <div class="info-section">
            <div class="info-sec-title"><i class="ri-bank-line"></i> Bank / Payout</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">Bank</div>
                    <div class="info-val"><?= !empty($emp['bank_name']) ? htmlspecialchars($emp['bank_name']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Account Number</div>
                    <div class="info-val mono"><?= !empty($emp['bank_account_no']) ? htmlspecialchars($emp['bank_account_no']) : '<span style="color:#ccc;">Not set</span>' ?></div>
                </div>
            </div>
            <div style="font-size:11px;color:#98a2ad;margin-top:6px;"><i class="ri-information-line me-1"></i>Your salary is deposited to this account. If anything is wrong, contact HR.</div>
        </div>

        <!-- Compensation -->
        <div class="info-section">
            <div class="info-sec-title"><i class="ri-money-dollar-circle-line"></i> Compensation & Schedule</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">Basic Pay</div>
                    <div class="info-val accent">₱<?= n2($emp['basic_pay']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Daily Rate</div>
                    <div class="info-val accent">₱<?= n2($emp['salary']) ?></div>
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
                    <div class="info-lbl">Rate Type</div>
                    <?php
                    $__prt = (isset($emp['rate_type']) && in_array($emp['rate_type'], ['daily', 'monthly', 'fixed'], true)) ? $emp['rate_type'] : 'daily';
                    $__prt_txt = ['daily' => 'Daily (per day present)', 'monthly' => 'Monthly (salary − absences)', 'fixed' => 'Fixed (full salary)'][$__prt];
                    ?>
                    <div class="info-val"><?= $__prt_txt ?></div>
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

        <!-- Login & Security — the one thing on this tab the employee CAN change. -->
        <div class="info-section" id="sec-security">
            <div class="info-sec-title"><i class="ri-shield-keyhole-line"></i> Login &amp; Security</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-lbl">Sign in with</div>
                    <div class="info-val mono"><?= $portal_acct ? htmlspecialchars($portal_acct['username']) : htmlspecialchars($emp['employee_no']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-lbl">Last Sign-in</div>
                    <div class="info-val"><?= ($portal_acct && !empty($portal_acct['last_login'])) ? date('M d, Y g:i A', strtotime($portal_acct['last_login'])) : '—' ?></div>
                </div>
            </div>
            <div style="padding:0 16px 14px;">
                <?php if ($portal_must_change): ?>
                <div class="pw-warn" id="pw-warn">
                    <i class="ri-error-warning-line"></i>
                    <span>You are still using the password HR gave you. Please set your own below.</span>
                </div>
                <?php endif; ?>
                <form id="form-change-password" autocomplete="off" onsubmit="return submitChangePassword(event);">
                    <div class="pw-field">
                        <label for="pw-current">Current password</label>
                        <div class="pw-input">
                            <input type="password" id="pw-current" name="current_password" class="form-control" autocomplete="current-password" required>
                            <button type="button" class="pw-eye" onclick="togglePw('pw-current',this)" aria-label="Show password"><i class="ri-eye-line"></i></button>
                        </div>
                    </div>
                    <div class="pw-field">
                        <label for="pw-new">New password</label>
                        <div class="pw-input">
                            <input type="password" id="pw-new" name="new_password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required oninput="pwStrength()">
                            <button type="button" class="pw-eye" onclick="togglePw('pw-new',this)" aria-label="Show password"><i class="ri-eye-line"></i></button>
                        </div>
                        <div class="pw-meter"><span id="pw-bar"></span></div>
                        <div class="pw-hint" id="pw-hint">At least 8 characters. Mix letters, numbers and a symbol.</div>
                    </div>
                    <div class="pw-field">
                        <label for="pw-confirm">Confirm new password</label>
                        <div class="pw-input">
                            <input type="password" id="pw-confirm" name="confirm_password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required oninput="pwStrength()">
                            <button type="button" class="pw-eye" onclick="togglePw('pw-confirm',this)" aria-label="Show password"><i class="ri-eye-line"></i></button>
                        </div>
                        <div class="pw-hint" id="pw-match"></div>
                    </div>
                    <button type="submit" class="pw-save" id="pw-save-btn">
                        <i class="ri-lock-password-line"></i> Change Password
                    </button>
                </form>
                <div style="font-size:11px;color:#98a2ad;margin-top:8px;">
                    <i class="ri-information-line me-1"></i>Forgot your password? Only HR can reset it for you.
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
                <div class="help-card-ic" style="background:#f0ecf6;color:#6642aa;"><i class="ri-dashboard-line"></i></div>
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
            <div class="info-sec-title" style="background:#4e3483;"><i class="ri-arrow-up-circle-line"></i> Earnings — money added</div>
            <div style="padding:4px 0;">
                <div class="gloss"><span class="gloss-t">Basic Pay</span><span class="gloss-d">Your contracted rate for the pay period.</span></div>
                <div class="gloss"><span class="gloss-t">Allowance</span><span class="gloss-d">Extra pay such as daily allowance × number of days.</span></div>
                <div class="gloss"><span class="gloss-t">Overtime (OT)</span><span class="gloss-d">Hours worked beyond your schedule × your OT rate.</span></div>
                <div class="gloss"><span class="gloss-t">Legal / Special Holiday</span><span class="gloss-d">Premium pay for working on a declared holiday.</span></div>
                <div class="gloss"><span class="gloss-t">Rest Day Duty</span><span class="gloss-d">Premium for rendering duty on a rest day.</span></div>
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
        <div style="background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:12px;padding:14px 18px;color:#fff;display:flex;align-items:center;gap:12px;margin-bottom:14px;">
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js"></script>
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
            .catch(function (e) {
                console.warn('[PWA] SW registration failed:', e);
                if (window.pwaNoteSwError) window.pwaNoteSwError(e.message || e);
            });
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
    refreshOtLimit();
}

// ── OT ceiling for the selected date ─────────────────────────────────────────
// Overtime may only be filed for hours the employee's own scans actually show
// past their shift end, so the modal asks the server (ot_request_limit) for the
// cap the moment a date is picked and shows it inline. Advisory only — the same
// limit is re-checked in submit_attendance_request, which is what actually
// blocks an over-claim.
var _otLimit = null;      // last resolved limit for the selected date
var _otLimitSeq = 0;      // stale-response guard (date changed mid-flight)

function setOtHint(text, tone) {
    var hint = document.getElementById('att-ot-limit-hint');
    if (!hint) return;
    var palette = {
        ok:      ['#eef6ee', '#1b5e20', 'ri-checkbox-circle-line'],
        blocked: ['#fdeaea', '#b3261e', 'ri-error-warning-line'],
        busy:    ['#f5f3f9', '#6b6b6b', 'ri-loader-4-line'],
    }[tone] || ['#f5f3f9', '#6b6b6b', 'ri-information-line'];
    hint.style.display    = text ? 'block' : 'none';
    hint.style.background = palette[0];
    hint.style.color      = palette[1];
    hint.innerHTML        = '<i class="' + palette[2] + ' me-1"></i>' + text;
}

function refreshOtLimit() {
    var typeEl = document.getElementById('att-req-type');
    var input  = document.getElementById('att-ot-hours');
    if (!input) return;
    var dateEl = document.getElementById('att-req-date-hidden');
    var date   = dateEl ? dateEl.value : '';

    _otLimit = null;
    input.setAttribute('max', '12');
    input.removeAttribute('data-parsley-otlimit-message');

    if (!typeEl || typeEl.value !== 'overtime') { setOtHint('', 'busy'); return; }
    if (!date) { setOtHint('Pick the date first — the OT you may file is limited to the hours your scans show past your shift end.', 'busy'); return; }

    setOtHint('Checking your scans for that date…', 'busy');
    var seq = ++_otLimitSeq;
    fetch('emp-portal-ajax.php?action=ot_request_limit', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'request_date=' + encodeURIComponent(date)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (seq !== _otLimitSeq) return;               // a newer date won
        var lim = (res && res.limit) || null;
        _otLimit = lim;
        if (lim && lim.allowed) {
            input.setAttribute('max', String(lim.max_hours));
            input.setAttribute('data-parsley-otlimit-message',
                'Your scans for that date only support up to ' + lim.max_hours + ' hr of overtime.');
            setOtHint(lim.message, 'ok');
        } else {
            input.value = '';
            // Short message on the field — the full reason is in the hint below it.
            input.setAttribute('data-parsley-otlimit-message', 'No overtime can be filed for that date — see the note below.');
            setOtHint((lim && lim.message) || 'Overtime cannot be filed for that date.', 'blocked');
        }
        if (window.jQuery && jQuery.fn.parsley) jQuery('#att-request-form').parsley().reset();
    }).catch(function () {
        if (seq !== _otLimitSeq) return;
        setOtHint('Could not check your scans for that date. You can still submit — it will be verified on the server.', 'busy');
    });
}

// Parsley gate on the OT input: never let a value through that the day's scans
// don't support (and never let one through at all on a date with no OT).
if (window.Parsley) {
    window.Parsley.addValidator('otlimit', {
        requirementType: 'string',
        validateString: function (value) {
            if (!_otLimit) return true;               // not resolved yet — server decides
            if (!_otLimit.allowed) return false;
            var n = parseFloat(value);
            return isNaN(n) ? true : n <= _otLimit.max_hours + 0.001;
        },
        messages: { en: 'That is more overtime than your scans for that date support.' }
    });
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
    // Always open a section at its top. Without this you keep the previous
    // section's scroll offset — tapping a tab after scrolling a long list
    // (Attendance, Leave) dropped you into the middle of the new page.
    window.scrollTo(0, 0);
    if (document.scrollingElement) document.scrollingElement.scrollTop = 0;
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
// Only the "needs review" count shows here — no running total of payslips.
function refreshPayslipsBadge() { setBadge('tabbtn-payslips', PENDING.payroll, '#e6a817'); }
function updatePayslipRow(payrollId, decision) {
    var badgeCls = decision === 1 ? 'ok' : 'dispute';
    var badgeLbl = decision === 1 ? 'Confirmed' : 'Disputed';
    // Compact list row (same markup on phone and desktop)
    var row = document.querySelector('.pslist .psrow[data-payroll-id="' + payrollId + '"]');
    if (!row) return;
    row.classList.remove('needs');                       // no longer awaiting an answer
    var meta = row.querySelector('.psrow-meta');
    if (!meta) return;
    var badge = meta.querySelector('.psbadge');
    if (!badge) {
        badge = document.createElement('span');
        meta.appendChild(badge);
    }
    badge.className = 'psbadge ' + badgeCls;
    badge.textContent = badgeLbl;
    // keep the in-memory record in step so reopening shows the new state
    if (typeof PAYROLL_REVIEW_DATA !== 'undefined' && PAYROLL_REVIEW_DATA[payrollId]) {
        PAYROLL_REVIEW_DATA[payrollId].review_status = decision;
    }
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

// Approval-stage labels in workflow order (from LEAVE_APPROVAL_STAGES) so a
// just-filed request's row can be built client-side with NO page reload.
var LEAVE_STAGES = <?= json_encode(array_map(fn($s) => $s['label'], array_values(leave_stages()))) ?>;

// Per-request payload for the click-to-open details modal (built server-side in
// the list loop; client-side prepends register themselves here too).
var LEAVE_DETAILS = <?= json_encode($lv_details ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function openLeaveDetail(id) {
    var d = LEAVE_DETAILS[id];
    if (!d) return;
    var stMap = { 0: ['Pending', '#fd7e14', '#fff8e8'], 1: ['Approved', '#4e3483', '#f0ecf6'], 2: ['Rejected', '#dc3545', '#fff0f0'] };
    var st = stMap[d.status] || ['Unknown', '#888', '#eee'];
    var h = '<div class="d-flex align-items-center justify-content-between mb-3">'
        + '<span style="font-weight:800;color:#4e3483;font-size:16px;">' + escapeHtml(d.type) + '</span>'
        + '<span style="background:' + st[2] + ';color:' + st[1] + ';border-radius:10px;padding:3px 12px;font-size:11px;font-weight:700;">' + st[0] + '</span>'
        + '</div>'
        + '<div class="row g-2 mb-2" style="font-size:12.5px;">'
        + '<div class="col-6"><div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">Filed</div>' + escapeHtml(d.applied) + '</div>'
        + '<div class="col-6"><div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">Days</div><b>' + escapeHtml(String(d.days)) + '</b>'
        + (d.half ? ' <span style="background:#fff8e8;color:#fd7e14;border-radius:8px;padding:1px 7px;font-size:10px;font-weight:700;">' + escapeHtml(d.half) + '</span>' : '') + '</div>'
        + '<div class="col-12"><div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">Period</div>' + escapeHtml(d.period) + '</div>'
        + (d.reason ? '<div class="col-12"><div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">Reason</div>' + escapeHtml(d.reason) + '</div>' : '')
        + '</div>'
        + (d.rej ? '<div style="background:#fff0f0;color:#dc3545;border-radius:10px;padding:8px 12px;font-size:12px;margin-bottom:10px;"><i class="ri-information-line me-1"></i><b>Rejected:</b> ' + escapeHtml(d.rej) + '</div>' : '')
        + '<div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px;">Approval Timeline</div>'
        + d.timeline;
    document.getElementById('leave-detail-body').innerHTML = h;
    new bootstrap.Modal(document.getElementById('modal-leave-detail')).show();
}

function prependLeaveRow(req) {
    // Build the new request's row (all stages pending) and prepend it — no page
    // reload, so the SweetAlert success message from ajaxSubmitForm stays visible.
    var wrap = document.getElementById('leave-list-wrap');
    if (!wrap) return;
    var tbody = wrap.querySelector('table.ps-hist-table tbody');
    if (!tbody) {   // list was empty — build the table shell first
        wrap.innerHTML = '<div class="paper" style="border-radius:14px;overflow:hidden;"><div class="table-responsive">'
            + '<table class="ps-hist-table"><thead><tr><th>Date Applied</th><th>Type</th><th>Period</th>'
            + '<th class="r">Days</th><th>Progress</th><th>Status</th></tr></thead><tbody></tbody></table></div></div>';
        tbody = wrap.querySelector('tbody');
    }
    // Stage chips — a fresh request is pending at every stage.
    var chips = LEAVE_STAGES.map(function (lbl) {
        return '<span class="lv-chip" style="color:#fd7e14;" title="' + escapeHtml(lbl) + ': Pending"><i class="ri-time-fill"></i></span>';
    }).join('');
    // Timeline — Filed (now) → first stage awaiting → later stages pending.
    var now = new Date();
    var filedAt = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
        + ' · ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    var tl = '<ul class="lvtl">'
        + '<li><span class="lvtl-dot filed"><i class="ri-flag-line"></i></span>'
        + '<span class="lvtl-stage">Filed</span><div class="lvtl-meta">' + filedAt + '</div></li>';
    LEAVE_STAGES.forEach(function (lbl, i) {
        if (i === 0) {
            tl += '<li><span class="lvtl-dot wait"><i class="ri-time-line"></i></span>'
                + '<span class="lvtl-stage">' + escapeHtml(lbl) + '</span><span class="lvtl-badge">Awaiting</span></li>';
        } else {
            tl += '<li><span class="lvtl-dot"><i class="ri-more-line"></i></span>'
                + '<span class="lvtl-stage" style="color:#9aa3b2;">' + escapeHtml(lbl) + '</span>'
                + '<div class="lvtl-meta">Pending earlier approval</div></li>';
        }
    });
    tl += '</ul>';
    // Register the new request for the click-to-open details modal.
    LEAVE_DETAILS[req.id] = {
        type: req.leave_type_name,
        applied: fmtMDY(req.date_applied),
        period: fmtMD(req.date_from) + ' – ' + fmtMDY(req.date_to),
        days: trimNum(req.duration),
        half: '',
        reason: req.reason || '',
        status: 0,
        rej: '',
        timeline: tl
    };
    var row = '<tr onclick="openLeaveDetail(' + parseInt(req.id, 10) + ')" style="cursor:pointer;" title="Tap to view details">'
        + '<td data-label="Date Applied">' + fmtMDY(req.date_applied) + '</td>'
        + '<td data-label="Type"><span style="font-weight:700;color:#4e3483;">' + escapeHtml(req.leave_type_name) + '</span></td>'
        + '<td data-label="Period" style="font-size:11px;">' + fmtMD(req.date_from) + ' – ' + fmtMDY(req.date_to) + '</td>'
        + '<td class="r" data-label="Days"><b>' + trimNum(req.duration) + '</b></td>'
        + '<td data-label="Progress"><div class="lv-chips">' + chips
        + '<span style="font-size:10px;color:#6642aa;font-weight:700;margin-left:4px;"><i class="ri-eye-line"></i> Details</span></div></td>'
        + '<td data-label="Status"><span style="background:#fff8e8;color:#fd7e14;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;">Pending</span></td>'
        + '</tr>';
    tbody.insertAdjacentHTML('afterbegin', row);
}

// ── Leave modals ─────────────────────────────────────────────────────────────
var BLOCKED = <?= json_encode(array_values(array_unique($blocked_dates))) ?>;
// Own pending/approved leave days — disabled in the leave/LWOP pickers so a
// duplicate filing can't even be selected (the server re-checks anyway).
var LV_TAKEN = <?= json_encode($my_taken_leave_dates) ?>;

function openLeaveModal() {
    var m = new bootstrap.Modal(document.getElementById('modal-leave-request'));
    m.show();
    document.getElementById('modal-leave-request').addEventListener('shown.bs.modal', function () {
        initLeavePicker();
        updateLvBalance();
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
var _lwopPicker = null;
var _lvIsHalf = false;

// ── Multi-date adapter over the app's standard bootstrap-datetimepicker ─────
// The portal uses the same picker as the admin panel (index.php). The widget
// is single-date by design, so this adapter keeps it open and toggles tapped
// days in/out of a selection set — one tap per day, tap again to remove.
// The hidden field carries the comma-joined YYYY-MM-DD list the server expects.
function makeMultiDatePicker(inputId, hiddenId, opts) {
    opts = opts || {};
    var $inp = jQuery('#' + inputId);
    var selected = [];                                   // 'YYYY-MM-DD', kept sorted
    $inp.datetimepicker({
        format: 'YYYY-MM-DD',
        useCurrent: false,
        ignoreReadonly: true,                            // inputs are readonly on purpose
        keepOpen: !opts.single,                          // stack several days per visit
        widgetPositioning: { horizontal: 'auto', vertical: 'bottom' },  // keep the month header reachable in the modal
        minDate: moment().startOf('day'),
        disabledDates: BLOCKED.concat(window.LV_TAKEN || []).map(function (s) { return moment(s, 'YYYY-MM-DD'); }),
        showClear: true,                                 // toolbar: wipe the selection…
        showClose: true,                                 // …and a Done button to dismiss
        tooltips: { clear: 'Clear all selected days', close: 'Done — keep my selection' },
        icons: {
            previous: 'ri-arrow-left-s-line', next: 'ri-arrow-right-s-line',
            clear: 'ri-eraser-line', close: 'ri-check-line'
        }
    });
    var dp = $inp.data('DateTimePicker');

    // The widget stops propagation on toolbar clicks, so its Clear button can
    // only be observed through the null-date dp.change it fires. This flag
    // marks OUR OWN dp.date(null) resets (the re-tap toggle) so they aren't
    // mistaken for the user pressing Clear.
    var suppressClear = false;
    function clearAll() {
        selected = [];
        suppressClear = true; dp.date(null); suppressClear = false;
        $inp.val('');
        jQuery('#' + hiddenId).val('');
        if (opts.onCount) opts.onCount(0);
        paint();
    }

    function paint() {                                   // re-mark every chosen day after each render
        $inp.parent().find('.bootstrap-datetimepicker-widget td.day').each(function () {
            var d = moment(jQuery(this).data('day'), 'L').format('YYYY-MM-DD');
            jQuery(this).toggleClass('active', selected.indexOf(d) !== -1);
        });
    }
    function sync() {
        selected.sort();
        jQuery('#' + hiddenId).val(selected.join(','));
        $inp.val(selected.map(function (d) { return moment(d, 'YYYY-MM-DD').format('MMM D, YYYY'); }).join(', '));
        if (opts.onCount) opts.onCount(selected.length);
        paint();
    }
    $inp.on('dp.change', function (e) {
        if (!e.date) {
            if (!suppressClear) clearAll();              // user pressed the widget's Clear button
            else paint();                                // our own toggle reset — selection stands
            return;
        }
        var d = e.date.format('YYYY-MM-DD');
        if (opts.single) {
            selected = [d];
        } else {
            var i = selected.indexOf(d);
            if (i === -1) selected.push(d); else selected.splice(i, 1);
            suppressClear = true; dp.date(null); suppressClear = false;  // so re-tapping any day always fires dp.change
        }
        sync();
    });
    // The widget is rebuilt on every open and swallows toolbar-click propagation,
    // so hook Clear with a DIRECT listener on its anchor after each show (direct
    // at-target handlers run before the widget's own stopPropagation).
    $inp.on('dp.show', function () {
        paint();
        $inp.parent().find('.bootstrap-datetimepicker-widget a[data-action="clear"]')
            .off('click.mdpclear').on('click.mdpclear', clearAll);
    });
    $inp.on('dp.update', paint);
    $inp.on('click', function () { dp.show(); });        // readonly input: a tap always opens it

    return {
        destroy: function () { $inp.off('dp.change dp.show dp.update click'); dp.destroy(); },
        clear: clearAll
    };
}

function setLvDuration(val) {
    _lvIsHalf = (val !== 'full');
    document.getElementById('lv-is-half').value   = _lvIsHalf ? '1' : '0';
    document.getElementById('lv-half-period').value = _lvIsHalf ? val : '';
    document.querySelectorAll('.lv-dur-btn').forEach(function(b) {
        var active = b.dataset.val === val;
        b.style.background  = active ? '#6642aa' : '#fff';
        b.style.color       = active ? '#fff' : '#555';
        b.style.borderColor = active ? '#6642aa' : '#b9b4c5';
    });
    document.getElementById('lv-half-hint').textContent =
        _lvIsHalf ? '(One of your selected days counts as a half day.)' : '';
    refreshLvDerived();          // selected dates are kept — only the math changes
}

// Multi-day half-day: which end of the selection is the half ('first'/'last').
function setLvHalfOn(val) {
    document.getElementById('lv-half-on').value = val;
    document.querySelectorAll('.lv-halfon-btn').forEach(function(b) {
        var active = b.dataset.val === val;
        b.style.background  = active ? '#6642aa' : '#fff';
        b.style.color       = active ? '#fff' : '#555';
        b.style.borderColor = active ? '#6642aa' : '#b9b4c5';
    });
    updateLvBalance();
}

function lvSelectedCount() {
    return (document.getElementById('lv-dates-hidden').value || '').split(',').filter(Boolean).length;
}
// Half-day = exactly one selected day counts 0.5, so duration is days − 0.5.
function lvDuration() {
    var n = lvSelectedCount();
    return (_lvIsHalf && n) ? n - 0.5 : n;
}
// Everything derived from the current selection: total line, the first/last
// half-day toggle (only for multi-day half requests) and the balance hint.
function refreshLvDerived() {
    var n = lvSelectedCount();
    var box = document.getElementById('lv-dur');
    if (n) {
        document.getElementById('lv-dur-val').textContent = trimNum(lvDuration());
        box.style.display = 'block';
    } else box.style.display = 'none';
    var wrap = document.getElementById('lv-half-on-wrap');
    if (wrap) wrap.style.display = (_lvIsHalf && n > 1) ? 'block' : 'none';
    updateLvBalance();
}

// ── Live leave-credit balance (mirrors the server-side guard) ────────────────
// remaining = credits − (approved + pending durations), per leave type.
var LV_REMAIN = <?= json_encode($lv_remaining_filing) ?>;
function updateLvBalance() {
    var sel  = document.querySelector('#leave-request-form select[name="leave_type_id"]');
    var hint = document.getElementById('lv-bal-hint');
    var btn  = document.getElementById('lv-submit');
    if (!sel || !hint) return;
    var rem = LV_REMAIN[sel.value];
    if (rem === undefined) {                    // no leave type picked yet
        hint.style.display = 'none';
        if (btn) { btn.disabled = false; btn.style.opacity = ''; }
        return;
    }
    var need = lvDuration(), over = need > rem + 0.001;
    hint.style.display = 'block';
    hint.style.color = over ? '#c62828' : '#4e3483';
    hint.innerHTML = over
        ? '<i class="ri-error-warning-line"></i> Not enough credits — this needs <b>' + trimNum(need) + '</b> day(s) but you only have <b>' + trimNum(rem) + '</b> left.'
        : (need > 0
            ? '<i class="ri-wallet-3-line"></i> Uses <b>' + trimNum(need) + '</b> of your <b>' + trimNum(rem) + '</b> remaining day(s).'
            : '<i class="ri-wallet-3-line"></i> You have <b>' + trimNum(rem) + '</b> day(s) left for this leave type.');
    if (btn) { btn.disabled = over; btn.style.opacity = over ? '.55' : ''; }
}

function initLeavePicker() {
    if (!document.getElementById('lv-dates') || _lvPicker) return;
    _lvPicker = makeMultiDatePicker('lv-dates', 'lv-dates-hidden', {
        onCount: function () { refreshLvDerived(); }
    });
}

function initLwopPicker() {
    if (!document.getElementById('lwop-dates') || _lwopPicker) return;
    _lwopPicker = makeMultiDatePicker('lwop-dates', 'lwop-dates-hidden', {
        onCount: function () { refreshLwopDerived(); }
    });
}

// ── LWOP duration / half-day — mirrors the leave modal (red theme) ──────────
var _lwopIsHalf = false;

function setLwopDuration(val) {
    _lwopIsHalf = (val !== 'full');
    document.getElementById('lwop-is-half').value    = _lwopIsHalf ? '1' : '0';
    document.getElementById('lwop-half-period').value = _lwopIsHalf ? val : '';
    document.querySelectorAll('.lwop-dur-btn').forEach(function(b) {
        var active = b.dataset.val === val;
        b.style.background  = active ? '#c62828' : '#fff';
        b.style.color       = active ? '#fff' : '#555';
        b.style.borderColor = active ? '#c62828' : '#b9b4c5';
    });
    document.getElementById('lwop-half-hint').textContent =
        _lwopIsHalf ? '(One of your selected days counts as a half day.)' : '';
    refreshLwopDerived();
}

function setLwopHalfOn(val) {
    document.getElementById('lwop-half-on').value = val;
    document.querySelectorAll('.lwop-halfon-btn').forEach(function(b) {
        var active = b.dataset.val === val;
        b.style.background  = active ? '#c62828' : '#fff';
        b.style.color       = active ? '#fff' : '#555';
        b.style.borderColor = active ? '#c62828' : '#b9b4c5';
    });
}

function refreshLwopDerived() {
    var n = (document.getElementById('lwop-dates-hidden').value || '').split(',').filter(Boolean).length;
    var dur = (_lwopIsHalf && n) ? n - 0.5 : n;
    var box = document.getElementById('lwop-dur');
    if (n) {
        document.getElementById('lwop-dur-val').textContent = trimNum(dur);
        box.style.display = 'block';
    } else box.style.display = 'none';
    var wrap = document.getElementById('lwop-half-on-wrap');
    if (wrap) wrap.style.display = (_lwopIsHalf && n > 1) ? 'block' : 'none';
}

// Leave/LWOP "at least one day" validation is handled by Parsley via the
// required, readonly #lv-dates / #lwop-dates inputs that the picker populates.

// ── My Info → Login & Security: change my own password ──────────────────────
function togglePw(id, btn) {
    var el = document.getElementById(id);
    var show = el.type === 'password';
    el.type = show ? 'text' : 'password';
    btn.innerHTML = show ? '<i class="ri-eye-off-line"></i>' : '<i class="ri-eye-line"></i>';
}

// Advisory only — the server is what actually enforces the rules.
function pwStrength() {
    var v = document.getElementById('pw-new').value || '';
    var score = 0;
    if (v.length >= 8) score++;
    if (v.length >= 12) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    var pct = [0, 20, 40, 60, 80, 100][score];
    var col = score <= 1 ? '#dc3545' : (score <= 3 ? '#f0ad4e' : '#28a745');
    var bar = document.getElementById('pw-bar');
    bar.style.width = pct + '%';
    bar.style.background = col;
    var hint = document.getElementById('pw-hint');
    if (!v) { hint.className = 'pw-hint'; hint.textContent = 'At least 8 characters. Mix letters, numbers and a symbol.'; }
    else if (v.length < 8) { hint.className = 'pw-hint bad'; hint.textContent = 'Too short — at least 8 characters.'; }
    else { hint.className = 'pw-hint'; hint.textContent = score <= 3 ? 'Okay — adding a capital letter, number or symbol makes it stronger.' : 'Strong password.'; }

    var c = document.getElementById('pw-confirm').value || '';
    var match = document.getElementById('pw-match');
    if (!c) { match.className = 'pw-hint'; match.textContent = ''; }
    else if (c === v) { match.className = 'pw-hint ok'; match.textContent = 'Passwords match.'; }
    else { match.className = 'pw-hint bad'; match.textContent = 'Passwords do not match yet.'; }
}

function submitChangePassword(ev) {
    ev.preventDefault();
    var cur = document.getElementById('pw-current').value;
    var nw  = document.getElementById('pw-new').value;
    var cf  = document.getElementById('pw-confirm').value;
    if (!cur || !nw || !cf) {
        Swal.fire({ icon: 'warning', title: 'Incomplete', text: 'Please fill in all three password fields.' });
        return false;
    }
    if (nw !== cf) {
        Swal.fire({ icon: 'warning', title: 'Check again', text: 'The new password and its confirmation do not match.' });
        return false;
    }
    if (nw.length < 8) {
        Swal.fire({ icon: 'warning', title: 'Too short', text: 'Your new password must be at least 8 characters long.' });
        return false;
    }
    var btn = document.getElementById('pw-save-btn');
    btn.disabled = true;
    var label = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line"></i> Saving…';

    var params = new URLSearchParams();
    params.append('current_password', cur);
    params.append('new_password', nw);
    params.append('confirm_password', cf);
    fetch('emp-portal-ajax.php?action=change_my_password', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
        btn.disabled = false; btn.innerHTML = label;
        if (!res.result) {
            Swal.fire({ icon: 'error', title: 'Not changed', text: res.message || 'Something went wrong.' });
            return;
        }
        document.getElementById('form-change-password').reset();
        pwStrength();
        var warn = document.getElementById('pw-warn');
        if (warn) warn.remove();
        Swal.fire({ icon: 'success', title: 'Password changed', text: res.message, timer: 3000, showConfirmButton: false });
    }).catch(function () {
        btn.disabled = false; btn.innerHTML = label;
        Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
    });
    return false;
}

<?php if ($portal_must_change): ?>
// Still on the password HR handed out — offer to set a real one on load. It is
// dismissible on purpose (an employee mid-task shouldn't be blocked), and the
// warning banner in My Info → Login & Security stays until they change it.
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        Swal.fire({
            icon: 'warning',
            title: 'Set your own password',
            text: 'You are still signing in with the password HR gave you. Please change it to something only you know.',
            showCancelButton: true,
            confirmButtonText: 'Change it now',
            cancelButtonText: 'Later',
            confirmButtonColor: '#6642aa'
        }).then(function (r) {
            if (r.isConfirmed) {
                switchTab('info', null);
                var sec = document.getElementById('sec-security');
                if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var f = document.getElementById('pw-current');
                if (f) f.focus({ preventScroll: true });
            }
        });
    }, 900);
});
<?php endif; ?>

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
    // Keep the live balance in step with the just-filed (now pending) request.
    if (res.request && res.request.leave_type_id in LV_REMAIN) {
        LV_REMAIN[res.request.leave_type_id] =
            Math.max(0, LV_REMAIN[res.request.leave_type_id] - parseFloat(res.request.duration));
    }
    setLvDuration('full');
    setLvHalfOn('first');
    document.getElementById('lv-bal-hint').style.display = 'none';
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
    if (_lwopPicker) _lwopPicker.clear();
    setLwopDuration('full');
    setLwopHalfOn('first');
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
$valid_portal_tabs = ['overview','payslips','attendance','leave','mydtr','att-requests','compare','loans','contrib','holidays','info','help'];
$req_tab = $_GET['tab'] ?? null;
if ($req_tab !== null && in_array($req_tab, $valid_portal_tabs, true)):
    // Optional deep-link into a specific attendance record (from a message push).
    $dl_rec  = isset($_GET['rec']) ? (int)$_GET['rec'] : 0;
    $dl_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : '';
?>
document.addEventListener('DOMContentLoaded', function () {
<?php if ($req_tab === 'attendance' && $dl_rec > 0): ?>
    goAttendanceRecord(<?= $dl_rec ?>, '<?= $dl_date ?>');
<?php else: ?>
    switchTab('<?= $req_tab ?>', null);
<?php endif; ?>
    window.scrollTo(0, 0);
});
<?php endif; ?>

// ── Notification bell ────────────────────────────────────────────────────────
function empRenderNotif(data) {
    var dot  = document.getElementById('emp-bell-dot');
    var list = document.getElementById('emp-notif-list');
    if (dot) dot.style.display = (data.unread > 0) ? 'block' : 'none';
    if (!list) return;
    if (!data.items || !data.items.length) {
        list.innerHTML = '<div class="emp-notif-empty">'
            + '<i class="ri-notification-off-line"></i>'
            + '<div class="net">You\'re all caught up</div>'
            + '<div class="nes">New notifications will show up here.</div>'
            + '</div>';
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
// Refresh the current view: notifications, action badges, and whatever the
// active tab shows. The icon spins for the duration so the tap feels alive.
var _portalReloading = false;
function portalReload(btn) {
    if (_portalReloading) return;
    _portalReloading = true;
    btn = btn || document.getElementById('emp-reload');
    if (btn) btn.classList.add('spinning');

    var jobs = [];
    // Always refresh the notification list + unread dot.
    jobs.push(fetch('emp-portal-ajax.php?action=emp_notifications', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(empRenderNotif).catch(function () {}));

    // Reload the data behind the active tab (only the ones with live feeds).
    var active = document.querySelector('.tab-panel.active');
    var tabId = active ? active.id.replace(/^tab-/, '') : '';
    try {
        if (tabId === 'attendance') {
            if (window.attTable) window.attTable.ajax.reload(null, false);
            if (typeof attMReset === 'function' && (attM.started || attMobileMQ.matches)) attMReset();
        } else if (tabId === 'mydtr' && typeof loadMyDtr === 'function') { loadMyDtr(); }
        else if (tabId === 'att-requests' && window.areqTable) { window.areqTable.ajax.reload(null, false); }
    } catch (e) {}

    // Keep the spin visible for at least a beat so it reads as a refresh.
    Promise.all(jobs).catch(function () {}).finally(function () {
        setTimeout(function () {
            if (btn) btn.classList.remove('spinning');
            _portalReloading = false;
        }, 500);
    });
}

function toggleEmpBell(e) {
    if (e) e.stopPropagation();
    var p = document.getElementById('emp-notif-panel');
    var s = document.getElementById('emp-notif-scrim');
    var open = !p.classList.contains('open');
    p.classList.toggle('open', open);
    if (s) s.classList.toggle('open', open);
    if (open) empLoadNotif();
}
function empMarkAllRead() {
    fetch('emp-portal-ajax.php?action=emp_mark_all_read', { method: 'POST', credentials: 'same-origin' })
        .then(function () { empLoadNotif(); });
}
document.addEventListener('click', function (e) {
    var panel = document.getElementById('emp-notif-panel');
    var bell  = document.getElementById('emp-bell');
    var scrim = document.getElementById('emp-notif-scrim');
    if (panel && panel.classList.contains('open') && !panel.contains(e.target) && !bell.contains(e.target)) {
        panel.classList.remove('open');
        if (scrim) scrim.classList.remove('open');
    }
    var item = e.target.closest('.emp-notif-item');
    if (item) {
        var id = item.dataset.id, link = item.dataset.link;
        fetch('emp-portal-ajax.php?action=emp_mark_read', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        }).then(function () {
            var closePanel = function () {
                document.getElementById('emp-notif-panel').classList.remove('open');
                var sc = document.getElementById('emp-notif-scrim'); if (sc) sc.classList.remove('open');
            };
            if (link && link.indexOf('tab=attendance') !== -1) {
                // Message about an attendance date → open that record's details in-place.
                var qs = link.split('?')[1] || '';
                var p = new URLSearchParams(qs);
                closePanel();
                goAttendanceRecord(p.get('rec'), p.get('date'));
            }
            else if (link && link.indexOf('tab=mydtr') !== -1) { switchTab('mydtr', null); loadMyDtr(); closePanel(); }
            else if (link) { window.location.href = link; }
            else { empLoadNotif(); }
        });
    }
});
document.addEventListener('DOMContentLoaded', function () { empLoadNotif(); setInterval(empLoadNotif, 60000); });

// ── My DTR review ────────────────────────────────────────────────────────────
var _dtrReviewId = null;
var _dtrMsgDays = [];      // [{rec_id, date}] — days on this DTR with a conversation
var _dtrMsgTarget = null;  // rec_id the pinned composer posts to
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
                // The whole card is the tap target — no per-row "View" button.
                // A chevron carries the affordance; awaiting-review rows get a
                // highlighted card so the one that needs action still stands out.
                var badge, needsAction = false;
                if (isReview && rv === null) {
                    badge = '<span class="mydtr-badge review">Awaiting your review</span>';
                    needsAction = true;
                } else if (rv === 1) {
                    badge = '<span class="mydtr-badge ok">You confirmed</span>';
                } else if (rv === 2) {
                    badge = '<span class="mydtr-badge dispute">You disputed</span>';
                } else {
                    badge = '<span class="mydtr-badge done">Approved</span>';
                }
                return '<div class="mydtr-card' + (needsAction ? ' needs-action' : '') + '" role="button" tabindex="0"'
                    + ' onclick="openDtrReview(' + d.id + ')"'
                    + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();openDtrReview(' + d.id + ');}">'
                    + '<div class="mydtr-card-main">'
                    + '<div class="mydtr-period"><i class="ri-calendar-2-line"></i> ' + period + '</div>'
                    + '<div class="mydtr-meta">' + (d.day_count || 0) + ' day(s) · ' + (Number(d.total_hours || 0).toFixed(2)) + ' hrs · OT ' + (Number(d.total_ot || 0).toFixed(2)) + '</div>'
                    + '</div>'
                    + '<div class="mydtr-card-side">' + badge + '</div>'
                    + '<i class="ri-arrow-right-s-line mydtr-chev" aria-hidden="true"></i>'
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
    document.getElementById('dtr-review-sub').textContent = res.dtr.period;

    // Reshape the flat day list into the { 'YYYY-MM-DD': {in,out,wh,ot,ut,late} }
    // map the shared Form 48 template expects, and total everything up.
    var days = {}, totals = { wh: 0, ot: 0, ut: 0, late: 0 };
    res.days.forEach(function (d) {
        days[d.iso] = {
            in: d.time_in, out: d.time_out,
            in_off: d.in_off || 0, out_off: d.out_off || 0,
            in_tip: d.in_tip || '', out_tip: d.out_tip || '',
            // DTR_details.notes — 'note' here, not d.note, which the endpoint
            // uses for the rejection reason shown elsewhere in this view.
            note: d.dtr_note || '',
            wh: d.work_hours, ot: d.overtime, ut: (d.undertime || 0), late: d.late
        };
        totals.wh += d.work_hours; totals.ot += d.overtime;
        totals.ut += (d.undertime || 0); totals.late += d.late;
    });

    // Same Civil Service Form 48 the admin DTR Documents page renders, now with
    // Work Hrs / Overtime / Late columns and a full totals row.
    var form48 = window.DTRForm48.render({
        name: res.name || '',
        periodLabel: res.dtr.period,
        dateFrom: res.dtr.date_from,
        dateTo: res.dtr.date_to,
        logMode: (window.DTR_LOG_MODE || 'single'),
        compact: true,
        days: days,
        totals: totals,
        marks: res.marks || {}
    });

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

    // Per-day conversations with HR. The reply box is no longer inside each
    // card — one composer is pinned at the bottom of the screen and addresses
    // whichever day is selected (tap a card to switch, newest is the default).
    var msgDays = (res.days || []).filter(function (d) { return (d.msgs || []).length; });
    _dtrMsgDays = msgDays.map(function (d) { return { rec_id: d.rec_id, date: d.date }; });
    var threads = msgDays.map(function (d) {
        var bubbles = d.msgs.map(function (m) {
            return '<div class="drev-bub ' + (m.from === 'emp' ? 'me' : 'them') + '">'
                + '<div>' + escapeHtml(m.msg) + '</div>'
                + '<div class="mm">' + escapeHtml(m.from === 'emp' ? 'You' : (m.by || 'Support')) + (m.at ? ' · ' + escapeHtml(m.at) : '') + '</div>'
                + '</div>';
        }).join('');
        return '<div class="drev-thread" id="drev-card-' + d.rec_id + '" data-rec="' + d.rec_id + '"'
            + ' role="button" tabindex="0" onclick="setDtrMsgTarget(' + d.rec_id + ')">'
            + '<div class="drev-thread-date"><i class="ri-calendar-event-line"></i> ' + escapeHtml(d.date) + '</div>'
            + '<div class="drev-thread-list" id="drev-thread-' + d.rec_id + '">' + bubbles + '</div>'
            + '</div>';
    }).join('');
    // Conversations move out of this sheet into their own message screen so the
    // Form 48 keeps the whole scroll area; a floating button opens them.
    var msgCount = (res.days || []).reduce(function (n, d) { return n + ((d.msgs || []).length); }, 0);
    var fab = threads
        ? '<div class="drev-fab-wrap"><button type="button" class="drev-fab" onclick="openDtrMessages()"'
            + ' aria-label="Messages with Support"><i class="ri-question-answer-line"></i>'
            + (msgCount ? '<span class="drev-fab-badge">' + msgCount + '</span>' : '') + '</button></div>'
        : '';

    // The Form 48 has fixed column widths and can't shrink below ~340px, so it
    // scrolls sideways inside its own wrapper — contained by the card's content
    // box instead of spilling over the card's padding and border.
    document.getElementById('dtr-review-body').innerHTML =
        reviewedNote + '<div class="drev-f48-card"><div class="drev-f48-wrap">' + form48 + '</div></div>' + fab;

    // Fill the message screen now (not on open) so sendDtrReply can always find
    // its thread list — a hidden Bootstrap modal keeps its DOM in place.
    document.getElementById('dtr-msg-sub').textContent = res.dtr.period;
    document.getElementById('dtr-msg-body').innerHTML = threads
        ? '<div class="drev-stream">' + threads + '</div>'
        : dtrMsgEmptyState();
    // Composer targets the newest conversation until the reader taps another;
    // with nothing to reply to it stands down entirely.
    document.getElementById('modal-dtr-messages').classList.toggle('is-empty', !msgDays.length);
    setDtrMsgTarget(msgDays.length ? msgDays[msgDays.length - 1].rec_id : null);

    // read-only view once approved (status 2) — hide the action footer
    var footer = document.getElementById('dtr-review-footer');
    footer.style.display = (res.dtr.status === 3) ? 'flex' : 'none';
}

// Bootstrap 5.3 strips body.modal-open when ANY modal hides, without checking
// whether a lower one in the stack is still open. Portal CSS keys off that class
// (it stands the FCM push banner down), so the banner would pop back over the
// still-open DTR sheet. Put the class back while a modal remains visible.
document.addEventListener('hidden.bs.modal', function () {
    if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
});

// ── Message screen: keep the composer above the on-screen keyboard ──────────
// iOS/Android shrink the visual viewport instead of the layout viewport, so a
// 100dvh modal keeps its height and the focused composer ends up underneath the
// keyboard. Mirror the keyboard height into --kb and pad the scroller by it.
// Deferred: this script block runs before the modal markup further down the
// page, so binding at parse time would find nothing.
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('modal-dtr-messages');
    var vv = window.visualViewport;
    if (!el || !vv) return;

    function sync() {
        if (!el.classList.contains('show')) return;
        var kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
        el.style.setProperty('--kb', kb + 'px');
        el.classList.toggle('kb-open', kb > 80);   // 80px ignores URL-bar jitter
    }
    vv.addEventListener('resize', sync);
    vv.addEventListener('scroll', sync);
    el.addEventListener('shown.bs.modal', sync);
    el.addEventListener('hidden.bs.modal', function () {
        el.classList.remove('kb-open');
        el.style.removeProperty('--kb');
    });
    // With backdrop:false there's no overlay to click away on, so the empty
    // space around the floating pieces dismisses instead.
    el.addEventListener('click', function (e) {
        // Only genuine outside clicks — not the empty space inside the panel,
        // which is just unfilled scroll area above the conversations.
        if (e.target !== el && !e.target.classList.contains('modal-dialog')) return;
        var inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    });
    // Bring the focused composer into view once the keyboard has settled
    el.addEventListener('focusin', function (e) {
        if (!e.target.closest || !e.target.closest('.drev-thread-in')) return;
        setTimeout(function () {
            sync();
            var t = e.target.closest('.drev-thread');
            if (t) t.scrollIntoView({ block: 'end' });
        }, 260);
    });
});

// Floating button in the DTR sheet → the message screen (stacked over it)
function openDtrMessages() {
    var el = document.getElementById('modal-dtr-messages');
    if (!el) return;
    // backdrop:false — the portal floats over the DTR sheet with no dimming
    // overlay. Tapping the empty space around it closes it (see below), which
    // is what the backdrop would normally have done.
    (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el, { backdrop: false })).show();
    // Open each conversation at its newest message
    el.querySelectorAll('.drev-thread-list').forEach(function (l) { l.scrollTop = l.scrollHeight; });
    // Newest conversation into view
    var body = document.getElementById('dtr-msg-body');
    if (body) body.scrollTop = body.scrollHeight;
}

// Empty message screen — explains why it's empty and what would fill it
function dtrMsgEmptyState() {
    return '<div class="drev-msg-empty">'
        + '<div class="dme-ic"><i class="ri-chat-smile-2-line"></i></div>'
        + '<div class="dme-t">No messages on this DTR</div>'
        + '<div class="dme-d">Nothing needs clarifying — every log in this period came through clean.</div>'
        + '<div class="dme-hint"><i class="ri-information-line"></i>'
        + 'If Support has a question about one of your days, it appears here and you can reply straight back.</div>'
        + '</div>';
}

// Which day's conversation the pinned composer is addressing
function setDtrMsgTarget(recId) {
    _dtrMsgTarget = recId;
    var el = document.getElementById('modal-dtr-messages');
    if (!el) return;
    el.querySelectorAll('.drev-thread').forEach(function (c) {
        c.classList.toggle('is-active', String(c.dataset.rec) === String(recId));
    });
    var day = _dtrMsgDays.filter(function (d) { return String(d.rec_id) === String(recId); })[0];
    var to = document.getElementById('dtr-msg-to');
    var inp = document.getElementById('dtr-msg-input');
    if (to) to.innerHTML = day
        ? '<i class="ri-corner-down-right-line"></i> Replying to <b>' + escapeHtml(day.date) + '</b>'
        : '';
    if (inp) inp.placeholder = day ? 'Message Support about ' + day.date + '…' : 'Reply to Support…';
    // More than one conversation? Say so, so switching is discoverable.
    var many = document.getElementById('dtr-msg-many');
    if (many) many.style.display = _dtrMsgDays.length > 1 ? '' : 'none';
}

// Employee replies to an HR message about one attendance date (two-way thread).
// While the request is in flight the send button spins; on success the input is
// blurred so the on-screen keyboard drops away instead of staying up.
function sendDtrReply(recId) {
    recId = (recId === undefined || recId === null) ? _dtrMsgTarget : recId;
    var inp = document.getElementById('dtr-msg-input');
    var btn = document.getElementById('dtr-msg-send');
    var msg = (inp && inp.value || '').trim();
    if (!msg || !recId) return;
    if (btn && btn.classList.contains('is-loading')) return;   // no double-send
    var busy = function (on) {
        inp.disabled = on;
        if (!btn) return;
        btn.disabled = on;
        btn.classList.toggle('is-loading', on);
        btn.setAttribute('aria-busy', on ? 'true' : 'false');
    };
    busy(true);
    fetch('emp-portal-ajax.php?action=reply_dtr_message', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'rec_id=' + encodeURIComponent(recId) + '&message=' + encodeURIComponent(msg)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.result) { busy(false); Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed' }); return; }
        var list = document.getElementById('drev-thread-' + recId);
        if (list) {
            list.insertAdjacentHTML('beforeend',
                '<div class="drev-bub me"><div>' + escapeHtml(msg) + '</div>'
                + '<div class="mm">You' + (res.at ? ' · ' + escapeHtml(res.at) : '') + '</div></div>');
            list.scrollTop = list.scrollHeight;
        }
        var card = document.getElementById('drev-card-' + recId);
        if (card) card.scrollIntoView({ block: 'end' });
        inp.value = '';
        busy(false);
        // Drop focus so the on-screen keyboard closes and the thread is readable
        inp.blur();
        if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    }).catch(function () { busy(false); Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); });
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
        confirmButtonColor: decision === 1 ? '#6642aa' : '#c62828',
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
        + (pos(d.sun_amt) ? eRow((d.rate_type === 'monthly' || d.rate_type === 'fixed' ? 'Rest Day Duty (' + d.sun_days + ')' : 'Rest Day Premium (' + d.sun_days + ' \u00d7 30%)'), d.sun_amt) : '')
        + (pos(d.spc_amt) ? eRow('Special Holiday (' + d.spc_days + ')', d.spc_amt) : '')
        + (pos(d.late_amt) ? eRow('Late (' + d.late_min + ' min)', d.late_amt, true) : '')
        + '<div class="ps-row" style="margin-top:4px;"><span class="ps-lbl" style="font-weight:800;color:#6642aa;">Gross Pay</span>'
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

// ── Payslip details sheet ───────────────────────────────────────────────────
// Tapping any row in "All Payslips" opens the SAME itemised breakdown used for
// review. The footer adapts: Confirm/Dispute only while the payroll is out for
// review (status 3); otherwise it's a read-only view with a PDF button.
function openPayslipDetails(payrollId) {
    var d = (typeof PAYROLL_REVIEW_DATA !== 'undefined') ? PAYROLL_REVIEW_DATA[payrollId] : null;
    if (!d) return;
    openPayrollReview(payrollId);            // fills body + breakdown, shows modal

    var footer = document.getElementById('payroll-review-footer');
    if (!footer) return;
    var canReview = parseInt(d.status, 10) === 3;
    // Toggle a class, not inline display: these blocks carry Bootstrap's
    // .d-flex (display:flex !important), which would beat an inline style.
    footer.querySelectorAll('.prv-review-only').forEach(function (el) {
        el.classList.toggle('prv-hide', !canReview);
    });
    // The View Payslip button stays available in both states; only the
    // "this payroll is closed" note is review-dependent.
    var note = document.getElementById('prv-closed-note');
    if (note) note.classList.toggle('prv-hide', canReview);
    var dl = document.getElementById('prv-pdf');
    if (dl) dl.setAttribute('onclick', 'openPayslipPreview(' + parseInt(d.item_id, 10) + ')');
}

// ── Payslip preview: the payslip's own HTML page inside the modal ────────────
// It used to embed pdf-payroll.php, but Android has no in-page PDF viewer: an
// <iframe> pointed at a PDF renders a grey "Open" placeholder instead of the
// document, so the installed app showed no payslip at all. view_payslip.php is
// the same document dompdf renders from, it carries a phone layout of its own,
// and ?preview=1 drops its toolbar + auto-print. PDF stays on Download, where
// the platform's own viewer handles it.
function openPayslipPreview(itemId) {
    var frame = document.getElementById('payslip-preview-frame');
    if (!frame) return;
    var id = encodeURIComponent(itemId);
    frame.src = 'view_payslip.php?preview=1&id=' + id;
    var dl = document.getElementById('payslip-preview-download');
    if (dl) {
        dl.href = 'pdf-payroll.php?src=payslip&id=' + id + '&download=1';
        // Standalone PWAs have no address bar to fall back on — marking the link
        // as a download sends the tap to the download manager instead of a blank
        // view. Left valueless on purpose: the server's Content-Disposition name
        // (surname + pay period, see pdf-payroll.php) then wins.
        dl.setAttribute('download', '');
    }
    new bootstrap.Modal(document.getElementById('modal-payslip-preview')).show();
}

// ── Loans: tap a loan card → details + per-payroll deduction history ─────────
// The card only shows the running balance; the history answers "where did my
// money actually go?" by listing every payroll that deducted this loan.
// Fetched on demand (the loans tab would otherwise carry every ledger row on
// page load) and cached per loan, so re-opening the same card is instant.
var LOAN_HIST_CACHE = {};

function loanBox(label, value, cls) {
    return '<div class="lnh-box' + (cls ? ' ' + cls : '') + '"><em>' + label + '</em><b>' + value + '</b></div>';
}

function loanDetailHtml(d) {
    var L = d.loan;
    // "Paid" is derived from the loan itself so it always agrees with the card;
    // the posted total below the list is the sum of the ledger rows, which can
    // differ if a balance was ever corrected by hand.
    var paid = Math.max(0, L.loan_amount - L.loan_balance);
    var pct  = L.loan_amount > 0 ? Math.round(paid / L.loan_amount * 1000) / 10 : 0;
    var rows = d.rows || [];

    var h = '<div class="d-flex align-items-center justify-content-between mb-3">'
        + '<span style="font-weight:800;color:#4e3483;font-size:15px;">' + escapeHtml(L.type_name) + '</span>'
        + (L.settled
            ? '<span style="background:#f0ecf6;color:#4e3483;border-radius:10px;padding:3px 12px;font-size:11px;font-weight:700;">Settled</span>'
            : '<span style="background:#fff8e8;color:#fd7e14;border-radius:10px;padding:3px 12px;font-size:11px;font-weight:700;">Active</span>')
        + '</div>';

    h += '<div class="loan-progwrap" style="margin-bottom:12px;">'
        + '<div class="loan-prog"><div class="loan-prog-bar" style="width:' + pct + '%;"></div></div>'
        + '<span class="loan-pct">' + pct + '% paid</span></div>';

    h += '<div class="lnh-sum">'
        + loanBox('Loan amount', peso(L.loan_amount))
        + loanBox('Remaining balance', peso(L.loan_balance), 'bal')
        + loanBox('Paid so far', peso(paid))
        + loanBox('Per period', peso(L.damount))
        + loanBox('Granted', escapeHtml(L.loan_date || '—'))
        + loanBox('First deduction', escapeHtml(L.effective_date || L.loan_date || '—'))
        + '</div>';

    h += '<div class="lnh-hd"><span><i class="ri-history-line me-1"></i>Deduction history</span>'
        + '<span>' + rows.length + ' payroll' + (rows.length === 1 ? '' : 's') + '</span></div>';

    if (!rows.length) {
        h += '<div class="lnh-empty"><i class="ri-inbox-line"></i>'
            + 'No deductions posted yet. Amortization appears here once a payroll that '
            + 'includes this loan is processed.</div>';
        return h;
    }

    h += '<div class="lnh-list">' + rows.map(function (r) {
        return '<div class="lnh-row">'
            + '<div class="lnh-l">'
            + '<div class="lnh-per">' + escapeHtml(r.period) + '</div>'
            + '<div class="lnh-ref">' + (r.ref_no ? 'Ref ' + escapeHtml(r.ref_no) : 'No reference') + '</div>'
            + '</div>'
            + '<div class="lnh-r">'
            + '<div class="lnh-amt">−' + peso(r.amount) + '</div>'
            + '<div class="lnh-bal">balance ' + peso(r.after) + '</div>'
            + '</div>'
            + '</div>';
    }).join('') + '</div>';

    h += '<div class="lnh-hd" style="margin-bottom:0;"><span>Total posted</span>'
        + '<span style="color:#4e3483;font-size:12px;">' + peso(d.paid_posted) + '</span></div>';
    return h;
}

function openLoanDetail(loanId) {
    loanId = parseInt(loanId, 10);
    if (!loanId) return;
    var body  = document.getElementById('loan-detail-body');
    var title = document.getElementById('loan-detail-title');
    var cached = LOAN_HIST_CACHE[loanId];

    body.innerHTML = cached
        ? loanDetailHtml(cached)
        : '<div class="lnh-load"><i class="ri-loader-4-line"></i>Loading your deduction history…</div>';
    if (title) title.textContent = cached ? cached.loan.type_name : 'Loan Details';
    new bootstrap.Modal(document.getElementById('modal-loan-detail')).show();

    // Always refetch: a payroll processed since the last open would otherwise
    // leave a stale ledger behind the cached view.
    fetch('emp-portal-ajax.php?action=loan_payment_history', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'loan_id=' + encodeURIComponent(loanId)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || !res.result || !res.loan) {
            if (!cached) {
                body.innerHTML = '<div class="lnh-err"><i class="ri-information-line me-1"></i>'
                    + escapeHtml((res && res.message) || 'Could not load this loan.') + '</div>';
            }
            return;
        }
        LOAN_HIST_CACHE[loanId] = res;
        body.innerHTML = loanDetailHtml(res);
        if (title) title.textContent = res.loan.type_name;
    }).catch(function () {
        if (!cached) {
            body.innerHTML = '<div class="lnh-err"><i class="ri-information-line me-1"></i>'
                + 'Request failed. Please check your connection and try again.</div>';
        }
    });
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
                 grid:{ borderColor:'#f0eff3', strokeDashArray:0 },
                 xaxis:{ categories:CHART.labels, labels:{ style:{ fontSize:'10px', colors:'#999' } } },
                 dataLabels:{ enabled:false }, legend:{ fontSize:'11px', markers:{ width:9, height:9 } } };

    if (CHART.labels && CHART.labels.length > 1) {
        // Net vs Gross — area, two series
        new ApexCharts(document.querySelector('#chart-pay'), Object.assign({}, base, {
            chart: Object.assign({ type:'area', height:240 }, base.chart),
            colors:['#6642aa','#4a5bbf'],
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
            colors:['#6642aa','#dc3545'],
            plotOptions:{ bar:{ columnWidth:'55%', borderRadius:3 } },
            series:[ { name:'Present', data:CHART.present }, { name:'Absent', data:CHART.absent } ],
            yaxis:{ labels:{ style:{ fontSize:'10px', colors:'#999' } } }
        })).render();
    }

    // Deduction composition — donut (values also listed in the payslip card below)
    if (DED.length && document.querySelector('#chart-deduct')) {
        new ApexCharts(document.querySelector('#chart-deduct'), {
            chart:{ type:'donut', height:262, fontFamily:'Segoe UI,Arial,sans-serif' },
            colors:['#6642aa','#4a5bbf','#b26a00','#c9366f','#5e35b1','#7a7f2a'],
            series: DED.map(function(d){ return d.value; }),
            labels: DED.map(function(d){ return d.label; }),
            stroke:{ width:2, colors:['#ffffff'] },
            dataLabels:{ enabled:false },
            legend:{ position:'bottom', fontSize:'11px', markers:{ width:9, height:9 } },
            plotOptions:{ pie:{ donut:{ size:'70%', labels:{ show:true,
                value:{ fontSize:'14px', fontWeight:800, color:'#312f38', formatter:function(v){ return peso(v); } },
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
            '<thead><tr style="background:#4e3483;color:#fff;">'+
            '<th style="padding:9px 12px;text-align:left;">Item</th>'+
            '<th style="padding:9px 12px;text-align:right;">A</th>'+
            '<th style="padding:9px 12px;text-align:right;">B</th>'+
            '<th style="padding:9px 12px;text-align:right;">Diff (B−A)</th></tr>'+
            '<tr style="background:#5a3c96;color:rgba(255,255,255,.85);font-size:10px;">'+
            '<td style="padding:4px 12px;">Period</td><td style="padding:4px 12px;text-align:right;">'+a.label+'</td>'+
            '<td style="padding:4px 12px;text-align:right;">'+b.label+'</td><td></td></tr></thead><tbody>'+
            '<tr><td colspan="4" style="padding:6px 12px;background:#f2f0f6;font-weight:800;color:#4e3483;font-size:10px;text-transform:uppercase;">Earnings</td></tr>'+
            row('Basic Pay',a.basic,b.basic)+
            row('Allowance',a.allowance,b.allowance)+
            row('Overtime',a.ot,b.ot)+
            row('Absent (−)',a.absent,b.absent,true)+
            row('Late (−)',a.late,b.late,true)+
            '<tr style="border-top:1px solid #eee;"><td style="padding:7px 12px;font-weight:800;color:#6642aa;">Gross Pay</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(a.gross)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(b.gross)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;" class="'+((b.gross-a.gross)>=0?'earn':'ded')+'">'+((b.gross-a.gross)>0?'+':'')+peso(b.gross-a.gross)+'</td></tr>'+
            '<tr><td colspan="4" style="padding:6px 12px;background:#fdecec;font-weight:800;color:#b02a37;font-size:10px;text-transform:uppercase;">Deductions</td></tr>'+
            row('Contributions',a.contrib,b.contrib,true)+
            row('SSS Fund',a.sss_fund,b.sss_fund,true)+
            row('Tax',a.tax,b.tax,true)+
            row('JEI Advances',a.jei,b.jei,true)+
            row('JCC Advances',a.jcc,b.jcc,true)+
            row('Other',a.other,b.other,true)+
            '<tr style="border-top:1px solid #eee;"><td style="padding:7px 12px;font-weight:800;color:#dc3545;">Total Deductions</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(a.ded)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;">'+peso(b.ded)+'</td><td style="padding:7px 12px;text-align:right;font-weight:800;" class="'+((b.ded-a.ded)<=0?'earn':'ded')+'">'+((b.ded-a.ded)>0?'+':'')+peso(b.ded-a.ded)+'</td></tr>'+
            '</tbody></table>'+
            '<div style="background:linear-gradient(135deg,#6642aa,#4e3483);padding:14px 18px;display:flex;justify-content:space-between;align-items:center;color:#fff;">'+
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
    var q = this.value.trim().toLowerCase();
    var shown = 0;
    document.querySelectorAll('.pslist .psrow').forEach(function (el) {
        var hay = el.getAttribute('data-search') || el.textContent.toLowerCase();
        var hit = !q || hay.indexOf(q) !== -1;
        el.style.display = hit ? '' : 'none';
        if (hit) shown++;
    });
    var none = document.getElementById('ps-no-match');
    if (none) none.style.display = shown ? 'none' : '';
});

// ── Attendance Records — one infinite-scroll card feed (#att-mlist) at EVERY
// width. The old desktop DataTable is retired: the always-matching query below
// keeps the existing feed logic untouched while it now drives desktop too. ──
var attToday = moment().format('YYYY-MM-DD');
// Default view: the last 7 days (today included) rather than only today.
var attWeekAgo = moment().subtract(6, 'days').format('YYYY-MM-DD');
var attFrom  = attWeekAgo, attTo = attToday;
var attMobileMQ = window.matchMedia('(min-width:0px)');   // always true → feed everywhere

// (Re)binds Bootstrap popovers on the log-detail pills just drawn (table or feed).
var ATT_POPOVER_SCOPES = '#att-tbl [data-bs-toggle="popover"], #att-mlist [data-bs-toggle="popover"], #att-detail-body [data-bs-toggle="popover"]';
function initAttPopovers() {
    document.querySelectorAll(ATT_POPOVER_SCOPES).forEach(function (el) {
        var existing = bootstrap.Popover.getInstance(el);
        if (existing) existing.dispose();
        // 'left' (from the server markup) falls off-screen on phones — pin to top there.
        new bootstrap.Popover(el, { sanitize: false, placement: attMobileMQ.matches ? 'top' : 'left' });
        el.addEventListener('shown.bs.popover', function () {
            document.querySelectorAll(ATT_POPOVER_SCOPES).forEach(function (other) {
                if (other !== el) bootstrap.Popover.getInstance(other) && bootstrap.Popover.getInstance(other).hide();
            });
        });
    });
}
// Click outside closes any open popover
document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover')) {
        document.querySelectorAll(ATT_POPOVER_SCOPES).forEach(function (el) {
            var inst = bootstrap.Popover.getInstance(el);
            if (inst) inst.hide();
        });
    }
});

// ── Mobile infinite-scroll feed ──────────────────────────────────
var attM = { start: 0, pageSize: 15, total: null, loading: false, done: false, started: false };

// Click-to-open details modal for one attendance record (table row or card).
function openAttDetail(r) {
    if (!r) return;
    var lbl = function (t) { return '<div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">' + t + '</div>'; };
    var noteText = (r.notes || '').replace(/<[^>]*>/g, '').trim();
    var h = '<div class="d-flex align-items-center justify-content-between mb-3">'
        + '<div style="font-weight:800;color:#4e3483;font-size:15px;line-height:1.3;">' + (r.date || '') + '</div>'
        + '<div>' + (r.type || '') + '</div>'
        + '</div>'
        + '<div class="row g-2" style="font-size:13px;">'
        + '<div class="col-6" style="background:#f9f8fb;border-radius:10px;padding:10px 12px;">' + lbl('Work Hours') + '<b>' + (r.work_hours || '—') + '</b></div>'
        + '<div class="col-6" style="background:#f9f8fb;border-radius:10px;padding:10px 12px;">' + lbl('OT Hours') + '<b>' + (r.ot_hours || '—') + '</b></div>'
        + '<div class="col-12" style="margin-top:10px;">' + lbl('Time In / Out') + (r.time_io || '—') + '</div>'
        + '<div class="col-12" style="margin-top:6px;">'
        + lbl('All Logs' + (r.logs_count ? ' (' + r.logs_count + ')' : ''))
        + '<div style="background:#f9f8fb;border:1px solid #e7e6ed;border-radius:10px;padding:8px 12px;margin-top:3px;">'
        + (r.logs_all || '<span style="color:#aaa;font-size:11px;">No logs</span>') + '</div></div>'
        + ((noteText && noteText !== '—') ? '<div class="col-12" style="margin-top:6px;">' + lbl('Notes') + r.notes + '</div>' : '')
        + '</div>'
        + attThreadHtml(r);
    document.getElementById('att-detail-body').innerHTML = h;
    new bootstrap.Modal(document.getElementById('modal-att-detail')).show();
}

// Conversation with Support about this attendance date — shown in the details
// modal, with a reply box + refresh (same two-way thread + endpoint as DTR review).
function attBubblesHtml(msgs) {
    if (!msgs || !msgs.length) return '<div class="drev-thread-empty">'
        + '<i class="ri-chat-off-line"></i>'
        + '<div class="det">No messages yet</div>'
        + '<div class="des">Ask Support about this day and your conversation appears here.</div>'
        + '</div>';
    return msgs.map(function (m) {
        return '<div class="drev-bub ' + (m.from === 'emp' ? 'me' : 'them') + '">'
            + '<div>' + escapeHtml(m.msg) + '</div>'
            + '<div class="mm">' + escapeHtml(m.from === 'emp' ? 'You' : (m.by || 'Support')) + (m.at ? ' · ' + escapeHtml(m.at) : '') + '</div>'
            + '</div>';
    }).join('');
}

function attThreadHtml(r) {
    if (!r || !r.rec_id) return '';
    return '<div class="drev-msgs" style="margin-top:14px;">'
        + '<div class="drev-thread-hd">'
        + '<span class="drev-thread-date"><i class="ri-question-answer-line"></i> Messages with Support</span>'
        + '<button type="button" class="drev-refresh" id="att-thread-rf-' + r.rec_id + '" onclick="refreshAttThread(' + r.rec_id + ')" title="Check for replies"><i class="ri-refresh-line"></i></button>'
        + '</div>'
        + '<div class="drev-thread">'
        + '<div class="drev-thread-list" id="att-thread-' + r.rec_id + '">' + attBubblesHtml(r.msgs) + '</div>'
        + '<div class="drev-thread-in">'
        + '<input type="text" id="att-thread-in-' + r.rec_id + '" maxlength="500" placeholder="Message Support about this date…" '
        + 'onkeydown="if(event.key===\'Enter\'){event.preventDefault();sendAttReply(' + r.rec_id + ');}">'
        + '<button type="button" class="drev-send" id="att-thread-send-' + r.rec_id + '" onclick="sendAttReply(' + r.rec_id + ')" title="Send"><i class="ri-send-plane-2-line"></i></button>'
        + '</div></div></div>';
}

function refreshAttThread(recId) {
    var rf = document.getElementById('att-thread-rf-' + recId);
    if (rf) rf.classList.add('spinning');
    fetch('emp-portal-ajax.php?action=dtr_message_thread', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'rec_id=' + encodeURIComponent(recId)
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.result) {
            var list = document.getElementById('att-thread-' + recId);
            if (list) { list.innerHTML = attBubblesHtml(res.msgs); list.scrollTop = list.scrollHeight; }
        }
    }).catch(function () {}).finally(function () {
        setTimeout(function () { if (rf) rf.classList.remove('spinning'); }, 500);
    });
}

function sendAttReply(recId) {
    var inp  = document.getElementById('att-thread-in-' + recId);
    var send = document.getElementById('att-thread-send-' + recId);
    var msg  = (inp && inp.value || '').trim();
    if (!msg) return;
    inp.disabled = true;
    if (send) { send.disabled = true; send.classList.add('sending'); send.innerHTML = '<i class="ri-loader-4-line"></i>'; }
    fetch('emp-portal-ajax.php?action=reply_dtr_message', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'rec_id=' + encodeURIComponent(recId) + '&message=' + encodeURIComponent(msg)
    }).then(function (r) { return r.json(); }).then(function (res) {
        var restore = function () {
            inp.disabled = false;
            if (send) { send.disabled = false; send.classList.remove('sending'); send.innerHTML = '<i class="ri-send-plane-2-line"></i>'; }
        };
        if (!res.result) { restore(); Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed' }); return; }
        var list = document.getElementById('att-thread-' + recId);
        if (list) {
            var empty = list.querySelector('.drev-thread-empty');
            if (empty) empty.remove();
            list.insertAdjacentHTML('beforeend',
                '<div class="drev-bub me"><div>' + escapeHtml(msg) + '</div>'
                + '<div class="mm">You' + (res.at ? ' · ' + escapeHtml(res.at) : '') + '</div></div>');
            list.scrollTop = list.scrollHeight;
        }
        inp.value = ''; restore(); inp.focus();
        if (window.attTable) window.attTable.ajax.reload(null, false);   // refresh the row badge
    }).catch(function () {
        inp.disabled = false;
        if (send) { send.disabled = false; send.classList.remove('sending'); send.innerHTML = '<i class="ri-send-plane-2-line"></i>'; }
        Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
    });
}

function attMCard(r) {
    // One compact list row per day — same shape as the payslip / leave lists
    // (.psrow). Tap opens the full attendance details sheet.
    var row = document.createElement('div');
    row.className = 'psrow attrow';

    // The date cell arrives as two stacked <div>s (date + weekday) — pull the
    // text out so it can be laid out as headline + meta.
    var dtmp = document.createElement('span');
    dtmp.innerHTML = r.date || '';
    var dv = dtmp.querySelectorAll('div');
    var d1 = dv[0] ? dv[0].textContent.trim() : dtmp.textContent.trim();
    var d2 = dv[1] ? dv[1].textContent.trim() : '';

    var noteText = (r.notes || '').replace(/<[^>]*>/g, '').trim();
    var hasNote  = noteText && noteText !== '—';
    var otNum    = parseFloat(String(r.ot_hours || '').replace(/[^0-9.]/g, '')) || 0;

    row.innerHTML =
        '<span class="psrow-main">' +
            '<span class="psrow-period">' + d1 + (d2 ? ' <small class="attrow-day">' + d2 + '</small>' : '') + '</span>' +
            '<span class="psrow-meta">' + (r.type || '') +
                '<span class="attrow-io">' + (r.time_io || '') + '</span>' +
            '</span>' +
            (hasNote ? '<span class="psrow-meta attrow-note"><i class="ri-sticky-note-line"></i>' + noteText + '</span>' : '') +
        '</span>' +
        '<span class="psrow-right">' +
            '<span class="psrow-net">' + (r.work_hours || '0.00') + '</span>' +
            '<span class="psrow-sub">' + (otNum > 0 ? 'hrs · +' + r.ot_hours + ' ot' : 'work hrs') + '</span>' +
        '</span>' +
        '<i class="ri-arrow-right-s-line psrow-chev"></i>';

    row.style.cursor = 'pointer';
    row.title = 'Tap to view details';
    row.addEventListener('click', function (e) {
        // Taps on the log-detail popover pills keep their own behavior.
        if (e.target.closest('[data-bs-toggle="popover"]') || e.target.closest('.popover')) return;
        openAttDetail(r);
    });
    return row;
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
        attTryOpenTarget(res.data);
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
        // Whole row opens the details modal; taps on the log popover pills keep their own behavior.
        createdRow: function (row, data) {
            row.style.cursor = 'pointer';
            row.title = 'Tap to view details';
            row.addEventListener('click', function (e) {
                if (e.target.closest('[data-bs-toggle="popover"]') || e.target.closest('.popover')) return;
                openAttDetail(data);
            });
        },
        drawCallback: function (settings) {
            var json = settings.json;
            var c = document.getElementById('att-count');
            if (c && json) c.textContent = json.recordsFiltered;
            initAttPopovers();
            attTryOpenTarget(json && json.data);
        },
    });
}

// Deep-link target: when a notification points at a specific attendance record,
// open its details modal once that record shows up in the loaded data.
window._attOpenRec = null;
function attTryOpenTarget(rows) {
    if (!window._attOpenRec || !rows) return;
    var hit = rows.find(function (r) { return parseInt(r.rec_id, 10) === window._attOpenRec; });
    if (hit) { window._attOpenRec = null; openAttDetail(hit); }
}

// Jump to the Attendance tab, frame the date in a 7-day window, and open the
// record's details. Called from a notification click (link tab=attendance).
function goAttendanceRecord(recId, dateStr) {
    switchTab('attendance', null);
    if (dateStr && window.moment) {
        attTo   = dateStr;
        attFrom = moment(dateStr).subtract(6, 'days').format('YYYY-MM-DD');
        var lbl = (attFrom === attToday && attTo === attToday) ? 'Today'
                : moment(attFrom).format('MMM D, YYYY') + ' – ' + moment(dateStr).format('MMM D, YYYY');
        var lblEl = document.getElementById('att-range-label');
        if (lblEl) lblEl.textContent = lbl;
    }
    window._attOpenRec = recId ? parseInt(recId, 10) : null;
    if (window.attTable) window.attTable.ajax.reload();      // desktop
    if (attM.started || attMobileMQ.matches) attMReset();     // mobile feed
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
        startDate: moment().subtract(6, 'days'),
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
        var lbl;
        if (attFrom === attToday && attTo === attToday) lbl = 'Today';
        else if (attFrom === moment().subtract(6, 'days').format('YYYY-MM-DD') && attTo === attToday) lbl = 'Last 7 Days';
        else lbl = picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY');
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
        refreshOtLimit();               // OT ceiling is per-date — re-check it
    });
    $rd.on('cancel.daterangepicker', function () {
        $rd.val('');
        $('#att-req-date-hidden').val('');
        refreshOtLimit();
    });
});

// ── "File a Request" claimed time in/out — clock-timepicker web component ────
// (assets2/vendor/clock-timepicker.js). The picker's named input carries the
// 24-hour HH:mm the backend (submit_attendance_request → applyIncidentToDtr)
// expects; the .ctp-display twin mirrors it as 12-hour text ("8:00 PM").
$(function () {
    document.querySelectorAll('#att-request-form .ctp-12h').forEach(function (wrap) {
        var ctp  = wrap.querySelector('clock-timepicker');
        var disp = wrap.querySelector('.ctp-display');
        function sync() {
            var v = ctp.value;                       // 'HH:mm' or undefined
            if (v) {
                var p = v.split(':'), h = parseInt(p[0], 10);
                disp.value = (h % 12 || 12) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
            } else {
                disp.value = '';
            }
            if (window.jQuery && jQuery.fn.parsley) jQuery('#att-request-form').parsley().validate();
        }
        ctp.addEventListener('input', sync);         // live while the popup is open
        ctp.addEventListener('change', sync);
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
// Always true → the #areq-mlist card feed renders at every width (the desktop
// DataTable is retired), reusing the existing infinite-scroll logic as-is.
var areqMobileMQ = window.matchMedia('(min-width:0px)');
var areqM = { start: 0, pageSize: 15, total: null, loading: false, done: false, started: false };
var AREQ_ENDPOINT = 'attendance-requests-portal-server.php';

// Click-to-open details modal for a request row/card (shares one modal).
function openAreqDetail(r) {
    if (!r) return;
    var typeIcon = r.type_key === 'incident' ? 'ri-error-warning-line' : 'ri-timer-flash-line';
    var lbl = function (t) { return '<div style="font-size:9.5px;font-weight:800;color:#8f8c98;text-transform:uppercase;letter-spacing:.3px;">' + t + '</div>'; };
    var h = '<div class="d-flex align-items-center justify-content-between mb-3">'
        + '<span style="font-weight:800;color:#4e3483;font-size:16px;"><i class="' + typeIcon + ' me-1"></i>' + (r.type_label || 'Request') + '</span>'
        + '<span style="background:' + (r.status_color || '#888') + ';color:#fff;border-radius:10px;padding:3px 12px;font-size:11px;font-weight:800;">' + (r.status_label || '') + '</span>'
        + '</div>'
        + '<div class="row g-2" style="font-size:12.5px;">'
        + '<div class="col-6">' + lbl('Request Date') + (r.date_plain || '—') + '</div>'
        + '<div class="col-6">' + lbl('Filed') + (r.filed || '—') + '</div>'
        + '<div class="col-12">' + lbl('Reason') + (r.reason_plain || '—') + '</div>'
        + '<div class="col-12">' + lbl('Details') + (r.details_html || '—') + '</div>'
        + '</div>'
        + (r.reviewer_html
            ? '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e7e6ed;font-size:12px;color:#555;">' + lbl('Reviewer Notes') + r.reviewer_html + '</div>'
            : '');
    document.getElementById('areq-detail-body').innerHTML = h;
    new bootstrap.Modal(document.getElementById('modal-areq-detail')).show();
}

function areqCard(r) {
    // Compact list row — same shape as the payslip / leave / attendance lists.
    // Tap opens the full request details sheet.
    var row = document.createElement('div');
    row.className = 'psrow attrow st-' + (r.status_slug || 'pending');
    var typeIcon = r.type_key === 'incident' ? 'ri-error-warning-line' : 'ri-timer-flash-line';
    var pending  = (r.status_slug || 'pending') === 'pending';
    if (pending) row.classList.add('needs');

    var reason = (r.reason_plain || '').replace(/<[^>]*>/g, '').trim();
    var reviewer = (r.reviewer_html || '').replace(/<[^>]*>/g, '').trim();

    row.innerHTML =
        '<span class="psrow-main">' +
            '<span class="psrow-period"><i class="' + typeIcon + ' attrow-tic"></i>' + (r.type_label || 'Request') + '</span>' +
            '<span class="psrow-meta">' +
                '<span class="psrow-ref"><i class="ri-calendar-event-line"></i> ' + (r.date_plain || '—') + '</span>' +
                '<span class="psbadge" style="background:' + (r.status_color || '#888') + '1a;color:' + (r.status_color || '#888') + ';border:1px solid ' + (r.status_color || '#888') + '40;">' + (r.status_label || '') + '</span>' +
            '</span>' +
            (reason ? '<span class="psrow-meta attrow-note"><i class="ri-question-line"></i>' + reason + '</span>' : '') +
            (reviewer ? '<span class="psrow-meta attrow-note"><i class="ri-chat-1-line"></i>' + reviewer + '</span>' : '') +
        '</span>' +
        '<span class="psrow-right">' +
            '<span class="psrow-sub" style="margin:0 0 2px;">filed</span>' +
            '<span class="attrow-filed">' + (r.filed || '—') + '</span>' +
        '</span>' +
        '<i class="ri-arrow-right-s-line psrow-chev"></i>';

    row.style.cursor = 'pointer';
    row.title = 'Tap to view details';
    row.addEventListener('click', function () { openAreqDetail(r); });
    return row;
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
        // Whole row opens the details modal (same data object the mobile cards use).
        createdRow: function (row, data) {
            row.style.cursor = 'pointer';
            row.title = 'Tap to view details';
            row.addEventListener('click', function () { openAreqDetail(data); });
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
            <div class="modal-header">
                <div>
                    <!-- color is explicit: the theme's global h1-h6 rule sets
                         --vz-heading-color on headings, which would otherwise
                         win over the header's own colour. -->
                    <h5 class="modal-title mb-0" style="color:#4e3483;"><i class="ri-file-list-3-line me-1" style="color:#6642aa;"></i>Review My DTR</h5>
                    <div id="dtr-review-sub" style="font-size:12px;color:#908c9c;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dtr-review-body" style="background:#f9f8fb;">
                <div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>
            </div>
            <div class="modal-footer" id="dtr-review-footer" style="background:#fff;flex-direction:column;align-items:stretch;gap:8px;">
                <textarea id="dtr-review-comment" class="form-control" rows="2"
                    placeholder="Add a comment (required if disputing)…" style="font-size:13px;border-radius:10px;"></textarea>
                <div class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn" style="background:#fdecea;color:#c62828;font-weight:700;border-radius:10px;"
                        onclick="submitDtrReview(2)"><i class="ri-error-warning-line me-1"></i>Dispute</button>
                    <button type="button" class="btn" style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border-radius:10px;"
                        onclick="submitDtrReview(1)"><i class="ri-checkbox-circle-line me-1"></i>Confirm<span class="prv-btn-long"> — Looks Correct</span></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: DTR messages with Support — opened by the floating button inside the
     DTR review sheet, stacked on top of it (Bootstrap 5.3 handles the stack;
     the z-index bump below keeps it above the portal's own modal override). -->
<div class="modal fade" id="modal-dtr-messages" tabindex="-1" aria-labelledby="dtr-msg-title">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="dtr-msg-titlewrap">
                    <h5 class="modal-title mb-0" id="dtr-msg-title"><i class="ri-question-answer-line me-1"></i>Messages with Support</h5>
                    <div id="dtr-msg-sub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="dtr-msg-body"></div>
            <!-- Composer is pinned to the bottom like a chat app. It addresses
                 the selected day's thread; tapping another card switches it. -->
            <div class="drev-composer">
                <div class="drev-composer-to" id="dtr-msg-to"></div>
                <div class="drev-thread-in">
                    <input type="text" id="dtr-msg-input" maxlength="500" placeholder="Reply to Support…"
                        onkeydown="if(event.key==='Enter'){event.preventDefault();sendDtrReply();}">
                    <button type="button" id="dtr-msg-send" onclick="sendDtrReply()" title="Send">
                        <i class="ri-send-plane-2-line"></i></button>
                </div>
                <div class="drev-composer-hint" id="dtr-msg-many" style="display:none;">
                    <i class="ri-information-line"></i>Tap another day above to reply to it instead.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Payslip Review / Sign-off -->
<div class="modal fade" id="modal-payroll-review" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#4e3483;border-bottom:1px solid #f0eff3;">
                <div>
                    <h5 class="modal-title mb-0" style="color:#4e3483;"><i class="ri-file-list-3-line me-1" style="color:#6642aa;"></i>Review My Payslip</h5>
                    <div id="payroll-review-sub" style="font-size:12px;color:#908c9c;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="payroll-review-body" style="background:#f9f8fb;">
                <div class="mydtr-empty"><i class="ri-loader-4-line"></i> Loading…</div>
            </div>
            <div class="modal-footer" id="payroll-review-footer" style="background:#fff;flex-direction:column;align-items:stretch;gap:8px;">
                <!-- Shown only while the payroll is out for review (status 3) -->
                <textarea id="payroll-review-comment" class="form-control prv-review-only" rows="2"
                    placeholder="Add a comment (required if disputing)…" style="font-size:13px;border-radius:10px;"></textarea>
                <div class="d-flex gap-2 justify-content-end prv-review-only">
                    <button type="button" class="btn" style="background:#fdecea;color:#c62828;font-weight:700;border-radius:10px;"
                        onclick="submitPayrollReview(2)"><i class="ri-error-warning-line me-1"></i>Dispute</button>
                    <button type="button" class="btn" style="background:linear-gradient(135deg,#107c41,#0e6b37);color:#fff;font-weight:700;border-radius:10px;"
                        onclick="submitPayrollReview(1)"><i class="ri-checkbox-circle-line me-1"></i>Confirm<span class="prv-btn-long"> — Looks Correct</span></button>
                </div>
                <!-- Always available: the payslip document itself. Only the
                     "closed" note is conditional — while a payroll is out for
                     review the employee still needs to open the payslip to
                     decide whether to confirm or dispute it. -->
                <div id="prv-readonly" class="d-flex gap-2 justify-content-between align-items-center">
                    <span id="prv-closed-note" style="font-size:11.5px;color:#908c9c;"><i class="ri-lock-2-line me-1"></i>This payroll is closed — view only.</span>
                    <button type="button" id="prv-pdf" class="btn btn-sm"
                        style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;border-radius:10px;">
                        <i class="ri-file-text-line me-1"></i>View Payslip
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Payslip Preview (view before printing) -->
<div class="modal fade" id="modal-payslip-preview" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#4e3483;border-bottom:1px solid #f0eff3;">
                <h5 class="modal-title mb-0" style="color:#4e3483;"><i class="ri-file-text-line me-1" style="color:#6642aa;"></i>Payslip Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <!-- Light backdrop: the frame holds the payslip's own HTML page now,
                 not a PDF viewer, so the old dark PDF-chrome grey just flashed. -->
            <div class="modal-body" style="background:#e8e8e8;padding:0;">
                <iframe id="payslip-preview-frame" title="Payslip preview" loading="lazy"
                        style="width:100%;height:70vh;border:0;display:block;background:#e8e8e8;"></iframe>
            </div>
            <!-- Centered action bar. Bootstrap right-aligns modal footers, which
                 left Download hugging the edge; and the footer Close is dropped
                 (phones already hid it — portal-mobile.css) so the one real
                 action sits dead center at every width. The header ✕ and Esc
                 still dismiss. -->
            <div class="modal-footer" style="background:#fff;justify-content:center;">
                <a id="payslip-preview-download" href="#" class="btn btn-sm" style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;">
                    <i class="ri-download-2-line me-1"></i>Download PDF
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Request a Leave -->
<!-- Leave Details Modal (opened by tapping a row in My Leave Requests) -->
<div class="modal fade" id="modal-leave-detail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                    <i class="ri-calendar-event-line me-2"></i>Leave Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="leave-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Loan Details Modal (opened by tapping a loan card in the Loans tab) -->
<div class="modal fade" id="modal-loan-detail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                    <i class="ri-bank-line me-2"></i><span id="loan-detail-title">Loan Details</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="loan-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Record Details Modal (opened by tapping a row/card in Attendance Records) -->
<div class="modal fade" id="modal-att-detail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                    <i class="ri-fingerprint-line me-2"></i>Attendance Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="att-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Request Details Modal (opened by tapping a row/card in My Requests) -->
<div class="modal fade" id="modal-areq-detail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                    <i class="ri-timer-flash-line me-2"></i>Request Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="areq-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-leave-request" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="leave-request-form" data-parsley-validate novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                        <i class="ri-calendar-event-line me-2"></i>Request a Leave
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Type of Leave <span style="color:red">*</span></label>
                            <select name="leave_type_id" class="form-control" onchange="updateLvBalance()" data-parsley-required-message="Please select a leave type." required>
                                <option value="">Select leave type…</option>
                                <?php foreach ($leave_types_list as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="lv-bal-hint" style="display:none;font-size:11.5px;font-weight:700;margin-top:5px;"></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Duration <span style="color:red">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="lv-dur-btn active" data-val="full" onclick="setLvDuration('full')"
                                    style="flex:1;padding:7px;border:1.5px solid #6642aa;background:#6642aa;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    Full Day
                                </button>
                                <button type="button" class="lv-dur-btn" data-val="AM" onclick="setLvDuration('AM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    AM Half
                                </button>
                                <button type="button" class="lv-dur-btn" data-val="PM" onclick="setLvDuration('PM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    PM Half
                                </button>
                            </div>
                            <input type="hidden" name="is_half_day" id="lv-is-half" value="0">
                            <input type="hidden" name="half_period" id="lv-half-period" value="">
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Leave Day(s) <span style="color:red">*</span></label>
                            <input type="text" id="lv-dates" class="form-control" placeholder="Pick one or more days…" readonly
                                data-parsley-required-message="Please select at least one leave day." required>
                            <input type="hidden" name="dates" id="lv-dates-hidden">
                            <div style="font-size:10.5px;color:#999;margin-top:3px;" id="lv-date-hint">
                                <i class="ri-information-line"></i> Holidays are disabled. <span id="lv-half-hint"></span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6" id="lv-dur" style="display:none;font-size:12px;color:#4e3483;font-weight:700;align-self:flex-end;">
                            <i class="ri-time-line"></i> Total: <span id="lv-dur-val">0</span> day(s)
                        </div>
                        <!-- Multi-day half-day: which end of the selection is the half -->
                        <div class="col-12" id="lv-half-on-wrap" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Half Day Falls On <span style="color:red">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="lv-halfon-btn" data-val="first" onclick="setLvHalfOn('first')"
                                    style="flex:1;padding:7px;border:1.5px solid #6642aa;background:#6642aa;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    First Day
                                </button>
                                <button type="button" class="lv-halfon-btn" data-val="last" onclick="setLvHalfOn('last')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    Last Day
                                </button>
                            </div>
                            <input type="hidden" name="half_on" id="lv-half-on" value="first">
                        </div>
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Reason / Purpose <span style="color:red">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="State the reason for your leave"
                                data-parsley-required-message="Please state your reason for leave." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="lv-submit" class="btn btn-sm" style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;">
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
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Duration <span style="color:red">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="lwop-dur-btn" data-val="full" onclick="setLwopDuration('full')"
                                    style="flex:1;padding:7px;border:1.5px solid #c62828;background:#c62828;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    Full Day
                                </button>
                                <button type="button" class="lwop-dur-btn" data-val="AM" onclick="setLwopDuration('AM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    AM Half
                                </button>
                                <button type="button" class="lwop-dur-btn" data-val="PM" onclick="setLwopDuration('PM')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    PM Half
                                </button>
                            </div>
                            <input type="hidden" name="is_half_day" id="lwop-is-half" value="0">
                            <input type="hidden" name="half_period" id="lwop-half-period" value="">
                        </div>
                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Leave Day(s) <span style="color:red">*</span></label>
                            <input type="text" id="lwop-dates" class="form-control" placeholder="Pick one or more days…" readonly
                                data-parsley-required-message="Please select at least one LWOP day." required>
                            <input type="hidden" name="dates" id="lwop-dates-hidden">
                            <div style="font-size:10.5px;color:#999;margin-top:3px;">
                                <i class="ri-information-line"></i> Holidays are disabled. <span id="lwop-half-hint"></span>
                            </div>
                        </div>
                        <div class="col-12" id="lwop-dur" style="display:none;font-size:12px;color:#c62828;font-weight:700;">
                            <i class="ri-time-line"></i> Total: <span id="lwop-dur-val">0</span> day(s) without pay
                        </div>
                        <!-- Multi-day half-day: which end of the selection is the half -->
                        <div class="col-12" id="lwop-half-on-wrap" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#c62828;text-transform:uppercase;letter-spacing:.4px;">Half Day Falls On <span style="color:red">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="lwop-halfon-btn" data-val="first" onclick="setLwopHalfOn('first')"
                                    style="flex:1;padding:7px;border:1.5px solid #c62828;background:#c62828;color:#fff;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    First Day
                                </button>
                                <button type="button" class="lwop-halfon-btn" data-val="last" onclick="setLwopHalfOn('last')"
                                    style="flex:1;padding:7px;border:1.5px solid #b9b4c5;background:#fff;color:#555;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
                                    Last Day
                                </button>
                            </div>
                            <input type="hidden" name="half_on" id="lwop-half-on" value="first">
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
                    <h6 class="modal-title" style="color:#4e3483;font-weight:700;">
                        <i class="ri-timer-flash-line me-2"></i>File a Request
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Request Type <span style="color:red;">*</span></label>
                            <select name="request_type" class="form-control" id="att-req-type" onchange="toggleAttFields(this.value)"
                                data-parsley-required-message="Please select a request type." required>
                                <option value="">— Select type —</option>
                                <option value="incident">Incident Report (missed/wrong scan)</option>
                                <option value="overtime">Overtime Authorization Request</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Date <span style="color:red;">*</span></label>
                            <input type="text" id="att-req-date" class="form-control" placeholder="Select a date…" readonly
                                data-parsley-required-message="Please select a date." required>
                            <input type="hidden" name="request_date" id="att-req-date-hidden">
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Reason <span style="color:red;">*</span></label>
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
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Claimed Time In <span style="color:red;">*</span></label>
                            <div class="ctp-12h">
                                <input type="text" id="att-time-in-disp" class="form-control ctp-display" placeholder="e.g. 8:00 AM" readonly tabindex="-1"
                                    data-parsley-required-message="Please enter your claimed time in.">
                                <clock-timepicker format="HH:mm" precision="00:05">
                                    <input type="text" name="claimed_time_in" autocomplete="off">
                                </clock-timepicker>
                            </div>
                        </div>
                        <div class="col-6 att-incident-field" style="display:none;">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Claimed Time Out <span style="color:red;">*</span></label>
                            <div class="ctp-12h">
                                <input type="text" id="att-time-out-disp" class="form-control ctp-display" placeholder="e.g. 5:00 PM" readonly tabindex="-1"
                                    data-parsley-required-message="Please enter your claimed time out.">
                                <clock-timepicker format="HH:mm" precision="00:05">
                                    <input type="text" name="claimed_time_out" autocomplete="off">
                                </clock-timepicker>
                            </div>
                        </div>

                        <!-- OT fields -->
                        <div class="col-12 att-ot-field" style="display:none;">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">OT Hours Requested <span style="color:red;">*</span></label>
                                    <input type="number" name="ot_hours_requested" id="att-ot-hours" class="form-control" min="0.5" max="12" step="0.5" placeholder="e.g. 2.5"
                                        data-parsley-type="number" data-parsley-otlimit="true"
                                        data-parsley-required-message="Please enter the OT hours requested.">
                                </div>
                                <!-- Live ceiling from the day's actual scans (ot_request_limit) — the
                                     same rule the server re-checks on submit. -->
                                <div class="col-12">
                                    <div id="att-ot-limit-hint" style="display:none;font-size:11.5px;line-height:1.4;border-radius:10px;padding:9px 12px;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label style="font-size:11px;font-weight:700;color:#4e3483;text-transform:uppercase;letter-spacing:.4px;">Notes / Explanation</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Describe what happened…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#6642aa,#4e3483);color:#fff;font-weight:700;border:none;">
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
    var THRESHOLD = 70, MAX_PULL = 110;
    var startY = null, pulling = false, refreshing = false;

    var indicator = document.createElement('div');
    indicator.id = 'ptr-indicator';
    indicator.innerHTML = '<i class="ri-refresh-line"></i>';
    document.body.appendChild(indicator);

    function currentTabId() {
        var activePanel = document.querySelector('.tab-panel.active');
        return activePanel ? activePanel.id.replace(/^tab-/, '') : 'overview';
    }

    // Spin the indicator and reload back onto the same tab (via the ?tab= deep-link).
    // Shared by the manual pull gesture and the FCM auto-refresh below.
    function triggerRefresh() {
        if (refreshing) return;
        refreshing = true;
        indicator.classList.add('spin');
        indicator.style.transform = 'translateY(16px)';
        setTimeout(function () { location.href = 'employee-portal.php?tab=' + encodeURIComponent(currentTabId()); }, 2000);
    }
    window.portalRefresh = triggerRefresh;

    // A new FCM push arrived while the portal is open → refresh to pull in the new
    // content, unless the user is mid-task in a sheet/modal/notification panel.
    window.addEventListener('fcm:foreground-message', function () {
        if (document.querySelector('.more-sheet.open, .modal.show, .emp-notif-panel.open')) return;
        triggerRefresh();
    });

    if (!('ontouchstart' in window)) return; // manual pull gesture: touch devices only

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
            triggerRefresh();
        } else {
            indicator.style.transform = 'translateY(-100px)';
            indicator.classList.remove('ready');
        }
        startY = null;
    });
})();
</script>
<!-- Portrait-only guard. Shown by CSS only on a short landscape phone — the
     last line of defence when the manifest's "orientation": "portrait" isn't
     honoured (see assets2/css/portal-mobile.css). Inert at every other size. -->
<div id="rotate-guard" aria-hidden="true">
    <i class="ri-phone-lock-line"></i>
    <div class="rg-t">Please rotate your phone upright</div>
    <div class="rg-s">The portal is designed for portrait — turn your device back and it will pick up right where you left off.</div>
</div>

<!-- Native-app interactions: swipe tab navigation, bottom-sheet drag, notification
     swipe-to-dismiss, per-screen titles. Loads last so it can wrap switchTab & co. -->
<script src="<?= av('assets2/js/portal-mobile.js') ?>"></script>
</body>
</html>
