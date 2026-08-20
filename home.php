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

// Department Heads (role 8) see people-stats scoped to their own department.
// Payroll/finance analytics stay company-wide. Fragments are '' when unscoped.
require_once 'dept-scope.php';
$dsD   = dept_scope_sql('department_id');      // employee table, no alias
$dsE   = dept_scope_sql('e.department_id');    // queries joining employee e
$dsSub = dept_scope_emp_sql('employee_id');    // employee_id-only tables (leave_requests, DTR_details, …)

$total_employees = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE status=1 $dsD");
$total_inactive  = db_count($conn, "SELECT COUNT(*) AS c FROM employee WHERE status=0 $dsD");
$total_clusters  = db_count($conn, "SELECT COUNT(*) AS c FROM department");
$total_positions = db_count($conn, "SELECT COUNT(*) AS c FROM position");
$total_users     = db_count($conn, "SELECT COUNT(*) AS c FROM users WHERE role!=1 AND status=1");
$total_payrolls  = db_count($conn, "SELECT COUNT(*) AS c FROM payroll");
$pending_dtr     = db_count($conn, "SELECT COUNT(*) AS c FROM DTR WHERE status=1");
$approved_dtr    = db_count($conn, "SELECT COUNT(*) AS c FROM DTR WHERE status=2");

// ── Payroll status breakdown (full lifecycle: Draft → Calculated → Ready for Review → Locked) ──
$pay_new        = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=0");
$pay_calculated = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=1");
$pay_review     = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=3");
$pay_locked     = db_count($conn, "SELECT COUNT(*) AS c FROM payroll WHERE status=2");

// ── Loan balance summary ────────────────────────────────────────
$loan_res      = $conn->query("SELECT COUNT(DISTINCT employee_id) AS borrowers, COALESCE(SUM(loan_balance),0) AS total FROM loans WHERE loan_status=0 AND loan_balance>0");
$loan_data     = $loan_res ? $loan_res->fetch_assoc() : ['borrowers'=>0,'total'=>0];

// ── Role-based sections ─────────────────────────────────────────
// The dashboard is the one screen everybody lands on, so its sections follow
// the same gate as the pages they link to — otherwise a role locked out of
// Payroll would still read the net-pay totals off a card here.
// Role 7 (Auditor) additionally has no leave management.
$can_approve  = in_array($login_role, [1, 8, 9], true);
$show_finance = page_allowed('payroll') && $login_role !== 7;   // payroll figures
$show_dtr     = page_allowed('dtr');                            // DTR cards & links
$show_leave   = $login_role !== 7;

// ── Leave overview stats ────────────────────────────────────────
$leave_new_today  = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE date_applied = CURDATE() $dsSub");
$leave_new_week   = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE date_applied >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) $dsSub");
// Pending count awaiting each approval stage, in workflow order (config-driven).
$leave_stage_defs = leave_stages();
$leave_stage_keys = array_keys($leave_stage_defs);
$leave_wait_by_stage = [];
foreach ($leave_stage_keys as $sk) {
    $leave_wait_by_stage[$sk] = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE " . leave_stage_pending_predicate($sk) . " $dsSub");
}
// The two dashboard tiles show the first two stages of the chain.
$leave_wait_hr    = $leave_wait_by_stage[$leave_stage_keys[0]] ?? 0;   // 1st stage (e.g. Supervisor)
$leave_wait_admin = $leave_wait_by_stage[$leave_stage_keys[1]] ?? 0;   // 2nd stage (e.g. Department Head)
$leave_stage1_lbl = $leave_stage_defs[$leave_stage_keys[0]]['label'] ?? 'Stage 1';
$leave_stage2_lbl = $leave_stage_defs[$leave_stage_keys[1]]['label'] ?? 'Stage 2';
$leave_appr_month = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE status=1 AND MONTH(date_from)=MONTH(CURDATE()) AND YEAR(date_from)=YEAR(CURDATE()) $dsSub");
$leave_rej_month  = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE status=2 AND MONTH(date_applied)=MONTH(CURDATE()) AND YEAR(date_applied)=YEAR(CURDATE()) $dsSub");

// Employees on approved leave covering today (count + list for the widget)
$on_leave_today_count = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE status=1 AND CURDATE() BETWEEN date_from AND date_to $dsSub");
$on_leave_today = [];
$olr = $conn->query("
    SELECT lr.date_from, lr.date_to, lr.duration, COALESCE(lr.is_half_day,0) AS is_half_day,
           CONCAT(e.firstname,' ',e.lastname) AS emp_name,
           lt.name AS leave_type, COALESCE(d.name,'—') AS dept
    FROM leave_requests lr
    INNER JOIN employee e ON e.id = lr.employee_id
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE lr.status = 1 AND CURDATE() BETWEEN lr.date_from AND lr.date_to $dsE
    ORDER BY lr.date_to ASC LIMIT 10
");
if ($olr) while ($r = $olr->fetch_assoc()) { $on_leave_today[] = $r; }

// Upcoming approved leaves (next 14 days)
$upcoming_leave_count = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE status=1 AND date_from > CURDATE() AND date_from <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) $dsSub");
$upcoming_leaves = [];
$ulr = $conn->query("
    SELECT lr.date_from, lr.date_to, lr.duration,
           CONCAT(e.firstname,' ',e.lastname) AS emp_name,
           lt.name AS leave_type, COALESCE(d.name,'—') AS dept
    FROM leave_requests lr
    INNER JOIN employee e ON e.id = lr.employee_id
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE lr.status = 1 AND lr.date_from > CURDATE()
      AND lr.date_from <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) $dsE
    ORDER BY lr.date_from ASC LIMIT 10
");
if ($ulr) while ($r = $ulr->fetch_assoc()) { $upcoming_leaves[] = $r; }

