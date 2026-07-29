<?php
/**
 * Payroll Details — standalone full-page document workbench for one payroll
 * (layout mirrors dtr-documents.php: no sidebar, no app header).
 *
 *   left    employee list (search + filters, payslip selection)
 *   center  the selected employee's categorized pay computation sheet
 *   right   employee summary + review progress + batch totals
 *
 * The classic Excel-style editing table now lives in the full-screen
 * #modal-table-editor ("Edit Sheet"); all editing/saving still runs through
 * assets2/js/payroll_calculations.js and the same ajax.php actions.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if ((empty($_SESSION['is_login'])) && !empty($_SESSION['emp_is_login'])) {
    header('Location: employee-portal.php');
    exit;
}
if (empty($_SESSION['is_login'])) {
    header('Location: login.php');
    exit;
}
if (!isset($conn)) include 'db_connect.php';

if (!isset($_GET['id'])) {
    header('Location: index.php?page=payroll');
    exit;
}
$id = (int) $_GET['id'];


// Site filter removed — a payroll always covers all active sites now.
$filter_query = "";
$sid = '';

$query = "SELECT  category,  clusters.cluster FROM payroll
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
// seen_at ships in 2026_07_review_seen.sql — older databases fall back to
// "everything read" instead of a fatal unknown-column error.
$pcwHasSeen = (bool)($conn->query("SHOW COLUMNS FROM payroll_employee_reviews LIKE 'seen_at'")->num_rows ?? 0);
$prvq = $conn->query("SELECT r.id, r.employee_id, r.status, r.comment, r.reviewed_at,
        r.resolved_at, r.admin_reply, " . ($pcwHasSeen ? "r.seen_at" : "NULL AS seen_at") . ",
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

// Rows an admin reopened for correction while the batch is out for review.
// Drives the Save button (hidden at status 3 otherwise) and the header banner.
// Guarded: 2026_07_payroll_item_unlock.sql may not have run yet.
$pcwHasUnlockCol = (bool)($conn->query("SHOW COLUMNS FROM payroll_items LIKE 'unlocked_at'")->num_rows ?? 0);
$pcwUnlockedCount = $pcwHasUnlockCol
    ? (int)($conn->query("SELECT COUNT(*) AS c FROM payroll_items
            WHERE payroll_id = $id AND unlocked_at IS NOT NULL")->fetch_assoc()['c'] ?? 0)
    : 0;
// The Save button and the unlocked-count flag are rendered for any batch that
// could become editable (Open, or in review where a row can be unlocked) and
// simply stay hidden until they apply — so unlocking never needs a page reload
// to bring them into existence.
$pcwCanSave = in_array((int)$status, [1, 3], true);

// The sign-off conversation, keyed by employee: what the employee wrote when
// they confirmed or disputed, and HR's reply if one was sent. The panel and
// the left list show only a name + chat icon; this feeds the popup behind it.
$pcwReviewConvo = [];
$pcwUnreadMsgs = 0;
foreach ($payrollReviewRows as $eid => $prv) {
    $hasMsg = trim((string)($prv['comment'] ?? '')) !== '';
    $unread = $hasMsg && empty($prv['seen_at']);
    if ($unread) $pcwUnreadMsgs++;
    $pcwReviewConvo[(int)$eid] = [
        'id'     => (int)$prv['id'],
        'st'     => (int)$prv['status'],
        'name'   => $prv['name'],
        'c'      => (string)($prv['comment'] ?? ''),
        'at'     => !empty($prv['reviewed_at']) ? date('M j, Y g:i A', strtotime($prv['reviewed_at'])) : '',
        'rep'    => (string)($prv['admin_reply'] ?? ''),
        'rep_at' => !empty($prv['resolved_at']) ? date('M j, Y g:i A', strtotime($prv['resolved_at'])) : '',
        'new'    => $unread ? 1 : 0,
    ];
}

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

// Internal admin notes written on the DTR batches that overlap this payroll —
// listed read-only in the workbench's Employee Summary (same content and style
// as dtr-documents.php). Guarded for older databases without the table.
$pcwNotesByEmp = [];
if ($commaSeparatedSites !== '') {
    $nq = @$conn->query("SELECT n.employee_id, n.level, n.note, n.created_at, u.name AS author
        FROM dtr_admin_notes n
        INNER JOIN DTR d ON d.id = n.ddtr_id
        LEFT JOIN users u ON u.id = n.created_by
        WHERE d.site_id IN ($commaSeparatedSites)
          AND d.date_from <= '$dt_esc' AND d.date_to >= '$df_esc'
        ORDER BY n.id ASC");
    if ($nq) while ($nr = $nq->fetch_assoc()) {
        $pcwNotesByEmp[(int)$nr['employee_id']][] = [
            'level' => $nr['level'],
            'note'  => $nr['note'],
            'by'    => $nr['author'] ?? '',
            'at'    => date('M j, g:i A', strtotime($nr['created_at'])),
        ];
    }
}

// Admin ↔ employee record messages (dtr_messages) for this payroll's employees
// within the period — shown read-only in the DTR Details modal, grouped per
// employee with the record's date. Guarded for older databases.
$pcwMsgsByEmp = [];
if ($commaSeparatedSites !== '') {
    $mq = @$conn->query("SELECT dd.employee_id, DATE(dd.date_time) AS rec_date,
            m.message, m.created_at, m.sender_type, u.name AS sender
        FROM dtr_messages m
        INNER JOIN DTR_details dd ON dd.id = m.dtr_detail_id
        INNER JOIN DTR d ON d.id = dd.ddtr_id
        LEFT JOIN users u ON u.id = m.sent_by
        WHERE d.site_id IN ($commaSeparatedSites)
          AND dd.date_time BETWEEN '$df_esc' AND '$dt_esc'
        ORDER BY m.id ASC");
    if ($mq) while ($mr = $mq->fetch_assoc()) {
        $pcwMsgsByEmp[(int)$mr['employee_id']][] = [
            'from' => ($mr['sender_type'] === 'employee') ? 'emp' : 'admin',
            'msg'  => $mr['message'],
            'by'   => $mr['sender'] ?? '',
            'at'   => date('M j, g:i A', strtotime($mr['created_at'])),
            'date' => date('M j', strtotime($mr['rec_date'])),
        ];
    }
}

/**
 * May this employee's row be edited right now?
 *
 * An Open payroll (status 1) is editable throughout. Once it is out for employee
 * review (status 3) the batch freezes, EXCEPT for individual rows an admin has
 * deliberately unlocked to correct a disputed figure — that way fixing three
 * disputes does not void the sign-offs of everyone who already confirmed.
 *
 * Mirrors payroll_item_write_block() in admin_class.php, which enforces the same
 * rule server-side; this one only decides whether to render an <input>.
 */
function pcw_row_editable($status, $row) {
    if ((int)$status === 1) return true;
    return (int)$status === 3 && !empty($row['unlocked_at']);
}

