<?php
if (!isset($_GET['id'])) {
    return;
}
$id = (int) $_GET['id'];


$filter_query = "";
$sid = '';
if (isset($_GET['site_id'])  && $_GET['site_id'] !== 'all') {
    $sid = $_GET['site_id'];
    $filter_query = " AND a.site_id = $sid  ";
}
// LEFT JOIN payroll g ON g.id = a.payroll_id 
// LEFT JOIN employers  h ON g.employer_id = h.id

$query = "SELECT  employer_name, category,  clusters.cluster FROM payroll  
        LEFT JOIN employers  ON payroll.employer_id = employers.id  
        LEFT JOIN clusters  ON clusters.id = payroll.category 
        WHERE payroll.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll_r = $result->fetch_assoc();

?>
<?php
$query2 = "SELECT * FROM payroll   WHERE id = ?";
$stmt = $conn->prepare($query2);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$payroll = $result->fetch_assoc();
$site_ids = json_decode($payroll['site_ids'] ?? '', true);
$site_ids = is_array($site_ids) ? array_values(array_filter(array_map('intval', $site_ids))) : [];
$commaSeparatedSites = implode(',', $site_ids);
$status = $payroll['status'] ?? 0;

// ── Employee review progress (whole-batch sign-off, mirrors DTR) ──
$payrollReviewTotalEmp = (int) ($conn->query("SELECT COUNT(DISTINCT employee_id) AS c FROM payroll_items WHERE payroll_id = $id")->fetch_assoc()['c'] ?? 0);
$payrollReviewRows = [];
$payrollReviewConfirmed = $payrollReviewDisputed = 0;
$prvq = $conn->query("SELECT r.id, r.employee_id, r.status, r.comment, r.reviewed_at,
        r.resolved_at, r.admin_reply,
        CONCAT(e.lastname, ', ', e.firstname) AS name
    FROM payroll_employee_reviews r
    INNER JOIN employee e ON e.id = r.employee_id
    WHERE r.payroll_id = " . (int)$id . "
    ORDER BY r.status ASC, r.reviewed_at DESC");
if ($prvq) while ($prv = $prvq->fetch_assoc()) {
    $payrollReviewRows[$prv['employee_id']] = $prv;
    if ((int)$prv['status'] === 1) $payrollReviewConfirmed++;
    elseif ((int)$prv['status'] === 2) $payrollReviewDisputed++;
}
$payrollReviewPending = max(0, $payrollReviewTotalEmp - $payrollReviewConfirmed - $payrollReviewDisputed);

// Approved DTR_details entries behind each employee's "No. of Days" figure,
// grouped by employee + site so the view popover mirrors calculate_payroll()'s source data.
$dtrLogsByEmpSite = [];
if ($commaSeparatedSites !== '') {
    $df_esc = $conn->real_escape_string(date('Y-m-d', strtotime($payroll['date_from'])));
    $dt_esc = $conn->real_escape_string(date('Y-m-d', strtotime($payroll['date_to'])));
    $dtr_logs_q = $conn->query("SELECT DTR_details.employee_id, DTR_details.date_time, DTR_details.work_hours,
            DTR_details.overtime, DTR_details.undertime, DTR_details.late, DTR_details.logs, DTR.site_id
        FROM DTR_details
        INNER JOIN DTR ON DTR.id = DTR_details.ddtr_id
        WHERE DTR_details.date_time BETWEEN '$df_esc' AND '$dt_esc'
        AND DTR.status = 2 AND DTR_details.status = 1
        AND DTR.site_id IN ($commaSeparatedSites)
        ORDER BY DTR_details.date_time ASC");
    if ($dtr_logs_q) while ($dlr = $dtr_logs_q->fetch_assoc()) {
        $dtrLogsByEmpSite[$dlr['employee_id']][$dlr['site_id']][] = $dlr;
    }
}

// Renders the Approved Attendance Logs modal body: every approved day as a
// table of punches (# / Time / Direction / Source), mirroring the Time Log
// Details modal on attendance.php.
function dtr_days_popover_content($days) {
    if (empty($days)) {
        return '<div class="text-center text-muted py-4">'
             . '<i class="ri-calendar-close-line" style="font-size:28px;opacity:.4;"></i>'
             . '<div class="mt-1" style="font-size:12px;">No approved attendance found</div></div>';
    }
    $html = '';
    foreach ($days as $d) {
        $logs = json_decode($d['logs'], true) ?: [];
        $html .= '<div class="al-day">';
        $html .= '<div class="al-day-head">'
               . '<span class="al-day-date"><i class="ri-calendar-line me-1"></i>' . date('M j, Y (D)', strtotime($d['date_time'])) . '</span>'
               . '<span class="al-day-hrs">' . number_format((float)$d['work_hours'], 2) . ' hrs</span>'
               . '</div>';
        if (!empty($logs)) {
            $count = count($logs);
            $html .= '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0 al-table">'
                   . '<thead class="table-dark"><tr>'
                   . '<th class="text-center" style="width:36px;">#</th>'
                   . '<th><i class="ri-time-line me-1"></i>Time</th>'
                   . '<th class="text-center" style="width:80px;">Direction</th>'
                   . '<th class="text-center" style="width:80px;">Source</th>'
                   . '</tr></thead><tbody>';
            foreach ($logs as $li => $log) {
                $isBio = ($log['type'] ?? '') === 'bio';
                if ($li === 0) {
                    $dir = '<span class="att-log-dir att-log-in">IN</span>';
                } elseif ($li === $count - 1) {
                    $dir = '<span class="att-log-dir att-log-out">OUT</span>';
                } else {
                    $dir = '<span class="text-muted small">&mdash;</span>';
                }
                $src = $isBio
                    ? '<span class="badge att-log-bio"><i class="ri-fingerprint-line me-1"></i>Bio</span>'
                    : '<span class="badge att-log-manual"><i class="ri-edit-line me-1"></i>Manual</span>';
                $html .= '<tr>'
                       . '<td class="text-center text-muted">' . ($li + 1) . '</td>'
                       . '<td class="fw-semibold">' . date('g:i A', strtotime($log['dateTime'])) . '</td>'
                       . '<td class="text-center">' . $dir . '</td>'
                       . '<td class="text-center">' . $src . '</td>'
                       . '</tr>';
            }
            $html .= '</tbody></table></div>';
        } else {
            $html .= '<span class="text-muted small">No logs</span>';
        }
        $html .= '</div>';
    }
    return $html;
}

// ── Net delta vs previous payroll ───────────────────────────────────────
// Most recent payroll ending before this one starts; per-employee stored nets
// let each row show how its net moved since last period (typo'd rates and
// missed attendance stand out immediately).
$prevPayroll      = null;
$prevNetByEmpSite = [];
$prevNetByEmp     = [];
$prev_q = $conn->query("SELECT id, date_from, date_to FROM payroll
    WHERE id != $id AND date_to < '" . $conn->real_escape_string($payroll['date_from']) . "'
    ORDER BY date_to DESC, id DESC LIMIT 1");
if ($prev_q && $prev_q->num_rows) {
    $prevPayroll = $prev_q->fetch_assoc();
    $pn_q = $conn->query("SELECT employee_id, site_id, SUM(net) AS n FROM payroll_items
        WHERE payroll_id = " . (int)$prevPayroll['id'] . " GROUP BY employee_id, site_id");
    if ($pn_q) while ($pn = $pn_q->fetch_assoc()) {
        $prevNetByEmpSite[$pn['employee_id'] . '-' . $pn['site_id']] = (float)$pn['n'];
        $prevNetByEmp[$pn['employee_id']] = ($prevNetByEmp[$pn['employee_id']] ?? 0) + (float)$pn['n'];
    }
}

// ▲/▼ badge under the Net Pay figure. Empty when there is no previous payroll;
// "new" when the employee wasn't in it; nd-warn flags moves of ±30% or more.
function net_delta_badge($emp_id, $site_id, $net) {
    global $prevPayroll, $prevNetByEmpSite, $prevNetByEmp;
    if (!$prevPayroll) return '';
    $prev = $prevNetByEmpSite[$emp_id . '-' . $site_id] ?? $prevNetByEmp[$emp_id] ?? null;
    $period = date('M j', strtotime($prevPayroll['date_from'])) . '–' . date('M j', strtotime($prevPayroll['date_to']));
    if ($prev === null) {
        return '<div><span class="nd-badge nd-new" title="Not in previous payroll (' . $period . ')">new</span></div>';
    }
    if ($prev == 0.0) return '';
    $pct = (($net - $prev) / abs($prev)) * 100;
    $title = 'Previous (' . $period . '): &#8369;' . number_format($prev, 2);
    if (abs($pct) < 0.5) {
        return '<div><span class="nd-badge nd-flat" title="' . $title . '">&#8776; same</span></div>';
    }
    $cls  = $pct > 0 ? 'nd-up' : 'nd-down';
    $cls .= abs($pct) >= 30 ? ' nd-warn' : '';
    $arrow = $pct > 0 ? '&#9650;' : '&#9660;';
    return '<div><span class="nd-badge ' . $cls . '" title="' . $title . '">' . $arrow . ' ' . number_format(abs($pct), 1) . '%</span></div>';
}

// ── Remittance breakdown accumulator ────────────────────────────────────
// Filled inside both table loops (one call per deduction/refund cell), then
// rendered as the Remittance modal. Keyed type-id: 1 contribution, 2 deduction,
// 3 loan, 4 refund.
$remit = [];
$remit_add = function ($type, $dd_id, $amount) use (&$remit) {
    $key = $type . '-' . $dd_id;
    if (!isset($remit[$key])) $remit[$key] = ['type' => $type, 'id' => $dd_id, 'total' => 0.0, 'employees' => 0];
    $remit[$key]['total'] += (float)$amount;
    if ((float)$amount > 0) $remit[$key]['employees']++;
};

// Departments present in this payroll — feeds the toolbar's department filter.
$pay_departments = [];
$dept_q = $conn->query("SELECT DISTINCT d.name FROM payroll_items a
    INNER JOIN employee e ON a.employee_id = e.id
    INNER JOIN department d ON e.department_id = d.id
    WHERE a.payroll_id = $id ORDER BY d.name ASC");
if ($dept_q) while ($dq = $dept_q->fetch_assoc()) $pay_departments[] = $dq['name'];

$i = 0;
$query = $conn->query("SELECT  a.*, f.site_code,f.site_name,f.site_address, e.employee_no, e.lastname, e.firstname, e.middlename, e.basic_pay, d.name as department, p.name as position
FROM payroll_items a 
INNER JOIN employee e ON a.employee_id = e.id 
LEFT JOIN department d ON e.department_id = d.id 
LEFT JOIN position p ON e.position_id = p.id 
LEFT JOIN sites f ON f.id = a.site_id 
WHERE  a.payroll_id = $id $filter_query  ORDER BY lastname ASC ");

// ── Summary stats (pre-aggregated) ──
$sum_q = $conn->prepare("SELECT COUNT(*) as emp_count, SUM(net) as total_net, SUM(absent) as total_absent, SUM(late) as total_late FROM payroll_items WHERE payroll_id = ?");
$sum_q->bind_param("i", $id);
$sum_q->execute();
$summary = $sum_q->get_result()->fetch_assoc();

$contributions_settings = json_decode($payroll['settings'], true) ?: [];

$refunds_settings  = array_filter($contributions_settings, function ($item) {
    return $item["type"] === 4;
});

$contributions_settings = array_filter($contributions_settings, function ($item) {
    return $item["type"] !== 4;
});

$contributions_settings = array_values($contributions_settings);



$payroll_type = $payroll['type'];

?>
<link rel="stylesheet" href="assets2/css/my-style.css">
<style>
/* ════════════════════════════════════════════
   Payroll Details — Excel-style enhancements
   ════════════════════════════════════════════ */

/* ── Employee name cell (avatar + name + ID + status) — soft Excel look ── */
.emp-cell { display: flex; align-items: center; gap: 10px; padding: 1px 0; }
.emp-avatar {
    width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px;
    background: #d9eedd; color: #0b5e31; border: 1px solid #c0e0c8;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; letter-spacing: .5px; text-decoration: none;
    transition: background .15s, border-color .15s, transform .15s;
}
.emp-avatar:hover { background: #c6e4cd; border-color: #8fc7a6; color: #0b5e31; transform: translateY(-1px); }
.emp-cell-info { min-width: 0; display: flex; flex-direction: column; gap: 3px; line-height: 1.2; }
.emp-name-link { display: inline-flex; align-items: center; gap: 5px; color: #1f2937; font-size: 12.5px; text-decoration: none; transition: color .15s; }
.emp-name-link:hover { color: #107c41; }
.emp-name-link b { font-weight: 700; }
.emp-name-link i { display: none; }   /* the avatar already signals "person" */
.emp-meta-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.emp-no {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-family: ui-monospace, 'Segoe UI', monospace; font-weight: 700;
    color: #5b6470; background: #f2f4f6; border: 1px solid #e5e8ec;
    border-radius: 5px; padding: 1px 7px; white-space: nowrap;
}
.emp-no i { font-size: 11px; color: #9aa3ae; }

/* ── Summary strip ── */
.pay-stats-strip {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-bottom: 10px; padding: 10px 14px;
    background: #fff; border: 1px solid #e1dfdd;
    border-radius: 6px; border-left: 4px solid #107c41;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.pay-stat {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 14px; border-radius: 5px;
    background: #f8f9fa; border: 1px solid #eee;
    min-width: 140px; flex: 1;
}
.pay-stat-icon {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.pay-stat-body { line-height: 1.2; }
.pay-stat-val { font-size: 14px; font-weight: 800; color: #323130; }
.pay-stat-lbl { font-size: 10px; color: #a19f9d; text-transform: uppercase; letter-spacing: .4px; }
.pay-stat.employees .pay-stat-icon { background:#e6f5f3; color:#219688; }
.pay-stat.gross     .pay-stat-icon { background:#e8f5e9; color:#2e7d32; }
.pay-stat.deduct    .pay-stat-icon { background:#fce4ec; color:#c62828; }
.pay-stat.net       .pay-stat-icon { background:#e3f2fd; color:#1565c0; }
.pay-stat.absent    .pay-stat-icon { background:#fff8e1; color:#c98a00; }
.pay-stat.late      .pay-stat-icon { background:#fbe9e7; color:#bf360c; }

/* ── Excel table overrides ── */
/* keep border-collapse:separate — sticky columns require it */
/* Look: light pastel header bands + dark colored text + thin gray gridlines,
   like an Excel sheet — not saturated banner fills. */

/* Header row 1: section banners (pastel tint per group) */
#table-1 thead tr:first-child th {
    background: #d7ece9 !important;           /* mint — general/attendance group */
    color: #116257 !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: .4px !important;
    border: 1px solid #c2ddd8 !important;
    padding: 7px 8px !important;
}
#table-1 thead tr:first-child th.info-header    { background: #dde9f8 !important; color: #1e50a0 !important; border-color: #c5d8f0 !important; }
#table-1 thead tr:first-child th.success-header { background: #d9eedd !important; color: #107c41 !important; border-color: #c0e0c8 !important; }
#table-1 thead tr:first-child th.danger-header  { background: #fbe3e6 !important; color: #b02a37 !important; border-color: #f3cdd2 !important; }

/* Header row 2: column labels (lighter tint of the same family) */
#table-1 thead tr:nth-child(2) th {
    background: #ebf5f3 !important;
    color: #116257 !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    border: 1px solid #d5e6e2 !important;
    white-space: nowrap !important;
    padding: 5px 8px !important;
}
#table-1 thead tr:nth-child(2) th.info-header    { background: #eef4fc !important; color: #1e50a0 !important; border-color: #d8e5f5 !important; }
#table-1 thead tr:nth-child(2) th.success-header { background: #ebf7ee !important; color: #107c41 !important; border-color: #d3ead9 !important; }
#table-1 thead tr:nth-child(2) th.danger-header  { background: #fdf0f1 !important; color: #b02a37 !important; border-color: #f6dade !important; }

/* Body rows — white sheet, thin Excel gridlines, whisper striping */
#table-1 tbody tr td {
    font-size: 11px !important;
    border: 1px solid #e4e7e5 !important;
    padding: 4px 8px !important;
    vertical-align: middle !important;
}
#table-1 tbody tr:nth-child(even) td { background: #fafcfa !important; }
#table-1 tbody tr:nth-child(odd) td  { background: #ffffff !important; }
#table-1 tbody tr:hover td { background: #eaf6ef !important; }

/* Frozen cols (checkbox + No.) — light gray-green like Excel's row headers */
#table-1 tbody td:nth-child(1),
#table-1 tbody td:nth-child(2) {
    background: #f1f6f2 !important;
    border-right: 1px solid #dbe6dd !important;
    font-weight: 600 !important;
    color: #444 !important;
}
#table-1 tbody tr:hover td:nth-child(1),
#table-1 tbody tr:hover td:nth-child(2) { background: #ddeee3 !important; }
/* drop the col-2 edge shadow — the Name column now closes the frozen group */
#table-1 tbody td:nth-child(2),
#table-1 thead tr:first-child th:nth-child(2) { box-shadow: none !important; }

/* ── Frozen Name column (col 3) — left offset set by JS ── */
#table-1 tbody td:nth-child(3),
#table-1 thead tr:first-child th:nth-child(3) {
    position: sticky !important;
    z-index: 12;
    border-right: 2px solid #b8d8c2 !important;
    box-shadow: 3px 0 6px -3px rgba(0,0,0,.08);
    transform: translateZ(0);
    text-align: left !important;
}
#table-1 tbody td:nth-child(3) { background: #f5faf6 !important; }
#table-1 thead tr:first-child th:nth-child(3) { z-index: 14 !important; }
#table-1 tbody tr:nth-child(even) td:nth-child(3) { background: #eff6f0 !important; }
#table-1 tbody tr:hover td:nth-child(3) { background: #eaf6ef !important; }

/* Tfoot totals row — Excel-green band, dark green bold figures */
#table-1 tfoot tr th {
    background: #d9eedd !important;
    color: #0b5e31 !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    border: 1px solid #c0e0c8 !important;
    border-top: 2px solid #b8d8c2 !important;
    padding: 6px 8px !important;
    position: sticky; bottom: 0; z-index: 10;
}

/* Merged TOTAL label — spans the checkbox + No. + Name frozen columns
   (colspan="3" on the tfoot row; nth-child no longer applies to it, so it
   gets its own class-based sticky rule instead). */
#table-1 tfoot th.tfoot-total-cell {
    position: sticky !important;
    left: 0 !important;
    z-index: 12 !important;
    border-right: 2px solid #b8d8c2 !important;
    box-shadow: 3px 0 6px -3px rgba(0,0,0,.08);
    text-align: center !important;
    font-size: 12px !important;
    letter-spacing: .5px;
}

/* Net pay column — tinted like the screenshot's highlighted score column */
td.net-content { background: #eef4fc !important; color: #1e50a0 !important; font-weight: 800 !important; border-left: 2px solid #9dbdea !important; }
#table-1 tbody tr:hover td.net-content { background: #dce9f9 !important; }

/* Absent/late badge chips */
.pay-badge { display:inline-flex; align-items:center; gap:3px; font-size:9px; font-weight:700; border-radius:8px; padding:1px 7px; vertical-align:middle; white-space:nowrap; line-height:1.5; }
.pay-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; opacity:.65; }
.pay-badge.absent { background:#fdf3d7; color:#8a6d1a; border:1px solid #f0e2b0; }
.pay-badge.late   { background:#fdf0f1; color:#b02a37; border:1px solid #f6dade; }
.pay-badge.clear  { background:#ebf7ee; color:#107c41; border:1px solid #d3ead9; }
.pay-badge.clear::before { content:'\2713'; width:auto; height:auto; border-radius:0; background:none; opacity:1; font-size:8px; font-weight:900; }

/* Input cells */
#table-1 .input-group { margin-bottom:0 !important; }
#table-1 .input-class { font-size:11px !important; padding:2px 6px !important; height:26px !important; }

/* Payslip checkbox column */
.ps-chk-cell { width:32px; text-align:center !important; padding:4px 6px !important; }
.ps-chk { width:15px; height:15px; cursor:pointer; accent-color:#107c41; }

/* Actions column — view attendance logs modal trigger (paired with .xl-btn) */
.dtr-view-days { margin-left:4px; }

/* Approved Attendance Logs modal (mirrors attendance.php's Time Log Details modal) */
.att-log-dir { font-size:10px; font-weight:700; padding:1px 0; border-radius:3px; min-width:34px; text-align:center; display:inline-block; }
.att-log-in  { background:#d4edda; color:#155724; }
.att-log-out { background:#f8d7da; color:#721c24; }
.att-log-bio    { background:#009688; color:#fff; font-size:10px; }
.att-log-manual { background:#dc3545; color:#fff; font-size:10px; }
#modal-att-logs .al-meta-label { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.3px; }
#modal-att-logs .al-meta-value { font-size:13px; font-weight:700; color:#009688; }
.al-day { margin-bottom:14px; }
.al-day:last-child { margin-bottom:0; }
.al-day-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.al-day-date { font-size:12px; font-weight:700; color:#323130; }
.al-day-hrs { font-size:11px; font-weight:700; color:#107c41; background:#eafaf0; border:1px solid #b7e4c7; padding:1px 8px; border-radius:10px; }
.al-table th, .al-table td { font-size:12px; }

/* ── Search / dept filter / anomaly-flags toolbar ── */
.pay-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.pay-search-box { display:flex; align-items:center; gap:6px; background:#fff; border:1px solid #cfe3e0; border-radius:6px; padding:5px 10px; min-width:240px; }
.pay-search-box i { color:#219688; font-size:14px; }
.pay-search-box input { border:none; outline:none; font-size:12px; flex:1; background:transparent; min-width:160px; }
.pay-search-box button { border:none; background:none; color:#999; cursor:pointer; padding:0 2px; font-size:14px; line-height:1; }
#pay-dept-filter { border:1px solid #cfe3e0; border-radius:6px; background:#fff; font-size:12px; font-weight:600; color:#0e6b37; padding:5px 8px; cursor:pointer; }
.pay-anomaly-chips { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pay-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px; border:1px solid; cursor:pointer; user-select:none; background:#fff; transition:box-shadow .1s; }
.pay-chip:hover { box-shadow:0 1px 4px rgba(0,0,0,.12); }
.pay-chip.zero     { color:#856404; border-color:#ffe082; background:#fff8e1; }
.pay-chip.negative { color:#c62828; border-color:#f5c6cb; background:#fdecea; }
.pay-chip.noatt    { color:#b02a37; border-color:#f3cdd2; background:#fbe3e6; }
.pay-chip.bigmove  { color:#7c3aed; border-color:#ddd0f7; background:#f3eefe; }
.pay-chip.active { outline:2px solid currentColor; outline-offset:1px; }
.pay-chip.all-clear { color:#107c41; border-color:#b7e4c7; background:#eafaf0; cursor:default; }
.pay-filter-count { font-size:11px; color:#888; font-weight:600; margin-left:auto; }
#table-1 tbody tr.pay-row-hit td { background:#fffbe6 !important; }

/* ── Net delta vs previous payroll ── */
.nd-badge { display:inline-block; font-size:10px; font-weight:700; padding:0 6px; border-radius:8px; margin-top:2px; line-height:16px; }
.nd-up   { background:#eafaf0; color:#107c41; border:1px solid #b7e4c7; }
.nd-down { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.nd-flat { background:#f1f3f4; color:#777; border:1px solid #ddd; }
.nd-new  { background:#e3f2fd; color:#1565c0; border:1px solid #b7d5f5; }
.nd-badge.nd-warn { outline:2px solid #ffb300; outline-offset:1px; }

/* ── Remittance breakdown modal ── */
#modal-remit .rm-section-title { font-size:12px; font-weight:700; color:#107c41; display:flex; align-items:center; gap:6px; margin:12px 0 6px; }
#modal-remit .rm-section-title:first-child { margin-top:0; }
#modal-remit table th, #modal-remit table td { font-size:12px; }
#modal-remit .rm-grand { background:#f4faf5; font-weight:800; }

/* Employee review progress panel (mirrors DTR's, recolored to this page's Excel-green theme) */
.pr-review-panel { border:1px solid #cdeacb; background:#f4faf5; border-radius:10px; padding:10px 14px; margin-bottom:10px; }
.prp-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.prp-title { font-size:13px; font-weight:700; color:#0e6b37; display:flex; align-items:center; gap:6px; }
.prp-counts { display:flex; gap:6px; flex-wrap:wrap; }
.prp-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:12px; }
.prp-chip.appr { background:#eafaf0; color:#107c41; border:1px solid #b7e4c7; }
.prp-chip.disp { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.prp-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.prp-disputes { margin-top:8px; display:flex; flex-direction:column; gap:6px; }
.prp-dispute-item { display:flex; gap:8px; align-items:flex-start; background:#fff5f5; border:1px solid #f3d3d3; border-radius:8px; padding:7px 10px; }
.prp-dispute-item > i { color:#c62828; font-size:15px; margin-top:1px; }
.prp-emp { font-size:12px; font-weight:700; color:#333; }
.prp-when { font-size:10px; color:#999; font-weight:400; margin-left:6px; }
.prp-comment { font-size:12px; color:#555; margin-top:1px; }
.prp-confirmed-names { margin-top:8px; font-size:11px; color:#4a6b4a; }
.prp-confirmed-lbl { font-weight:700; color:#107c41; margin-right:4px; }
/* ── Large batches ──────────────────────────────────────────────────────────
   A 500-employee payroll used to dump every confirmed name into one runaway
   line and stack disputes unbounded, pushing the payroll table off-screen.
   Names now collapse behind a count; disputes scroll once there are a few. */
.prp-names { margin-top:8px; }
.prp-names > summary { list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    font-size:11px; font-weight:700; color:#107c41; user-select:none; }
.prp-names > summary::-webkit-details-marker { display:none; }
.prp-names > summary:hover .prp-names-hint { text-decoration:underline; }
.prp-count-pill { background:#eef7f0; border:1px solid #cfe9d6; color:#107c41;
    border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.prp-names-hint { font-weight:500; color:#8a8a8a; }
.prp-names .lbl-hide, .prp-names[open] .lbl-show { display:none; }
.prp-names[open] .lbl-hide { display:inline; }
.prp-chip-wrap { margin-top:6px; display:flex; flex-wrap:wrap; gap:4px;
    max-height:132px; overflow-y:auto; padding:2px; }
.prp-name-chip { background:#eef7f0; border:1px solid #cfe9d6; color:#2f5d3f;
    border-radius:10px; padding:1px 7px; font-size:11px; white-space:nowrap; }
.prp-disputes.is-scroll { max-height:340px; overflow-y:auto; padding-right:4px; }
.prp-act-btn { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:12px; border:1px solid; cursor:pointer; text-decoration:none; }
.prp-act-btn.remind { background:#fff8e1; color:#c98a00; border-color:#ffe082; }
.prp-act-btn.export { background:#eef7f0; color:#107c41; border-color:#cfe9d6; }
.prp-act-btn.resolve { margin-top:5px; background:#e9f7ef; color:#107c41; border-color:#b7e4c7; }
.prp-act-btn:hover { filter:brightness(0.97); }
.prp-resolved { margin-top:5px; font-size:11.5px; color:#107c41; font-weight:600; background:#f0faf3; border:1px solid #cdeeda; border-radius:6px; padding:4px 8px; }

/* ── Reviewer color marks: whole-row soft tint (1=ok, 2=issue, 3=reviewing) ── */
#table-1 tbody tr.review-ok > td       { background:#e9f7ee !important; }
#table-1 tbody tr.review-issue > td    { background:#fff4e2 !important; }
#table-1 tbody tr.review-checking > td { background:#e8f1fb !important; }
#table-1 tbody tr.review-ok:hover > td       { background:#dcf1e4 !important; }
#table-1 tbody tr.review-issue:hover > td    { background:#ffedd1 !important; }
#table-1 tbody tr.review-checking:hover > td { background:#dbe9f8 !important; }

/* Green = verified: inputs freeze into display text — no accidental edits */
tr.review-ok .input-class { pointer-events:none; background:transparent !important; border-color:transparent !important; box-shadow:none !important; font-weight:700; }

/* The dot button in the Actions column */
.review-mark-btn { margin-left:4px; }
.rv-dot { display:inline-block; width:11px; height:11px; border-radius:50%; background:#e1e5e3; border:1px solid #aab5b0; vertical-align:-1px; }
.rv-dot.rv-1 { background:#63c584; border-color:#3f9c63; }
.rv-dot.rv-2 { background:#f4ad60; border-color:#d68830; }
.rv-dot.rv-3 { background:#74ace6; border-color:#457fbd; }
.rv-comment-flag { color:#c98a00; font-size:12px; margin-left:2px; vertical-align:-1px; }

/* Swatch choices inside the review modal */
.rv-choice { display:flex; align-items:center; gap:10px; width:100%; text-align:left; border:2px solid #e3e7e5; border-radius:10px; background:#fff; padding:9px 12px; margin-bottom:7px; cursor:pointer; font-size:13px; font-weight:600; color:#333; }
.rv-choice .rv-dot { width:16px; height:16px; }
.rv-choice small { display:block; font-weight:400; color:#888; font-size:11px; }
.rv-choice:hover { border-color:#b8c4be; }
.rv-choice.selected { border-color:#107c41; background:#f2faf5; }
.rv-choice.selected.rv-c2 { border-color:#d68830; background:#fff8ee; }
.rv-choice.selected.rv-c3 { border-color:#457fbd; background:#f0f6fd; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <!-- start page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <div class="page-title-enhanced">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h4 class="mb-0 fw-bold">Payroll Details</h4>
                                    <?php if ($status == 2): ?>
                                        <span class="payroll-status-badge bg-danger text-white"><i class="ri-lock-fill me-1"></i>Locked</span>
                                    <?php elseif ($status == 3): ?>
                                        <span class="payroll-status-badge bg-primary text-white"><i class="ri-user-received-2-line me-1"></i>Ready for Review</span>
                                    <?php else: ?>
                                        <span class="payroll-status-badge bg-success text-white"><i class="ri-lock-unlock-line me-1"></i>Open</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="ri-calendar-2-line me-1"></i>
                                    <?= date('M d', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($payroll['date_to'])) ?>
                                    &nbsp;&bull;&nbsp;
                                    <i class="ri-user-2-line me-1"></i><?= htmlspecialchars($payroll_r['employer_name']) ?>
                                    <?php if ($payroll_r['category'] != 0): ?>
                                        &nbsp;&bull;&nbsp;<i class="ri-global-line me-1"></i><?= htmlspecialchars($payroll_r['cluster']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Payroll</a></li>
                                <li class="breadcrumb-item active">Payroll Details</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <div class="xl-panel" id="myDiv">
                    <div class="xl-ribbon">
                        <span class="xl-ribbon-title">
                            <i class="ri-file-excel-2-line"></i> Payroll List
                        </span>
                        <div class="xl-ribbon-actions">
                            <button data-toggle="tooltip" id="sf" title="Fullscreen" onclick="openFullscreen()" class="xl-btn"><i class="ri-fullscreen-line"></i></button>
                            <button style="display:none;" id="hf" data-toggle="tooltip" title="Exit Fullscreen" onclick="closeFullscreen()" class="xl-btn"><i class="ri-fullscreen-exit-line"></i></button>
                            <div class="xl-ribbon-sep"></div>
                            <!-- <button data-toggle="tooltip" title="Sites" onclick="view_site()" class="xl-btn"><i class="ri-building-line"></i> Sites</button> -->
                            <div class="xl-ribbon-sep"></div>
                            <?php if ($payroll_type == 5) { ?>
                                <button data-toggle="tooltip" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=monthly&id=<?= $id ?>', 'Payroll PDF')" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                            <?php } else { ?>
                                <button data-toggle="tooltip" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=payroll&id=<?= $id ?>&site_id=<?= $sid ?>', 'Payroll PDF')" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                                <!-- <button data-toggle="tooltip" title="Summary PDF" onclick="openPdfPreview('pdf-payroll.php?src=employer&id=<?= $id ?>&type=all', 'Payroll Summary PDF')" class="xl-btn"><i class="ri-printer-fill"></i> Summary</button> -->
                                <button data-toggle="tooltip" title="Summary by Department PDF" onclick="openPdfPreview('pdf-payroll.php?src=dept&id=<?= $id ?>', 'Department Summary PDF')" class="xl-btn"><i class="ri-building-2-line"></i> Dept. Summary</button>
                            <?php } ?>
                            <button data-toggle="tooltip" title="Totals per contribution, deduction, loan, and refund type" onclick="openRemitModal()" class="xl-btn"><i class="ri-hand-coin-line"></i> Remittance</button>
                            <button id="btn-print-payslips" title="Check rows to select employees, then click to print their payslips" onclick="printSelectedPayslips()" class="xl-btn">
                                <i class="ri-file-text-line"></i> Payslips <span id="ps-count" style="background:#c8e6e2;color:#176358;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:2px;font-weight:700;">0</span>
                            </button>
                            <?php if ($status == 1) { ?>
                                <div class="xl-ribbon-sep"></div>
                                <button data-toggle="tooltip" title="Send employees their payslip for review before locking" onclick="sendPayrollForReview(<?= $id ?>)" class="xl-btn xl-btn-save"><i class="ri-user-received-2-line"></i> Send for Review</button>
                            <?php } ?>
                            <?php if ($status !== 2) { ?>
                                <div class="xl-ribbon-sep"></div>
                                <button data-toggle="tooltip" title="Lock Payroll" onclick="lockPayroll(<?= $id ?>)" class="xl-btn xl-btn-danger"><i class="ri-lock-line"></i> Lock</button>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="saveUnsaved()" id="btn-unsaved" title="Save Changes" class="xl-btn xl-btn-save" style="display:none;">
                                    <i class="bx bx-save"></i> Save
                                    <span id="counter-unsaved">0</span>
                                </button>
                            <?php } ?>
                            <div class="xl-ribbon-sep"></div>
                            <button data-toggle="tooltip" title="Version History" onclick="openPayrollHistory(<?= $id ?>)" class="xl-btn"><i class="ri-history-line"></i> History</button>
                        </div>
                    </div>
                    <div class="xl-panel-body">
                        <!-- ── Summary stats strip ── -->
                        <div class="pay-stats-strip">
                            <div class="pay-stat employees">
                                <div class="pay-stat-icon"><i class="ri-group-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['emp_count']) ?></div>
                                    <div class="pay-stat-lbl">Employees</div>
                                </div>
                            </div>
                            <div class="pay-stat gross">
                                <div class="pay-stat-icon"><i class="ri-money-dollar-circle-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val" id="stat-gross">—</div>
                                    <div class="pay-stat-lbl">Total Gross</div>
                                </div>
                            </div>
                            <div class="pay-stat deduct">
                                <div class="pay-stat-icon"><i class="ri-subtract-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val" id="stat-deduct">—</div>
                                    <div class="pay-stat-lbl">Total Deductions</div>
                                </div>
                            </div>
                            <div class="pay-stat net">
                                <div class="pay-stat-icon"><i class="ri-wallet-3-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= '₱ ' . number_format($summary['total_net'], 2) ?></div>
                                    <div class="pay-stat-lbl">Total Net Pay</div>
                                </div>
                            </div>
                            <div class="pay-stat absent">
                                <div class="pay-stat-icon"><i class="ri-user-unfollow-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['total_absent']) ?></div>
                                    <div class="pay-stat-lbl">Total Absences</div>
                                </div>
                            </div>
                            <div class="pay-stat late">
                                <div class="pay-stat-icon"><i class="ri-alarm-warning-line"></i></div>
                                <div class="pay-stat-body">
                                    <div class="pay-stat-val"><?= number_format($summary['total_late']) ?> min</div>
                                    <div class="pay-stat-lbl">Total Late</div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Search / department filter / anomaly flags ── -->
                        <div class="pay-toolbar">
                            <div class="pay-search-box">
                                <i class="ri-search-line"></i>
                                <input type="text" id="pay-search" placeholder="Search name or employee no.&hellip;" autocomplete="off">
                                <button type="button" id="pay-search-clear" title="Clear search" style="display:none;"><i class="ri-close-line"></i></button>
                            </div>
                            <select id="pay-dept-filter" title="Filter by department">
                                <option value="">All Departments</option>
                                <?php foreach ($pay_departments as $pd): ?>
                                    <option value="<?= htmlspecialchars($pd, ENT_QUOTES) ?>"><?= htmlspecialchars($pd) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pay-anomaly-chips" id="pay-anomaly-chips"></div>
                            <span class="pay-filter-count" id="pay-filter-count"></span>
                        </div>

                        <?php if (in_array((int)$status, [2, 3], true)): ?>
                        <!-- ── Employee Review Progress ── -->
                        <div class="pr-review-panel">
                            <div class="prp-head">
                                <span class="prp-title"><i class="ri-user-received-2-line"></i> Employee Review
                                    <?php if ((int)$status === 3): ?>
                                        <span class="badge bg-info text-dark ms-1">In progress</span>
                                    <?php else: ?>
                                        <span class="badge bg-success ms-1">Locked</span>
                                    <?php endif; ?>
                                </span>
                                <div class="prp-counts">
                                    <span class="prp-chip appr"><i class="ri-checkbox-circle-line"></i> <?= $payrollReviewConfirmed ?> Confirmed</span>
                                    <span class="prp-chip disp"><i class="ri-error-warning-line"></i> <?= $payrollReviewDisputed ?> Disputed</span>
                                    <span class="prp-chip pend"><i class="ri-time-line"></i> <?= $payrollReviewPending ?> Awaiting</span>
                                    <?php if ((int)$status === 3 && $payrollReviewPending > 0): ?>
                                    <button type="button" class="prp-act-btn remind" onclick="remindPayrollReview(<?= $id ?>)" title="Remind employees who haven't reviewed">
                                        <i class="ri-notification-badge-line"></i> Remind (<?= $payrollReviewPending ?>)
                                    </button>
                                    <?php endif; ?>
                                    <a class="prp-act-btn export" href="ajax.php?action=export_payroll_reviews&id=<?= $id ?>" title="Download review log (CSV)">
                                        <i class="ri-download-2-line"></i> Export
                                    </a>
                                </div>
                            </div>
                            <?php if ($payrollReviewDisputed > 0): ?>
                            <div class="prp-disputes<?= $payrollReviewDisputed > 4 ? ' is-scroll' : '' ?>">
                                <?php foreach ($payrollReviewRows as $prv): if ((int)$prv['status'] !== 2) continue; ?>
                                <div class="prp-dispute-item">
                                    <i class="ri-error-warning-line"></i>
                                    <div style="flex:1;">
                                        <div class="prp-emp"><?= htmlspecialchars($prv['name']) ?>
                                            <span class="prp-when"><?= date('M j, g:i A', strtotime($prv['reviewed_at'])) ?></span>
                                        </div>
                                        <div class="prp-comment"><?= htmlspecialchars($prv['comment']) ?></div>
                                        <?php if (!empty($prv['resolved_at'])): ?>
                                            <div class="prp-resolved"><i class="ri-checkbox-circle-line"></i> Resolved — HR reply: <?= htmlspecialchars($prv['admin_reply']) ?></div>
                                        <?php else: ?>
                                            <button type="button" class="prp-act-btn resolve" onclick="openResolveDispute('payroll', <?= (int)$prv['id'] ?>, <?= htmlspecialchars(json_encode($prv['name']), ENT_QUOTES) ?>)">
                                                <i class="ri-chat-check-line"></i> Resolve &amp; Reply
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($payrollReviewConfirmed > 0): ?>
                            <?php $pcn = [];
                                foreach ($payrollReviewRows as $prv) if ((int)$prv['status'] === 1) $pcn[] = $prv['name'];
                            ?>
                            <details class="prp-names"<?= count($pcn) <= 12 ? ' open' : '' ?>>
                                <summary>
                                    <span class="prp-confirmed-lbl"><i class="ri-checkbox-circle-line"></i> Confirmed by</span>
                                    <span class="prp-count-pill"><?= count($pcn) ?></span>
                                    <span class="prp-names-hint"><span class="lbl-show">show names</span><span class="lbl-hide">hide</span></span>
                                </summary>
                                <div class="prp-chip-wrap">
                                    <?php foreach ($pcn as $n): ?>
                                    <span class="prp-name-chip"><?= htmlspecialchars($n) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <div class="xl-search-wrap">
                                <i class="ri-search-2-line"></i>
                                <input id="myInput" type="text" placeholder="Search...">
                            </div>
                        </div>
                        <form id="form-payroll">
                            <div class="table-responsive2" id="table-responsive2">
                                <?php if ($payroll_type == 5) { ?>

                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <!-- ═══ ROW 1 : Section group banners ═══ -->
                                            <tr>
                                                <th rowspan="2" class="text-center primary-header ps-chk-cell"><input type="checkbox" class="ps-chk" id="chk-all" title="Select all"></th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                                <th rowspan="2" class="text-center primary-header">Name</th>
                                                <th rowspan="2" class="text-center primary-header">Position</th>
                                                <!-- Basic Earnings (3 cols) -->
                                                <th colspan="3" class="text-center primary-header">Basic Earnings</th>
                                                <!-- Allowance (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Allowance</th>
                                                <!-- Attendance (4 cols) -->
                                                <th colspan="4" class="text-center primary-header">Attendance</th>
                                                <!-- Holidays & Extra Duties (6 cols) -->
                                                <th colspan="6" class="text-center info-header">Holidays &amp; Extra Duties</th>
                                                <!-- Overtime (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Overtime</th>
                                                <!-- Late (3 cols) -->
                                                <th colspan="3" class="text-center info-header">Late</th>
                                                <th rowspan="2" class="text-center success-header">Total Gross Pay</th>
                                                <!-- Deductions -->
                                                <th colspan="<?= count($contributions_settings) + 4 ?>" class="text-center danger-header">Deductions</th>
                                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                                <?php if (count($refunds_settings) > 0) { ?>
                                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center success-header">Refunds</th>
                                                <?php } ?>
                                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                                <th rowspan="2" class="text-center primary-header">Actions</th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                            </tr>
                                            <!-- ═══ ROW 2 : Individual column labels ═══ -->
                                            <tr>
                                                <!-- Basic Earnings -->
                                                <th class="text-center primary-header">Monthly Basic Pay</th>
                                                <th class="text-center primary-header">Quinsena Pay</th>
                                                <th class="text-center success-header">Basic Daily Rate</th>
                                                <!-- Allowance -->
                                                <th class="text-center info-header">No. Days</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Attendance -->
                                                <th class="text-center primary-header">No. of Duty</th>
                                                <th class="text-center primary-header">Absences</th>
                                                <th class="text-center primary-header">Amt. Absences</th>
                                                <th class="text-center success-header">Total Amount</th>
                                                <!-- Holidays & Extra Duties -->
                                                <th class="text-center info-header">Legal Holiday</th>
                                                <th class="text-center info-header">Amount</th>
                                                <th class="text-center info-header">Rest Day Duty</th>
                                                <th class="text-center info-header">Amount</th>
                                                <th class="text-center info-header">Special Holiday</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Overtime -->
                                                <th class="text-center info-header">No. Hrs</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Late -->
                                                <th class="text-center info-header">Minutes</th>
                                                <th class="text-center info-header">Rate</th>
                                                <th class="text-center info-header">Amount</th>
                                                <!-- Deduction sub-headers -->
                                                <?php if (count($contributions_settings) > 0) {
                                                    foreach ($contributions_settings as $k) {
                                                        if ($k['type'] == 1) {
                                                            $query_con = "SELECT * FROM contributions WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['contribution'];
                                                        } elseif ($k['type'] == 3) {
                                                            $query_con = "SELECT * FROM contribution_loan_types WHERE clt_id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['loan_type'];
                                                        } elseif ($k['type'] == 2) {
                                                            $query_con = "SELECT * FROM deductions WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $contribution = $stmt_con->get_result()->fetch_assoc();
                                                            $name_deduction = $contribution['deduction'];
                                                        }
                                                ?>
                                                        <th class="text-center danger-header"><?= htmlspecialchars($name_deduction) ?></th>
                                                <?php } } ?>
                                                <th class="text-center danger-header">SSS Provident Fund</th>
                                                <th class="text-center danger-header">JEI Advance</th>
                                                <th class="text-center danger-header">JCC Advances</th>
                                                <th class="text-center danger-header">Tax</th>
                                                <!-- Refund sub-headers -->
                                                <?php if (count($refunds_settings) > 0) {
                                                    foreach ($refunds_settings as $k) {
                                                        $query_con = "SELECT * FROM refunds WHERE id = ?";
                                                        $stmt_con = $conn->prepare($query_con);
                                                        $stmt_con->bind_param("i", $k['id']);
                                                        $stmt_con->execute();
                                                        $rfund = $stmt_con->get_result()->fetch_assoc();
                                                ?>
                                                        <th class="text-center success-header"><?= htmlspecialchars($rfund['refunds']) ?></th>
                                                <?php } } ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_number_days = 0;
                                            $t_basic_rate = 0;
                                            $t_per_day = 0;
                                            $t_allowance = 0;
                                            $t_gross = 0;
                                            $t_deduction = 0;
                                            $t_net = 0;
                                            $t_perDay = 0;
                                            $t_total_amount = 0;
                                            $t_ot_rate = 0;
                                            $t_late = 0;
                                            // ── full column totals ──
                                            $t_monthly = 0; $t_quinsena = 0;
                                            $t_allow_days = 0; $t_allow_rate = 0; $t_allow_total = 0;
                                            $t_absent_days = 0; $t_absent_amt = 0;
                                            $t_legal_d = 0; $t_legal_amt = 0;
                                            $t_sun_d = 0; $t_sun_amt = 0;
                                            $t_spc_d = 0; $t_spc_amt = 0;
                                            $t_ot_hrs = 0; $t_ot_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_sss_fund = 0; $t_jei = 0; $t_jcc = 0; $t_tax = 0;
                                            $t_contrib = []; $t_refund = [];

                                            while ($row = $query->fetch_assoc()) {
                                                $i++;
                                                $minutesPerDay = 8 * 60;
                                                $perMinute = $row['per_day'] / $minutesPerDay;
                                                $employee_id = $row['employee_id'];
                                                $total_basic_rate = $row['basic_pay'];
                                                $overtime_amount = $row['ot'] * $row['ot_rate'];
                                                $t_ot_rate += $row['ot_rate'];
                                                $late_amount = $row['late'] * $perMinute;
                                                $t_late += $perMinute;
                                                $undertime_amount = $row['under_time'] * $perMinute;
                                                $allowance_amount = $row['allowance_amount'];
                                                $allowance_days = $row['allowance_days'];
                                                $total_allowance = $allowance_amount *  $allowance_days;
                                                $tax = $row['tax'];
                                                $absent_amount = $row['absent'] *  $row['per_day'];
                                                $jei_advances = $row['jei_advances'];
                                                $jcc_advances = $row['jcc_advances'];
                                                $sss_fund = $row['sss_fund'];
                                                $perDay = $row['per_day'];
                                                $t_perDay += $perDay;
                                                $legal_holiday = $row['legal_holiday'];
                                                $legal_holiday_amount =  $legal_holiday * $perDay;
                                                $sunday_duty = $row['sunday_duty'];
                                                $sunday_duty_amount =  $sunday_duty * $perDay;
                                                $special_holiday = $row['special_holiday'];
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                $total_amount =  ($total_basic_rate    +  $total_allowance - $absent_amount) / 2;
                                                $t_total_amount += $total_amount;
                                                $gross_salary =  ($total_amount +   $overtime_amount   +  $legal_holiday_amount + $sunday_duty_amount +  $special_holiday_amount - $late_amount);

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;



                                                $total_number_days += $row['present'];
                                                $t_basic_rate += $total_basic_rate;
                                                $t_per_day += $perDay;
                                                $t_allowance += $allowance_amount;
                                                $t_gross += $gross_salary;
                                                // ── per-column totals ──
                                                $t_monthly     += $row['basic_pay'];
                                                $t_quinsena    += $row['basic_pay'] / 2;
                                                $t_allow_days  += $allowance_days;
                                                $t_allow_rate  += $allowance_amount;
                                                $t_allow_total += $total_allowance;
                                                $t_absent_days += $row['absent'];
                                                $t_absent_amt  += $absent_amount;
                                                $t_legal_d     += $legal_holiday;
                                                $t_legal_amt   += $legal_holiday_amount;
                                                $t_sun_d       += $sunday_duty;
                                                $t_sun_amt     += $sunday_duty_amount;
                                                $t_spc_d       += $special_holiday;
                                                $t_spc_amt     += $special_holiday_amount;
                                                $t_ot_hrs      += $row['ot'];
                                                $t_ot_amt      += $overtime_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_sss_fund    += $sss_fund;
                                                $t_jei         += $jei_advances;
                                                $t_jcc         += $jcc_advances;
                                                $t_tax         += $tax;

                                            ?>
                                                <?php
                                                    $rv = (int)($row['review_status'] ?? 0);
                                                    $rvClass = [1 => 'review-ok', 2 => 'review-issue', 3 => 'review-checking'][$rv] ?? '';
                                                ?>
                                                <tr class="name-<?= $row['id'] ?> <?= $rvClass ?>" data-row-id="<?= $row['id'] ?>" data-review="<?= $rv ?>" data-review-comment="<?= htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars(strtolower($row['lastname'] . ', ' . $row['firstname'] . ' ' . $row['employee_no']), ENT_QUOTES) ?>"
                                                    data-dept="<?= htmlspecialchars($row['department'] ?? '', ENT_QUOTES) ?>">
                                                    <td class="ps-chk-cell"><input type="checkbox" class="ps-chk ps-row-chk" value="<?= $row['id'] ?>"></td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>
                                                    <td style="min-width:220px;">
                                                        <?php $emp_initials = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1)); ?>
                                                        <div class="emp-cell">
                                                            <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-avatar" title="View employee details"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-name-link" title="View employee details">
                                                                    <i class="ri-user-3-line"></i><b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                                                                </a>
                                                                <div class="emp-meta-row">
                                                                    <span class="emp-no"><i class="ri-bank-card-line"></i><?= htmlspecialchars($row['employee_no']) ?></span>
                                                                    <span data-badges="<?= $row['id'] ?>">
                                                                        <?php if ($row['absent'] > 0): ?><span class="pay-badge absent"><?= $row['absent'] ?> absent</span><?php endif; ?>
                                                                        <?php if ($row['late'] > 0): ?><span class="pay-badge late"><?= number_format($row['late']) ?> min late</span><?php endif; ?>
                                                                        <?php if ($row['absent'] <= 0 && $row['late'] <= 0): ?><span class="pay-badge clear">clear</span><?php endif; ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="min-width: 200px;" class="text-center">
                                                        <?= $row['position'] ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b><?= number_format($row['basic_pay'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b><?= number_format($row['basic_pay'] / 2, 2) ?></b>
                                                    </td>
                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class" placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_days')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['allowance_days'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b data-computed="allowance_amount"><?= number_format($allowance_amount, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="allowance_total"><?= number_format($total_allowance, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <!-- <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $allowance_amount ?>" class="form-control input-class" placeholder="Allowance" aria-label="Allowance" aria-describedby="basic-addon2">
                                                                <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_amount')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div>
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($allowance_amount, 2) ?></b>
                                                        <?php } ?>
                                                    </td> -->
                                                    <!-- <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($allowance_amount / 2, 2) ?></b>
                                                    </td> -->
                                                    <!-- Basic Daily Rate -->
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['per_day'], 2) ?></b>
                                                    </td>
                                                    <!-- Allowance Daily Rate -->
                                                    <!-- <td class="text-right"><?= number_format($allowance_amount / 26, 2) ?></td> -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class" placeholder="No. of Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'present')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <?= $row['present'] ?>
                                                        <?php } ?>
                                                    </td>

                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['absent'] ?>" data-id="<?= $row['id'] ?>" data-type="absent" class="form-control input-class" placeholder="absent" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'absent')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['absent'] ?></b>
                                                        <?php } ?>
                                                    </td>

                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="absent_amount"><?= number_format($absent_amount, 2) ?></b>
                                                    </td>
                                                    <!-- total amount -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="total_amount"><?= number_format($total_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['legal_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="legal_holiday" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'legal_holiday')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['legal_holiday'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                         <b data-computed="legal_amount"><?= number_format($legal_holiday_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['sunday_duty'] ?>" data-id="<?= $row['id'] ?>" data-type="sunday_duty" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'sunday_duty')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['sunday_duty'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="sunday_amount"><?= number_format($sunday_duty_amount, 2) ?></b>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['special_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="special_holiday" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'special_holiday')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['special_holiday'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="special_amount"><?= number_format($special_holiday_amount, 2) ?></b>
                                                    </td>
                                                    <!-- ot -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'ot')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['ot'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['ot_rate'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="overtime_amount"><?= number_format($overtime_amount, 2) ?></b>
                                                    </td>

                                                    <!-- /ot -->

                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'late')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['late'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b><?= number_format($perMinute, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="late_amount"><?= number_format($late_amount, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="gross"><?= number_format($gross_salary, 2) ?></b>
                                                    </td>
                                                    <?php

                                                    if (count($contributions_settings) > 0) {
                                                        foreach ($contributions_settings as $i2 =>  $k) {
                                                            $deduction_amount = 0;
                                                            if ($k['type'] == 1) {
                                                                foreach ($contributions as $kd) {
                                                                    if ($kd["contribution_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 2) {
                                                                foreach ($deductions as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 3) {
                                                                foreach ($loans as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }

                                                            $total_deductions += $deduction_amount;
                                                            $t_contrib[$k['id']] = ($t_contrib[$k['id']] ?? 0) + $deduction_amount;
                                                            $remit_add($k['type'], $k['id'], $deduction_amount);


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                            <button
                                                                                onclick="updateData(this, <?= $row['id'] ?>, '<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>', <?= $k['id'] ?>)"
                                                                                class="btn btn-success"
                                                                                data-toggle="tooltip"
                                                                                title="Save Changes"
                                                                                type="button">
                                                                                <i class="ri-save-fill"></i>
                                                                            </button>
                                                                        </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($deduction_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                        <?php } ?>
                                                    <?php  } else { ?>

                                                    <?php } ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $sss_fund ?>" data-id="<?= $row['id'] ?>" data-type="sss_fund" class="form-control input-class" placeholder="Enter Amount" aria-label="sss_fund" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'sss_fund')"   class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($sss_fund, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jei_advances ?>" data-id="<?= $row['id'] ?>" data-type="jei_advances" class="form-control input-class" placeholder="Enter Amount" aria-label="jei_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jei_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jei_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jcc_advances ?>" data-id="<?= $row['id'] ?>" data-type="jcc_advances" class="form-control input-class" placeholder="Enter Amount" aria-label="jcc_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jcc_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jcc_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>

                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $tax ?>" class="form-control input-class" data-id="<?= $row['id'] ?>" data-type="tax" placeholder="Enter Amount" aria-label="Other Deduction" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'tax')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($tax, 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <?php $total_deductions = $total_deductions   + $tax + $jei_advances + $jcc_advances + $sss_fund;
                                                    $t_deduction += $total_deductions;  ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($total_deductions, 2) ?></b>
                                                    </td>
                                                    <!-- refunds -->
                                                    <?php if (count($refunds_settings) > 0) {;
                                                        foreach ($refunds_settings as $kd) {
                                                            $refund_amount = 0;
                                                            foreach ($refunds as $cd) {
                                                                if ($cd["refund_id"] == $kd["id"]) {
                                                                    $refund_amount = $cd["amount"];
                                                                }
                                                            }
                                                            $total_refunds += $refund_amount;
                                                            $t_refund[$kd['id']] = ($t_refund[$kd['id']] ?? 0) + $refund_amount;
                                                            $remit_add(4, $kd['id'], $refund_amount);





                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                        <button
                                                                            onclick="updateData(this, <?= $row['id'] ?>, 'refund', <?= $kd['id'] ?>)"
                                                                            class="btn btn-success"
                                                                            data-toggle="tooltip"
                                                                            title="Save Changes"
                                                                            type="button">
                                                                            <i class="ri-save-fill"></i>
                                                                        </button>
                                                                    </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($refund_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                    <?php
                                                        }
                                                    } ?>
                                                    <?php

                                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                                    $t_net += $net;
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                        <?= net_delta_badge($row['employee_id'], $row['site_id'], $net) ?>
                                                    </td>
                                                    <td style="min-width: 150px;" class="text-center">
                                                        <a href="view_payslip.php?id=<?= $row['id'] ?>" class="xl-btn" data-toggle="tooltip" title="View Payslip" onclick="openPayslipPreview(<?= $row['id'] ?>); return false;">
                                                            <i class="ri-file-text-line"></i> View
                                                        </a>
                                                        <?php $empDays = $dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []; ?>
                                                        <span class="xl-btn dtr-view-days"
                                                            data-pop-key="<?= (int)$row['employee_id'] ?>-<?= (int)$row['site_id'] ?>"
                                                            data-emp-name="<?= htmlspecialchars($row['lastname'] . ', ' . $row['firstname'], ENT_QUOTES) ?>"
                                                            data-days="<?= count($empDays) ?>"
                                                            title="Approved Attendance Logs">
                                                            <i class="ri-time-line"></i> Logs (<?= count($empDays) ?>)
                                                        </span>
                                                        <span class="xl-btn review-mark-btn" onclick="openReviewMark(<?= $row['id'] ?>)" data-toggle="tooltip"
                                                            title="<?= $rv === 0 ? 'Mark review status' : htmlspecialchars($row['review_comment'] ?: 'Marked', ENT_QUOTES) ?>">
                                                            <span class="rv-dot rv-<?= $rv ?>"></span>
                                                            <?php if (trim($row['review_comment'] ?? '') !== ''): ?><i class="ri-chat-3-fill rv-comment-flag"></i><?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />
                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="3" class="text-center tfoot-total-cell">TOTAL</th>
                                                <th></th>
                                                <th class="text-right"><?= number_format($t_monthly, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_quinsena, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_allow_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_total, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_perDay, 2) ?></th>
                                                <th class="text-center"><?= number_format($total_number_days, 0) ?></th>
                                                <th class="text-center"><?= number_format($t_absent_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_absent_amt, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_total_amount, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_legal_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_legal_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_sun_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_sun_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_spc_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_spc_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ot_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_late_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_late_amt, 2) ?></th>
                                                <th class="text-right" id="tfoot-gross"><?= number_format($t_gross, 2) ?></th>
                                                <?php foreach ($contributions_settings as $k): ?>
                                                <th class="text-right"><?= number_format($t_contrib[$k['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_sss_fund, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jei, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jcc, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_tax, 2) ?></th>
                                                <th class="text-right" id="tfoot-deduct"><?= number_format($t_deduction, 2) ?></th>
                                                <?php foreach ($refunds_settings as $kd): ?>
                                                <th class="text-right"><?= number_format($t_refund[$kd['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>




                                <?php } else { ?>
                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" class="text-center primary-header ps-chk-cell"><input type="checkbox" class="ps-chk" id="chk-all" title="Select all"></th>
                                                <th rowspan="2" class="text-center primary-header">No.</th>
                                                <th rowspan="2" class="text-center primary-header">Name</th>
                                                <th rowspan="2" class="text-center primary-header">Position</th>
                                                <th rowspan="2" class="text-center  primary-header">No. of Days</th>
                                                <th rowspan="2" class="text-center  primary-header">Basic Rate</th>
                                                <th rowspan="2" class="text-center  primary-header">Total Basic Rate</th>
                                                <th colspan="3" class="text-center  info-header">Allowance</th>
                                                <th colspan="3" class="text-center info-header">Overtime</th>
                                                <th colspan="3" class="text-center info-header">Late</th>
                                                <th rowspan="2" class="text-center success-header">GROSS SALARY</th>
                                                <th colspan="<?= count($contributions_settings) ?>" class="text-center danger-header">Deduction</th>
                                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                                <?php if (count($refunds_settings) > 0) { ?>
                                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center primary-header">Refunds</th>
                                                <?php } ?>
                                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                                <th rowspan="2" class="text-center  primary-header">Actions</th>
                                                <th rowspan="2" class="text-center  primary-header">No.</th>
                                            </tr>
                                            <tr>
                                                <th class="text-center  info-header">No. dys</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <th class="text-center  info-header">No. hr</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <th class="text-center  info-header">Min</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <?php if (count($contributions_settings) > 0) {
                                                    foreach ($contributions_settings as $k) {
                                                        if ($k['type'] == 1) {
                                                            $query_con = "SELECT * FROM contributions   WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction = $contribution['contribution'];
                                                        } else if ($k['type'] == 3) {
                                                            $query_con = "SELECT * FROM contribution_loan_types   WHERE clt_id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction =  $contribution['loan_type'];
                                                        } else if ($k['type'] == 2) {
                                                            $query_con = "SELECT * FROM deductions   WHERE id = ?";
                                                            $stmt_con = $conn->prepare($query_con);
                                                            $stmt_con->bind_param("i", $k['id']);
                                                            $stmt_con->execute();
                                                            $result_con = $stmt_con->get_result();
                                                            $contribution = $result_con->fetch_assoc();
                                                            $name_deduction =  $contribution['deduction'];
                                                        }


                                                ?>
                                                        <th class="text-center danger-header"><?= $name_deduction ?></th>
                                                    <?php } ?>
                                                <?php } else { ?>
                                                <?php } ?>
                                                <!-- <th class="text-center  danger-header">SSS PROVIDENT FUND </th>
                                                <th class="text-center  danger-header">JEI ADVANCE</th>
                                                <th class="text-center  danger-header">JCC ADVANCES</th> -->
                                                <?php if (count($refunds_settings) > 0) {
                                                    foreach ($refunds_settings as $k) {
                                                        $query_con = "SELECT * FROM refunds   WHERE id = ?";
                                                        $stmt_con = $conn->prepare($query_con);
                                                        $stmt_con->bind_param("i", $k['id']);
                                                        $stmt_con->execute();
                                                        $result_con = $stmt_con->get_result();
                                                        $rfund = $result_con->fetch_assoc();
                                                        $name_refunds =  $rfund['refunds'];

                                                ?>
                                                        <th class="text-center success-header"><?= $name_refunds ?></th>
                                                <?php }
                                                } ?>

                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_number_days = 0;
                                            $t_basic_rate = 0;
                                            $t_per_day = 0;
                                            $t_allowance = 0;
                                            $t_gross = 0;
                                            $t_deduction = 0;
                                            $t_net = 0;
                                            $t_perDay = 0;
                                            $t_total_amount = 0;
                                            $t_ot_rate = 0;
                                            $t_late = 0;
                                            // ── full column totals ──
                                            $t_monthly = 0; $t_quinsena = 0;
                                            $t_allow_days = 0; $t_allow_rate = 0; $t_allow_total = 0;
                                            $t_absent_days = 0; $t_absent_amt = 0;
                                            $t_legal_d = 0; $t_legal_amt = 0;
                                            $t_sun_d = 0; $t_sun_amt = 0;
                                            $t_spc_d = 0; $t_spc_amt = 0;
                                            $t_ot_hrs = 0; $t_ot_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_sss_fund = 0; $t_jei = 0; $t_jcc = 0; $t_tax = 0;
                                            $t_contrib = []; $t_refund = [];

                                            while ($row = $query->fetch_assoc()) {
                                                $i++;
                                                $minutesPerDay = 8 * 60;
                                                $perMinute = $row['per_day'] / $minutesPerDay;
                                                $employee_id = $row['employee_id'];
                                                $total_basic_rate = $row['present'] * $row['per_day'];
                                                $overtime_amount = $row['ot'] * $row['ot_rate'];
                                                $t_ot_rate += $row['ot_rate'];
                                                $late_amount = $row['late'] * $perMinute;
                                                $t_late += $perMinute;
                                                $undertime_amount = $row['under_time'] * $perMinute;
                                                $allowance_amount = $row['allowance_amount'];
                                                $allowance_days = $row['allowance_days'];
                                                $total_allowance = $allowance_amount *  $allowance_days;
                                                $tax = $row['tax'];
                                                $absent_amount = $row['absent'] *  $row['per_day'];
                                                $jei_advances = $row['jei_advances'];
                                                $jcc_advances = $row['jcc_advances'];
                                                $sss_fund = $row['sss_fund'];
                                                $perDay = $row['per_day'];
                                                $t_perDay += $perDay;
                                                $legal_holiday = $row['legal_holiday'];
                                                $legal_holiday_amount =  $legal_holiday * $perDay;
                                                $sunday_duty = $row['sunday_duty'];
                                                $sunday_duty_amount =  $sunday_duty * $perDay;
                                                $special_holiday = $row['special_holiday'];
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                $total_amount =  ($total_basic_rate    +  $total_allowance - $absent_amount) / 2;
                                                $t_total_amount += $total_amount;
                                                $gross_salary =  (($total_basic_rate +   $overtime_amount   +  $total_allowance)   - $late_amount);

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;


                                                $total_number_days += $row['present'];
                                                $t_basic_rate += $total_basic_rate;
                                                $t_per_day += $perDay;
                                                $t_allowance += $allowance_amount;
                                                $t_gross += $gross_salary;
                                                // ── per-column totals ──
                                                $t_monthly     += $row['basic_pay'];
                                                $t_quinsena    += $row['basic_pay'] / 2;
                                                $t_allow_days  += $allowance_days;
                                                $t_allow_rate  += $allowance_amount;
                                                $t_allow_total += $total_allowance;
                                                $t_absent_days += $row['absent'];
                                                $t_absent_amt  += $absent_amount;
                                                $t_legal_d     += $legal_holiday;
                                                $t_legal_amt   += $legal_holiday_amount;
                                                $t_sun_d       += $sunday_duty;
                                                $t_sun_amt     += $sunday_duty_amount;
                                                $t_spc_d       += $special_holiday;
                                                $t_spc_amt     += $special_holiday_amount;
                                                $t_ot_hrs      += $row['ot'];
                                                $t_ot_amt      += $overtime_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_sss_fund    += $sss_fund;
                                                $t_jei         += $jei_advances;
                                                $t_jcc         += $jcc_advances;
                                                $t_tax         += $tax;

                                            ?>
                                                <?php
                                                    $rv = (int)($row['review_status'] ?? 0);
                                                    $rvClass = [1 => 'review-ok', 2 => 'review-issue', 3 => 'review-checking'][$rv] ?? '';
                                                ?>
                                                <tr class="name-<?= $row['id'] ?> <?= $rvClass ?>" data-row-id="<?= $row['id'] ?>" data-review="<?= $rv ?>" data-review-comment="<?= htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars(strtolower($row['lastname'] . ', ' . $row['firstname'] . ' ' . $row['employee_no']), ENT_QUOTES) ?>"
                                                    data-dept="<?= htmlspecialchars($row['department'] ?? '', ENT_QUOTES) ?>">
                                                    <td class="ps-chk-cell"><input type="checkbox" class="ps-chk ps-row-chk" value="<?= $row['id'] ?>"></td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>
                                                    <td style="min-width:220px;">
                                                        <?php $emp_initials = strtoupper(substr($row['firstname'],0,1).substr($row['lastname'],0,1)); ?>
                                                        <div class="emp-cell">
                                                            <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>" target="_blank" class="emp-avatar" title="View employee details"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="index.php?page=employee-details&id=<?= $row['employee_id'] ?>"  class="emp-name-link" title="View employee details">
                                                                    <i class="ri-user-3-line"></i><b><?= $row['lastname'] ?>, <?= $row['firstname'] ?></b>
                                                                </a>
                                                                <div class="emp-meta-row">
                                                                    <span class="emp-no"><i class="ri-bank-card-line"></i><?= htmlspecialchars($row['employee_no']) ?></span>
                                                                    <span data-badges="<?= $row['id'] ?>">
                                                                        <?php if ($row['absent'] > 0): ?><span class="pay-badge absent"><?= $row['absent'] ?> absent</span><?php endif; ?>
                                                                        <?php if ($row['late'] > 0): ?><span class="pay-badge late"><?= number_format($row['late']) ?> min late</span><?php endif; ?>
                                                                        <?php if ($row['absent'] <= 0 && $row['late'] <= 0): ?><span class="pay-badge clear">clear</span><?php endif; ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="min-width: 200px;" class="text-center">
                                                        <?= $row['position'] ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class" placeholder="No. of Days" aria-describedby="basic-addon2">
                                                            </div>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['per_day'] ?>" data-id="<?= $row['id'] ?>" data-type="per_day" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($row['per_day'], 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 130px;">
                                                        <b data-computed="total_basic_rate"><?= number_format($total_basic_rate, 2) ?></b>
                                                    </td>
                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class" placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'allowance_days')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['allowance_days'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b data-computed="allowance_amount"><?= number_format($allowance_amount, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="allowance_total"><?= number_format($total_allowance, 2) ?></b>
                                                    </td>


                                                    <!-- ot -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'ot')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['ot'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($row['ot_rate'], 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="overtime_amount"><?= number_format($overtime_amount, 2) ?></b>
                                                    </td>

                                                    <!-- /ot -->

                                                    <!-- Late -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($status === 1) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class" placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'late')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['late'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b><?= number_format($perMinute, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="late_amount"><?= number_format($late_amount, 2) ?></b>
                                                    </td>
                                                    <!-- /Late -->
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="gross"><?= number_format($gross_salary, 2) ?></b>
                                                    </td>
                                                    <?php

                                                    if (count($contributions_settings) > 0) {
                                                        foreach ($contributions_settings as $i2 =>  $k) {
                                                            $deduction_amount = 0;
                                                            if ($k['type'] == 1) {
                                                                foreach ($contributions as $kd) {
                                                                    if ($kd["contribution_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 2) {
                                                                foreach ($deductions as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }
                                                            if ($k['type'] == 3) {
                                                                foreach ($loans as $kd) {
                                                                    if ($kd["deduction_id"] == $k["id"]) {
                                                                        $deduction_amount = $kd["amount"];
                                                                    }
                                                                }
                                                            }

                                                            $total_deductions += $deduction_amount;
                                                            $t_contrib[$k['id']] = ($t_contrib[$k['id']] ?? 0) + $deduction_amount;
                                                            $remit_add($k['type'], $k['id'], $deduction_amount);


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                        <!-- <div class="input-group-append">
                                                                            <button
                                                                                onclick="updateData(this, <?= $row['id'] ?>, '<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>', <?= $k['id'] ?>)"
                                                                                class="btn btn-success"
                                                                                data-toggle="tooltip"
                                                                                title="Save Changes"
                                                                                type="button">
                                                                                <i class="ri-save-fill"></i>
                                                                            </button>
                                                                        </div> -->
                                                                    </div>
                                                                <?php } else { ?>
                                                                    <b><?= number_format($deduction_amount, 2) ?></b>
                                                                <?php } ?>
                                                            </td>
                                                        <?php } ?>
                                                    <?php  } else { ?>

                                                    <?php } ?>
                                                    <?php $total_deductions = $total_deductions;
                                                    $t_deduction += $total_deductions;  ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b><?= number_format($total_deductions, 2) ?></b>
                                                    </td>
                                                    <!-- refunds -->
                                                    <?php if (count($refunds_settings) > 0) {;
                                                        foreach ($refunds_settings as $kd) {
                                                            $refund_amount = 0;
                                                            foreach ($refunds as $cd) {
                                                                if ($cd["refund_id"] == $kd["id"]) {
                                                                    $refund_amount = $cd["amount"];
                                                                }
                                                            }
                                                            $total_refunds += $refund_amount;
                                                            $t_refund[$kd['id']] = ($t_refund[$kd['id']] ?? 0) + $refund_amount;
                                                            $remit_add(4, $kd['id'], $refund_amount);

                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($status === 1) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class" placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                    <?php } else { ?>
                                                                        <b><?= number_format($refund_amount, 2) ?></b>
                                                                    <?php } ?>
                                                            </td>
                                                    <?php
                                                        }
                                                    } ?>
                                                    <?php

                                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                                    $t_net += $net;
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                        <?= net_delta_badge($row['employee_id'], $row['site_id'], $net) ?>
                                                    </td>
                                                    <td style="min-width: 150px;" class="text-center">
                                                        <a href="view_payslip.php?id=<?= $row['id'] ?>" class="xl-btn" data-toggle="tooltip" title="View Payslip" onclick="openPayslipPreview(<?= $row['id'] ?>); return false;">
                                                            <i class="ri-file-text-line"></i> View
                                                        </a>
                                                        <?php $empDays = $dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []; ?>
                                                        <span class="xl-btn dtr-view-days"
                                                            data-pop-key="<?= (int)$row['employee_id'] ?>-<?= (int)$row['site_id'] ?>"
                                                            data-emp-name="<?= htmlspecialchars($row['lastname'] . ', ' . $row['firstname'], ENT_QUOTES) ?>"
                                                            data-days="<?= count($empDays) ?>"
                                                            title="Approved Attendance Logs">
                                                            <i class="ri-time-line"></i> Logs (<?= count($empDays) ?>)
                                                        </span>
                                                        <span class="xl-btn review-mark-btn" onclick="openReviewMark(<?= $row['id'] ?>)" data-toggle="tooltip"
                                                            title="<?= $rv === 0 ? 'Mark review status' : htmlspecialchars($row['review_comment'] ?: 'Marked', ENT_QUOTES) ?>">
                                                            <span class="rv-dot rv-<?= $rv ?>"></span>
                                                            <?php if (trim($row['review_comment'] ?? '') !== ''): ?><i class="ri-chat-3-fill rv-comment-flag"></i><?php endif; ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />

                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="3" class="text-center tfoot-total-cell">TOTAL</th>
                                                <th></th>
                                                <th class="text-center"><?= number_format($total_number_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_per_day, 2) ?></th>
                                                <th class="text-right" id="tfoot-basic-rate"><?= number_format($t_basic_rate, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_allow_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_total, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ot_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_late_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_late_amt, 2) ?></th>
                                                <th class="text-right" id="tfoot-gross"><?= number_format($t_gross, 2) ?></th>
                                                <?php foreach ($contributions_settings as $k): ?>
                                                <th class="text-right"><?= number_format($t_contrib[$k['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right" id="tfoot-deduct"><?= number_format($t_deduction, 2) ?></th>
                                                <?php foreach ($refunds_settings as $kd): ?>
                                                <th class="text-right"><?= number_format($t_refund[$kd['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                <?php } ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- end page title -->
        </div>
        <!-- container-fluid -->
    </div>
    <!-- End Page-content -->

</div>

<!-- ── Version History Offcanvas ──────────────────────── -->
<style>
#offcanvas-history { width: 460px; max-width: 100vw; }
.vh-header {
    background: #d9eedd;
    padding: 16px 18px 0;
    color: #10453d;
    position: relative; overflow: hidden;
}

.vh-header-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px; position:relative; }
.vh-title { font-size:15px; font-weight:800; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
.vh-title-icon {
    width:26px; height:26px; border-radius:8px;
    background:rgba(16,69,61,.10); border:1px solid rgba(16,69,61,.16);
    display:flex; align-items:center; justify-content:center; font-size:14px;
}
.vh-subtitle { font-size:11px; color:rgba(16,69,61,.8); margin-top:3px; }
.vh-count-badge {
    background:rgba(16,69,61,.12); color:#10453d;
    font-size:11px; font-weight:700;
    border-radius:20px; padding:3px 10px;
    backdrop-filter:blur(4px); white-space:nowrap;
}
.vh-tabs {
    display:flex; gap:0; border-bottom: none; margin-top:4px; position:relative;
}
.vh-tab {
    padding:8px 13px; font-size:11px; font-weight:600;
    color:rgba(16,69,61,.75); cursor:pointer;
    border-bottom:2px solid transparent;
    transition:all .15s; white-space:nowrap;
    display:flex; align-items:center; gap:5px;
}
.vh-tab.active { color:#10453d; border-bottom-color:#219688; }
.vh-tab:hover:not(.active) { color:rgba(16,69,61,.95); }
.vh-tab-count {
    font-size:9px; font-weight:800; line-height:1;
    background:rgba(16,69,61,.12); border-radius:20px; padding:2px 5px;
}
.vh-tab.active .vh-tab-count { background:rgba(16,69,61,.2); }
.vh-search-bar {
    padding:10px 14px; background:#f8fffe;
    border-bottom:1px solid #e1dfdd;
    display:flex; align-items:center; gap:8px;
}
.vh-search-wrap { position:relative; flex:1; }
.vh-search-input {
    width:100%; border:1px solid #c8e6e2; border-radius:6px;
    padding:6px 28px 6px 32px; font-size:12px; color:#323130;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23a19f9d' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
    outline:none; transition:border-color .15s, box-shadow .15s;
}
.vh-search-input:focus { border-color:#219688; box-shadow:0 0 0 3px rgba(33,150,136,.12); }
.vh-search-clear {
    position:absolute; right:6px; top:50%; transform:translateY(-50%);
    border:none; background:none; color:#a19f9d; font-size:14px;
    line-height:1; cursor:pointer; padding:2px; display:none;
}
.vh-search-clear:hover { color:#605e5c; }
.vh-date-group-label {
    padding:7px 16px;
    font-size:10px; font-weight:800;
    color:#176358; text-transform:uppercase; letter-spacing:.6px;
    background:#f4fbfa; border-top:1px solid #e6f5f3; border-bottom:1px solid #e6f5f3;
    position:sticky; top:0; z-index:2;
    display:flex; align-items:center; gap:6px;
}
.vh-date-group-label .vh-group-count { margin-left:auto; color:#8aa9a4; font-weight:700; }
.vh-entry {
    display:flex; gap:12px; padding:13px 16px 13px 14px;
    border-bottom:1px solid #f4f4f4;
    transition:background .12s; position:relative;
}
.vh-entry:hover { background:#f9fffe; }
.vh-entry.is-latest { background:#f2fdfa; }
.vh-entry.is-latest::before {
    content:''; position:absolute; left:0; top:0; bottom:0;
    width:3px; border-radius:0 2px 2px 0;
    background:#219688;
}
.vh-timeline {
    display:flex; flex-direction:column; align-items:center;
    flex-shrink:0; width:30px;
}
.vh-node {
    width:30px; height:30px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    font-size:14px; transition:transform .15s;
}
.vh-entry:hover .vh-node { transform:scale(1.08); }
.vh-line { width:2px; flex:1; min-height:14px; background:#e8ecec; margin-top:6px; border-radius:2px; }
.vh-content { flex:1; min-width:0; padding-top:2px; }
.vh-event-row { display:flex; align-items:flex-start; gap:8px; margin-bottom:6px; }
.vh-event-text {
    font-size:12.5px; font-weight:600; color:#323130;
    flex:1; min-width:0; line-height:1.45;
}
.vh-event-sub { font-size:11px; font-weight:500; color:#8a8886; margin-top:1px; }
.vh-latest-badge {
    font-size:9px; font-weight:800; letter-spacing:.3px;
    background:#219688; color:#fff;
    border-radius:20px; padding:2px 7px;
    flex-shrink:0; margin-top:1px;
}
.vh-chips { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:7px; }
.vh-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:10.5px; font-weight:600; line-height:1.6;
    border-radius:5px; padding:1px 7px;
    background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb;
    max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.vh-chip i { font-size:11px; opacity:.75; }
.vh-chip-emp { background:#eef4ff; color:#3557b7; border-color:#dbe4fb; }
.vh-chip-val { background:#ecfdf5; color:#0f766e; border-color:#d3f0e8; font-variant-numeric:tabular-nums; }
.vh-meta { display:flex; align-items:center; gap:7px; }
.vh-avatar {
    width:22px; height:22px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:8.5px; font-weight:800; flex-shrink:0;
}
.vh-user { font-size:11px; color:#605e5c; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.vh-time-block { margin-left:auto; text-align:right; flex-shrink:0; }
.vh-rel-time { font-size:11px; font-weight:600; color:#323130; }
.vh-abs-time { font-size:9.5px; color:#a19f9d; }
.vh-empty { padding:48px 24px; text-align:center; color:#a19f9d; }
.vh-empty i { font-size:40px; display:block; margin-bottom:10px; opacity:.4; }
.vh-empty-title { font-size:13px; font-weight:600; color:#605e5c; margin-bottom:4px; }
.vh-empty div:last-child { font-size:11.5px; }
.vh-skeleton { padding:14px 16px; display:flex; gap:12px; border-bottom:1px solid #f4f4f4; }
.vh-sk-node { width:30px; height:30px; border-radius:9px; flex-shrink:0; }
.vh-sk-lines { flex:1; }
.vh-sk-bar { height:9px; border-radius:4px; margin-bottom:7px; }
.vh-skeleton .vh-sk-node, .vh-skeleton .vh-sk-bar {
    background:linear-gradient(90deg,#eef1f1 25%,#f7f9f9 50%,#eef1f1 75%);
    background-size:200% 100%; animation:vh-shimmer 1.2s infinite;
}
@keyframes vh-shimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }
</style>

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas-history">
    <!-- Header -->
    <div class="vh-header">
        <div class="vh-header-top">
            <div>
                <div class="vh-title"><span class="vh-title-icon"><i class="ri-history-line"></i></span>Version History</div>
                <div class="vh-subtitle" id="vh-subtitle">Payroll change log</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="vh-count-badge" id="vh-count">—</span>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
        </div>
        <!-- Filter tabs -->
        <div class="vh-tabs" id="vh-tabs">
            <div class="vh-tab active" data-filter="all" onclick="vhFilter('all',this)"><i class="ri-stack-line"></i>All<span class="vh-tab-count" data-count="all">0</span></div>
            <div class="vh-tab" data-filter="calc"   onclick="vhFilter('calc',this)"><i class="ri-calculator-line"></i>Calculate<span class="vh-tab-count" data-count="calc">0</span></div>
            <div class="vh-tab" data-filter="lock"   onclick="vhFilter('lock',this)"><i class="ri-lock-line"></i>Lock<span class="vh-tab-count" data-count="lock">0</span></div>
            <div class="vh-tab" data-filter="update" onclick="vhFilter('update',this)"><i class="ri-edit-line"></i>Updates<span class="vh-tab-count" data-count="update">0</span></div>
        </div>
    </div>

    <!-- Search -->
    <div class="vh-search-bar">
        <div class="vh-search-wrap">
            <input class="vh-search-input" id="vh-search" placeholder="Search by action, employee or user…" oninput="vhSearch(this.value)">
            <button type="button" class="vh-search-clear" id="vh-search-clear" title="Clear" onclick="vhClearSearch()"><i class="ri-close-circle-fill"></i></button>
        </div>
    </div>

    <!-- Body -->
    <div class="offcanvas-body p-0" id="offcanvas-history-body" style="overflow-y:auto; background:#fff;"></div>
</div>

<!-- Attendance-logs modal bodies, rendered ONCE per employee+site. Both payroll
     tables reference these by data-pop-key instead of duplicating the full punch
     history into every row (which made large payrolls multi-MB pages). -->
<div id="dtr-pop-src" class="d-none">
    <?php foreach ($dtrLogsByEmpSite as $dppEmp => $dppSites): ?>
        <?php foreach ($dppSites as $dppSite => $dppDays): ?>
            <div id="dtr-pop-<?= (int)$dppEmp ?>-<?= (int)$dppSite ?>"><?= dtr_days_popover_content($dppDays) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

<!-- Approved Attendance Logs modal (style mirrors attendance.php's Time Log Details modal) -->
<div class="modal fade" id="modal-att-logs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#009688;">
                <h6 class="modal-title text-white"><i class="ri-history-line me-2"></i>Approved Attendance Logs</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <div class="al-meta-label"><i class="ri-user-line me-1"></i>Employee</div>
                        <div class="al-meta-value" id="al-employee"></div>
                    </div>
                    <div class="text-end">
                        <div class="al-meta-label"><i class="ri-calendar-range-line me-1"></i>Payroll Period</div>
                        <div class="al-meta-value"><?= date('M j, Y', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M j, Y', strtotime($payroll['date_to'])) ?></div>
                    </div>
                </div>
                <div id="al-body"></div>
            </div>
            <div class="modal-footer py-2">
                <span class="text-muted small me-auto" id="al-days-count"></span>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
// Resolves a remittance row's display name from its source table.
function remit_type_name($conn, $type, $dd_id) {
    $dd_id = (int)$dd_id;
    $sql = [
        1 => "SELECT contribution AS n FROM contributions WHERE id = $dd_id",
        2 => "SELECT deduction AS n FROM deductions WHERE id = $dd_id",
        3 => "SELECT loan_type AS n FROM contribution_loan_types WHERE clt_id = $dd_id",
        4 => "SELECT refunds AS n FROM refunds WHERE id = $dd_id",
    ][$type] ?? null;
    if (!$sql) return '#' . $dd_id;
    $r = $conn->query($sql);
    $row = $r ? $r->fetch_assoc() : null;
    return $row['n'] ?? ('#' . $dd_id);
}
$remit_groups = [
    1 => ['label' => 'Contributions', 'icon' => 'ri-hand-coin-line'],
    2 => ['label' => 'Deductions',    'icon' => 'ri-subtract-line'],
    3 => ['label' => 'Loans',         'icon' => 'ri-bank-card-line'],
    4 => ['label' => 'Refunds',       'icon' => 'ri-refund-2-line'],
];
$remit_deduction_total = 0;
$remit_refund_total    = 0;
foreach ($remit as $rm) {
    if ($rm['type'] == 4) $remit_refund_total += $rm['total'];
    else                  $remit_deduction_total += $rm['total'];
}
?>
<!-- Remittance breakdown modal — totals per contribution/deduction/loan/refund type -->
<div class="modal fade" id="modal-remit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="ri-hand-coin-line me-2"></i>Remittance Breakdown</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <div class="al-meta-label"><i class="ri-calendar-range-line me-1"></i>Payroll Period</div>
                        <div class="al-meta-value" style="color:#107c41;"><?= date('M j, Y', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M j, Y', strtotime($payroll['date_to'])) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="al-meta-label"><i class="ri-group-line me-1"></i>Employees</div>
                        <div class="al-meta-value" style="color:#107c41;"><?= number_format($summary['emp_count']) ?></div>
                    </div>
                </div>
                <?php foreach ($remit_groups as $rg_type => $rg):
                    $rows = array_filter($remit, function ($rm) use ($rg_type) { return $rm['type'] == $rg_type; });
                    if (empty($rows)) continue; ?>
                    <div class="rm-section-title"><i class="<?= $rg['icon'] ?>"></i><?= $rg['label'] ?></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-dark"><tr>
                                <th>Type</th>
                                <th class="text-center" style="width:100px;">Employees</th>
                                <th class="text-end" style="width:130px;">Total</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $rm): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars(remit_type_name($conn, $rm['type'], $rm['id'])) ?></td>
                                    <td class="text-center"><?= number_format($rm['employees']) ?></td>
                                    <td class="text-end fw-semibold">&#8369; <?= number_format($rm['total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <tbody>
                            <tr class="rm-grand">
                                <td>Total Deductions (contributions + deductions + loans)</td>
                                <td class="text-end" style="width:130px;">&#8369; <?= number_format($remit_deduction_total, 2) ?></td>
                            </tr>
                            <tr class="rm-grand">
                                <td>Total Refunds</td>
                                <td class="text-end">&#8369; <?= number_format($remit_refund_total, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted" style="font-size:11px;">Amounts reflect this payroll's configured deduction settings as currently displayed.</small>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
function openRemitModal() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-remit')).show();
}
</script>

<script>
var _vhData = [];
var _vhFilter = 'all';
var _vhSearch = '';

var actionMeta = {
    created:    { color:'#219688', bg:'#e6f5f3', icon:'ri-add-circle-line',       label:'Created'    },
    calculated: { color:'#2563eb', bg:'#dbeafe', icon:'ri-calculator-line',        label:'Calculated' },
    locked:     { color:'#7c3aed', bg:'#ede9fe', icon:'ri-lock-line',              label:'Locked'     },
    unlocked:   { color:'#059669', bg:'#d1fae5', icon:'ri-lock-unlock-line',       label:'Unlocked'   },
    approved:   { color:'#16a34a', bg:'#dcfce7', icon:'ri-checkbox-circle-line',   label:'Approved'   },
    review:     { color:'#0891b2', bg:'#cffafe', icon:'ri-send-plane-line',        label:'Sent for Review' },
    updated:    { color:'#d97706', bg:'#fef3c7', icon:'ri-edit-line',              label:'Updated'    },
    printed:    { color:'#db2777', bg:'#fce7f3', icon:'ri-printer-line',           label:'Printed'    },
    def:        { color:'#6b7280', bg:'#f3f4f6', icon:'ri-time-line',             label:'Event'      }
};

function vhEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}

function vhGetMeta(d) {
    d = (d || '').toLowerCase();
    if (d.includes('creat'))   return actionMeta.created;
    if (d.includes('re-calc') || d.includes('recalc')) return actionMeta.calculated;
    if (d.includes('calc'))    return actionMeta.calculated;
    if (d.includes('unlock'))  return actionMeta.unlocked;
    if (d.includes('lock'))    return actionMeta.locked;
    if (d.includes('review'))  return actionMeta.review;
    if (d.includes('approv'))  return actionMeta.approved;
    if (d.includes('print'))   return actionMeta.printed;
    if (d.includes('field:') || d.includes('updat') || d.includes('edit') || d.includes('save')) return actionMeta.updated;
    return actionMeta.def;
}

// Logged details for field edits arrive as
// "Employee: Doe, John & Field: Overtime & Value: 5" — split that into a
// readable headline plus chips instead of showing the raw string.
function vhParse(details) {
    var raw = details || '—';
    var m = raw.match(/^\s*Employee:\s*(.*?)\s*&\s*Field:\s*(.*?)\s*&\s*Value:\s*(.*?)\s*$/i);
    if (!m) return { title: raw, sub: null, employee: null, value: null };

    var field = m[2].replace(/\s+/g, ' ').trim();
    var value = m[3].trim();
    if (value !== '' && !isNaN(value)) {
        value = Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }
    return {
        title: field + ' updated',
        sub: 'Payroll line item changed',
        employee: m[1].trim(),
        value: value === '' ? '—' : value
    };
}

function vhRelTime(dateStr) {
    var diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)     return 'Just now';
    if (diff < 3600)   return Math.floor(diff/60) + 'm ago';
    if (diff < 86400)  return Math.floor(diff/3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
    return new Date(dateStr).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}

function vhDateGroup(dateStr) {
    var d = new Date(dateStr), now = new Date();
    var diffDays = Math.floor((now - d) / 86400000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7)  return 'This Week';
    if (diffDays < 30) return 'This Month';
    return d.toLocaleDateString('en-US',{month:'long',year:'numeric'});
}

function vhMatchFilter(row, filter) {
    var d = (row.details || '').toLowerCase();
    if (filter === 'calc')   return d.includes('calc');
    if (filter === 'lock')   return d.includes('lock');
    if (filter === 'update') return d.includes('field:') || d.includes('updat') || d.includes('edit') || d.includes('save');
    return true;
}

function vhMatchSearch(row) {
    if (!_vhSearch) return true;
    return (row.details || '').toLowerCase().includes(_vhSearch)
        || (row.name || '').toLowerCase().includes(_vhSearch);
}

function vhUpdateTabCounts() {
    document.querySelectorAll('.vh-tab-count').forEach(function(el) {
        var f = el.getAttribute('data-count');
        el.textContent = _vhData.filter(function(row) {
            return vhMatchFilter(row, f) && vhMatchSearch(row);
        }).length;
    });
}

function vhFilter(f, el) {
    _vhFilter = f;
    document.querySelectorAll('.vh-tab').forEach(function(t){ t.classList.remove('active'); });
    el.classList.add('active');
    vhRender();
}

function vhSearch(q) {
    _vhSearch = q.toLowerCase();
    var clear = document.getElementById('vh-search-clear');
    if (clear) clear.style.display = q ? 'block' : 'none';
    vhRender();
}

function vhClearSearch() {
    var input = document.getElementById('vh-search');
    if (input) input.value = '';
    vhSearch('');
    if (input) input.focus();
}

function vhRender() {
    var body = document.getElementById('offcanvas-history-body');
    var data = _vhData.filter(function(row) {
        return vhMatchFilter(row, _vhFilter) && vhMatchSearch(row);
    });

    vhUpdateTabCounts();

    var countEl = document.getElementById('vh-count');
    if (countEl) countEl.textContent = data.length + ' event' + (data.length !== 1 ? 's' : '');

    if (data.length === 0) {
        body.innerHTML = _vhData.length === 0
            ? '<div class="vh-empty"><i class="ri-time-line"></i><div class="vh-empty-title">No history yet</div><div>Changes to this payroll will appear here.</div></div>'
            : '<div class="vh-empty"><i class="ri-search-line"></i><div class="vh-empty-title">No events found</div><div>Try a different filter or search term</div></div>';
        return;
    }

    // Pre-group by day bucket so each header can show its own count.
    var groups = [];
    data.forEach(function(row) {
        var g = vhDateGroup(row.created_at);
        if (!groups.length || groups[groups.length - 1].label !== g) groups.push({ label: g, rows: [] });
        groups[groups.length - 1].rows.push(row);
    });

    var html = '';
    var index = 0;

    groups.forEach(function(group) {
        html += '<div class="vh-date-group-label"><i class="ri-calendar-line"></i>' + vhEsc(group.label)
             +  '<span class="vh-group-count">' + group.rows.length + '</span></div>';

        group.rows.forEach(function(row) {
            var m = vhGetMeta(row.details);
            var p = vhParse(row.details);
            var isFirst = (index === 0 && _vhFilter === 'all' && !_vhSearch);
            var isLast = (index === data.length - 1);
            var initials = (row.name || '?').trim().split(' ').map(function(w){ return w[0] || ''; }).join('').substring(0,2).toUpperCase();
            var ts = new Date(row.created_at);
            var timeStr = ts.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
            var absDate = ts.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});

            html += '<div class="vh-entry' + (isFirst ? ' is-latest' : '') + '">';

            // Timeline node doubles as the event icon
            html += '<div class="vh-timeline">';
            html += '<div class="vh-node" style="background:' + m.bg + ';color:' + m.color + ';"><i class="' + m.icon + '"></i></div>';
            if (!isLast) html += '<div class="vh-line"></div>';
            html += '</div>';

            html += '<div class="vh-content">';

            html += '<div class="vh-event-row"><div class="vh-event-text">' + vhEsc(p.title);
            if (p.sub) html += '<div class="vh-event-sub">' + vhEsc(p.sub) + '</div>';
            html += '</div>';
            if (isFirst) html += '<span class="vh-latest-badge">LATEST</span>';
            html += '</div>';

            if (p.employee || p.value !== null) {
                html += '<div class="vh-chips">';
                if (p.employee) html += '<span class="vh-chip vh-chip-emp" title="' + vhEsc(p.employee) + '"><i class="ri-user-3-line"></i>' + vhEsc(p.employee) + '</span>';
                if (p.value !== null) html += '<span class="vh-chip vh-chip-val"><i class="ri-arrow-right-line"></i>' + vhEsc(p.value) + '</span>';
                html += '</div>';
            }

            html += '<div class="vh-meta">';
            html += '<div class="vh-avatar" style="background:' + m.color + '22;border:1px solid ' + m.color + '44;color:' + m.color + ';">' + vhEsc(initials) + '</div>';
            html += '<span class="vh-user" title="' + vhEsc(row.name || '') + '">' + vhEsc(row.name || '—') + '</span>';
            html += '<div class="vh-time-block" title="' + vhEsc(absDate + ' ' + timeStr) + '">';
            html += '<div class="vh-rel-time">' + vhEsc(vhRelTime(row.created_at)) + '</div>';
            html += '<div class="vh-abs-time">' + vhEsc(absDate + ' · ' + timeStr) + '</div>';
            html += '</div></div>';

            html += '</div></div>';
            index++;
        });
    });

    body.innerHTML = html;
}

function openPayrollHistory(id) {
    _vhFilter = 'all';
    _vhSearch = '';
    document.querySelectorAll('.vh-tab').forEach(function(t){ t.classList.remove('active'); });
    var allTab = document.querySelector('.vh-tab[data-filter="all"]');
    if (allTab) allTab.classList.add('active');
    var searchEl = document.getElementById('vh-search');
    if (searchEl) searchEl.value = '';
    var clearEl = document.getElementById('vh-search-clear');
    if (clearEl) clearEl.style.display = 'none';

    document.getElementById('vh-count').textContent = '—';
    document.getElementById('vh-subtitle').textContent = 'Loading change log…';

    var body = document.getElementById('offcanvas-history-body');
    body.innerHTML = [0,1,2,3,4].map(function() {
        return '<div class="vh-skeleton"><div class="vh-sk-node"></div><div class="vh-sk-lines">'
             + '<div class="vh-sk-bar" style="width:75%"></div>'
             + '<div class="vh-sk-bar" style="width:45%"></div>'
             + '<div class="vh-sk-bar" style="width:60%;height:7px"></div></div></div>';
    }).join('');
    new bootstrap.Offcanvas(document.getElementById('offcanvas-history')).show();

    $.ajax({
        url: 'ajax.php?action=payroll_history_details',
        method: 'POST',
        dataType: 'JSON',
        data: { id: id },
        success: function(res) {
            _vhData = (res && res.length) ? res : [];
            var sub = document.getElementById('vh-subtitle');
            sub.textContent = _vhData.length
                ? 'Last activity ' + vhRelTime(_vhData[0].created_at).toLowerCase() + ' by ' + (_vhData[0].name || 'unknown')
                : 'Payroll change log';
            vhRender();
        },
        error: function() {
            document.getElementById('vh-count').textContent = '—';
            document.getElementById('vh-subtitle').textContent = 'Payroll change log';
            body.innerHTML = '<div class="vh-empty"><i class="ri-error-warning-line" style="color:#dc2626;"></i>'
                + '<div class="vh-empty-title" style="color:#dc2626;">Failed to load</div>'
                + '<div class="mb-2">Something went wrong fetching the history.</div>'
                + '<button class="btn btn-sm" style="background:#219688;color:#fff;font-weight:600;border:none;" onclick="openPayrollHistory(' + Number(id) + ')">'
                + '<i class="ri-refresh-line me-1"></i>Retry</button></div>';
        }
    });
}
</script>

<!-- PDF Preview Modal (dompdf output shown inline; download from here) -->
<div class="modal fade" id="modal-pdf-preview" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:1400px;width:95%;">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#107c41;border-bottom:1px solid #e6efe8;">
                <h5 class="modal-title mb-0" style="color:#107c41;"><i class="ri-file-pdf-2-line me-1"></i><span id="pdf-preview-title">PDF Preview</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background:#525659;padding:0;overflow:hidden;">
                <iframe id="pdf-preview-frame" title="PDF preview" style="width:100%;height:80vh;border:0;display:block;background:#525659;"></iframe>
            </div>
            <div class="modal-footer" style="background:#fff;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <a id="pdf-preview-download" href="#" class="btn btn-sm" style="background:#107c41;color:#fff;font-weight:600;border:none;">
                    <i class="ri-download-2-line me-1"></i>Download PDF
                </a>
            </div>
        </div>
    </div>
</div>
<script>
// All payroll prints render as PDF via dompdf and preview here — no browser print pop-ups.
function openPdfPreview(url, title) {
    var frame = document.getElementById('pdf-preview-frame');
    if (!frame) return;
    document.getElementById('pdf-preview-title').textContent = title || 'PDF Preview';
    document.getElementById('pdf-preview-download').href = url + '&download=1';
    frame.src = url;
    new bootstrap.Modal(document.getElementById('modal-pdf-preview')).show();
}
</script>

<!-- Review Mark Modal — color-code a payroll row + reviewer comment -->
<div class="modal fade" id="modal-review-mark" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid #e6efe8;">
                <h5 class="modal-title mb-0" style="color:#107c41;font-size:15px;">
                    <i class="ri-checkbox-multiple-line me-1"></i>Review Mark — <span id="rv-emp-name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <button type="button" class="rv-choice rv-c1" data-rv="1" onclick="pickReviewChoice(this)">
                    <span class="rv-dot rv-1"></span>
                    <span>Okay <small>Figures verified — inputs lock to display only</small></span>
                </button>
                <button type="button" class="rv-choice rv-c2" data-rv="2" onclick="pickReviewChoice(this)">
                    <span class="rv-dot rv-2"></span>
                    <span>Something wrong <small>Needs correction — say what in the comment</small></span>
                </button>
                <button type="button" class="rv-choice rv-c3" data-rv="3" onclick="pickReviewChoice(this)">
                    <span class="rv-dot rv-3"></span>
                    <span>Ongoing review <small>Still being checked</small></span>
                </button>
                <button type="button" class="rv-choice rv-c0" data-rv="0" onclick="pickReviewChoice(this)">
                    <span class="rv-dot rv-0"></span>
                    <span>No mark <small>Clear the color and comment</small></span>
                </button>
                <label class="form-label small text-muted mt-2 mb-1" for="rv-comment">Comment</label>
                <textarea id="rv-comment" class="form-control" rows="2" maxlength="500" placeholder="e.g. OT hours look too high — verify with site logs"></textarea>
            </div>
            <div class="modal-footer" style="border-top:1px solid #e6efe8;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm" style="background:#107c41;color:#fff;font-weight:600;border:none;" onclick="saveReviewMark()">
                    <i class="ri-save-line me-1"></i>Save Mark
                </button>
            </div>
        </div>
    </div>
</div>
<script>
// ── Reviewer color marks: green = okay (locks row inputs), orange = issue, blue = reviewing ──
var rvCurrentItem = null;
var rvClassMap = { 1: 'review-ok', 2: 'review-issue', 3: 'review-checking' };

function openReviewMark(itemId) {
    var tr = document.querySelector('tr[data-row-id="' + itemId + '"]');
    if (!tr) return;
    rvCurrentItem = itemId;
    var nameEl = tr.querySelector('.emp-name-link b');
    document.getElementById('rv-emp-name').textContent = nameEl ? nameEl.textContent : '#' + itemId;
    document.getElementById('rv-comment').value = tr.getAttribute('data-review-comment') || '';
    var current = tr.getAttribute('data-review') || '0';
    document.querySelectorAll('#modal-review-mark .rv-choice').forEach(function (b) {
        b.classList.toggle('selected', b.getAttribute('data-rv') === current);
    });
    new bootstrap.Modal(document.getElementById('modal-review-mark')).show();
}

function pickReviewChoice(btn) {
    document.querySelectorAll('#modal-review-mark .rv-choice').forEach(function (b) { b.classList.remove('selected'); });
    btn.classList.add('selected');
}

function saveReviewMark() {
    var picked = document.querySelector('#modal-review-mark .rv-choice.selected');
    if (!picked || rvCurrentItem === null) return;
    var status = parseInt(picked.getAttribute('data-rv'), 10);
    var comment = status === 0 ? '' : document.getElementById('rv-comment').value.trim();
    $.ajax({
        url: 'ajax.php?action=set_payroll_item_review',
        method: 'POST',
        data: { id: rvCurrentItem, review_status: status, review_comment: comment },
        success: function () {
            applyReviewToRow(rvCurrentItem, status, comment);
            bootstrap.Modal.getInstance(document.getElementById('modal-review-mark')).hide();
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Review mark saved', showConfirmButton: false, timer: 1800 });
        },
        error: function () {
            Swal.fire({ icon: 'error', title: 'Oops...', text: 'Could not save the review mark.' });
        }
    });
}

function applyReviewToRow(itemId, status, comment) {
    var tr = document.querySelector('tr[data-row-id="' + itemId + '"]');
    if (!tr) return;
    tr.classList.remove('review-ok', 'review-issue', 'review-checking');
    if (rvClassMap[status]) tr.classList.add(rvClassMap[status]);
    tr.setAttribute('data-review', status);
    tr.setAttribute('data-review-comment', comment);
    var btn = tr.querySelector('.review-mark-btn');
    if (btn) {
        var dot = btn.querySelector('.rv-dot');
        dot.className = 'rv-dot rv-' + status;
        btn.setAttribute('title', comment || (status === 0 ? 'Mark review status' : 'Marked'));
        btn.setAttribute('data-bs-original-title', comment || (status === 0 ? 'Mark review status' : 'Marked'));
        var flag = btn.querySelector('.rv-comment-flag');
        if (comment && !flag) {
            btn.insertAdjacentHTML('beforeend', '<i class="ri-chat-3-fill rv-comment-flag"></i>');
        } else if (!comment && flag) {
            flag.remove();
        }
    }
    setReviewInputLock(tr, status === 1);
}

// Green = verified: freeze the row's inputs so figures can't be changed by accident.
function setReviewInputLock(tr, locked) {
    tr.querySelectorAll('.input-class').forEach(function (inp) { inp.readOnly = locked; });
}

// Apply the input lock to rows already marked green on page load.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tr.review-ok').forEach(function (tr) { setReviewInputLock(tr, true); });
});
</script>

<!-- Bulk Payslip Preview Modal (multiple selected payslips — HTML preview) -->
<div class="modal fade" id="modal-payslip-preview" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:#fff;color:#107c41;border-bottom:1px solid #e6efe8;">
                <h5 class="modal-title mb-0" style="color:#107c41;"><i class="ri-file-text-line me-1"></i>Payslip Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background:#e8e8e8;padding:0;overflow:hidden;">
                <iframe id="payslip-preview-frame" title="Payslip preview" style="width:100%;height:72vh;border:0;display:block;background:#e8e8e8;"></iframe>
            </div>
            <div class="modal-footer" style="background:#fff;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm" style="background:#107c41;color:#fff;font-weight:600;border:none;" onclick="printPayslipPreview()">
                    <i class="ri-printer-line me-1"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>
<script>
// Single payslip → dompdf PDF in the shared PDF modal, same flow as the other
// payroll prints (preview inline, download from the modal).
function openPayslipPreview(itemId) {
    openPdfPreview('pdf-payroll.php?src=payslip&id=' + encodeURIComponent(itemId), 'Payslip PDF');
}
// Bulk selected payslips still preview as HTML in the modal above.
function printPayslipPreview() {
    var frame = document.getElementById('payslip-preview-frame');
    if (frame && frame.contentWindow) {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }
}
</script>

<div class="modal" id="modal-sites-2" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-sitesModalLabel">Sites</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table id="data-table" class="table table-hover dataTable table-custom table-striped m-b-0 c_list">
                    <thead class="thead-dark">
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Timekeeper</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (is_array($site_ids)) {
                            foreach ($site_ids as $k) {  ?>

                                <?php
                                $query_site = "SELECT A.*,  C.name AS timekeeper 
                                        FROM sites AS A 
                                        LEFT JOIN users AS C ON A.timekeeper_id = C.id 
                           
                                        WHERE A.id = ?
                                    ";
                                $stmt_site = $conn->prepare($query_site);
                                $stmt_site->bind_param("i", $k);
                                $stmt_site->execute();
                                $result_site = $stmt_site->get_result();
                                $site_details = $result_site->fetch_assoc();
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($site_details['site_code']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['site_name']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['site_address']); ?></td>
                                    <td><?php echo htmlspecialchars($site_details['timekeeper']); ?></td>
                                    <td class="text-center" width="100">
                                        <button data-toggle="tooltip" title="Payroll PDF for this Site" onclick="openPdfPreview('pdf-payroll.php?src=payroll&id=<?= $id ?>&type=site&site_id=<?= $site_details['id'] ?>', 'Site Payroll PDF — <?= htmlspecialchars($site_details['site_code'], ENT_QUOTES) ?>')" class="btn btn-sm btn-outline-info mr-1"><span class="sr-only">Print</span> <i class="fa fa-print"></i></button>
                                    </td>
                                </tr>
                        <?php }
                        } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
    let id = "<?= $id ?>";
    let status = "<?= $status ?>";

    function fitTableToViewport() {
        const container = document.getElementById('table-responsive2');
        if (!container) return;
        const top = container.getBoundingClientRect().top + window.scrollY;
        const scrollTop = window.scrollY || document.documentElement.scrollTop;
        const visibleTop = top - scrollTop;
        const available = window.innerHeight - visibleTop - 24;
        container.style.height = Math.max(available, 200) + 'px';
    }

    function fixStickyHeaderGap() {
        const thead = document.querySelector('#table-1 thead');
        if (!thead) return;
        const row1 = thead.querySelector('tr:first-child');
        if (!row1) return;
        const row1Height = row1.getBoundingClientRect().height;
        thead.querySelectorAll('tr:nth-child(2) th').forEach(th => {
            th.style.top = row1Height + 'px';
        });
    }

    function fixFrozenColumns() {
        const table = document.getElementById('table-1');
        if (!table) return;
        // Measure the rendered width of the first body/header cells
        const col1Ref = table.querySelector('tbody tr td:nth-child(1)')
                     || table.querySelector('thead tr:first-child th:nth-child(1)');
        const col2Ref = table.querySelector('tbody tr td:nth-child(2)')
                     || table.querySelector('thead tr:first-child th:nth-child(2)');
        if (!col1Ref) return;
        const col1W = col1Ref.getBoundingClientRect().width;
        // Col 2 (No.) sits right after the checkbox column
        // (tfoot's checkbox+No.+Name cells are merged into one .tfoot-total-cell
        // at left:0 via CSS, so tfoot is intentionally excluded here — its
        // child(2)/(3) indices now point at unrelated cells after the merge.)
        table.querySelectorAll(
            'tbody td:nth-child(2), thead tr:first-child th:nth-child(2)'
        ).forEach(el => { el.style.left = col1W + 'px'; });
        // Col 3 (Name) sits right after checkbox + No. columns
        if (col2Ref) {
            const leftFor3 = col1W + col2Ref.getBoundingClientRect().width;
            table.querySelectorAll(
                'tbody td:nth-child(3), thead tr:first-child th:nth-child(3)'
            ).forEach(el => { el.style.left = leftFor3 + 'px'; });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        fitTableToViewport();
        fixStickyHeaderGap();
        fixFrozenColumns();

        // Pull gross + deduction totals from tfoot into summary strip
        var gross  = document.getElementById('tfoot-gross');
        var deduct = document.getElementById('tfoot-deduct');
        if (gross)  document.getElementById('stat-gross').textContent  = '₱ ' + gross.textContent.trim();
        if (deduct) document.getElementById('stat-deduct').textContent = '₱ ' + deduct.textContent.trim();

        // Approved Attendance Logs — modal. Content is looked up lazily from the
        // single hidden #dtr-pop-src copy (one per employee+site) instead of being
        // duplicated into every row in both tables.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.dtr-view-days');
            if (!btn) return;
            var src = document.getElementById('dtr-pop-' + btn.getAttribute('data-pop-key'));
            document.getElementById('al-body').innerHTML = src ? src.innerHTML
                : '<div class="text-center text-muted py-4">No approved attendance found</div>';
            document.getElementById('al-employee').textContent = btn.getAttribute('data-emp-name') || '';
            var days = parseInt(btn.getAttribute('data-days') || '0', 10);
            document.getElementById('al-days-count').textContent = days
                ? days + ' approved day' + (days === 1 ? '' : 's') : '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-att-logs')).show();
        });

        // ── Search / department / anomaly-flag filtering ──
        var payFilter = { q: '', dept: '', chip: '' };
        var payAnom = {};
        var CHIP_DEFS = [
            { key: 'negative', cls: 'negative', icon: 'ri-error-warning-line',  label: 'negative net' },
            { key: 'zero',     cls: 'zero',     icon: 'ri-forbid-line',         label: 'zero net' },
            { key: 'noatt',    cls: 'noatt',    icon: 'ri-calendar-close-line', label: 'paid, no attendance' },
            { key: 'bigmove',  cls: 'bigmove',  icon: 'ri-line-chart-line',     label: 'net moved ≥30%' },
        ];

        function payRows() {
            return Array.prototype.slice.call(document.querySelectorAll('#table-1 tbody tr[data-row-id]'));
        }

        function payClassifyRows() {
            payAnom = { zero: [], negative: [], noatt: [], bigmove: [] };
            payRows().forEach(function (tr) {
                var netEl = tr.querySelector('[data-computed="net"]');
                var net = netEl ? parseFloat(netEl.textContent.replace(/,/g, '')) : NaN;
                var days = parseInt(tr.querySelector('.dtr-view-days')?.getAttribute('data-days') || '0', 10);
                if (!isNaN(net)) {
                    if (net === 0) payAnom.zero.push(tr);
                    if (net < 0) payAnom.negative.push(tr);
                    if (net > 0 && days === 0) payAnom.noatt.push(tr);
                }
                if (tr.querySelector('.nd-warn')) payAnom.bigmove.push(tr);
            });
        }

        function payRenderChips() {
            var wrap = document.getElementById('pay-anomaly-chips');
            if (!wrap) return;
            var html = '';
            CHIP_DEFS.forEach(function (c) {
                var n = payAnom[c.key].length;
                if (!n) return;
                html += '<span class="pay-chip ' + c.cls + (payFilter.chip === c.key ? ' active' : '') + '" data-chip="' + c.key + '" title="Click to show only these rows">'
                      + '<i class="' + c.icon + '"></i>' + n + ' ' + c.label + '</span>';
            });
            if (!html) html = '<span class="pay-chip all-clear"><i class="ri-checkbox-circle-line"></i>no anomalies</span>';
            wrap.innerHTML = html;
        }

        function payApplyFilter() {
            var shown = 0, total = 0;
            payRows().forEach(function (tr) {
                total++;
                var okQ = !payFilter.q || (tr.getAttribute('data-name') || '').indexOf(payFilter.q) !== -1;
                var okD = !payFilter.dept || tr.getAttribute('data-dept') === payFilter.dept;
                var okC = !payFilter.chip || payAnom[payFilter.chip].indexOf(tr) !== -1;
                var show = okQ && okD && okC;
                tr.style.display = show ? '' : 'none';
                tr.classList.toggle('pay-row-hit', show && !!payFilter.chip);
                if (show) shown++;
            });
            var counter = document.getElementById('pay-filter-count');
            if (counter) counter.textContent = (payFilter.q || payFilter.dept || payFilter.chip)
                ? shown + ' of ' + total + ' employees' : '';
            var clearBtn = document.getElementById('pay-search-clear');
            if (clearBtn) clearBtn.style.display = payFilter.q ? '' : 'none';
        }

        var paySearchEl = document.getElementById('pay-search');
        if (paySearchEl) {
            paySearchEl.addEventListener('input', function () {
                payFilter.q = this.value.trim().toLowerCase();
                payApplyFilter();
            });
            document.getElementById('pay-search-clear').addEventListener('click', function () {
                paySearchEl.value = '';
                payFilter.q = '';
                payApplyFilter();
                paySearchEl.focus();
            });
            document.getElementById('pay-dept-filter').addEventListener('change', function () {
                payFilter.dept = this.value;
                payApplyFilter();
            });
            document.getElementById('pay-anomaly-chips').addEventListener('click', function (e) {
                var chip = e.target.closest('.pay-chip[data-chip]');
                if (!chip) return;
                var key = chip.getAttribute('data-chip');
                payFilter.chip = (payFilter.chip === key) ? '' : key;
                payRenderChips();
                payApplyFilter();
            });
            payClassifyRows();
            payRenderChips();
        }
    });
    window.addEventListener('resize', () => {
        fitTableToViewport();
        fixStickyHeaderGap();
        fixFrozenColumns();
    });
</script>
<?php include 'component/add_payroll.php'; ?>