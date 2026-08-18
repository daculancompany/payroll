<?php
if (!isset($_GET['id']) || !isset($_GET['device_id']) || !isset($_GET['site_id'])) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
};

$id =  base64_decode($_GET['id']);
$device_id =  base64_decode($_GET['device_id']);
$site_id =  base64_decode($_GET['site_id']);
$timekeeper_name = base64_decode($_GET['timekeeper_name']);

$query = "SELECT DTR.*, sites.site_code, sites.site_name, employer_name FROM DTR
        LEFT JOIN sites ON sites.id = DTR.site_id
        LEFT JOIN employers ON sites.employer_id = employers.id
        WHERE DTR.id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die('<b>MySQL prepare error:</b> ' . htmlspecialchars($conn->error) . '<br><b>Query:</b> ' . htmlspecialchars($query));
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$dtr = $result->fetch_assoc();

if (!$dtr) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
}

// ── Output trimming for the big record loops ─────────────────────────────────
// The row markup is indented ~60 columns deep and each record spans dozens of
// lines, so on a full batch more than half the HTML is leading whitespace —
// ~37 MB the browser's parser has to walk for nothing. These wrap only the three
// record loops (by-day tbody + the two <template> blocks); the rest of the page
// is left alone so nothing inside <pre>, <textarea> or inline <script> is touched.
function dtr_trim_start()
{
    ob_start();
}
function dtr_trim_end()
{
    echo preg_replace(['/^[ \t]+/m', '/\n{2,}/'], ['', "\n"], ob_get_clean());
}

function stringToArrayBySpaces($string)
{
    $split_array = preg_split('/\s{7}+/', $string, -1, PREG_SPLIT_NO_EMPTY);
    return $split_array;
}

function getDataBySpaces($dataString)
{
    $splitData = [];
    $currentElement = "";

    foreach (str_split($dataString) as $char) {
        if (ctype_space($char)) {
            if ($currentElement) {
                $splitData[] = $currentElement;
                $currentElement = "";
            }
        } else {
            $currentElement .= $char;
        }
    }

    if ($currentElement) {
        $splitData[] = $currentElement;
    }

    return $splitData;
}

// Uploaded DTR files carry base64 content after a comma ("data:...,<base64>").
// Biometric attendance has file='biometric' (no encoded payload), so guard the decode.
$file_parts   = explode(",", $dtr['file']);
$decoded_data = isset($file_parts[1]) ? base64_decode($file_parts[1]) : '';
$result_array = $decoded_data !== '' ? stringToArrayBySpaces($decoded_data) : [];
$is_duplicate = false;