// Approved leave days by type — current year (donut)
$lvtype_labels = []; $lvtype_data = [];
$ltr = $conn->query("
    SELECT lt.name, COALESCE(SUM(lr.duration),0) AS days
    FROM leave_requests lr
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.status = 1 AND YEAR(lr.date_from) = YEAR(CURDATE())" . dept_scope_emp_sql('lr.employee_id') . "
    GROUP BY lt.id, lt.name ORDER BY days DESC LIMIT 8
");
if ($ltr) while ($r = $ltr->fetch_assoc()) { $lvtype_labels[] = $r['name']; $lvtype_data[] = round((float)$r['days'], 1); }

// Leave days taken per month — last 6 months (filed & approved, by leave start date)
$lv_month_labels = []; $lv_month_days = []; $lv_month_reqs = [];
$lmr = $conn->query("
    SELECT DATE_FORMAT(date_from,'%Y-%m') AS ym,
           COALESCE(SUM(duration),0) AS days, COUNT(*) AS reqs
    FROM leave_requests
    WHERE status = 1 AND date_from >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') $dsSub
    GROUP BY DATE_FORMAT(date_from,'%Y-%m')
");
$lv_by_month = [];
if ($lmr) while ($r = $lmr->fetch_assoc()) { $lv_by_month[$r['ym']] = $r; }
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i months"));
    $lv_month_labels[] = date('M Y', strtotime($ym . '-01'));
    $lv_month_days[]   = isset($lv_by_month[$ym]) ? round((float)$lv_by_month[$ym]['days'], 1) : 0;
    $lv_month_reqs[]   = isset($lv_by_month[$ym]) ? (int)$lv_by_month[$ym]['reqs'] : 0;
}

// ── Today's Attendance Highlights — duty in/out, hours, OT for CURDATE() ──
$today_str = date('Y-m-d');
$today_rows = [];
$tar = $conn->query("
    SELECT dd.work_hours, dd.late, dd.overtime, dd.logs,
           CONCAT(e.firstname,' ',e.lastname) AS emp_name,
           COALESCE(d.name,'—') AS dept_name
    FROM DTR_details dd
    INNER JOIN employee e ON e.id = dd.employee_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE dd.date_time = '" . $conn->real_escape_string($today_str) . "'
      AND UPPER(LEFT(COALESCE(dd.attendance_type,'P'),1)) <> 'A' $dsE
");
$today_late_count = 0; $today_ot_total = 0; $today_on_duty = 0;
if ($tar) while ($r = $tar->fetch_assoc()) {
    $logs = json_decode($r['logs'] ?? '[]', true) ?: [];
    usort($logs, fn($a, $b) => strcmp($a['dateTime'] ?? '', $b['dateTime'] ?? ''));
    $in  = $logs[0]['dateTime']  ?? null;
    $out = count($logs) > 1 ? end($logs)['dateTime'] : null;
    $isLate = (float) $r['late'] > 0;
    if ($isLate) $today_late_count++;
    if ($in && !$out) $today_on_duty++;
    $today_ot_total += (float) $r['overtime'];
    $today_rows[] = [
        'name' => $r['emp_name'], 'dept' => $r['dept_name'],
        'in' => $in, 'out' => $out,
        'hours' => (float) $r['work_hours'], 'ot' => (float) $r['overtime'], 'late' => (float) $r['late'],
    ];
}
$today_present_count = count($today_rows);
// Absent = active headcount minus present minus those on approved leave today.
$today_absent_count  = max(0, $total_employees - $today_present_count - $on_leave_today_count);
// Priority for the highlight list: still on duty first, then late, then biggest OT — cap at 10, rest via "View Full Board".
usort($today_rows, function ($a, $b) {
    $pa = ($a['in'] && !$a['out']) ? 0 : ($a['late'] > 0 ? 1 : 2);
    $pb = ($b['in'] && !$b['out']) ? 0 : ($b['late'] > 0 ? 1 : 2);
    if ($pa !== $pb) return $pa - $pb;
    return $b['ot'] <=> $a['ot'];
});
$today_rows_shown    = array_slice($today_rows, 0, 10);
$today_rows_overflow = max(0, count($today_rows) - count($today_rows_shown));

// ── Action needed: pending approvals ────────────────────────────
$pending_leaves  = db_count($conn, "SELECT COUNT(*) AS c FROM leave_requests WHERE status=0 $dsSub");
$pending_att_req = db_count($conn, "SELECT COUNT(*) AS c FROM attendance_requests WHERE status=0 $dsSub");

// ── Action needed: open employee disputes (unresolved) ──────────
$open_dtr_disputes     = db_count($conn, "SELECT COUNT(*) AS c FROM dtr_employee_reviews WHERE status=2 AND resolved_at IS NULL");
$open_payroll_disputes = db_count($conn, "SELECT COUNT(*) AS c FROM payroll_employee_reviews WHERE status=2 AND resolved_at IS NULL");

// ── Action needed: schedule changes not yet applied to open DTR batches ──
// A batch counts when any of its rows carries a stamped shift that disagrees
// with the employee's current schedule assignment (admin changed a schedule
// after the punches were recorded). Fixed by Recompute on the batch screen;
// final-approved batches are locked and excluded.
$stale_sched_batches = db_count($conn, "SELECT COUNT(DISTINCT d.ddtr_id) AS c
    FROM DTR_details d INNER JOIN DTR ON DTR.id = d.ddtr_id
    WHERE DTR.status <> 2 AND " . dtr_schedule_mismatch_where('d'));

// ── Schedule changes: made in the last 7 days or taking effect soon ──────
$sched_changes = [];
$scr = $conn->query("
    SELECT CONCAT(e.lastname, ', ', e.firstname) AS emp_name,
           COALESCE(ws.description, '—') AS shift,
           es.effective_from, es.created_at, u.name AS changed_by_name
    FROM employee_schedules es
    INNER JOIN employee e ON e.id = es.employee_id
    LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
    LEFT JOIN users u ON u.id = es.changed_by
    WHERE (es.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           OR es.effective_from BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY))
          $dsE
    ORDER BY es.created_at DESC
    LIMIT 8
");
if ($scr) while ($r = $scr->fetch_assoc()) { $sched_changes[] = $r; }

// ── Pending leave requests (top 6, for the approval widget) ────
$pending_leave_list = [];
$plr = $conn->query("
    SELECT lr.date_applied, lr.date_from, lr.date_to, lr.duration,
           CONCAT(e.firstname,' ',e.lastname) AS emp_name,
           lt.name AS leave_type, COALESCE(d.name,'—') AS dept
    FROM leave_requests lr
    INNER JOIN employee e ON e.id = lr.employee_id
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE lr.status = 0 $dsE
    ORDER BY lr.date_applied ASC LIMIT 6
");
if ($plr) while ($r = $plr->fetch_assoc()) { $pending_leave_list[] = $r; }

// ── Daily attendance – last 14 days (present headcount) ────────
$att_day_labels = []; $att_day_present = []; $att_day_ot = []; $att_day_late = [];
$adr = $conn->query("
    SELECT DATE(date_time) AS d,
           COUNT(DISTINCT employee_id) AS present,
           COUNT(DISTINCT CASE WHEN late > 0 THEN employee_id END) AS late_cnt,
           COALESCE(SUM(overtime),0) AS ot_hrs
    FROM DTR_details
    WHERE date_time >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      AND UPPER(LEFT(COALESCE(attendance_type,'P'),1)) <> 'A' $dsSub
    GROUP BY DATE(date_time) ORDER BY d ASC
");
$att_by_day = [];
if ($adr) while ($r = $adr->fetch_assoc()) { $att_by_day[$r['d']] = $r; }
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $att_day_labels[]  = date('M d', strtotime($d));
    $att_day_present[] = isset($att_by_day[$d]) ? (int)$att_by_day[$d]['present'] : 0;
    $att_day_ot[]      = isset($att_by_day[$d]) ? round((float)$att_by_day[$d]['ot_hrs'], 1) : 0;
    $att_day_late[]    = isset($att_by_day[$d]) ? (int)$att_by_day[$d]['late_cnt'] : 0;
}

// ── Employees by classification ─────────────────────────────────
$class_labels = []; $class_data = [];
$clr = $conn->query("
    SELECT COALESCE(cl.clasification,'Unassigned') AS cls, COUNT(e.id) AS cnt
    FROM employee e LEFT JOIN clasification cl ON cl.id = e.clasification_id
    WHERE e.status=1 $dsE GROUP BY cl.id, cl.clasification ORDER BY cnt DESC
");
if ($clr) while ($r = $clr->fetch_assoc()) { $class_labels[] = $r['cls']; $class_data[] = (int)$r['cnt']; }

// ── Upcoming holidays & events (next 6) ─────────────────────────
$upcoming_events = [];
$uer = $conn->query("
    SELECT title, start_date, end_date, type, color, note
    FROM calendar_events
    WHERE COALESCE(end_date, start_date) >= CURDATE()
    ORDER BY start_date ASC LIMIT 6
");
if ($uer) while ($r = $uer->fetch_assoc()) { $upcoming_events[] = $r; }

// ── Computed payrolls (for the department-breakdown period selector) ─
// Calculated / in-review / locked all carry real payroll_items figures; drafts don't.
$locked_payrolls = [];
$lpr = $conn->query("
    SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status, e.employer_name
    FROM payroll p LEFT JOIN employers e ON p.employer_id = e.id
    WHERE p.status IN (1,2,3) ORDER BY p.id DESC LIMIT 15
");
if ($lpr) while ($r = $lpr->fetch_assoc()) { $locked_payrolls[] = $r; }

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
    WHERE e.status=1 $dsE GROUP BY d.id, d.name ORDER BY cnt DESC LIMIT 10
");
while ($r = $dept_res->fetch_assoc()) { $dept_labels[] = $r['dept']; $dept_data[] = (int)$r['cnt']; }

// ── Top 5 positions by employee count ──────────────────────────
$pos_labels = []; $pos_data = [];
$pos_res = $conn->query("
    SELECT p.name, COUNT(e.id) AS cnt
    FROM employee e LEFT JOIN position p ON e.position_id = p.id
    WHERE e.status=1 $dsE GROUP BY p.id ORDER BY cnt DESC LIMIT 6
");
while ($r = $pos_res->fetch_assoc()) { $pos_labels[] = $r['name'] ?: 'Unassigned'; $pos_data[] = (int)$r['cnt']; }

// ── Latest payroll snapshot — locked preferred, else newest computed run ──
$snap_res = $conn->query("
    SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status, e.employer_name,
        COUNT(pi.id) AS emp_count,
        COALESCE(SUM(pi.net), 0) AS total_net,
        COALESCE(SUM(" . sql_gross('pi') . "), 0) AS total_gross
    FROM payroll p
    LEFT JOIN employers e ON p.employer_id = e.id
    LEFT JOIN payroll_items pi ON pi.payroll_id = p.id
    WHERE p.status IN (1,2,3)
    GROUP BY p.id ORDER BY (p.status = 2) DESC, p.id DESC LIMIT 1
");
$snap = $snap_res ? $snap_res->fetch_assoc() : null;
$pay_status_lbl = [0=>'Draft', 1=>'Calculated', 3=>'In Review', 2=>'Locked'];

// ── Payroll by department — reusable so the AJAX endpoint shares it ──
// (home-dashboard-ajax.php includes this same helper via home-dashboard-data.php)
require_once 'home-dashboard-data.php';
// Passing 0 returns empty series. A role without payroll access must not
// receive these figures at all — hiding the cards is not enough while the
// numbers still ship to the browser as chart JSON in the page source.
$deptpay = dashboard_deptpay($conn, ($show_finance && $snap) ? (int)$snap['id'] : 0);
if (!$show_finance) { $netpay_labels = []; $netpay_data = []; }
$deptpay_labels = $deptpay['labels'];
$deptpay_emp    = $deptpay['emp'];
$deptpay_net    = $deptpay['net'];
$deptpay_gross  = $deptpay['gross'];
$deptpay_ded    = $deptpay['ded'];
$deptpay_avg    = $deptpay['avg'];
$deptpay_ot     = $deptpay['ot'];
$deptpay_late   = $deptpay['late'];

// ── Birthdays this month ────────────────────────────────────────
$bday_res = $conn->query("
    SELECT e.firstname, e.lastname, e.bday, COALESCE(d.name,'—') AS dept
    FROM employee e LEFT JOIN department d ON e.department_id = d.id
    WHERE e.status=1 AND MONTH(e.bday) = MONTH(CURDATE()) $dsE
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
// ═══════════════════════════════════════════════════════════════
// NEW ANALYTICS — attendance discipline, payroll cost mix, loans,
// leave turnaround, workforce mix
// ═══════════════════════════════════════════════════════════════

// ── Attendance rate — average present headcount over the days that have
//    records in the last 14 days, as a share of active headcount.
$att_days_with_data = array_values(array_filter($att_day_present, fn($v) => $v > 0));
$att_rate_14d = ($total_employees > 0 && count($att_days_with_data))
    ? round(array_sum($att_days_with_data) / count($att_days_with_data) / $total_employees * 100, 1) : 0;

// ── Late & undertime — last 30 days, per day (hours) + late incident count ──
$lu_labels = []; $lu_late_hrs = []; $lu_ut_hrs = []; $lu_late_cnt = [];
$lur = $conn->query("
    SELECT DATE(date_time) AS d,
           COALESCE(SUM(late),0) AS late_hrs, COALESCE(SUM(undertime),0) AS ut_hrs,
           SUM(late > 0) AS late_cnt
    FROM DTR_details
    WHERE date_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      AND UPPER(LEFT(COALESCE(attendance_type,'P'),1)) <> 'A' $dsSub
    GROUP BY DATE(date_time)
");
$lu_by_day = [];
if ($lur) while ($r = $lur->fetch_assoc()) { $lu_by_day[$r['d']] = $r; }
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $lu_labels[]   = date('M d', strtotime($d));
    $lu_late_hrs[] = isset($lu_by_day[$d]) ? round((float)$lu_by_day[$d]['late_hrs'], 2) : 0;
    $lu_ut_hrs[]   = isset($lu_by_day[$d]) ? round((float)$lu_by_day[$d]['ut_hrs'], 2) : 0;
    $lu_late_cnt[] = isset($lu_by_day[$d]) ? (int)$lu_by_day[$d]['late_cnt'] : 0;
}
$late_30d_incidents = array_sum($lu_late_cnt);
$late_30d_hours     = round(array_sum($lu_late_hrs), 1);
$ut_30d_hours       = round(array_sum($lu_ut_hrs), 1);

// ── Top late employees — last 30 days (incidents + total late hours) ──
$toplate_labels = []; $toplate_cnt = []; $toplate_hrs = []; $toplate_dept = [];
$tlr = $conn->query("
    SELECT CONCAT(e.lastname, ', ', e.firstname) AS emp_name, COALESCE(d.name,'—') AS dept,
           SUM(dd.late > 0) AS cnt, COALESCE(SUM(dd.late),0) AS hrs
    FROM DTR_details dd
    INNER JOIN employee e ON e.id = dd.employee_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE dd.date_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND dd.late > 0 $dsE
    GROUP BY dd.employee_id ORDER BY cnt DESC, hrs DESC LIMIT 8
");
if ($tlr) while ($r = $tlr->fetch_assoc()) {
    $toplate_labels[] = $r['emp_name']; $toplate_dept[] = $r['dept'];
    $toplate_cnt[] = (int)$r['cnt']; $toplate_hrs[] = round((float)$r['hrs'], 1);
}

// ── Overtime hours by month — last 6 months (+ headcount who rendered OT) ──
$ot_month_labels = []; $ot_month_hrs = []; $ot_month_emp = [];
$omr = $conn->query("
    SELECT DATE_FORMAT(date_time,'%Y-%m') AS ym,
           COALESCE(SUM(overtime),0) AS hrs,
           COUNT(DISTINCT CASE WHEN overtime > 0 THEN employee_id END) AS emp
    FROM DTR_details
    WHERE date_time >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01') $dsSub
    GROUP BY DATE_FORMAT(date_time,'%Y-%m')
");
$ot_by_month = [];
if ($omr) while ($r = $omr->fetch_assoc()) { $ot_by_month[$r['ym']] = $r; }
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i months"));
    $ot_month_labels[] = date('M Y', strtotime($ym . '-01'));
    $ot_month_hrs[]    = isset($ot_by_month[$ym]) ? round((float)$ot_by_month[$ym]['hrs'], 1) : 0;
    $ot_month_emp[]    = isset($ot_by_month[$ym]) ? (int)$ot_by_month[$ym]['emp'] : 0;
}

// ── Attendance / OT requests — last 30 days by type × outcome ──
$areq = ['incident'=>['p'=>0,'a'=>0,'r'=>0], 'overtime'=>['p'=>0,'a'=>0,'r'=>0], 'rest_day'=>['p'=>0,'a'=>0,'r'=>0]];
$arr = $conn->query("
    SELECT request_type, status, COUNT(*) AS c FROM attendance_requests
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) $dsSub
    GROUP BY request_type, status
");
if ($arr) while ($r = $arr->fetch_assoc()) {
    $k = ($r['status'] == 1) ? 'a' : (($r['status'] == 2) ? 'r' : 'p');
    if (isset($areq[$r['request_type']])) $areq[$r['request_type']][$k] = (int)$r['c'];
}
$areq_total_30d = array_sum(array_map('array_sum', $areq));

// ── Payroll cost mix + deductions mix — for the snapshot payroll ─────
$costmix = ['Basic Pay'=>0, 'Allowances'=>0, 'Overtime'=>0, 'Holiday & Rest-day Premium'=>0, 'Night Differential'=>0];
$dedmix  = ['Contributions, Loans & Deductions'=>0, 'Withholding Tax'=>0, 'Late & Undertime'=>0, 'Cash Advances / SSS Fund'=>0, 'Other Deductions'=>0];
$snap_total_ded = 0;
if ($show_finance && $snap) {
    $pm   = sql_per_minute('pi');
    $spec = (float) SPECIAL_HOLIDAY_PREMIUM_RATE;
    $rest = (float) REST_DAY_PREMIUM_RATE;
    $cmr  = $conn->query("
        SELECT
          COALESCE(SUM(CASE WHEN pi.rate_type IN ('monthly','fixed') THEN pi.basic_pay/2 - pi.absent*pi.per_day
                            ELSE (pi.present + COALESCE(pi.paid_leave,0)) * pi.per_day END),0) AS basic,
          COALESCE(SUM(pi.allowance_amount*pi.allowance_days + COALESCE(pi.allowance_total,0)),0) AS allow,
          COALESCE(SUM(pi.ot*pi.ot_rate),0) AS ot,
          COALESCE(SUM(pi.legal_holiday*pi.per_day
                     + CASE WHEN pi.rate_type IN ('monthly','fixed') THEN pi.sunday_duty*pi.per_day ELSE pi.sunday_duty*pi.per_day*$rest END
                     + pi.special_holiday*pi.per_day*$spec),0) AS hol,
          COALESCE(SUM(COALESCE(pi.nsd_amount,0)),0) AS nsd,
          COALESCE(SUM(pi.deduction_amount),0) AS ded_main,
          COALESCE(SUM(pi.tax),0) AS tax,
          COALESCE(SUM((pi.late + pi.under_time) * $pm),0) AS lateut,
          COALESCE(SUM(pi.jei_advances + pi.jcc_advances + COALESCE(pi.sss_fund,0)),0) AS adv,
          COALESCE(SUM(pi.other_deduction),0) AS other
        FROM payroll_items pi WHERE pi.payroll_id = " . (int)$snap['id']);
    if ($cmr && ($cm = $cmr->fetch_assoc())) {
        $costmix = ['Basic Pay'=>round((float)$cm['basic'],2), 'Allowances'=>round((float)$cm['allow'],2), 'Overtime'=>round((float)$cm['ot'],2),
                    'Holiday & Rest-day Premium'=>round((float)$cm['hol'],2), 'Night Differential'=>round((float)$cm['nsd'],2)];
        $dedmix  = ['Contributions, Loans & Deductions'=>round((float)$cm['ded_main'],2), 'Withholding Tax'=>round((float)$cm['tax'],2),
                    'Late & Undertime'=>round((float)$cm['lateut'],2), 'Cash Advances / SSS Fund'=>round((float)$cm['adv'],2), 'Other Deductions'=>round((float)$cm['other'],2)];
        $snap_total_ded = array_sum($dedmix);
    }
}

// ── Active loan portfolio by type ───────────────────────────────
$loan_type_labels = []; $loan_type_bal = []; $loan_type_cnt = [];
if ($show_finance) {
    $ltr2 = $conn->query("
        SELECT COALESCE(REPLACE(REPLACE(t.loan_type, '\\n', ' '), '\\r', ''), 'Other') AS lt,
               COUNT(*) AS cnt, COALESCE(SUM(l.loan_balance),0) AS bal
        FROM loans l LEFT JOIN contribution_loan_types t ON t.clt_id = l.loan_type
        WHERE l.loan_status = 0 AND l.loan_balance > 0
        GROUP BY l.loan_type ORDER BY bal DESC LIMIT 8
    ");
    if ($ltr2) while ($r = $ltr2->fetch_assoc()) {
        $loan_type_labels[] = trim(preg_replace('/\s+/', ' ', $r['lt'])); $loan_type_bal[] = round((float)$r['bal'], 2); $loan_type_cnt[] = (int)$r['cnt'];
    }
}

// ── Leave approval turnaround (last 90 days) + pending aging ────
$leave_turnaround_days = null; $leave_decided_90d = 0;
$ltar = $conn->query("
    SELECT COUNT(*) AS c,
           AVG(DATEDIFF(GREATEST(COALESCE(sec_at,'1970-01-01'), COALESCE(sup_at,'1970-01-01'),
                                 COALESCE(hr_at,'1970-01-01'),  COALESCE(admin_at,'1970-01-01')), date_applied)) AS avg_days
    FROM leave_requests
    WHERE status IN (1,2) AND date_applied >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      AND GREATEST(COALESCE(sec_at,'1970-01-01'), COALESCE(sup_at,'1970-01-01'),
                   COALESCE(hr_at,'1970-01-01'),  COALESCE(admin_at,'1970-01-01')) > '1971-01-01' $dsSub
");
if ($ltar && ($r = $ltar->fetch_assoc())) { $leave_decided_90d = (int)$r['c']; $leave_turnaround_days = $r['avg_days'] !== null ? round((float)$r['avg_days'], 1) : null; }
$leave_aging = ['0-2 days'=>0, '3-5 days'=>0, '6+ days'=>0];
$lagr = $conn->query("
    SELECT SUM(DATEDIFF(CURDATE(), date_applied) <= 2) AS a,
           SUM(DATEDIFF(CURDATE(), date_applied) BETWEEN 3 AND 5) AS b,
           SUM(DATEDIFF(CURDATE(), date_applied) >= 6) AS c
    FROM leave_requests WHERE status = 0 $dsSub
");
if ($lagr && ($r = $lagr->fetch_assoc())) { $leave_aging = ['0-2 days'=>(int)$r['a'], '3-5 days'=>(int)$r['b'], '6+ days'=>(int)$r['c']]; }

// ── Workforce mix — rate type ───────────────────────────────────
$rate_labels = []; $rate_data = [];
$rtr = $conn->query("SELECT COALESCE(NULLIF(rate_type,''),'daily') AS rt, COUNT(*) AS c FROM employee WHERE status=1 $dsD GROUP BY rt ORDER BY c DESC");
if ($rtr) while ($r = $rtr->fetch_assoc()) { $rate_labels[] = ucfirst($r['rt']); $rate_data[] = (int)$r['c']; }

// ── Headcount by area (top 8) ───────────────────────────────────
$area_labels = []; $area_data = [];
$aar = $conn->query("
    SELECT COALESCE(a.name,'Unassigned') AS nm, COUNT(e.id) AS c
    FROM employee e LEFT JOIN area a ON a.id = e.area_id
    WHERE e.status=1 $dsE GROUP BY e.area_id ORDER BY c DESC LIMIT 8
");
if ($aar) while ($r = $aar->fetch_assoc()) { $area_labels[] = $r['nm']; $area_data[] = (int)$r['c']; }
?>
<style>
    /* ── Dashboard design tokens ─────────────────────────────── */
    .dash-stat { height:100%; border-top:3px solid #673bb6; border-radius:8px; background:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(57,75,124,.07); transition:box-shadow .2s, transform .15s; }
    .dash-stat:hover { box-shadow:0 4px 16px rgba(57,75,124,.13); transform:translateY(-1px); }
    .dash-stat .ds-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:21px; flex-shrink:0; }
    .dash-stat .ds-val { font-size:22px; font-weight:800; color:#673bb6; line-height:1; }
    .dash-stat .ds-lbl { font-size:11px; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
    .dash-stat .ds-sub { font-size:11px; color:#aaa; margin-top:2px; }
    .pay-status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:4px; }
    .snap-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f0f0f0; }
    .snap-row:last-child { border-bottom:none; }
    .snap-lbl { font-size:11px; color:#888; }
    .snap-val { font-size:14px; font-weight:800; }
    .bday-item { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f5f5f5; }
    .bday-item:last-child { border-bottom:none; }
    .bday-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#6642aa,#4e3483); color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; flex-shrink:0; }
    .bday-day { margin-left:auto; background:#f0ecf6; color:#4e3483; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:700; white-space:nowrap; }
    /* Action-needed cards */
    .action-card { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid #eee; border-left:4px solid var(--ac,#e6a817); border-radius:8px; padding:12px 16px; text-decoration:none; color:inherit; box-shadow:0 1px 4px rgba(57,75,124,.07); transition:box-shadow .2s, transform .15s; height:100%; }
    .action-card:hover { box-shadow:0 4px 16px rgba(57,75,124,.15); transform:translateY(-1px); color:inherit; }
    .action-card .ac-ic { width:40px; height:40px; border-radius:9px; background:#f4f6f9; background:color-mix(in srgb, var(--ac) 12%, #fff); color:var(--ac); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .action-card .ac-val { font-size:20px; font-weight:800; color:var(--ac); line-height:1; }
    .action-card .ac-lbl { font-size:11.5px; color:#777; margin-top:3px; }
    .action-card .ac-go { margin-left:auto; color:#ccc; font-size:18px; }
    /* Event / pending-leave list rows */
    .ev-row { display:flex; align-items:center; gap:10px; padding:7px 0; border-bottom:1px solid #f5f5f5; }
    .ev-row:last-child { border-bottom:none; }
    .ev-date { width:42px; flex-shrink:0; text-align:center; background:#f4f6f9; border-radius:8px; padding:3px 0; }
    .ev-date .d { font-size:14px; font-weight:800; color:#333; line-height:1.1; }
    .ev-date .m { font-size:9px; font-weight:800; color:#673bb6; text-transform:uppercase; }
    /* Payroll pipeline stage tracker */
    .pipeline { display:flex; align-items:center; justify-content:space-between; gap:3px; }
    .pl-stage { flex:1 1 0; min-width:0; text-align:center; background:#f8f9fb; border:1px solid #eef0f2; border-radius:10px; padding:10px 3px; }
    .pl-ic { width:34px; height:34px; border-radius:9px; margin:0 auto 5px; display:flex; align-items:center; justify-content:center; font-size:17px; }
    .pl-n { font-size:18px; font-weight:800; line-height:1; }
    .pl-t { font-size:9px; color:#888; text-transform:uppercase; letter-spacing:.2px; margin-top:3px; font-weight:700; white-space:nowrap; }
    .pl-arrow { color:#cfd4da; font-size:15px; flex-shrink:0; }
    .s-new  .pl-ic { background:#efebf6; color:#673bb6; } .s-new  .pl-n { color:#673bb6; }
    .s-calc .pl-ic { background:#eef0fb; color:#4a5bbf; } .s-calc .pl-n { color:#4a5bbf; }
    .s-rev  .pl-ic { background:#fff6e0; color:#c98a00; } .s-rev  .pl-n { color:#c98a00; }
    .s-lock .pl-ic { background:#fbe9f0; color:#c9366f; } .s-lock .pl-n { color:#c9366f; }
    /* Today's Attendance highlight stats + duty in/out chips */
    .today-att-stat { background:#f8f9fb; border:1px solid #eef0f2; border-radius:10px; padding:10px 12px; border-left:3px solid var(--ac,#673bb6); height:100%; }
    .today-att-stat .tas-v { font-size:19px; font-weight:800; color:var(--ac,#673bb6); line-height:1; }
    .today-att-stat .tas-l { font-size:10.5px; color:#888; margin-top:4px; }
    .tai-chip { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:700; white-space:nowrap; }
    .tai-chip.in  { background:#eeeaf5; color:#673bb6; }
    .tai-chip.out { background:#fce4ec; color:#c62828; }
    /* Section headers + anchor navigation */
    .dash-section { scroll-margin-top:130px; }
    .dash-sec-hd { display:flex; align-items:center; gap:10px; margin:6px 0 10px; }
    .dash-sec-hd h6 { margin:0; font-size:12px; font-weight:800; color:#8a94a6; text-transform:uppercase; letter-spacing:.6px; white-space:nowrap; }
    .dash-sec-hd .line { flex-grow:1; border-top:1px dashed #e3e7ee; }
    .dash-sec-hd .hint { font-size:11px; color:#aab; white-space:nowrap; }
    .dash-nav { position:sticky; top:70px; z-index:5; display:flex; flex-wrap:wrap; gap:6px; padding:8px 10px; margin-bottom:14px; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border:1px solid #eef0f2; border-radius:10px; box-shadow:0 1px 4px rgba(57,75,124,.06); }
    .dash-nav a { font-size:11.5px; font-weight:700; color:#666; text-decoration:none; padding:5px 11px; border-radius:20px; border:1px solid transparent; transition:all .15s; }
    .dash-nav a:hover, .dash-nav a.active { background:#f0ecf6; color:#673bb6; border-color:#d7d0e6; }
    .dash-nav a i { margin-right:4px; }
    .dash-nav .spacer { flex-grow:1; }
    .dash-nav .refreshed { font-size:10.5px; color:#aab; align-self:center; white-space:nowrap; }
    /* Mini stat strip inside cards */
    .mini-strip { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .mini-stat { flex:1 1 0; min-width:90px; background:#f8f9fb; border:1px solid #eef0f2; border-radius:8px; padding:8px 10px; border-left:3px solid var(--ac,#673bb6); }
    .mini-stat .mv { font-size:16px; font-weight:800; color:var(--ac,#673bb6); line-height:1; }
    .mini-stat .ml { font-size:10px; color:#888; margin-top:3px; }
    /* Aging bar for pending leaves */
    .aging { display:flex; height:10px; border-radius:6px; overflow:hidden; background:#f0f2f5; margin:6px 0 4px; }
    .aging span { display:block; height:100%; }
    .aging-legend { display:flex; gap:12px; font-size:10.5px; color:#777; flex-wrap:wrap; }
    .aging-legend i { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:4px; }
    .card-title .sub { font-size:10px; color:#aaa; font-weight:600; margin-left:4px; }
    .empty-state { text-align:center; padding:26px 8px; color:#aaa; }
    .empty-state i { font-size:30px; }
    .empty-state div { font-size:12px; margin-top:6px; }
    .th-p { background:#673bb6; color:#fff; padding:8px 12px; font-size:11px; border:none; }
    .qa-btn { background:#eef0f8; color:#673bb6; border:1px solid #d0d7ee; font-weight:600; }
    /* Theme CSS paints donut centre text with --vz-body-color, which can resolve to white; pin it. */
    .dash-section .apexcharts-datalabels-group .apexcharts-datalabel-value { fill:#333 !important; }
    .dash-section .apexcharts-datalabels-group .apexcharts-datalabel-label { fill:#999 !important; }
    @media (max-width: 767.98px) { .dash-nav { position:static; } }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-dashboard-line me-2" style="color:#673bb6;"></i>Dashboard</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Dashboard</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section navigation ── -->
            <nav class="dash-nav" id="dash-nav">
                <a href="#sec-overview"><i class="ri-layout-grid-line"></i>Overview</a>
                <a href="#sec-today"><i class="ri-calendar-check-line"></i>Today</a>
                <a href="#sec-attendance"><i class="ri-time-line"></i>Attendance &amp; Time</a>
                <?php if ($show_finance): ?><a href="#sec-payroll"><i class="ri-money-dollar-circle-line"></i>Payroll &amp; Finance</a><?php endif; ?>
                <?php if ($show_leave): ?><a href="#sec-leave"><i class="ri-calendar-event-line"></i>Leave</a><?php endif; ?>
                <a href="#sec-workforce"><i class="ri-team-line"></i>Workforce</a>
                <a href="#sec-activity"><i class="ri-history-line"></i>Activity</a>
                <span class="spacer"></span>
                <span class="refreshed"><i class="ri-refresh-line me-1"></i>Live counters refresh every 60s · <span id="dash-refreshed"><?= date('h:i A') ?></span></span>
            </nav>

            <!-- ══════════════ OVERVIEW ══════════════ -->
            <div id="sec-overview" class="dash-section">
            <div class="row g-3 mb-3">
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat">
                        <div class="ds-icon" style="background:#f4f1f9;"><i class="ri-group-line" style="color:#673bb6;"></i></div>
                        <div>
                            <div class="ds-val" data-stat="employees"><?= $total_employees ?></div>
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
                            <div class="ds-sub"><?= $total_positions ?> positions</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#0891b2;">
                        <div class="ds-icon" style="background:#e6f7fb;"><i class="ri-percent-line" style="color:#0891b2;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#0891b2;"><?= $att_rate_14d ?>%</div>
                            <div class="ds-lbl">Attendance Rate</div>
                            <div class="ds-sub">avg daily · last 14 days</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#e6a817;">
                        <div class="ds-icon" style="background:#fff6e0;"><i class="ri-alarm-warning-line" style="color:#c98a00;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#c98a00;"><?= $late_30d_incidents ?></div>
                            <div class="ds-lbl">Late Incidents</div>
                            <div class="ds-sub"><?= $late_30d_hours ?>h late · last 30 days</div>
                        </div>
                    </div>
                </div>
                <?php if ($show_finance): ?>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#6f42c1;">
                        <div class="ds-icon" style="background:#f2eefb;"><i class="ri-money-dollar-circle-line" style="color:#6f42c1;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#6f42c1;"><?= $total_payrolls ?></div>
                            <div class="ds-lbl">Payrolls</div>
                            <div class="ds-sub"><?= $pay_locked ?> locked · <?= $pay_review ?> in review</div>
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
                <?php else: ?>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#fd7e14;">
                        <div class="ds-icon" style="background:#fff4ec;"><i class="ri-time-line" style="color:#fd7e14;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#fd7e14;" data-stat="pending_dtr"><?= $pending_dtr ?></div>
                            <div class="ds-lbl">Pending DTR</div>
                            <div class="ds-sub"><?= $approved_dtr ?> approved</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <div class="dash-stat" style="border-top-color:#17a2b8;">
                        <div class="ds-icon" style="background:#e8f7fa;"><i class="ri-calendar-event-line" style="color:#17a2b8;"></i></div>
                        <div>
                            <div class="ds-val" style="color:#17a2b8;" data-stat="on_leave_today"><?= $on_leave_today_count ?></div>
                            <div class="ds-lbl">On Leave Today</div>
                            <div class="ds-sub"><?= $upcoming_leave_count ?> upcoming (14d)</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Action needed — pending approvals ── -->
            <?php
            $has_approver_items = $can_approve && ($pending_leaves || $pending_att_req || ($show_dtr && ($pending_dtr || $stale_sched_batches)));
            $has_finance_items  = $show_finance && ($pay_review || $open_payroll_disputes);
            ?>
            <?php if ($has_approver_items || $has_finance_items || ($can_approve && $open_dtr_disputes)): ?>
            <div class="dash-sec-hd"><h6><i class="ri-notification-3-line me-1" style="color:#e6a817;"></i>Action Needed</h6><div class="line"></div><span class="hint">items waiting on you</span></div>
            <div class="row g-3 mb-3">
                <?php if ($can_approve): ?>
                <div class="col-md-4">
                    <a href="index.php?page=leaves&lstatus=pending" class="action-card" style="--ac:#e6a817;">
                        <div class="ac-ic"><i class="ri-calendar-event-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="pending_leaves"><?= $pending_leaves ?></div>
                            <div class="ac-lbl">
                                Leave request<?= $pending_leaves == 1 ? '' : 's' ?> awaiting review
                                <?php if ($pending_leaves): ?>
                                · <?php
                                    $__parts = [];
                                    foreach ($leave_stage_defs as $sk => $sd) {
                                        if (($leave_wait_by_stage[$sk] ?? 0) > 0) $__parts[] = $leave_wait_by_stage[$sk] . ' at ' . htmlspecialchars($sd['label']);
                                    }
                                    echo implode(', ', $__parts);
                                ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="index.php?page=attendance-requests" class="action-card" style="--ac:#0891b2;">
                        <div class="ac-ic"><i class="ri-timer-flash-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="pending_att_req"><?= $pending_att_req ?></div>
                            <div class="ac-lbl">Attendance / OT request<?= $pending_att_req == 1 ? '' : 's' ?> pending</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php if ($show_dtr): ?>
                <div class="col-md-4">
                    <a href="dtr" class="action-card" style="--ac:#fd7e14;">
                        <div class="ac-ic"><i class="ri-time-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="pending_dtr"><?= $pending_dtr ?></div>
                            <div class="ac-lbl">DTR upload<?= $pending_dtr == 1 ? '' : 's' ?> awaiting approval</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($show_finance && $pay_review): ?>
                <div class="col-md-4">
                    <a href="payroll-list" class="action-card" style="--ac:#e6a817;">
                        <div class="ac-ic"><i class="ri-eye-line"></i></div>
                        <div>
                            <div class="ac-val"><?= $pay_review ?></div>
                            <div class="ac-lbl">Payroll<?= $pay_review == 1 ? '' : 's' ?> in employee review</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($show_finance && $open_payroll_disputes): ?>
                <div class="col-md-4">
                    <a href="payroll-list" class="action-card" style="--ac:#dc3545;">
                        <div class="ac-ic"><i class="ri-error-warning-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="open_payroll_disputes"><?= $open_payroll_disputes ?></div>
                            <div class="ac-lbl">Payslip dispute<?= $open_payroll_disputes == 1 ? '' : 's' ?> to resolve</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($can_approve && $show_dtr && $open_dtr_disputes): ?>
                <div class="col-md-4">
                    <a href="dtr" class="action-card" style="--ac:#dc3545;">
                        <div class="ac-ic"><i class="ri-error-warning-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="open_dtr_disputes"><?= $open_dtr_disputes ?></div>
                            <div class="ac-lbl">DTR dispute<?= $open_dtr_disputes == 1 ? '' : 's' ?> to resolve</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($can_approve && $show_dtr && $stale_sched_batches): ?>
                <div class="col-md-4">
                    <a href="dtr" class="action-card" style="--ac:#c98a00;">
                        <div class="ac-ic"><i class="ri-calendar-schedule-line"></i></div>
                        <div>
                            <div class="ac-val" data-stat="stale_sched_batches"><?= $stale_sched_batches ?></div>
                            <div class="ac-lbl">DTR batch<?= $stale_sched_batches == 1 ? '' : 'es' ?> with a schedule change to recompute</div>
                        </div>
                        <i class="ri-arrow-right-line ac-go"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            </div><!-- /sec-overview -->

            <!-- ══════════════ TODAY ══════════════ -->
            <div id="sec-today" class="dash-section">
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2 gap-2 flex-wrap">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-calendar-check-line me-2" style="color:#673bb6;"></i>Today's Attendance
                                <span class="sub"><?= date('l, M d, Y') ?></span>
                            </h6>
                            <a href="daily-board" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Full Board <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body pb-2">
                            <div class="row g-2 mb-3">
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#673bb6;">
                                        <div class="tas-v"><?= $today_present_count ?></div>
                                        <div class="tas-l"><i class="ri-checkbox-circle-line me-1"></i>Present</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#28a745;">
                                        <div class="tas-v"><?= $today_on_duty ?></div>
                                        <div class="tas-l"><i class="ri-pulse-line me-1"></i>Still On Duty</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#e6a817;">
                                        <div class="tas-v"><?= $today_late_count ?></div>
                                        <div class="tas-l"><i class="ri-alarm-warning-line me-1"></i>Late</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#dc3545;">
                                        <div class="tas-v"><?= $today_absent_count ?></div>
                                        <div class="tas-l"><i class="ri-close-circle-line me-1"></i>Absent</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#0891b2;">
                                        <div class="tas-v" data-stat="on_leave_today"><?= $on_leave_today_count ?></div>
                                        <div class="tas-l"><i class="ri-calendar-event-line me-1"></i>On Leave</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4 col-xl">
                                    <div class="today-att-stat" style="--ac:#4a5bbf;">
                                        <div class="tas-v"><?= rtrim(rtrim(number_format($today_ot_total, 1), '0'), '.') ?>h</div>
                                        <div class="tas-l"><i class="ri-timer-flash-line me-1"></i>OT Hours</div>
                                    </div>
                                </div>
                            </div>

                            <?php if (count($today_rows_shown)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th class="th-p">Employee</th>
                                            <th class="th-p">Department</th>
                                            <th class="th-p">Duty In</th>
                                            <th class="th-p">Duty Out</th>
                                            <th class="th-p" style="text-align:right;">Hours</th>
                                            <th class="th-p" style="text-align:right;">Late</th>
                                            <th class="th-p" style="text-align:right;">OT</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($today_rows_shown as $tr): ?>
                                        <tr>
                                            <td style="padding:7px 12px;font-size:12px;font-weight:600;"><?= htmlspecialchars($tr['name']) ?></td>
                                            <td style="padding:7px 12px;font-size:11px;color:#666;"><?= htmlspecialchars($tr['dept']) ?></td>
                                            <td style="padding:7px 12px;">
                                                <?php if ($tr['in']): ?>
                                                    <span class="tai-chip in"><?= date('h:i A', strtotime($tr['in'])) ?></span>
                                                <?php else: ?><span style="color:#ccc;font-size:11px;">—</span><?php endif; ?>
                                            </td>
                                            <td style="padding:7px 12px;">
                                                <?php if ($tr['out']): ?>
                                                    <span class="tai-chip out"><?= date('h:i A', strtotime($tr['out'])) ?></span>
                                                <?php elseif ($tr['in']): ?>
                                                    <span style="background:#e8f8ee;color:#1c7c3c;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;"><i class="ri-pulse-line me-1"></i>On Duty</span>
                                                <?php else: ?><span style="color:#ccc;font-size:11px;">—</span><?php endif; ?>
                                            </td>
                                            <td style="padding:7px 12px;font-size:12px;text-align:right;font-weight:700;"><?= number_format($tr['hours'], 1) ?></td>
                                            <td style="padding:7px 12px;text-align:right;">
                                                <?php if ($tr['late'] > 0): ?>
                                                    <span style="background:#fff6e0;color:#c98a00;border-radius:8px;padding:2px 8px;font-size:11px;font-weight:700;"><?= rtrim(rtrim(number_format($tr['late'], 2), '0'), '.') ?>h</span>
                                                <?php else: ?><span style="color:#ccc;font-size:11px;">—</span><?php endif; ?>
                                            </td>
                                            <td style="padding:7px 12px;text-align:right;">
                                                <?php if ($tr['ot'] > 0): ?>
                                                    <span style="background:#eef0fb;color:#4a5bbf;border-radius:8px;padding:2px 8px;font-size:11px;font-weight:700;"><?= rtrim(rtrim(number_format($tr['ot'], 1), '0'), '.') ?>h</span>
                                                <?php else: ?><span style="color:#ccc;font-size:11px;">—</span><?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($today_rows_overflow > 0): ?>
                            <div class="text-center mt-2" style="font-size:11px;color:#aaa;">
                                +<?= $today_rows_overflow ?> more — <a href="daily-board" style="color:#673bb6;font-weight:700;text-decoration:none;">view Full Board</a>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-calendar-check-line"></i><div>No attendance recorded yet today.</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- /sec-today -->

            <!-- ══════════════ ATTENDANCE & TIME ══════════════ -->
            <div id="sec-attendance" class="dash-section">
            <div class="dash-sec-hd"><h6><i class="ri-time-line me-1" style="color:#673bb6;"></i>Attendance &amp; Time</h6><div class="line"></div><span class="hint">tardiness, overtime and requests</span></div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-bar-chart-2-line me-2" style="color:#673bb6;"></i>Daily Attendance — Present vs Late <span class="sub">last 14 days</span>
                            </h6>
                            <a href="attendance-summary" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Summary <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-att-daily" style="min-height:250px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #0891b2;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-timer-flash-line me-2" style="color:#0891b2;"></i>Attendance &amp; OT Requests <span class="sub">last 30 days</span></h6>
                            <a href="index.php?page=attendance-requests" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Open</a>
                        </div>
                        <div class="card-body pb-2">
                            <div class="mini-strip">
                                <div class="mini-stat" style="--ac:#0891b2;"><div class="mv"><?= $areq_total_30d ?></div><div class="ml">Filed</div></div>
                                <div class="mini-stat" style="--ac:#e6a817;"><div class="mv" data-stat="pending_att_req"><?= $pending_att_req ?></div><div class="ml">Pending (all time)</div></div>
                                <?php $areq_appr = array_sum(array_column($areq, 'a')); $areq_pend = array_sum(array_column($areq, 'p')); ?>
                                <div class="mini-stat" style="--ac:#28a745;"><div class="mv"><?= $areq_total_30d ? round($areq_appr / max(1, $areq_total_30d - $areq_pend) * 100) : 0 ?>%</div><div class="ml">Approval rate</div></div>
                            </div>
                            <?php if ($areq_total_30d): ?>
                            <div id="chart-att-req" style="min-height:170px;"></div>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-timer-flash-line"></i><div>No requests filed in the last 30 days</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-7">
                    <div class="card h-100" style="border-top:3px solid #e6a817;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-line-chart-line me-2" style="color:#c98a00;"></i>Late &amp; Undertime Hours <span class="sub">last 30 days · daily</span>
                            </h6>
                        </div>
                        <div class="card-body pb-2">
                            <div class="mini-strip">
                                <div class="mini-stat" style="--ac:#c98a00;"><div class="mv"><?= $late_30d_incidents ?></div><div class="ml">Late incidents</div></div>
                                <div class="mini-stat" style="--ac:#e6a817;"><div class="mv"><?= $late_30d_hours ?>h</div><div class="ml">Late hours</div></div>
                                <div class="mini-stat" style="--ac:#c9366f;"><div class="mv"><?= $ut_30d_hours ?>h</div><div class="ml">Undertime hours</div></div>
                            </div>
                            <div id="chart-late-ut" style="min-height:220px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #c98a00;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-user-unfollow-line me-2" style="color:#c98a00;"></i>Frequent Latecomers <span class="sub">last 30 days · top 8</span></h6>
                        </div>
                        <div class="card-body pb-2">
                            <?php if (count($toplate_labels)): ?>
                            <div id="chart-top-late" style="min-height:270px;"></div>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-emotion-happy-line" style="color:#28a745;"></i><div>No late arrivals recorded in the last 30 days</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-<?= ($show_dtr && count($sched_changes)) ? '6' : '12' ?>">
                    <div class="card h-100" style="border-top:3px solid #4a5bbf;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-timer-2-line me-2" style="color:#4a5bbf;"></i>Overtime Hours by Month <span class="sub">last 6 months</span></h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-ot-month" style="min-height:240px;"></div>
                        </div>
                    </div>
                </div>
                <?php if ($show_dtr && count($sched_changes)): ?>
                <div class="col-xl-6">
                    <div class="card h-100" style="border-top:3px solid #c98a00;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-calendar-schedule-line me-2" style="color:#c98a00;"></i>Schedule Changes <span class="sub">recent &amp; upcoming</span>
                            </h6>
                            <a href="index.php?page=schedule-roster" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Roster <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body py-2" style="max-height:290px;overflow-y:auto;">
                            <?php foreach ($sched_changes as $sc):
                                $eff      = strtotime($sc['effective_from']);
                                $upcoming = $sc['effective_from'] > date('Y-m-d');
                            ?>
                            <div class="ev-row">
                                <div class="bday-avatar" style="background:linear-gradient(135deg,#c98a00,#8a6400);"><?= strtoupper(substr($sc['emp_name'], 0, 1)) ?></div>
                                <div style="min-width:0;">
                                    <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($sc['emp_name']) ?></div>
                                    <div style="font-size:11px;color:#aaa;">
                                        <?= htmlspecialchars($sc['shift']) ?>
                                        <?php if (!empty($sc['changed_by_name'])): ?> · changed by <?= htmlspecialchars($sc['changed_by_name']) ?><?php endif; ?>
                                        <?php if (!empty($sc['created_at'])): ?> · <?= date('M d', strtotime($sc['created_at'])) ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="bday-day" style="<?= $upcoming ? 'background:#e6f7fb;color:#0891b2;' : 'background:#fff6e0;color:#c98a00;' ?>">
                                    <?= $upcoming ? 'starts' : 'since' ?> <?= date('M d', $eff) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($stale_sched_batches): ?>
                            <div style="font-size:11px;color:#c98a00;padding:6px 2px 2px;">
                                <i class="ri-error-warning-line me-1"></i><?= $stale_sched_batches ?> open DTR batch<?= $stale_sched_batches == 1 ? ' still uses' : 'es still use' ?> the old schedule — open the batch and press <b>Recompute</b> to apply.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            </div><!-- /sec-attendance -->

            <?php if ($show_finance): ?>
            <!-- ══════════════ PAYROLL & FINANCE ══════════════ -->
            <div id="sec-payroll" class="dash-section">
            <div class="dash-sec-hd"><h6><i class="ri-money-dollar-circle-line me-1" style="color:#6f42c1;"></i>Payroll &amp; Finance</h6><div class="line"></div><span class="hint">runs, cost mix, deductions and loans</span></div>

            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-line-chart-line me-2" style="color:#673bb6;"></i>Net Pay Disbursed &amp; Payroll Runs <span class="sub">last 7 months</span>
                            </h6>
                            <a href="payroll-list" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">All Payrolls <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-netpay-trend" style="min-height:260px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card mb-3" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-flow-chart me-2" style="color:#673bb6;"></i>Payroll Pipeline</h6>
                        </div>
                        <div class="card-body py-3">
                            <div class="pipeline">
                                <div class="pl-stage s-new"><div class="pl-ic"><i class="ri-draft-line"></i></div><div class="pl-n"><?= $pay_new ?></div><div class="pl-t">Draft</div></div>
                                <i class="ri-arrow-right-s-line pl-arrow"></i>
                                <div class="pl-stage s-calc"><div class="pl-ic"><i class="ri-calculator-line"></i></div><div class="pl-n"><?= $pay_calculated ?></div><div class="pl-t">Calculated</div></div>
                                <i class="ri-arrow-right-s-line pl-arrow"></i>
                                <div class="pl-stage s-rev"><div class="pl-ic"><i class="ri-eye-line"></i></div><div class="pl-n"><?= $pay_review ?></div><div class="pl-t">In Review</div></div>
                                <i class="ri-arrow-right-s-line pl-arrow"></i>
                                <div class="pl-stage s-lock"><div class="pl-ic"><i class="ri-lock-2-line"></i></div><div class="pl-n"><?= $pay_locked ?></div><div class="pl-t">Locked</div></div>
                            </div>
                        </div>
                    </div>
                    <div class="card" style="border-top:3px solid #6642aa;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-lock-line me-2" style="color:#6642aa;"></i>Latest Payroll</h6>
                        </div>
                        <div class="card-body py-2">
                            <?php if ($snap): ?>
                            <div style="background:linear-gradient(135deg,#6642aa,#4e3483);border-radius:8px;padding:10px 12px;color:#fff;margin-bottom:8px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div style="font-size:13px;font-weight:800;letter-spacing:.3px;flex-grow:1;"><?= htmlspecialchars($snap['ref_no']) ?></div>
                                    <span style="background:rgba(255,255,255,.2);border-radius:8px;padding:1px 8px;font-size:10px;font-weight:700;"><?= $pay_status_lbl[(int)$snap['status']] ?? '' ?></span>
                                </div>
                                <div style="font-size:11px;opacity:.85;margin-top:2px;"><?= htmlspecialchars($snap['employer_name']) ?> · <?= number_format($snap['emp_count']) ?> employees</div>
                                <div style="font-size:11px;opacity:.75;margin-top:2px;"><?= date('M d', strtotime($snap['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($snap['date_to'])) ?></div>
                            </div>
                            <div class="snap-row"><span class="snap-lbl">Gross Pay</span><span class="snap-val" style="color:#4e3483;">₱ <?= number_format((float)$snap['total_gross'], 2) ?></span></div>
                            <div class="snap-row"><span class="snap-lbl">Total Deductions</span><span class="snap-val" style="color:#dc3545;">₱ <?= number_format((float)$snap['total_gross'] - (float)$snap['total_net'], 2) ?></span></div>
                            <div class="snap-row"><span class="snap-lbl">Net Pay</span><span class="snap-val" style="color:#673bb6;font-size:16px;">₱ <?= number_format((float)$snap['total_net'], 2) ?></span></div>
                            <a href="payroll_calculations?id=<?= $snap['id'] ?>" class="btn btn-sm w-100 mt-2" style="background:#f0ecf6;color:#4e3483;font-weight:700;border:1px solid #d7d0e6;"><i class="ri-eye-line me-1"></i>View Payroll</a>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-lock-unlock-line"></i><div>No computed payrolls yet</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($snap): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-pie-chart-line me-2" style="color:#673bb6;"></i>Payroll Cost Mix <span class="sub"><?= htmlspecialchars($snap['ref_no']) ?></span></h6>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-costmix" style="min-height:250px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #c9366f;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-scissors-cut-line me-2" style="color:#c9366f;"></i>Deductions Breakdown <span class="sub"><?= htmlspecialchars($snap['ref_no']) ?></span></h6>
                        </div>
                        <div class="card-body pb-2">
                            <?php if ($snap_total_ded > 0): ?>
                            <div id="chart-dedmix" style="min-height:250px;"></div>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-scissors-cut-line"></i><div>No deductions applied on this run</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #e83e8c;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-bank-line me-2" style="color:#e83e8c;"></i>Active Loans by Type</h6>
                            <a href="loans" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Loans</a>
                        </div>
                        <div class="card-body pb-2">
                            <div class="mini-strip">
                                <div class="mini-stat" style="--ac:#e83e8c;"><div class="mv">₱<?= number_format((float)$loan_data['total'], 0) ?></div><div class="ml">Outstanding balance</div></div>
                                <div class="mini-stat" style="--ac:#6f42c1;"><div class="mv"><?= $loan_data['borrowers'] ?></div><div class="ml">Borrowers</div></div>
                            </div>
                            <?php if (count($loan_type_labels)): ?>
                            <div id="chart-loans" style="min-height:180px;"></div>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-bank-line"></i><div>No active loans</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (count($deptpay_labels)): ?>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2 gap-2 flex-wrap">
                            <h6 class="card-title mb-0 flex-grow-1">
                                <i class="ri-funds-box-line me-2" style="color:#673bb6;"></i>Gross · Deductions · Net by Department
                            </h6>
                            <select id="deptpay-period" class="form-select form-select-sm" style="width:auto;max-width:340px;font-size:11.5px;">
                                <?php foreach ($locked_payrolls as $lp): ?>
                                <option value="<?= (int)$lp['id'] ?>" <?= ((int)$lp['id'] === (int)$snap['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lp['ref_no']) ?> · <?= date('M d', strtotime($lp['date_from'])) ?>–<?= date('M d, Y', strtotime($lp['date_to'])) ?> · <?= $pay_status_lbl[(int)$lp['status']] ?? '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="card-body pb-2">
                            <div id="chart-deptstack" style="min-height:320px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-6">
                    <div class="card h-100" style="border-top:3px solid #6f42c1;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-user-star-line me-2" style="color:#6f42c1;"></i>Average Net Pay per Employee</h6>
                        </div>
                        <div class="card-body pb-2"><div id="chart-deptavg" style="min-height:280px;"></div></div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card h-100" style="border-top:3px solid #4a5bbf;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-scales-3-line me-2" style="color:#4a5bbf;"></i>OT Pay vs Late Deductions by Department</h6>
                        </div>
                        <div class="card-body pb-2"><div id="chart-deptotlate" style="min-height:280px;"></div></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-money-dollar-circle-line me-2" style="color:#673bb6;"></i>Recent Payrolls</h6>
                            <div class="d-flex gap-2 align-items-center" style="font-size:11px;">
                                <span><span class="pay-status-dot" style="background:#673bb6;"></span>New (<?= $pay_new ?>)</span>
                                <span><span class="pay-status-dot" style="background:#4a5bbf;"></span>Calc. (<?= $pay_calculated ?>)</span>
                                <span><span class="pay-status-dot" style="background:#e6a817;"></span>Review (<?= $pay_review ?>)</span>
                                <span><span class="pay-status-dot" style="background:#c9366f;"></span>Locked (<?= $pay_locked ?>)</span>
                                <a href="payroll-list" class="btn btn-sm btn-outline-secondary ms-2" style="font-size:11px;">View All <i class="ri-arrow-right-line ms-1"></i></a>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead><tr>
                                        <th class="th-p">Ref No.</th><th class="th-p">Employer</th><th class="th-p">Period</th><th class="th-p" style="text-align:center;">Status</th>
                                    </tr></thead>
                                    <tbody>
                                        <?php while ($r = $recent_payrolls->fetch_assoc()): ?>
                                        <tr>
                                            <td style="padding:7px 12px;font-size:12px;"><span style="font-family:monospace;font-weight:700;color:#673bb6;"><?= htmlspecialchars($r['ref_no']) ?></span></td>
                                            <td style="padding:7px 12px;font-size:12px;font-weight:600;"><?= htmlspecialchars($r['employer_name']) ?></td>
                                            <td style="padding:7px 12px;font-size:11px;color:#555;"><?= date('M d', strtotime($r['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($r['date_to'])) ?></td>
                                            <td style="padding:7px 12px;text-align:center;">
                                                <?php if ($r['status'] == 0): ?><span class="badge bg-primary" style="font-size:10px;">New</span>
                                                <?php elseif ($r['status'] == 1): ?><span class="badge bg-success" style="font-size:10px;">Calculated</span>
                                                <?php elseif ($r['status'] == 3): ?><span class="badge bg-warning text-dark" style="font-size:10px;"><i class="ri-eye-line me-1"></i>Ready for Review</span>
                                                <?php else: ?><span class="badge bg-danger" style="font-size:10px;"><i class="ri-lock-fill me-1"></i>Locked</span><?php endif; ?>
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
            </div><!-- /sec-payroll -->
            <?php endif; ?>

            <?php if ($show_leave): ?>
            <!-- ══════════════ LEAVE MANAGEMENT ══════════════ -->
            <div id="sec-leave" class="dash-section">
            <div class="dash-sec-hd"><h6><i class="ri-calendar-event-line me-1" style="color:#e6a817;"></i>Leave Management</h6><div class="line"></div><span class="hint">requests, approvals and balances</span></div>
            <div class="row g-3 mb-3">
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#673bb6;">
                        <div class="ds-icon" style="background:#f4f1f9;"><i class="ri-mail-add-line" style="color:#673bb6;"></i></div>
                        <div><div class="ds-val" data-stat="leave_new_week"><?= $leave_new_week ?></div><div class="ds-lbl">New This Week</div><div class="ds-sub"><?= $leave_new_today ?> filed today</div></div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#e6a817;">
                        <div class="ds-icon" style="background:#fff6e0;"><i class="ri-user-heart-line" style="color:#c98a00;"></i></div>
                        <div><div class="ds-val" style="color:#c98a00;" data-stat="leave_wait_hr"><?= $leave_wait_hr ?></div><div class="ds-lbl">Awaiting <?= htmlspecialchars($leave_stage1_lbl) ?></div><div class="ds-sub">1st approval stage</div></div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#fd7e14;">
                        <div class="ds-icon" style="background:#fff4ec;"><i class="ri-shield-check-line" style="color:#fd7e14;"></i></div>
                        <div><div class="ds-val" style="color:#fd7e14;" data-stat="leave_wait_admin"><?= $leave_wait_admin ?></div><div class="ds-lbl">Awaiting <?= htmlspecialchars($leave_stage2_lbl) ?></div><div class="ds-sub">2nd approval stage</div></div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#0891b2;">
                        <div class="ds-icon" style="background:#e6f7fb;"><i class="ri-walk-line" style="color:#0891b2;"></i></div>
                        <div><div class="ds-val" style="color:#0891b2;" data-stat="on_leave_today"><?= $on_leave_today_count ?></div><div class="ds-lbl">On Leave Today</div><div class="ds-sub"><?= $upcoming_leave_count ?> upcoming (14d)</div></div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#28a745;">
                        <div class="ds-icon" style="background:#e8f8ee;"><i class="ri-checkbox-circle-line" style="color:#28a745;"></i></div>
                        <div><div class="ds-val" style="color:#28a745;"><?= $leave_appr_month ?></div><div class="ds-lbl">Approved (<?= date('M') ?>)</div><div class="ds-sub"><?= $leave_rej_month ?> rejected this month</div></div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                    <div class="dash-stat" style="border-top-color:#6f42c1;">
                        <div class="ds-icon" style="background:#f2eefb;"><i class="ri-speed-line" style="color:#6f42c1;"></i></div>
                        <div><div class="ds-val" style="color:#6f42c1;"><?= $leave_turnaround_days === null ? '—' : $leave_turnaround_days . 'd' ?></div><div class="ds-lbl">Avg Turnaround</div><div class="ds-sub"><?= $leave_decided_90d ?> decided · last 90 days</div></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #e6a817;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-event-line me-2" style="color:#e6a817;"></i>Pending Leave Approvals</h6>
                            <a href="index.php?page=leaves" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Review All <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body py-2">
                            <?php $ag_tot = max(1, array_sum($leave_aging)); ?>
                            <div style="font-size:10.5px;color:#888;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">Aging of <span data-stat="pending_leaves"><?= $pending_leaves ?></span> pending request(s)</div>
                            <div class="aging">
                                <span style="width:<?= $leave_aging['0-2 days']/$ag_tot*100 ?>%;background:#28a745;"></span>
                                <span style="width:<?= $leave_aging['3-5 days']/$ag_tot*100 ?>%;background:#e6a817;"></span>
                                <span style="width:<?= $leave_aging['6+ days']/$ag_tot*100 ?>%;background:#dc3545;"></span>
                            </div>
                            <div class="aging-legend mb-2">
                                <span><i style="background:#28a745;"></i>0–2 days: <b><?= $leave_aging['0-2 days'] ?></b></span>
                                <span><i style="background:#e6a817;"></i>3–5 days: <b><?= $leave_aging['3-5 days'] ?></b></span>
                                <span><i style="background:#dc3545;"></i>6+ days: <b><?= $leave_aging['6+ days'] ?></b></span>
                            </div>
                            <div style="max-height:220px;overflow-y:auto;">
                            <?php if (count($pending_leave_list)): ?>
                                <?php foreach ($pending_leave_list as $pl): $age = (int)((time() - strtotime($pl['date_applied'])) / 86400); ?>
                                <div class="ev-row">
                                    <div class="bday-avatar" style="background:linear-gradient(135deg,#e6a817,#c98a00);"><?= strtoupper(substr($pl['emp_name'], 0, 1)) ?></div>
                                    <div style="min-width:0;">
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($pl['emp_name']) ?></div>
                                        <div style="font-size:11px;color:#aaa;">
                                            <?= htmlspecialchars($pl['leave_type']) ?> · <?= rtrim(rtrim(number_format($pl['duration'],1),'0'),'.') ?> day(s)
                                            · <?= date('M d', strtotime($pl['date_from'])) ?><?= $pl['date_to'] != $pl['date_from'] ? '–'.date('M d', strtotime($pl['date_to'])) : '' ?>
                                        </div>
                                    </div>
                                    <div class="bday-day" style="<?= $age >= 6 ? 'background:#fdecea;color:#c62828;' : ($age >= 3 ? 'background:#fff6e0;color:#c98a00;' : 'background:#e8f8ee;color:#1c7c3c;') ?>"><?= $age ?>d waiting</div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="ri-checkbox-circle-line" style="color:#673bb6;"></i><div>No pending leave requests — all caught up!</div></div>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="card h-100" style="border-top:3px solid #e6a817;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-bar-chart-grouped-line me-2" style="color:#e6a817;"></i>Leave Days Taken <span class="sub">last 6 months · approved</span></h6>
                            <a href="index.php?page=leave_balances" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Leave Balances <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body pb-2"><div id="chart-leave-trend" style="min-height:270px;"></div></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #0891b2;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-walk-line me-2" style="color:#0891b2;"></i>On Leave Today</h6>
                            <span class="badge" style="background:#e6f7fb;color:#0891b2;font-size:11px;font-weight:700;"><?= $on_leave_today_count ?></span>
                        </div>
                        <div class="card-body py-2" style="max-height:250px;overflow-y:auto;">
                            <?php if (count($on_leave_today)): ?>
                                <?php foreach ($on_leave_today as $ol): ?>
                                <div class="ev-row">
                                    <div class="bday-avatar" style="background:linear-gradient(135deg,#0891b2,#0a6e86);"><?= strtoupper(substr($ol['emp_name'], 0, 1)) ?></div>
                                    <div style="min-width:0;">
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($ol['emp_name']) ?></div>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($ol['leave_type']) ?><?= $ol['is_half_day'] ? ' · half day' : '' ?> · <?= htmlspecialchars($ol['dept']) ?></div>
                                    </div>
                                    <div class="bday-day" style="background:#e6f7fb;color:#0891b2;">until <?= date('M d', strtotime($ol['date_to'])) ?></div>
                                </div>
                                <?php endforeach; ?>
                                <?php if ($on_leave_today_count > count($on_leave_today)): ?>
                                <div class="text-center mt-1" style="font-size:11px;color:#aaa;">+<?= $on_leave_today_count - count($on_leave_today) ?> more — <a href="index.php?page=leaves&lstatus=approved" style="color:#0891b2;font-weight:700;text-decoration:none;">view all</a></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="ri-team-line" style="color:#673bb6;"></i><div>Nobody is on leave today — full crew!</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #28a745;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-todo-line me-2" style="color:#28a745;"></i>Upcoming Approved Leaves <span class="sub">next 14 days</span></h6>
                        </div>
                        <div class="card-body py-2" style="max-height:250px;overflow-y:auto;">
                            <?php if (count($upcoming_leaves)): ?>
                                <?php foreach ($upcoming_leaves as $ul): $st = strtotime($ul['date_from']); ?>
                                <div class="ev-row">
                                    <div class="ev-date"><div class="d"><?= date('d', $st) ?></div><div class="m"><?= date('M', $st) ?></div></div>
                                    <div style="min-width:0;">
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($ul['emp_name']) ?></div>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($ul['leave_type']) ?> · <?= rtrim(rtrim(number_format($ul['duration'],1),'0'),'.') ?> day(s) · <?= htmlspecialchars($ul['dept']) ?></div>
                                    </div>
                                    <?php if ($ul['date_to'] != $ul['date_from']): ?><div class="bday-day" style="background:#e8f8ee;color:#1c7c3c;">to <?= date('M d', strtotime($ul['date_to'])) ?></div><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="ri-calendar-todo-line"></i><div>No approved leaves in the next 14 days</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #e6a817;">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0"><i class="ri-pie-chart-2-line me-2" style="color:#e6a817;"></i>Leave Days by Type <span class="sub"><?= date('Y') ?> · approved</span></h6>
                        </div>
                        <div class="card-body py-2">
                            <?php if (count($lvtype_data)): ?>
                            <div id="chart-leave-type" style="min-height:220px;"></div>
                            <?php else: ?>
                            <div class="empty-state"><i class="ri-pie-chart-2-line"></i><div>No approved leaves yet this year</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- /sec-leave -->
            <?php endif; ?>

            <!-- ══════════════ WORKFORCE ══════════════ -->
            <div id="sec-workforce" class="dash-section">
            <div class="dash-sec-hd"><h6><i class="ri-team-line me-1" style="color:#6642aa;"></i>Workforce</h6><div class="line"></div><span class="hint">headcount composition · active employees</span></div>
            <div class="row g-3 mb-3">
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #6642aa;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-building-2-line me-2" style="color:#6642aa;"></i>Employees by Department</h6></div>
                        <div class="card-body pb-2"><div id="chart-emp-dept" style="min-height:250px;"></div></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-briefcase-4-line me-2" style="color:#673bb6;"></i>Top Positions</h6></div>
                        <div class="card-body pb-2"><div id="chart-emp-pos" style="min-height:250px;"></div></div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-map-pin-line me-2" style="color:#673bb6;"></i>Employees by Area</h6></div>
                        <div class="card-body pb-2"><div id="chart-emp-area" style="min-height:250px;"></div></div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-xl-4">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-shield-star-line me-2" style="color:#673bb6;"></i>Employees by Classification</h6></div>
                        <div class="card-body pb-2"><div id="chart-emp-class" style="min-height:220px;"></div></div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="card h-100" style="border-top:3px solid #4a5bbf;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-donut-chart-line me-2" style="color:#4a5bbf;"></i>Rate Type Mix</h6></div>
                        <div class="card-body pb-2"><div id="chart-rate-type" style="min-height:220px;"></div></div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #e83e8c;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-cake-line me-2" style="color:#e83e8c;"></i>Birthdays This Month</h6>
                            <span class="badge" style="background:#fdf0f6;color:#e83e8c;font-size:11px;font-weight:700;"><?= count($bdays) ?></span>
                        </div>
                        <div class="card-body py-2" style="max-height:250px;overflow-y:auto;">
                            <?php if (count($bdays) > 0): ?>
                                <?php foreach ($bdays as $b): $isToday = date('m-d', strtotime($b['bday'])) === date('m-d'); ?>
                                <div class="bday-item">
                                    <div class="bday-avatar"><?= strtoupper(substr($b['firstname'],0,1).substr($b['lastname'],0,1)) ?></div>
                                    <div>
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($b['lastname'].', '.$b['firstname']) ?><?= $isToday ? ' <span style="font-size:10px;color:#e83e8c;">🎂 today</span>' : '' ?></div>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($b['dept']) ?></div>
                                    </div>
                                    <div class="bday-day"><?= date('M d', strtotime($b['bday'])) ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="ri-cake-line"></i><div>No birthdays this month</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- /sec-workforce -->

            <!-- ══════════════ ACTIVITY ══════════════ -->
            <div id="sec-activity" class="dash-section">
            <div class="dash-sec-hd"><h6><i class="ri-history-line me-1" style="color:#0891b2;"></i>Activity &amp; Calendar</h6><div class="line"></div></div>
            <div class="row g-3 mb-3">
                <div class="col-xl-7">
                    <div class="card h-100" style="border-top:3px solid #673bb6;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-time-line me-2" style="color:#673bb6;"></i>Recent DTR Uploads</h6>
                            <a href="dtr" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">View All <i class="ri-arrow-right-line ms-1"></i></a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead><tr><th class="th-p">Site</th><th class="th-p">Uploaded By</th><th class="th-p" style="text-align:center;">Status</th></tr></thead>
                                    <tbody>
                                        <?php while ($recent_dtr && $r = $recent_dtr->fetch_assoc()): ?>
                                        <tr>
                                            <td style="padding:7px 12px;">
                                                <span style="background:#673bb6;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:700;font-family:monospace;"><?= htmlspecialchars($r['site_code']) ?></span>
                                                <div style="font-size:12px;font-weight:600;margin-top:2px;"><?= htmlspecialchars($r['site_name']) ?></div>
                                            </td>
                                            <td style="padding:7px 12px;font-size:12px;"><?= htmlspecialchars($r['uploader'] ?? '—') ?></td>
                                            <td style="padding:7px 12px;text-align:center;">
                                                <?php if ($r['status'] == 2): ?><span class="badge bg-success" style="font-size:10px;"><i class="ri-check-line me-1"></i>Approved</span>
                                                <?php else: ?><span class="badge bg-warning text-dark" style="font-size:10px;">Pending</span><?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5">
                    <div class="card h-100" style="border-top:3px solid #0891b2;">
                        <div class="card-header d-flex align-items-center py-2">
                            <h6 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-2-line me-2" style="color:#0891b2;"></i>Upcoming Holidays &amp; Events</h6>
                            <?php if ($show_leave): ?><a href="index.php?page=calendar" class="btn btn-sm btn-outline-secondary" style="font-size:11px;">Calendar <i class="ri-arrow-right-line ms-1"></i></a><?php endif; ?>
                        </div>
                        <div class="card-body py-2" style="max-height:260px;overflow-y:auto;">
                            <?php if (count($upcoming_events)): ?>
                                <?php foreach ($upcoming_events as $ev): $st = strtotime($ev['start_date']); $t = (int) $ev['type']; $isHol = $t == 1; $isSpl = $t == 3; ?>
                                <div class="ev-row">
                                    <div class="ev-date"><div class="d"><?= date('d', $st) ?></div><div class="m"><?= date('M', $st) ?></div></div>
                                    <div style="min-width:0;">
                                        <div style="font-size:12px;font-weight:700;line-height:1.2;"><?= htmlspecialchars($ev['title']) ?></div>
                                        <?php if (!empty($ev['end_date']) && $ev['end_date'] != $ev['start_date']): ?>
                                        <div style="font-size:11px;color:#aaa;">until <?= date('M d, Y', strtotime($ev['end_date'])) ?></div>
                                        <?php elseif (!empty($ev['note'])): ?>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars(mb_strimwidth($ev['note'], 0, 40, '…')) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="bday-day" style="<?= $isHol ? 'background:#fdecea;color:#c62828;' : ($isSpl ? 'background:#fff4e5;color:#c76a00;' : 'background:#e6f7fb;color:#0891b2;') ?>"><?= $isHol ? 'Legal Holiday' : ($isSpl ? 'Special Holiday' : 'Activity') ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="ri-calendar-2-line"></i><div>No upcoming events</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card" style="border-top:3px solid #673bb6;">
                        <div class="card-header py-2"><h6 class="card-title mb-0"><i class="ri-apps-line me-2" style="color:#673bb6;"></i>Quick Access</h6></div>
                        <div class="card-body py-3">
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($login_role !== 7): ?><a href="employee" class="btn btn-sm qa-btn"><i class="ri-group-line me-1"></i>Employees</a><?php endif; ?>
                                <?php if ($show_finance): ?><a href="payroll-list" class="btn btn-sm qa-btn"><i class="ri-money-dollar-circle-line me-1"></i>Payroll</a><?php endif; ?>
                                <a href="dtr" class="btn btn-sm qa-btn"><i class="ri-time-line me-1"></i>DTR</a>
                                <a href="attendance" class="btn btn-sm qa-btn"><i class="ri-calendar-check-line me-1"></i>Attendance</a>
                                <a href="daily-board" class="btn btn-sm qa-btn"><i class="ri-dashboard-2-line me-1"></i>Daily Board</a>
                                <?php if ($show_leave): ?>
                                <a href="index.php?page=leaves" class="btn btn-sm qa-btn"><i class="ri-calendar-event-line me-1"></i>Leave Requests</a>
                                <a href="index.php?page=leave_balances" class="btn btn-sm qa-btn"><i class="ri-coins-line me-1"></i>Leave Balances</a>
                                <a href="index.php?page=calendar" class="btn btn-sm qa-btn"><i class="ri-calendar-2-line me-1"></i>Calendar</a>
                                <?php endif; ?>
                                <?php if ($login_role !== 7): ?>
                                <a href="branch" class="btn btn-sm qa-btn"><i class="ri-building-2-line me-1"></i>Branches</a>
                                <a href="department" class="btn btn-sm qa-btn"><i class="ri-building-3-line me-1"></i>Departments</a>
                                <?php endif; ?>
                                <?php if (!in_array($login_role, [4, 7], true)): ?><a href="users" class="btn btn-sm qa-btn"><i class="ri-shield-user-line me-1"></i>Users</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div><!-- /sec-activity -->

        </div>
    </div>
</div>

<script src="assets/libs/apexcharts/apexcharts.min.js"></script>
<script>
(function () {
    var primary = '#673bb6';
    var GRID  = { borderColor:'#eef0f2', strokeDashArray:0 };
    var peso0 = function(v){ return '₱'+Number(v).toLocaleString('en-PH',{maximumFractionDigits:0}); };
    var peso2 = function(v){ return '₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2}); };
    var pesoK = function(v){ return Math.abs(v) >= 1000 ? '₱'+Math.round(v/1000)+'k' : peso0(v); };
    var el = function(id){ return document.getElementById(id); };
    var hrs = function(v){ return (Math.round(v*10)/10) + 'h'; };

    // ── Daily Attendance — present vs late headcount (14 days) ──
    if (el('chart-att-daily'))
    new ApexCharts(el('chart-att-daily'), {
        chart: { type:'bar', height:250, toolbar:{show:false}, fontFamily:'inherit' },
        colors: [primary, '#e6a817'],
        series: [
            { name:'Present', data: <?= json_encode($att_day_present) ?> },
            { name:'Late',    data: <?= json_encode($att_day_late) ?> }
        ],
        xaxis: { categories: <?= json_encode($att_day_labels) ?>, labels:{style:{fontSize:'10px'}, rotate:-35} },
        yaxis: { labels:{style:{fontSize:'10px'}}, min:0, forceNiceScale:true },
        plotOptions: { bar:{ borderRadius:3, columnWidth:'60%' } },
        dataLabels: { enabled:false },
        legend: { position:'top', fontSize:'11px' },
        grid: GRID,
        tooltip: { shared:true, intersect:false,
            y: { formatter: function(v, opts){
                if (opts.seriesIndex === 0) { var ot = <?= json_encode($att_day_ot) ?>[opts.dataPointIndex] || 0; return v + ' present · ' + ot + ' OT hr(s)'; }
                return v + ' late';
            } } },
    }).render();

    // ── Attendance / OT requests — type × outcome (30 days) ────
    if (el('chart-att-req'))
    new ApexCharts(el('chart-att-req'), {
        chart: { type:'bar', height:170, stacked:true, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#e6a817', '#28a745', '#dc3545'],
        plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'55%' } },
        series: [
            { name:'Pending',  data: [<?= $areq['incident']['p'] ?>, <?= $areq['overtime']['p'] ?>, <?= $areq['rest_day']['p'] ?>] },
            { name:'Approved', data: [<?= $areq['incident']['a'] ?>, <?= $areq['overtime']['a'] ?>, <?= $areq['rest_day']['a'] ?>] },
            { name:'Rejected', data: [<?= $areq['incident']['r'] ?>, <?= $areq['overtime']['r'] ?>, <?= $areq['rest_day']['r'] ?>] }
        ],
        xaxis: { categories: ['Incident / Missed log', 'Overtime', 'Rest-day work'], labels:{style:{fontSize:'10px'}} },
        yaxis: { labels:{style:{fontSize:'11px'}} },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']}, formatter:function(v){ return v > 0 ? v : ''; } },
        legend: { position:'bottom', fontSize:'11px' },
        stroke: { width:1, colors:['#fff'] },
        grid: GRID,
        tooltip: { y:{ formatter:function(v){ return v + ' request(s)'; } } },
    }).render();

    // ── Late & Undertime hours — 30 days ────────────────────────
    if (el('chart-late-ut'))
    new ApexCharts(el('chart-late-ut'), {
        chart: { type:'area', height:220, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#e6a817', '#c9366f'],
        fill: { type:'gradient', gradient:{ shadeIntensity:1, opacityFrom:.35, opacityTo:.03 } },
        stroke: { curve:'smooth', width:2 },
        series: [
            { name:'Late (hrs)',      data: <?= json_encode($lu_late_hrs) ?> },
            { name:'Undertime (hrs)', data: <?= json_encode($lu_ut_hrs) ?> }
        ],
        xaxis: { categories: <?= json_encode($lu_labels) ?>, labels:{style:{fontSize:'10px'}, rotate:-35, hideOverlappingLabels:true}, tickAmount:10 },
        yaxis: { labels:{style:{fontSize:'10px'}, formatter:hrs}, min:0, forceNiceScale:true },
        dataLabels: { enabled:false },
        legend: { position:'top', fontSize:'11px' },
        markers: { size:0, hover:{size:4} },
        grid: GRID,
        tooltip: { shared:true, intersect:false,
            y:{ formatter:function(v, opts){
                if (opts.seriesIndex === 0) { var c = <?= json_encode($lu_late_cnt) ?>[opts.dataPointIndex] || 0; return hrs(v) + ' · ' + c + ' late'; }
                return hrs(v);
            } } },
    }).render();

    // ── Frequent latecomers — top 8 (30 days) ───────────────────
    if (el('chart-top-late'))
    new ApexCharts(el('chart-top-late'), {
        chart: { type:'bar', height:270, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#c98a00'],
        plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'60%' } },
        series: [{ name:'Late incidents', data: <?= json_encode($toplate_cnt) ?> }],
        xaxis: { categories: <?= json_encode($toplate_labels) ?>, labels:{style:{fontSize:'10px'}, formatter:function(v){ return Math.round(v); }}, tickAmount:4 },
        yaxis: { labels:{style:{fontSize:'10px'}, maxWidth:150} },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']} },
        grid: GRID,
        tooltip: { y:{ formatter:function(v, opts){
            var h = <?= json_encode($toplate_hrs) ?>[opts.dataPointIndex] || 0;
            var d = <?= json_encode($toplate_dept) ?>[opts.dataPointIndex] || '';
            return v + ' time(s) · ' + h + ' hr(s) late · ' + d;
        } } },
    }).render();

    // ── Overtime hours by month ─────────────────────────────────
    if (el('chart-ot-month'))
    new ApexCharts(el('chart-ot-month'), {
        chart: { type:'bar', height:240, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#4a5bbf'],
        series: [{ name:'OT Hours', data: <?= json_encode($ot_month_hrs) ?> }],
        xaxis: { categories: <?= json_encode($ot_month_labels) ?>, labels:{style:{fontSize:'11px'}} },
        yaxis: { labels:{style:{fontSize:'10px'}, formatter:hrs}, min:0, forceNiceScale:true },
        plotOptions: { bar:{ borderRadius:4, columnWidth:'45%', dataLabels:{ position:'top' } } },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#4a5bbf']}, offsetY:-18, formatter:function(v){ return v > 0 ? hrs(v) : ''; } },
        grid: GRID,
        tooltip: { y:{ formatter:function(v, opts){ var e = <?= json_encode($ot_month_emp) ?>[opts.dataPointIndex] || 0; return hrs(v) + ' by ' + e + ' employee(s)'; } } },
    }).render();

    <?php if ($show_finance): ?>
    // ── Net Pay Disbursed (area) — tooltip shows payroll runs that month ─
    if (el('chart-netpay-trend')) {
        var runsByMonth = {};
        <?= json_encode($monthly_labels) ?>.forEach(function(l, i){ runsByMonth[l] = <?= json_encode($monthly_data) ?>[i]; });
        new ApexCharts(el('chart-netpay-trend'), {
            chart: { type:'area', height:260, toolbar:{show:false}, fontFamily:'inherit' },
            colors: [primary],
            fill: { type:'gradient', gradient:{ shadeIntensity:1, opacityFrom:.4, opacityTo:.05 } },
            stroke: { curve:'smooth', width:2 },
            series: [{ name:'Net Pay', data: <?= json_encode($netpay_data) ?> }],
            xaxis: { categories: <?= json_encode($netpay_labels) ?>, labels:{style:{fontSize:'11px'}} },
            yaxis: { labels:{ style:{fontSize:'10px'}, formatter: pesoK }, min:0 },
            dataLabels: { enabled:false },
            grid: GRID,
            tooltip: { y:{ formatter: function(v, opts){ var l = opts.w.globals.categoryLabels[opts.dataPointIndex]; var n = runsByMonth[l]; return peso2(v) + (n ? ' · ' + n + ' payroll run(s)' : ''); } } },
            markers: { size:4, colors:[primary], strokeColors:'#fff', strokeWidth:2 },
        }).render();
    }

    // ── Payroll cost mix / deductions mix (donuts) ──────────────
    function donut(id, labels, series, colors, centerLabel, fmt) {
        if (!el(id)) return;
        new ApexCharts(el(id), {
            chart: { type:'donut', height:250, toolbar:{show:false}, fontFamily:'inherit' },
            colors: colors, series: series, labels: labels,
            legend: { position:'bottom', fontSize:'11px', markers:{width:8,height:8} },
            dataLabels: { enabled:false },
            plotOptions: { pie:{ donut:{ size:'64%', labels:{ show:true,
                value:{ fontSize:'15px', fontWeight:800, color:'#333', offsetY:2, formatter:function(v){ return fmt(v); } },
                total:{ show:true, showAlways:true, label:centerLabel, fontSize:'11px', color:'#999',
                        formatter:function(w){ return fmt(w.globals.seriesTotals.reduce(function(a,b){return a+b;},0)); } }
            } } } },
            stroke: { width:2, colors:['#fff'] },
            tooltip: { y:{ formatter: fmt } },
        }).render();
    }
    donut('chart-costmix', <?= json_encode(array_keys($costmix)) ?>, <?= json_encode(array_values($costmix)) ?>,
          ['#673bb6', '#4a5bbf', '#0891b2', '#e6a817', '#6f42c1'], 'Earnings', peso0);
    donut('chart-dedmix', <?= json_encode(array_keys($dedmix)) ?>, <?= json_encode(array_values($dedmix)) ?>,
          ['#c9366f', '#e83e8c', '#e6a817', '#6f42c1', '#8a94a6'], 'Deductions', peso0);

    // ── Active loans by type ────────────────────────────────────
    if (el('chart-loans'))
    new ApexCharts(el('chart-loans'), {
        chart: { type:'bar', height:180, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#e83e8c'],
        plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'55%' } },
        series: [{ name:'Balance', data: <?= json_encode($loan_type_bal) ?> }],
        xaxis: { categories: <?= json_encode($loan_type_labels) ?>, labels:{style:{fontSize:'10px'}, formatter:pesoK}, tickAmount:4 },
        yaxis: { labels:{style:{fontSize:'10px'}, maxWidth:120} },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']}, formatter:peso0 },
        grid: GRID,
        tooltip: { y:{ formatter:function(v, opts){ var c = <?= json_encode($loan_type_cnt) ?>[opts.dataPointIndex] || 0; return peso2(v) + ' · ' + c + ' loan(s)'; } } },
    }).render();
    <?php endif; ?>

    // ── Workforce composition — single-hue horizontal bars ──────
    function hbar(id, labels, data, color, height, unit) {
        if (!el(id)) return;
        new ApexCharts(el(id), {
            chart: { type:'bar', height:height, toolbar:{show:false}, fontFamily:'inherit' },
            colors: [color],
            plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'58%' } },
            series: [{ name:unit, data: data }],
            xaxis: { categories: labels, labels:{style:{fontSize:'10px'}, formatter:function(v){ return Math.round(v); }}, tickAmount:4 },
            yaxis: { labels:{style:{fontSize:'10px'}, maxWidth:120} },
            dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']} },
            grid: GRID,
            tooltip: { y:{ formatter:function(v){ return v + ' ' + unit.toLowerCase(); } } },
        }).render();
    }
    hbar('chart-emp-dept',  <?= json_encode($dept_labels) ?>,  <?= json_encode($dept_data) ?>,  '#6642aa', 250, 'Employees');
    hbar('chart-emp-pos',   <?= json_encode($pos_labels) ?>,   <?= json_encode($pos_data) ?>,   primary,   250, 'Employees');
    hbar('chart-emp-area',  <?= json_encode($area_labels) ?>,  <?= json_encode($area_data) ?>,  primary,   250, 'Employees');
    hbar('chart-emp-class', <?= json_encode($class_labels) ?>, <?= json_encode($class_data) ?>, primary,   220, 'Employees');

    if (el('chart-rate-type'))
    new ApexCharts(el('chart-rate-type'), {
        chart: { type:'donut', height:220, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#4a5bbf', '#673bb6', '#0891b2', '#e6a817'],
        series: <?= json_encode($rate_data) ?>, labels: <?= json_encode($rate_labels) ?>,
        legend: { position:'bottom', fontSize:'11px', markers:{width:8,height:8} },
        dataLabels: { enabled:false },
        plotOptions: { pie:{ donut:{ size:'64%', labels:{ show:true,
            value:{ fontSize:'18px', fontWeight:800, color:'#333', offsetY:2 },
            total:{ show:true, showAlways:true, label:'Active', fontSize:'11px', color:'#999', formatter:function(w){ return w.globals.seriesTotals.reduce(function(a,b){return a+b;},0); } }
        } } } },
        stroke: { width:2, colors:['#fff'] },
        tooltip: { y:{ formatter:function(v){ return v + ' employee(s)'; } } },
    }).render();

    // ── Payroll by Department (selectable payroll run) ──────────
    var dpEmp = <?= json_encode($deptpay_emp) ?>;
    var chartStack = null, chartAvg = null, chartOtLate = null;
    if (el('chart-deptstack')) {
        chartStack = new ApexCharts(el('chart-deptstack'), {
            chart: { type:'bar', height:320, stacked:true, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#673bb6', '#c9366f', '#4a5bbf'],
            plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'65%' } },
            series: [
                { name:'Gross',      data: <?= json_encode($deptpay_gross) ?> },
                { name:'Deductions', data: <?= json_encode($deptpay_ded) ?> },
                { name:'Net',        data: <?= json_encode($deptpay_net) ?> }
            ],
            xaxis: { categories: <?= json_encode($deptpay_labels) ?>, labels:{ style:{fontSize:'10px'}, formatter:pesoK } },
            yaxis: { labels:{ style:{fontSize:'11px'}, maxWidth:140 } },
            dataLabels: { enabled:false },
            legend: { position:'top', fontSize:'12px' },
            stroke: { width:2, colors:['#fff'] },
            grid: GRID,
            tooltip: { y:{ formatter: peso2 } },
        });
        chartStack.render();
    }
    if (el('chart-deptavg')) {
        chartAvg = new ApexCharts(el('chart-deptavg'), {
            chart: { type:'bar', height:280, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#6f42c1'],
            plotOptions: { bar:{ horizontal:true, borderRadius:3, barHeight:'60%' } },
            series: [{ name:'Avg Net', data: <?= json_encode($deptpay_avg) ?> }],
            xaxis: { categories: <?= json_encode($deptpay_labels) ?>, labels:{ style:{fontSize:'10px'}, formatter:pesoK } },
            yaxis: { labels:{ style:{fontSize:'10px'}, maxWidth:130 } },
            dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#fff']}, formatter: peso0 },
            grid: GRID,
            tooltip: { y:{ formatter: function(v, opts){ var e=dpEmp[opts.dataPointIndex]||0; return peso2(v)+' / employee ('+e+')'; } } },
        });
        chartAvg.render();
    }
    if (el('chart-deptotlate')) {
        chartOtLate = new ApexCharts(el('chart-deptotlate'), {
            chart: { type:'bar', height:280, toolbar:{show:false}, fontFamily:'inherit' },
            colors: ['#4a5bbf', '#b26a00'],
            plotOptions: { bar:{ columnWidth:'55%', borderRadius:3 } },
            series: [
                { name:'OT Pay',          data: <?= json_encode($deptpay_ot) ?> },
                { name:'Late Deductions', data: <?= json_encode($deptpay_late) ?> }
            ],
            xaxis: { categories: <?= json_encode($deptpay_labels) ?>, labels:{ style:{fontSize:'9px'}, rotate:-35, trim:true, hideOverlappingLabels:false } },
            yaxis: { labels:{ style:{fontSize:'10px'}, formatter:pesoK } },
            dataLabels: { enabled:false },
            legend: { position:'top', fontSize:'12px' },
            grid: GRID,
            tooltip: { shared:true, intersect:false, y:{ formatter: peso2 } },
        });
        chartOtLate.render();
    }

    // ── Leave charts ────────────────────────────────────────────
    if (el('chart-leave-type'))
    new ApexCharts(el('chart-leave-type'), {
        chart: { type:'donut', height:220, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#e6a817', '#673bb6', '#4a5bbf', '#c9366f', '#0891b2', '#6f42c1', '#fd7e14', '#28a745'],
        series: <?= json_encode($lvtype_data) ?>,
        labels: <?= json_encode($lvtype_labels) ?>,
        legend: { position:'bottom', fontSize:'11px', markers:{width:8,height:8} },
        dataLabels: { enabled:false },
        plotOptions: { pie:{ donut:{ size:'62%', labels:{ show:true,
            value:{ fontSize:'18px', fontWeight:800, color:'#333', offsetY:2 },
            total:{ show:true, showAlways:true, label:'Days', fontSize:'11px', color:'#999',
                    formatter:function(w){ return w.globals.seriesTotals.reduce(function(a,b){return a+b;},0).toFixed(1).replace(/\.0$/,''); } }
        } } } },
        stroke: { width:2, colors:['#fff'] },
        tooltip: { y:{ formatter: function(v){ return v+' day(s)'; } } },
    }).render();

    if (el('chart-leave-trend'))
    new ApexCharts(el('chart-leave-trend'), {
        chart: { type:'bar', height:270, toolbar:{show:false}, fontFamily:'inherit' },
        colors: ['#e6a817'],
        series: [{ name:'Leave Days', data: <?= json_encode($lv_month_days) ?> }],
        xaxis: { categories: <?= json_encode($lv_month_labels) ?>, labels:{style:{fontSize:'11px'}} },
        yaxis: { labels:{style:{fontSize:'11px'}, formatter:function(v){ return Math.round(v*2)/2; }}, min:0, forceNiceScale:true },
        plotOptions: { bar:{ borderRadius:4, columnWidth:'45%', dataLabels:{ position:'top' } } },
        dataLabels: { enabled:true, style:{fontSize:'10px', colors:['#c98a00']}, offsetY:-18, formatter:function(v){ return v > 0 ? v : ''; } },
        grid: GRID,
        tooltip: { y: { formatter: function(v, opts){ var reqs = <?= json_encode($lv_month_reqs) ?>[opts.dataPointIndex] || 0; return v + ' day(s) · ' + reqs + ' request(s)'; } } },
    }).render();

    // ── Dynamic period selector — refresh dept charts without reload ─
    var periodSel = el('deptpay-period');
    if (periodSel) {
        periodSel.addEventListener('change', function () {
            var pid = this.value;
            var cards = ['chart-deptstack','chart-deptavg','chart-deptotlate'].map(el);
            cards.forEach(function(c){ if (c) c.style.opacity = '.45'; });
            fetch('home-dashboard-ajax.php?action=deptpay&payroll_id=' + encodeURIComponent(pid), { credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.labels) return;
                    dpEmp = d.emp;
                    if (chartStack) chartStack.updateOptions({ xaxis:{ categories:d.labels }, series:[
                        { name:'Gross', data:d.gross }, { name:'Deductions', data:d.ded }, { name:'Net', data:d.net } ] });
                    if (chartAvg) chartAvg.updateOptions({ xaxis:{ categories:d.labels }, series:[ { name:'Avg Net', data:d.avg } ] });
                    if (chartOtLate) chartOtLate.updateOptions({ xaxis:{ categories:d.labels }, series:[
                        { name:'OT Pay', data:d.ot }, { name:'Late Deductions', data:d.late } ] });
                })
                .catch(function(){})
                .finally(function(){ cards.forEach(function(c){ if (c) c.style.opacity = ''; }); });
        });
    }

    // ── Section nav — smooth scroll + active highlight ──────────
    var nav = el('dash-nav');
    if (nav) {
        var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
        links.forEach(function(a){
            a.addEventListener('click', function(e){
                var t = document.querySelector(a.getAttribute('href'));
                if (!t) return;
                e.preventDefault();
                t.scrollIntoView({ behavior:'smooth', block:'start' });
                links.forEach(function(x){ x.classList.remove('active'); }); a.classList.add('active');
            });
        });
        if ('IntersectionObserver' in window) {
            var secs = links.map(function(a){ return document.querySelector(a.getAttribute('href')); }).filter(Boolean);
            var io = new IntersectionObserver(function(entries){
                entries.forEach(function(en){
                    if (!en.isIntersecting) return;
                    links.forEach(function(x){ x.classList.toggle('active', x.getAttribute('href') === '#' + en.target.id); });
                });
            }, { rootMargin:'-35% 0px -55% 0px' });
            secs.forEach(function(s){ io.observe(s); });
        }
    }

    // ── Live stat refresh (60s) — keeps counters current without reload ─
    function refreshStats() {
        fetch('home-dashboard-ajax.php?action=stats', { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d) return;
                document.querySelectorAll('[data-stat]').forEach(function(n){
                    var k = n.getAttribute('data-stat');
                    if (d[k] !== undefined && String(d[k]) !== n.textContent.trim()) n.textContent = d[k];
                });
                var ts = el('dash-refreshed');
                if (ts) { var now = new Date(); ts.textContent = now.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' }); }
            }).catch(function(){});
    }
    setInterval(refreshStats, 60000);
})();
</script>