// Builds the Form 48 payload (days map + totals) for one employee+site from
// the approved DTR_details rows, mirroring dtr-employee-server.php's cells so
// window.DTRForm48.render() draws the same Daily Time Record sheet.
function pcw_dtr_sheet($days) {
    $map = [];
    $tot = ['wh' => 0, 'ot' => 0, 'ut' => 0, 'late' => 0];
    foreach ($days as $d) {
        $iso = date('Y-m-d', strtotime($d['date_time']));
        $logs = json_decode($d['logs'], true) ?: [];
        $punches = [];
        foreach ($logs as $lg) {
            $punches[] = ['ts' => strtotime($lg['dateTime']), 'bio' => (($lg['type'] ?? '') === 'bio')];
        }
        usort($punches, function ($a, $b) { return $a['ts'] <=> $b['ts']; });
        $times = array_column($punches, 'ts');
        $n = count($times);
        $cell = ['in' => '', 'out' => '', 'am_in' => '', 'am_out' => '', 'pm_in' => '', 'pm_out' => ''];
        if ($n >= 1) {
            $cell['in'] = date('g:i', $times[0]);
            if ((int)date('G', $times[0]) < 12) $cell['am_in'] = $cell['in']; else $cell['pm_in'] = $cell['in'];
        }
        if ($n >= 2) {
            $cell['out'] = date('g:i', $times[$n - 1]);
            if ((int)date('G', $times[$n - 1]) < 12) $cell['am_out'] = $cell['out']; else $cell['pm_out'] = $cell['out'];
        }
        $cell['wh']   = (float)$d['work_hours'];
        $cell['ot']   = (float)$d['overtime'];
        $cell['ut']   = (float)$d['undertime'];
        $cell['late'] = (float)$d['late'];
        // Raw punch list for the modal's Attendance Logs section (Form 48
        // render ignores this extra key).
        $cell['logs'] = array_map(function ($p) {
            return ['t' => date('g:i A', $p['ts']), 'bio' => $p['bio']];
        }, $punches);
        $map[$iso] = $cell;
        $tot['wh'] += $cell['wh'];
        $tot['ot'] += $cell['ot'];
        $tot['ut'] += $cell['ut'];
        $tot['late'] += $cell['late'];
    }
    return ['days' => $map ?: new stdClass(), 'totals' => $tot];
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

// ── Per-employee one-off items (payroll_item_extras) ────────────────────
// Named ad-hoc lines attached to a single payslip: kind 1 deducts, 2 adds.
// Keyed by payroll_items.id. Guarded so a database without the migration
// simply has none.
$pcwExtras = [];
$pcwHasExtras = (bool)($conn->query("SHOW TABLES LIKE 'payroll_item_extras'")->num_rows ?? 0);
if ($pcwHasExtras) {
    $exq = $conn->query("SELECT id, payroll_item_id, kind, label, amount
                         FROM payroll_item_extras WHERE payroll_id = $id ORDER BY id ASC");
    if ($exq) while ($ex = $exq->fetch_assoc()) {
        $pcwExtras[(int)$ex['payroll_item_id']][] = [
            'id' => (int)$ex['id'], 'kind' => (int)$ex['kind'],
            'label' => $ex['label'], 'amt' => (float)$ex['amount'],
        ];
    }
}
/** Sum one item's extras: ['add' => allowances, 'less' => deductions]. */
function pcw_extra_totals($extras) {
    $t = ['add' => 0.0, 'less' => 0.0];
    foreach ($extras ?: [] as $x) {
        if ((int)$x['kind'] === 2) $t['add'] += (float)$x['amt'];
        else                       $t['less'] += (float)$x['amt'];
    }
    return $t;
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

// Positions present in this payroll — feeds the position filters (table + card view).
$pay_positions = [];
$pos_q = $conn->query("SELECT DISTINCT p.name FROM payroll_items a
    INNER JOIN employee e ON a.employee_id = e.id
    INNER JOIN position p ON e.position_id = p.id
    WHERE a.payroll_id = $id ORDER BY p.name ASC");
if ($pos_q) while ($pq = $pos_q->fetch_assoc()) if (trim($pq['name']) !== '') $pay_positions[] = $pq['name'];

// Rate types present in this payroll — feeds the rate-type filters (table + card view).
// Read off payroll_items (not employee) so the list matches what was actually paid,
// even if the employee's rate type has changed since the run was calculated.
$pay_rate_types = [];
$rt_q = $conn->query("SELECT DISTINCT rate_type FROM payroll_items
    WHERE payroll_id = $id AND rate_type <> '' ORDER BY rate_type ASC");
if ($rt_q) while ($rq2 = $rt_q->fetch_assoc()) $pay_rate_types[] = $rq2['rate_type'];

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

// ── Document workbench data ─────────────────────────────────────────────
// The table loops below also fill $pcwEmployees (one entry per row) so the
// left employee list + center computation sheet render without extra queries.
$pcwEmployees = [];
$ded_names    = [];   // "type-id" => display name, captured while the thead renders
$refund_names = [];   // refund id => display name

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payroll Details &mdash; <?= date('M d', strtotime($payroll['date_from'])) ?>&ndash;<?= date('M d, Y', strtotime($payroll['date_to'])) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets2/css/my-style.css">
    <!-- Global DTR (Form 48) template — same sheet dtr-documents.php renders -->
    <link href="assets2/css/dtr-form48.css" rel="stylesheet">
    <!-- defer keeps these off the critical render path so the loading overlay
         paints immediately instead of the page hanging blank on the CDN fetches.
         None of the inline scripts use jQuery at parse time, so order is safe. -->
    <script defer src="assets2/js/dtr-form48.js"></script>
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
    <script defer src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
.pay-stat.employees .pay-stat-icon { background:#eeeaf5; color:#6642aa; }
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
    background: #e1dcec !important;           /* mint — general/attendance group */
    color: #4f3288 !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: .4px !important;
    border: 1px solid #cfc8de !important;
    padding: 7px 8px !important;
}
#table-1 thead tr:first-child th.info-header    { background: #dde9f8 !important; color: #1e50a0 !important; border-color: #c5d8f0 !important; }
#table-1 thead tr:first-child th.success-header { background: #d9eedd !important; color: #107c41 !important; border-color: #c0e0c8 !important; }
#table-1 thead tr:first-child th.danger-header  { background: #fbe3e6 !important; color: #b02a37 !important; border-color: #f3cdd2 !important; }

/* Header row 2: column labels (lighter tint of the same family) */
#table-1 thead tr:nth-child(2) th {
    background: #f0edf5 !important;
    color: #4f3288 !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    border: 1px solid #ddd9e7 !important;
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

/* Frozen col 1 (No.) — light gray-green like Excel's row headers.
   The checkbox column was removed, so No. is col 1 and Name is col 2. */
#table-1 tbody td:nth-child(1) {
    background: #f1f6f2 !important;
    border-right: 1px solid #dbe6dd !important;
    font-weight: 600 !important;
    color: #444 !important;
    box-shadow: none !important;
}
#table-1 tbody tr:hover td:nth-child(1) { background: #ddeee3 !important; }

/* ── Frozen Name column (col 2) — left offset set by JS ── */
#table-1 tbody td:nth-child(2),
#table-1 thead tr:first-child th:nth-child(2) {
    position: sticky !important;
    z-index: 12;
    border-right: 2px solid #b8d8c2 !important;
    box-shadow: 3px 0 6px -3px rgba(0,0,0,.08);
    transform: translateZ(0);
    text-align: left !important;
}
#table-1 tbody td:nth-child(2) { background: #f5faf6 !important; }
#table-1 thead tr:first-child th:nth-child(2) { z-index: 14 !important; }
#table-1 tbody tr:nth-child(even) td:nth-child(2) { background: #eff6f0 !important; }
#table-1 tbody tr:hover td:nth-child(2) { background: #eaf6ef !important; }

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

/* Merged TOTAL label — spans the No. + Name frozen columns (colspan="2" on the
   tfoot row; nth-child no longer applies to it, so it gets its own
   class-based sticky rule instead). */
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

/* Payslip selection lives in the workbench's employee list (card view) — the
   table preview is read-only and has no checkbox column. */

/* Approved Attendance Logs modal (mirrors attendance.php's Time Log Details modal) */
.att-log-dir { font-size:10px; font-weight:700; padding:1px 0; border-radius:3px; min-width:34px; text-align:center; display:inline-block; }
.att-log-in  { background:#d4edda; color:#155724; }
.att-log-out { background:#f8d7da; color:#721c24; }
.att-log-bio    { background:#673bb6; color:#fff; font-size:10px; }
.att-log-manual { background:#dc3545; color:#fff; font-size:10px; }
#modal-att-logs .al-meta-label { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.3px; }
#modal-att-logs .al-meta-value { font-size:13px; font-weight:700; color:#673bb6; }
.al-day { margin-bottom:14px; }
.al-day:last-child { margin-bottom:0; }
.al-day-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.al-day-date { font-size:12px; font-weight:700; color:#323130; }
.al-day-hrs { font-size:11px; font-weight:700; color:#107c41; background:#eafaf0; border:1px solid #b7e4c7; padding:1px 8px; border-radius:10px; }
.al-table th, .al-table td { font-size:12px; }

/* ── Search / dept filter / anomaly-flags toolbar ── */
.pay-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.pay-search-box { display:flex; align-items:center; gap:6px; background:#fff; border:1px solid #d9d3e4; border-radius:6px; padding:5px 10px; min-width:240px; }
.pay-search-box i { color:#6642aa; font-size:14px; }
.pay-search-box input { border:none; outline:none; font-size:12px; flex:1; background:transparent; min-width:160px; }
.pay-search-box button { border:none; background:none; color:#999; cursor:pointer; padding:0 2px; font-size:14px; line-height:1; }
#pay-dept-filter,
#pay-pos-filter,
#pay-rate-filter { border:1px solid #d9d3e4; border-radius:6px; background:#fff; font-size:12px; font-weight:600; color:#0e6b37; padding:5px 8px; cursor:pointer; max-width:230px; }
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
.prp-chip.unread { background:#eef2fd; color:#3557b7; border:1px solid #ccd9f7; }
.prp-disputes { margin-top:8px; display:flex; flex-direction:column; gap:3px; }
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
/* ── Name-only sign-off rows ───────────────────────────────────────────────
   Both lists (disputed + confirmed) are one line per employee: icon, name,
   and a chat button when they left a message. The message itself opens in
   #modal-emp-review, so a long comment can never stretch this panel. */
.prp-note-pill { display:inline-flex; align-items:center; gap:3px; background:#fff6e2; border:1px solid #f2dfae;
    color:#a9700a; border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.prp-confirms { margin-top:6px; display:flex; flex-direction:column; gap:3px; }
.prp-confirms.is-scroll { max-height:190px; overflow-y:auto; padding-right:4px; }
.prp-row { display:flex; align-items:center; gap:6px; min-width:0; padding:4px 8px; border-radius:7px;
    border:1px solid transparent; font-size:11.5px; }
.prp-row.ok   { background:#f2faf4; border-color:#dceee2; }
.prp-row.disp { background:#fff5f5; border-color:#f3d3d3; }
.prp-row-ic { font-size:13px; flex-shrink:0; }
.prp-row.ok   .prp-row-ic { color:#33a466; }
.prp-row.disp .prp-row-ic { color:#c62828; }
.prp-row-name { flex:1; min-width:0; font-weight:700; color:#3c3846;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.prp-row-done { color:#107c41; font-size:12px; flex-shrink:0; }
/* Unread = nobody has opened this message yet (seen_at IS NULL) */
.prp-row-new { flex-shrink:0; font-size:8.5px; font-weight:800; letter-spacing:.4px; color:#fff;
    background:#3557b7; border-radius:8px; padding:1px 5px; }
.prp-row.unread { border-color:#ccd9f7; box-shadow:0 0 0 1px #ccd9f7 inset; }
.prp-row.unread .prp-row-name { color:#1f3a80; }
.prp-row.unread .prp-row-msg { background:#eef2fd; border-color:#ccd9f7; color:#3557b7; }
.prp-row-msg { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
    width:20px; height:20px; border-radius:6px; background:#fff6e2; border:1px solid #f2dfae; color:#a9700a; font-size:11px; }
.prp-row.has-msg { cursor:pointer; }
.prp-row.has-msg:hover { filter:brightness(.97); }
.prp-row.has-msg:hover .prp-row-msg,
.prp-row.has-msg:focus-visible .prp-row-msg { background:#a9700a; border-color:#a9700a; color:#fff; }
.prp-row.has-msg:focus-visible { outline:2px solid #107c41; outline-offset:1px; }

/* ── The sign-off conversation popup ── */
#modal-emp-review .mer-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
#modal-emp-review .mer-name { font-size:14px; font-weight:800; color:#3c3846; }
.mer-badge { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:800;
    padding:2px 9px; border-radius:12px; border:1px solid; }
.mer-badge.ok   { background:#eafaf0; color:#107c41; border-color:#b7e4c7; }
.mer-badge.disp { background:#fdecea; color:#c62828; border-color:#f5c6cb; }
.mer-when { font-size:10.5px; color:#948ea5; margin-left:auto; }
.mer-empty { text-align:center; color:#948ea5; font-size:12.5px; padding:22px 12px; }
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

<!-- ── Version History Offcanvas ──────────────────────── -->
<style>
#offcanvas-history { width: 460px; max-width: 100vw; }
.vh-header {
    background: #d9eedd;
    padding: 16px 18px 0;
    color: #412b6d;
    position: relative; overflow: hidden;
}

.vh-header-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px; position:relative; }
.vh-title { font-size:15px; font-weight:800; letter-spacing:.2px; display:flex; align-items:center; gap:8px; }
.vh-title-icon {
    width:26px; height:26px; border-radius:8px;
    background:rgba(65,43,109,.10); border:1px solid rgba(65,43,109,.16);
    display:flex; align-items:center; justify-content:center; font-size:14px;
}
.vh-subtitle { font-size:11px; color:rgba(65,43,109,.8); margin-top:3px; }
.vh-count-badge {
    background:rgba(65,43,109,.12); color:#412b6d;
    font-size:11px; font-weight:700;
    border-radius:20px; padding:3px 10px;
    backdrop-filter:blur(4px); white-space:nowrap;
}
.vh-tabs {
    display:flex; gap:0; border-bottom: none; margin-top:4px; position:relative;
}
.vh-tab {
    padding:8px 13px; font-size:11px; font-weight:600;
    color:rgba(65,43,109,.75); cursor:pointer;
    border-bottom:2px solid transparent;
    transition:all .15s; white-space:nowrap;
    display:flex; align-items:center; gap:5px;
}
.vh-tab.active { color:#412b6d; border-bottom-color:#6642aa; }
.vh-tab:hover:not(.active) { color:rgba(65,43,109,.95); }
.vh-tab-count {
    font-size:9px; font-weight:800; line-height:1;
    background:rgba(65,43,109,.12); border-radius:20px; padding:2px 5px;
}
.vh-tab.active .vh-tab-count { background:rgba(65,43,109,.2); }
.vh-search-bar {
    padding:10px 14px; background:#fcfbfe;
    border-bottom:1px solid #e1dfdd;
    display:flex; align-items:center; gap:8px;
}
.vh-search-wrap { position:relative; flex:1; }
.vh-search-input {
    width:100%; border:1px solid #d7d0e6; border-radius:6px;
    padding:6px 28px 6px 32px; font-size:12px; color:#323130;
    background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23a19f9d' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
    outline:none; transition:border-color .15s, box-shadow .15s;
}
.vh-search-input:focus { border-color:#6642aa; box-shadow:0 0 0 3px rgba(102,66,170,.12); }
.vh-search-clear {
    position:absolute; right:6px; top:50%; transform:translateY(-50%);
    border:none; background:none; color:#a19f9d; font-size:14px;
    line-height:1; cursor:pointer; padding:2px; display:none;
}
.vh-search-clear:hover { color:#605e5c; }
.vh-date-group-label {
    padding:7px 16px;
    font-size:10px; font-weight:800;
    color:#4e3483; text-transform:uppercase; letter-spacing:.6px;
    background:#f8f6fb; border-top:1px solid #eeeaf5; border-bottom:1px solid #eeeaf5;
    position:sticky; top:0; z-index:2;
    display:flex; align-items:center; gap:6px;
}
.vh-date-group-label .vh-group-count { margin-left:auto; color:#9890ab; font-weight:700; }
.vh-entry {
    display:flex; gap:12px; padding:13px 16px 13px 14px;
    border-bottom:1px solid #f4f4f4;
    transition:background .12s; position:relative;
}
.vh-entry:hover { background:#fcfcfe; }
.vh-entry.is-latest { background:#f7f6fc; }
.vh-entry.is-latest::before {
    content:''; position:absolute; left:0; top:0; bottom:0;
    width:3px; border-radius:0 2px 2px 0;
    background:#6642aa;
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
.vh-line { width:2px; flex:1; min-height:14px; background:#eae9ec; margin-top:6px; border-radius:2px; }
.vh-content { flex:1; min-width:0; padding-top:2px; }
.vh-event-row { display:flex; align-items:flex-start; gap:8px; margin-bottom:6px; }
.vh-event-text {
    font-size:12.5px; font-weight:600; color:#323130;
    flex:1; min-width:0; line-height:1.45;
}
.vh-event-sub { font-size:11px; font-weight:500; color:#8a8886; margin-top:1px; }
.vh-latest-badge {
    font-size:9px; font-weight:800; letter-spacing:.3px;
    background:#6642aa; color:#fff;
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
.vh-chip-val { background:#ecfdf5; color:#5b3699; border-color:#e0dcef; font-variant-numeric:tabular-nums; }
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
    background:linear-gradient(90deg,#f0eef1 25%,#f8f7f9 50%,#f0eef1 75%);
    background-size:200% 100%; animation:vh-shimmer 1.2s infinite;
}
@keyframes vh-shimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }
</style>
<style>
/* Critical, render-blocking: hides the workbench and styles the spinner from the
   very first paint, before the bulk pcw-* stylesheet lower in the page loads. */
body.pcw-booting .pcw-app { opacity:0; pointer-events:none; }
.pcw-app { transition:opacity .35s ease; }
#pcw-boot { position:fixed; inset:0; z-index:4000; display:flex; align-items:center; justify-content:center;
    background:#f0eff2; transition:opacity .35s ease; }
#pcw-boot.hide { opacity:0; pointer-events:none; }
.pcw-boot-inner { display:flex; flex-direction:column; align-items:center; gap:14px; }
.pcw-boot-ring { width:46px; height:46px; border-radius:50%;
    border:4px solid #e1dcec; border-top-color:#107c41; animation:pcw-spin .8s linear infinite; }
.pcw-boot-txt { font-size:13px; font-weight:700; color:#0e6b37; letter-spacing:.3px;
    font-family:'Segoe UI', system-ui, Arial, sans-serif; }
@keyframes pcw-spin { to { transform:rotate(360deg); } }
</style>
</head>
<body class="pcw-booting">

    <!-- Loading overlay — the workbench stays hidden (.pcw-booting) until the
         sheet has parsed and rendered, then the overlay fades and the content
         fades in together. -->
    <div id="pcw-boot">
        <div class="pcw-boot-inner">
            <span class="pcw-boot-ring"></span>
            <span class="pcw-boot-txt">Loading payroll&hellip;</span>
        </div>
    </div>

            <!-- ══ Document workbench (layout mirrors dtr-documents.php) ══ -->
            <div class="pcw-app">

                <!-- ── Top bar ── -->
                <div class="pcw-header">
                    <div class="pcw-h-left">
                        <a class="pcw-back-btn" href="index.php?page=payroll"><i class="ri-arrow-left-line"></i> Back</a>
                        <div class="pcw-title-icon"><i class="ri-file-excel-2-line"></i></div>
                        <div>
                            <div class="pcw-h-title">
                                Payroll Details
                                <?php if ($status == 2): ?>
                                    <span class="pcw-status-badge pst-lock"><i class="ri-lock-fill"></i> Locked</span>
                                <?php elseif ($status == 3): ?>
                                    <span class="pcw-status-badge pst-rev"><i class="ri-user-received-2-line"></i> Ready for Review</span>
                                <?php else: ?>
                                    <span class="pcw-status-badge pst-open"><i class="ri-lock-unlock-line"></i> Open</span>
                                <?php endif; ?>
                            </div>
                            <div class="pcw-meta-chips">
                                <span class="pcw-meta-chip"><i class="ri-calendar-2-line"></i><?= date('M d', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($payroll['date_to'])) ?></span>
                                <span class="pcw-meta-chip"><i class="ri-group-line"></i><?= number_format($summary['emp_count']) ?> employees</span>
                                <span class="pcw-meta-chip"><i class="ri-wallet-3-line"></i>&#8369; <?= number_format($summary['total_net'], 2) ?> net</span>
                                <?php if ($payroll_r['category'] != 0): ?>
                                    <span class="pcw-meta-chip"><i class="ri-global-line"></i><?= htmlspecialchars($payroll_r['cluster']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="pcw-h-actions">
                        <?php /* Also shown mid-review when at least one employee is unlocked —
                                 otherwise their reopened row could be typed into but never saved. */ ?>
                        <?php if ($pcwCanSave) { ?>
                            <button type="button" id="pcw-save" class="pcw-btn primary" style="display:none;" onclick="saveUnsaved()" title="Save the figures changed on the computation sheet">
                                <i class="ri-save-3-line"></i> Save <span class="pcw-count-pill" id="pcw-unsaved-n" style="background:#fff;color:#0e6b37;">0</span>
                            </button>
                        <?php } ?>
                        <?php if ((int)$status === 3) { ?>
                            <span class="pcw-unlock-flag" title="These employees can be edited; everyone else stays frozen"
                                  <?= $pcwUnlockedCount > 0 ? '' : 'style="display:none;"' ?>>
                                <i class="ri-lock-unlock-line"></i> <?= $pcwUnlockedCount ?> unlocked
                            </span>
                        <?php } ?>
                        <button type="button" class="pcw-btn" data-bs-toggle="modal" data-bs-target="#modal-table-editor" title="Preview the whole payroll in classic table form (read-only)">
                            <i class="ri-table-line"></i> Table View
                        </button>
                        <?php if ($payroll_type == 5) { ?>
                            <button type="button" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=monthly&id=<?= $id ?>', 'Payroll PDF')" class="pcw-btn"><i class="ri-printer-line"></i> Print</button>
                        <?php } else { ?>
                            <button type="button" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=payroll&id=<?= $id ?>&site_id=<?= $sid ?>', 'Payroll PDF')" class="pcw-btn"><i class="ri-printer-line"></i> Print</button>
                            <button type="button" title="Summary by Department PDF" onclick="openPdfPreview('pdf-payroll.php?src=dept&id=<?= $id ?>', 'Department Summary PDF')" class="pcw-btn"><i class="ri-building-2-line"></i> Dept.</button>
                        <?php } ?>
                        <button type="button" title="Totals per contribution, deduction, loan, and refund type" onclick="openRemitModal()" class="pcw-btn"><i class="ri-hand-coin-line"></i> Remittance</button>
                        <?php if ($status == 1) { ?>
                            <button type="button" title="Send employees their payslip for review before locking" onclick="sendPayrollForReview(<?= $id ?>)" class="pcw-btn good"><i class="ri-user-received-2-line"></i> Send for Review</button>
                        <?php } ?>
                        <?php if ($status !== 2) { ?>
                            <button type="button" title="Lock Payroll" onclick="lockPayroll(<?= $id ?>)" class="pcw-btn danger"><i class="ri-lock-line"></i> Lock</button>
                        <?php } ?>
                        <button type="button" title="Version History" onclick="openPayrollHistory(<?= $id ?>)" class="pcw-btn"><i class="ri-history-line"></i> History</button>
                    </div>
                </div>

                <!-- ── Workspace ── -->
                <div class="pcw-wrap" id="pcw-wrap">

                    <!-- LEFT: employee previews -->
                    <div class="pcw-panel pcw-left">
                        <div class="pcw-panel-head"><span><i class="ri-team-line"></i> Employees</span><span id="pcw-total"></span></div>
                        <div class="pcw-search">
                            <div class="pcw-search-wrap">
                                <i class="ri-search-2-line"></i>
                                <input id="pcw-q" type="text" placeholder="Search name, no., position..." autocomplete="off">
                                <button type="button" class="pcw-filter-btn" id="pcw-filter-btn" onclick="pcwToggleFilter()" title="Filters">
                                    <i class="ri-filter-3-line"></i>
                                    <span class="pcw-filter-count" id="pcw-filter-count" style="display:none;">0</span>
                                </button>
                            </div>
                            <div class="pcw-filter-pop" id="pcw-filter-pop">
                                <div class="pcw-fp-head">
                                    <span><i class="ri-filter-3-line"></i> Filters</span>
                                    <button type="button" class="pcw-fp-reset" onclick="pcwResetFilters()"><i class="ri-restart-line"></i> Reset all</button>
                                </div>
                                <div class="pcw-fp-lbl">Review mark</div>
                                <div class="pcw-rv-chips" id="pcw-rv-chips">
                                    <button type="button" data-rv="" class="on">All</button>
                                    <button type="button" data-rv="1"><i class="ri-lock-2-fill" style="color:#33a466;"></i> Verified</button>
                                    <button type="button" data-rv="2"><i class="ri-error-warning-fill" style="color:#e0653f;"></i> Issue</button>
                                    <button type="button" data-rv="3"><i class="ri-loader-4-line" style="color:#3f7fe0;"></i> Reviewing</button>
                                    <button type="button" data-rv="0"><i class="ri-checkbox-blank-circle-line" style="color:#a29cac;"></i> No mark</button>
                                </div>
                                <?php if (in_array((int)$status, [2, 3], true)): ?>
                                <?php /* The employees' own sign-off, separate from the reviewer's
                                         colour mark above — lets HR pull up every disputed payslip
                                         (or everyone still silent) in one click. */ ?>
                                <div class="pcw-fp-lbl">Employee review</div>
                                <div class="pcw-rv-chips" id="pcw-erv-chips">
                                    <button type="button" data-erv="" class="on">All</button>
                                    <button type="button" data-erv="2"><i class="ri-error-warning-fill" style="color:#c62828;"></i> Disputed</button>
                                    <button type="button" data-erv="1"><i class="ri-checkbox-circle-fill" style="color:#33a466;"></i> Confirmed</button>
                                    <button type="button" data-erv="0"><i class="ri-time-line" style="color:#c98a00;"></i> Awaiting</button>
                                    <button type="button" data-erv="m"><i class="ri-chat-3-fill" style="color:#a9700a;"></i> With message</button>
                                </div>
                                <?php endif; ?>
                                <?php if ((int)$status === 3): ?>
                                <?php /* Which rows an admin reopened for correction. Locked is the
                                         norm mid-review, so this mostly answers "what is still open?" */ ?>
                                <div class="pcw-fp-lbl">Edit lock</div>
                                <div class="pcw-rv-chips" id="pcw-lock-chips">
                                    <button type="button" data-lock="" class="on">All</button>
                                    <button type="button" data-lock="u"><i class="ri-lock-unlock-line" style="color:#c98a00;"></i> Unlocked</button>
                                    <button type="button" data-lock="l"><i class="ri-lock-line" style="color:#827d91;"></i> Locked</button>
                                </div>
                                <?php endif; ?>
                                <div class="pcw-fp-lbl">Department</div>
                                <select id="pcw-dept" class="pcw-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($pay_departments as $pd): ?>
                                        <option value="<?= htmlspecialchars($pd, ENT_QUOTES) ?>"><?= htmlspecialchars($pd) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pcw-fp-lbl">Position</div>
                                <select id="pcw-pos-filter" class="pcw-select">
                                    <option value="">All Positions</option>
                                    <?php foreach ($pay_positions as $pp): ?>
                                        <option value="<?= htmlspecialchars($pp, ENT_QUOTES) ?>"><?= htmlspecialchars($pp) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pcw-fp-lbl">Rate type</div>
                                <select id="pcw-rate-filter" class="pcw-select">
                                    <option value="">All Rate Types</option>
                                    <?php foreach ($pay_rate_types as $prt): ?>
                                        <option value="<?= htmlspecialchars($prt, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($prt)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="pcw-list" id="pcw-list"></div>
                        <!-- ── Bulk actions for the ticked employees ── -->
                        <div class="pcw-bulk" id="pcw-bulk">
                            <div class="pcw-bulk-head">
                                <span class="pcw-bulk-n"><i class="ri-checkbox-multiple-line"></i> <span id="pcw-bulk-count">0</span> selected</span>
                                <button type="button" class="pcw-bulk-x" onclick="pcwClearSelection()" title="Clear selection"><i class="ri-close-line"></i></button>
                            </div>
                            <div class="pcw-bulk-lbl">Set review mark</div>
                            <div class="pcw-bulk-rv">
                                <button type="button" class="rv-ok" onclick="pcwBulkReview(1)" title="Mark selected as verified (locks their figures)"><i class="ri-lock-2-fill"></i> Verified</button>
                                <button type="button" class="rv-issue" onclick="pcwBulkReview(2)" title="Flag selected as having an issue"><i class="ri-error-warning-fill"></i> Issue</button>
                                <button type="button" class="rv-chk" onclick="pcwBulkReview(3)" title="Mark selected as under review"><i class="ri-loader-4-line"></i> Reviewing</button>
                                <button type="button" class="rv-none" onclick="pcwBulkReview(0)" title="Clear the mark on selected"><i class="ri-eraser-line"></i> Clear mark</button>
                            </div>
                            <div class="pcw-bulk-lbl">Actions</div>
                            <div class="pcw-bulk-act">
                                <?php if ($status == 3): ?>
                                <?php /* Only offered once the batch is out for review (status 3): that is
                                         the only state where employees can actually SEE and confirm their
                                         payslip, so a chase notification can never point at a blank portal. */ ?>
                                <button type="button" class="pcw-btn good" onclick="pcwNotifySelected()" title="Remind only the selected employees to confirm their payslip">
                                    <i class="ri-notification-badge-line"></i> Notify for review
                                </button>
                                <?php endif; ?>
                                <button type="button" class="pcw-btn" onclick="printSelectedPayslips()" title="Preview / print the selected payslips">
                                    <i class="ri-file-text-line"></i> Payslips
                                </button>
                            </div>
                            <!-- Progress while a bulk action runs -->
                            <div class="pcw-bulk-prog" id="pcw-bulk-prog">
                                <div class="pcw-bulk-prog-lbl"><span id="pcw-bulk-prog-txt">Working…</span><span id="pcw-bulk-prog-n">0 / 0</span></div>
                                <div class="pcw-bulk-bar"><div id="pcw-bulk-bar-fill"></div></div>
                            </div>
                        </div>
                        <div class="pcw-list-foot">
                            <label class="pcw-selall" title="Select every employee currently listed"><input type="checkbox" id="pcw-sel-all"> All</label>
                            <span class="pcw-selall-hint" id="pcw-ps-wrap">
                                <i class="ri-checkbox-multiple-line"></i> <span class="pcw-count-pill" id="pcw-ps-count">0</span> selected
                            </span>
                        </div>
                    </div>

                    <!-- CENTER: pay computation sheet -->
                    <div class="pcw-center">
                        <div class="pcw-doc-toolbar">
                            <div class="pcw-doc-nav">
                                <button type="button" class="pcw-btn" id="pcw-prev"><i class="ri-arrow-left-s-line"></i> Prev</button>
                                <button type="button" class="pcw-btn" id="pcw-next">Next <i class="ri-arrow-right-s-line"></i></button>
                                <span class="pcw-doc-pos" id="pcw-pos"></span>
                            </div>
                            <div class="pcw-doc-zoom">
                                <button type="button" class="pcw-pg-btn" id="pcw-zoom-out" title="Zoom out"><i class="ri-zoom-out-line"></i></button>
                                <button type="button" class="pcw-zoom-val" id="pcw-zoom-val" title="Reset zoom">100%</button>
                                <button type="button" class="pcw-pg-btn" id="pcw-zoom-in" title="Zoom in"><i class="ri-zoom-in-line"></i></button>
                                <button type="button" class="pcw-pg-btn" onclick="window.print()" title="Print this sheet"><i class="ri-printer-line"></i></button>
                            </div>
                        </div>
                        <div class="pcw-paper-scroll">
                            <div class="pcw-paper" id="pcw-paper">
                                <div class="pcw-doc-empty">Select an employee to view their pay computation sheet.</div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: summary + insights + review -->
                    <div class="pcw-right">
                        <div class="pcw-panel" style="flex-shrink:0;">
                            <div class="pcw-panel-head pcw-ch" onclick="pcwTogglePanel(this)" title="Click to collapse / expand">
                                <span><i class="ri-user-3-line"></i> Employee Summary</span>
                                <i class="ri-arrow-down-s-line pcw-collapse-ic"></i>
                            </div>
                            <div class="pcw-sum-body" id="pcw-sum">
                                <div style="font-size:12px;color:#948ea5;">No employee selected.</div>
                            </div>
                        </div>
                        <!-- ── Batch Insights ── -->
                        <div class="pcw-panel grow">
                            <div class="pcw-panel-head pcw-ch" onclick="pcwTogglePanel(this)" title="Click to collapse / expand">
                                <span><i class="ri-lightbulb-flash-line"></i> Batch Insights</span>
                                <span class="pcw-head-right">
                                    <span id="pcw-ins-clear" style="display:none;font-weight:600;color:#c62828;font-size:10px;cursor:pointer;"><i class="ri-close-circle-line"></i> clear filter</span>
                                    <i class="ri-arrow-down-s-line pcw-collapse-ic"></i>
                                </span>
                            </div>
                            <div class="pcw-ins-body" id="pcw-insights"></div>
                        </div>
                        <?php if (in_array((int)$status, [2, 3], true)): ?>
                        <!-- ── Employee Review Progress — same card shell as Batch Insights:
                             fixed head, body-only scroll, collapsible. ── -->
                        <div class="pcw-panel pcw-rv-panel">
                            <div class="pcw-panel-head pcw-ch" onclick="pcwTogglePanel(this)" title="Click to collapse / expand">
                                <span><i class="ri-user-received-2-line"></i> Employee Review</span>
                                <span class="pcw-head-right">
                                    <?php if ((int)$status === 3): ?>
                                        <span class="pcw-head-badge rev">In progress</span>
                                    <?php else: ?>
                                        <span class="pcw-head-badge lock">Locked</span>
                                    <?php endif; ?>
                                    <?php if ($pcwUnreadMsgs > 0): ?>
                                    <span class="pcw-head-badge unread" id="prp-unread-chip" title="Employee messages nobody has opened yet">
                                        <i class="ri-mail-unread-line"></i> <span id="prp-unread-n"><?= $pcwUnreadMsgs ?></span>
                                    </span>
                                    <?php endif; ?>
                                    <i class="ri-arrow-down-s-line pcw-collapse-ic"></i>
                                </span>
                            </div>
                            <div class="pcw-rv-body">
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
                                <?php
                                // Name-only rows keep the panel readable on a 400-employee batch.
                                // The comment itself lives one click away in the conversation popup
                                // (pcwOpenReviewConvo), so nothing here wraps or scrolls sideways.
                                $prpRow = function ($prv, $kind) {
                                    $hasMsg = trim((string)$prv['comment']) !== '';
                                    $unread = $hasMsg && empty($prv['seen_at']);
                                    $eid    = (int)$prv['employee_id'];
                                    ?>
                                    <div class="prp-row <?= $kind ?><?= $hasMsg ? ' has-msg' : '' ?><?= $unread ? ' unread' : '' ?>"
                                         data-emp="<?= $eid ?>"
                                         <?= $hasMsg ? 'role="button" tabindex="0" onclick="pcwOpenReviewConvo(' . $eid . ')" title="View this employee\'s message"' : '' ?>>
                                        <i class="prp-row-ic <?= $kind === 'disp' ? 'ri-error-warning-fill' : 'ri-checkbox-circle-fill' ?>"></i>
                                        <span class="prp-row-name"><?= htmlspecialchars($prv['name']) ?></span>
                                        <?php if ($unread): ?>
                                            <span class="prp-row-new" title="Not yet read">NEW</span>
                                        <?php endif; ?>
                                        <?php if (!empty($prv['resolved_at'])): ?>
                                            <span class="prp-row-done" title="HR already replied"><i class="ri-check-double-line"></i></span>
                                        <?php endif; ?>
                                        <?php if ($hasMsg): ?>
                                            <span class="prp-row-msg" title="Open the message"><i class="ri-chat-3-fill"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                };
                                ?>
                                <?php if ($payrollReviewDisputed > 0): ?>
                                <div class="prp-disputes<?= $payrollReviewDisputed > 6 ? ' is-scroll' : '' ?>">
                                    <?php foreach ($payrollReviewRows as $prv): if ((int)$prv['status'] !== 2) continue; ?>
                                        <?php $prpRow($prv, 'disp'); ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($payrollReviewConfirmed > 0): ?>
                                <?php
                                    $pcnRows = [];
                                    $pcnMsgs = 0;
                                    foreach ($payrollReviewRows as $prv) {
                                        if ((int)$prv['status'] !== 1) continue;
                                        $pcnRows[] = $prv;
                                        if (trim((string)$prv['comment']) !== '') $pcnMsgs++;
                                    }
                                ?>
                                <details class="prp-names"<?= count($pcnRows) <= 12 || $pcnMsgs ? ' open' : '' ?>>
                                    <summary>
                                        <span class="prp-confirmed-lbl"><i class="ri-checkbox-circle-line"></i> Confirmed by</span>
                                        <span class="prp-count-pill"><?= count($pcnRows) ?></span>
                                        <?php if ($pcnMsgs): ?>
                                        <span class="prp-note-pill" title="Confirmed with a message"><i class="ri-chat-3-line"></i> <?= $pcnMsgs ?> with message</span>
                                        <?php endif; ?>
                                        <span class="prp-names-hint"><span class="lbl-show">show names</span><span class="lbl-hide">hide</span></span>
                                    </summary>
                                    <div class="prp-confirms<?= count($pcnRows) > 6 ? ' is-scroll' : '' ?>">
                                        <?php foreach ($pcnRows as $prv): $prpRow($prv, 'ok'); endforeach; ?>
                                    </div>
                                </details>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

<!-- ══ Full-width sheet editor modal — the classic Excel-style payroll table ══ -->
<div class="modal fade" id="modal-table-editor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content" style="background:#f0eff2;">
            <div class="modal-body p-2 d-flex flex-column" style="overflow:hidden;">
                <div class="xl-panel" id="myDiv" style="flex:1;min-height:0;display:flex;flex-direction:column;">
                    <div class="xl-ribbon">
                        <span class="xl-ribbon-title">
                            <i class="ri-file-excel-2-line"></i> Payroll List
                        </span>
                        <div class="xl-ribbon-actions">
                            <!-- <button data-toggle="tooltip" id="sf" title="Fullscreen" onclick="openFullscreen()" class="xl-btn"><i class="ri-fullscreen-line"></i></button>
                            <button style="display:none;" id="hf" data-toggle="tooltip" title="Exit Fullscreen" onclick="closeFullscreen()" class="xl-btn"><i class="ri-fullscreen-exit-line"></i></button>
                            <div class="xl-ribbon-sep"></div> -->
                            <!-- <button data-toggle="tooltip" title="Sites" onclick="view_site()" class="xl-btn"><i class="ri-building-line"></i> Sites</button> -->
                            <div class="xl-ribbon-sep"></div>
                            <?php if ($payroll_type == 5) { ?>
                                <button data-toggle="tooltip" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=monthly&id=<?= $id ?>', 'Payroll PDF')" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                            <?php } else { ?>
                                <button data-toggle="tooltip" title="Payroll PDF" onclick="openPdfPreview('pdf-payroll.php?src=payroll&id=<?= $id ?>&site_id=<?= $sid ?>', 'Payroll PDF')" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                                <!-- <button data-toggle="tooltip" title="Summary PDF" onclick="openPdfPreview('pdf-payroll.php?src=employer&id=<?= $id ?>&type=all', 'Payroll Summary PDF')" class="xl-btn"><i class="ri-printer-fill"></i> Summary</button> -->
                                <!-- <button data-toggle="tooltip" title="Summary by Department PDF" onclick="openPdfPreview('pdf-payroll.php?src=dept&id=<?= $id ?>', 'Department Summary PDF')" class="xl-btn"><i class="ri-building-2-line"></i> Dept. Summary</button> -->
                            <?php } ?>
                            <button data-toggle="tooltip" title="Download this table as a styled Excel workbook" onclick="pcwExportTableExcel(<?= $id ?>)" class="xl-btn" id="xl-export-btn"><i class="ri-file-excel-2-line"></i> Excel</button>
                            <!-- <button data-toggle="tooltip" title="Totals per contribution, deduction, loan, and refund type" onclick="openRemitModal()" class="xl-btn"><i class="ri-hand-coin-line"></i> Remittance</button>
                            <button id="btn-print-payslips" title="Check rows to select employees, then click to print their payslips" onclick="printSelectedPayslips()" class="xl-btn">
                                <i class="ri-file-text-line"></i> Payslips <span id="ps-count" style="background:#d7d0e6;color:#4e3483;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:2px;font-weight:700;">0</span>
                            </button> -->
                            <!-- <?php if ($status == 1) { ?>
                                <div class="xl-ribbon-sep"></div>
                                <button data-toggle="tooltip" title="Send employees their payslip for review before locking" onclick="sendPayrollForReview(<?= $id ?>)" class="xl-btn xl-btn-save"><i class="ri-user-received-2-line"></i> Send for Review</button>
                            <?php } ?> -->
                            <!-- <?php if ($status !== 2) { ?>
                                <div class="xl-ribbon-sep"></div>
                                <button data-toggle="tooltip" title="Lock Payroll" onclick="lockPayroll(<?= $id ?>)" class="xl-btn xl-btn-danger"><i class="ri-lock-line"></i> Lock</button>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="saveUnsaved()" id="btn-unsaved" title="Save Changes" class="xl-btn xl-btn-save" style="display:none;">
                                    <i class="bx bx-save"></i> Save
                                    <span id="counter-unsaved">0</span>
                                </button>
                            <?php } ?> -->
                            <!-- <div class="xl-ribbon-sep"></div>
                            <button data-toggle="tooltip" title="Version History" onclick="openPayrollHistory(<?= $id ?>)" class="xl-btn"><i class="ri-history-line"></i> History</button> -->
                            <div class="xl-ribbon-sep"></div>
                            <button type="button" data-bs-dismiss="modal" class="xl-btn" title="Close the table preview"><i class="ri-close-line"></i> Close</button>
                        </div>
                    </div>
                    <div class="xl-panel-body" style="flex:1;min-height:0;overflow:auto;position:relative;">
                        <!-- ── Summary stats strip ── -->
                        <!-- <div class="pay-stats-strip">
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
                        </div> -->

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
                            <select id="pay-pos-filter" title="Filter by position">
                                <option value="">All Positions</option>
                                <?php foreach ($pay_positions as $pp): ?>
                                    <option value="<?= htmlspecialchars($pp, ENT_QUOTES) ?>"><?= htmlspecialchars($pp) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="pay-rate-filter" title="Filter by rate type">
                                <option value="">All Rate Types</option>
                                <?php foreach ($pay_rate_types as $prt): ?>
                                    <option value="<?= htmlspecialchars($prt, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($prt)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pay-anomaly-chips" id="pay-anomaly-chips"></div>
                            <span class="pay-filter-count" id="pay-filter-count"></span>
                        </div>

                        <form id="form-payroll">
                            <div class="table-responsive2" id="table-responsive2">
                                <?php if ($payroll_type == 5) { ?>

                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <!-- ═══ ROW 1 : Section group banners ═══ -->
                                            <tr>
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
                                                <!-- Actions column removed — the table is a read-only preview now;
                                                     payslip / DTR / review-mark actions live in the workbench panels. -->
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
                                                    $ded_names[$k['type'] . '-' . $k['id']] = $name_deduction;
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
                                                        $refund_names[$k['id']] = $rfund['refunds'];
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
                                                // Minutes in this employee's working day (their
                                                // shift length, frozen on the item), not a fixed 8h.
                                                $minutesPerDay = day_hours_or_default($row['day_hours'] ?? null) * 60;
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
                                                // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                $total_amount =  ($total_basic_rate    +  $total_allowance - $absent_amount) / 2;
                                                $t_total_amount += $total_amount;
                                                // Named one-off items for this employee: allowances add to gross,
                                                // deductions are applied with the other deductions below.
                                                $rowExtras = $pcwExtras[(int)$row['id']] ?? [];
                                                $rowExtraT = pcw_extra_totals($rowExtras);
                                                $gross_salary =  ($total_amount +   $overtime_amount   +  $legal_holiday_amount + $sunday_duty_amount +  $special_holiday_amount - $late_amount);
                                                $gross_salary += $rowExtraT['add'];

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;
                                                $pcw_row_deds = [];
                                                $pcw_row_refunds = [];



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
                                                    // Editable when the payroll is Open, or when this one row was
                                                    // unlocked by an admin while the batch is out for review.
                                                    $rowEditable = pcw_row_editable($status, $row);
                                                    // Inputs are rendered for Open AND in-review batches so a row can be
                                                    // unlocked without a page reload; readonly is what actually gates editing.
                                                    $rowShowInputs = in_array((int)$status, [1, 3], true);
                                                    $rowRO = $rowEditable ? '' : ' readonly';
                                                ?>
                                                <tr class="name-<?= $row['id'] ?> <?= $rvClass ?>" data-row-id="<?= $row['id'] ?>" data-review="<?= $rv ?>" data-review-comment="<?= htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES) ?>"
                                                    data-unlocked="<?= !empty($row['unlocked_at']) ? '1' : '0' ?>"
                                                    data-rate-type="<?= htmlspecialchars($row['rate_type'] ?? 'daily', ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars(strtolower($row['lastname'] . ', ' . $row['firstname'] . ' ' . $row['employee_no']), ENT_QUOTES) ?>"
                                                    data-dept="<?= htmlspecialchars($row['department'] ?? '', ENT_QUOTES) ?>"
                                                    data-pos="<?= htmlspecialchars($row['position'] ?? '', ENT_QUOTES) ?>"
                                                    data-days="<?= count($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []) ?>">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class"<?= $rowRO ?> placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $allowance_amount ?>" class="form-control input-class"<?= $rowRO ?> placeholder="Allowance" aria-label="Allowance" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class"<?= $rowRO ?> placeholder="No. of Days" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'present')" data-toggle="tooltip" title="Save Changes" class="btn btn-success" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <?= $row['present'] ?>
                                                        <?php } ?>
                                                    </td>

                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['absent'] ?>" data-id="<?= $row['id'] ?>" data-type="absent" class="form-control input-class"<?= $rowRO ?> placeholder="absent" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['legal_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="legal_holiday" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['sunday_duty'] ?>" data-id="<?= $row['id'] ?>" data-type="sunday_duty" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['special_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="special_holiday" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                            $pcw_row_deds[] = ['g' => (int)$k['type'], 'id' => (int)$k['id'], 'label' => $ded_names[$k['type'] . '-' . $k['id']] ?? ('#' . $k['id']), 'amt' => (float)$deduction_amount];


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($rowShowInputs) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $sss_fund ?>" data-id="<?= $row['id'] ?>" data-type="sss_fund" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="sss_fund" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'sss_fund')"   class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($sss_fund, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jei_advances ?>" data-id="<?= $row['id'] ?>" data-type="jei_advances" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="jei_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jei_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jei_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $jcc_advances ?>" data-id="<?= $row['id'] ?>" data-type="jcc_advances" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="jcc_advances" aria-describedby="basic-addon2">
                                                                <!-- <div class="input-group-append">
                                                                    <button onclick="updateData(this, <?= $row['id'] ?>,'jcc_advances')" class="btn btn-success" data-toggle="tooltip" title="Save Changes" type="button"><i class="ri-save-fill"></i></button>
                                                                </div> -->
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($jcc_advances, 2) ?></b>
                                                        <?php } ?>

                                                    </td>

                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $tax ?>" class="form-control input-class"<?= $rowRO ?> data-id="<?= $row['id'] ?>" data-type="tax" placeholder="Enter Amount" aria-label="Tax" aria-describedby="basic-addon2">
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
                                                            $pcw_row_refunds[] = ['id' => (int)$kd['id'], 'label' => $refund_names[$kd['id']] ?? ('#' . $kd['id']), 'amt' => (float)$refund_amount];





                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($rowShowInputs) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
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

                                                    // One-off deductions for this employee (allowances already in gross).
                                                    $total_deductions += $rowExtraT['less'];
                                                    $t_deduction     += $rowExtraT['less'];
                                                    $net = $gross_salary -  $total_deductions + $total_refunds;
                                                    $t_net += $net;
                                                    $pcwEmployees[] = [
                                                        'id' => (int)$row['id'], 'emp' => (int)$row['employee_id'], 'site' => (int)$row['site_id'],
                                                        'name' => $row['lastname'] . ', ' . $row['firstname'],
                                                        'first' => $row['firstname'], 'last' => $row['lastname'],
                                                        'no' => (string)$row['employee_no'], 'pos' => (string)($row['position'] ?? ''), 'dept' => (string)($row['department'] ?? ''),
                                                        'rate_type' => (string)($row['rate_type'] ?? 'monthly'),
                                                        'monthly' => (float)$row['basic_pay'], 'quinsena' => (float)$row['basic_pay'] / 2, 'per_day' => (float)$perDay,
                                                        'present' => (float)$row['present'], 'absent' => (float)$row['absent'], 'absent_amt' => (float)$absent_amount,
                                                        'late_min' => (float)$row['late'], 'late_amt' => (float)$late_amount,
                                                        'ot_hrs' => (float)$row['ot'], 'ot_rate' => (float)$row['ot_rate'], 'ot_amt' => (float)$overtime_amount,
                                                        'allow_days' => (float)$allowance_days, 'allow_rate' => (float)$allowance_amount, 'allow_amt' => (float)$total_allowance,
                                                        'legal' => (float)$legal_holiday, 'legal_amt' => (float)$legal_holiday_amount,
                                                        'rest' => (float)$sunday_duty, 'rest_amt' => (float)$sunday_duty_amount,
                                                        'spc' => (float)$special_holiday, 'spc_amt' => (float)$special_holiday_amount,
                                                        'basic_earned' => (float)$total_amount, 'gross' => (float)$gross_salary,
                                                        'deds' => $pcw_row_deds,
                                                        'sss_fund' => (float)$sss_fund, 'jei' => (float)$jei_advances, 'jcc' => (float)$jcc_advances, 'tax' => (float)$tax, 'other_ded' => 0.0,
                                                        'total_ded' => (float)$total_deductions,
                                                        'refunds' => $pcw_row_refunds, 'total_ref' => (float)$total_refunds,
                                                        'adj' => 0.0, 'adj_rem' => '',
                                                        'net' => (float)$net,
                                                        'prev_net' => $prevPayroll ? ($prevNetByEmpSite[$row['employee_id'] . '-' . $row['site_id']] ?? $prevNetByEmp[$row['employee_id']] ?? null) : null,
                                                        'dtr_days' => count($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []),
                                                        'dtr' => pcw_dtr_sheet($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []),
                                                        'notes' => $pcwNotesByEmp[(int)$row['employee_id']] ?? [],
                                                        'msgs' => $pcwMsgsByEmp[(int)$row['employee_id']] ?? [],
                                                        'rv' => $rv, 'rv_c' => (string)($row['review_comment'] ?? ''),
                                                        'sent_n' => (int)($row['review_sent_count'] ?? 0),
                                                        'sent_at' => !empty($row['review_sent_at']) ? date('M j, g:i A', strtotime($row['review_sent_at'])) : '',
                                                        'emp_rv' => (int)($payrollReviewRows[$row['employee_id']]['status'] ?? 0),
                                                        // Per-row edit window (admin unlocked this employee mid-review)
                                                        // Named one-off lines on this payslip
                                                        'extras' => $rowExtras,
                                                        'extra_add' => (float)$rowExtraT['add'],
                                                        'extra_less' => (float)$rowExtraT['less'],
                                                        'unlocked' => !empty($row['unlocked_at']) ? 1 : 0,
                                                        'unlock_why' => (string)($row['unlocked_reason'] ?? ''),
                                                        'editable' => pcw_row_editable($status, $row) ? 1 : 0,
                                                    ];
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                        <?= net_delta_badge($row['employee_id'], $row['site_id'], $net) ?>
                                                    </td>
                                                    <!-- Actions cell removed with its column — those actions now live in
                                                         the workbench (Employee Summary + DTR Details modal). -->
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />
                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="2" class="text-center tfoot-total-cell">TOTAL</th>
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
                                            </tr>
                                        </tfoot>
                                    </table>




                                <?php } else { ?>
                                    <table cellspacing="0" id="table-1">
                                        <thead>
                                            <tr>
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
                                                <?php /* configured types + SSS Fund, JEI, JCC, Tax (Other Deduction retired) */ ?>
                                                <th colspan="<?= count($contributions_settings) + 4 ?>" class="text-center danger-header">Deduction</th>
                                                <th rowspan="2" class="text-center danger-header">Total Deduction</th>
                                                <?php if (count($refunds_settings) > 0) { ?>
                                                    <th colspan="<?= count($refunds_settings) ?>" class="text-center primary-header">Refunds</th>
                                                <?php } ?>
                                                <th rowspan="2" class="text-center info-header">Adjustment (+/−)</th>
                                                <th rowspan="2" class="text-center success-header">Net Pay</th>
                                                <!-- Actions column removed — read-only preview (see the other table). -->
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


                                                    $ded_names[$k['type'] . '-' . $k['id']] = $name_deduction;
                                                ?>
                                                        <th class="text-center danger-header"><?= $name_deduction ?></th>
                                                    <?php } ?>
                                                <?php } else { ?>
                                                <?php } ?>
                                                <th class="text-center  danger-header">SSS Provident Fund</th>
                                                <th class="text-center  danger-header">JEI Advance</th>
                                                <th class="text-center  danger-header">JCC Advances</th>
                                                <th class="text-center  danger-header">Tax</th>
                                                <?php if (count($refunds_settings) > 0) {
                                                    foreach ($refunds_settings as $k) {
                                                        $query_con = "SELECT * FROM refunds   WHERE id = ?";
                                                        $stmt_con = $conn->prepare($query_con);
                                                        $stmt_con->bind_param("i", $k['id']);
                                                        $stmt_con->execute();
                                                        $result_con = $stmt_con->get_result();
                                                        $rfund = $result_con->fetch_assoc();
                                                        $name_refunds =  $rfund['refunds'];
                                                        $refund_names[$k['id']] = $name_refunds;

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
                                                // Minutes in this employee's working day (their
                                                // shift length, frozen on the item), not a fixed 8h.
                                                $minutesPerDay = day_hours_or_default($row['day_hours'] ?? null) * 60;
                                                $perMinute = $row['per_day'] / $minutesPerDay;
                                                $employee_id = $row['employee_id'];
                                                $rate_type = in_array($row['rate_type'] ?? 'daily', ['daily', 'monthly', 'fixed'], true) ? $row['rate_type'] : 'daily';
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
                                                // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;

                                                // Rate-type-aware Total Basic Rate — matches the authoritative net
                                                // (admin_class.php) and the client payslips:
                                                //   monthly/fixed → half-month salary (basic_pay ÷ 2) minus absences
                                                //                   (fixed always has absent = 0, so full half salary)
                                                //   daily         → days present × rate per day
                                                if ($rate_type === 'monthly' || $rate_type === 'fixed') {
                                                    $total_basic_rate = ($row['basic_pay'] - $absent_amount) / 2;
                                                    $gross_salary = $total_basic_rate + ($total_allowance / 2) + $overtime_amount
                                                        + $legal_holiday_amount + $sunday_duty_amount + $special_holiday_amount - $late_amount;
                                                } else {
                                                    $total_basic_rate = $row['present'] * $row['per_day'];
                                                    $gross_salary = ($total_basic_rate + $overtime_amount + $total_allowance) - $late_amount;
                                                }
                                                // Named one-off items for this employee: allowances add to gross,
                                                // deductions are applied with the other deductions below.
                                                $rowExtras = $pcwExtras[(int)$row['id']] ?? [];
                                                $rowExtraT = pcw_extra_totals($rowExtras);
                                                $gross_salary += $rowExtraT['add'];
                                                $total_amount = $total_basic_rate;
                                                $t_total_amount += $total_amount;

                                                $contributions = json_decode($row['contributions'], true);
                                                $deductions = json_decode($row['deductions'], true);
                                                $loans = json_decode($row['loans'], true);
                                                $refunds = json_decode($row['refunds'], true);
                                                $total_deductions =  0;
                                                $total_refunds =  0;
                                                $pcw_row_deds = [];
                                                $pcw_row_refunds = [];


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
                                                    // Editable when the payroll is Open, or when this one row was
                                                    // unlocked by an admin while the batch is out for review.
                                                    $rowEditable = pcw_row_editable($status, $row);
                                                    // Inputs are rendered for Open AND in-review batches so a row can be
                                                    // unlocked without a page reload; readonly is what actually gates editing.
                                                    $rowShowInputs = in_array((int)$status, [1, 3], true);
                                                    $rowRO = $rowEditable ? '' : ' readonly';
                                                ?>
                                                <tr class="name-<?= $row['id'] ?> <?= $rvClass ?>" data-row-id="<?= $row['id'] ?>" data-review="<?= $rv ?>" data-review-comment="<?= htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES) ?>"
                                                    data-unlocked="<?= !empty($row['unlocked_at']) ? '1' : '0' ?>"
                                                    data-rate-type="<?= htmlspecialchars($row['rate_type'] ?? 'daily', ENT_QUOTES) ?>"
                                                    data-name="<?= htmlspecialchars(strtolower($row['lastname'] . ', ' . $row['firstname'] . ' ' . $row['employee_no']), ENT_QUOTES) ?>"
                                                    data-dept="<?= htmlspecialchars($row['department'] ?? '', ENT_QUOTES) ?>"
                                                    data-pos="<?= htmlspecialchars($row['position'] ?? '', ENT_QUOTES) ?>"
                                                    data-days="<?= count($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []) ?>">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['present'] ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class"<?= $rowRO ?> placeholder="No. of Days" aria-describedby="basic-addon2">
                                                            </div>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['per_day'] ?>" data-id="<?= $row['id'] ?>" data-type="per_day" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['allowance_days'] ?>" data-id="<?= $row['id'] ?>" data-type="allowance_days" class="form-control input-class"<?= $rowRO ?> placeholder="Days" aria-label="Days" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['ot'] ?>" data-id="<?= $row['id'] ?>" data-type="ot" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['late'] ?>" data-id="<?= $row['id'] ?>" data-type="late" class="form-control input-class"<?= $rowRO ?> placeholder="Hours Worked" aria-label="Hours Worked" aria-describedby="basic-addon2">
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
                                                            $pcw_row_deds[] = ['g' => (int)$k['type'], 'id' => (int)$k['id'], 'label' => $ded_names[$k['type'] . '-' . $k['id']] ?? ('#' . $k['id']), 'amt' => (float)$deduction_amount];


                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($rowShowInputs) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $deduction_amount ?>" data-id="<?= $row['id'] ?>" data-type='<?= $k['type'] == 1 ? 'contribution' : ($k['type'] == 3 ? 'loan' : 'deduction') ?>' data-dd_id="<?= $k['id'] ?>" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
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
                                                    <!-- Fixed deductions (SSS Fund / JEI / JCC / Tax) + free-form Other
                                                         Deduction — all subtracted from net, mirroring the monthly table
                                                         and get_payroll_rows_data(). -->
                                                    <?php
                                                    // Other Deduction retired — replaced by named one-off items
                                                    // (payroll_item_extras). Kept at 0 for legacy reports only.
                                                    $other_deduction = 0.0;
                                                    $total_deductions += $sss_fund + $jei_advances + $jcc_advances + $tax;
                                                    $fixed_ded_cells = [
                                                        'sss_fund'        => $sss_fund,
                                                        'jei_advances'    => $jei_advances,
                                                        'jcc_advances'    => $jcc_advances,
                                                        'tax'             => $tax,
                                                    ];
                                                    foreach ($fixed_ded_cells as $fd_field => $fd_val): ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $fd_val ?>" data-id="<?= $row['id'] ?>" data-type="<?= $fd_field ?>" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="<?= $fd_field ?>">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($fd_val, 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <?php endforeach; ?>
                                                    <?php $t_deduction += $total_deductions;  ?>
                                                    <td style="min-width: 90px;" class="text-right">
                                                        <b data-computed="total_deductions"><?= number_format($total_deductions, 2) ?></b>
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
                                                            $pcw_row_refunds[] = ['id' => (int)$kd['id'], 'label' => $refund_names[$kd['id']] ?? ('#' . $kd['id']), 'amt' => (float)$refund_amount];

                                                    ?>
                                                            <td style="min-width: 90px;" class="text-right">
                                                                <?php if ($rowShowInputs) { ?>
                                                                    <div class="input-group mb-3">
                                                                        <input type="text" value="<?= $refund_amount ?>" data-id="<?= $row['id'] ?>" data-dd_id="<?= $kd['id'] ?>" data-type="refund" class="form-control input-class"<?= $rowRO ?> placeholder="Enter Amount" aria-label="Enter Amount" aria-describedby="basic-addon2">
                                                                    <?php } else { ?>
                                                                        <b><?= number_format($refund_amount, 2) ?></b>
                                                                    <?php } ?>
                                                            </td>
                                                    <?php
                                                        }
                                                    } ?>
                                                    <!-- Adjustment — signed manual correction ADDED to net (+ bonus, − recovery) -->
                                                    <?php
                                                    $adjustment  = (float) ($row['adjustment'] ?? 0);
                                                    $adj_remarks = trim((string) ($row['adjustment_remarks'] ?? ''));
                                                    $t_adjust = ($t_adjust ?? 0) + $adjustment;
                                                    ?>
                                                    <td style="min-width: 130px;" class="text-right">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-1">
                                                                <input type="text" value="<?= $adjustment ?>" data-id="<?= $row['id'] ?>" data-type="adjustment" class="form-control input-class"<?= $rowRO ?> placeholder="+/− Amount" aria-label="Adjustment" title="Positive adds to net pay, negative deducts">
                                                            </div>
                                                            <input type="text" value="<?= htmlspecialchars($adj_remarks, ENT_QUOTES) ?>" data-id="<?= $row['id'] ?>" data-type="adjustment_remarks" class="form-control input-class"<?= $rowRO ?> placeholder="Remarks" aria-label="Adjustment Remarks" style="font-size:10px;">
                                                        <?php } else { ?>
                                                            <b class="<?= $adjustment < 0 ? 'text-danger' : '' ?>"><?= number_format($adjustment, 2) ?></b>
                                                            <?php if ($adj_remarks !== ''): ?><div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($adj_remarks) ?></div><?php endif; ?>
                                                        <?php } ?>
                                                    </td>
                                                    <?php

                                                    // One-off deductions for this employee (allowances already in gross).
                                                    $total_deductions += $rowExtraT['less'];
                                                    $t_deduction     += $rowExtraT['less'];
                                                    $net = $gross_salary -  $total_deductions + $total_refunds + $adjustment;
                                                    $t_net += $net;
                                                    $pcwEmployees[] = [
                                                        'id' => (int)$row['id'], 'emp' => (int)$row['employee_id'], 'site' => (int)$row['site_id'],
                                                        'name' => $row['lastname'] . ', ' . $row['firstname'],
                                                        'first' => $row['firstname'], 'last' => $row['lastname'],
                                                        'no' => (string)$row['employee_no'], 'pos' => (string)($row['position'] ?? ''), 'dept' => (string)($row['department'] ?? ''),
                                                        'rate_type' => $rate_type,
                                                        'monthly' => (float)$row['basic_pay'], 'quinsena' => null, 'per_day' => (float)$perDay,
                                                        'present' => (float)$row['present'], 'absent' => (float)$row['absent'], 'absent_amt' => (float)$absent_amount,
                                                        'late_min' => (float)$row['late'], 'late_amt' => (float)$late_amount,
                                                        'ot_hrs' => (float)$row['ot'], 'ot_rate' => (float)$row['ot_rate'], 'ot_amt' => (float)$overtime_amount,
                                                        'allow_days' => (float)$allowance_days, 'allow_rate' => (float)$allowance_amount, 'allow_amt' => (float)$total_allowance,
                                                        'legal' => (float)$legal_holiday, 'legal_amt' => (float)$legal_holiday_amount,
                                                        'rest' => (float)$sunday_duty, 'rest_amt' => (float)$sunday_duty_amount,
                                                        'spc' => (float)$special_holiday, 'spc_amt' => (float)$special_holiday_amount,
                                                        'basic_earned' => (float)$total_basic_rate, 'gross' => (float)$gross_salary,
                                                        'deds' => $pcw_row_deds,
                                                        'sss_fund' => (float)$sss_fund, 'jei' => (float)$jei_advances, 'jcc' => (float)$jcc_advances, 'tax' => (float)$tax, 'other_ded' => (float)$other_deduction,
                                                        'total_ded' => (float)$total_deductions,
                                                        'refunds' => $pcw_row_refunds, 'total_ref' => (float)$total_refunds,
                                                        'adj' => (float)$adjustment, 'adj_rem' => $adj_remarks,
                                                        'net' => (float)$net,
                                                        'prev_net' => $prevPayroll ? ($prevNetByEmpSite[$row['employee_id'] . '-' . $row['site_id']] ?? $prevNetByEmp[$row['employee_id']] ?? null) : null,
                                                        'dtr_days' => count($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []),
                                                        'dtr' => pcw_dtr_sheet($dtrLogsByEmpSite[$row['employee_id']][$row['site_id']] ?? []),
                                                        'notes' => $pcwNotesByEmp[(int)$row['employee_id']] ?? [],
                                                        'msgs' => $pcwMsgsByEmp[(int)$row['employee_id']] ?? [],
                                                        'rv' => $rv, 'rv_c' => (string)($row['review_comment'] ?? ''),
                                                        'sent_n' => (int)($row['review_sent_count'] ?? 0),
                                                        'sent_at' => !empty($row['review_sent_at']) ? date('M j, g:i A', strtotime($row['review_sent_at'])) : '',
                                                        'emp_rv' => (int)($payrollReviewRows[$row['employee_id']]['status'] ?? 0),
                                                        // Per-row edit window (admin unlocked this employee mid-review)
                                                        // Named one-off lines on this payslip
                                                        'extras' => $rowExtras,
                                                        'extra_add' => (float)$rowExtraT['add'],
                                                        'extra_less' => (float)$rowExtraT['less'],
                                                        'unlocked' => !empty($row['unlocked_at']) ? 1 : 0,
                                                        'unlock_why' => (string)($row['unlocked_reason'] ?? ''),
                                                        'editable' => pcw_row_editable($status, $row) ? 1 : 0,
                                                    ];
                                                    ?>
                                                    <td style="min-width: 90px;" class="text-right net-content">
                                                        <b data-computed="net"><?= number_format($net, 2) ?></b>
                                                        <?= net_delta_badge($row['employee_id'], $row['site_id'], $net) ?>
                                                    </td>
                                                    <!-- Actions cell removed with its column — those actions now live in
                                                         the workbench (Employee Summary + DTR Details modal). -->
                                                    <td class="text-center" style="min-width: 40px;"><b><?= $i ?></b></td>

                                                </tr>
                                                <input style="display: none;" name="id[]" value="<?= $row['id'] ?>" />
                                                <input style="display: none;" name="net[]" value="<?= $net ?>" />
                                                <input style="display: none;" class="net-class" did="<?= $row['id'] ?>" value="<?= $net ?>" />

                                            <?php } ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="2" class="text-center tfoot-total-cell">TOTAL</th>
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
                                                <th class="text-right"><?= number_format($t_sss_fund, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jei, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_jcc, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_tax, 2) ?></th>
                                                <th class="text-right" id="tfoot-deduct"><?= number_format($t_deduction, 2) ?></th>
                                                <?php foreach ($refunds_settings as $kd): ?>
                                                <th class="text-right"><?= number_format($t_refund[$kd['id']] ?? 0, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-right"><?= number_format($t_adjust ?? 0, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_net, 2) ?></th>
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
        </div>
    </div>
</div>
<!-- /modal-table-editor -->


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

<!-- Payslip sign-off conversation — what the employee wrote when they confirmed
     or disputed, plus HR's reply. Opened from the Employee Review panel and
     from the chat button on a left-list card. -->
<div class="modal fade" id="modal-emp-review" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#107c41;">
                <h6 class="modal-title text-white"><i class="ri-user-received-2-line me-2"></i>Payslip Review Message</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="background:#f0eff2;" id="mer-body"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-success" id="mer-reply" style="display:none;">
                    <i class="ri-chat-check-line me-1"></i>Resolve &amp; Reply
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Daily Time Record modal — the same Form 48 sheet dtr-documents.php renders,
     built from this payroll's approved DTR_details rows. -->
<div class="modal fade" id="modal-att-logs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#673bb6;">
                <h6 class="modal-title text-white"><i class="ri-calendar-check-line me-2"></i>Daily Time Record</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="background:#f0eff2;">
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
                <!-- Tabs: DTR · Logs · Messages · Notes -->
                <div class="pcw-tabs" id="al-tabs">
                    <button type="button" class="pcw-tab active" data-tab="dtr"><i class="ri-calendar-check-line"></i> DTR</button>
                    <button type="button" class="pcw-tab" data-tab="logs"><i class="ri-history-line"></i> Logs</button>
                    <button type="button" class="pcw-tab" data-tab="msgs"><i class="ri-chat-3-line"></i> Messages <span class="pcw-tab-count" id="al-tab-msgs">0</span></button>
                    <button type="button" class="pcw-tab" data-tab="notes"><i class="ri-sticky-note-line"></i> Notes <span class="pcw-tab-count" id="al-tab-notes">0</span></button>
                </div>
                <div class="pcw-tab-panes">
                    <div class="pcw-tab-pane active" data-pane="dtr">
                        <div id="al-body" class="pcw-dtr-paper"></div>
                    </div>
                    <!-- Per-day punch logs (same chips as dtr-documents.php's Records & Logs) -->
                    <div class="pcw-tab-pane" data-pane="logs">
                        <div id="al-logs"></div>
                    </div>
                    <!-- Admin ↔ employee record messages (read-only conversation) -->
                    <div class="pcw-tab-pane" data-pane="msgs">
                        <div id="al-msgs"></div>
                    </div>
                    <!-- Internal admin notes for this employee (read-only list) -->
                    <div class="pcw-tab-pane" data-pane="notes">
                        <div id="al-notes"></div>
                    </div>
                </div>
            </div>
            <!-- Footer: the period's totals at a glance, so the numbers that
                 feed the pay computation stay visible on every tab. -->
            <div class="modal-footer py-2 al-foot">
                <div class="al-foot-stats">
                    <span class="al-fs days"><i class="ri-calendar-check-line"></i><b id="al-days-count">—</b></span>
                    <span class="al-fs wh" title="Total work hours in this period"><i class="ri-time-line"></i><b id="al-tot-wh">0.00</b><em>hrs</em></span>
                    <span class="al-fs ot" title="Total overtime hours"><i class="ri-flashlight-line"></i><b id="al-tot-ot">0.00</b><em>OT</em></span>
                    <span class="al-fs ut" title="Total undertime hours"><i class="ri-arrow-down-circle-line"></i><b id="al-tot-ut">0.00</b><em>UT</em></span>
                    <span class="al-fs late" title="Total tardiness"><i class="ri-alarm-warning-line"></i><b id="al-tot-late">0</b><em>late min</em></span>
                    <span class="al-fs rv" id="al-emp-rv" style="display:none;"></span>
                </div>
                <button type="button" class="btn btn-sm btn-secondary ms-auto" data-bs-dismiss="modal">Close</button>
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
            <!-- Filled by renderRemittance() on open, straight from the saved
                 figures, so an edit made in this session is reflected. -->
            <div class="modal-body" id="rm-body"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
// The breakdown is rebuilt from the database every time the modal opens. It used
// to be PHP-rendered once at page load, so after saving an edit it still showed
// the pre-change figures — the workbench updates in place and never reloads.
function openRemitModal() {
    var modal = document.getElementById('modal-remit');
    bootstrap.Modal.getOrCreateInstance(modal).show();
    renderRemittance();
}

function renderRemittance() {
    var box = document.getElementById('rm-body');
    if (!box) return;
    box.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    $.ajax({
        url: 'ajax.php?action=remittance_breakdown', method: 'POST', dataType: 'JSON',
        data: { id: <?= (int)$id ?> },
        error: function () { box.innerHTML = '<div class="text-center text-danger py-4">Could not load the breakdown.</div>'; },
        success: function (res) {
            if (!res || !res.result) {
                box.innerHTML = '<div class="text-center text-danger py-4">' + ((res && res.message) || 'Failed.') + '</div>';
                return;
            }
            var esc = function (s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };
            var peso = function (n) {
                return '&#8369; ' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };
            var h = '<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">'
                + '<div><div class="al-meta-label"><i class="ri-calendar-range-line me-1"></i>Payroll Period</div>'
                + '<div class="al-meta-value" style="color:#107c41;"><?= date('M j, Y', strtotime($payroll['date_from'])) ?> &ndash; <?= date('M j, Y', strtotime($payroll['date_to'])) ?></div></div>'
                + '<div class="text-end"><div class="al-meta-label"><i class="ri-group-line me-1"></i>Employees</div>'
                + '<div class="al-meta-value" style="color:#107c41;">' + (res.employees || 0).toLocaleString('en-US') + '</div></div>'
                + '</div>';

            (res.groups || []).forEach(function (g) {
                h += '<div class="rm-section-title"><i class="' + esc(g.icon) + '"></i>' + esc(g.label) + '</div>'
                    + '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">'
                    + '<thead class="table-dark"><tr><th>Type</th>'
                    + '<th class="text-center" style="width:100px;">Employees</th>'
                    + '<th class="text-end" style="width:130px;">Total</th></tr></thead><tbody>';
                g.items.forEach(function (it) {
                    h += '<tr><td class="fw-semibold">' + esc(it.name) + '</td>'
                        + '<td class="text-center">' + (it.employees || 0).toLocaleString('en-US') + '</td>'
                        + '<td class="text-end fw-semibold">' + peso(it.total) + '</td></tr>';
                });
                h += '</tbody></table></div>';
            });

            if (!(res.groups || []).length) {
                h += '<div class="text-center text-muted py-4">No contributions, deductions, loans or refunds configured for this payroll.</div>';
            }

            h += '<div class="table-responsive mt-3"><table class="table table-sm table-bordered align-middle mb-0"><tbody>'
                + '<tr class="rm-grand"><td>Total Deductions (contributions + deductions + loans)</td>'
                + '<td class="text-end" style="width:130px;">' + peso(res.ded_total) + '</td></tr>'
                + '<tr class="rm-grand"><td>Total Refunds</td><td class="text-end">' + peso(res.ref_total) + '</td></tr>'
                + (res.extra_add > 0
                    ? '<tr class="rm-grand"><td>Total One-off Allowances (paid out, not remitted)</td>'
                      + '<td class="text-end">' + peso(res.extra_add) + '</td></tr>'
                    : '')
                + '</tbody></table></div>'
                + '<small class="text-muted" style="font-size:11px;">Rebuilt from the saved payroll figures each time this opens.</small>';
            box.innerHTML = h;
        }
    });
}
</script>

<script>
var _vhData = [];
var _vhFilter = 'all';
var _vhSearch = '';

var actionMeta = {
    created:    { color:'#6642aa', bg:'#eeeaf5', icon:'ri-add-circle-line',       label:'Created'    },
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
                + '<button class="btn btn-sm" style="background:#6642aa;color:#fff;font-weight:600;border:none;" onclick="openPayrollHistory(' + Number(id) + ')">'
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
    // A row an admin deliberately unlocked stays editable whatever its review
    // mark says. The green freeze is a guard rail against stray edits to
    // verified rows; an explicit unlock is the admin overriding that on purpose
    // (and an employee re-confirming re-locks the row anyway).
    if (tr.dataset.unlocked === '1') locked = false;
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
// Single payslip → dompdf PDF in the shared PDF modal. Server-side PDF cache
// makes repeat views instant; only the first view after a data change renders.
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

<!-- Sites modal removed — payroll always covers all active sites. -->
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
        // Columns are No. (1) then Name (2) — the checkbox column was removed.
        // Name is the sticky one and sits right after No.
        // (tfoot's No.+Name cells are merged into one .tfoot-total-cell at
        // left:0 via CSS, so tfoot is intentionally excluded here.)
        const col1Ref = table.querySelector('tbody tr td:nth-child(1)')
                     || table.querySelector('thead tr:first-child th:nth-child(1)');
        if (!col1Ref) return;
        const col1W = col1Ref.getBoundingClientRect().width;
        table.querySelectorAll(
            'tbody td:nth-child(2), thead tr:first-child th:nth-child(2)'
        ).forEach(el => { el.style.left = col1W + 'px'; });
    }

    document.addEventListener('DOMContentLoaded', () => {
        fitTableToViewport();
        fixStickyHeaderGap();
        fixFrozenColumns();

        // Pull gross + deduction totals from tfoot into the summary strip.
        // Both sides are guarded — the strip is commented out in the table
        // preview, and an unguarded lookup here threw and aborted this handler.
        var gross    = document.getElementById('tfoot-gross');
        var deduct   = document.getElementById('tfoot-deduct');
        var sGross   = document.getElementById('stat-gross');
        var sDeduct  = document.getElementById('stat-deduct');
        if (gross  && sGross)  sGross.textContent  = '₱ ' + gross.textContent.trim();
        if (deduct && sDeduct) sDeduct.textContent = '₱ ' + deduct.textContent.trim();

        // ── Search / department / anomaly-flag filtering ──
        var payFilter = { q: '', dept: '', pos: '', rate: '', chip: '' };
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
                var days = parseInt(tr.getAttribute('data-days') || '0', 10);
                if (!isNaN(net)) {
                    if (net === 0) payAnom.zero.push(tr);
                    if (net < 0) payAnom.negative.push(tr);
                    // Fixed-rate (salaried, no attendance) employees are paid with 0 days
                    // by design — don't flag them as an anomaly.
                    if (net > 0 && days === 0 && tr.getAttribute('data-rate-type') !== 'fixed') payAnom.noatt.push(tr);
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
                var okP = !payFilter.pos || tr.getAttribute('data-pos') === payFilter.pos;
                var okR = !payFilter.rate || tr.getAttribute('data-rate-type') === payFilter.rate;
                var okC = !payFilter.chip || payAnom[payFilter.chip].indexOf(tr) !== -1;
                var show = okQ && okD && okP && okR && okC;
                tr.style.display = show ? '' : 'none';
                tr.classList.toggle('pay-row-hit', show && !!payFilter.chip);
                if (show) shown++;
            });
            var counter = document.getElementById('pay-filter-count');
            if (counter) counter.textContent = (payFilter.q || payFilter.dept || payFilter.pos || payFilter.rate || payFilter.chip)
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
            document.getElementById('pay-pos-filter').addEventListener('change', function () {
                payFilter.pos = this.value;
                payApplyFilter();
            });
            document.getElementById('pay-rate-filter').addEventListener('change', function () {
                payFilter.rate = this.value;
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

<style>
/* ════════════════════════════════════════════════════════════════════
   Payroll document workbench (pcw-*) — layout mirrors dtr-documents.php
   ════════════════════════════════════════════════════════════════════ */
html, body { height:100%; }
body { margin:0; background:#f0eff2; font-family:'Segoe UI', system-ui, Arial, sans-serif; overflow:hidden; }
.pcw-app { display:flex; flex-direction:column; gap:12px; height:100vh; padding:12px 16px; }
/* Loading overlay + content-gating CSS lives in the critical <style> in <head>
   so it applies before first paint — see that block. */
.pcw-header { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
  padding:10px 14px; background:#fff; border:1px solid #e1dfdd; border-radius:12px; box-shadow:0 1px 6px rgba(0,0,0,.06); }
.pcw-h-left { display:flex; align-items:center; gap:12px; min-width:0; }
.pcw-back-btn { display:inline-flex; align-items:center; gap:6px; flex-shrink:0; padding:7px 14px; border-radius:8px;
  font-size:12.5px; font-weight:700; color:#0e6b37; background:#eef7f0; border:1px solid #cfe9d6; text-decoration:none; }
.pcw-back-btn:hover { background:#ddeee3; color:#0e6b37; }
.pcw-title-icon { width:38px; height:38px; border-radius:11px; flex-shrink:0; background:linear-gradient(135deg,#107c41,#2ea867);
  color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 3px 8px rgba(16,124,65,.3); }
.pcw-h-title { font-size:15px; font-weight:800; color:#363242; display:flex; align-items:center; gap:8px; }
.pcw-status-badge { font-size:10.5px; font-weight:800; padding:3px 11px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; }
.pst-open { background:#eafaf0; color:#107c41; border:1px solid #b7e4c7; }
.pst-rev  { background:#e3f2fd; color:#1565c0; border:1px solid #a8cff5; }
.pst-lock { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.pcw-meta-chips { display:flex; flex-wrap:wrap; gap:5px; margin-top:4px; }
.pcw-meta-chip { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:600; color:#0e6b37;
  background:#eef7f0; border:1px solid #cfe9d6; border-radius:20px; padding:2px 9px; }
.pcw-meta-chip i { color:#107c41; font-size:12px; }
.pcw-h-actions { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
.pcw-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 13px; border-radius:8px; font-size:12px; font-weight:700;
  cursor:pointer; color:#0e6b37; background:#eef7f0; border:1px solid #cfe9d6; transition:background .12s; text-decoration:none; }
.pcw-btn:hover:not(:disabled) { background:#ddeee3; color:#0e6b37; }
.pcw-btn:disabled { opacity:.45; cursor:not-allowed; }
.pcw-btn.primary { background:#107c41; border-color:#0e6b37; color:#fff; }
.pcw-btn.primary:hover:not(:disabled) { background:#0e6b37; color:#fff; }
.pcw-btn.good { background:#d9eedd; border-color:#b8d8c2; color:#0b5e31; }
.pcw-btn.danger { background:#fdecea; border-color:#f5c6cb; color:#c62828; }
.pcw-btn.danger:hover:not(:disabled) { background:#fadbd8; color:#c62828; }
.pcw-wrap { flex:1; min-height:0; display:grid; grid-template-columns:345px minmax(0,1fr) 335px; gap:13px; }
.pcw-panel { background:#fff; border:1px solid #e1dfdd; border-radius:12px; box-shadow:0 1px 4px rgba(0,0,0,.05);
  overflow:hidden; display:flex; flex-direction:column; min-height:0; }
.pcw-panel-head { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:8px; padding:9px 13px;
  border-bottom:1px solid #eef2f0; background:#f4faf5; font-size:12px; font-weight:800; color:#0e6b37; }
.pcw-panel-head i { color:#107c41; }
#pcw-total { font-weight:600; color:#827d91; font-size:10.5px; }
/* ── Collapsible right-column panels ──────────────────────────────────────
   The head stays put and only the body scrolls; clicking the head folds the
   panel away so a long batch can be given the whole column. */
.pcw-panel-head.pcw-ch { cursor:pointer; user-select:none; }
.pcw-panel-head.pcw-ch:hover { background:#eaf6ee; }
.pcw-head-right { display:inline-flex; align-items:center; gap:6px; }
.pcw-collapse-ic { font-size:15px; transition:transform .15s; }
.pcw-panel.collapsed > :not(.pcw-panel-head) { display:none; }
.pcw-panel.collapsed { flex:0 0 auto !important; }
.pcw-panel.collapsed .pcw-collapse-ic { transform:rotate(-90deg); }
.pcw-head-badge { display:inline-flex; align-items:center; gap:3px; font-size:9.5px; font-weight:800;
  padding:1px 7px; border-radius:10px; border:1px solid; letter-spacing:.2px; }
.pcw-head-badge.rev    { background:#e8f1fb; color:#2c62c0; border-color:#bcd3f5; }
.pcw-head-badge.lock   { background:#e6f7ee; color:#178a4e; border-color:#b7e4c7; }
.pcw-head-badge.unread { background:#eef2fd; color:#3557b7; border-color:#ccd9f7; }
.pcw-head-badge.unread i { color:#3557b7; font-size:11px; }

/* ── Left: employee previews ── */
.pcw-left { min-height:0; }
.pcw-search { flex-shrink:0; padding:9px 11px 7px; position:relative; }
.pcw-search-wrap { display:flex; align-items:center; gap:7px; border:1px solid #cfe9d6; border-radius:8px; background:#fff; padding:6px 10px; }
.pcw-search-wrap:focus-within { border-color:#107c41; box-shadow:0 0 0 2px rgba(16,124,65,.14); }
.pcw-search-wrap > i { color:#107c41; font-size:14px; }
.pcw-search-wrap input { border:none; outline:none; flex:1; font-size:12px; min-width:0; background:transparent; }
.pcw-select { width:100%; border:1px solid #cfe9d6; border-radius:8px; font-size:11.5px; padding:5px 8px; color:#3c3846; background:#fff; outline:none; cursor:pointer; }
/* Filter button + popover (mirrors dtr-documents.php's filter UI) */
.pcw-filter-btn { position:relative; display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
  flex-shrink:0; border-radius:7px; cursor:pointer; color:#0e6b37; background:#eef7f0; border:1px solid #cfe9d6; transition:background .12s; }
.pcw-filter-btn:hover { background:#ddeee3; }
.pcw-filter-btn.on { background:#d9eedd; border-color:#b8d8c2; }
.pcw-filter-count { position:absolute; top:-5px; right:-5px; min-width:14px; height:14px; padding:0 3px; border-radius:8px;
  background:#c62828; color:#fff; font-size:8.5px; font-weight:800; display:flex; align-items:center; justify-content:center; }
.pcw-filter-pop { display:none; position:absolute; left:11px; right:11px; top:calc(100% + 2px); z-index:40;
  background:#fff; border:1px solid #e1dfdd; border-radius:12px; padding:11px; box-shadow:0 10px 30px rgba(58,40,93,.18); }
.pcw-filter-pop.open { display:block; }
.pcw-fp-head { display:flex; justify-content:space-between; align-items:center; font-size:11.5px; font-weight:800; color:#0e6b37; }
.pcw-fp-head i { color:#107c41; }
.pcw-fp-reset { display:inline-flex; align-items:center; gap:3px; border:none; background:transparent; color:#c62828;
  font-size:10px; font-weight:700; cursor:pointer; padding:2px 5px; border-radius:6px; }
.pcw-fp-reset:hover { background:#fdf4f3; }
.pcw-fp-lbl { font-size:9px; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:#948ea5; margin:10px 0 4px; }
.pcw-rv-chips { display:flex; flex-wrap:wrap; gap:4px; }
.pcw-rv-chips button { display:inline-flex; align-items:center; gap:3px; padding:3px 9px; border-radius:20px; font-size:10px;
  font-weight:700; cursor:pointer; color:#5b6f62; background:#fff; border:1px solid #d5e6da; transition:all .12s; }
.pcw-rv-chips button i { font-size:11px; }
.pcw-rv-chips button:hover:not(.on) { background:#f2faf5; }
.pcw-rv-chips button.on { background:#e6f5ec; border-color:#b8d8c2; color:#0e6b37; box-shadow:0 0 0 1px #b8d8c2 inset; }
.pcw-list { flex:1; overflow-y:auto; padding:5px 9px 9px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
.pcw-item { display:flex; align-items:center; gap:10px; width:100%; padding:8px 10px; margin-bottom:5px; text-align:left;
  background:#fff; border:1px solid #e8eeeb; border-radius:10px; border-left:3px solid transparent; cursor:pointer;
  transition:background .12s, border-color .12s, box-shadow .12s; }
.pcw-item:hover { background:#f4fbf6; border-color:#c9e5d0; }
.pcw-item.active { background:#eaf6ef; border-color:#107c41; box-shadow:0 0 0 1px #107c41 inset; }
/* Left accent + subtle tint by review mark */
.pcw-item.rv-ok    { border-left-color:#33a466; }
.pcw-item.rv-issue { border-left-color:#e0653f; }
.pcw-item.rv-chk   { border-left-color:#3f7fe0; }
.pcw-item.rv-ok:not(.active)    { background:#f4fcf7; }
.pcw-item.rv-issue:not(.active) { background:#fef6f2; }
.pcw-item.rv-chk:not(.active)   { background:#f4f8fe; }
.pcw-item input[type=checkbox] { width:15px; height:15px; accent-color:#107c41; cursor:pointer; flex-shrink:0; }
.pcw-item-avwrap { position:relative; flex-shrink:0; }
.pcw-item-av { width:34px; height:34px; border-radius:10px; background:#d9eedd; color:#0b5e31; border:1px solid #c0e0c8;
  display:flex; align-items:center; justify-content:center; font-size:11.5px; font-weight:800; }
.pcw-item-av.rv-1 { background:#d9f2e2; border-color:#63c584; }
.pcw-item-av.rv-2 { background:#fdecd7; border-color:#f4ad60; }
.pcw-item-av.rv-3 { background:#ddebfa; border-color:#74ace6; }
/* Corner status badge on the avatar (lock / warning / spinner) */
.pcw-rv-ic { position:absolute; right:-5px; bottom:-5px; width:18px; height:18px; border-radius:50%;
  display:flex; align-items:center; justify-content:center; color:#fff; border:2px solid #fff; box-shadow:0 1px 2px rgba(0,0,0,.2); }
.pcw-rv-ic i { font-size:9.5px; line-height:1; }
.pcw-rv-ic.rv-ok    { background:#33a466; }
.pcw-rv-ic.rv-issue { background:#e0653f; }
.pcw-rv-ic.rv-chk   { background:#3f7fe0; }
.pcw-rv-ic.rv-chk i { animation:pcw-spin 1.4s linear infinite; }
.pcw-item-main { flex:1; min-width:0; }
.pcw-item-name { font-size:12px; font-weight:700; color:#3c3846; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pcw-item-sub { font-size:10px; color:#948ea5; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pcw-item-tags { margin-top:3px; }
.pcw-rv-tag { display:inline-flex; align-items:center; gap:3px; font-size:9px; font-weight:800; letter-spacing:.2px;
  padding:1px 7px; border-radius:20px; border:1px solid; }
.pcw-rv-tag i { font-size:10px; }
.pcw-rv-tag.rv-ok    { background:#e6f7ee; color:#178a4e; border-color:#b7e4c7; }
.pcw-rv-tag.rv-issue { background:#fdece5; color:#c0491f; border-color:#f5c6b3; }
.pcw-rv-tag.rv-chk   { background:#e8f0fd; color:#2c62c0; border-color:#bcd3f5; }
/* Activity badges on a card: review requests sent, messages, notes */
.pcw-tag-sent { display:inline-flex; align-items:center; gap:3px; margin-left:4px; font-size:9px; font-weight:800;
  padding:1px 7px; border-radius:20px; background:#eef2fd; color:#3557b7; border:1px solid #ccd9f7; }
.pcw-tag-sent i { font-size:9.5px; }
.pcw-tag-cnt { display:inline-flex; align-items:center; gap:3px; margin-left:4px; font-size:9px; font-weight:800;
  padding:1px 6px; border-radius:20px; border:1px solid; }
.pcw-tag-cnt i { font-size:9.5px; }
.pcw-tag-cnt.msg  { background:#fff6e2; color:#a9700a; border-color:#f2dfae; }
.pcw-tag-cnt.note { background:#f2f0fb; color:#6b4fc4; border-color:#ded7f5; }
/* Row reopened for correction while the batch is out for review */
.pcw-tag-open { display:inline-flex; align-items:center; gap:3px; margin-left:4px; font-size:9px; font-weight:800;
  padding:1px 7px; border-radius:20px; background:#fff8e1; color:#8a6100; border:1px solid #ffe082; }
.pcw-tag-open i { font-size:9.5px; }
/* Chat button on a card — the employee left a message with their sign-off */
.pcw-item-msg { display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; padding:0;
  border-radius:6px; cursor:pointer; font-size:11px; background:#eafaf0; border:1px solid #b7e4c7; color:#107c41; }
.pcw-item-msg.disp { background:#fdecea; border-color:#f5c6cb; color:#c62828; }
.pcw-item-msg:hover { background:#107c41; border-color:#107c41; color:#fff; }
.pcw-item-msg.disp:hover { background:#c62828; border-color:#c62828; color:#fff; }
/* Unread sign-off message — blue dot in the corner until someone opens it */
.pcw-item-msg.new { position:relative; }
.pcw-item-msg.new::after { content:''; position:absolute; top:-3px; right:-3px; width:7px; height:7px;
  border-radius:50%; background:#3557b7; border:1.5px solid #fff; }

/* ── Bulk action panel (shown while employees are ticked) ── */
.pcw-bulk { display:none; flex-shrink:0; border-top:1px solid #e1e8e4; background:#f7fbf8; padding:9px 11px 10px; }
.pcw-bulk.show { display:block; animation:pcw-bulk-in .18s ease; }
@keyframes pcw-bulk-in { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
.pcw-bulk-head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.pcw-bulk-n { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:800; color:#0e6b37; }
.pcw-bulk-x { border:none; background:transparent; color:#948ea5; cursor:pointer; font-size:15px; line-height:1; padding:2px 4px; }
.pcw-bulk-x:hover { color:#c62828; }
.pcw-bulk-lbl { font-size:8.5px; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:#948ea5; margin:8px 0 4px; }
.pcw-bulk-rv { display:grid; grid-template-columns:1fr 1fr; gap:4px; }
.pcw-bulk-rv button { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:5px 6px; border-radius:7px;
  font-size:10px; font-weight:800; cursor:pointer; border:1px solid; background:#fff; transition:filter .12s; }
.pcw-bulk-rv button:hover { filter:brightness(.96); }
.pcw-bulk-rv button i { font-size:11px; }
.pcw-bulk-rv .rv-ok    { color:#178a4e; border-color:#b7e4c7; background:#f0fbf4; }
.pcw-bulk-rv .rv-issue { color:#c0491f; border-color:#f5c6b3; background:#fdf4ef; }
.pcw-bulk-rv .rv-chk   { color:#2c62c0; border-color:#bcd3f5; background:#f1f6fe; }
.pcw-bulk-rv .rv-none  { color:#6b7a74; border-color:#dbe3de; background:#f7f9f8; }
.pcw-bulk-act { display:flex; gap:5px; flex-wrap:wrap; }
.pcw-bulk-act .pcw-btn { flex:1 1 0; justify-content:center; padding:6px 8px; font-size:10.5px; white-space:nowrap; }
/* Progress while a bulk action runs */
.pcw-bulk-prog { display:none; margin-top:9px; }
.pcw-bulk-prog.show { display:block; }
.pcw-bulk-prog-lbl { display:flex; justify-content:space-between; font-size:9.5px; font-weight:700; color:#5b6f62; margin-bottom:3px; }
.pcw-bulk-bar { height:6px; border-radius:5px; background:#e4ece7; overflow:hidden; }
.pcw-bulk-bar > div { height:100%; width:0; border-radius:5px; background:linear-gradient(90deg,#107c41,#4fc07d); transition:width .25s ease; }
.pcw-selall-hint { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; color:#5b6f62; }

.pcw-item-right { text-align:right; flex-shrink:0; }
.pcw-item-net { font-size:11.5px; font-weight:800; color:#1e50a0; font-variant-numeric:tabular-nums; }
.pcw-item-net.neg { color:#c62828; }
.pcw-item-flags { display:flex; gap:3px; justify-content:flex-end; margin-top:2px; min-height:7px; }
.pcw-fdot { width:7px; height:7px; border-radius:50%; }
.pcw-fdot.abs { background:#c98a00; } .pcw-fdot.lt { background:#e2574c; } .pcw-fdot.neg { background:#c62828; }
.pcw-fdot.disp { background:#7c3aed; } .pcw-fdot.ok { background:#63c584; }
.pcw-list-empty { text-align:center; color:#948ea5; font-size:12px; padding:26px 8px; }
.pcw-list-foot { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:6px; padding:7px 11px;
  border-top:1px solid #eef2f0; background:#fafdfb; }
.pcw-selall { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; color:#5b6f62; margin:0; cursor:pointer; }
.pcw-selall input { accent-color:#107c41; }
.pcw-count-pill { background:#c8e6d2; color:#0b5e31; border-radius:10px; padding:0 7px; font-size:10px; font-weight:800; }

/* ── Center: paper ── */
.pcw-center { min-width:0; min-height:0; display:flex; flex-direction:column; }
.pcw-doc-toolbar { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; max-width:820px; margin:0 auto 9px; width:100%; }
.pcw-doc-nav { display:flex; align-items:center; gap:6px; }
.pcw-doc-pos { font-size:11px; color:#827d91; font-weight:600; }
.pcw-doc-zoom { display:flex; align-items:center; gap:5px; }
.pcw-pg-btn { width:27px; height:27px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #cfe9d6;
  background:#fff; color:#0e6b37; border-radius:7px; font-size:14px; cursor:pointer; }
.pcw-pg-btn:hover:not(:disabled) { background:#eef7f0; }
.pcw-pg-btn:disabled { opacity:.35; cursor:not-allowed; }
.pcw-zoom-val { min-width:48px; height:27px; padding:0 7px; border-radius:7px; cursor:pointer; border:1px solid #cfe9d6; background:#fff; color:#0e6b37; font-size:11px; font-weight:700; }
.pcw-paper-scroll { flex:1; overflow:auto; min-height:0; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) transparent; padding-bottom:10px; }
/* grooveless bar over the paper backdrop — thumb only (see theme.css .sb-ghost) */
.pcw-paper-scroll::-webkit-scrollbar-track,
.pcw-paper-scroll::-webkit-scrollbar-corner { background:transparent; }
.pcw-paper-scroll::-webkit-scrollbar-thumb { border-color:transparent; }
.pcw-paper { background:#fffefb; width:100%; max-width:820px; margin:0 auto; border:1px solid #dcd8cc; border-radius:2px;
  box-shadow:0 2px 14px rgba(60,55,40,.14); padding:30px 36px 26px; font-family:'Times New Roman', Times, serif; color:#1a1a1a; zoom:var(--pcw-zoom, 1); }
.pcw-doc-empty { text-align:center; color:#948ea5; font-size:13px; padding:60px 10px; font-family:'Segoe UI', system-ui, sans-serif; }

/* Paper internals (pp-*) — categorized computation form */
.pp-head { text-align:center; border-bottom:2.5px solid #1a1a1a; padding-bottom:10px; }
.pp-head h1 { font-size:19px; margin:0; letter-spacing:2.5px; font-weight:700; }
.pp-head .pp-sub { font-size:11.5px; color:#444; margin-top:3px; letter-spacing:.4px; }
.pp-emp-grid { display:grid; grid-template-columns:1fr 1fr; gap:3px 26px; margin:13px 0 4px; font-size:12.5px; }
.pp-emp-grid .lbl { color:#666; font-size:10px; letter-spacing:.7px; text-transform:uppercase; }
.pp-emp-grid .val { font-weight:700; border-bottom:1px dotted #999; padding-bottom:1px; min-height:17px; }
.pp-sec { margin-top:15px; }
.pp-sec-title { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase;
  border-bottom:1.5px solid #1a1a1a; padding-bottom:3px; margin-bottom:6px; }
.pp-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.pp-table td { padding:3px 6px; border-bottom:1px solid #eceae2; }
.pp-table td.qty { text-align:center; color:#555; width:150px; font-size:11.5px; }
.pp-table td.amt { text-align:right; width:120px; font-variant-numeric:tabular-nums; font-weight:600; }
.pp-table td.amt.neg { color:#a02020; }
.pp-table tr.subgroup td { border-bottom:none; padding-top:8px; font-size:10px; letter-spacing:1.2px; text-transform:uppercase; color:#666; font-weight:700; }
.pp-table tr.subtotal td { border-top:1px solid #c9c5b8; font-weight:700; }
.pp-table tr.totalrow td { border-top:2px solid #1a1a1a; border-bottom:3px double #1a1a1a; font-weight:800; font-size:13px; }
.pp-note { font-size:11px; color:#666; font-style:italic; margin-top:3px; }
/* One-off items: inline edit / remove, and the add button */
.pp-x-act { margin-left:7px; white-space:nowrap; opacity:0; transition:opacity .12s; }
.pp-table tr:hover .pp-x-act { opacity:1; }
.pp-x-btn { border:none; background:transparent; cursor:pointer; color:#948ea5; font-size:12px; padding:0 3px; }
.pp-x-btn:hover { color:#107c41; }
.pp-x-btn.del:hover { color:#c62828; }
.pp-x-add { display:inline-flex; align-items:center; gap:4px; border:1px dashed #b8d8c2; background:#f7fbf8;
  color:#0e6b37; border-radius:7px; padding:3px 10px; font-size:11px; font-weight:700; cursor:pointer;
  font-family:system-ui, -apple-system, "Segoe UI", sans-serif; }
.pp-x-add:hover { background:#eaf6ef; border-style:solid; }
@media print { .pp-x-act, .pp-x-add { display:none !important; } }
.pp-net { margin-top:17px; display:flex; align-items:center; justify-content:space-between; border:2px solid #1a1a1a; padding:9px 14px; background:#f7f5ec; }
.pp-net .lbl { font-size:12px; font-weight:700; letter-spacing:2px; }
.pp-net .amt { font-size:21px; font-weight:800; font-variant-numeric:tabular-nums; }
.pp-net .amt.neg { color:#a02020; }
.pp-delta { font-family:'Segoe UI', sans-serif; font-size:10px; font-weight:700; margin-left:10px; padding:1px 8px; border-radius:9px; vertical-align:middle; }
.pp-delta.up { background:#eafaf0; color:#107c41; border:1px solid #b7e4c7; }
.pp-delta.down { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.pp-delta.flat { background:#f1f3f4; color:#777; border:1px solid #ddd; }
.pp-delta.new { background:#e3f2fd; color:#1565c0; border:1px solid #b7d5f5; }
.pp-chips { display:flex; gap:5px; flex-wrap:wrap; margin-top:10px; font-family:'Segoe UI', sans-serif; }
.pp-chip { font-size:9.5px; font-weight:700; padding:2px 9px; border-radius:10px; border:1px solid; }
.pp-chip.g { background:#eafaf0; color:#107c41; border-color:#b7e4c7; }
.pp-chip.o { background:#fff8e1; color:#c98a00; border-color:#ffe082; }
.pp-chip.r { background:#fdecea; color:#c62828; border-color:#f5c6cb; }
.pp-chip.b { background:#e3f2fd; color:#1565c0; border-color:#b7d5f5; }
/* Editable figures on the sheet: dashed underline inputs that write through
   to the (hidden) payroll table's inputs, so the existing save pipeline and
   server-side recompute stay untouched. */
.pp-in { font-family:inherit; font-size:12.5px; font-weight:700; color:#0b5e31; background:#fbfff6;
  border:none; border-bottom:1.5px dashed #9ab8a5; outline:none; padding:0 3px; width:64px; text-align:center; border-radius:2px 2px 0 0; }
.pp-in:focus { border-bottom-color:#107c41; background:#eef8f1; }
.pp-in.amt-in { text-align:right; width:92px; }
.pp-in.txt-in { text-align:left; width:100%; max-width:340px; font-weight:600; }
.pp-edit-hint { font-family:'Segoe UI', sans-serif; font-size:10px; font-weight:600; color:#0e6b37;
  background:#eef7f0; border:1px solid #cfe9d6; border-radius:8px; padding:4px 10px; margin-top:10px; display:inline-flex; align-items:center; gap:5px; }

.pp-sign { display:grid; grid-template-columns:1fr 1fr 1fr; gap:26px; margin-top:34px; }
.pp-sign > div { text-align:center; font-size:10.5px; color:#444; }
.pp-sign .line { border-top:1px solid #1a1a1a; margin-bottom:3px; padding-top:3px; font-weight:700; font-size:11px; color:#1a1a1a; min-height:18px; }

/* ── Right: summary + batch ── */
.pcw-right { min-height:0; display:flex; flex-direction:column; gap:12px; }
.pcw-right .pcw-panel.grow { flex:1; min-height:0; }
/* Employee Review shares Batch Insights' shell: fixed head, body-only scroll. */
.pcw-rv-panel { flex:0 1 auto; min-height:0; max-height:42%; }
.pcw-rv-body { flex:1; min-height:0; overflow-y:auto; padding:10px 13px;
  scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
/* The body is the only scroller — the inner lists must not scroll inside it. */
.pcw-rv-body .prp-disputes.is-scroll,
.pcw-rv-body .prp-confirms.is-scroll { max-height:none; overflow:visible; padding-right:0; }
.pcw-sum-body { padding:11px 13px; max-height:42vh; overflow-y:auto; overflow-x:hidden; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
/* ── Batch Insights panel ── */
.pcw-ins-body { flex:1; min-height:0; overflow-y:auto; padding:11px 13px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
.pcw-ins-sec { font-size:9px; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:#948ea5; margin:2px 0 6px; }
.pcw-ins-sec:not(:first-child) { margin-top:15px; }
.pcw-ins-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:6px; }
.pcw-ins-tile { border:1px solid #e8eeeb; border-radius:8px; padding:7px 9px; background:#fafdfb; }
.pcw-ins-tile .v { font-size:14px; font-weight:800; line-height:1.1; font-variant-numeric:tabular-nums; color:#3c3846; }
.pcw-ins-tile .l { font-size:8.5px; color:#948ea5; text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }
.pcw-ins-tile.net .v { color:#1e50a0; } .pcw-ins-tile.ded .v { color:#c62828; } .pcw-ins-tile.gross .v { color:#107c41; }
/* Review progress bar */
.pcw-ins-bar { display:flex; height:10px; border-radius:6px; overflow:hidden; background:#eef2f0; border:1px solid #e1e6e3; }
.pcw-ins-bar > span { display:block; height:100%; }
.pcw-ins-bar .b-ok { background:#33a466; } .pcw-ins-bar .b-issue { background:#e0653f; }
.pcw-ins-bar .b-chk { background:#3f7fe0; } .pcw-ins-bar .b-none { background:#d3ddd7; }
.pcw-ins-legend { display:flex; flex-wrap:wrap; gap:4px 10px; margin-top:7px; }
.pcw-ins-lg { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:600; color:#5b6f62; }
.pcw-ins-lg .dot { width:8px; height:8px; border-radius:50%; }
.pcw-ins-lg .dot.ok { background:#33a466; } .pcw-ins-lg .dot.issue { background:#e0653f; }
.pcw-ins-lg .dot.chk { background:#3f7fe0; } .pcw-ins-lg .dot.none { background:#c3cec8; }
.pcw-ins-pct { float:right; font-size:10px; font-weight:800; color:#178a4e; }
/* Exception chips (clickable → filter the left list) */
.pcw-exc-chips { display:flex; flex-direction:column; gap:5px; }
.pcw-exc { display:flex; align-items:center; gap:8px; width:100%; padding:7px 10px; border-radius:9px; cursor:pointer;
  border:1px solid #e8eeeb; background:#fff; text-align:left; transition:background .12s, border-color .12s; }
.pcw-exc:hover { background:#f6faf8; }
.pcw-exc.on { border-color:#107c41; box-shadow:0 0 0 1px #107c41 inset; background:#eef7f1; }
.pcw-exc .ic { width:24px; height:24px; flex-shrink:0; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:13px; }
.pcw-exc .tx { flex:1; min-width:0; font-size:11px; font-weight:700; color:#434050; }
.pcw-exc .n { font-size:12px; font-weight:800; font-variant-numeric:tabular-nums; }
.pcw-exc.danger .ic { background:#fdecea; color:#c62828; } .pcw-exc.danger .n { color:#c62828; }
.pcw-exc.warn .ic { background:#fff4e0; color:#c98a00; } .pcw-exc.warn .n { color:#c98a00; }
.pcw-exc.info .ic { background:#e8f0fd; color:#2c62c0; } .pcw-exc.info .n { color:#2c62c0; }
.pcw-exc.purple .ic { background:#f1ebfb; color:#7c3aed; } .pcw-exc.purple .n { color:#7c3aed; }
.pcw-exc.muted { opacity:.55; cursor:default; }
.pcw-exc.muted:hover { background:#fff; }
.pcw-ins-allclear { display:flex; align-items:center; gap:8px; padding:9px 11px; border-radius:9px; background:#eefaf2;
  border:1px solid #bfe6cd; color:#178a4e; font-size:11.5px; font-weight:700; }
.pcw-ins-allclear i { font-size:16px; }
.pcw-ins-mover { display:flex; align-items:center; gap:8px; padding:5px 2px; border-bottom:1px dashed #eef2f0; }
.pcw-ins-mover:last-child { border-bottom:none; }
.pcw-ins-mover .nm { flex:1; min-width:0; font-size:10.5px; font-weight:700; color:#3c3846; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pcw-ins-mover .pct { font-size:10.5px; font-weight:800; font-variant-numeric:tabular-nums; }
.pcw-ins-mover .pct.up { color:#178a4e; } .pcw-ins-mover .pct.down { color:#c62828; }
/* Name + "no. · position · department". Long department strings
   (ADMINISTRATION/FINANCE/HR/BILLING/…) are clipped to one line so the panel
   never scrolls sideways — the full text stays in the title tooltip. */
.pcw-sum-emp { font-size:12.5px; font-weight:800; color:#3c3846;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pcw-sum-sub { font-size:10.5px; color:#948ea5; margin:1px 0 9px;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pcw-sum-sent { display:flex; align-items:center; gap:5px; font-size:10.5px; font-weight:600; color:#3557b7;
  background:#eef2fd; border:1px solid #ccd9f7; border-radius:8px; padding:5px 9px; margin:-4px 0 9px; }
.pcw-sum-sent b { font-weight:800; }
.pcw-sum-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
.pcw-sum-tile { border:1px solid #e8eeeb; border-radius:8px; padding:6px 4px; background:#fafdfb; text-align:center; }
.pcw-sum-tile .v { font-size:12.5px; font-weight:800; color:#107c41; line-height:1.1; font-variant-numeric:tabular-nums; }
.pcw-sum-tile .l { font-size:8.5px; color:#948ea5; text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }
.pcw-sum-tile.ded .v { color:#c62828; } .pcw-sum-tile.net .v { color:#1e50a0; }
.pcw-sum-tile.abs .v { color:#c98a00; } .pcw-sum-tile.lt .v { color:#e2574c; }
/* ── Per-employee unlock (mid-review editing) ── */
.pcw-unlock-note { display:flex; gap:7px; align-items:flex-start; margin:9px 0 0; padding:7px 9px;
  background:#fff8e1; border:1px solid #ffe082; border-radius:8px; font-size:11px; color:#8a6100; line-height:1.4; }
.pcw-unlock-note i { font-size:14px; color:#c98a00; flex-shrink:0; margin-top:1px; }
.pcw-unlock-note .why { font-style:italic; opacity:.85; }
.pcw-btn.warn { background:#fff8e1; border-color:#ffe082; color:#8a6100; }
.pcw-btn.warn:hover { background:#ffefc2; }
.pcw-unlock-flag { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700;
  padding:3px 10px; border-radius:20px; background:#fff8e1; border:1px solid #ffe082; color:#8a6100; }
.pcw-sum-actions { display:flex; flex-wrap:wrap; gap:5px; margin-top:10px; }
.pcw-sum-actions .pcw-btn { padding:5px 10px; font-size:11px; flex:1 1 45%; justify-content:center; white-space:nowrap; }
/* Internal admin notes — read-only list, same content and style as
   dtr-documents.php's notes panel (no add form here). */
.ddv-notes { margin-top:11px; border-top:1px dashed #e7e6ed; padding-top:9px; }
.ddv-notes-hd { display:flex; align-items:center; gap:5px; font-size:10.5px; font-weight:800; color:#635f73; margin-bottom:6px; }
.ddv-notes-hd .lock { font-size:9px; font-weight:800; color:#948ea5; background:#eef2f0; border-radius:10px; padding:1px 6px; }
.ddv-note { display:flex; gap:7px; align-items:flex-start; padding:6px 8px; border-radius:8px; margin-bottom:5px; border:1px solid; }
.ddv-note .nt { flex:1; min-width:0; font-size:11px; font-weight:600; color:#434050; line-height:1.35; word-break:break-word; }
.ddv-note .nm { display:block; font-size:8.5px; color:#9d9baa; margin-top:2px; font-weight:600; }
.ddv-note.info     { background:#eef4fd; border-color:#c9def7; }
.ddv-note.good     { background:#eefaf2; border-color:#bfe6cd; }
.ddv-note.watch    { background:#fff8e6; border-color:#f2e0a6; }
.ddv-note.critical { background:#fdecec; border-color:#f3c9c9; }
.ddv-note .lv { font-size:14px; flex-shrink:0; line-height:1.2; }
.ddv-note-empty { font-size:10px; color:#a9a6b4; padding:2px 2px 6px; }

/* The per-day punch tables in the DTR modal reuse the original .al-day /
   .att-log-* styles defined at the top of this page. Logs cards sit on the
   modal's grey background, so give the day blocks a white card. */
#al-logs .al-day { background:#fff; border:1px solid #e1dfdd; border-radius:8px; padding:9px 11px; }

/* Fixed modal height so switching tabs never resizes the dialog — the active
   pane scrolls inside a constant-height body instead. */
#modal-att-logs .modal-dialog { max-width:840px; }
#modal-att-logs .modal-content { height:min(86vh, 900px); min-height:520px; }

/* ── DTR modal footer — period totals beside the Close button ── */
#modal-att-logs .al-foot { justify-content:flex-start; gap:8px; padding-left:14px; padding-right:14px;
  border-top:1px solid #e1dfdd; background:#f7faf8; }
.al-foot-stats { display:flex; flex-wrap:wrap; align-items:center; gap:5px; min-width:0; }
.al-fs { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; line-height:1.35;
  font-size:10.5px; font-weight:700; color:#5b6f62; background:#fff; border:1px solid #e1e8e4; }
.al-fs i { font-size:12px; color:#107c41; }
.al-fs b { font-weight:800; color:#3c3846; font-variant-numeric:tabular-nums; }
.al-fs em { font-style:normal; font-weight:700; font-size:9px; letter-spacing:.3px; text-transform:uppercase; color:#948ea5; }
.al-fs.days i, .al-fs.days b { color:#673bb6; }
.al-fs.ot   i, .al-fs.ot   b { color:#c98a00; }
.al-fs.ut   i, .al-fs.ut   b { color:#1565c0; }
.al-fs.late i, .al-fs.late b { color:#c62828; }
.al-fs.zero { opacity:.55; }
.al-fs.rv.ok   { background:#e6f7ee; border-color:#b7e4c7; color:#178a4e; }
.al-fs.rv.ok i { color:#178a4e; }
.al-fs.rv.bad   { background:#fdece5; border-color:#f5c6b3; color:#c0491f; }
.al-fs.rv.bad i { color:#c0491f; }
.al-fs.rv.wait   { background:#fff6e2; border-color:#f2dfae; color:#a9700a; }
.al-fs.rv.wait i { color:#a9700a; }

/* ── Tabs inside the Daily Time Record modal (DTR · Logs · Messages · Notes) ── */
.pcw-tabs { display:flex; gap:4px; border-bottom:2px solid #d5e6da; margin-bottom:12px; flex-wrap:wrap;
  position:sticky; top:0; z-index:5; background:#f0eff2; padding-top:2px; }
.pcw-tab { display:inline-flex; align-items:center; gap:5px; border:none; background:transparent; cursor:pointer;
  padding:8px 14px; margin-bottom:-2px; font-size:12px; font-weight:700; color:#5b6f62;
  border-bottom:2px solid transparent; border-radius:6px 6px 0 0; transition:color .12s, border-color .12s, background .12s; }
.pcw-tab i { font-size:14px; }
.pcw-tab:hover:not(.active) { background:#eef4f0; color:#0e6b37; }
.pcw-tab.active { color:#0e6b37; border-bottom-color:#107c41; }
.pcw-tab-count { font-size:9.5px; font-weight:800; line-height:1; min-width:16px; text-align:center;
  background:#d9eedd; color:#0b5e31; border-radius:9px; padding:2px 5px; }
.pcw-tab.active .pcw-tab-count { background:#107c41; color:#fff; }
.pcw-tab-pane { display:none; }
.pcw-tab-pane.active { display:block; }
.pcw-tab-empty { text-align:center; color:#948ea5; font-size:12.5px; padding:34px 12px; }
.pcw-tab-empty i { font-size:26px; display:block; margin-bottom:8px; opacity:.5; }

/* Admin ↔ employee record messages — read-only chat bubbles in the DTR modal
   (same look as dtr-documents.php's per-record conversation) */
.pcw-chat { display:flex; flex-direction:column; gap:6px; padding:2px; }
.pcw-bub { max-width:82%; padding:6px 10px; border-radius:11px; font-size:11.5px; line-height:1.4;
  word-break:break-word; position:relative; }
.pcw-bub.me   { align-self:flex-end; background:#e1dcec; color:#4f3288; border-bottom-right-radius:3px; }
.pcw-bub.them { align-self:flex-start; background:#f1f3f2; color:#3c3846; border-bottom-left-radius:3px; }
.pcw-bub .m { display:block; font-size:8.5px; opacity:.7; margin-top:3px; }
.pcw-bub-day { display:inline-block; font-size:8px; font-weight:800; letter-spacing:.4px; text-transform:uppercase;
  background:rgba(0,0,0,.06); border-radius:8px; padding:0 6px; margin-right:5px; vertical-align:1px; }

/* Paper frame for the Daily Time Record modal (Form 48 styles come from
   assets2/css/dtr-form48.css, shared with dtr-documents.php) */
.pcw-dtr-paper { background:#fffefb; border:1px solid #dcd8cc; border-radius:2px;
  box-shadow:0 2px 10px rgba(60,55,40,.12); padding:20px 24px 16px;
  font-family:'Times New Roman', Times, serif; color:#1a1a1a; }

@media (max-width:1199.98px) {
  body { overflow:auto; }
  .pcw-app { height:auto; min-height:100vh; }
  .pcw-wrap { display:flex; flex-direction:column; }
  .pcw-left { max-height:430px; }
  .pcw-center { min-height:70vh; }
  .pcw-sum-body { max-height:none; }
}

/* The classic table is a read-only PREVIEW now — its inputs stay in the DOM
   as the data store for the save pipeline, but can't be edited directly. */
#modal-table-editor .input-class,
#modal-table-editor #table-1 input.input-class {
  pointer-events:none;
  background:transparent !important;
  border:none !important;
  box-shadow:none !important;
  font-weight:700;
  text-align:right;
}

/* Full-height chain inside the fullscreen preview modal: modal body → panel →
   form → scroll container, so the table always fills the viewport. */
#modal-table-editor .modal-body { height:100vh; max-height:none; display:flex; flex-direction:column; overflow:hidden; }
#modal-table-editor .xl-panel { flex:1; min-height:0; }
#modal-table-editor .xl-panel-body { display:flex; flex-direction:column; }
#modal-table-editor .xl-panel-body > .pay-stats-strip,
#modal-table-editor .xl-panel-body > .pay-toolbar { flex-shrink:0; }
#modal-table-editor #form-payroll { flex:1; min-height:0; display:flex; flex-direction:column; }
#modal-table-editor .table-responsive2 { flex:1; min-height:0; height:auto !important; }

/* Print: only the computation sheet */
@media print {
  body { overflow:visible; }
  body * { visibility:hidden; }
  #pcw-paper, #pcw-paper * { visibility:visible; }
  #pcw-paper { position:absolute; left:0; top:0; width:100%; max-width:none; border:none; box-shadow:none; zoom:1 !important; }
}
</style>

<script>
window.PCW_DATA = <?= json_encode($pcwEmployees, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
// employee_id → their payslip sign-off (decision, message, HR reply)
window.PCW_REVIEWS = <?= json_encode($pcwReviewConvo, JSON_UNESCAPED_UNICODE) ?>;
window.PCW_META = <?= json_encode([
    'id'         => (int)$id,
    'status'     => (int)$status,
    'type'       => (int)$payroll_type,
    'from'       => date('M j, Y', strtotime($payroll['date_from'])),
    'to'         => date('M j, Y', strtotime($payroll['date_to'])),
    'from_iso'   => date('Y-m-d', strtotime($payroll['date_from'])),
    'to_iso'     => date('Y-m-d', strtotime($payroll['date_to'])),
    'log_mode'   => defined('DTR_LOG_MODE') ? DTR_LOG_MODE : 'single',
    'prev_label' => $prevPayroll ? (date('M j', strtotime($prevPayroll['date_from'])) . '–' . date('M j', strtotime($prevPayroll['date_to']))) : null,
], JSON_UNESCAPED_UNICODE) ?>;

(function () {
    var D = window.PCW_DATA || [];
    var M = window.PCW_META || {};
    // S.ps holds the payslip selection ({id: true}) — the card list is the only
    // selection UI now that the table preview has no checkbox column.
    var S = { q: '', dept: '', pos: '', rate: '', rv: '', erv: '', lock: '', exc: '', sel: null, zoom: 1, list: [], ps: {} };
    // Exception predicates — reused by the Insights chips and the left-list filter.
    function excPred(key, e) {
        switch (key) {
            case 'negnet':   return e.net <= 0;
            case 'noatt':    return e.net > 0 && e.dtr_days === 0 && e.rate_type !== 'fixed';
            case 'swing':    return e.prev_net != null && e.prev_net != 0 && Math.abs((e.net - e.prev_net) / e.prev_net) >= 0.30;
            case 'disputed': return e.emp_rv === 2;
            case 'absent':   return e.absent > 0;
            case 'late':     return e.late_min > 0;
        }
        return true;
    }
    var EXC_DEFS = [
        { k: 'negnet',   lbl: 'Zero / negative net',  cls: 'danger', ic: 'ri-error-warning-line' },
        { k: 'noatt',    lbl: 'Paid, no attendance',  cls: 'warn',   ic: 'ri-calendar-close-line' },
        { k: 'swing',    lbl: 'Net moved ≥30%',  cls: 'info',   ic: 'ri-line-chart-line', needPrev: true },
        { k: 'disputed', lbl: 'Disputed by employee', cls: 'purple', ic: 'ri-chat-off-line' },
        { k: 'absent',   lbl: 'Has absences',         cls: 'warn',   ic: 'ri-user-unfollow-line' },
        { k: 'late',     lbl: 'Has late',             cls: 'warn',   ic: 'ri-time-line' }
    ];
    var GROUPS = { 1: 'Contributions', 3: 'Loans', 2: 'Other Deductions' };
    // Review-mark meta — colour + icon shown in the left list per employee.
    var RV_META = {
        1: { cls: 'rv-ok',    ic: 'ri-lock-2-fill',        lbl: 'Verified', t: 'Okay — figures verified &amp; locked' },
        2: { cls: 'rv-issue', ic: 'ri-error-warning-fill', lbl: 'Issue',    t: 'Something wrong — needs correction' },
        3: { cls: 'rv-chk',   ic: 'ri-loader-4-line',      lbl: 'Reviewing', t: 'Ongoing review' }
    };

    var byId = function (x) { return document.getElementById(x); };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var fmt = function (n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var peso = function (n) { return '₱ ' + fmt(n); };
    var initials = function (e) { return ((e.first || ' ')[0] + (e.last || ' ')[0]).toUpperCase(); };

    function flags(e) {
        return {
            neg: e.net <= 0,
            abs: e.absent > 0,
            late: e.late_min > 0,
            disp: e.emp_rv === 2,
            issue: e.rv === 2,
            ok: e.emp_rv === 1
        };
    }

    function empById(id) {
        for (var i = 0; i < D.length; i++) if (D[i].id == id) return D[i];
        return null;
    }

    // ── Left list ──
    function buildList() {
        S.list = D.filter(function (e) {
            if (S.q && (e.name + ' ' + e.no + ' ' + e.pos).toLowerCase().indexOf(S.q) === -1) return false;
            if (S.dept && e.dept !== S.dept) return false;
            if (S.pos && e.pos !== S.pos) return false;
            if (S.rate && e.rate_type !== S.rate) return false;
            if (S.rv !== '' && String(e.rv) !== S.rv) return false;
            // Employee sign-off: '1'/'2'/'0' by decision, 'm' = left a message
            if (S.erv !== '') {
                var cv = (window.PCW_REVIEWS || {})[e.emp];
                if (S.erv === 'm') { if (!cv || !cv.c) return false; }
                else if (String(e.emp_rv || 0) !== S.erv) return false;
            }
            // Edit lock: u = reopened for correction, l = still frozen
            if (S.lock === 'u' && !e.unlocked) return false;
            if (S.lock === 'l' && e.unlocked) return false;
            if (S.exc && !excPred(S.exc, e)) return false;
            return true;
        });
        var html = '';
        S.list.forEach(function (e) {
            var f = flags(e);
            var chk = S.ps[e.id] ? { checked: true } : null;
            var dots = '';
            if (f.neg) dots += '<span class="pcw-fdot neg" title="Zero / negative net"></span>';
            if (f.abs) dots += '<span class="pcw-fdot abs" title="' + e.absent + ' absent"></span>';
            if (f.late) dots += '<span class="pcw-fdot lt" title="' + Math.round(e.late_min) + ' min late"></span>';
            if (f.disp) dots += '<span class="pcw-fdot disp" title="Disputed by employee"></span>';
            if (f.ok) dots += '<span class="pcw-fdot ok" title="Confirmed by employee"></span>';
            // Review-mark badge: colour + icon follow the mark (green lock = OK &
            // figures locked, red = issue, blue = ongoing review).
            var rvm = RV_META[e.rv];
            var rvBadge = rvm
                ? '<span class="pcw-rv-ic ' + rvm.cls + '" title="' + rvm.t + (e.rv_c ? ' — ' + esc(e.rv_c) : '') + '"><i class="' + rvm.ic + '"></i></span>'
                : '';
            var rvTag = rvm
                ? '<span class="pcw-rv-tag ' + rvm.cls + '"><i class="' + rvm.ic + '"></i>' + rvm.lbl + '</span>'
                : '';
            // Activity badges: review requests sent, messages, internal notes
            var tags = rvTag;
            if (e.sent_n > 0) {
                tags += '<span class="pcw-tag-sent" title="Review request sent ' + e.sent_n + '×'
                    + (e.sent_at ? ' — last ' + esc(e.sent_at) : '') + '"><i class="ri-send-plane-fill"></i>' + e.sent_n + '× sent</span>';
            }
            // Reopened for correction — without this the "Unlocked" filter would
            // return rows you cannot tell apart from the frozen ones.
            if (e.unlocked) {
                tags += '<span class="pcw-tag-open" title="Unlocked for editing'
                    + (e.unlock_why ? ' — ' + esc(e.unlock_why) : '') + '"><i class="ri-lock-unlock-line"></i>open</span>';
            }
            var nMsg = (e.msgs || []).length, nNote = (e.notes || []).length;
            // Attendance-record messages (calendar icon) vs the payslip sign-off
            // message (speech bubble, below) — two different conversations, so
            // they get two clearly different icons.
            if (nMsg) tags += '<span class="pcw-tag-cnt msg" title="' + nMsg + ' attendance-record message(s)"><i class="ri-calendar-2-line"></i>' + nMsg + '</span>';
            if (nNote) tags += '<span class="pcw-tag-cnt note" title="' + nNote + ' internal note(s)"><i class="ri-sticky-note-fill"></i>' + nNote + '</span>';
            // Chat button — only for employees who left a message with their
            // payslip sign-off; opens the same popup as the review panel.
            var conv = (window.PCW_REVIEWS || {})[e.emp];
            var convBtn = '';
            if (conv && conv.c) {
                var cCls = 'pcw-item-msg' + (conv.st === 2 ? ' disp' : '') + (conv.new ? ' new' : '');
                convBtn = '<button type="button" class="' + cCls + '" data-convo="' + e.emp + '" title="'
                    + (conv.new ? 'UNREAD — ' : '') + (conv.st === 2 ? 'Disputed' : 'Confirmed')
                    + ' their payslip with a message — click to read"><i class="ri-chat-3-fill"></i></button>';
            }
            html += '<div class="pcw-item' + (S.sel == e.id ? ' active' : '') + (rvm ? ' ' + rvm.cls : '') + '" data-eid="' + e.id + '">'
                + '<input type="checkbox" data-ps="' + e.id + '"' + (chk && chk.checked ? ' checked' : '') + ' title="Select this employee for bulk actions">'
                + '<div class="pcw-item-avwrap"><div class="pcw-item-av rv-' + e.rv + '">' + esc(initials(e)) + '</div>' + rvBadge + '</div>'
                + '<div class="pcw-item-main"><div class="pcw-item-name">' + esc(e.name) + '</div>'
                + '<div class="pcw-item-sub">' + esc(e.no) + (e.pos ? ' · ' + esc(e.pos) : '') + '</div>'
                + (tags ? '<div class="pcw-item-tags">' + tags + '</div>' : '')
                + '</div>'
                + '<div class="pcw-item-right"><div class="pcw-item-net' + (e.net <= 0 ? ' neg' : '') + '">' + fmt(e.net) + '</div>'
                + '<div class="pcw-item-flags">' + convBtn + dots + '</div></div>'
                + '</div>';
        });
        byId('pcw-list').innerHTML = html || '<div class="pcw-list-empty"><i class="ri-user-search-line" style="font-size:22px;opacity:.5;"></i><br>No employees match.</div>';
        byId('pcw-total').textContent = S.list.length + ' of ' + D.length;
        updNav();
    }

    function select(id) {
        var e = empById(id);
        if (!e) return;
        S.sel = e.id;
        document.querySelectorAll('.pcw-item').forEach(function (it) {
            it.classList.toggle('active', it.getAttribute('data-eid') == String(e.id));
        });
        renderPaper(e);
        renderSum(e);
        updNav();
    }

    function updNav() {
        var idx = -1;
        S.list.forEach(function (e, i) { if (e.id == S.sel) idx = i; });
        byId('pcw-pos').textContent = idx >= 0 ? (idx + 1) + ' / ' + S.list.length : '';
        byId('pcw-prev').disabled = idx <= 0;
        byId('pcw-next').disabled = idx < 0 || idx >= S.list.length - 1;
    }

    // ── Center: categorized computation sheet ──
    function deltaBadge(e) {
        if (M.prev_label == null) return '';
        if (e.prev_net == null) return '<span class="pp-delta new" title="Not in previous payroll (' + esc(M.prev_label) + ')">NEW</span>';
        if (!e.prev_net) return '';
        var pct = ((e.net - e.prev_net) / Math.abs(e.prev_net)) * 100;
        var tip = 'Previous (' + esc(M.prev_label) + '): ' + peso(e.prev_net);
        if (Math.abs(pct) < 0.5) return '<span class="pp-delta flat" title="' + tip + '">≈ same</span>';
        var cls = pct > 0 ? 'up' : 'down';
        var arr = pct > 0 ? '▲' : '▼';
        return '<span class="pp-delta ' + cls + '" title="' + tip + '">' + arr + ' ' + Math.abs(pct).toFixed(1) + '%</span>';
    }

    // The table row's matching input (the classic table stays the data store)
    function tIn(e, t, dd) {
        return document.querySelector('tr[data-row-id="' + e.id + '"] input[data-type="' + t + '"]'
            + (dd != null ? '[data-dd_id="' + dd + '"]' : ''));
    }
    // Editable dashed field when the table row still has a live input for it
    // (payroll open + row not review-locked); plain text otherwise.
    function fld(e, t, fallback, dd, cls) {
        var inp = tIn(e, t, dd);
        if (!inp || inp.readOnly) return esc(String(fallback));
        return '<input type="text" class="pp-in' + (cls ? ' ' + cls : '') + '" data-t="' + t + '"'
            + (dd != null ? ' data-dd="' + esc(String(dd)) + '"' : '') + ' value="' + esc(inp.value) + '">';
    }
    // Amount cell: editable input when possible, formatted figure otherwise
    function edAmt(e, t, amt, dd) {
        var inp = tIn(e, t, dd);
        if (!inp || inp.readOnly) return amt;
        return fld(e, t, amt, dd, 'amt-in');
    }
    function canEditRow(e) {
        var inp = document.querySelector('tr[data-row-id="' + e.id + '"] input.input-class');
        return !!inp && !inp.readOnly;
    }
    function earnRow(label, qty, amt, neg, idAttr) {
        var amtHtml = (typeof amt === 'string' && amt.charAt(0) === '<')
            ? amt
            : (neg ? '(' + fmt(amt) + ')' : fmt(amt));
        return '<tr><td>' + label + '</td><td class="qty">' + (qty || '') + '</td>'
            + '<td class="amt' + (neg ? ' neg' : '') + '"' + (idAttr ? ' id="' + idAttr + '"' : '') + '>' + amtHtml + '</td></tr>';
    }

    // ── Named one-off items on a single payslip ───────────────────────────────
    // kind 1 deducts (section C), kind 2 adds (section B). Unlimited per
    // employee, applied without recalculating the batch.
    function extrasOfKind(e, kind) {
        return (e.extras || []).filter(function (x) { return x.kind === kind; });
    }
    function extraRow(e, x, neg) {
        var editable = canEditRow(e);
        var act = editable
            ? '<span class="pp-x-act">'
              + '<button type="button" class="pp-x-btn" title="Edit this item" onclick="pcwEditExtra(' + e.id + ',' + x.id + ')"><i class="ri-pencil-line"></i></button>'
              + '<button type="button" class="pp-x-btn del" title="Remove this item" onclick="pcwDeleteExtra(' + e.id + ',' + x.id + ')"><i class="ri-delete-bin-line"></i></button>'
              + '</span>'
            : '';
        return '<tr><td>' + esc(x.label) + act + '</td><td class="qty">one-off</td>'
            + '<td class="amt' + (neg ? ' neg' : '') + '">' + (neg ? '(' + fmt(x.amt) + ')' : fmt(x.amt)) + '</td></tr>';
    }
    function addExtraRow(e, kind) {
        return '<tr><td colspan="3" style="padding-top:5px;">'
            + '<button type="button" class="pp-x-add" onclick="pcwAddExtra(' + e.id + ',' + kind + ')">'
            + '<i class="ri-add-line"></i> Add ' + (kind === 2 ? 'allowance' : 'deduction') + '</button></td></tr>';
    }

    // Add / edit share one dialog; the server upserts on the id.
    window.pcwAddExtra  = function (itemId, kind) { extraDialog(itemId, kind, null); };
    window.pcwEditExtra = function (itemId, xid) {
        var e = empById(itemId);
        var x = (e && (e.extras || []).find(function (i) { return i.id === xid; })) || null;
        if (x) extraDialog(itemId, x.kind, x);
    };
    function extraDialog(itemId, kind, existing) {
        var isAllow = kind === 2;
        Swal.fire({
            title: (existing ? 'Edit ' : 'Add ') + (isAllow ? 'allowance' : 'deduction'),
            html: '<div style="text-align:left;font-size:12.5px;color:#555;margin-bottom:8px;">'
                + 'Applies to <b>this employee only</b> and shows as its own line on their payslip.</div>'
                + '<input id="x-label" class="swal2-input" maxlength="120" placeholder="Label — e.g. Uniform, Transport allowance" value="' + (existing ? esc(existing.label) : '') + '">'
                + '<input id="x-amount" class="swal2-input" type="number" step="0.01" min="0.01" placeholder="Amount" value="' + (existing ? existing.amt : '') + '">',
            showCancelButton: true,
            confirmButtonColor: isAllow ? '#107c41' : '#c62828',
            confirmButtonText: existing ? 'Save changes' : 'Add item',
            didOpen: function () { document.getElementById('x-label').focus(); },
            preConfirm: function () {
                var label = (document.getElementById('x-label').value || '').trim();
                var amt = parseFloat(document.getElementById('x-amount').value);
                if (!label) { Swal.showValidationMessage('A label is required.'); return false; }
                if (!(amt > 0)) { Swal.showValidationMessage('Enter an amount greater than zero.'); return false; }
                return { label: label, amount: amt };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            pcwPostExtra('save_payroll_item_extra', {
                item_id: itemId, id: existing ? existing.id : 0,
                kind: kind, label: r.value.label, amount: r.value.amount
            }, itemId);
        });
    }
    window.pcwDeleteExtra = function (itemId, xid) {
        Swal.fire({
            title: 'Remove this item?', text: 'It will disappear from the payslip and the net pay recomputes.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#c62828', confirmButtonText: 'Remove'
        }).then(function (r) {
            if (r.isConfirmed) pcwPostExtra('delete_payroll_item_extra', { id: xid }, itemId);
        });
    };
    // One round trip: save, then pull the row's recomputed figures back so the
    // sheet, the card list and the batch totals all agree — without a reload.
    function pcwPostExtra(action, data, itemId) {
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        $.ajax({
            url: 'ajax.php?action=' + action, method: 'POST', dataType: 'JSON', data: data,
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); },
            success: function (res) {
                if (!res || !res.result) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed.' });
                    return;
                }
                var e = empById(itemId);
                if (e) e.extras = res.extras || [];
                // refreshPayrollRows repaints the table from server-computed
                // figures (which now include extras); syncRow pulls the row's
                // new gross / deductions / net back into the payload object.
                refreshPayrollRows(M.id).then(function () {
                    var fresh = empById(itemId);
                    if (fresh) { syncRow(fresh); renderPaper(fresh); renderSum(fresh); }
                    buildList(); renderInsights();
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success',
                        title: res.message, showConfirmButton: false, timer: 2400 });
                });
            }
        });
    }

    function renderPaper(e) {
        var isMonthlyBatch = M.type === 5;
        var monthlyRate = e.rate_type === 'monthly' || e.rate_type === 'fixed';
        var h = '';

        h += '<div class="pp-head"><h1>PAY COMPUTATION SHEET</h1>'
            + '<div class="pp-sub">Payroll Period: ' + esc(M.from) + ' – ' + esc(M.to)
            + ' &nbsp;·&nbsp; ' + (isMonthlyBatch ? 'Monthly Payroll' : 'Payroll') + ' #' + M.id + '</div></div>';

        h += '<div class="pp-emp-grid">'
            + '<div><div class="lbl">Employee Name</div><div class="val">' + esc(e.name) + '</div></div>'
            + '<div><div class="lbl">Employee No.</div><div class="val">' + esc(e.no) + '</div></div>'
            + '<div><div class="lbl">Position</div><div class="val">' + esc(e.pos || '—') + '</div></div>'
            + '<div><div class="lbl">Department</div><div class="val">' + esc(e.dept || '—') + '</div></div>'
            + '<div><div class="lbl">Rate Type</div><div class="val" style="text-transform:capitalize;">' + esc(e.rate_type) + '</div></div>'
            + '<div><div class="lbl">' + (monthlyRate || isMonthlyBatch ? 'Monthly Basic Pay' : 'Rate per Day') + '</div><div class="val">'
            + peso(monthlyRate || isMonthlyBatch ? e.monthly : e.per_day) + '</div></div>'
            + '</div>';

        var ed = canEditRow(e);
        if (ed) {
            h += '<div class="pp-edit-hint"><i class="ri-edit-line"></i> Dashed fields are editable — type a figure, then Save. Totals recompute on Save.</div>';
        }

        // A. Attendance
        h += '<div class="pp-sec"><div class="pp-sec-title">A &nbsp; Attendance</div><table class="pp-table">';
        h += '<tr><td>Days on Duty</td><td class="qty">' + (e.dtr_days ? e.dtr_days + ' approved DTR day(s)' : '') + '</td><td class="amt">' + fld(e, 'present', e.present) + '</td></tr>';
        if (ed || e.absent > 0) h += earnRow('Absences', fld(e, 'absent', e.absent) + ' day(s) × ' + fmt(e.per_day), e.absent_amt, true);
        if (ed || e.late_min > 0) h += earnRow('Late', fld(e, 'late', Math.round(e.late_min)) + ' min', e.late_amt, true);
        if (!ed && !(e.absent > 0) && !(e.late_min > 0)) h += '<tr><td colspan="3" style="color:#4a7d4a;font-size:11.5px;">No absences or tardiness this period.</td></tr>';
        h += '</table></div>';

        // B. Earnings
        h += '<div class="pp-sec"><div class="pp-sec-title">B &nbsp; Earnings</div><table class="pp-table">';
        if (isMonthlyBatch) {
            h += earnRow('Half-Month Basic (Quinsena)', '(' + fmt(e.monthly) + ' + allowance − absences) ÷ 2', e.basic_earned, false);
        } else if (monthlyRate) {
            h += earnRow('Half-Month Basic', '(' + fmt(e.monthly) + ' − absences) ÷ 2', e.basic_earned, false);
        } else {
            h += earnRow('Basic Pay', esc(String(e.present)) + ' day(s) × ' + fld(e, 'per_day', fmt(e.per_day)), e.basic_earned, false);
        }
        if (ed || e.allow_amt) h += earnRow('Allowance' + (monthlyRate && !isMonthlyBatch ? ' (½ applied to gross)' : ''), fld(e, 'allowance_days', e.allow_days) + ' day(s) × ' + fmt(e.allow_rate), e.allow_amt, false);
        if (ed || e.legal_amt) h += earnRow('Legal Holiday Pay', fld(e, 'legal_holiday', e.legal) + ' × ' + fmt(e.per_day), e.legal_amt, false);
        if (ed || e.rest_amt) h += earnRow('Rest Day Duty', fld(e, 'sunday_duty', e.rest) + ' × ' + fmt(e.per_day), e.rest_amt, false);
        if (ed || e.spc_amt) h += earnRow('Special Holiday Pay', fld(e, 'special_holiday', e.spc) + ' day(s)', e.spc_amt, false);
        if (ed || e.ot_amt) h += earnRow('Overtime', fld(e, 'ot', e.ot_hrs) + ' hr(s) × ' + fmt(e.ot_rate), e.ot_amt, false);
        if (e.late_amt) h += earnRow('Less: Late', Math.round(e.late_min) + ' min', e.late_amt, true);
        // Named one-off allowances for this employee only.
        extrasOfKind(e, 2).forEach(function (x) {
            h += extraRow(e, x, false);
        });
        if (ed) h += addExtraRow(e, 2);
        h += '<tr class="totalrow"><td>GROSS PAY</td><td class="qty"></td><td class="amt" id="pp-gross-amt">' + fmt(e.gross) + '</td></tr>';
        h += '</table></div>';

        // C. Deductions — categorized: contributions / loans / other / company & tax
        h += '<div class="pp-sec"><div class="pp-sec-title">C &nbsp; Deductions</div><table class="pp-table">';
        [1, 3, 2].forEach(function (g) {
            var rows = (e.deds || []).filter(function (d) { return d.g === g; });
            if (!rows.length) return;
            var dtype = g === 1 ? 'contribution' : (g === 3 ? 'loan' : 'deduction');
            var sub = 0;
            h += '<tr class="subgroup"><td colspan="3">' + GROUPS[g] + '</td></tr>';
            rows.forEach(function (d) {
                sub += d.amt;
                h += earnRow(esc(d.label), '', edAmt(e, dtype, d.amt, d.id), d.amt > 0);
            });
            h += '<tr class="subtotal"><td>Subtotal — ' + GROUPS[g] + '</td><td class="qty"></td><td class="amt neg" id="pp-sub-g' + g + '">(' + fmt(sub) + ')</td></tr>';
        });
        var fixed = [
            ['SSS Provident Fund', 'sss_fund', e.sss_fund],
            ['JEI Advance', 'jei_advances', e.jei],
            ['JCC Advances', 'jcc_advances', e.jcc],
            ['Tax', 'tax', e.tax]
        ];
        var fsub = 0;
        h += '<tr class="subgroup"><td colspan="3">Company Advances &amp; Tax</td></tr>';
        fixed.forEach(function (fd) {
            fsub += fd[2];
            h += earnRow(fd[0], '', edAmt(e, fd[1], fd[2]), fd[2] > 0);
        });
        h += '<tr class="subtotal"><td>Subtotal — Company Advances &amp; Tax</td><td class="qty"></td><td class="amt neg" id="pp-sub-fx">(' + fmt(fsub) + ')</td></tr>';
        // Named one-off deductions for this employee only.
        var xDed = extrasOfKind(e, 1);
        if (xDed.length || ed) {
            h += '<tr class="subgroup"><td colspan="3">One-off Items</td></tr>';
            xDed.forEach(function (x) { h += extraRow(e, x, true); });
            if (ed) h += addExtraRow(e, 1);
        }
        h += '<tr class="totalrow"><td>TOTAL DEDUCTIONS</td><td class="qty"></td><td class="amt neg" id="pp-totded-amt">(' + fmt(e.total_ded) + ')</td></tr>';
        h += '</table></div>';

        // D. Refunds
        if ((e.refunds || []).length) {
            h += '<div class="pp-sec"><div class="pp-sec-title">D &nbsp; Refunds</div><table class="pp-table">';
            e.refunds.forEach(function (r) { h += earnRow(esc(r.label), '', edAmt(e, 'refund', r.amt, r.id), false); });
            h += '<tr class="totalrow"><td>TOTAL REFUNDS</td><td class="qty"></td><td class="amt" id="pp-totref-amt">' + fmt(e.total_ref) + '</td></tr>';
            h += '</table></div>';
        }

        // E. Adjustment (signed, non-monthly batches only)
        var adjEditable = !!tIn(e, 'adjustment') && !tIn(e, 'adjustment').readOnly;
        if (!isMonthlyBatch && (adjEditable || e.adj || e.adj_rem)) {
            h += '<div class="pp-sec"><div class="pp-sec-title">' + ((e.refunds || []).length ? 'E' : 'D') + ' &nbsp; Adjustment</div><table class="pp-table">';
            h += earnRow('Manual Adjustment (+ adds to net, − deducts)', '', adjEditable ? edAmt(e, 'adjustment', e.adj) : Math.abs(e.adj), !adjEditable && e.adj < 0);
            h += '</table>';
            if (adjEditable) {
                h += '<div class="pp-note">Remarks: ' + fld(e, 'adjustment_remarks', e.adj_rem || '—', null, 'txt-in') + '</div>';
            } else if (e.adj_rem) {
                h += '<div class="pp-note">Remarks: ' + esc(e.adj_rem) + '</div>';
            }
            h += '</div>';
        }

        // Net pay band
        h += '<div class="pp-net"><span class="lbl">NET PAY</span>'
            + '<span><span class="amt' + (e.net <= 0 ? ' neg' : '') + '" id="pp-net-amt">' + peso(e.net) + '</span>' + deltaBadge(e) + '</span></div>';

        // Status chips
        var chips = '';
        if (e.rv === 1) chips += '<span class="pp-chip g">✓ Reviewer: verified</span>';
        else if (e.rv === 2) chips += '<span class="pp-chip r">! Reviewer: issue' + (e.rv_c ? ' — ' + esc(e.rv_c) : '') + '</span>';
        else if (e.rv === 3) chips += '<span class="pp-chip b">● Reviewer: checking</span>';
        if (M.status === 2 || M.status === 3) {
            if (e.emp_rv === 1) chips += '<span class="pp-chip g">Employee confirmed</span>';
            else if (e.emp_rv === 2) chips += '<span class="pp-chip r">Employee disputed</span>';
            else chips += '<span class="pp-chip o">Awaiting employee review</span>';
        }
        chips += '<span class="pp-chip ' + (e.dtr_days ? 'g' : 'o') + '">' + e.dtr_days + ' approved DTR day(s)</span>';
        if (chips) h += '<div class="pp-chips">' + chips + '</div>';

        // Signatures
        h += '<div class="pp-sign">'
            + '<div><div class="line"></div>Prepared by</div>'
            + '<div><div class="line"></div>Checked by</div>'
            + '<div><div class="line">' + esc(e.first + ' ' + e.last) + '</div>Employee’s Signature</div>'
            + '</div>';

        byId('pcw-paper').innerHTML = h;
    }

    // ── Internal admin notes — read-only list (same content/style as
    // dtr-documents.php, minus the add form) ──
    var NOTE_ICONS = { info: '🔵', good: '🟢', watch: '🟠', critical: '🔴' };
    function notesHTML(e) {
        var notes = e.notes || [];
        var list = notes.length
            ? notes.map(function (n) {
                return '<div class="ddv-note ' + esc(n.level) + '">'
                    + '<span class="lv">' + (NOTE_ICONS[n.level] || NOTE_ICONS.info) + '</span>'
                    + '<span class="nt">' + esc(n.note)
                    + '<span class="nm">' + esc(n.by || 'Admin') + (n.at ? ' · ' + esc(n.at) : '') + '</span></span>'
                    + '</div>';
            }).join('')
            : '<div class="ddv-note-empty">No notes yet.</div>';
        return '<div class="ddv-notes">'
            + '<div class="ddv-notes-hd"><i class="ri-sticky-note-line"></i> Internal Notes <span class="lock"><i class="ri-lock-2-line"></i> admin only</span></div>'
            + list + '</div>';
    }

    // ── Per-employee unlock while the batch is out for employee review ────────
    // Correcting one disputed payslip should not mean recalling the whole batch
    // and voiding everyone else's sign-off, so a single row can be reopened.
    // Admin only; the server enforces the same rule in payroll_item_write_block().
    var PCW_IS_ADMIN = <?= ((int)($_SESSION['login_role'] ?? 0) === 1) ? 'true' : 'false' ?>;

    function unlockBanner(e) {
        if (M.status !== 3 || !e.unlocked) return '';
        return '<div class="pcw-unlock-note"><i class="ri-lock-unlock-line"></i>'
            + '<span><b>Open for editing.</b> Saving a change voids this employee’s review and asks them to confirm again.'
            + (e.unlock_why ? '<br><span class="why">Reason: ' + esc(e.unlock_why) + '</span>' : '')
            + '</span></div>';
    }
    function unlockButton(e) {
        if (M.status !== 3 || !PCW_IS_ADMIN) return '';
        return e.unlocked
            ? '<button type="button" class="pcw-btn warn" onclick="pcwRelockEmployee(' + e.id + ')" title="Freeze this employee again"><i class="ri-lock-line"></i> Lock</button>'
            : '<button type="button" class="pcw-btn" onclick="pcwUnlockEmployee(' + e.id + ')" title="Reopen just this employee so their figures can be corrected"><i class="ri-lock-unlock-line"></i> Unlock</button>';
    }

    window.pcwUnlockEmployee = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        // Unlocking someone who already confirmed is the risky case — say so.
        var warn = e.emp_rv === 1
            ? '<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:8px 10px;margin-bottom:8px;font-size:12.5px;color:#8a6100;text-align:left;">'
              + '<b>' + esc(e.name) + ' has already confirmed this payslip.</b><br>'
              + 'Saving any change will void that confirmation and ask them to review again.</div>'
            : '';
        Swal.fire({
            title: 'Unlock ' + esc(e.name) + '?',
            html: warn + '<div style="font-size:12.5px;color:#555;text-align:left;">'
                + 'Only this employee becomes editable — the rest of the batch stays frozen.<br>'
                + 'Give a reason for the audit log.</div>',
            input: 'text',
            inputPlaceholder: 'e.g. Missing OT for Jun 8–9, per their dispute',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonColor: '#107c41',
            confirmButtonText: 'Unlock for editing',
            preConfirm: function (v) {
                if (!v || !v.trim()) { Swal.showValidationMessage('A reason is required.'); return false; }
                return v.trim();
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            pcwPostLock('unlock_payroll_item', { id: itemId, reason: r.value }, true, r.value);
        });
    };

    window.pcwRelockEmployee = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        Swal.fire({
            title: 'Lock ' + esc(e.name) + '?',
            text: 'Their figures become read-only again. Unsaved edits are lost.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#c98a00',
            confirmButtonText: 'Lock'
        }).then(function (r) {
            if (r.isConfirmed) pcwPostLock('relock_payroll_item', { id: itemId }, false);
        });
    };

    // Applied in place — no page reload. The row's inputs are always in the DOM
    // while a batch is Open or in review; readonly is what gates editing, so
    // flipping it is all that is needed. Selection, filters and scroll survive.
    function pcwPostLock(action, data, unlocking, reason) {
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        $.ajax({
            url: 'ajax.php?action=' + action, method: 'POST', dataType: 'JSON', data: data,
            success: function (res) {
                if (!res || !res.result) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed.' });
                    return;
                }
                pcwApplyLockState(data.id, unlocking, reason);
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: res.message, showConfirmButton: false, timer: 3200, timerProgressBar: true
                });
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); }
        });
    }

    // Flip one row between editable and frozen, and refresh everything that
    // reflects that state.
    function pcwApplyLockState(itemId, unlocked, reason) {
        var e = empById(itemId);
        if (!e) return;
        e.unlocked = unlocked ? 1 : 0;
        e.unlock_why = unlocked ? (reason || '') : '';
        e.editable = e.unlocked;

        var tr = document.querySelector('tr[data-row-id="' + itemId + '"]');
        if (tr) {
            tr.dataset.unlocked = unlocked ? '1' : '0';
            // setReviewInputLock honours the unlock, so a green row unfreezes too.
            setReviewInputLock(tr, !unlocked && tr.classList.contains('review-ok'));
            if (!unlocked) {
                tr.querySelectorAll('.input-class').forEach(function (i) { i.readOnly = true; });
            }
        }

        // Header count + Save button follow the number of open rows.
        var open = D.filter(function (x) { return x.unlocked; }).length;
        var flag = document.querySelector('.pcw-unlock-flag');
        if (flag) {
            flag.innerHTML = '<i class="ri-lock-unlock-line"></i> ' + open + ' unlocked';
            flag.style.display = open ? '' : 'none';
        }
        var save = byId('pcw-save');
        if (save && !open && M.status === 3) save.style.display = 'none';

        if (S.sel == itemId) { renderPaper(e); renderSum(e); }
        buildList();
    }

    // ── Right: employee summary ──
    function renderSum(e) {
        var sentLine = e.sent_n > 0
            ? '<div class="pcw-sum-sent"><i class="ri-send-plane-fill"></i> Review request sent <b>' + e.sent_n + '×</b>'
              + (e.sent_at ? ' · last ' + esc(e.sent_at) : '') + '</div>'
            : '';
        // One-line sub-caption; the untruncated text lives in the tooltip.
        var sub = e.no + (e.pos ? ' · ' + e.pos : '') + (e.dept ? ' · ' + e.dept : '');
        var h = '<div class="pcw-sum-emp" title="' + esc(e.name) + '">' + esc(e.name) + '</div>'
            + '<div class="pcw-sum-sub" title="' + esc(sub) + '">' + esc(sub) + '</div>'
            + sentLine
            + '<div class="pcw-sum-grid">'
            + '<div class="pcw-sum-tile"><div class="v">' + fmt(e.gross) + '</div><div class="l">Gross</div></div>'
            + '<div class="pcw-sum-tile ded"><div class="v">' + fmt(e.total_ded) + '</div><div class="l">Deductions</div></div>'
            + '<div class="pcw-sum-tile net"><div class="v">' + fmt(e.net) + '</div><div class="l">Net Pay</div></div>'
            + '<div class="pcw-sum-tile"><div class="v">' + e.present + '</div><div class="l">Days</div></div>'
            + '<div class="pcw-sum-tile abs"><div class="v">' + e.absent + '</div><div class="l">Absent</div></div>'
            + '<div class="pcw-sum-tile lt"><div class="v">' + Math.round(e.late_min) + '</div><div class="l">Late (min)</div></div>'
            + '</div>'
            + unlockBanner(e)
            + '<div class="pcw-sum-actions">'
            + '<button type="button" class="pcw-btn" onclick="openPayslipPreview(' + e.id + ')"><i class="ri-file-pdf-2-line"></i> Payslip PDF</button>'
            + '<button type="button" class="pcw-btn" onclick="pcwOpenDtr(' + e.id + ')"><i class="ri-calendar-check-line"></i> DTR Details (' + e.dtr_days + ')</button>'
            + '<button type="button" class="pcw-btn" onclick="openReviewMark(' + e.id + ')"><i class="ri-checkbox-multiple-line"></i> Review Mark</button>'
            + unlockButton(e)
            + '<a class="pcw-btn" href="index.php?page=employee-details&id=' + e.emp + '" target="_blank"><i class="ri-user-3-line"></i> Profile</a>'
            + '</div>';
        byId('pcw-sum').innerHTML = h;
    }

    // ── Right: Batch Insights (live totals, review progress, exception chips) ──
    function renderInsights() {
        var box = byId('pcw-insights');
        if (!box) return;
        var t = { gross: 0, ded: 0, net: 0, ref: 0 };
        var rv = { 1: 0, 2: 0, 3: 0, 0: 0 };
        D.forEach(function (e) {
            t.gross += e.gross; t.ded += e.total_ded; t.net += e.net; t.ref += e.total_ref;
            rv[e.rv === 1 ? 1 : e.rv === 2 ? 2 : e.rv === 3 ? 3 : 0]++;
        });
        var N = D.length || 1;
        var hasPrev = M.prev_label != null;

        var h = '';
        // Totals
        h += '<div class="pcw-ins-sec">Payroll totals</div>'
            + '<div class="pcw-ins-stats">'
            + '<div class="pcw-ins-tile gross"><div class="v">' + peso(t.gross) + '</div><div class="l">Gross</div></div>'
            + '<div class="pcw-ins-tile ded"><div class="v">' + peso(t.ded) + '</div><div class="l">Deductions</div></div>'
            + '<div class="pcw-ins-tile net"><div class="v">' + peso(t.net) + '</div><div class="l">Net pay</div></div>'
            + '<div class="pcw-ins-tile"><div class="v">' + D.length + '</div><div class="l">Employees</div></div>'
            + '</div>';

        // Review progress
        var pctOk = Math.round(rv[1] / N * 100);
        h += '<div class="pcw-ins-sec">Reviewer progress <span class="pcw-ins-pct">' + pctOk + '% verified</span></div>'
            + '<div class="pcw-ins-bar">'
            + '<span class="b-ok" style="width:' + (rv[1] / N * 100) + '%"></span>'
            + '<span class="b-issue" style="width:' + (rv[2] / N * 100) + '%"></span>'
            + '<span class="b-chk" style="width:' + (rv[3] / N * 100) + '%"></span>'
            + '<span class="b-none" style="width:' + (rv[0] / N * 100) + '%"></span>'
            + '</div>'
            + '<div class="pcw-ins-legend">'
            + '<span class="pcw-ins-lg"><span class="dot ok"></span>Verified ' + rv[1] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot issue"></span>Issue ' + rv[2] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot chk"></span>Reviewing ' + rv[3] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot none"></span>Unmarked ' + rv[0] + '</span>'
            + '</div>';

        // Exceptions — clickable chips that filter the left list
        var defs = EXC_DEFS.filter(function (d) { return !d.needPrev || hasPrev; });
        var counts = {}, totalExc = 0;
        defs.forEach(function (d) {
            counts[d.k] = D.filter(function (e) { return excPred(d.k, e); }).length;
            totalExc += counts[d.k];
        });
        h += '<div class="pcw-ins-sec">Exceptions to review</div>';
        if (totalExc === 0) {
            h += '<div class="pcw-ins-allclear"><i class="ri-checkbox-circle-line"></i> No exceptions — this batch looks clean.</div>';
        } else {
            h += '<div class="pcw-exc-chips">';
            defs.forEach(function (d) {
                var n = counts[d.k];
                var muted = n === 0 ? ' muted' : '';
                var on = S.exc === d.k ? ' on' : '';
                h += '<button type="button" class="pcw-exc ' + d.cls + muted + on + '" data-exc="' + d.k + '"' + (n === 0 ? ' disabled' : '') + '>'
                    + '<span class="ic"><i class="' + d.ic + '"></i></span>'
                    + '<span class="tx">' + d.lbl + '</span>'
                    + '<span class="n">' + n + '</span>'
                    + '</button>';
            });
            h += '</div>';
        }

        // Biggest net movers vs previous payroll
        if (hasPrev) {
            var movers = D.filter(function (e) { return e.prev_net != null && e.prev_net != 0; })
                .map(function (e) { return { name: e.name, id: e.id, pct: (e.net - e.prev_net) / e.prev_net * 100 }; })
                .sort(function (a, b) { return Math.abs(b.pct) - Math.abs(a.pct); })
                .slice(0, 5);
            if (movers.length) {
                h += '<div class="pcw-ins-sec">Biggest changes vs ' + esc(M.prev_label) + '</div>';
                movers.forEach(function (m) {
                    var up = m.pct >= 0;
                    h += '<div class="pcw-ins-mover" data-eid="' + m.id + '" style="cursor:pointer;">'
                        + '<span class="nm">' + esc(m.name) + '</span>'
                        + '<span class="pct ' + (up ? 'up' : 'down') + '">' + (up ? '▲' : '▼') + ' ' + Math.abs(m.pct).toFixed(0) + '%</span>'
                        + '</div>';
                });
            }
        }

        box.innerHTML = h;
        var clr = byId('pcw-ins-clear');
        if (clr) clr.style.display = S.exc ? '' : 'none';
    }

    // Insights interactions: exception chips filter the list; movers jump to employee
    (function () {
        var box = byId('pcw-insights');
        if (box) box.addEventListener('click', function (ev) {
            var chip = ev.target.closest('.pcw-exc[data-exc]');
            if (chip && !chip.disabled) {
                var k = chip.getAttribute('data-exc');
                S.exc = (S.exc === k) ? '' : k;
                buildList();
                renderInsights();
                return;
            }
            var mv = ev.target.closest('.pcw-ins-mover[data-eid]');
            if (mv) select(parseInt(mv.getAttribute('data-eid'), 10));
        });
        var clr = byId('pcw-ins-clear');
        if (clr) clr.addEventListener('click', function () { S.exc = ''; buildList(); renderInsights(); });
    })();

    // ── Excel export of the Table View ──────────────────────────────────────
    // PhpSpreadsheet's HTML reader understands inline style attributes only —
    // not classes or <style> blocks — so the page palette is stamped onto the
    // clone here, cell by cell. Exporting the rendered DOM (rather than redoing
    // ~40 columns of arithmetic server-side) keeps the workbook and the screen
    // permanently in agreement.
    var XL_FILL = {
        // group band (header row 1) → [background, text]
        head1: {
            'primary-header': ['#e1dcec', '#4f3288'],
            'info-header':    ['#dde9f8', '#1e50a0'],
            'success-header': ['#d9eedd', '#107c41'],
            'danger-header':  ['#fbe3e6', '#b02a37']
        },
        // column labels (header row 2) — lighter tint of the same family
        head2: {
            'primary-header': ['#f0edf5', '#4f3288'],
            'info-header':    ['#eef4fc', '#1e50a0'],
            'success-header': ['#ebf7ee', '#107c41'],
            'danger-header':  ['#fdf0f1', '#b02a37']
        },
        // reviewer colour marks carried over as row tints
        row: { 'review-ok': '#e9f7ee', 'review-issue': '#fff4e2', 'review-checking': '#e8f1fb' }
    };
    function xlHeadStyle(th, map) {
        var key = ['primary-header', 'info-header', 'success-header', 'danger-header']
            .find(function (c) { return th.classList.contains(c); }) || 'primary-header';
        var c = map[key];
        return 'background-color:' + c[0] + ';color:' + c[1] + ';font-weight:bold;'
            + 'border:1px solid #cfc8de;text-align:center;';
    }
    window.pcwExportTableExcel = function (payrollId) {
        var src = document.getElementById('table-1');
        if (!src) { Swal.fire({ icon: 'error', title: 'Nothing to export', text: 'Open Table View first.' }); return; }
        Swal.fire({ title: 'Building workbook…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        // Let the spinner paint before the (synchronous) DOM walk on a big batch.
        setTimeout(function () {
            try {
                // cloneNode copies an input's ATTRIBUTE, not its live value, so an
                // open payroll the user has just edited would export stale figures.
                // Stamp the live values onto the attributes first — harmless on the
                // page, since a dirty input keeps showing its own value.
                src.querySelectorAll('input').forEach(function (inp) {
                    if (inp.type !== 'checkbox') inp.setAttribute('value', inp.value);
                });

                var t = src.cloneNode(true);

                // Inputs are the live data store — replace each with its value so
                // the export carries figures, not empty form controls.
                t.querySelectorAll('input').forEach(function (inp) {
                    if (inp.type === 'checkbox') { inp.parentNode.removeChild(inp); return; }
                    var span = document.createElement('span');
                    span.textContent = (inp.value || '').replace(/,/g, '');   // raw number → Excel parses it
                    inp.parentNode.replaceChild(span, inp);
                });
                // Buttons / icons / avatars / badges carry no meaning in a
                // spreadsheet, and their text would run into the real content
                // ("QAABAD, QUERWIN" from the avatar initials).
                t.querySelectorAll('button, .review-mark-btn, i, svg, .rv-dot, .emp-avatar, .pcw-fdot, .nd-badge, .pp-delta, .pay-badge, [data-badges]')
                    .forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });

                // The Name cell stacks name + employee no. In one flat Excel cell
                // those would concatenate ("ABAD, QUERWIN2026-00062"), so keep the
                // name here and move the number to its own column.
                var splitNo = t.querySelector('td .emp-cell') !== null;
                t.querySelectorAll('td .emp-cell').forEach(function (cell) {
                    var nm = cell.querySelector('.emp-name-link');
                    var no = cell.querySelector('.emp-no');
                    var td = cell.closest('td');
                    td.textContent = nm ? nm.textContent.trim() : cell.textContent.trim();
                    if (no) {
                        var extra = document.createElement('td');
                        extra.textContent = no.textContent.trim();
                        td.parentNode.insertBefore(extra, td.nextSibling);
                    }
                });
                // …and give that column a header in both header rows.
                if (splitNo) {
                    var hrows = t.querySelectorAll('thead tr');
                    if (hrows[0]) {
                        var th = document.createElement('th');
                        th.textContent = 'Employee No.';
                        th.setAttribute('rowspan', '2');
                        th.className = 'primary-header';
                        th.setAttribute('style', xlHeadStyle(th, XL_FILL.head1));
                        hrows[0].insertBefore(th, hrows[0].children[2] || null);
                    }
                    // tfoot's TOTAL cell spans No.+Name — widen it over the new column.
                    var tot = t.querySelector('tfoot .tfoot-total-cell');
                    if (tot) tot.setAttribute('colspan', String((parseInt(tot.getAttribute('colspan'), 10) || 2) + 1));
                }
                // The Adjustment cell stacks the amount over its remark ("1,000.00"
                // / "For other payment"). Flattened they concatenate and the column
                // stops being numeric, so the remark moves to its own column.
                // Pass 1: locate the column (only some rows carry a remark).
                var adjIdx = -1;
                t.querySelectorAll('tbody tr').forEach(function (tr) {
                    if (adjIdx !== -1) return;
                    for (var i = 0; i < tr.children.length; i++) {
                        if (tr.children[i].querySelector('.text-muted') && tr.children[i].querySelector('b')) { adjIdx = i; return; }
                    }
                });
                // Pass 2: split it on EVERY row — a row with no remark still needs
                // the placeholder cell, or the columns after it shift left.
                if (adjIdx !== -1) {
                    t.querySelectorAll('tbody tr').forEach(function (tr) {
                        var cell = tr.children[adjIdx];
                        if (!cell) return;
                        var note = cell.querySelector('.text-muted');
                        var amt = cell.querySelector('b');
                        var extra = document.createElement('td');
                        extra.textContent = note ? note.textContent.trim() : '';
                        cell.textContent = (amt ? amt.textContent : cell.textContent).trim().replace(/,/g, '');
                        tr.insertBefore(extra, cell.nextSibling);
                    });
                }
                if (adjIdx !== -1) {
                    // Header: the Adjustment th spans both header rows, so the new
                    // label only needs inserting into row 1.
                    var h0 = t.querySelectorAll('thead tr')[0];
                    if (h0) {
                        var col = 0, at = null;
                        for (var k = 0; k < h0.children.length; k++) {
                            if (col === adjIdx) { at = h0.children[k]; break; }
                            col += h0.children[k].colSpan || 1;
                        }
                        var rth = document.createElement('th');
                        rth.textContent = 'Adjustment Remark';
                        rth.setAttribute('rowspan', '2');
                        rth.className = 'primary-header';
                        rth.setAttribute('style', xlHeadStyle(rth, XL_FILL.head1));
                        if (at) h0.insertBefore(rth, at.nextSibling); else h0.appendChild(rth);
                    }
                    // tfoot: walk colspans to the same column, then pad it.
                    t.querySelectorAll('tfoot tr').forEach(function (tr) {
                        var col = 0;
                        for (var k = 0; k < tr.children.length; k++) {
                            var span = tr.children[k].colSpan || 1;
                            if (col + span > adjIdx) {
                                tr.insertBefore(document.createElement('th'), tr.children[k].nextSibling);
                                return;
                            }
                            col += span;
                        }
                    });
                }

                // The table repeats the row number in a trailing "No." column so
                // the index stays visible when scrolled far right. A spreadsheet
                // has row numbers of its own, so drop that last column entirely.
                var lastHead = t.querySelectorAll('thead tr')[0];
                var lastTh = lastHead && lastHead.lastElementChild;
                if (lastTh && lastTh.textContent.trim() === 'No.' && lastTh.rowSpan === 2) {
                    lastTh.parentNode.removeChild(lastTh);
                    t.querySelectorAll('tbody tr, tfoot tr').forEach(function (tr) {
                        if (tr.lastElementChild) tr.removeChild(tr.lastElementChild);
                    });
                }

                // One-off items are already inside the Gross / Total Deduction /
                // Net figures, but nothing in the workbook says WHAT they were.
                // Append a trailing annotation column naming them per employee.
                if (D.some(function (x) { return (x.extras || []).length; })) {
                    var h0x = t.querySelectorAll('thead tr')[0];
                    if (h0x) {
                        var xth = document.createElement('th');
                        xth.textContent = 'One-off Items';
                        xth.setAttribute('rowspan', '2');
                        xth.className = 'primary-header';
                        xth.setAttribute('style', xlHeadStyle(xth, XL_FILL.head1));
                        h0x.appendChild(xth);
                    }
                    t.querySelectorAll('tbody tr').forEach(function (tr) {
                        var emp = empById(parseInt(tr.getAttribute('data-row-id'), 10));
                        var td = document.createElement('td');
                        td.textContent = ((emp && emp.extras) || []).map(function (x) {
                            return x.label + ' ' + (x.kind === 2 ? '+' : '−') + fmt(x.amt);
                        }).join('; ');
                        tr.appendChild(td);
                    });
                    t.querySelectorAll('tfoot tr').forEach(function (tr) {
                        tr.appendChild(document.createElement('th'));
                    });
                }

                // Leading text columns (No., Name, [Employee No.], Position) — the
                // server needs the count to know where the money columns begin.
                var identityCols = splitNo ? 4 : 3;

                // Header rows: group band, then column labels.
                var headRows = t.querySelectorAll('thead tr');
                if (headRows[0]) headRows[0].querySelectorAll('th').forEach(function (th) {
                    th.setAttribute('style', xlHeadStyle(th, XL_FILL.head1));
                });
                if (headRows[1]) headRows[1].querySelectorAll('th').forEach(function (th) {
                    th.setAttribute('style', xlHeadStyle(th, XL_FILL.head2));
                });

                // Body: reviewer mark tint + strip thousands separators so the
                // server can format the columns as real numbers.
                t.querySelectorAll('tbody tr').forEach(function (tr) {
                    var tint = Object.keys(XL_FILL.row).find(function (c) { return tr.classList.contains(c); });
                    tr.querySelectorAll('td').forEach(function (td) {
                        var txt = td.textContent.trim();
                        if (/^\(?-?[\d,]+\.?\d*\)?$/.test(txt) && txt.indexOf(',') !== -1) {
                            td.textContent = txt.replace(/,/g, '');
                        }
                        td.setAttribute('style', 'border:1px solid #e4e7e5;'
                            + (tint ? 'background-color:' + XL_FILL.row[tint] + ';' : ''));
                    });
                });

                // Totals row — bold on the same green the screen uses.
                t.querySelectorAll('tfoot th').forEach(function (th) {
                    th.textContent = th.textContent.trim().replace(/,/g, '');
                    th.setAttribute('style', 'background-color:#d9eedd;color:#0e6b37;font-weight:bold;border:1px solid #c0e0c8;');
                });

                // A plain form post lets the browser handle the download itself —
                // no blob juggling, and the file lands with the server's filename.
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = 'export-payroll-table.php';
                f.style.display = 'none';
                [['id', payrollId], ['identity', identityCols], ['html', t.outerHTML]].forEach(function (kv) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
                    f.appendChild(i);
                });
                document.body.appendChild(f);
                f.submit();
                setTimeout(function () { document.body.removeChild(f); Swal.close(); }, 1500);
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Export failed', text: err.message || 'Could not build the workbook.' });
            }
        }, 60);
    };

    // ── Collapsible right-column panels ──
    // Which panels are folded is remembered per browser, so an admin who works
    // with Batch Insights closed does not have to close it every page load.
    var PCW_FOLD_KEY = 'pcw-folded-panels';
    window.pcwTogglePanel = function (head) {
        var panel = head.closest('.pcw-panel');
        if (!panel) return;
        panel.classList.toggle('collapsed');
        try {
            var folded = [];
            document.querySelectorAll('.pcw-right .pcw-panel').forEach(function (p, i) {
                if (p.classList.contains('collapsed')) folded.push(i);
            });
            localStorage.setItem(PCW_FOLD_KEY, JSON.stringify(folded));
        } catch (e) { /* private mode — collapsing still works, just not remembered */ }
    };
    (function () {
        var folded = [];
        try { folded = JSON.parse(localStorage.getItem(PCW_FOLD_KEY) || '[]'); } catch (e) { folded = []; }
        if (!folded.length) return;
        document.querySelectorAll('.pcw-right .pcw-panel').forEach(function (p, i) {
            if (folded.indexOf(i) !== -1) p.classList.add('collapsed');
        });
    })();

    // The "clear filter" link sits inside the collapsible head — don't fold on it.
    (function () {
        var clr = byId('pcw-ins-clear');
        if (clr) clr.addEventListener('click', function (ev) { ev.stopPropagation(); });
    })();

    // ── Payslip sign-off conversation ──
    // Reads window.PCW_REVIEWS (employee_id → decision + message + HR reply)
    // and shows it as a two-bubble thread. Global: the Employee Review rows and
    // the left-list chat buttons both call it.
    var EMP_RV_META = {
        1: { cls: 'ok',   ic: 'ri-checkbox-circle-line', lbl: 'Confirmed' },
        2: { cls: 'disp', ic: 'ri-error-warning-line',   lbl: 'Disputed' }
    };
    window.pcwOpenReviewConvo = function (empId) {
        var r = (window.PCW_REVIEWS || {})[empId];
        var box = byId('mer-body'), btn = byId('mer-reply');
        if (!box) return;
        if (!r) {
            box.innerHTML = '<div class="mer-empty"><i class="ri-chat-off-line"></i> This employee has not reviewed their payslip yet.</div>';
            btn.style.display = 'none';
        } else {
            var meta = EMP_RV_META[r.st] || EMP_RV_META[1];
            var h = '<div class="mer-head">'
                + '<span class="mer-name">' + esc(r.name) + '</span>'
                + '<span class="mer-badge ' + meta.cls + '"><i class="' + meta.ic + '"></i>' + meta.lbl + '</span>'
                + (r.at ? '<span class="mer-when">' + esc(r.at) + '</span>' : '')
                + '</div>';
            h += '<div class="pcw-chat">';
            h += r.c
                ? '<div class="pcw-bub them">' + esc(r.c) + '<span class="m">' + esc(r.name) + (r.at ? ' · ' + esc(r.at) : '') + '</span></div>'
                : '<div class="mer-empty">' + meta.lbl + ' without a message.</div>';
            if (r.rep) {
                h += '<div class="pcw-bub me">' + esc(r.rep)
                    + '<span class="m">HR reply' + (r.rep_at ? ' · ' + esc(r.rep_at) : '') + '</span></div>';
            }
            h += '</div>';
            box.innerHTML = h;
            // Anything with a message and no reply yet can be answered — a
            // dispute to resolve, or a confirmation that asked something.
            var canReply = !r.rep && (r.st === 2 || (r.st === 1 && !!r.c));
            var isDisp = r.st === 2;
            btn.style.display = canReply ? '' : 'none';
            btn.innerHTML = isDisp
                ? '<i class="ri-chat-check-line me-1"></i>Resolve &amp; Reply'
                : '<i class="ri-chat-1-line me-1"></i>Reply';
            btn.onclick = canReply ? function () { openResolveDispute('payroll', r.id, r.name, isDisp); } : null;
            if (r.new) pcwMarkReviewSeen(empId, r);
        }
        bootstrap.Modal.getOrCreateInstance(byId('modal-emp-review')).show();
    };

    // Opening a message clears its UNREAD state — server first, then the badges
    // in the panel, the card list and the header count, without a reload.
    function pcwMarkReviewSeen(empId, r) {
        r.new = 0;
        var url = 'ajax.php?action=mark_review_seen';
        var body = 'type=payroll&id=' + encodeURIComponent(r.id);
        fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        }).catch(function () { /* the badge still clears locally */ });

        var row = document.querySelector('.prp-row[data-emp="' + empId + '"]');
        if (row) {
            row.classList.remove('unread');
            var tag = row.querySelector('.prp-row-new');
            if (tag) tag.remove();
        }
        var n = byId('prp-unread-n'), chip = byId('prp-unread-chip');
        if (n) {
            var left = Math.max(0, (parseInt(n.textContent, 10) || 0) - 1);
            n.textContent = left;
            if (!left && chip) chip.style.display = 'none';
        }
        buildList();
    }
    // The review rows are role="button" divs, which browsers focus but do not
    // activate on Enter/Space the way a real <button> does — wire that up.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        var row = ev.target.closest ? ev.target.closest('.prp-row.has-msg') : null;
        if (!row) return;
        ev.preventDefault();
        row.click();
    });

    // Daily Time Record modal — renders the shared Form 48 sheet from this
    // payroll's approved DTR rows (identical template to dtr-documents.php).
    window.pcwOpenDtr = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        var body = document.getElementById('al-body');
        if (window.DTRForm48) {
            body.innerHTML = window.DTRForm48.render({
                name: e.name,
                periodLabel: M.from + ' – ' + M.to,
                dateFrom: M.from_iso,
                dateTo: M.to_iso,
                logMode: M.log_mode || 'single',
                compact: true,
                days: (e.dtr && e.dtr.days) || {},
                totals: (e.dtr && e.dtr.totals) || { wh: 0, ot: 0, ut: 0, late: 0 }
            });
        } else {
            body.innerHTML = '<div class="text-center text-muted py-4">DTR template failed to load.</div>';
        }
        // Approved Attendance Logs — the same per-day punch tables the old
        // Logs modal showed (# / Time / Direction / Source), kept below the
        // Form 48 sheet.
        var logsBox = document.getElementById('al-logs');
        if (logsBox) {
            var days = (e.dtr && e.dtr.days) || {};
            var dates = Object.keys(days).sort();
            var lh = '';
            dates.forEach(function (iso) {
                var d = days[iso];
                var dDate = new Date(iso + 'T00:00:00');
                var dLbl = dDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    + ' (' + dDate.toLocaleDateString('en-US', { weekday: 'short' }) + ')';
                lh += '<div class="al-day">'
                    + '<div class="al-day-head">'
                    + '<span class="al-day-date"><i class="ri-calendar-line me-1"></i>' + esc(dLbl) + '</span>'
                    + '<span class="al-day-hrs">' + fmt(d.wh) + ' hrs</span>'
                    + '</div>';
                var lgs = d.logs || [];
                if (lgs.length) {
                    lh += '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0 al-table">'
                        + '<thead class="table-dark"><tr>'
                        + '<th class="text-center" style="width:36px;">#</th>'
                        + '<th><i class="ri-time-line me-1"></i>Time</th>'
                        + '<th class="text-center" style="width:80px;">Direction</th>'
                        + '<th class="text-center" style="width:80px;">Source</th>'
                        + '</tr></thead><tbody>';
                    lgs.forEach(function (l, li) {
                        var dir = li === 0 ? '<span class="att-log-dir att-log-in">IN</span>'
                            : (li === lgs.length - 1 ? '<span class="att-log-dir att-log-out">OUT</span>'
                                : '<span class="text-muted small">&mdash;</span>');
                        var src = l.bio
                            ? '<span class="badge att-log-bio"><i class="ri-fingerprint-line me-1"></i>Bio</span>'
                            : '<span class="badge att-log-manual"><i class="ri-edit-line me-1"></i>Manual</span>';
                        lh += '<tr>'
                            + '<td class="text-center text-muted">' + (li + 1) + '</td>'
                            + '<td class="fw-semibold">' + esc(l.t) + '</td>'
                            + '<td class="text-center">' + dir + '</td>'
                            + '<td class="text-center">' + src + '</td>'
                            + '</tr>';
                    });
                    lh += '</tbody></table></div>';
                } else {
                    lh += '<span class="text-muted small">No logs</span>';
                }
                lh += '</div>';
            });
            logsBox.innerHTML = dates.length ? lh
                : '<div class="pcw-tab-empty"><i class="ri-history-line"></i> No approved attendance logs.</div>';
        }
        // Admin ↔ employee record messages (read-only conversation)
        var msgsBox = document.getElementById('al-msgs');
        var msgs = e.msgs || [];
        if (msgsBox) {
            if (msgs.length) {
                var mh = '<div class="pcw-chat">';
                msgs.forEach(function (m) {
                    mh += '<div class="pcw-bub ' + (m.from === 'emp' ? 'them' : 'me') + '">'
                        + (m.date ? '<span class="pcw-bub-day">' + esc(m.date) + '</span>' : '')
                        + esc(m.msg)
                        + '<span class="m">' + esc(m.by || (m.from === 'emp' ? 'Employee' : 'Admin')) + (m.at ? ' · ' + esc(m.at) : '') + '</span>'
                        + '</div>';
                });
                mh += '</div>';
                msgsBox.innerHTML = mh;
            } else {
                msgsBox.innerHTML = '<div class="pcw-tab-empty"><i class="ri-chat-off-line"></i> No messages on this employee\'s records.</div>';
            }
        }
        var notesBox = document.getElementById('al-notes');
        if (notesBox) notesBox.innerHTML = notesHTML(e);

        // Tab counts + reset to the DTR tab each time the modal opens
        var nMsgs = msgs.length, nNotes = (e.notes || []).length;
        var cM = byId('al-tab-msgs'), cN = byId('al-tab-notes');
        if (cM) { cM.textContent = nMsgs; cM.style.display = nMsgs ? '' : 'none'; }
        if (cN) { cN.textContent = nNotes; cN.style.display = nNotes ? '' : 'none'; }
        pcwSelectDtrTab('dtr');

        document.getElementById('al-employee').textContent = e.name;
        document.getElementById('al-days-count').textContent = e.dtr_days
            ? e.dtr_days + ' approved day' + (e.dtr_days === 1 ? '' : 's') : 'No approved attendance';

        // Footer totals — same figures the Form 48 TOTAL row shows, plus this
        // employee's standing in the batch's review round.
        var tot = (e.dtr && e.dtr.totals) || { wh: 0, ot: 0, ut: 0, late: 0 };
        [['al-tot-wh', tot.wh, 2], ['al-tot-ot', tot.ot, 2],
         ['al-tot-ut', tot.ut, 2], ['al-tot-late', tot.late, 0]].forEach(function (t) {
            var el = byId(t[0]);
            if (!el) return;
            var v = Number(t[1]) || 0;
            el.textContent = v.toFixed(t[2]);
            el.parentNode.classList.toggle('zero', v === 0);
        });
        var rvChip = byId('al-emp-rv');
        if (rvChip) {
            var rvMap = {
                1: ['ok',   'ri-checkbox-circle-line',  'Employee confirmed'],
                2: ['bad',  'ri-error-warning-line',    'Employee disputed'],
                0: ['wait', 'ri-time-line',             'Awaiting employee review']
            };
            var rv = (M.status === 2 || M.status === 3) ? (rvMap[e.emp_rv] || rvMap[0]) : null;
            rvChip.className = 'al-fs rv' + (rv ? ' ' + rv[0] : '');
            rvChip.style.display = rv ? '' : 'none';
            if (rv) rvChip.innerHTML = '<i class="' + rv[1] + '"></i>' + rv[2];
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-att-logs')).show();
    };
    // Switch the active tab/pane inside the DTR Details modal.
    function pcwSelectDtrTab(tab) {
        document.querySelectorAll('#al-tabs .pcw-tab').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === tab);
        });
        document.querySelectorAll('.pcw-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-pane') === tab);
        });
    }
    var alTabs = document.getElementById('al-tabs');
    if (alTabs) alTabs.addEventListener('click', function (ev) {
        var b = ev.target.closest('.pcw-tab');
        if (b) pcwSelectDtrTab(b.getAttribute('data-tab'));
    });
    // Table Actions buttons carry "empId-siteId" keys — map them to an item.
    window.pcwOpenDtrByKey = function (key) {
        for (var i = 0; i < D.length; i++) {
            if (D[i].emp + '-' + D[i].site === key) return window.pcwOpenDtr(D[i].id);
        }
    };

    // ── Selection (the card list is the single source of truth) ──
    function psIds() { return Object.keys(S.ps).filter(function (k) { return S.ps[k]; }); }
    function updPsPill() {
        var n = psIds().length;
        var pill = byId('pcw-ps-count');
        if (pill) pill.textContent = n;
        var all = byId('pcw-sel-all');
        if (all) {
            var vis = S.list.length;
            var on = S.list.filter(function (e) { return S.ps[e.id]; }).length;
            all.checked = vis > 0 && on === vis;
            all.indeterminate = on > 0 && on < vis;
        }
        // Bulk bar slides in only while something is selected
        var bulk = byId('pcw-bulk');
        if (bulk) bulk.classList.toggle('show', n > 0);
        var bn = byId('pcw-bulk-count');
        if (bn) bn.textContent = n;
    }
    // printSelectedPayslips() (payroll_calculations.js) reads the selection here.
    window.pcwSelectedPayslipIds = psIds;
    window.pcwClearSelection = function () {
        S.ps = {};
        document.querySelectorAll('#pcw-list input[data-ps]').forEach(function (c) { c.checked = false; });
        updPsPill();
    };

    // ── Bulk progress bar ──
    function bulkProgress(done, total, label) {
        var box = byId('pcw-bulk-prog');
        if (!box) return;
        box.classList.add('show');
        byId('pcw-bulk-prog-txt').textContent = label || 'Working…';
        byId('pcw-bulk-prog-n').textContent = done + ' / ' + total;
        byId('pcw-bulk-bar-fill').style.width = (total ? (done / total * 100) : 0) + '%';
    }
    function bulkProgressDone() {
        var box = byId('pcw-bulk-prog');
        if (box) setTimeout(function () { box.classList.remove('show'); }, 900);
    }

    // ── Bulk: set the reviewer mark on every selected employee ──
    window.pcwBulkReview = function (statusVal) {
        var ids = psIds();
        if (!ids.length) return;
        var meta = RV_META[statusVal];
        var label = meta ? meta.lbl : 'No mark';
        Swal.fire({
            title: 'Mark ' + ids.length + ' employee(s) as "' + label + '"?',
            input: statusVal === 0 ? undefined : 'text',
            inputPlaceholder: 'Optional comment for all selected…',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#107c41', confirmButtonText: 'Apply to ' + ids.length
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var comment = statusVal === 0 ? '' : (r.value || '').trim();
            var done = 0, failed = 0;
            bulkProgress(0, ids.length, 'Applying "' + label + '"…');
            // Sequential so a big selection can't flood the server.
            (function next(i) {
                if (i >= ids.length) {
                    bulkProgress(ids.length, ids.length, 'Done');
                    bulkProgressDone();
                    buildList(); renderInsights();
                    var cur = empById(S.sel);
                    if (cur) renderSum(cur);
                    showToast(failed
                        ? (done + ' updated, ' + failed + ' failed.')
                        : (done + ' employee(s) marked as "' + label + '".'), failed ? 'warning' : 'success');
                    return;
                }
                var id = ids[i];
                $.ajax({
                    url: 'ajax.php?action=set_payroll_item_review', method: 'POST',
                    data: { id: id, review_status: statusVal, review_comment: comment }
                }).done(function () {
                    var e = empById(id);
                    if (e) { e.rv = statusVal; e.rv_c = comment; }
                    // keep the (hidden) table row in step for saves/printing
                    if (typeof window.applyReviewToRow === 'function') {
                        var tr = document.querySelector('tr[data-row-id="' + id + '"]');
                        if (tr) {
                            tr.classList.remove('review-ok', 'review-issue', 'review-checking');
                            var cls = { 1: 'review-ok', 2: 'review-issue', 3: 'review-checking' }[statusVal];
                            if (cls) tr.classList.add(cls);
                            tr.setAttribute('data-review', statusVal);
                            tr.setAttribute('data-review-comment', comment);
                            setReviewInputLock(tr, statusVal === 1);   // honours an admin unlock
                        }
                    }
                    done++;
                }).fail(function () { failed++; })
                  .always(function () { bulkProgress(done + failed, ids.length, 'Applying "' + label + '"…'); next(i + 1); });
            })(0);
        });
    };

    // ── Bulk: ask the selected employees to review their payslip ──
    // Repeatable — every send bumps that employee's counter.
    window.pcwNotifySelected = function () {
        var ids = psIds();
        if (!ids.length) return;
        var again = ids.filter(function (id) { var e = empById(id); return e && e.sent_n > 0; }).length;
        Swal.fire({
            title: 'Notify ' + ids.length + ' employee(s)?',
            html: 'They will be asked to review their payslip.'
                + (again ? '<div style="margin-top:8px;font-size:12px;color:#c98a00;"><b>' + again + '</b> of them were already notified before — this sends again.</div>' : '')
                + '<div style="margin-top:8px;font-size:12px;color:#888;">The payroll status is not changed.</div>',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#107c41', confirmButtonText: 'Send now'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            bulkProgress(0, ids.length, 'Notifying employees…');
            $.ajax({
                url: 'ajax.php?action=notify_payroll_review_selected', method: 'POST', dataType: 'JSON',
                data: { id: M.id, item_ids: ids.join(',') }
            }).done(function (res) {
                if (!(res && res.result)) {
                    bulkProgressDone();
                    Swal.fire({ icon: 'error', title: 'Not sent', text: (res && res.message) || 'Request failed.' });
                    return;
                }
                // Apply the fresh per-employee counters returned by the server
                var sent = res.sent || {};
                ids.forEach(function (id) {
                    var e = empById(id);
                    if (!e) return;
                    if (sent[id]) { e.sent_n = sent[id].n; e.sent_at = sent[id].at; }
                    else { e.sent_n = (e.sent_n || 0) + 1; }
                });
                bulkProgress(ids.length, ids.length, 'Sent');
                bulkProgressDone();
                buildList(); renderInsights();
                var cur = empById(S.sel);
                if (cur) renderSum(cur);
                showToast(res.message || 'Employees notified.', 'success');
            }).fail(function () {
                bulkProgressDone();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server.' });
            });
        });
    };

    byId('pcw-list').addEventListener('click', function (ev) {
        var chk = ev.target.closest('input[data-ps]');
        if (chk) {
            S.ps[chk.getAttribute('data-ps')] = chk.checked;
            updPsPill();
            ev.stopPropagation();
            return;
        }
        // Chat button opens the sign-off message without selecting the card.
        var cv = ev.target.closest('button[data-convo]');
        if (cv) {
            ev.stopPropagation();
            window.pcwOpenReviewConvo(parseInt(cv.getAttribute('data-convo'), 10));
            return;
        }
        var it = ev.target.closest('.pcw-item');
        if (it) select(parseInt(it.getAttribute('data-eid'), 10));
    });

    // "All" ticks every employee currently listed (respects the active filters)
    byId('pcw-sel-all').addEventListener('change', function () {
        var on = this.checked;
        S.list.forEach(function (e) { S.ps[e.id] = on; });
        document.querySelectorAll('#pcw-list input[data-ps]').forEach(function (c) { c.checked = on; });
        updPsPill();
    });

    // ── Filters ──
    byId('pcw-q').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); buildList(); });
    byId('pcw-dept').addEventListener('change', function () { S.dept = this.value; buildList(); pcwUpdFilterCount(); });
    byId('pcw-pos-filter').addEventListener('change', function () { S.pos = this.value; buildList(); pcwUpdFilterCount(); });
    byId('pcw-rate-filter').addEventListener('change', function () { S.rate = this.value; buildList(); pcwUpdFilterCount(); });

    // Review-mark chips
    byId('pcw-rv-chips').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.rv = b.getAttribute('data-rv');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    // Employee sign-off chips (only rendered once the batch is out for review)
    // Edit-lock chips (only rendered while the batch is out for review)
    var lockChips = byId('pcw-lock-chips');
    if (lockChips) lockChips.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.lock = b.getAttribute('data-lock');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    var ervChips = byId('pcw-erv-chips');
    if (ervChips) ervChips.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.erv = b.getAttribute('data-erv');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    // Filter popover open/close + active-count badge (global — called from onclick)
    window.pcwToggleFilter = function () {
        var pop = byId('pcw-filter-pop'), btn = byId('pcw-filter-btn');
        var open = pop.classList.toggle('open');
        btn.classList.toggle('on', open);
    };
    function pcwActiveFilters() { return (S.dept ? 1 : 0) + (S.pos ? 1 : 0) + (S.rate ? 1 : 0) + (S.rv !== '' ? 1 : 0) + (S.erv !== '' ? 1 : 0) + (S.lock !== '' ? 1 : 0); }
    window.pcwUpdFilterCount = function () {
        var n = pcwActiveFilters();
        var badge = byId('pcw-filter-count'), btn = byId('pcw-filter-btn');
        badge.textContent = n;
        badge.style.display = n ? 'flex' : 'none';
        btn.classList.toggle('on', n > 0 || byId('pcw-filter-pop').classList.contains('open'));
    };
    window.pcwResetFilters = function () {
        S.dept = ''; S.pos = ''; S.rate = ''; S.rv = ''; S.erv = ''; S.lock = '';
        byId('pcw-dept').value = '';
        byId('pcw-pos-filter').value = '';
        byId('pcw-rate-filter').value = '';
        byId('pcw-rv-chips').querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-rv') === ''); });
        if (ervChips) ervChips.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-erv') === ''); });
        if (lockChips) lockChips.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-lock') === ''); });
        buildList();
        pcwUpdFilterCount();
    };
    // Close the popover when clicking outside it
    document.addEventListener('click', function (ev) {
        var pop = byId('pcw-filter-pop');
        if (!pop.classList.contains('open')) return;
        if (pop.contains(ev.target) || byId('pcw-filter-btn').contains(ev.target)) return;
        pop.classList.remove('open');
        byId('pcw-filter-btn').classList.toggle('on', pcwActiveFilters() > 0);
    });

    // ── Prev / next ──
    byId('pcw-prev').addEventListener('click', function () {
        var idx = S.list.findIndex(function (e) { return e.id == S.sel; });
        if (idx > 0) select(S.list[idx - 1].id);
    });
    byId('pcw-next').addEventListener('click', function () {
        var idx = S.list.findIndex(function (e) { return e.id == S.sel; });
        if (idx >= 0 && idx < S.list.length - 1) select(S.list[idx + 1].id);
    });

    // ── Zoom ──
    function setZoom(z) {
        S.zoom = Math.min(1.6, Math.max(0.5, z));
        byId('pcw-paper').style.setProperty('--pcw-zoom', S.zoom);
        byId('pcw-zoom-val').textContent = Math.round(S.zoom * 100) + '%';
    }
    byId('pcw-zoom-in').addEventListener('click', function () { setZoom(S.zoom + 0.1); });
    byId('pcw-zoom-out').addEventListener('click', function () { setZoom(S.zoom - 0.1); });
    byId('pcw-zoom-val').addEventListener('click', function () { setZoom(1); });

    // ── Sync workbench data from the sheet editor's live table ──
    function syncRow(e) {
            var tr = document.querySelector('tr[data-row-id="' + e.id + '"]');
            if (!tr) return;
            var num = function (el) {
                if (!el) return null;
                var v = parseFloat(String(el.value !== undefined && el.tagName === 'INPUT' ? el.value : el.textContent).replace(/,/g, ''));
                return isNaN(v) ? null : v;
            };
            var inp = function (t) { return num(tr.querySelector('input[data-type="' + t + '"]')); };
            var cell = function (k) { return num(tr.querySelector('[data-computed="' + k + '"]')); };
            var pick = function (v, old) { return v === null ? old : v; };

            e.present = pick(inp('present'), e.present);
            e.absent = pick(inp('absent'), e.absent);
            e.late_min = pick(inp('late'), e.late_min);
            e.ot_hrs = pick(inp('ot'), e.ot_hrs);
            e.per_day = pick(inp('per_day'), e.per_day);
            e.allow_days = pick(inp('allowance_days'), e.allow_days);
            e.legal = pick(inp('legal_holiday'), e.legal);
            e.rest = pick(inp('sunday_duty'), e.rest);
            e.spc = pick(inp('special_holiday'), e.spc);
            e.sss_fund = pick(inp('sss_fund'), e.sss_fund);
            e.jei = pick(inp('jei_advances'), e.jei);
            e.jcc = pick(inp('jcc_advances'), e.jcc);
            e.tax = pick(inp('tax'), e.tax);
            e.other_ded = pick(inp('other_deduction'), e.other_ded);
            e.adj = pick(inp('adjustment'), e.adj);
            var rem = tr.querySelector('input[data-type="adjustment_remarks"]');
            if (rem) e.adj_rem = rem.value;

            tr.querySelectorAll('input[data-dd_id]').forEach(function (f) {
                var t = f.getAttribute('data-type'), dd = f.getAttribute('data-dd_id'), v = parseFloat(f.value) || 0;
                if (t === 'refund') {
                    (e.refunds || []).forEach(function (r) { if (String(r.id) === String(dd)) r.amt = v; });
                } else {
                    var g = t === 'contribution' ? 1 : (t === 'loan' ? 3 : 2);
                    (e.deds || []).forEach(function (d) { if (d.g === g && String(d.id) === String(dd)) d.amt = v; });
                }
            });

            e.absent_amt = pick(cell('absent_amount'), e.absent_amt);
            e.allow_amt = pick(cell('allowance_total'), e.allow_amt);
            e.legal_amt = pick(cell('legal_amount'), e.legal_amt);
            e.rest_amt = pick(cell('sunday_amount'), e.rest_amt);
            e.spc_amt = pick(cell('special_amount'), e.spc_amt);
            e.ot_amt = pick(cell('overtime_amount'), e.ot_amt);
            e.late_amt = pick(cell('late_amount'), e.late_amt);
            var be = cell('total_basic_rate');
            if (be === null) be = cell('total_amount');
            if (be !== null) e.basic_earned = be;
            e.gross = pick(cell('gross'), e.gross);
            e.net = pick(cell('net'), e.net);
            var td = cell('total_deductions');
            e.total_ded = td !== null ? td
                : (e.deds || []).reduce(function (s, d) { return s + d.amt; }, 0) + e.sss_fund + e.jei + e.jcc + e.tax + e.other_ded;
            e.total_ref = (e.refunds || []).reduce(function (s, r) { return s + r.amt; }, 0);
            e.rv = parseInt(tr.getAttribute('data-review') || '0', 10);
            e.rv_c = tr.getAttribute('data-review-comment') || '';
    }

    function pcwSync() {
        D.forEach(syncRow);
        buildList();
        renderInsights();
        var cur = empById(S.sel);
        if (cur) { renderPaper(cur); renderSum(cur); }
        updPsPill();
    }
    window.pcwSync = pcwSync;

    // Keep the workbench current: after saves refresh the table, then re-sync;
    // also re-sync when the sheet editor closes (live, unsaved edits included).
    window.addEventListener('load', function () {
        if (typeof window.refreshPayrollRows === 'function') {
            var orig = window.refreshPayrollRows;
            window.refreshPayrollRows = function (pid) {
                return Promise.resolve(orig(pid)).then(function (r) { pcwSync(); return r; });
            };
        }
    });
    var editorModal = document.getElementById('modal-table-editor');
    if (editorModal) {
        editorModal.addEventListener('shown.bs.modal', function () {
            if (typeof fitTableToViewport === 'function') fitTableToViewport();
            if (typeof fixStickyHeaderGap === 'function') fixStickyHeaderGap();
            if (typeof fixFrozenColumns === 'function') fixFrozenColumns();
        });
        editorModal.addEventListener('hidden.bs.modal', pcwSync);
    }

    // ── Inline editing on the computation sheet ──
    // Every keystroke writes through to the hidden table's matching input and
    // fires its 'input' event, so changedInputs, the live table recalc, and
    // Save behave exactly as they did when the table was edited directly.
    function updPaperTotals(e) {
        var set = function (id, v, neg) {
            var el = byId(id);
            if (el) el.textContent = neg ? '(' + fmt(v) + ')' : fmt(v);
        };
        set('pp-gross-amt', e.gross);
        [1, 3, 2].forEach(function (g) {
            var sub = (e.deds || []).filter(function (d) { return d.g === g; })
                .reduce(function (s, d) { return s + d.amt; }, 0);
            set('pp-sub-g' + g, sub, true);
        });
        set('pp-sub-fx', e.sss_fund + e.jei + e.jcc + e.tax + e.other_ded, true);
        set('pp-totded-amt', e.total_ded, true);
        set('pp-totref-amt', e.total_ref);
        var net = byId('pp-net-amt');
        if (net) { net.textContent = peso(e.net); net.classList.toggle('neg', e.net <= 0); }
    }

    byId('pcw-paper').addEventListener('input', function (ev) {
        var f = ev.target.closest('.pp-in');
        if (!f) return;
        var e = empById(S.sel);
        if (!e) return;
        var inp = tIn(e, f.getAttribute('data-t'), f.hasAttribute('data-dd') ? f.getAttribute('data-dd') : null);
        if (!inp) return;
        inp.value = f.value;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // On blur: pull the row's (possibly cleaned/recomputed) values back and
    // refresh totals without rebuilding the sheet, so typing focus is kept.
    byId('pcw-paper').addEventListener('change', function (ev) {
        var f = ev.target.closest('.pp-in');
        if (!f) return;
        var e = empById(S.sel);
        if (!e) return;
        var inp = tIn(e, f.getAttribute('data-t'), f.hasAttribute('data-dd') ? f.getAttribute('data-dd') : null);
        if (inp && inp.value !== f.value) f.value = inp.value;
        syncRow(e);
        updPaperTotals(e);
        renderSum(e);
        buildList();
        renderInsights();
    });

    // Mirror the table ribbon's unsaved counter onto the workbench Save button
    window.addEventListener('load', function () {
        if (typeof window.countUnsaved === 'function') {
            var origCount = window.countUnsaved;
            window.countUnsaved = function () {
                origCount();
                var b = byId('pcw-save');
                if (!b) return;
                var n = 0;
                try { n = changedInputs.length; } catch (err) { n = 0; }
                b.style.display = n ? 'inline-flex' : 'none';
                var p = byId('pcw-unsaved-n');
                if (p) p.textContent = n;
            };
        }
    });

    // Reviewer marks saved from the modal reflect immediately in the workbench
    if (typeof window.applyReviewToRow === 'function') {
        var origApply = window.applyReviewToRow;
        window.applyReviewToRow = function (itemId, st, c) {
            origApply(itemId, st, c);
            var e = empById(itemId);
            if (e) { e.rv = st; e.rv_c = c || ''; }
            buildList();
            renderInsights();
            if (S.sel == itemId && e) { renderPaper(e); renderSum(e); }
        };
    }

    // ── Init ──
    buildList();
    renderInsights();
    if (S.list.length) select(S.list[0].id);
    updPsPill();

    // ── Hide the loading overlay once the workbench is up ──
    // The panels are already rendered here; wait for full load (fonts/icons/
    // deferred scripts) before fading out, with an 8s safety cap.
    function pcwHideBoot() {
        var b = byId('pcw-boot');
        if (!b || b.classList.contains('hide')) return;
        document.body.classList.remove('pcw-booting');   // reveal the workbench
        b.classList.add('hide');                         // fade the overlay out
        setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 450);
    }
    if (document.readyState === 'complete') pcwHideBoot();
    else window.addEventListener('load', pcwHideBoot);
    setTimeout(pcwHideBoot, 8000);
})();
</script>

<!-- deferred so it runs after the deferred jQuery/bootstrap/sweetalert above -->
<script defer src="assets2/js/payroll_calculations.js"></script>
</body>
</html>