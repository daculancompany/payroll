<?php
/**
 * Payroll Details — standalone full-page document workbench for one payroll
 * (layout mirrors dtr-documents.php: no sidebar, no app header).
 *
 *   left    employee list (search + filters, payslip selection)
 *   center  the selected employee's categorized pay computation sheet
 *   right   tabbed panel — Summary / Insights (charts) / employee Review
 *
 * The classic Excel-style editing table now lives in the full-screen
 * #modal-table-editor ("Edit Sheet"); all editing/saving still runs through
 * assets2/js/payroll_calculations.js and the same ajax.php actions.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if ((empty($_SESSION['is_login'])) && !empty($_SESSION['emp_is_login'])) {
    header('Location: employee-portal.php');
    exit;
}
if (empty($_SESSION['is_login'])) {
    header('Location: login.php');
    exit;
}
if (!isset($conn)) include 'db_connect.php';
// Payroll is admin-only and this page never passes through index.php's router,
// so it has to ask the same gate itself (see PAYROLL_DTR_ROLES in db_connect.php).
require_page_access('payroll_calculations');

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

// ── Name catalog for the one-off item picker ────────────────────────────
// Suggestions come from the allowances / deductions masters plus every label
// already used on a one-off line, so ad-hoc items stay named consistently.
// Nothing is linked by id — payroll_item_extras stores the label as text —
// so a name that isn't in the masters is still typed in and saved verbatim.
$pcwExtraCatalog = ['allow' => [], 'deduct' => []];
$pcwCatSeen      = ['allow' => [], 'deduct' => []];
$pcwCatAdd = function ($bucket, $name, $desc, $used) use (&$pcwExtraCatalog, &$pcwCatSeen) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return;
    $key = mb_strtolower($name);
    if (isset($pcwCatSeen[$bucket][$key])) return;
    $pcwCatSeen[$bucket][$key] = true;
    $pcwExtraCatalog[$bucket][] = ['n' => $name, 'd' => trim((string)$desc), 'u' => $used ? 1 : 0];
};
if ($cq = $conn->query("SELECT allowance, description FROM allowances ORDER BY allowance ASC")) {
    while ($cr = $cq->fetch_assoc()) $pcwCatAdd('allow', $cr['allowance'], $cr['description'], false);
}
if ($cq = $conn->query("SELECT deduction, description FROM deductions ORDER BY deduction ASC")) {
    while ($cr = $cq->fetch_assoc()) $pcwCatAdd('deduct', $cr['deduction'], $cr['description'], false);
}
if ($pcwHasExtras && ($cq = $conn->query("SELECT DISTINCT kind, label FROM payroll_item_extras ORDER BY label ASC"))) {
    while ($cr = $cq->fetch_assoc()) {
        $pcwCatAdd((int)$cr['kind'] === 2 ? 'allow' : 'deduct', $cr['label'], '', true);
    }
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

// Shift effective on the period's last day, per employee in this payroll —
// feeds the shift filter. Rows ordered by effective_from ASC so a later
// assignment overwrites an earlier one and the newest schedule wins.
$pay_schedules = [];
$schedByEmp    = [];
$sch_to = $conn->real_escape_string($payroll['date_to'] ?? date('Y-m-d'));
$sch_q  = @$conn->query("SELECT es.employee_id, ws.description
    FROM employee_schedules es
    INNER JOIN work_schedules ws ON ws.id = es.schedule_id
    WHERE es.employee_id IN (SELECT employee_id FROM payroll_items WHERE payroll_id = $id)
      AND es.effective_from <= '$sch_to'
      AND (es.effective_to IS NULL OR es.effective_to >= '$sch_to')
    ORDER BY es.effective_from ASC");
if ($sch_q) while ($sr = $sch_q->fetch_assoc()) $schedByEmp[(int)$sr['employee_id']] = $sr['description'];
$pay_schedules = array_values(array_unique(array_values($schedByEmp)));
sort($pay_schedules, SORT_NATURAL | SORT_FLAG_CASE);

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
    <meta name="robots" content="noindex, nofollow">
    <!-- Standalone <head> (not includes/header.php), so publish the CSRF token
         here as well — this page's $.ajax calls write payroll items. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets2/js/csrf.js"></script>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets2/css/my-style.css">
    <!-- Global DTR (Form 48) template — same sheet dtr-documents.php renders -->
    <link href="<?= av('assets2/css/dtr-form48.css') ?>" rel="stylesheet">
    <!-- defer keeps these off the critical render path so the loading overlay
         paints immediately instead of the page hanging blank on the CDN fetches.
         None of the inline scripts use jQuery at parse time, so order is safe. -->
    <script defer src="assets2/js/dtr-form48.js"></script>
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
    <script defer src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="<?= av('assets2/css/payroll_calculations.css') ?>" rel="stylesheet">

<!-- ── Version History Offcanvas ──────────────────────── -->
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
                                <!-- <?php if ($payroll_r['category'] != 0): ?>
                                    <span class="pcw-meta-chip"><i class="ri-global-line"></i><?= htmlspecialchars($payroll_r['cluster']) ?></span>
                                <?php endif; ?> -->
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
                                <?php if ($pay_schedules): ?>
                                <div class="pcw-fp-lbl">Shift</div>
                                <select id="pcw-sch-filter" class="pcw-select">
                                    <option value="">All Shifts</option>
                                    <?php foreach ($pay_schedules as $ps): ?>
                                        <option value="<?= htmlspecialchars($ps, ENT_QUOTES) ?>"><?= htmlspecialchars($ps) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                                <?php /* Pay components — pull up everyone whose payslip carries
                                         (or is missing) a given line: paid leave, OT, night
                                         differential, holiday pay, rest-day duty. */ ?>
                                <div class="pcw-fp-lbl">Pay components</div>
                                <div class="pcw-rv-chips" id="pcw-has-chips">
                                    <button type="button" data-has="" class="on">All</button>
                                    <button type="button" data-has="leave" title="Has paid-leave days in this period"><i class="ri-flight-takeoff-line" style="color:#33a466;"></i> Paid leave</button>
                                    <button type="button" data-has="ot" title="Has overtime hours"><i class="ri-timer-flash-line" style="color:#3f7fe0;"></i> Overtime</button>
                                    <button type="button" data-has="nsd" title="Has night differential (10PM–6AM) hours"><i class="ri-moon-clear-line" style="color:#6642aa;"></i> Night diff</button>
                                    <button type="button" data-has="hol" title="Worked a legal or special holiday"><i class="ri-calendar-event-line" style="color:#d68830;"></i> Holiday</button>
                                    <button type="button" data-has="rest" title="Worked on a rest day"><i class="ri-hotel-bed-line" style="color:#c98a00;"></i> Rest day</button>
                                </div>
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

                    <!-- RIGHT: one tabbed panel — Summary / Insights / Review -->
                    <div class="pcw-right">
                        <div class="pcw-panel grow">
                            <div class="pcw-rtabs" id="pcw-rtabs">
                                <button type="button" class="pcw-rtab active" data-rtab="sum"><i class="ri-user-3-line"></i> Summary</button>
                                <button type="button" class="pcw-rtab" data-rtab="ins"><i class="ri-donut-chart-fill"></i> Insights</button>
                                <?php if (in_array((int)$status, [2, 3], true)): ?>
                                <button type="button" class="pcw-rtab" data-rtab="rev">
                                    <i class="ri-user-received-2-line"></i> Review
                                    <?php if ($pcwUnreadMsgs > 0): ?>
                                    <span class="pcw-rtab-unread" id="prp-unread-chip" title="Employee messages nobody has opened yet"><span id="prp-unread-n"><?= $pcwUnreadMsgs ?></span></span>
                                    <?php endif; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="pcw-rpane active" data-rpane="sum">
                                <div class="pcw-sum-body" id="pcw-sum">
                                    <div style="font-size:12px;color:#948ea5;">No employee selected.</div>
                                </div>
                            </div>
                            <div class="pcw-rpane" data-rpane="ins">
                                <div class="pcw-ins-body" id="pcw-insights"></div>
                            </div>
                            <?php if (in_array((int)$status, [2, 3], true)): ?>
                            <div class="pcw-rpane" data-rpane="rev">
                            <div class="pcw-rv-status">
                                <?php if ((int)$status === 3): ?>
                                    <span class="pcw-head-badge rev">In progress</span>
                                <?php else: ?>
                                    <span class="pcw-head-badge lock">Locked</span>
                                <?php endif; ?>
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
                                                <!-- Night Diff (2 cols) -->
                                                <th colspan="2" class="text-center info-header">Night Diff</th>
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
                                                <!-- Night Diff -->
                                                <th class="text-center info-header">Hrs</th>
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
                                            $t_nsd_hrs = 0; $t_nsd_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_ut_min = 0; $t_ut_amt = 0;
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
                                                // Daily rate → the +30% premium only; the worked rest day is already
                                                // inside `present`. Monthly/fixed keep the full-day figure, which is
                                                // what their gross has always added.
                                                $rt_row = $row['rate_type'] ?? 'daily';
                                                $sunday_duty_amount = ($rt_row === 'monthly' || $rt_row === 'fixed')
                                                    ? $sunday_duty * $perDay
                                                    : rest_day_premium($sunday_duty, $perDay);
                                                $special_holiday = $row['special_holiday'];
                                                // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;
                                                // Night differential — hours from the DTR, amount priced at calc
                                                // time (stored on the item), both editable-visible on the sheet.
                                                $nsd_hours  = (float)($row['nsd_hours'] ?? 0);
                                                $nsd_amount = (float)($row['nsd_amount'] ?? 0);

                                                // This table applied the MONTHLY formula to every employee
                                                // regardless of rate_type, so a daily-rate row here was grossed
                                                // as half a monthly salary. Now the one shared formula
                                                // (payroll_earnings, db_connect.php) decides the basis, exactly
                                                // as the authoritative net and the second table already do.
                                                $__e            = payroll_earnings($row);
                                                $total_amount   = $__e['subtotal'];
                                                $t_total_amount += $total_amount;
                                                // Named one-off items for this employee: allowances add to gross,
                                                // deductions are applied with the other deductions below.
                                                $rowExtras = $pcwExtras[(int)$row['id']] ?? [];
                                                $rowExtraT = pcw_extra_totals($rowExtras);
                                                $gross_salary   = $__e['gross'] + $rowExtraT['add'];

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
                                                $t_nsd_hrs     += $nsd_hours;
                                                $t_nsd_amt     += $nsd_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_ut_min      += $row['under_time'];
                                                $t_ut_amt      += $undertime_amount;
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
                                                            <a href="javascript:void(0);" data-emp-quickview="<?= $row['employee_id'] ?>" class="emp-avatar" title="Employee quick view"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="javascript:void(0);" data-emp-quickview="<?= $row['employee_id'] ?>" class="emp-name-link" title="Employee quick view">
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
                                                                <?php // present is a double summed from work_hours/day_hours, so it arrives as
                                                                      // 12.858571428571. Shown to 2dp like every other figure on the
                                                                      // sheet; the full precision stays in the column until an admin
                                                                      // actually edits this field (typing is what queues a save). ?>
                                                                <input type="text" value="<?= round((float)$row['present'], 2) ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class"<?= $rowRO ?> placeholder="No. of Days" aria-describedby="basic-addon2">
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

                                                    <!-- Night differential: hours carried from the DTR (read-only);
                                                         the amount was priced per day at calc time, so the amount —
                                                         not the hours — is the editable figure. -->
                                                    <td style="min-width: 70px;" class="text-center">
                                                        <b><?= number_format($nsd_hours, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $nsd_amount ?>" data-id="<?= $row['id'] ?>" data-type="nsd_amount" class="form-control input-class"<?= $rowRO ?> placeholder="Amount" aria-label="Night Diff Amount">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($nsd_amount, 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <!-- /night diff -->

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
                                                        'pleave' => (float)($row['paid_leave'] ?? 0),
                                                        'sch' => (string)($schedByEmp[(int)$row['employee_id']] ?? ''),
                                                        'nsd_hrs' => (float)($row['nsd_hours'] ?? 0), 'nsd_amt' => (float)($row['nsd_amount'] ?? 0),
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
                                                <th class="text-center"><?= number_format($total_number_days, 2) ?></th>
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
                                                <th class="text-center"><?= number_format($t_nsd_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_nsd_amt, 2) ?></th>
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
                                                <th colspan="2" class="text-center info-header">Night Diff</th>
                                                <th colspan="3" class="text-center info-header">Late</th>
                                                <th colspan="3" class="text-center info-header">Undertime</th>
                                                <?php /* Holiday + rest-day duty. Days are auto-counted from the holiday
                                                        calendar during calculation (rest day from the roster); the inputs
                                                        stay editable so an admin can still override a day. */ ?>
                                                <th colspan="6" class="text-center info-header">Holiday / Rest Day</th>
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

                                                <th class="text-center  info-header">ND Hrs</th>
                                                <th class="text-center  info-header">ND Amount</th>

                                                <th class="text-center  info-header">Min</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <?php /* Undertime — same Min/Rate/Amount shape as Late above. */ ?>
                                                <th class="text-center  info-header">Min</th>
                                                <th class="text-center  info-header">Rate</th>
                                                <th class="text-center  info-header">Amount</th>

                                                <th class="text-center  info-header">Legal Hol. dys</th>
                                                <th class="text-center  info-header">Legal Hol. Amount</th>
                                                <th class="text-center  info-header">Rest Day dys</th>
                                                <th class="text-center  info-header">Rest Day Amount</th>
                                                <th class="text-center  info-header">Spec. Hol. dys</th>
                                                <th class="text-center  info-header">Spec. Hol. Amount</th>

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
                                            $t_nsd_hrs = 0; $t_nsd_amt = 0;
                                            $t_late_min = 0; $t_late_amt = 0;
                                            $t_ut_min = 0; $t_ut_amt = 0;
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
                                                // Daily rate → the +30% premium only; the worked rest day is already
                                                // inside `present`. Monthly/fixed keep the full-day figure, which is
                                                // what their gross has always added.
                                                $rt_row = $row['rate_type'] ?? 'daily';
                                                $sunday_duty_amount = ($rt_row === 'monthly' || $rt_row === 'fixed')
                                                    ? $sunday_duty * $perDay
                                                    : rest_day_premium($sunday_duty, $perDay);
                                                $special_holiday = $row['special_holiday'];
                                                // /8 * 2.4 is the 30% special-holiday premium (= * 0.3), NOT a day-length divisor.
                                                $special_holiday_amount =  (($perDay / 8) * 2.4) *  $special_holiday;
                                                // Night differential — hours from the DTR, amount priced at calc
                                                // time (stored on the item), both editable-visible on the sheet.
                                                $nsd_hours  = (float)($row['nsd_hours'] ?? 0);
                                                $nsd_amount = (float)($row['nsd_amount'] ?? 0);

                                                // Basic pay for the period + gross, from the ONE shared formula
                                                // (payroll_earnings, db_connect.php):
                                                //   monthly/fixed → basic_pay ÷ 2, less absences
                                                //   daily         → (days present + paid leave) × rate per day
                                                //
                                                // This block used to keep its own copy and had drifted three ways:
                                                // it halved absences AND the allowance on the monthly basis, and its
                                                // daily branch left legal- and special-holiday pay out of gross —
                                                // so a daily employee who worked a holiday saw a different gross here
                                                // than on the payslip printed from the same row.
                                                $__e2             = payroll_earnings($row);
                                                $total_basic_rate = $__e2['subtotal'];
                                                $gross_salary     = $__e2['gross'];
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
                                                $t_nsd_hrs     += $nsd_hours;
                                                $t_nsd_amt     += $nsd_amount;
                                                $t_late_min    += $row['late'];
                                                $t_late_amt    += $late_amount;
                                                $t_ut_min      += $row['under_time'];
                                                $t_ut_amt      += $undertime_amount;
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
                                                            <a href="javascript:void(0);" data-emp-quickview="<?= $row['employee_id'] ?>" class="emp-avatar" title="Employee quick view"><?= $emp_initials ?></a>
                                                            <div class="emp-cell-info">
                                                                <a href="javascript:void(0);" data-emp-quickview="<?= $row['employee_id'] ?>" class="emp-name-link" title="Employee quick view">
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
                                                                <?php // present is a double summed from work_hours/day_hours, so it arrives as
                                                                      // 12.858571428571. Shown to 2dp like every other figure on the
                                                                      // sheet; the full precision stays in the column until an admin
                                                                      // actually edits this field (typing is what queues a save). ?>
                                                                <input type="text" value="<?= round((float)$row['present'], 2) ?>" data-id="<?= $row['id'] ?>" data-type="present" class="form-control input-class"<?= $rowRO ?> placeholder="No. of Days" aria-describedby="basic-addon2">
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
                                                        <?php
                                                        // Amount = the hand-typed slot (days × rate) PLUS the employee's
                                                        // configured allowance types, frozen on the item at calculation.
                                                        // Listed in the tooltip so a blended figure can still be read
                                                        // back to its parts — the whole point of moving recurring
                                                        // allowances out of the generic Adjustment column.
                                                        $__alist = payroll_allowance_list($row);
                                                        $__atip  = [];
                                                        if ($total_allowance > 0) $__atip[] = 'Manual: ' . number_format($total_allowance, 2);
                                                        foreach ($__alist as $__a) {
                                                            $__atip[] = $__a['label'] . ': ' . number_format((float) $__a['amount'], 2);
                                                        }
                                                        $__atot = $__e2['allowance'];
                                                        ?>
                                                        <b data-computed="allowance_total"
                                                           <?= $__alist ? 'style="border-bottom:1px dotted #888;cursor:help;" title="' . htmlspecialchars(implode("\n", $__atip), ENT_QUOTES) . '"' : '' ?>><?= number_format($__atot, 2) ?></b>
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

                                                    <!-- Night differential: hours carried from the DTR (read-only);
                                                         the amount was priced per day at calc time, so the amount —
                                                         not the hours — is the editable figure. -->
                                                    <td style="min-width: 70px;" class="text-center">
                                                        <b><?= number_format($nsd_hours, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $nsd_amount ?>" data-id="<?= $row['id'] ?>" data-type="nsd_amount" class="form-control input-class"<?= $rowRO ?> placeholder="Amount" aria-label="Night Diff Amount">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= number_format($nsd_amount, 2) ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <!-- /night diff -->

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

                                                    <!-- Undertime — leaving before the shift ends. Same unit and same
                                                         per-minute rate as Late (it is lost time, not a premium); the
                                                         two never overlap because the DTR measures late from the shift
                                                         START and undertime to the shift END. -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['under_time'] ?>" data-id="<?= $row['id'] ?>" data-type="under_time" class="form-control input-class"<?= $rowRO ?> placeholder="Minutes" aria-label="Undertime minutes">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['under_time'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td style="min-width: 100px;" class="text-right">
                                                        <b><?= number_format($perMinute, 2) ?></b>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="undertime_amount"><?= number_format($undertime_amount, 2) ?></b>
                                                    </td>
                                                    <!-- /Undertime -->

                                                    <!-- Holiday / Rest Day. The day counts come from the calculation
                                                         (holiday calendar + roster) and stay overridable here; the
                                                         amounts beside them are computed. -->
                                                    <td style="min-width: 90px;" class="text-center">
                                                        <?php if ($rowShowInputs) { ?>
                                                            <div class="input-group mb-3">
                                                                <input type="text" value="<?= $row['legal_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="legal_holiday" class="form-control input-class"<?= $rowRO ?> placeholder="Days" aria-label="Legal Holiday Days">
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
                                                                <input type="text" value="<?= $row['sunday_duty'] ?>" data-id="<?= $row['id'] ?>" data-type="sunday_duty" class="form-control input-class"<?= $rowRO ?> placeholder="Days" aria-label="Rest Day Duty Days">
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
                                                                <input type="text" value="<?= $row['special_holiday'] ?>" data-id="<?= $row['id'] ?>" data-type="special_holiday" class="form-control input-class"<?= $rowRO ?> placeholder="Days" aria-label="Special Holiday Days">
                                                            </div>
                                                        <?php } else { ?>
                                                            <b><?= $row['special_holiday'] ?></b>
                                                        <?php } ?>
                                                    </td>
                                                    <td class="text-right" style="min-width: 90px;">
                                                        <b data-computed="special_amount"><?= number_format($special_holiday_amount, 2) ?></b>
                                                    </td>
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
                                                        'pleave' => (float)($row['paid_leave'] ?? 0),
                                                        'sch' => (string)($schedByEmp[(int)$row['employee_id']] ?? ''),
                                                        'nsd_hrs' => (float)($row['nsd_hours'] ?? 0), 'nsd_amt' => (float)($row['nsd_amount'] ?? 0),
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
                                                <th class="text-center"><?= number_format($total_number_days, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_per_day, 2) ?></th>
                                                <th class="text-right" id="tfoot-basic-rate"><?= number_format($t_basic_rate, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_allow_days, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_allow_total, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ot_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_rate, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ot_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_nsd_hrs, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_nsd_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_late_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_late_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_ut_min, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_late, 2) ?></th>
                                                <th class="text-right"><?= number_format($t_ut_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_legal_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_legal_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_sun_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_sun_amt, 2) ?></th>
                                                <th class="text-center"><?= number_format($t_spc_d, 0) ?></th>
                                                <th class="text-right"><?= number_format($t_spc_amt, 2) ?></th>
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

<!-- One-off allowance / deduction for a single payslip (driven by extraDialog) -->
<div class="modal fade" id="modal-extra-item" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:470px;">
        <div class="modal-content" style="border-radius:12px;">
            <div class="modal-header" style="border-bottom:1px solid #ece9f3;">
                <h5 class="modal-title mb-0" id="xi-title" style="font-size:15px;font-weight:700;"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="xi-note">Applies to <b id="xi-who">this employee only</b> and prints as its own line on their payslip.</p>

                <label class="xi-lbl" for="xi-label">Name <span style="color:#c62828;">*</span></label>
                <div class="xi-ac">
                    <i class="ri-search-line xi-ac-ic"></i>
                    <input type="text" id="xi-label" class="form-control" maxlength="120" autocomplete="off"
                        role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="xi-menu"
                        placeholder="Search the list, or type a new name">
                    <div class="xi-ac-menu" id="xi-menu" role="listbox"></div>
                </div>
                <div class="xi-err" id="xi-label-err"></div>

                <label class="xi-lbl mt-3" for="xi-amount">Amount <span style="color:#c62828;">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">&#8369;</span>
                    <input type="number" id="xi-amount" class="form-control" step="0.01" min="0.01" placeholder="0.00">
                </div>
                <div class="xi-err" id="xi-amount-err"></div>

                <div class="xi-warn" id="xi-dup"><i class="ri-alert-line"></i><span></span></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #ece9f3;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm text-white" id="xi-save"
                    style="background:#6642aa;border:none;font-weight:600;"></button>
            </div>
        </div>
    </div>
</div>

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


    <!-- Server data bridge. These are the ONLY lines in this block that need
         PHP, so they stay inline and the rest of the page script moves to a
         cacheable file. `var` at top level is a global, so code in that file
         still sees PCW_IS_ADMIN. -->
    <script>
window.PCW_DATA = <?= json_encode($pcwEmployees, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
window.PCW_REVIEWS = <?= json_encode($pcwReviewConvo, JSON_UNESCAPED_UNICODE) ?>;
window.PCW_EXTRA_CATALOG = <?= json_encode($pcwExtraCatalog, JSON_UNESCAPED_UNICODE) ?>;
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
    var PCW_IS_ADMIN = <?= ((int)($_SESSION['login_role'] ?? 0) === 1) ? 'true' : 'false' ?>;
    </script>
    <!-- Plain (not deferred) and in the block's original position, so execution
         order relative to the other inline scripts is exactly as before. -->
    <script src="<?= av('assets2/js/payroll_calculations.page.js') ?>"></script>


<!-- deferred so it runs after the deferred jQuery/bootstrap/sweetalert above -->
<script defer src="assets2/js/payroll_calculations.js"></script>

<?php
// Employee quick-view drawer (avatar / name clicks on the sheet). This page is
// a standalone workbench, so "Full details" opens in a new tab.
$eqv_full_target = '_blank';
include __DIR__ . '/component/employee_quick_view.php';
?>
</body>
</html>