// ── Batch aggregates ─────────────────────────────────────────────────────────
// The page used to SELECT every DTR_details row of the batch and group it in PHP
// (4,310 rows on a 15-day / 361-employee batch) purely to draw the summary cards
// and three full renderings of the same data. The summary now comes from one
// aggregate query and the employee cards are paginated — the first page is
// rendered below, the rest come from dtr-employee-server.php.
$sumStmt = $conn->prepare("
    SELECT COUNT(*)                          AS total,
           SUM(status = 1)                   AS approved,
           SUM(status = 2)                   AS disapproved,
           SUM(status <> 1 AND status <> 2)  AS pending,
           COALESCE(SUM(work_hours), 0)      AS work_hours,
           COALESCE(SUM(overtime), 0)        AS overtime,
           COALESCE(SUM(undertime), 0)       AS undertime,
           COALESCE(SUM(late), 0)            AS late,
           COUNT(DISTINCT employee_id)       AS employees,
           COUNT(DISTINCT date_time)         AS days
    FROM DTR_details WHERE ddtr_id = ?
");
$sumStmt->bind_param("i", $id);
$sumStmt->execute();
$agg = $sumStmt->get_result()->fetch_assoc() ?: [];

$grandTotals = [
    "work_hours"  => (float)($agg["work_hours"] ?? 0),
    "overtime"    => (float)($agg["overtime"] ?? 0),
    "undertime"   => (float)($agg["undertime"] ?? 0),
    "late"        => (float)($agg["late"] ?? 0),
    "approved"    => (int)($agg["approved"] ?? 0),
    "disapproved" => (int)($agg["disapproved"] ?? 0),
    "pending"     => (int)($agg["pending"] ?? 0),
    "total"       => (int)($agg["total"] ?? 0),
];
$empCount   = (int)($agg["employees"] ?? 0);
$daysLogged = (int)($agg["days"] ?? 0);

// Cross-batch duplicates: same employee, same date, different DTR batch.
// Reported as a count only. The old per-row check ran 4,310 queries and set a
// flag that the ribbon had already read, so it never actually gated anything —
// gating behaviour is left exactly as it was.
$dupCount = 0;
$dupStmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM DTR_details a
    INNER JOIN DTR_details b
        ON b.employee_id = a.employee_id AND b.date_time = a.date_time AND b.ddtr_id <> a.ddtr_id
    WHERE a.ddtr_id = ?
");
$dupStmt->bind_param("i", $id);
$dupStmt->execute();
$dupCount = (int)($dupStmt->get_result()->fetch_assoc()["c"] ?? 0);

// ── First page of employee cards (rendered inline, no round trip) ────────────
require_once "component/dtr_employee_card.php";
define("DTR_EMP_PAGE_SIZE", 10);

$firstPage = [];
$pageIds = [];
$pi = $conn->prepare("
    SELECT e.id
    FROM DTR_details d
    INNER JOIN employee e ON d.employee_id = e.id
    WHERE d.ddtr_id = ?
    GROUP BY e.id
    ORDER BY e.lastname ASC, e.firstname ASC
    LIMIT " . DTR_EMP_PAGE_SIZE . "
");
$pi->bind_param("i", $id);
$pi->execute();
$pr = $pi->get_result();
while ($row = $pr->fetch_assoc()) $pageIds[] = (int)$row["id"];

$byEmployee = $employeeTotals = [];
if ($pageIds) {
    $idList = implode(",", $pageIds);
    $fr = $conn->prepare("
        SELECT a.*, e.employee_no, e.lastname, e.firstname, e.middlename,
               dep.name AS department, p.name AS position,
               DATE(a.date_time) AS attendance_date
        FROM DTR_details a
        INNER JOIN employee e ON a.employee_id = e.id
        LEFT JOIN department dep ON e.department_id = dep.id
        LEFT JOIN position p ON e.position_id = p.id
        WHERE a.ddtr_id = ? AND a.employee_id IN ($idList)
        ORDER BY a.date_time ASC
    ");
    $fr->bind_param("i", $id);
    $fr->execute();
    $frRes = $fr->get_result();
    while ($row = $frRes->fetch_assoc()) {
        $eid  = (int)$row["employee_id"];
        $date = $row["attendance_date"];
        if (!isset($byEmployee[$eid])) {
            $byEmployee[$eid]     = ["employee_info" => $row, "dates" => []];
            $employeeTotals[$eid] = ["work_hours" => 0, "overtime" => 0, "undertime" => 0, "late" => 0];
        }
        $byEmployee[$eid]["dates"][$date][] = $row;
        $employeeTotals[$eid]["work_hours"] += floatval($row["work_hours"]);
        $employeeTotals[$eid]["overtime"]   += floatval($row["overtime"]);
        $employeeTotals[$eid]["undertime"]  += floatval($row["undertime"]);
        $employeeTotals[$eid]["late"]       += floatval($row["late"]);
    }
}
// ── Employee review progress (whole-batch sign-off) ──
$reviewTotalEmp = count($byEmployee);
$reviewRows = [];
$reviewConfirmed = $reviewDisputed = 0;
// seen_at ships in 2026_07_review_seen.sql — degrade to "all read" without it.
$ddHasSeen = (bool)($conn->query("SHOW COLUMNS FROM dtr_employee_reviews LIKE 'seen_at'")->num_rows ?? 0);
$ddReviewConvo = [];
$ddUnreadMsgs  = 0;
$rvq = $conn->query("SELECT r.id, r.employee_id, r.status, r.comment, r.reviewed_at,
        r.resolved_at, r.admin_reply, " . ($ddHasSeen ? "r.seen_at" : "NULL AS seen_at") . ",
        CONCAT(e.lastname, ', ', e.firstname) AS name
    FROM dtr_employee_reviews r
    INNER JOIN employee e ON e.id = r.employee_id
    WHERE r.ddtr_id = " . (int)$id . "
    ORDER BY r.status ASC, r.reviewed_at DESC");
if ($rvq) while ($rv = $rvq->fetch_assoc()) {
    $reviewRows[$rv['employee_id']] = $rv;
    if ((int)$rv['status'] === 1) $reviewConfirmed++;
    elseif ((int)$rv['status'] === 2) $reviewDisputed++;

    $hasMsg = trim((string)($rv['comment'] ?? '')) !== '';
    $unread = $hasMsg && empty($rv['seen_at']);
    if ($unread) $ddUnreadMsgs++;
    $ddReviewConvo[(int)$rv['employee_id']] = [
        'id'     => (int)$rv['id'],
        'st'     => (int)$rv['status'],
        'name'   => $rv['name'],
        'c'      => (string)($rv['comment'] ?? ''),
        'at'     => !empty($rv['reviewed_at']) ? date('M j, Y g:i A', strtotime($rv['reviewed_at'])) : '',
        'rep'    => (string)($rv['admin_reply'] ?? ''),
        'rep_at' => !empty($rv['resolved_at']) ? date('M j, Y g:i A', strtotime($rv['resolved_at'])) : '',
        'new'    => $unread ? 1 : 0,
    ];
}
$reviewPending = max(0, $reviewTotalEmp - $reviewConfirmed - $reviewDisputed);

// ── Period day count ──
// $periodDays = calendar days covered by the batch (inclusive, e.g. Jul 1–13 = 13).
// $daysLogged = days that actually have attendance records, so the view counter can
// show "12 of 13 days" and make a missing day obvious at a glance.
$periodDays = 0;
if (!empty($dtr['date_from']) && !empty($dtr['date_to'])) {
    $dFrom = date_create($dtr['date_from']);
    $dTo   = date_create($dtr['date_to']);
    if ($dFrom && $dTo) {
        $periodDays = (int)$dFrom->diff($dTo)->days + 1;
    }
}
// $daysLogged comes from the aggregate query above (COUNT(DISTINCT date_time)).
?>

<link rel="stylesheet" href="assets2/css/my-style.css">
<style>
/* ── DTR Details — #6642aa brand theme ── */
#print-section { display: none; }

/* ── Table headers — soft pastel mint bands (was a solid purple fill) ── */
/* z-index:14 beats frozen body cols (z-index:12) so header stays on top when scrolling */
#table-1 thead th { background-color:#f0edf5 !important; color:#4f3288 !important; border-color:#ddd9e7 !important; font-weight:700 !important; z-index:14 !important; }
#table-1 thead tr:first-child th,
#table-1 thead tr:first-child th.primary-header,
#table-1 thead tr:first-child th.info-header,
#table-1 thead tr:first-child th.success-header,
#table-1 thead tr:first-child th.danger-header { background-color:#e1dcec !important; color:#4f3288 !important; border-color:#cfc8de !important; font-weight:800 !important; z-index:14 !important; }
/* Corner cells (top+left sticky) need highest z-index */
#table-1 thead tr:first-child th:nth-child(1),
#table-1 thead tr:first-child th:nth-child(2),
#table-1 thead tr:nth-child(2) th:nth-child(1),
#table-1 thead tr:nth-child(2) th:nth-child(2) { z-index:16 !important; }
#table-1 thead tr:nth-child(2) th.primary-header,
#table-1 thead tr:nth-child(2) th.info-header,
#table-1 thead tr:nth-child(2) th.success-header,
#table-1 thead tr:nth-child(2) th.danger-header { background-color:#f0edf5 !important; color:#4f3288 !important; border-color:#ddd9e7 !important; z-index:14 !important; }
.table-responsive2 { border-color:#c0e0c8 !important; }
#table-1 tbody td:nth-child(1), #table-1 tfoot th:nth-child(1) {
    background-color:#f1f6f2 !important; color:#0b5e31 !important; border-right-color:#b8d8c2 !important;
}
#table-1 tbody td:nth-child(2), #table-1 tfoot th:nth-child(2) {
    background-color:#eaf3ec !important; color:#0b5e31 !important; border-right-color:#b8d8c2 !important;
}
#table-1 tbody tr:nth-child(even) td { background-color:#fafcfa !important; }
#table-1 tbody tr:hover td { background-color:#eaf6ef !important; }
.xl-ribbon { border-top-color:#6642aa !important; }
.xl-ribbon-title { color:#4e3483 !important; }
.xl-ribbon-title i { color:#6642aa !important; }
.xl-btn i { color:#6642aa !important; }
/* Soft ribbon buttons — pastel mint fill, deeper on hover */
.xl-btn { background:#f2f0f7 !important; border:1px solid #ddd9e7 !important; color:#4e3483 !important; border-radius:8px !important; }
.xl-btn:hover { background:#e5e1ef !important; border-color:#c0b5d5 !important; color:#4e3483 !important; }
.xl-btn-save { background:#e1dcec !important; border-color:#c0b5d5 !important; color:#4f3288 !important; animation:none !important; }
.xl-btn-save i { color:#4f3288 !important; }
.xl-btn-save:hover { background:#d2cae2 !important; border-color:#aa9bc7 !important; color:#462c79 !important; }
.xl-search-wrap i { color:#6642aa !important; }
.xl-search-wrap:focus-within { border-color:#6642aa !important; box-shadow:0 0 0 2px rgba(102,66,170,0.15) !important; }

/* ── Employee review progress panel ── */
.dtr-review-panel { border:1px solid #cdeeda; background:#f6fbf8; border-radius:10px; padding:10px 14px; margin-bottom:10px; }
.drp-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.drp-title { font-size:13px; font-weight:700; color:#4e3483; display:flex; align-items:center; gap:6px; }
.drp-counts { display:flex; gap:6px; flex-wrap:wrap; }
.drp-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:12px; }
.drp-chip.appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.drp-chip.disp { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.drp-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.drp-chip.unread { background:#eef2fd; color:#3557b7; border:1px solid #ccd9f7; }
.drp-disputes { margin-top:8px; display:flex; flex-direction:column; gap:3px; }
.drp-confirmed-lbl { font-weight:700; color:#0f9d58; margin-right:4px; }
/* ── Name-only sign-off rows (mirrors payroll_calculations.php) ────────────
   Icon, name, and a chat button when the employee left a message; the message
   itself opens in #modal-emp-review. UNREAD until someone opens it. */
.drp-note-pill { display:inline-flex; align-items:center; gap:3px; background:#fff6e2; border:1px solid #f2dfae;
    color:#a9700a; border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.drp-confirms { margin-top:6px; display:flex; flex-direction:column; gap:3px; }
.drp-confirms.is-scroll { max-height:190px; overflow-y:auto; padding-right:4px; }
.drp-row { display:flex; align-items:center; gap:6px; min-width:0; padding:4px 8px; border-radius:7px;
    border:1px solid transparent; font-size:11.5px; }
.drp-row.ok   { background:#f2faf6; border-color:#dceee5; }
.drp-row.disp { background:#fff5f5; border-color:#f3d3d3; }
.drp-row-ic { font-size:13px; flex-shrink:0; }
.drp-row.ok   .drp-row-ic { color:#0f9d58; }
.drp-row.disp .drp-row-ic { color:#c62828; }
.drp-row-name { flex:1; min-width:0; font-weight:700; color:#3c3846; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.drp-row-done { color:#0f9d58; font-size:12px; flex-shrink:0; }
.drp-row-new { flex-shrink:0; font-size:8.5px; font-weight:800; letter-spacing:.4px; color:#fff;
    background:#3557b7; border-radius:8px; padding:1px 5px; }
.drp-row-msg { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
    width:20px; height:20px; border-radius:6px; background:#fff6e2; border:1px solid #f2dfae; color:#a9700a; font-size:11px; }
.drp-row.unread { border-color:#ccd9f7; box-shadow:0 0 0 1px #ccd9f7 inset; }
.drp-row.unread .drp-row-name { color:#1f3a80; }
.drp-row.unread .drp-row-msg { background:#eef2fd; border-color:#ccd9f7; color:#3557b7; }
.drp-row.has-msg { cursor:pointer; }
.drp-row.has-msg:hover { filter:brightness(.97); }
.drp-row.has-msg:hover .drp-row-msg { background:#a9700a; border-color:#a9700a; color:#fff; }
.drp-row.has-msg:focus-visible { outline:2px solid #6642aa; outline-offset:1px; }
#modal-emp-review .mer-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
#modal-emp-review .mer-name { font-size:14px; font-weight:800; color:#3c3846; }
.mer-badge { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:800;
    padding:2px 9px; border-radius:12px; border:1px solid; }
.mer-badge.ok   { background:#eafaf0; color:#0f9d58; border-color:#b7e4c7; }
.mer-badge.disp { background:#fdecea; color:#c62828; border-color:#f5c6cb; }
.mer-when { font-size:10.5px; color:#948ea5; margin-left:auto; }
.mer-empty { text-align:center; color:#948ea5; font-size:12.5px; padding:22px 12px; }
.mer-chat { display:flex; flex-direction:column; gap:6px; }
.mer-bub { max-width:85%; padding:6px 10px; border-radius:11px; font-size:11.5px; line-height:1.4; word-break:break-word; }
.mer-bub.them { align-self:flex-start; background:#f1f3f2; color:#3c3846; border-bottom-left-radius:3px; }
.mer-bub.me   { align-self:flex-end; background:#e1dcec; color:#4f3288; border-bottom-right-radius:3px; }
.mer-bub .m { display:block; font-size:8.5px; opacity:.7; margin-top:3px; }
/* ── Large batches ──────────────────────────────────────────────────────────
   Mirrors payroll_calculations.php: with hundreds of employees the confirmed
   names used to render as one runaway line and disputes stacked unbounded.
   Names now collapse behind a count; disputes scroll once there are a few. */
.drp-names { margin-top:8px; }
.drp-names > summary { list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    font-size:11px; font-weight:700; color:#0f9d58; user-select:none; }
.drp-names > summary::-webkit-details-marker { display:none; }
.drp-names > summary:hover .drp-names-hint { text-decoration:underline; }
.drp-count-pill { background:#e9f7ef; border:1px solid #b7e4c7; color:#0f9d58;
    border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.drp-names-hint { font-weight:500; color:#8a8a8a; }
.drp-names .lbl-hide, .drp-names[open] .lbl-show { display:none; }
.drp-names[open] .lbl-hide { display:inline; }
.drp-disputes.is-scroll { max-height:210px; overflow-y:auto; padding-right:4px; }
.drp-act-btn { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:2px 9px; border-radius:12px; border:1px solid; cursor:pointer; text-decoration:none; }
.drp-act-btn.remind { background:#fff8e1; color:#c98a00; border-color:#ffe082; }
.drp-act-btn.export { background:#eef2fb; color:#394b7c; border-color:#c3c9e0; }
.drp-act-btn.resolve { margin-top:5px; background:#e9f7ef; color:#0f9d58; border-color:#b7e4c7; }
.drp-act-btn:hover { filter:brightness(0.97); }
.drp-resolved { margin-top:5px; font-size:11.5px; color:#0f9d58; font-weight:600; background:#f0faf3; border:1px solid #cdeeda; border-radius:6px; padding:4px 8px; }

/* ── Summary stat cards — soft bordered cards with a colored top accent ── */
.dtr-stats-row { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.dtr-stat-box {
    flex:1; min-width:150px;
    border:1px solid #e1dfdd; border-top:3px solid #6642aa; border-radius:10px; background:#fff;
    padding:11px 16px; display:flex; align-items:center; gap:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    transition:box-shadow .15s, transform .15s;
}
.dtr-stat-box:hover { box-shadow:0 4px 14px rgba(0,0,0,.09); transform:translateY(-1px); }
.dtr-stat-box .stat-icon {
    width:36px; height:36px; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    font-size:16px; flex-shrink:0;
}
.dtr-stat-box .stat-val  { font-size:17px; font-weight:800; line-height:1.1; font-family:'Segoe UI',Arial,sans-serif; }
.dtr-stat-box .stat-lbl  { font-size:10px; color:#a19f9d; text-transform:uppercase; letter-spacing:0.5px; margin-top:2px; }
/* Per-stat accent: top border + faint gradient wash matching the metric color */
.dtr-stat-box.sb-wh   { border-top-color:#6642aa; background:linear-gradient(180deg,#f8f7fb 0%,#fff 60%); }
.dtr-stat-box.sb-ot   { border-top-color:#f7b84b; background:linear-gradient(180deg,#fffcf3 0%,#fff 60%); }
.dtr-stat-box.sb-ut   { border-top-color:#50a5f1; background:linear-gradient(180deg,#f4f9fe 0%,#fff 60%); }
.dtr-stat-box.sb-late { border-top-color:#f06548; background:linear-gradient(180deg,#fef6f4 0%,#fff 60%); }
.dtr-stat-box.sb-appr { border-top-color:#0f9d58; background:linear-gradient(180deg,#f3fbf6 0%,#fff 60%); }
.dtr-stat-box.sb-disa { border-top-color:#c62828; background:linear-gradient(180deg,#fdf5f5 0%,#fff 60%); }
.dtr-stat-box.sb-pend { border-top-color:#c98a00; background:linear-gradient(180deg,#fffcf3 0%,#fff 60%); }

/* ── Collapsible date groups ── */
.date-separator { cursor:pointer; user-select:none; }
.dtr-chevron { font-size:16px; transition:transform 0.2s; }
.date-separator.collapsed .dtr-chevron { transform:rotate(-90deg); }
.dtr-group-row.dtr-hidden { display:none !important; }

/* ── New clean layout helpers ── */
.dtr-date-label { font-weight:700; font-size:12px; letter-spacing:0.2px; }
.dtr-emp-count  { font-size:10px; padding:1px 8px; border-radius:10px; background:rgba(79,50,136,0.10); margin-left:6px; }
.dtr-date-totals { display:flex; gap:14px; font-size:11px; opacity:0.9; }
.dtr-emp-init {
    width:28px; height:28px; border-radius:8px;
    background:#d9eedd; color:#0b5e31; font-size:10px; font-weight:800;
    border:1px solid #c0e0c8;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.dtr-emp-name { font-size:12px; color:#4e3483; font-weight:600; }
.dtr-emp-pos  { font-size:11px; color:#745aa4; }
.dtr-emp-totals { display:flex; gap:10px; }
.dtr-tot-item { display:flex; flex-direction:column; align-items:center; min-width:40px; }
.dtr-tot-item .tot-lbl { font-size:9px; color:#888; text-transform:uppercase; letter-spacing:0.3px; }
.dtr-tot-item .tot-val { font-size:12px; font-weight:700; color:#6642aa; line-height:1.1; }
.dtr-tot-item.ot   .tot-val { color:#c98a00; }
.dtr-tot-item.ut   .tot-val { color:#1565c0; }
.dtr-tot-item.late .tot-val { color:#c62828; }
.dtr-time-chip { display:inline-block; padding:2px 7px; border-radius:2px; font-size:11px; font-weight:600; font-family:'Segoe UI',Arial,sans-serif; }
.dtr-time-chip.in  { background:#eeeaf5; color:#6642aa; border:1px solid #c0b5d5; }
.dtr-time-chip.out { background:#fce4ec; color:#c62828; border:1px solid #f9a8b5; }
.dtr-time-chip.na  { background:#f5f5f5; color:#888;    border:1px solid #ddd; }
.dtr-pos-chip { display:inline-block; padding:1px 7px; border-radius:2px; font-size:10px; background:#f3f2f1; color:#323130; border:1px solid #e1dfdd; }
.dtr-log-chip { display:inline-flex; align-items:center; gap:3px; margin-bottom:1px; padding:1px 5px; border-radius:2px; font-size:10px; font-weight:500; }
.dtr-logs-pill { display:inline-flex; flex-direction:column; align-items:center; gap:1px; font-size:11px; line-height:1.3; }
.dtr-logs-count { font-size:9px; color:#a19f9d; margin-top:1px; }
.dtr-logs-pill:hover .dtr-logs-count { color:#6642aa; }

/* ── Segment toggle ── */
.dtr-segment { display:inline-flex; background:#eeeaf5; border-radius:8px; padding:3px; gap:2px; }
.dtr-seg-btn { border:none; background:transparent; border-radius:6px; padding:5px 14px; font-size:12px; font-weight:600; color:#6642aa; cursor:pointer; display:flex; align-items:center; gap:5px; transition:all .15s; }
.dtr-seg-btn.active { background:#6642aa; color:#fff; box-shadow:0 1px 4px rgba(102,66,170,.25); }
.dtr-seg-btn:not(.active):hover { background:#d7cfe7; }

/* ── Employee cards view ── */
.ecard { background:#fff; border:1px solid #e1dfdd; border-radius:10px; margin-bottom:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); transition:box-shadow .15s; }
.ecard:hover { box-shadow:0 3px 10px rgba(102,66,170,.12); }
.ecard-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; cursor:pointer; user-select:none; gap:12px; }
.ecard-header:hover { background:#fcfcfe; }
.ecard-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
.ecard-right { display:flex; align-items:center; gap:12px; flex-shrink:0; }
.ecard-name { font-size:13px; font-weight:700; color:#323130; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ecard-meta { display:flex; align-items:center; gap:6px; margin-top:3px; }
.ecard-days { font-size:10px; color:#a19f9d; display:flex; align-items:center; gap:3px; }
.ecard-chevron { font-size:18px; color:#a19f9d; transition:transform .2s; flex-shrink:0; }
.ecard.open .ecard-chevron { transform:rotate(180deg); }
.ecard-body { display:none; border-top:1px solid #f3f2f1; padding:0 0 4px; }
.ecard.open .ecard-body { display:block; }
.ecard-date-group { margin:8px 12px 4px; }
.ecard-date-header { display:flex; align-items:center; justify-content:space-between; padding:5px 8px; background:#f3f2f1; border-radius:6px; margin-bottom:4px; }
.ecard-date-label { font-size:11px; font-weight:700; color:#605e5c; display:flex; align-items:center; gap:5px; }
.ecard-entry { display:flex; align-items:center; justify-content:space-between; padding:6px 8px; border-radius:6px; margin-bottom:2px; background:#fafafa; border:1px solid #f0f0f0; gap:8px; }
.ecard-times { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.ecard-entry-stats { display:flex; gap:8px; flex-shrink:0; }
.ecard-stat { display:flex; flex-direction:column; align-items:center; min-width:30px; }
.ecard-stat-lbl { font-size:9px; color:#a19f9d; text-transform:uppercase; letter-spacing:.3px; }
.ecard-stat-val { font-size:11px; font-weight:700; color:#6642aa; }
.ecard-stat.ot .ecard-stat-val  { color:#c98a00; }
.ecard-stat.ut .ecard-stat-val  { color:#1565c0; }
.ecard-stat.late .ecard-stat-val { color:#c62828; }

/* ── Employee card: approval summary chips (header) ── */
.ecard-sum-chip { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px; }
.ecard-sum-chip i { font-size:11px; }
.ecard-sum-chip.appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.ecard-sum-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ecard-sum-chip.disa { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }

/* ── Employee card: per-entry approval status + actions ── */
.ecard-entry-status { display:flex; align-items:center; gap:6px; flex-shrink:0; }
.ecard-entry-status .btn-group-sm .btn { padding:1px 7px; font-size:11px; line-height:1.4; }
.ecard-entry.is-approved    { background:#f2fbf6; border-color:#cdeeda; }
.ecard-entry.is-disapproved { background:#fdf4f4; border-color:#f3d3d3; }
.ecard-approve-all { flex-shrink:0; }
.ecard-entry .rec-check { transform:scale(1.15); cursor:pointer; flex-shrink:0; margin-top:0; }

/* ── Excel-like fullscreen (whole DTR Attendance card) ── */
#dtrDiv:fullscreen, #dtrDiv:-webkit-full-screen { background:#fff; }
#dtrDiv::backdrop, #dtrDiv::-webkit-full-screen-backdrop { background:#fff; }
.dtr-fs { border-radius:0 !important; box-shadow:none !important; background:#fff !important; display:flex !important; flex-direction:column; }
.dtr-fs .xl-panel-body { flex:1; overflow-y:auto; }
.dtr-fs-fallback { position:fixed !important; inset:0 !important; z-index:9990 !important; width:100vw !important; height:100vh !important; margin:0 !important; }
.dtr-fs-exit {
    display:none; position:fixed; top:10px; right:16px; z-index:9999;
    align-items:center; gap:5px; padding:6px 14px; font-size:12px; font-weight:600;
    color:#fff; background:#6642aa; border:1px solid #4e3483; border-radius:4px; cursor:pointer;
    box-shadow:0 2px 8px rgba(0,0,0,.25);
}
.dtr-fs-exit:hover { background:#4e3483; }
.dtr-fs .dtr-fs-exit { display:inline-flex; }
.dtr-log-chip.bio    { background:#eeeaf5; color:#6642aa; border:1px solid #c0b5d5; }
.dtr-log-chip.manual { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }

/* ── Date group banner — soft mint band (was a solid purple fill) ── */
#table-1 tbody tr.date-separator td {
    background:#e1dcec !important; color:#4f3288 !important;
    padding:7px 14px !important;
    border-top:1px solid #cfc8de !important;
    border-bottom:1px solid #cfc8de !important;
    font-family:'Segoe UI',Arial,sans-serif;
}
#table-1 tbody tr.date-separator td * { color:#4f3288 !important; }
#table-1 tbody tr.date-separator td small,
#table-1 tbody tr.date-separator td .text-muted { color:#6b5794 !important; }
/* OT/Late figures keep their status color even inside the banner */
#table-1 tbody tr.date-separator td .dtr-date-ot   { color:#8a6d1a !important; font-weight:700; }
#table-1 tbody tr.date-separator td .dtr-date-late { color:#b02a37 !important; font-weight:700; }
/* "Approve day" pill — soft green pill, legible on the mint banner */
.approveday-btn {
    background:#eafaf0 !important; border:1px solid #b7e4c7 !important; color:#0b5e31 !important;
    border-radius:20px !important;
}
.approveday-btn:hover { background:#d9f2e3 !important; border-color:#8fd3a8 !important; color:#0b5e31 !important; }
.approveday-btn i, .approveday-btn span { color:#0b5e31 !important; }

/* ── Employee card row ── */
#table-1 tbody tr.employee-header td {
    background:#f8f6fb !important;
    border-top:2px solid #cdeeda !important;
    border-bottom:0 !important;
    padding:8px 12px 0 !important;
}
.dtr-emp-card {
    background:#fff;
    border:1px solid #dbe6dd;
    border-radius:10px;
    padding:8px 14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 1px 4px rgba(0,0,0,.05);
    margin-bottom: 10px;
}

/* ── Entry rows under a card ── */
#table-1 tbody tr.attendance-entry td { background:#f8f6fb; }
#table-1 tbody tr.attendance-entry:last-of-type td { padding-bottom:10px !important; }

/* ── Duplicate entry ── */
#table-1 tbody tr.duplicate-entry td { background:#fdf0f1 !important; border-left:3px solid #e0a1a8 !important; }

/* ── Grand total row — soft Excel-green band, dark green bold figures ── */
#table-1 tbody tr.grand-total-row td {
    background:#d9eedd !important; color:#0b5e31 !important;
    font-weight:800 !important; font-size:12px !important; padding:6px 8px !important;
    border-top:2px solid #b8d8c2 !important; border-right:1px solid #c0e0c8 !important;
    position:sticky; bottom:0; z-index:13;
    box-shadow:0 -3px 8px rgba(0,0,0,0.08);
}
#table-1 tbody tr.grand-total-row td:nth-child(1),
#table-1 tbody tr.grand-total-row td:nth-child(2) { z-index:15 !important; background:#c6e4cd !important; }

/* ── Page title: icon badge + soft meta chips ── */
.dtr-title-icon {
    width:42px; height:42px; border-radius:12px; flex-shrink:0;
    background:linear-gradient(135deg,#6642aa,#7654b8); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:20px;
    box-shadow:0 3px 8px rgba(102,66,170,.30);
}
.dtr-meta-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:5px; }
.dtr-meta-chip {
    display:inline-flex; align-items:center; gap:5px;
    font-size:11px; font-weight:600; color:#4e3483;
    background:#f2f0f7; border:1px solid #ddd9e7; border-radius:20px; padding:3px 10px;
}
.dtr-meta-chip i { color:#6642aa; font-size:13px; }

/* ── Column header icons ── */
#table-1 thead tr:first-child th i { font-size:13px; margin-right:4px; vertical-align:-1.5px; color:#674f98 !important; }

/* ── Soft status badges (pastel pills instead of solid Bootstrap fills) ── */
#table-1 [data-rec-badge] .badge, .ecard-entry-status .badge, #cmpl-badge .badge,
#table-1 .badge.bg-success, .ecard [data-rec-badge] .badge { border-radius:20px; }
#table-1 .badge.bg-success, .ecard .badge.bg-success {
    background:#eafaf0 !important; color:#0f9d58 !important; border:1px solid #b7e4c7;
}
#table-1 .badge.bg-danger, .ecard .badge.bg-danger {
    background:#fdecea !important; color:#c62828 !important; border:1px solid #f5c6cb;
}
#table-1 .badge.bg-warning, .ecard .badge.bg-warning {
    background:#fff8e1 !important; color:#c98a00 !important; border:1px solid #ffe082;
}

/* ── DTR loading overlay ──────────────────────────────────────────────────────
   Covers the whole attendance panel while the page boots and while
   refreshDtrPanel() re-fetches the markup, so the table never flashes a
   half-swapped state. Lives outside #dtr-panel-body so the innerHTML swap
   can't blow it away mid-refresh. */
#dtrDiv { position:relative; }
.dtr-loader {
    position:absolute; inset:0; z-index:60;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px;
    background:rgba(255,255,255,0.86); backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px);
    border-radius:inherit;
    opacity:0; visibility:hidden; transition:opacity .18s ease, visibility .18s ease;
}
.dtr-loader.show { opacity:1; visibility:visible; }
.dtr-loader-ring {
    width:46px; height:46px; border-radius:50%;
    border:4px solid #e1dcec; border-top-color:#6642aa;
    animation:dtr-spin .8s linear infinite;
}
.dtr-loader-text {
    font-size:12px; font-weight:700; color:#4e3483; letter-spacing:.3px;
    display:flex; align-items:center; gap:2px;
}
.dtr-loader-text .dot { animation:dtr-blink 1.4s infinite both; }
.dtr-loader-text .dot:nth-child(2) { animation-delay:.2s; }
.dtr-loader-text .dot:nth-child(3) { animation-delay:.4s; }
.dtr-loader-bar {
    width:180px; height:4px; border-radius:4px; background:#eeeaf5; overflow:hidden;
}
.dtr-loader-bar::after {
    content:''; display:block; width:40%; height:100%; border-radius:4px;
    background:linear-gradient(90deg,#6642aa,#967ec5);
    animation:dtr-slide 1.1s ease-in-out infinite;
}
@keyframes dtr-spin  { to { transform:rotate(360deg); } }
@keyframes dtr-blink { 0%,80%,100% { opacity:.2; } 40% { opacity:1; } }
@keyframes dtr-slide { 0% { transform:translateX(-100%); } 100% { transform:translateX(350%); } }
/* Panel content dims + can't be clicked while a refresh is in flight */
#dtr-panel-body.is-refreshing { opacity:.45; pointer-events:none; transition:opacity .18s ease; }
@media (prefers-reduced-motion: reduce) {
    .dtr-loader-ring, .dtr-loader-bar::after, .dtr-loader-text .dot { animation:none; }
}

/* Ribbon reload button — spins its icon while refreshing */
.xl-btn.is-loading { pointer-events:none; opacity:.7; }
.xl-btn.is-loading i { animation:dtr-spin .8s linear infinite; display:inline-block; }

/* ── Employee list pager ── */
.emp-pager {
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
    padding:10px 4px 16px; border-top:1px solid #eef2f0; margin-top:6px;
}
.emp-pager-size, .emp-pager-info { font-size:11.5px; color:#827d91; }
.emp-pager-size select {
    border:1px solid #ddd9e7; background:#fff; color:#4e3483; border-radius:6px;
    padding:3px 22px 3px 8px; font-size:11.5px; font-weight:600; cursor:pointer; margin-left:2px;
}
.emp-pager-size select:focus { outline:none; border-color:#6642aa; box-shadow:0 0 0 2px rgba(102,66,170,.15); }
.emp-pager-nav { display:flex; align-items:center; gap:3px; flex-wrap:wrap; }
.emp-page-btn {
    min-width:30px; height:30px; padding:0 8px;
    display:inline-flex; align-items:center; justify-content:center;
    border:1px solid #ddd9e7; background:#fff; color:#4e3483;
    border-radius:7px; font-size:12px; font-weight:600; cursor:pointer;
    transition:background .12s, border-color .12s, color .12s;
}
.emp-page-btn:hover:not(:disabled):not(.active) { background:#f2f0f7; border-color:#c0b5d5; }
.emp-page-btn.active { background:#6642aa; border-color:#4e3483; color:#fff; cursor:default; }
.emp-page-btn:disabled { opacity:.4; cursor:not-allowed; }
.emp-page-gap { padding:0 3px; color:#aeabb9; font-size:12px; user-select:none; }
.emp-pager.is-loading { opacity:.55; pointer-events:none; }
@media (max-width:575.98px) {
    .emp-pager { justify-content:center; }
    .emp-pager-info { width:100%; text-align:center; order:3; }
}

/* ── View counter chip ── */
.dtr-count-chip {
    display:inline-flex; align-items:center; gap:5px;
    font-size:11px; font-weight:600; color:#4e3483;
    background:#f2f0f7; border:1px solid #ddd9e7; border-radius:20px; padding:3px 10px;
}
.dtr-count-chip i { color:#6642aa; font-size:13px; }

/* Ribbon "pending" badge — soft amber pill */
#batch-pending-badge {
    background:#fff8e1 !important; color:#c98a00 !important;
    border:1px solid #ffe082; border-radius:20px; font-weight:700;
}

/* ── bg-soft utilities ── */
.bg-soft-primary   { background:rgba(102,66,170,0.10) !important; }
.bg-soft-secondary { background:rgba(108,117,125,0.08) !important; }
.bg-soft-success   { background:rgba(16,124,65,0.10) !important; }
.bg-soft-danger    { background:rgba(240,101,72,0.10) !important; }
.bg-soft-warning   { background:rgba(247,184,75,0.10) !important; }
.bg-soft-info      { background:rgba(80,165,241,0.10) !important; }
.bg-soft-light     { background:#f8f9fa !important; }

/* ── Editable fields ── */
.editable-field { display:flex; align-items:center; justify-content:center; gap:2px; }
.editable-field .form-control {
    border:1px solid #d4d4d4; border-radius:2px;
    font-size:11px; padding:2px 5px; height:24px;
    background:#f5f3fa; transition:all 0.15s;
}
.editable-field .form-control:focus {
    border-color:#6642aa; box-shadow:0 0 0 2px rgba(102,66,170,0.15); background:#fff;
}
.update-dtr-field {
    display:inline-flex !important; align-items:center; justify-content:center;
    width:22px !important; height:22px !important; padding:0 !important; font-size:11px !important;
    background:#eeeaf5 !important; border:1px solid #c0b5d5 !important; border-radius:2px !important;
    color:#6642aa !important; cursor:pointer; flex-shrink:0;
    box-shadow:none !important; transition:background 0.1s, border-color 0.1s;
}
.update-dtr-field:hover { background:#6642aa !important; border-color:#4e3483 !important; color:#fff !important; }

/* ── Action buttons — soft pastel fills ── */
#table-1 td .btn-group .btn, .ecard .btn-group .btn { width:26px; height:26px; padding:0; font-size:13px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; box-shadow:none; margin-right:2px; }
#table-1 .btn-group .btn-outline-success, .ecard .btn-group .btn-outline-success {
    background:#eafaf0 !important; border:1px solid #b7e4c7 !important; color:#0f9d58 !important;
}
#table-1 .btn-group .btn-outline-success:hover, .ecard .btn-group .btn-outline-success:hover { background:#d9f2e3 !important; border-color:#8fd3a8 !important; }
#table-1 .btn-group .btn-outline-danger, .ecard .btn-group .btn-outline-danger {
    background:#fdecea !important; border:1px solid #f5c6cb !important; color:#c62828 !important;
}
#table-1 .btn-group .btn-outline-danger:hover, .ecard .btn-group .btn-outline-danger:hover { background:#fadbd8 !important; border-color:#efaab2 !important; }
#table-1 .btn-group .btn-outline-secondary, .ecard .btn-group .btn-outline-secondary {
    background:#f3f2f1 !important; border:1px solid #e1dfdd !important; color:#605e5c !important;
}
#table-1 .btn-group .btn-outline-secondary:hover, .ecard .btn-group .btn-outline-secondary:hover { background:#e8e6e4 !important; border-color:#c8c6c4 !important; }
#table-1 td .btn-group .btn-outline-warning,
#table-1 td .btn-group a.btn-outline-warning { background:#fff8e1 !important; border:1px solid #ffe082 !important; color:#c98a00 !important; }
#table-1 td .btn-group .btn-outline-warning:hover,
#table-1 td .btn-group a.btn-outline-warning:hover { background:#fdefc3 !important; border-color:#f5d264 !important; }
#table-1 .btn-group .btn:disabled, .ecard .btn-group .btn:disabled { opacity:.45; }

/* ── Logs ── */
.logs-container { max-height:90px; overflow-y:auto; }
.log-entry { line-height:1.3; }

/* ── Misc ── */
.stat-item, .total-item { min-width:55px; }
.stat-value, .total-value { font-size:1rem; }
.update-success { border-color:#6642aa !important; background:rgba(102,66,170,0.08) !important; animation:pulse-ok 2s; }
@keyframes pulse-ok {
    0%   { box-shadow:0 0 0 0 rgba(102,66,170,0.6); }
    70%  { box-shadow:0 0 0 8px rgba(102,66,170,0); }
    100% { box-shadow:0 0 0 0 rgba(102,66,170,0); }
}

/* ── Print ── */
@media print {
    body * { visibility:hidden; margin:0; padding:0; }
    #print-section, #print-section * { visibility:visible; }
    #print-section { display:block !important; position:absolute; left:0; top:0; width:100%; padding:20px; font-family:Arial,sans-serif; font-size:12px; background:#fff; }
    .main-content,.page-content,.container-fluid,.card,.btn,.search-box { display:none !important; }
    .print-header { text-align:center; margin-bottom:20px; padding-bottom:15px; border-bottom:2px solid #333; }
    .print-header h2 { font-size:18px; margin-bottom:10px; color:#333; }
    .print-info { margin-bottom:15px; }
    .print-info p { margin:2px 0; font-size:11px; }
    .print-summary { display:flex; justify-content:center; gap:20px; margin:15px 0; padding:10px; background:#f5f5f5; border-radius:4px; }
    .summary-item { text-align:center; }
    .summary-item .label { display:block; font-size:10px; color:#666; }
    .summary-item .value { display:block; font-size:12px; font-weight:bold; color:#333; }
    .print-table { width:100%; border-collapse:collapse; margin:15px 0; font-size:10px; }
    .print-table th { background:#f8f9fa; border:1px solid #ddd; padding:6px 4px; text-align:center; font-weight:bold; }
    .print-table td { border:1px solid #ddd; padding:5px 3px; }
    .print-table .text-center { text-align:center; }
    .print-table .text-end { text-align:right; }
    .date-separator { background:#e9ecef; }
    .employee-row { page-break-inside:avoid; }
    .grand-total { background:#d1ecf1; font-weight:bold; }
    .print-footer { margin-top:20px; padding-top:10px; border-top:1px solid #ddd; text-align:center; font-size:10px; color:#666; }
}

/* ── DTR Details: taller body-scroll + tighter columns (page-scoped) ── */
.table-responsive2 {
    height: auto !important;
    max-height: calc(100vh - 300px) !important;   /* only the body scrolls; header stays put */
    min-height: 320px !important;
    overflow-y: auto !important;
    overflow-x: auto !important;                    /* fallback on very narrow screens */
    border: 1px solid #c0e0c8 !important;
    border-radius: 8px !important;
    /* Scrollbar colours are global — see assets2/css/theme.css */
    scrollbar-width: thin;
    scrollbar-color: var(--sb-thumb) var(--sb-track);
}
.table-responsive2::-webkit-scrollbar { width: 9px; height: 9px; }
.table-responsive2::-webkit-scrollbar-track { background: var(--sb-track); border-radius: 6px; }
.table-responsive2::-webkit-scrollbar-thumb { background: var(--sb-thumb); border-radius: 6px; border: 2px solid var(--sb-track); }
.table-responsive2::-webkit-scrollbar-thumb:hover { background: var(--sb-thumb-hover); }

/* Compact the wide columns so the table fits without horizontal scroll on normal screens */
#table-1 thead th, #table-1 tbody td { padding: 5px 7px !important; }
#table-1 .editable-field input.form-control { width: 54px !important; padding: 2px 4px !important; font-size: 11px !important; }
#table-1 .editable-field .update-dtr-field { padding: 2px 5px !important; }
#table-1 .dtr-pos-chip { white-space: normal !important; max-width: 120px; display: inline-block; line-height: 1.15; }
#table-1 .dtr-time-chip { padding: 2px 7px !important; }

/* Nicer rows */
#table-1 tbody tr.attendance-entry:hover td { background: #f7f5fb !important; }
#table-1 tbody tr.attendance-entry td { transition: background .15s ease; vertical-align: middle !important; }
#table-1 .btn-group-sm .btn { padding: 2px 6px !important; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <!-- start page title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <div class="page-title-enhanced d-flex align-items-center gap-3">
                            <div class="dtr-title-icon"><i class="ri-calendar-check-line"></i></div>
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h4 class="mb-0 fw-bold">DTR Details</h4>
                                    <?php if ($dtr['status'] === 0): ?>
                                        <span class="payroll-status-badge bg-warning text-dark"><i class="ri-time-line me-1"></i>Open</span>
                                    <?php elseif ($dtr['status'] === 1): ?>
                                        <span class="payroll-status-badge bg-info text-white"><i class="ri-check-double-line me-1"></i>Pending Approval</span>
                                    <?php elseif ($dtr['status'] === 3): ?>
                                        <span class="payroll-status-badge bg-primary text-white"><i class="ri-user-received-2-line me-1"></i>Ready for Review</span>
                                    <?php else: ?>
                                        <span class="payroll-status-badge bg-success text-white"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>
                                    <?php endif; ?>
                                </div>
                                <div class="dtr-meta-chips">
                                    <span class="dtr-meta-chip"><i class="ri-calendar-2-line"></i><?= date('M d', strtotime($dtr['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($dtr['date_to'])) ?></span>
                                    <span class="dtr-meta-chip"><i class="ri-calendar-event-line"></i><?= $periodDays ?> day<?= $periodDays !== 1 ? 's' : '' ?></span>
                                    <span class="dtr-meta-chip"><i class="ri-building-line"></i><?= htmlspecialchars($dtr['site_name']) ?> (<?= htmlspecialchars($dtr['site_code']) ?>)</span>
                                    <span class="dtr-meta-chip"><i class="ri-user-2-line"></i><?= htmlspecialchars($dtr['employer_name']) ?></span>
                                    <span class="dtr-meta-chip"><i class="ri-shield-user-line"></i><?= htmlspecialchars($timekeeper_name) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">DTR Details</li>
                            </ol>
                        </div>
                    </div>
                </div>
                <!-- print section (hidden on screen, used by printDTRTable()) -->
                <!-- Add this CSS for print styling -->
                <style>
                    @media print {
                        body * {
                            visibility: hidden;
                            margin: 0;
                            padding: 0;
                        }

                        #print-section,
                        #print-section * {
                            visibility: visible;
                        }

                        #print-section {
                            display: block !important;
                            position: absolute;
                            left: 0;
                            top: 0;
                            width: 100%;
                            padding: 20px;
                            font-family: Arial, sans-serif;
                            font-size: 12px;
                            background: white;
                        }

                        /* Hide all other elements */
                        .main-content,
                        .page-content,
                        .container-fluid,
                        .card,
                        .btn,
                        .search-box {
                            display: none !important;
                        }

                        /* Print header styling */
                        .print-header {
                            text-align: center;
                            margin-bottom: 20px;
                            padding-bottom: 15px;
                            border-bottom: 2px solid #333;
                        }

                        .print-header h2 {
                            font-size: 18px;
                            margin-bottom: 10px;
                            color: #333;
                        }

                        .print-info {
                            margin-bottom: 15px;
                        }

                        .print-info p {
                            margin: 2px 0;
                            font-size: 11px;
                        }

                        .print-summary {
                            display: flex;
                            justify-content: center;
                            gap: 20px;
                            margin: 15px 0;
                            padding: 10px;
                            background-color: #f5f5f5;
                            border-radius: 4px;
                        }

                        .summary-item {
                            text-align: center;
                        }

                        .summary-item .label {
                            display: block;
                            font-size: 10px;
                            color: #666;
                        }

                        .summary-item .value {
                            display: block;
                            font-size: 12px;
                            font-weight: bold;
                            color: #333;
                        }

                        /* Print table styling */
                        .print-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 15px 0;
                            font-size: 10px;
                        }

                        .print-table th {
                            background-color: #f8f9fa;
                            border: 1px solid #ddd;
                            padding: 6px 4px;
                            text-align: center;
                            font-weight: bold;
                        }

                        .print-table td {
                            border: 1px solid #ddd;
                            padding: 5px 3px;
                            text-align: left;
                        }

                        .print-table .text-center {
                            text-align: center;
                        }

                        .print-table .text-end {
                            text-align: right;
                        }

                        /* Date separator styling */
                        .date-separator {
                            background-color: #e9ecef;
                        }

                        .date-header {
                            padding: 8px 5px;
                            font-size: 11px;
                        }

                        .employee-count {
                            color: #666;
                            font-weight: normal;
                        }

                        .date-totals {
                            float: right;
                            font-weight: normal;
                            color: #666;
                        }

                        /* Employee row styling */
                        .employee-row td {
                            padding: 4px 3px;
                        }

                        .employee-name {
                            font-weight: 500;
                        }

                        /* Grand total styling */
                        .grand-total {
                            background-color: #d1ecf1;
                            font-weight: bold;
                        }

                        .grand-total td {
                            padding: 8px 3px;
                        }

                        /* Print footer */
                        .print-footer {
                            margin-top: 20px;
                            padding-top: 10px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            font-size: 10px;
                            color: #666;
                        }

                        /* Page break control */
                        .date-separator {
                            page-break-before: auto;
                            page-break-after: avoid;
                        }

                        .employee-row {
                            page-break-inside: avoid;
                        }
                    }

                    /* Screen styles for print button */
                    .btn-outline-info {
                        border-color: #17a2b8;
                        color: #17a2b8;
                    }

                    .btn-outline-info:hover {
                        background-color: #17a2b8;
                        color: white;
                    }

                    /* Ensure print section is hidden on screen */
                    #print-section {
                        display: none;
                    }
                </style>

                <!-- Print target, filled on demand. The by-day print table used to be
                     rendered into every page load (~4.3k rows) for markup that's only ever
                     read when printing; dtr-employee-server.php?action=print builds it now. -->
                <div id="print-section" style="display: none;"></div>

                <!-- Attendance List panel -->
                <div class="xl-panel" id="dtrDiv">
                    <div class="xl-ribbon">
                        <span class="xl-ribbon-title">
                            <i class="ri-time-line"></i> DTR Attendance
                        </span>
                        <div class="xl-ribbon-actions">
                            <div class="xl-search-wrap">
                                <i class="ri-search-2-line"></i>
                                <input id="myInput" type="text" placeholder="Search employee...">
                            </div>
                            <div class="xl-ribbon-sep"></div>
                            <button id="btn-dtr-reload" onclick="reloadDtr()" class="xl-btn" title="Reload DTR records"><i class="ri-refresh-line"></i> Reload</button>
                            <div class="xl-ribbon-sep"></div>
                            <?php $docsQs = http_build_query([
                                'id'              => $_GET['id'],
                                'device_id'       => $_GET['device_id'],
                                'site_id'         => $_GET['site_id'],
                                'timekeeper_name' => $_GET['timekeeper_name'] ?? '',
                            ]); ?>
                            <a href="dtr-documents.php?<?= htmlspecialchars($docsQs) ?>" class="xl-btn" title="View as paper DTR documents"><i class="ri-file-text-line"></i> Documents</a>
                            <button onclick="printDTRTable()" class="xl-btn"><i class="ri-printer-line"></i> Print</button>
                            <button onclick="toggleDtrFullscreen()" class="xl-btn" id="btn-dtr-fullscreen" title="Fullscreen table (Esc to exit)"><i class="ri-fullscreen-line"></i> Fullscreen</button>
                            <?php if ($login_role !== 6): ?>
                                <div class="xl-ribbon-sep"></div>
                                <label class="xl-btn" style="cursor:pointer;margin-top: 10px;" title="Select all records">
                                    <input type="checkbox" id="chk-all-records" class="me-1"> All
                                </label>
                                <button id="btn-approve-selected" onclick="decideSelected(1)" class="xl-btn xl-btn-save" disabled>
                                    <i class="ri-checkbox-circle-line"></i> Approve (<span id="rec-sel-count">0</span>)
                                </button>
                                <button id="btn-disapprove-selected" onclick="decideSelected(2)" class="xl-btn" disabled style="color:#c62828;">
                                    <i class="ri-close-circle-line"></i> Disapprove
                                </button>
                            <?php endif; ?>
                            <?php if ($dtr['status'] === 1 && $login_role !== 6): ?>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="addSchedule(<?= $id ?>)" class="xl-btn"><i class="ri-add-line"></i> Add</button>
                            <?php endif; ?>
                            <?php if ($dtr['status'] === 1 && $login_role !== 6): ?>
                                <div class="xl-ribbon-sep"></div>
                                <?php $blockApprove = ($is_duplicate || $grandTotals['pending'] > 0); ?>
                                <button id="btn-send-review" data-dup="<?= $is_duplicate ? '1' : '0' ?>" <?= $blockApprove ? 'disabled' : '' ?> onclick="sendDtrForReview(<?= $id ?>)" class="xl-btn xl-btn-save"
                                    title="Decide all records first, then send to employees for review">
                                    <i class="ri-user-received-2-line"></i> Send for Review
                                    <span id="batch-pending-badge" class="badge bg-warning text-dark ms-1" style="<?= $grandTotals['pending'] > 0 ? '' : 'display:none;' ?>">
                                        <span id="batch-pending-count"><?= (int)$grandTotals['pending'] ?></span> pending
                                    </span>
                                </button>
                            <?php elseif ($dtr['status'] === 3 && $login_role !== 6): ?>
                                <div class="xl-ribbon-sep"></div>
                                <button onclick="approveDtr(<?= $id ?>)" class="xl-btn xl-btn-save" title="Final approve for payroll">
                                    <i class="ri-checkbox-circle-line"></i> Final Approve
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Loading overlay (shown on first paint + on every panel refresh) -->
                    <div class="dtr-loader show" id="dtr-loader">
                        <div class="dtr-loader-ring"></div>
                        <div class="dtr-loader-text" id="dtr-loader-text">Loading DTR<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></div>
                        <div class="dtr-loader-bar"></div>
                    </div>
                    <div class="xl-panel-body" id="dtr-panel-body">
                        <!-- Summary stat cards -->
                        <div class="dtr-stats-row">
                            <div class="dtr-stat-box sb-wh">
                                <div class="stat-icon" style="background:#eeeaf5;color:#6642aa;"><i class="ri-time-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#6642aa;"><span id="stat-work-hours"><?= number_format($grandTotals['work_hours'], 2) ?></span></div>
                                    <div class="stat-lbl">Work Hours</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-ot">
                                <div class="stat-icon" style="background:#fff8e1;color:#f7b84b;"><i class="ri-sun-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c98a00;"><span id="stat-overtime"><?= number_format($grandTotals['overtime'], 2) ?></span></div>
                                    <div class="stat-lbl">Overtime</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-ut">
                                <div class="stat-icon" style="background:#e3f2fd;color:#50a5f1;"><i class="ri-arrow-down-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#1565c0;"><span id="stat-undertime"><?= number_format($grandTotals['undertime'], 2) ?></span></div>
                                    <div class="stat-lbl">Undertime</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-late">
                                <div class="stat-icon" style="background:#fce4ec;color:#f06548;"><i class="ri-alarm-warning-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c62828;"><span id="stat-late"><?= number_format($grandTotals['late'], 2) ?></span></div>
                                    <div class="stat-lbl">Late (hrs)</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-appr">
                                <div class="stat-icon" style="background:#eafaf0;color:#0f9d58;"><i class="ri-checkbox-circle-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#0f9d58;"><span id="stat-approved"><?= (int)$grandTotals['approved'] ?></span> / <?= (int)$grandTotals['total'] ?></div>
                                    <div class="stat-lbl">Approved</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-disa">
                                <div class="stat-icon" style="background:#fdecea;color:#f06548;"><i class="ri-close-circle-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c62828;"><span id="stat-disapproved"><?= (int)$grandTotals['disapproved'] ?></span></div>
                                    <div class="stat-lbl">Disapproved</div>
                                </div>
                            </div>
                            <div class="dtr-stat-box sb-pend">
                                <div class="stat-icon" style="background:#fff8e1;color:#f7b84b;"><i class="ri-hourglass-line"></i></div>
                                <div>
                                    <div class="stat-val" style="color:#c98a00;"><span id="stat-pending"><?= (int)$grandTotals['pending'] ?></span></div>
                                    <div class="stat-lbl">Pending</div>
                                </div>
                            </div>
                        </div>

                        <?php if (in_array((int)$dtr['status'], [2, 3], true)): ?>
                        <!-- ── Employee Review Progress ── -->
                        <div class="dtr-review-panel">
                            <div class="drp-head">
                                <span class="drp-title"><i class="ri-user-received-2-line"></i> Employee Review
                                    <?php if ((int)$dtr['status'] === 3): ?>
                                        <span class="badge bg-info text-dark ms-1">In progress</span>
                                    <?php else: ?>
                                        <span class="badge bg-success ms-1">Approved</span>
                                    <?php endif; ?>
                                </span>
                                <div class="drp-counts">
                                    <span class="drp-chip appr"><i class="ri-checkbox-circle-line"></i> <?= $reviewConfirmed ?> Confirmed</span>
                                    <span class="drp-chip disp"><i class="ri-error-warning-line"></i> <?= $reviewDisputed ?> Disputed</span>
                                    <span class="drp-chip pend"><i class="ri-time-line"></i> <?= $reviewPending ?> Awaiting</span>
                                    <?php if ($login_role !== 6): ?>
                                        <?php if ((int)$dtr['status'] === 3 && $reviewPending > 0): ?>
                                        <button type="button" class="drp-act-btn remind" onclick="remindDtrReview(<?= $id ?>)" title="Remind employees who haven't reviewed">
                                            <i class="ri-notification-badge-line"></i> Remind (<?= $reviewPending ?>)
                                        </button>
                                        <?php endif; ?>
                                        <a class="drp-act-btn export" href="ajax.php?action=export_dtr_reviews&id=<?= $id ?>" title="Download review log (CSV)">
                                            <i class="ri-download-2-line"></i> Export
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($ddUnreadMsgs > 0): ?>
                                    <span class="drp-chip unread" id="drp-unread-chip" title="Employee messages nobody has opened yet">
                                        <i class="ri-mail-unread-line"></i> <span id="drp-unread-n"><?= $ddUnreadMsgs ?></span> unread
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                            // One line per employee — icon, name, and a chat button when
                            // they left a message; the message opens in #modal-emp-review.
                            $drpRow = function ($rv, $kind) {
                                $hasMsg = trim((string)$rv['comment']) !== '';
                                $unread = $hasMsg && empty($rv['seen_at']);
                                $eid    = (int)$rv['employee_id'];
                                ?>
                                <div class="drp-row <?= $kind ?><?= $hasMsg ? ' has-msg' : '' ?><?= $unread ? ' unread' : '' ?>"
                                     data-emp="<?= $eid ?>"
                                     <?= $hasMsg ? 'role="button" tabindex="0" onclick="ddOpenReviewConvo(' . $eid . ')" title="View this employee\'s message"' : '' ?>>
                                    <i class="drp-row-ic <?= $kind === 'disp' ? 'ri-error-warning-fill' : 'ri-checkbox-circle-fill' ?>"></i>
                                    <span class="drp-row-name"><?= htmlspecialchars($rv['name']) ?></span>
                                    <?php if ($unread): ?><span class="drp-row-new" title="Not yet read">NEW</span><?php endif; ?>
                                    <?php if (!empty($rv['resolved_at'])): ?>
                                        <span class="drp-row-done" title="Already replied"><i class="ri-check-double-line"></i></span>
                                    <?php endif; ?>
                                    <?php if ($hasMsg): ?>
                                        <span class="drp-row-msg" title="Open the message"><i class="ri-chat-3-fill"></i></span>
                                    <?php endif; ?>
                                </div>
                                <?php
                            };
                            ?>
                            <?php if ($reviewDisputed > 0): ?>
                            <div class="drp-disputes<?= $reviewDisputed > 6 ? ' is-scroll' : '' ?>">
                                <?php foreach ($reviewRows as $rv): if ((int)$rv['status'] !== 2) continue; ?>
                                    <?php $drpRow($rv, 'disp'); ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($reviewConfirmed > 0):
                                $cn = []; $cnMsgs = 0;
                                foreach ($reviewRows as $rv) {
                                    if ((int)$rv['status'] !== 1) continue;
                                    $cn[] = $rv;
                                    if (trim((string)$rv['comment']) !== '') $cnMsgs++;
                                }
                            ?>
                            <details class="drp-names"<?= count($cn) <= 12 || $cnMsgs ? ' open' : '' ?>>
                                <summary>
                                    <span class="drp-confirmed-lbl"><i class="ri-checkbox-circle-line"></i> Confirmed by</span>
                                    <span class="drp-count-pill"><?= count($cn) ?></span>
                                    <?php if ($cnMsgs): ?>
                                    <span class="drp-note-pill" title="Confirmed with a message"><i class="ri-chat-3-line"></i> <?= $cnMsgs ?> with message</span>
                                    <?php endif; ?>
                                    <span class="drp-names-hint"><span class="lbl-show">show names</span><span class="lbl-hide">hide</span></span>
                                </summary>
                                <div class="drp-confirms<?= count($cn) > 6 ? ' is-scroll' : '' ?>">
                                    <?php foreach ($cn as $rv): $drpRow($rv, 'ok'); endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>

                        <!-- DTR sign-off conversation popup -->
                        <div class="modal fade" id="modal-emp-review" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header py-2" style="background:#0f9d58;">
                                        <h6 class="modal-title text-white"><i class="ri-user-received-2-line me-2"></i>DTR Review Message</h6>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body" style="background:#f2f6f4;" id="mer-body"></div>
                                    <div class="modal-footer py-2">
                                        <button type="button" class="btn btn-sm btn-success" id="mer-reply" style="display:none;">
                                            <i class="ri-chat-check-line me-1"></i>Resolve &amp; Reply
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                        // employee_id → their DTR sign-off (decision, message, reply, unread)
                        window.DD_REVIEWS = <?= json_encode($ddReviewConvo, JSON_UNESCAPED_UNICODE) ?>;
                        window.DD_CAN_EDIT = <?= $login_role !== 6 ? 'true' : 'false' ?>;
                        </script>
                        <?php endif; ?>

                        <!-- Employee Notes Modal -->
                        <div class="modal fade" id="employeeNotesModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header" style="background:#f5f3fa;border-bottom:1px solid #ddd9e7;">
                                        <h5 class="modal-title" style="color:#4e3483;font-weight:700;"><i class="ri-sticky-note-line me-2" style="color:#6642aa;"></i>Employee Notes</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <h6 id="modalEmployeeName" class="mb-1" style="color:#6642aa;font-weight:600;"></h6>
                                        <p id="modalEmployeePosition" class="text-muted small mb-0"></p>
                                        <p id="modalEmployeeDate" class="text-muted small mb-2"></p>
                                        <label class="form-label fw-semibold small">Notes:</label>
                                        <div id="modalNotesContent" class="p-3 bg-light rounded border small"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- ── Employee list header ── -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                            <span class="dtr-count-chip"><i class="ri-group-line"></i><span id="dtr-view-count"><?= $empCount ?> employee<?= $empCount !== 1 ? 's' : '' ?></span></span>
                            <span class="dtr-count-chip"><i class="ri-calendar-2-line"></i><?= $daysLogged ?> of <?= $periodDays ?> day<?= $periodDays !== 1 ? 's' : '' ?> with records</span>
                        </div>

                        <!-- ── By Employee list ──────────────────────────────────────────────
                             Server-paginated: the first page is rendered inline below, every
                             page after that (and every search) comes from
                             dtr-employee-server.php. Nothing else of the batch is in the DOM. -->
                        <div id="view-by-employee" style="padding:4px 2px 8px;">
<?php dtr_trim_start(); ?>
                        <?php // Emit in $pageIds order (lastname) — $byEmployee is keyed in
                              // record order, which is not alphabetical. Must match the order
                              // dtr-employee-server.php uses or page 2 won't follow on from page 1.
                        foreach ($pageIds as $empId):
                            if (!isset($byEmployee[$empId])) continue;
                            render_dtr_employee_card($empId, $byEmployee[$empId], $employeeTotals[$empId], $login_role);
                        endforeach; ?>
<?php dtr_trim_end(); ?>
                        </div>

                        <div id="emp-empty" style="display:none;text-align:center;padding:26px 10px;color:#a19f9d;font-size:13px;">
                            <i class="ri-user-search-line" style="font-size:26px;display:block;margin-bottom:6px;color:#c8c6c4;"></i>
                            No employees match that search.
                        </div>

                        <!-- ── Pager ──────────────────────────────────────────────────────
                             Page numbers rather than "Load more": with 361 employees,
                             reaching the end of the list should not take 35 clicks. Every
                             control here just re-queries dtr-employee-server.php. -->
                        <div id="emp-pager" class="emp-pager">
                            <div class="emp-pager-size">
                                Show
                                <select id="emp-page-size" onchange="setEmpPageSize(this.value)">
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                            <nav class="emp-pager-nav" id="emp-pager-nav"></nav>
                            <div class="emp-pager-info" id="emp-pager-info">
                                1&ndash;<?= count($byEmployee) ?> of <?= $empCount ?>
                            </div>
                        </div>

                    </div><!-- end xl-panel-body -->
                </div><!-- end xl-panel -->

            </div>
            <!-- end page title -->
        </div>
        <!-- container-fluid -->
    </div>
    <!-- End Page-content -->
</div>
<?php
function human_time_diff(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', $ts);
}
?>
<?php include 'component/add_attendance.php'; ?>
<script>
    // ── DTR details — By Employee, server-paginated ─────────────────────────
    // Everything below drives one list of employee cards. Page 1 is rendered
    // inline by PHP; further pages, searches and the print table come from
    // dtr-employee-server.php so the browser never holds the whole batch.

    var DTR_ID          = <?= (int)$id ?>;
    var DTR_API         = 'dtr-employee-server.php';
    var EMP_PAGE_SIZE   = <?= DTR_EMP_PAGE_SIZE ?>;
    var DTR_EMP_COUNT   = <?= (int)$empCount ?>;   // employees in the whole batch

    var _empPage     = 1;                  // 1-based, page 1 is rendered inline by PHP
    var _empPageSize = EMP_PAGE_SIZE;
    var _empTotal    = DTR_EMP_COUNT;      // rows matching the current search
    var _empQuery    = '';
    var _empLoading  = false;

    // ── Loading overlay ──
    function showDtrLoading(msg) {
        var el = document.getElementById('dtr-loader');
        if (!el) return;
        if (msg) {
            document.getElementById('dtr-loader-text').innerHTML =
                msg + '<span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>';
        }
        el.classList.add('show');
        document.getElementById('dtr-panel-body')?.classList.add('is-refreshing');
    }
    function hideDtrLoading() {
        document.getElementById('dtr-loader')?.classList.remove('show');
        document.getElementById('dtr-panel-body')?.classList.remove('is-refreshing');
    }
    window.showDtrLoading = showDtrLoading;
    window.hideDtrLoading = hideDtrLoading;
    document.addEventListener('DOMContentLoaded', hideDtrLoading);
    window.addEventListener('load', hideDtrLoading);

    // ── Fetching a page of employees ────────────────────────────────────────
    // One page replaces the list; the pager is the only way the list changes.
    async function _loadEmpPage(page) {
        if (_empLoading) return;
        _empLoading = true;
        document.getElementById('emp-pager')?.classList.add('is-loading');

        var offset = (page - 1) * _empPageSize;
        var url = DTR_API + '?action=list&id=' + DTR_ID +
                  '&offset=' + offset + '&limit=' + _empPageSize +
                  '&q=' + encodeURIComponent(_empQuery);
        try {
            var res  = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            var data = await res.json();
            if (!data || !data.result) throw new Error(data && data.message || 'Request failed');

            document.getElementById('view-by-employee').innerHTML = data.html || '';
            _empPage  = page;
            _empTotal = data.total;
            _renderEmpPager();
        } catch (e) {
            console.error('Employee list failed', e);
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Could not load employees',
                            text: 'Check your connection and try again.' });
            }
        } finally {
            _empLoading = false;
            document.getElementById('emp-pager')?.classList.remove('is-loading');
        }
    }

    function _empLastPage() {
        return Math.max(1, Math.ceil(_empTotal / _empPageSize));
    }

    async function gotoEmpPage(page) {
        page = parseInt(page, 10);
        if (!page || page === _empPage || page < 1 || page > _empLastPage()) return;
        await _loadEmpPage(page);
        // Land at the top of the list rather than wherever the last page ended
        var list = document.getElementById('view-by-employee');
        if (list) {
            var y = list.getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
        }
    }
    window.gotoEmpPage = gotoEmpPage;

    function setEmpPageSize(size) {
        size = parseInt(size, 10);
        if (!size || size === _empPageSize) return;
        _empPageSize = size;
        _loadEmpPage(1);
    }
    window.setEmpPageSize = setEmpPageSize;

    // 1 … 4 5 [6] 7 8 … 37 — first and last always reachable, a window around
    // the current page, ellipsis for the rest.
    function _empPageList(cur, last) {
        if (last <= 7) {
            var all = [];
            for (var i = 1; i <= last; i++) all.push(i);
            return all;
        }
        var out = [1];
        var start = Math.max(2, cur - 1);
        var end   = Math.min(last - 1, cur + 1);
        if (start > 2) out.push('…');
        for (var j = start; j <= end; j++) out.push(j);
        if (end < last - 1) out.push('…');
        out.push(last);
        return out;
    }

    function _renderEmpPager() {
        var shown = document.querySelectorAll('#view-by-employee .ecard').length;
        var last  = _empLastPage();
        var el;

        if ((el = document.getElementById('emp-empty'))) {
            el.style.display = (shown === 0) ? '' : 'none';
        }
        if ((el = document.getElementById('dtr-view-count'))) {
            el.textContent = _empQuery
                ? _empTotal + ' match' + (_empTotal !== 1 ? 'es' : '')
                : DTR_EMP_COUNT + ' employee' + (DTR_EMP_COUNT !== 1 ? 's' : '');
        }

        var pager = document.getElementById('emp-pager');
        if (pager) pager.style.display = (_empTotal === 0) ? 'none' : '';

        if ((el = document.getElementById('emp-pager-info'))) {
            var from = _empTotal === 0 ? 0 : (_empPage - 1) * _empPageSize + 1;
            var to   = from + shown - 1;
            el.innerHTML = from + '&ndash;' + to + ' of ' + _empTotal;
        }

        var nav = document.getElementById('emp-pager-nav');
        if (!nav) return;
        var html = '<button type="button" class="emp-page-btn" title="Previous page"' +
                   (_empPage <= 1 ? ' disabled' : '') +
                   ' onclick="gotoEmpPage(' + (_empPage - 1) + ')"><i class="ri-arrow-left-s-line"></i></button>';
        _empPageList(_empPage, last).forEach(function (p) {
            if (p === '…') { html += '<span class="emp-page-gap">…</span>'; return; }
            html += '<button type="button" class="emp-page-btn' + (p === _empPage ? ' active' : '') + '"' +
                    (p === _empPage ? ' disabled' : '') +
                    ' onclick="gotoEmpPage(' + p + ')">' + p + '</button>';
        });
        html += '<button type="button" class="emp-page-btn" title="Next page"' +
                (_empPage >= last ? ' disabled' : '') +
                ' onclick="gotoEmpPage(' + (_empPage + 1) + ')"><i class="ri-arrow-right-s-line"></i></button>';
        nav.innerHTML = html;
    }

    document.addEventListener('DOMContentLoaded', _renderEmpPager);

    // ── Search (server-side) ────────────────────────────────────────────────
    // Debounced so typing doesn't fire a request per keystroke. Searching resets
    // to page 1 of the matches; "Load more" then pages through them.
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('myInput');
        if (!input) return;
        var t = null;
        input.addEventListener('keyup', function () {
            var v = this.value.trim();
            clearTimeout(t);
            t = setTimeout(function () {
                if (v === _empQuery) return;
                _empQuery = v;
                _loadEmpPage(1);   // a new search always starts at page 1
            }, 300);
        });
    });

    // ── Card expand / collapse ──────────────────────────────────────────────
    function toggleEmpCard(id) {
        var body = document.getElementById(id);
        if (!body) return;
        body.closest('.ecard').classList.toggle('open');
    }
    window.toggleEmpCard = toggleEmpCard;

    // ── Per-employee header chips ───────────────────────────────────────────
    // A card carries all of that employee's entries, so its own DOM is the
    // source of truth for its chips.
    function _dtrRefreshCardSummary(card) {
        var a = 0, p = 0, d = 0;
        card.querySelectorAll('.ecard-entry[data-rec-id]').forEach(function (en) {
            var s = parseInt(en.dataset.recStatus || '0', 10);
            if (s === 1) a++; else if (s === 2) d++; else p++;
        });
        var set = function (cls, v) { var el = card.querySelector(cls); if (el) el.textContent = v; };
        set('.emp-sum-appr', a);
        set('.emp-sum-pend', p);
        set('.emp-sum-disa', d);
        var btn = card.querySelector('.ecard-approve-all');
        if (btn) {
            btn.style.display = (p > 0) ? '' : 'none';
            var n = btn.querySelector('.emp-appr-count');
            if (n) n.textContent = p;
        }
    }
    function _dtrRefreshAllCardSummaries() {
        document.querySelectorAll('#view-by-employee .ecard[data-emp-id]').forEach(_dtrRefreshCardSummary);
    }
    window._dtrRefreshCardSummary     = _dtrRefreshCardSummary;
    window._dtrRefreshAllCardSummaries = _dtrRefreshAllCardSummaries;

    // ── Batch summary cards ─────────────────────────────────────────────────
    // Always from the server so the figures cover the whole batch, not the page
    // currently loaded.
    async function refreshDtrSummary() {
        try {
            var res  = await fetch(DTR_API + '?action=summary&id=' + DTR_ID,
                                   { credentials: 'same-origin', cache: 'no-store' });
            var data = await res.json();
            if (!data || !data.result) return;
            var s = data.summary, el;
            var num = function (v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
            if ((el = document.getElementById('stat-work-hours'))) el.textContent = num(s.work_hours);
            if ((el = document.getElementById('stat-overtime')))   el.textContent = num(s.overtime);
            if ((el = document.getElementById('stat-undertime')))  el.textContent = num(s.undertime);
            if ((el = document.getElementById('stat-late')))       el.textContent = num(s.late);
            if ((el = document.getElementById('stat-approved')))    el.textContent = s.approved;
            if ((el = document.getElementById('stat-disapproved'))) el.textContent = s.disapproved;
            if ((el = document.getElementById('stat-pending')))     el.textContent = s.pending;
            if ((el = document.getElementById('batch-pending-count'))) el.textContent = s.pending;
            if ((el = document.getElementById('batch-pending-badge'))) el.style.display = s.pending > 0 ? '' : 'none';
            var send = document.getElementById('btn-send-review');
            if (send) send.disabled = (s.pending > 0);
        } catch (e) {
            console.error('Summary refresh failed', e);
        }
    }
    window.refreshDtrSummary = refreshDtrSummary;

    // ── Full panel refresh (after add / delete / field edit) ────────────────
    // Stays on the current page so an edit doesn't throw the user back to the top
    // of a 37-page list.
    async function refreshDtrPanel(msg) {
        showDtrLoading(msg || 'Refreshing DTR');
        try {
            await _loadEmpPage(_empPage);
            await refreshDtrSummary();
        } finally {
            hideDtrLoading();
        }
    }
    window.refreshDtrPanel = refreshDtrPanel;

    async function reloadDtr() {
        var btn = document.getElementById('btn-dtr-reload');
        btn?.classList.add('is-loading');
        try { await refreshDtrPanel('Reloading DTR'); }
        finally { btn?.classList.remove('is-loading'); }
    }
    window.reloadDtr = reloadDtr;

    // ── Popovers (lazy) ─────────────────────────────────────────────────────
    // Triggers are marked data-dtr-pop rather than data-bs-toggle="popover"
    // because assets/js/app.js instantiates every [data-bs-toggle="popover"] on
    // page load; with hundreds of log pills that was pure waste. Built on first
    // click instead, and only one is ever open.
    var _dtrOpenPop = null;
    function _dtrHidePop() {
        if (!_dtrOpenPop) return;
        try { _dtrOpenPop.hide(); } catch (e) {}
        _dtrOpenPop = null;
    }
    document.addEventListener('click', function (e) {
        var trig = e.target.closest ? e.target.closest('[data-dtr-pop]') : null;
        if (!trig) { _dtrHidePop(); return; }
        var inst    = bootstrap.Popover.getInstance(trig);
        var wasOpen = inst && inst === _dtrOpenPop;
        _dtrHidePop();
        if (wasOpen) return;
        if (!inst) inst = new bootstrap.Popover(trig, { sanitize: false, trigger: 'manual' });
        inst.show();
        _dtrOpenPop = inst;
    });
    function initDtrPopovers() { _dtrHidePop(); }
    window.initDtrPopovers = initDtrPopovers;

    // ── Print ───────────────────────────────────────────────────────────────
    // The by-day print table is built by the server on demand instead of being
    // rendered into every page load.
    var _printCache = null;
    async function _getPrintHtml() {
        if (_printCache !== null) return _printCache;
        var res  = await fetch(DTR_API + '?action=print&id=' + DTR_ID,
                               { credentials: 'same-origin', cache: 'no-store' });
        var data = await res.json();
        _printCache = (data && data.result) ? data.html : '';
        return _printCache;
    }
    async function materializePrintSection() {
        var host = document.getElementById('print-section');
        if (!host || host.dataset.filled) return;
        host.innerHTML = await _getPrintHtml();
        host.dataset.filled = '1';
    }
    window.materializePrintSection = materializePrintSection;

    // ── Excel-like fullscreen ───────────────────────────────────────────────
    function _dtrFsTarget() { return document.getElementById('dtrDiv'); }
    function toggleDtrFullscreen() {
        var el = _dtrFsTarget();
        if (!el) return;
        var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (fsEl || el.classList.contains('dtr-fs-fallback')) {
            if (fsEl) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); }
            else { _dtrFsOff(el); }
            return;
        }
        var req = el.requestFullscreen || el.webkitRequestFullscreen;
        if (req) req.call(el).catch(function () { _dtrFsFallbackOn(el); });
        else _dtrFsFallbackOn(el);
    }
    function _dtrFsFallbackOn(el) {
        el.classList.add('dtr-fs', 'dtr-fs-fallback');
        document.body.style.overflow = 'hidden';
    }
    function _dtrFsOff(el) {
        el.classList.remove('dtr-fs', 'dtr-fs-fallback');
        document.body.style.overflow = '';
    }
    ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            var el = _dtrFsTarget();
            if (!el) return;
            var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
            if (fsEl === el) el.classList.add('dtr-fs');
            else if (!el.classList.contains('dtr-fs-fallback')) _dtrFsOff(el);
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var el = _dtrFsTarget();
        if (el && el.classList.contains('dtr-fs-fallback')) _dtrFsOff(el);
    });
    window.toggleDtrFullscreen = toggleDtrFullscreen;
</script>
<script>
    async function printDTRTable() {
        // The print table is built by the server on demand — fetch it first, and
        // open the print window only once we actually have content.
        showDtrLoading('Preparing print view');
        let printContent = '';
        try {
            await materializePrintSection();
            printContent = document.getElementById('print-section').innerHTML;
        } catch (e) {
            console.error('Print build failed', e);
        } finally {
            hideDtrLoading();
        }
        if (!printContent.trim()) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Print failed', text: 'Could not build the print view. Please try again.' });
            }
            return;
        }
        const printWindow = window.open('', '_blank');

        // Write the print content to the new window
        printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>DTR Details - <?= $dtr['site_name'] ?></title>
            <?php include __DIR__ . "/includes/favicon.php"; ?>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    font-size: 12px;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                }
                
                .print-header {
                    text-align: center;
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 2px solid #333;
                }
                
                .print-header h2 {
                    font-size: 18px;
                    margin-bottom: 10px;
                    color: #333;
                }
                
                .print-info {
                    margin-bottom: 15px;
                }
                
                .print-info p {
                    margin: 2px 0;
                    font-size: 11px;
                }
                
                .print-summary {
                    display: flex;
                    justify-content: center;
                    gap: 20px;
                    margin: 15px 0;
                    padding: 10px;
                    background-color: #f5f5f5;
                    border-radius: 4px;
                }
                
                .summary-item {
                    text-align: center;
                }
                
                .summary-item .label {
                    display: block;
                    font-size: 10px;
                    color: #666;
                }
                
                .summary-item .value {
                    display: block;
                    font-size: 12px;
                    font-weight: bold;
                    color: #333;
                }
                
                .print-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    font-size: 10px;
                }
                
                .print-table th {
                    background-color: #f8f9fa;
                    border: 1px solid #ddd;
                    padding: 6px 4px;
                    text-align: center;
                    font-weight: bold;
                }
                
                .print-table td {
                    border: 1px solid #ddd;
                    padding: 5px 3px;
                    text-align: left;
                }
                
                .print-table .text-center {
                    text-align: center;
                }
                
                .print-table .text-end {
                    text-align: right;
                }
                
                .date-separator {
                    background-color: #e9ecef;
                }
                
                .date-header {
                    padding: 8px 5px;
                    font-size: 11px;
                }
                
                .employee-count {
                    color: #666;
                    font-weight: normal;
                }
                
                .date-totals {
                    float: right;
                    font-weight: normal;
                    color: #666;
                }
                
                .employee-row td {
                    padding: 4px 3px;
                }
                
                .employee-name {
                    font-weight: 500;
                }
                
                .grand-total {
                    background-color: #d1ecf1;
                    font-weight: bold;
                }
                
                .grand-total td {
                    padding: 8px 3px;
                }
                
                .print-footer {
                    margin-top: 20px;
                    padding-top: 10px;
                    border-top: 1px solid #ddd;
                    text-align: center;
                    font-size: 10px;
                    color: #666;
                }
                
                @media print {
                    body {
                        margin: 0;
                        padding: 15px;
                    }
                    
                    .print-table {
                        font-size: 9px;
                    }
                }
            </style>
        
    <!-- App-wide custom <select> control (also loaded globally from includes/header.php
         for pages routed through index.php; this page renders standalone). -->
    <link rel="stylesheet" href="assets2/css/custom-select.css">
    <script defer src="assets2/js/custom-select.js"></script>

    <!-- App-wide tooltip — one hover style for the whole app; also upgrades any
         plain title= on this page (assets2/js/app-tooltip.js). -->
    <link rel="stylesheet" href="assets2/css/app-tooltip.css">
    <script defer src="assets2/js/app-tooltip.js"></script>
</head>
        <body>
            ${printContent}
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);

        printWindow.document.close();
    }

    // Optional: Add keyboard shortcut for printing (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            printDTRTable();
        }
    });

    // Add this JavaScript function to handle the modal
    function showEmployeeNotes(employeeName, position, date, notes) {
        // Set modal content
        document.getElementById('modalEmployeeName').textContent = employeeName;
        document.getElementById('modalEmployeePosition').textContent = 'Position: ' + (position || 'N/A');
        document.getElementById('modalEmployeeDate').textContent = 'Date: ' + (date ? new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }) : 'N/A');

        // Format and set notes content
        const notesContent = document.getElementById('modalNotesContent');
        if (notes && notes.trim() !== '') {
            // Convert line breaks to <br> tags and preserve formatting
            const formattedNotes = notes.replace(/\n/g, '<br>');
            notesContent.innerHTML = formattedNotes;
            notesContent.classList.remove('text-muted', 'fst-italic');
        } else {
            notesContent.textContent = 'No notes available.';
            notesContent.classList.add('text-muted', 'fst-italic');
        }

        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('employeeNotesModal'));
        modal.show();
    }

    // Optional: Add keyboard shortcut to close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = bootstrap.Modal.getInstance(document.getElementById('employeeNotesModal'));
            if (modal) {
                modal.hide();
            }
        }
    });
</script>