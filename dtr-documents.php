<?php
/**
 * DTR Documents — standalone full-page viewer/workbench for one DTR batch.
 *
 * This is the main screen for a batch (dtr.php links straight here). It is NOT
 * routed through index.php: no sidebar, no app header — the whole viewport is
 * the document workspace.
 *
 *   left    employee previews (search + pagination)
 *   center  the selected employee's DTR as a CS Form 48 paper sheet
 *   right   employee summary + every attendance record with its logs and the
 *           full action set (approve / disapprove / edit hours / delete)
 *
 * Batch-level actions (approve all pending, send for review, final approve,
 * print) live in the top bar. Data comes from dtr-employee-server.php
 * (action=docs / summary); decisions go through the same ajax.php actions the
 * old table screen used, so behaviour is identical.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['is_login'])) {
    header("Location: index.php");
    exit;
}
include 'db_connect.php';
$login_role = (int)($_SESSION['login_role'] ?? 0);
$loginId    = (int)($_SESSION['login_id'] ?? 0);
$loginName  = '';
if ($loginId) {
    $lnq = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $lnq->bind_param('i', $loginId);
    $lnq->execute();
    $loginName = (string)($lnq->get_result()->fetch_assoc()['name'] ?? '');
}

if (!isset($_GET['id']) || !isset($_GET['device_id']) || !isset($_GET['site_id'])) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
};

$id              = (int)base64_decode($_GET['id']);
$timekeeper_name = base64_decode($_GET['timekeeper_name'] ?? '');

$stmt = $conn->prepare("SELECT DTR.*, sites.site_code, sites.site_name, employer_name FROM DTR
        LEFT JOIN sites ON sites.id = DTR.site_id
        LEFT JOIN employers ON sites.employer_id = employers.id
        WHERE DTR.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$dtr = $stmt->get_result()->fetch_assoc();

if (!$dtr) {
    header("HTTP/1.1 405 Unauthorized");
    echo "Data not available";
    exit;
}

$periodDays = 0;
if (!empty($dtr['date_from']) && !empty($dtr['date_to'])) {
    $dFrom = date_create($dtr['date_from']);
    $dTo   = date_create($dtr['date_to']);
    if ($dFrom && $dTo) $periodDays = (int)$dFrom->diff($dTo)->days + 1;
}
$minDays = dtr_min_days($periodDays);

// clean_pending uses the shared rule from db_connect.php so this counter,
// the summary endpoint, and the actual clean bulk-approval always agree.
$cleanExpr = "status = 0" . dtr_clean_condition_sql($id, $minDays);
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
           COUNT(DISTINCT date_time)         AS days,
           SUM($cleanExpr)                   AS clean_pending
    FROM DTR_details WHERE ddtr_id = ?
");
$sumStmt->bind_param("i", $id);
$sumStmt->execute();
$agg = $sumStmt->get_result()->fetch_assoc() ?: [];

$batchStatus  = (int)$dtr['status'];
$canEdit      = ($login_role !== 6);
$pendingRecs  = (int)($agg['pending'] ?? 0);
$cleanPending = (int)($agg['clean_pending'] ?? 0);
$excPending   = max(0, $pendingRecs - $cleanPending);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DTR Documents &mdash; <?= htmlspecialchars($dtr['site_code']) ?> <?= date('M d', strtotime($dtr['date_from'])) ?>&ndash;<?= date('M d, Y', strtotime($dtr['date_to'])) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/icons.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root { --brand:#219688; --brand-dark:#176358; --line:#e1dfdd; }
html, body { height:100%; }
body { margin:0; background:#eef2f1; font-family:'Segoe UI',system-ui,Arial,sans-serif; overflow:hidden; }
.ddv-app { display:flex; flex-direction:column; height:100vh; }

/* ── Top bar ── */
.ddv-header {
    flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:10px 16px; background:#fff; border-bottom:1px solid var(--line);
    box-shadow:0 1px 6px rgba(0,0,0,.06); z-index:20; flex-wrap:wrap;
}
.ddv-h-left { display:flex; align-items:center; gap:12px; min-width:0; }
.ddv-back-btn {
    display:inline-flex; align-items:center; gap:6px; flex-shrink:0;
    padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:700;
    color:var(--brand-dark); background:#eef7f5; border:1px solid #d5e6e2; text-decoration:none;
}
.ddv-back-btn:hover { background:#dcefec; color:var(--brand-dark); }
.ddv-title-icon {
    width:38px; height:38px; border-radius:11px; flex-shrink:0;
    background:linear-gradient(135deg,#219688,#2fb3a3); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:18px;
    box-shadow:0 3px 8px rgba(33,150,136,.30);
}
.ddv-h-title { font-size:15px; font-weight:800; color:#2b3a36; line-height:1.1; display:flex; align-items:center; gap:8px; }
.ddv-meta-chips { display:flex; flex-wrap:wrap; gap:5px; margin-top:4px; }
.ddv-meta-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:10.5px; font-weight:600; color:var(--brand-dark);
    background:#eef7f5; border:1px solid #d5e6e2; border-radius:20px; padding:2px 9px;
}
.ddv-meta-chip i { color:var(--brand); font-size:12px; }
.ddv-status-badge { font-size:10.5px; font-weight:800; padding:3px 11px; border-radius:20px; }
.st-open { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.st-pend { background:#e3f2fd; color:#1565c0; border:1px solid #a8cff5; }
.st-rev  { background:#ede7f6; color:#5e35b1; border:1px solid #cbb8ee; }
.st-appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }

.ddv-h-actions { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
.ddv-btn {
    display:inline-flex; align-items:center; gap:5px;
    padding:7px 14px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;
    color:var(--brand-dark); background:#eef7f5; border:1px solid #d5e6e2; transition:background .12s;
    text-decoration:none;
}
.ddv-btn:hover:not(:disabled) { background:#dcefec; border-color:#aad5d0; color:var(--brand-dark); }
.ddv-btn:disabled { opacity:.45; cursor:not-allowed; }
.ddv-btn.primary { background:#d7ece9; border-color:#aad5d0; color:#116257; }
.ddv-btn.primary:hover:not(:disabled) { background:#c2e2dd; }
.ddv-btn.warn { background:#fff8e1; border-color:#ffe082; color:#c98a00; }
.ddv-btn.warn:hover:not(:disabled) { background:#fdefc3; }
.ddv-pend-pill { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; border-radius:12px; padding:0 7px; font-size:10px; font-weight:800; }
.ddv-exc-pill {
    display:inline-flex; align-items:center; gap:4px; cursor:help;
    font-size:11px; font-weight:800; padding:5px 11px; border-radius:20px;
    background:#fdecea; color:#c62828; border:1px solid #f5c6cb;
}

/* ── Workspace ── */
.ddv-wrap {
    flex:1; min-height:0;
    display:grid; grid-template-columns:250px minmax(0,1fr) 330px; gap:13px;
    padding:13px 16px;
}
/* Small screens: the right panel becomes a slide-in drawer so no action is lost */
.ddv-drawer-btn {
    display:none; position:fixed; right:14px; bottom:16px; z-index:44;
    align-items:center; gap:6px; padding:10px 16px; border-radius:24px; cursor:pointer;
    font-size:12.5px; font-weight:800; color:#fff; background:var(--brand);
    border:1px solid var(--brand-dark); box-shadow:0 4px 14px rgba(33,150,136,.4);
}
@media (max-width:1150px) {
    .ddv-wrap { grid-template-columns:220px minmax(0,1fr); }
    .ddv-drawer-btn { display:inline-flex; }
    .ddv-right {
        position:fixed; top:0; right:0; bottom:0; z-index:45;
        width:min(360px, 92vw); padding:14px; background:#eef2f1;
        box-shadow:-6px 0 24px rgba(0,0,0,.18);
        transform:translateX(105%); transition:transform .22s ease;
    }
    .ddv-right.open { transform:translateX(0); }
}

.ddv-panel {
    background:#fff; border:1px solid var(--line); border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.05); overflow:hidden;
    display:flex; flex-direction:column; min-height:0;
}
.ddv-panel-head {
    flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:8px;
    padding:9px 13px; border-bottom:1px solid #eef2f0; background:#f6fbfa;
    font-size:12px; font-weight:800; color:var(--brand-dark);
}
.ddv-panel-head i { color:var(--brand); }

/* ── Left: previews ── */
.ddv-left { min-height:0; }
.ddv-search { flex-shrink:0; padding:9px 11px 5px; }
.ddv-search-wrap { display:flex; align-items:center; gap:7px; border:1px solid #d5e6e2; border-radius:8px; background:#fff; padding:6px 10px; }
.ddv-search-wrap:focus-within { border-color:var(--brand); box-shadow:0 0 0 2px rgba(33,150,136,.15); }
.ddv-search-wrap i { color:var(--brand); font-size:14px; }
.ddv-search-wrap input { border:none; outline:none; flex:1; font-size:12px; min-width:0; background:transparent; }
.ddv-flag-toggle {
    margin-top:6px; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:5px;
    padding:5px 10px; border-radius:8px; font-size:11px; font-weight:700; cursor:pointer;
    color:#c62828; background:#fff; border:1px dashed #f5c6cb; transition:all .12s;
}
.ddv-flag-toggle:hover { background:#fdf4f4; }
.ddv-flag-toggle.on { background:#fdecea; border-style:solid; box-shadow:0 0 0 1px #f5c6cb inset; }
.ddv-list { flex:1; overflow-y:auto; padding:5px 9px 9px; scrollbar-width:thin; scrollbar-color:#b8d8c2 #f1f6f2; }
.ddv-item {
    display:flex; align-items:center; gap:9px; width:100%;
    padding:6px 8px; margin-bottom:4px; text-align:left;
    background:#fff; border:1px solid #e8eeeb; border-radius:9px; cursor:pointer;
    transition:background .12s, border-color .12s;
}
.ddv-item:hover { background:#f4fbfa; border-color:#c9e5e0; }
.ddv-item.active { background:#e6f5f3; border-color:var(--brand); box-shadow:0 0 0 1px var(--brand) inset; }
.ddv-thumb {
    width:30px; height:39px; flex-shrink:0; border-radius:3px;
    background:#fffdf7; border:1px solid #d9d5c9; box-shadow:1px 1px 0 #e8e4d8;
    display:flex; flex-direction:column; align-items:center; padding-top:4px; gap:2px;
}
.ddv-thumb::before { content:''; width:70%; height:2px; background:#3a3a3a; border-radius:1px; }
.ddv-thumb span { display:block; width:78%; height:1.5px; background:#c9c4b4; }
.ddv-item-name { font-size:11px; font-weight:700; color:#33403c; line-height:1.2; word-break:break-word; }
.ddv-item-sub  { font-size:9.5px; color:#8aa39c; margin-top:1px; display:block; }
.ddv-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-left:auto; }
.ddv-dot.ok { background:#0f9d58; } .ddv-dot.pend { background:#f7b84b; } .ddv-dot.disa { background:#c62828; }
.ddv-list-empty { text-align:center; color:#8aa39c; font-size:12px; padding:26px 8px; }
.ddv-pager { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:6px; padding:7px 11px; border-top:1px solid #eef2f0; background:#fafdfc; }
.ddv-pg-btn {
    width:27px; height:27px; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid #d5e6e2; background:#fff; color:var(--brand-dark); border-radius:7px; font-size:15px; cursor:pointer;
}
.ddv-pg-btn:hover:not(:disabled) { background:#eef7f5; }
.ddv-pg-btn:disabled { opacity:.35; cursor:not-allowed; }
.ddv-pg-info { font-size:10.5px; color:#7a8f88; font-weight:600; }

/* ── Center: paper ── */
.ddv-center { min-width:0; min-height:0; display:flex; flex-direction:column; }
.ddv-doc-toolbar { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; max-width:640px; margin:0 auto 9px; width:100%; }
.ddv-doc-nav { display:flex; align-items:center; gap:6px; }
.ddv-doc-pos { font-size:11px; color:#7a8f88; font-weight:600; }
.ddv-paper-scroll { flex:1; overflow-y:auto; min-height:0; scrollbar-width:thin; scrollbar-color:#b8d8c2 transparent; padding-bottom:10px; }
.ddv-paper-holder { display:flex; justify-content:center; }
.ddv-paper {
    background:#fffefb; width:100%; max-width:640px;
    border:1px solid #dcd8cc; border-radius:2px;
    box-shadow:0 2px 14px rgba(60,55,40,.14);
    padding:28px 32px 24px; font-family:'Times New Roman', Times, serif; color:#1a1a1a;
}
.ddv-paper .p-formno { font-size:10px; font-style:italic; }
.ddv-paper .p-title { text-align:center; font-size:19px; font-weight:800; letter-spacing:.6px; margin:4px 0 15px; }
.ddv-paper .p-name { text-align:center; font-size:14px; font-weight:700; text-transform:uppercase; border-bottom:1.5px solid #1a1a1a; padding:0 20px 2px; margin:0 26px; }
.ddv-paper .p-name-lbl { text-align:center; font-size:11px; font-style:italic; margin:2px 0 11px; }
.ddv-paper .p-line { font-size:12px; margin:3px 0; }
.ddv-paper .p-line b { border-bottom:1px solid #1a1a1a; padding:0 8px; font-weight:700; }
.ddv-table { width:100%; border-collapse:collapse; margin-top:11px; font-size:11px; }
.ddv-table th, .ddv-table td { border:1px solid #1a1a1a; text-align:center; padding:2.5px 3px; }
.ddv-table td.day { font-weight:700; width:34px; }
.ddv-table tr.wkend td { background:#f4f1e7; }
.ddv-table tr.absent td:not(.day) { color:#b9b3a2; }
.ddv-table td.ut { width:44px; }
.ddv-table tfoot td { font-weight:800; background:#efeadb; }
.ddv-paper .p-sign { margin-top:24px; font-size:11px; }
.ddv-paper .p-sign .sig-line { border-bottom:1.2px solid #1a1a1a; margin:22px 30px 3px; }
.ddv-paper .p-sign .sig-lbl { text-align:center; font-style:italic; font-size:10.5px; }
.ddv-doc-empty { text-align:center; color:#8aa39c; font-size:13px; padding:60px 10px; }

/* ── Right: summary + records ── */
.ddv-right { min-height:0; display:flex; flex-direction:column; gap:12px; }
.ddv-right .ddv-panel.grow { flex:1; min-height:0; }
.ddv-sum-body { padding:11px 13px; }
.ddv-sum-emp { font-size:12.5px; font-weight:800; color:#33403c; }
.ddv-sum-sub { font-size:10.5px; color:#8aa39c; margin:1px 0 9px; }
.ddv-sum-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:6px; }
.ddv-sum-tile { border:1px solid #e8eeeb; border-radius:8px; padding:6px 4px; background:#fafdfc; text-align:center; }
.ddv-sum-tile .v { font-size:13px; font-weight:800; color:var(--brand); line-height:1.1; }
.ddv-sum-tile .l { font-size:8.5px; color:#8aa39c; text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }
.ddv-sum-tile.ot .v { color:#c98a00; } .ddv-sum-tile.ut .v { color:#1565c0; } .ddv-sum-tile.late .v { color:#c62828; }
.ddv-chips { display:flex; flex-wrap:wrap; gap:4px; margin-top:8px; }
.ddv-chip { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px; }
.ddv-chip.appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.ddv-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-chip.disa { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-attend-bar { margin-top:9px; }
.ddv-attend-lbl { display:flex; justify-content:space-between; font-size:10px; color:#7a8f88; font-weight:600; margin-bottom:3px; }
.ddv-bar { height:7px; border-radius:6px; background:#e9f1ee; overflow:hidden; }
.ddv-bar > div { height:100%; border-radius:6px; background:linear-gradient(90deg,#219688,#5fc9bb); transition:width .3s; }
.ddv-emp-approve-all { width:100%; justify-content:center; margin-top:9px; }

/* Records list */
.ddv-recs { flex:1; overflow-y:auto; min-height:0; padding:8px 10px; scrollbar-width:thin; scrollbar-color:#b8d8c2 #f1f6f2; }
.ddv-rec {
    border:1px solid #e8eeeb; border-radius:9px; padding:7px 9px; margin-bottom:7px; background:#fafdfc;
}
.ddv-rec.is-appr { background:#f2fbf6; border-color:#cdeeda; }
.ddv-rec.is-disa { background:#fdf4f4; border-color:#f3d3d3; }
.ddv-rec-top { display:flex; align-items:center; justify-content:space-between; gap:6px; }
.ddv-rec-date { font-size:11px; font-weight:800; color:#33403c; }
.ddv-rec-badge { font-size:9px; font-weight:800; padding:1px 8px; border-radius:10px; }
.ddv-rec-badge.b-appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.ddv-rec-badge.b-pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-rec-badge.b-disa { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-rec-logs { display:flex; flex-wrap:wrap; gap:3px; margin-top:5px; }
.ddv-log-chip { display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:3px; font-size:9.5px; font-weight:600; }
.ddv-log-chip.bio    { background:#e6f5f3; color:var(--brand); border:1px solid #aad5d0; }
.ddv-log-chip.manual { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-log-chip i { font-size:10px; }
.ddv-rec-stats { display:flex; gap:8px; margin-top:5px; font-size:9.5px; color:#7a8f88; font-weight:600; }
.ddv-rec-stats b { color:var(--brand); font-size:10.5px; }
.ddv-rec-stats .ot b { color:#c98a00; } .ddv-rec-stats .ut b { color:#1565c0; } .ddv-rec-stats .late b { color:#c62828; }
.ddv-rec-flags { display:flex; flex-wrap:wrap; gap:3px; margin-top:5px; }
.ddv-flag { display:inline-flex; align-items:center; gap:3px; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:800; }
.ddv-flag.block { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-flag.info  { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-rec-note {
    margin-top:5px; padding:4px 8px; border-radius:6px; font-size:10px; font-weight:600;
    background:#fdf4f4; color:#a33; border:1px dashed #e8b8b8;
}
.ddv-rec-actions { display:flex; gap:4px; margin-top:6px; }
.ddv-mini-btn {
    flex:1; display:inline-flex; align-items:center; justify-content:center; gap:3px;
    height:24px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; border:1px solid;
}
.ddv-mini-btn.ok   { background:#eafaf0; color:#0f9d58; border-color:#b7e4c7; }
.ddv-mini-btn.no   { background:#fdecea; color:#c62828; border-color:#f5c6cb; }
.ddv-mini-btn.edit { background:#eef2fb; color:#394b7c; border-color:#c3c9e0; }
.ddv-mini-btn.del  { background:#f3f2f1; color:#605e5c; border-color:#e1dfdd; }
.ddv-mini-btn:hover:not(:disabled) { filter:brightness(.96); }
.ddv-mini-btn:disabled { opacity:.4; cursor:not-allowed; }
.ddv-batch-rows { overflow-y:auto; }
.ddv-batch-row { display:flex; justify-content:space-between; align-items:center; padding:5px 13px; font-size:11px; color:#5b6f68; border-bottom:1px dashed #eef2f0; }
.ddv-batch-row:last-child { border-bottom:none; }
.ddv-batch-row b { color:var(--brand-dark); font-size:11.5px; }

/* Loader */
.ddv-loader { display:none; align-items:center; justify-content:center; gap:10px; padding:40px 0; color:var(--brand-dark); font-size:12px; font-weight:700; }
.ddv-loader.show { display:flex; }
.ddv-ring { width:24px; height:24px; border-radius:50%; border:3px solid #d7ece9; border-top-color:var(--brand); animation:ddv-spin .8s linear infinite; }
@keyframes ddv-spin { to { transform:rotate(360deg); } }

/* Edit dialog inputs */
.ddv-edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:9px; text-align:left; }
.ddv-edit-grid label { font-size:11px; font-weight:700; color:#5b6f68; display:block; margin-bottom:2px; }
.ddv-edit-grid input { width:100%; border:1px solid #d5e6e2; border-radius:7px; padding:6px 8px; font-size:13px; }

/* Print: only the paper sheet — or, in print-all mode, every sheet */
#ddv-print-all { display:none; }
@media print {
    body { overflow:visible; }
    body * { visibility:hidden; }
    body:not(.print-all) #ddv-paper, body:not(.print-all) #ddv-paper * { visibility:visible; }
    body:not(.print-all) #ddv-paper { position:absolute; left:0; top:0; width:100%; max-width:none; border:none; box-shadow:none; padding:10px 24px; }
    body.print-all #ddv-print-all { display:block; position:absolute; left:0; top:0; width:100%; }
    body.print-all #ddv-print-all, body.print-all #ddv-print-all * { visibility:visible; }
    body.print-all #ddv-print-all .ddv-paper {
        page-break-after:always; border:none; box-shadow:none; max-width:none; border-radius:0;
    }
}
</style>
</head>
<body>
<div class="ddv-app"
     id="ddv-root"
     data-id="<?= $id ?>"
     data-from="<?= htmlspecialchars($dtr['date_from']) ?>"
     data-to="<?= htmlspecialchars($dtr['date_to']) ?>"
     data-can-edit="<?= $canEdit ? 1 : 0 ?>"
     data-status="<?= $batchStatus ?>">

    <!-- ── Top bar ── -->
    <div class="ddv-header">
        <div class="ddv-h-left">
            <a class="ddv-back-btn" href="index.php?page=dtr"><i class="ri-arrow-left-line"></i> Back</a>
            <div class="ddv-title-icon"><i class="ri-file-text-line"></i></div>
            <div>
                <div class="ddv-h-title">
                    DTR Documents
                    <?php if ($batchStatus === 0): ?>
                        <span class="ddv-status-badge st-open"><i class="ri-time-line"></i> Open</span>
                    <?php elseif ($batchStatus === 1): ?>
                        <span class="ddv-status-badge st-pend"><i class="ri-check-double-line"></i> Pending Approval</span>
                    <?php elseif ($batchStatus === 3): ?>
                        <span class="ddv-status-badge st-rev"><i class="ri-user-received-2-line"></i> Ready for Review</span>
                    <?php else: ?>
                        <span class="ddv-status-badge st-appr"><i class="ri-checkbox-circle-line"></i> Approved</span>
                    <?php endif; ?>
                </div>
                <div class="ddv-meta-chips">
                    <span class="ddv-meta-chip"><i class="ri-calendar-2-line"></i><?= date('M d', strtotime($dtr['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($dtr['date_to'])) ?></span>
                    <span class="ddv-meta-chip"><i class="ri-calendar-event-line"></i><?= $periodDays ?> day<?= $periodDays !== 1 ? 's' : '' ?></span>
                    <span class="ddv-meta-chip"><i class="ri-building-line"></i><?= htmlspecialchars($dtr['site_name']) ?> (<?= htmlspecialchars($dtr['site_code']) ?>)</span>
                    <span class="ddv-meta-chip"><i class="ri-user-2-line"></i><?= htmlspecialchars($dtr['employer_name']) ?></span>
                    <?php if ($timekeeper_name): ?><span class="ddv-meta-chip"><i class="ri-shield-user-line"></i><?= htmlspecialchars($timekeeper_name) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ddv-h-actions">
            <?php if ($canEdit): ?>
                <span class="ddv-exc-pill" id="hdr-exc" style="<?= $excPending > 0 ? '' : 'display:none;' ?>"
                    title="Pending records with something unusual (no time-out, zero hours, or high OT). Bulk approval skips these — decide them per employee.">
                    <i class="ri-error-warning-line"></i> <span id="hdr-exc-n"><?= $excPending ?></span> exception<?= $excPending === 1 ? '' : 's' ?>
                </span>
                <button class="ddv-btn warn" id="btn-approve-all-batch" onclick="approveAllBatch()" style="<?= $cleanPending > 0 ? '' : 'display:none;' ?>"
                    title="Approve every pending record that has no exception flags. Flagged records stay pending.">
                    <i class="ri-checkbox-multiple-line"></i> Approve Clean
                    <span class="ddv-pend-pill" id="hdr-clean"><?= $cleanPending ?></span>
                </button>
                <?php if ($batchStatus === 1): ?>
                    <button class="ddv-btn primary" id="btn-send-review" onclick="sendForReview()" <?= $pendingRecs > 0 ? 'disabled' : '' ?>
                        title="Decide all records first, then send to employees for review">
                        <i class="ri-user-received-2-line"></i> Send for Review
                    </button>
                <?php elseif ($batchStatus === 3): ?>
                    <button class="ddv-btn primary" onclick="finalApprove()" title="Final approve for payroll">
                        <i class="ri-checkbox-circle-line"></i> Final Approve
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <button class="ddv-btn" id="ddv-print" onclick="window.print()" title="Print the selected employee's DTR"><i class="ri-printer-line"></i> Print</button>
            <button class="ddv-btn" id="ddv-print-all-btn" onclick="printAll()" title="Print every employee's DTR sheet in this batch"><i class="ri-printer-cloud-line"></i> Print All</button>
        </div>
    </div>

    <!-- ── Workspace ── -->
    <div class="ddv-wrap">

        <!-- LEFT -->
        <div class="ddv-panel ddv-left">
            <div class="ddv-panel-head"><span><i class="ri-team-line"></i> Employees</span><span id="ddv-total" style="font-weight:600;color:#7a8f88;font-size:10.5px;"></span></div>
            <div class="ddv-search">
                <div class="ddv-search-wrap">
                    <i class="ri-search-2-line"></i>
                    <input id="ddv-q" type="text" placeholder="Search name, no., position...">
                </div>
                <button type="button" class="ddv-flag-toggle" id="ddv-flagged-toggle" onclick="toggleFlagged()"
                    title="Show only employees with flagged pending records or low attendance">
                    <i class="ri-error-warning-line"></i> Flagged only
                </button>
            </div>
            <div class="ddv-list" id="ddv-list">
                <div class="ddv-loader show"><span class="ddv-ring"></span> Loading...</div>
            </div>
            <div class="ddv-pager">
                <button class="ddv-pg-btn" id="ddv-prev" title="Previous page"><i class="ri-arrow-left-s-line"></i></button>
                <span class="ddv-pg-info" id="ddv-pg-info">&ndash;</span>
                <button class="ddv-pg-btn" id="ddv-next" title="Next page"><i class="ri-arrow-right-s-line"></i></button>
            </div>
        </div>

        <!-- CENTER -->
        <div class="ddv-center">
            <div class="ddv-doc-toolbar">
                <div class="ddv-doc-nav">
                    <button class="ddv-btn" id="ddv-doc-prev"><i class="ri-arrow-left-s-line"></i> Prev</button>
                    <button class="ddv-btn" id="ddv-doc-next">Next <i class="ri-arrow-right-s-line"></i></button>
                    <span class="ddv-doc-pos" id="ddv-doc-pos"></span>
                </div>
            </div>
            <div class="ddv-paper-scroll">
                <div class="ddv-paper-holder">
                    <div class="ddv-paper" id="ddv-paper">
                        <div class="ddv-doc-empty">Select an employee to view their Daily Time Record.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT -->
        <div class="ddv-right">
            <div class="ddv-panel">
                <div class="ddv-panel-head"><span><i class="ri-user-3-line"></i> Employee Summary</span></div>
                <div class="ddv-sum-body" id="ddv-emp-summary">
                    <div style="font-size:12px;color:#8aa39c;">No employee selected.</div>
                </div>
            </div>
            <div class="ddv-panel grow">
                <div class="ddv-panel-head">
                    <span><i class="ri-fingerprint-line"></i> Records &amp; Logs</span>
                    <span id="ddv-rec-count" style="font-weight:600;color:#7a8f88;font-size:10.5px;"></span>
                </div>
                <div class="ddv-recs" id="ddv-recs">
                    <div style="font-size:11.5px;color:#8aa39c;padding:8px;">No employee selected.</div>
                </div>
            </div>
            <div class="ddv-panel" style="flex-shrink:0;">
                <div class="ddv-panel-head"><span><i class="ri-stack-line"></i> Batch Summary</span>
                    <button type="button" class="ddv-pg-btn" onclick="toggleDrawer(false)" style="display:none;" id="ddv-drawer-close" title="Close"><i class="ri-close-line"></i></button>
                </div>
                <div class="ddv-batch-rows" id="ddv-batch">
                    <div class="ddv-batch-row"><span>Employees</span><b data-b="employees"><?= (int)($agg['employees'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Total work hours</span><b data-b="work_hours"><?= number_format((float)($agg['work_hours'] ?? 0), 2) ?></b></div>
                    <div class="ddv-batch-row"><span>Approved</span><b data-b="approved" style="color:#0f9d58;"><?= (int)($agg['approved'] ?? 0) ?> / <?= (int)($agg['total'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Pending</span><b data-b="pending" style="color:#c98a00;"><?= (int)($agg['pending'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Disapproved</span><b data-b="disapproved" style="color:#c62828;"><?= (int)($agg['disapproved'] ?? 0) ?></b></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Slide-in access to summary/actions on narrow screens -->
    <button type="button" class="ddv-drawer-btn" onclick="toggleDrawer()" id="ddv-drawer-btn">
        <i class="ri-list-check-2"></i> Summary &amp; Actions
    </button>
</div>

<!-- Print All target: every employee's sheet is rendered here on demand -->
<div id="ddv-print-all"></div>

<script>
const root      = document.getElementById('ddv-root');
const DDTR_ID   = root.dataset.id;
const DATE_FROM = root.dataset.from;
const DATE_TO   = root.dataset.to;
const CAN_EDIT  = root.dataset.canEdit === '1';
// Global DTR rules (defined once in db_connect.php)
const LOG_MODE  = <?= json_encode(DTR_LOG_MODE) ?>;      // 'single' | 'ampm'
const OT_HOURS  = <?= (float)DTR_HIGH_OT_HOURS ?>;
const MIN_DAYS  = <?= (int)$minDays ?>;
const ME        = <?= json_encode($loginName) ?>;         // for instant audit lines

const st = { page: 0, size: 20, q: '', flagged: false, total: 0, emps: [], sel: -1, seq: 0 };

const $id = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const toast = msg => Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, timer: 1600, showConfirmButton: false });

// ── Data ────────────────────────────────────────────────────────────────────
async function loadPage(keepSel) {
    const seq = ++st.seq;
    $id('ddv-list').innerHTML = '<div class="ddv-loader show"><span class="ddv-ring"></span> Loading...</div>';
    try {
        const u = `dtr-employee-server.php?action=docs&id=${DDTR_ID}&offset=${st.page * st.size}&limit=${st.size}&q=${encodeURIComponent(st.q)}${st.flagged ? '&flagged=1' : ''}`;
        const r = await fetch(u);
        const j = await r.json();
        if (seq !== st.seq) return;
        if (!j.result) throw new Error(j.message || 'Failed');
        st.total = j.total;
        st.emps  = j.employees || [];
        st.sel   = st.emps.length ? Math.min(keepSel ?? 0, st.emps.length - 1) : -1;
        renderList(); renderPager(); renderSelected();
    } catch (e) {
        if (seq !== st.seq) return;
        $id('ddv-list').innerHTML = '<div class="ddv-list-empty">Could not load employees.<br>' + esc(e.message) + '</div>';
    }
}

async function refreshBatch() {
    try {
        const r = await fetch(`dtr-employee-server.php?action=summary&id=${DDTR_ID}`);
        const j = await r.json();
        if (!j.result) return 0;
        const s = j.summary;
        const set = (k, v) => { const el = document.querySelector(`[data-b="${k}"]`); if (el) el.textContent = v; };
        set('employees', s.employees);
        set('work_hours', Number(s.work_hours).toFixed(2));
        set('approved', `${s.approved} / ${s.total}`);
        set('pending', s.pending);
        set('disapproved', s.disapproved);
        const exceptions = Math.max(0, s.pending - (s.clean_pending || 0));
        const hc = $id('hdr-clean');
        if (hc) hc.textContent = s.clean_pending || 0;
        const bab = $id('btn-approve-all-batch');
        if (bab) bab.style.display = (s.clean_pending || 0) > 0 ? '' : 'none';
        const he = $id('hdr-exc'), hen = $id('hdr-exc-n');
        if (he) he.style.display = exceptions > 0 ? '' : 'none';
        if (hen) hen.textContent = exceptions;
        const sr = $id('btn-send-review');
        if (sr) sr.disabled = s.pending > 0;
        return exceptions;
    } catch (e) { return 0; /* summary refresh is best-effort */ }
}

// ── Left list ────────────────────────────────────────────────────────────────
function renderList() {
    const box = $id('ddv-list');
    $id('ddv-total').textContent = st.total ? st.total + ' total' : '';
    if (!st.emps.length) {
        box.innerHTML = '<div class="ddv-list-empty"><i class="ri-search-eye-line" style="font-size:22px;display:block;margin-bottom:6px;"></i>No employees found.</div>';
        return;
    }
    box.innerHTML = st.emps.map((e, i) => {
        const dot = e.disa > 0 ? 'disa' : (e.pend > 0 ? 'pend' : 'ok');
        const marks = [];
        if (e.exc > 0)  marks.push(`<i class="ri-error-warning-fill" style="color:#c62828;font-size:13px;flex-shrink:0;" title="${e.exc} flagged record(s) need a manual decision"></i>`);
        if (e.low_att)  marks.push(`<i class="ri-calendar-close-fill" style="color:#c98a00;font-size:13px;flex-shrink:0;" title="Low attendance — fewer than ${MIN_DAYS} logged days"></i>`);
        return `<button type="button" class="ddv-item ${i === st.sel ? 'active' : ''}" data-i="${i}">
            <span class="ddv-thumb"><span></span><span></span><span></span><span></span></span>
            <span style="min-width:0;">
                <span class="ddv-item-name">${esc(e.lastname)}, ${esc(e.firstname)}</span>
                <span class="ddv-item-sub">${esc(e.no)}${e.position ? ' · ' + esc(e.position) : ''}</span>
            </span>
            ${marks.join('')}<span class="ddv-dot ${dot}" style="${marks.length ? 'margin-left:4px;' : ''}"></span>
        </button>`;
    }).join('');
}
$id('ddv-list').addEventListener('click', ev => {
    const btn = ev.target.closest('.ddv-item');
    if (!btn) return;
    st.sel = parseInt(btn.dataset.i, 10);
    renderList(); renderSelected();
});

// ── Pager + search ───────────────────────────────────────────────────────────
function renderPager() {
    const pages = Math.max(1, Math.ceil(st.total / st.size));
    $id('ddv-pg-info').textContent = st.total ? `Page ${st.page + 1} of ${pages}` : 'No results';
    $id('ddv-prev').disabled = st.page <= 0;
    $id('ddv-next').disabled = st.page >= pages - 1;
}
$id('ddv-prev').onclick = () => { if (st.page > 0) { st.page--; loadPage(); } };
$id('ddv-next').onclick = () => { st.page++; loadPage(); };
let qTimer = null;
$id('ddv-q').addEventListener('input', ev => {
    clearTimeout(qTimer);
    qTimer = setTimeout(() => { st.q = ev.target.value.trim(); st.page = 0; loadPage(); }, 300);
});

// ── Selection ────────────────────────────────────────────────────────────────
function renderSelected() {
    const e = st.emps[st.sel];
    $id('ddv-doc-pos').textContent = e ? `${st.page * st.size + st.sel + 1} of ${st.total}` : '';
    $id('ddv-doc-prev').disabled = st.sel <= 0;
    $id('ddv-doc-next').disabled = st.sel < 0 || st.sel >= st.emps.length - 1;
    renderDoc(e); renderSummary(e); renderRecords(e);
}

function eachDay(cb) {
    const d = new Date(DATE_FROM + 'T00:00:00');
    const end = new Date(DATE_TO + 'T00:00:00');
    while (d <= end) {
        const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        cb(iso, d.getDate(), d.getDay());
        d.setDate(d.getDate() + 1);
    }
}
const utSplit = ut => { const h = Math.floor(ut); return [h, Math.round((ut - h) * 60)]; };

// ── Paper document ───────────────────────────────────────────────────────────
// Column layout follows DTR_LOG_MODE: 'single' = one Arrival/Departure pair per
// day; 'ampm' = the classic 4-punch Form 48. Returns HTML so Print All can
// render every employee with the same markup.
function docHTML(e) {
    const ampm = LOG_MODE === 'ampm';
    let rows = '';
    eachDay((iso, dayNo, dow) => {
        const d = e.days[iso];
        const wkend = (dow === 0 || dow === 6) ? ' wkend' : '';
        const blank = ampm ? '<td></td><td></td><td></td><td></td>' : '<td></td><td></td>';
        if (!d) {
            rows += `<tr class="absent${wkend}"><td class="day">${dayNo}</td>${blank}<td class="ut"></td><td class="ut"></td></tr>`;
            return;
        }
        const [uh, um] = utSplit(d.ut || 0);
        const times = ampm
            ? `<td>${esc(d.am_in)}</td><td>${esc(d.am_out)}</td><td>${esc(d.pm_in)}</td><td>${esc(d.pm_out)}</td>`
            : `<td>${esc(d.in)}</td><td>${esc(d.out)}</td>`;
        rows += `<tr class="${wkend.trim()}">
            <td class="day">${dayNo}</td>${times}
            <td class="ut">${d.ut > 0 ? uh : ''}</td><td class="ut">${d.ut > 0 ? um : ''}</td>
        </tr>`;
    });
    const [tuh, tum] = utSplit(e.totals.ut || 0);
    const head = ampm
        ? `<tr><th rowspan="2">Day</th><th colspan="2">A.M.</th><th colspan="2">P.M.</th><th colspan="2">UNDERTIME</th></tr>
           <tr><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>`
        : `<tr><th rowspan="2">Day</th><th colspan="2">TIME</th><th colspan="2">UNDERTIME</th></tr>
           <tr><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>`;
    const totals = `${Number(e.totals.wh).toFixed(2)} hrs${e.totals.ot > 0 ? ' &nbsp;·&nbsp; OT ' + Number(e.totals.ot).toFixed(2) : ''}`;
    const foot = ampm
        ? `<td colspan="2">TOTAL</td><td colspan="2">${totals}</td><td>${tuh}</td><td>${tum}</td><td></td>`
        : `<td>TOTAL</td><td colspan="2">${totals}</td><td>${tuh}</td><td>${tum}</td>`;
    return `
        <div class="p-formno">Civil Service Form No. 48</div>
        <div class="p-title">DAILY TIME RECORD</div>
        <div class="p-name">${esc(e.lastname)}, ${esc(e.firstname)} ${esc(e.middlename || '')}</div>
        <div class="p-name-lbl">(NAME)</div>
        <div class="p-line">For the Month of: <b>${esc(fmtPeriod())}</b></div>
        <div class="p-line">Official Hours arrival: <b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</b> / <b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</b> &nbsp;Regular days: <b>Mon &ndash; Fri</b></div>
        <div class="p-line">And departure: <b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</b> / <b>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</b> &nbsp;Saturdays: <b>as required</b></div>
        <table class="ddv-table">
            <thead>${head}</thead>
            <tbody>${rows}</tbody>
            <tfoot><tr>${foot}</tr></tfoot>
        </table>
        <div class="p-sign">
            I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.
            <div class="sig-line"></div>
            <div class="sig-lbl">(Signature)</div>
            <div class="sig-line"></div>
            <div class="sig-lbl">Verified as to the prescribed office hours &mdash; In Charge</div>
        </div>`;
}

function renderDoc(e) {
    const paper = $id('ddv-paper');
    paper.innerHTML = e ? docHTML(e) : '<div class="ddv-doc-empty">Select an employee to view their Daily Time Record.</div>';
}

function fmtPeriod() {
    const f = new Date(DATE_FROM + 'T00:00:00'), t = new Date(DATE_TO + 'T00:00:00');
    const M = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    if (f.getMonth() === t.getMonth() && f.getFullYear() === t.getFullYear())
        return `${M[f.getMonth()]} ${f.getDate()}–${t.getDate()}, ${t.getFullYear()}`;
    return `${M[f.getMonth()]} ${f.getDate()} – ${M[t.getMonth()]} ${t.getDate()}, ${t.getFullYear()}`;
}

// ── Right: summary ───────────────────────────────────────────────────────────
function renderSummary(e) {
    const box = $id('ddv-emp-summary');
    if (!e) { box.innerHTML = '<div style="font-size:12px;color:#8aa39c;">No employee selected.</div>'; return; }
    const daysPresent = Object.keys(e.days).length;
    let periodDays = 0; eachDay(() => periodDays++);
    const pct = periodDays ? Math.round(daysPresent / periodDays * 100) : 0;
    box.innerHTML = `
        <div class="ddv-sum-emp">${esc(e.lastname)}, ${esc(e.firstname)} ${esc(e.middlename || '')}</div>
        <div class="ddv-sum-sub">${esc(e.no)}${e.position ? ' · ' + esc(e.position) : ''}${e.department ? ' · ' + esc(e.department) : ''}</div>
        <div class="ddv-sum-grid">
            <div class="ddv-sum-tile"><div class="v">${Number(e.totals.wh).toFixed(2)}</div><div class="l">Hours</div></div>
            <div class="ddv-sum-tile ot"><div class="v">${Number(e.totals.ot).toFixed(2)}</div><div class="l">OT</div></div>
            <div class="ddv-sum-tile ut"><div class="v">${Number(e.totals.ut).toFixed(2)}</div><div class="l">UT</div></div>
            <div class="ddv-sum-tile late"><div class="v">${Number(e.totals.late).toFixed(2)}</div><div class="l">Late</div></div>
        </div>
        <div class="ddv-chips">
            <span class="ddv-chip appr"><i class="ri-checkbox-circle-line"></i> ${e.appr} approved</span>
            <span class="ddv-chip pend"><i class="ri-time-line"></i> ${e.pend} pending</span>
            <span class="ddv-chip disa"><i class="ri-close-circle-line"></i> ${e.disa} disapproved</span>
            ${e.exc > 0 ? `<span class="ddv-chip disa" title="Flagged pending records — review them below"><i class="ri-error-warning-line"></i> ${e.exc} flagged</span>` : ''}
            ${e.low_att ? `<span class="ddv-chip pend" title="Logged fewer than ${MIN_DAYS} of the period's days — excluded from clean bulk-approval"><i class="ri-calendar-close-line"></i> Low attendance</span>` : ''}
        </div>
        <div class="ddv-attend-bar">
            <div class="ddv-attend-lbl"><span>Attendance</span><span>${daysPresent} of ${periodDays} days (${pct}%)</span></div>
            <div class="ddv-bar"><div style="width:${pct}%;"></div></div>
        </div>
        ${CAN_EDIT && e.pend > 0 ? `<button class="ddv-btn primary ddv-emp-approve-all" onclick="approveEmployee()">
            <i class="ri-checkbox-circle-line"></i> Approve All Pending (${e.pend})</button>` : ''}`;
}

// ── Right: records & logs ────────────────────────────────────────────────────
function renderRecords(e) {
    const box = $id('ddv-recs');
    if (!e) { box.innerHTML = '<div style="font-size:11.5px;color:#8aa39c;padding:8px;">No employee selected.</div>'; $id('ddv-rec-count').textContent = ''; return; }
    const dates = Object.keys(e.days).sort();
    let n = 0, html = '';
    dates.forEach(date => {
        (e.days[date].recs || []).forEach(r => {
            n++;
            const cls  = r.status === 1 ? 'is-appr' : (r.status === 2 ? 'is-disa' : '');
            const badge = r.status === 1 ? '<span class="ddv-rec-badge b-appr">Approved</span>'
                        : r.status === 2 ? '<span class="ddv-rec-badge b-disa">Disapproved</span>'
                        : '<span class="ddv-rec-badge b-pend">Pending</span>';
            const logs = (r.logs && r.logs.length)
                ? r.logs.map(l => `<span class="ddv-log-chip ${l.bio ? 'bio' : 'manual'}"><i class="${l.bio ? 'ri-fingerprint-line' : 'ri-edit-line'}"></i>${esc(l.t)}</span>`).join('')
                : '<span style="font-size:9.5px;color:#aaa;">No logs</span>';
            const dLbl = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            const FLAG_META = {
                no_out:     { cls: 'block', icon: 'ri-logout-box-r-line',    lbl: 'No time-out' },
                zero_hours: { cls: 'block', icon: 'ri-time-line',            lbl: 'Zero hours' },
                high_ot:    { cls: 'block', icon: 'ri-sun-line',             lbl: 'High OT' },
                manual:     { cls: 'info',  icon: 'ri-edit-line',            lbl: 'Manual log' },
            };
            const flags = (r.flags || []).map(f => {
                const m = FLAG_META[f];
                return m ? `<span class="ddv-flag ${m.cls}"><i class="${m.icon}"></i>${m.lbl}</span>` : '';
            }).join('');
            const note = (r.status === 2 && r.note)
                ? `<div class="ddv-rec-note"><i class="ri-chat-1-line"></i> ${esc(r.note)}</div>` : '';
            const audit = (r.status !== 0 && r.by)
                ? `<div style="margin-top:4px;font-size:9px;color:#98a8a2;"><i class="ri-user-follow-line"></i> ${r.status === 1 ? 'Approved' : 'Disapproved'} by ${esc(r.by)}${r.at ? ' · ' + esc(r.at) : ''}</div>` : '';
            html += `<div class="ddv-rec ${cls}" id="rec-${r.id}">
                <div class="ddv-rec-top"><span class="ddv-rec-date"><i class="ri-calendar-event-line" style="color:#219688;"></i> ${dLbl}</span>${badge}</div>
                <div class="ddv-rec-logs">${logs}</div>
                ${flags ? `<div class="ddv-rec-flags">${flags}</div>` : ''}
                ${note}${audit}
                <div class="ddv-rec-stats">
                    <span>Hrs <b>${Number(r.wh).toFixed(2)}</b></span>
                    <span class="ot">OT <b>${Number(r.ot).toFixed(2)}</b></span>
                    <span class="ut">UT <b>${Number(r.ut).toFixed(2)}</b></span>
                    <span class="late">Late <b>${Number(r.late).toFixed(2)}</b></span>
                </div>
                ${CAN_EDIT ? `<div class="ddv-rec-actions">
                    <button class="ddv-mini-btn ok"   onclick="decideRecs([${r.id}], 1)" ${r.status === 1 ? 'disabled' : ''}><i class="ri-check-line"></i> Approve</button>
                    <button class="ddv-mini-btn no"   onclick="decideRecs([${r.id}], 2)" ${r.status === 2 ? 'disabled' : ''}><i class="ri-close-line"></i> Reject</button>
                    <button class="ddv-mini-btn edit" onclick="editRec(${r.id})"><i class="ri-pencil-line"></i></button>
                    <button class="ddv-mini-btn del"  onclick="deleteRec(${r.id})"><i class="ri-delete-bin-6-line"></i></button>
                </div>` : ''}
            </div>`;
        });
    });
    $id('ddv-rec-count').textContent = n ? n + ' record' + (n > 1 ? 's' : '') : '';
    box.innerHTML = html || '<div style="font-size:11.5px;color:#8aa39c;padding:8px;">No records.</div>';
}

// ── Local mutation helpers ───────────────────────────────────────────────────
function findRec(recId) {
    for (const e of st.emps)
        for (const date of Object.keys(e.days))
            for (const r of (e.days[date].recs || []))
                if (r.id === recId) return { e, date, r };
    return null;
}
// Mirrors the server's flag rules (blockers first, 'manual' informational)
// so flags stay correct after client-side edits without a refetch.
function recFlags(r) {
    const f = [];
    if ((r.logs || []).length < 2) f.push('no_out');
    if (r.wh <= 0) f.push('zero_hours');
    if (r.ot > OT_HOURS) f.push('high_ot');
    if ((r.logs || []).some(l => !l.bio)) f.push('manual');
    return f;
}
const hasBlocker = r => r.flags.some(f => f !== 'manual');

function recomputeEmp(e) {
    e.appr = e.pend = e.disa = e.exc = 0;
    e.totals = { wh: 0, ot: 0, ut: 0, late: 0 };
    e.low_att = MIN_DAYS > 0 && Object.keys(e.days).length < MIN_DAYS;
    for (const date of Object.keys(e.days)) {
        const d = e.days[date];
        d.wh = d.ot = d.ut = d.late = 0; d.status = 1;
        for (const r of (d.recs || [])) {
            r.flags = recFlags(r);
            d.wh += r.wh; d.ot += r.ot; d.ut += r.ut; d.late += r.late;
            e.totals.wh += r.wh; e.totals.ot += r.ot; e.totals.ut += r.ut; e.totals.late += r.late;
            if (r.status === 1) e.appr++; else if (r.status === 2) { e.disa++; d.status = 2; }
            else { e.pend++; if (hasBlocker(r)) e.exc++; if (d.status !== 2) d.status = 0; }
        }
    }
}
function rerenderAll() { renderList(); renderSelected(); refreshBatch(); }

// ── Actions (same ajax.php endpoints as the old table screen) ────────────────
function decideRecs(ids, decision, confirmText) {
    const label = decision === 1 ? 'Approve' : 'Disapprove';
    // Disapprovals need a reason: it's stored on the record and shown to the
    // employee side of the dispute, so a blank rejection can't happen.
    const dlg = decision === 2
        ? Swal.fire({
            title: 'Disapprove?',
            text: confirmText || 'This attendance record will be disapproved.',
            input: 'text', inputLabel: 'Reason (required)',
            inputPlaceholder: 'e.g. No matching schedule / duplicate log / wrong site',
            inputAttributes: { maxlength: 255 },
            inputValidator: v => (!v || !v.trim()) ? 'A reason is required to disapprove.' : undefined,
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#c62828', confirmButtonText: 'Yes, disapprove',
        })
        : Swal.fire({
            title: 'Approve?',
            text: confirmText || 'This attendance record will be approved.',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#0f9d58', confirmButtonText: 'Yes, approve',
        });
    dlg.then(res => {
        if (!res.isConfirmed) return;
        const note = decision === 2 ? String(res.value || '').trim() : '';
        $.ajax({
            url: 'ajax.php?action=decide_dtr_details', method: 'POST', dataType: 'JSON',
            data: { ids: ids, decision: decision, note: note },
            success: r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                const at = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ', ' +
                           new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                ids.forEach(id => {
                    const hit = findRec(id);
                    if (hit) { hit.r.status = decision; hit.r.note = note; hit.r.by = ME; hit.r.at = at; }
                });
                st.emps.forEach(recomputeEmp);
                rerenderAll();
                toast((r.affected || ids.length) + ' record(s) ' + (decision === 1 ? 'approved' : 'disapproved'));
                if (st.advanceAfterApprove) { st.advanceAfterApprove = false; $id('ddv-doc-next').click(); }
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

function approveEmployee() {
    const e = st.emps[st.sel];
    if (!e) return;
    const ids = [];
    Object.values(e.days).forEach(d => (d.recs || []).forEach(r => { if (r.status !== 1 && r.status !== 2) ids.push(r.id); }));
    if (!ids.length) return;
    decideRecs(ids, 1, `All ${ids.length} pending record(s) of ${e.lastname}, ${e.firstname} will be approved.`);
}

function approveAllBatch() {
    Swal.fire({
        title: 'Approve all clean pending?',
        html: 'Every pending record <b>without exception flags</b> will be approved.<br>' +
              'Flagged records (no time-out, zero hours, high OT) stay pending for a manual decision.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#0f9d58', confirmButtonText: 'Yes, approve clean',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.ajax({
            url: 'ajax.php?action=decide_dtr_details', method: 'POST', dataType: 'JSON',
            data: { ddtr_id: DDTR_ID, decision: 1, clean_only: 1 },
            success: async r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                await loadPage(st.sel);
                const left = await refreshBatch();
                Swal.fire({
                    icon: left > 0 ? 'info' : 'success',
                    title: (r.affected || 0) + ' record(s) approved',
                    text: left > 0
                        ? left + ' flagged record(s) remain pending — look for the red markers in the employee list.'
                        : 'No exceptions left. The batch is fully decided.',
                });
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

function sendForReview() {
    Swal.fire({
        title: 'Send for employee review?',
        text: 'Employees will be notified to confirm or dispute their attendance in the portal.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#219688', confirmButtonText: 'Yes, send',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.ajax({
            url: 'ajax.php?action=send_dtr_for_review', method: 'POST', dataType: 'JSON',
            data: { id: DDTR_ID },
            success: r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                Swal.fire({ icon: 'success', title: 'Sent!', text: r.message || 'Sent for review.' }).then(() => location.reload());
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

function finalApprove() {
    Swal.fire({
        title: 'Final approve this DTR?',
        text: 'The batch becomes available for payroll processing.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0f9d58', confirmButtonText: 'Yes, approve',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.ajax({
            url: 'ajax.php?action=update_status_dtr', method: 'POST', dataType: 'JSON',
            data: { id: DDTR_ID },
            success: r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                Swal.fire({ icon: 'success', title: 'Approved!', text: 'DTR approved for payroll.' }).then(() => location.reload());
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

function editRec(recId) {
    const hit = findRec(recId);
    if (!hit) return;
    const r = hit.r;
    Swal.fire({
        title: 'Edit record',
        html: `<div class="ddv-edit-grid">
            <div><label>Work hours</label><input id="ed-wh" type="number" step="0.01" min="0" value="${r.wh}"></div>
            <div><label>Overtime</label><input id="ed-ot" type="number" step="0.01" min="0" value="${r.ot}"></div>
            <div><label>Undertime</label><input id="ed-ut" type="number" step="0.01" min="0" value="${r.ut}"></div>
            <div><label>Late (min)</label><input id="ed-late" type="number" step="0.01" min="0" value="${r.late}"></div>
        </div>`,
        showCancelButton: true, confirmButtonColor: '#219688', confirmButtonText: 'Save changes',
        preConfirm: () => ({
            work_hours: parseFloat(document.getElementById('ed-wh').value) || 0,
            overtime:   parseFloat(document.getElementById('ed-ot').value) || 0,
            undertime:  parseFloat(document.getElementById('ed-ut').value) || 0,
            late:       parseFloat(document.getElementById('ed-late').value) || 0,
        }),
    }).then(async res => {
        if (!res.isConfirmed) return;
        const v = res.value;
        const current = { work_hours: r.wh, overtime: r.ot, undertime: r.ut, late: r.late };
        const changed = Object.keys(v).filter(k => v[k] !== current[k]);
        if (!changed.length) return;
        try {
            for (const field of changed) {
                const resp = await $.ajax({
                    url: 'ajax.php?action=update_dtr_logs', method: 'POST', dataType: 'JSON',
                    data: { id: recId, [field]: v[field] },
                });
                if (!(resp && resp.result)) throw new Error((resp && resp.message) || 'Update failed');
            }
            // Server resets the record to pending on any figure edit — mirror it.
            r.wh = v.work_hours; r.ot = v.overtime; r.ut = v.undertime; r.late = v.late;
            r.status = 0; r.note = '';
            st.emps.forEach(recomputeEmp);
            rerenderAll();
            toast('Record updated — set back to Pending for re-approval');
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error!', text: err.message || 'Update failed.' });
        }
    });
}

function deleteRec(recId) {
    Swal.fire({
        title: 'Delete this record?',
        text: "You won't be able to revert this!",
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#c62828', confirmButtonText: 'Yes, delete it',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.ajax({
            url: 'ajax.php?action=delete_dtr_logs', method: 'POST', dataType: 'JSON',
            data: { id: recId },
            success: r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                const hit = findRec(recId);
                if (hit) {
                    const list = hit.e.days[hit.date].recs;
                    list.splice(list.indexOf(hit.r), 1);
                    if (!list.length) delete hit.e.days[hit.date];
                    recomputeEmp(hit.e);
                }
                rerenderAll();
                toast('Record deleted');
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

// ── Flagged-only filter ──────────────────────────────────────────────────────
function toggleFlagged() {
    st.flagged = !st.flagged;
    $id('ddv-flagged-toggle').classList.toggle('on', st.flagged);
    st.page = 0;
    loadPage();
}

// ── Print All: every employee's sheet, one page each ─────────────────────────
async function printAll() {
    Swal.fire({ title: 'Preparing documents...', text: 'Building every DTR sheet', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        // Whole batch, ignoring the current search/filter/page.
        const all = [];
        let total = Infinity;
        for (let off = 0; off < total; off += 100) {
            const r = await fetch(`dtr-employee-server.php?action=docs&id=${DDTR_ID}&offset=${off}&limit=100`);
            const j = await r.json();
            if (!j.result) throw new Error(j.message || 'Failed');
            total = j.total;
            all.push(...(j.employees || []));
            if (!j.employees || !j.employees.length) break;
        }
        const box = $id('ddv-print-all');
        box.innerHTML = all.map(e => `<div class="ddv-paper">${docHTML(e)}</div>`).join('');
        Swal.close();
        document.body.classList.add('print-all');
        const cleanup = () => { document.body.classList.remove('print-all'); box.innerHTML = ''; window.removeEventListener('afterprint', cleanup); };
        window.addEventListener('afterprint', cleanup);
        window.print();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: e.message || 'Could not build the documents.' });
    }
}

// ── Right-panel drawer (small screens) ───────────────────────────────────────
function toggleDrawer(force) {
    const open = typeof force === 'boolean' ? force : !document.querySelector('.ddv-right').classList.contains('open');
    document.querySelector('.ddv-right').classList.toggle('open', open);
    $id('ddv-drawer-close').style.display = open ? '' : 'none';
}

// ── Doc navigation + keyboard review ─────────────────────────────────────────
$id('ddv-doc-prev').onclick = () => { if (st.sel > 0) { st.sel--; renderList(); renderSelected(); scrollSel(); } };
$id('ddv-doc-next').onclick = () => { if (st.sel < st.emps.length - 1) { st.sel++; renderList(); renderSelected(); scrollSel(); } };
function scrollSel() { const el = document.querySelector('.ddv-item.active'); if (el) el.scrollIntoView({ block: 'nearest' }); }
document.addEventListener('keydown', ev => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
    if (Swal.isVisible()) return;
    if (ev.key === 'ArrowUp')   { ev.preventDefault(); $id('ddv-doc-prev').click(); }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); $id('ddv-doc-next').click(); }
    // A = approve the selected employee's pending records (confirm dialog opens;
    // Enter confirms), then jump to the next employee once applied.
    if (CAN_EDIT && (ev.key === 'a' || ev.key === 'A')) {
        ev.preventDefault();
        const e = st.emps[st.sel];
        if (e && e.pend > 0) { st.advanceAfterApprove = true; approveEmployee(); }
        else $id('ddv-doc-next').click();
    }
});

loadPage();
</script>
</body>
</html>
