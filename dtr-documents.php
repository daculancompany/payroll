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
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    header("Location: index.php");
    exit;
}
include 'db_connect.php';
// Standalone workbench — index.php's router never sees it, so it enforces the
// admin-only DTR boundary itself.
require_page_access('dtr-documents');
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

// ── Employee review progress (whole-batch sign-off) — shown once a batch is
// out for review (status 3) or approved (status 2). Same data the old
// dtr-details.php surfaced, ported here so admins see confirm/dispute status.
$reviewTotalEmp = (int)($agg['employees'] ?? 0);
$reviewRows = [];
$reviewConfirmed = $reviewDisputed = 0;
// The sign-off conversation keyed by employee (message + reply), and how many
// of those messages nobody has opened yet. Mirrors payroll_calculations.php.
$ddvReviewConvo = [];
$ddvUnreadMsgs  = 0;
if (in_array($batchStatus, [2, 3], true)) {
    // seen_at ships in 2026_07_review_seen.sql — degrade to "all read" without it.
    $ddvHasSeen = (bool)($conn->query("SHOW COLUMNS FROM dtr_employee_reviews LIKE 'seen_at'")->num_rows ?? 0);
    $rvq = $conn->query("SELECT r.id, r.employee_id, r.status, r.comment, r.reviewed_at,
            r.resolved_at, r.admin_reply, " . ($ddvHasSeen ? "r.seen_at" : "NULL AS seen_at") . ",
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
        if ($unread) $ddvUnreadMsgs++;
        $ddvReviewConvo[(int)$rv['employee_id']] = [
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
}
$reviewPending = max(0, $reviewTotalEmp - $reviewConfirmed - $reviewDisputed);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DTR Documents &mdash; <?= htmlspecialchars($dtr['site_code']) ?> <?= date('M d', strtotime($dtr['date_from'])) ?>&ndash;<?= date('M d, Y', strtotime($dtr['date_to'])) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <!-- This page renders its own <head> rather than includes/header.php, so the
         CSRF token has to be published here too — its $.ajax calls hit the
         review/note actions on ajax.php, which now require the token. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets2/js/csrf.js"></script>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/icons.min.css" rel="stylesheet">
    <!-- Global DTR (Form 48) template — shared with the employee portal -->
    <link href="<?= av('assets2/css/dtr-form48.css') ?>" rel="stylesheet">
    <script src="<?= av('assets2/js/dtr-form48.js') ?>"></script>
    <!-- Shared employee quick-view drawer (data-emp-view="<id>" opens it) -->
    <script src="<?= av('assets2/js/employee-view.js') ?>" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root { --brand:#6642aa; --brand-dark:#4e3483; --line:#e1dfdd; --sb-thumb:#cfc4e6; --sb-track:transparent; }
html, body { height:100%; }
body { margin:0; background:#f0eff2; font-family:'Segoe UI',system-ui,Arial,sans-serif; overflow:hidden; }
/* ── Soft purple scrollbars, everywhere on this page (standalone — no theme.css) ── */
* { scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
*::-webkit-scrollbar { width:9px; height:9px; }
*::-webkit-scrollbar-track, *::-webkit-scrollbar-corner { background:transparent; }
*::-webkit-scrollbar-thumb { background:var(--sb-thumb); border-radius:8px; border:2px solid transparent; background-clip:content-box; }
*::-webkit-scrollbar-thumb:hover { background:#b7a7d9; background-clip:content-box; }
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
    color:var(--brand-dark); background:#f2f0f7; border:1px solid #ddd9e7; text-decoration:none;
}
.ddv-back-btn:hover { background:#e5e1ef; color:var(--brand-dark); }
.ddv-title-icon {
    width:38px; height:38px; border-radius:11px; flex-shrink:0;
    background:linear-gradient(135deg,#6642aa,#7654b8); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:18px;
    box-shadow:0 3px 8px rgba(102,66,170,.30);
}
.ddv-h-title { font-size:15px; font-weight:800; color:#363242; line-height:1.1; display:flex; align-items:center; gap:8px; }
.ddv-meta-chips { display:flex; flex-wrap:wrap; gap:5px; margin-top:4px; }
.ddv-meta-chip {
    display:inline-flex; align-items:center; gap:4px;
    font-size:10.5px; font-weight:600; color:var(--brand-dark);
    background:#f2f0f7; border:1px solid #ddd9e7; border-radius:20px; padding:2px 9px;
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
    color:var(--brand-dark); background:#f2f0f7; border:1px solid #ddd9e7; transition:background .12s;
    text-decoration:none;
}
.ddv-btn:hover:not(:disabled) { background:#e5e1ef; border-color:#c0b5d5; color:var(--brand-dark); }
.ddv-btn:disabled { opacity:.45; cursor:not-allowed; }
.ddv-btn.primary { background:#e1dcec; border-color:#c0b5d5; color:#4f3288; }
.ddv-btn.primary:hover:not(:disabled) { background:#d2cae2; }
.ddv-btn.warn { background:#fff8e1; border-color:#ffe082; color:#c98a00; }
.ddv-btn.warn:hover:not(:disabled) { background:#fdefc3; }
.ddv-pend-pill { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; border-radius:12px; padding:0 7px; font-size:10px; font-weight:800; }
.ddv-exc-pill {
    position:relative; display:inline-flex; align-items:center; gap:4px; cursor:help;
    font-size:11px; font-weight:800; padding:5px 11px; border-radius:20px;
    background:#fdecea; color:#c62828; border:1px solid #f5c6cb;
}
/* Instant styled tooltip (native title is flaky in the sticky header) */
.ddv-exc-pill[data-tip]:hover::after {
    content:attr(data-tip);
    position:absolute; top:calc(100% + 8px); right:0; z-index:60;
    width:250px; max-width:70vw; white-space:normal; text-align:left;
    background:#342f42; color:#fff; padding:8px 11px; border-radius:8px;
    font-size:11px; font-weight:600; line-height:1.4; letter-spacing:.2px;
    box-shadow:0 6px 18px rgba(0,0,0,.28); pointer-events:none;
}
.ddv-exc-pill[data-tip]:hover::before {
    content:''; position:absolute; top:calc(100% + 3px); right:14px; z-index:60;
    border:5px solid transparent; border-bottom-color:#342f42; pointer-events:none;
}
/* Reusable instant tooltip for header buttons (native title is flaky here) */
.ddv-tip { position:relative; }
.ddv-tip[data-tip]:hover::after {
    content:attr(data-tip);
    position:absolute; top:calc(100% + 8px); right:0; z-index:60;
    width:250px; max-width:70vw; white-space:normal; text-align:left;
    background:#342f42; color:#fff; padding:8px 11px; border-radius:8px;
    font-size:11px; font-weight:600; line-height:1.4; letter-spacing:.2px;
    box-shadow:0 6px 18px rgba(0,0,0,.28); pointer-events:none;
}
.ddv-tip[data-tip]:hover::before {
    content:''; position:absolute; top:calc(100% + 3px); right:14px; z-index:60;
    border:5px solid transparent; border-bottom-color:#342f42; pointer-events:none;
}

/* ── Workspace ── */
.ddv-wrap {
    flex:1; min-height:0;
    display:grid; grid-template-columns:300px minmax(0,1fr) 330px; gap:13px;
    padding:13px 16px;
}
/* Small screens: the right panel becomes a slide-in drawer so no action is lost */
.ddv-drawer-btn {
    display:none; position:fixed; right:14px; bottom:16px; z-index:44;
    align-items:center; gap:6px; padding:10px 16px; border-radius:24px; cursor:pointer;
    font-size:12.5px; font-weight:800; color:#fff; background:var(--brand);
    border:1px solid var(--brand-dark); box-shadow:0 4px 14px rgba(102,66,170,.4);
}
@media (max-width:1150px) {
    .ddv-wrap { grid-template-columns:260px minmax(0,1fr); }
    .ddv-drawer-btn { display:inline-flex; }
    .ddv-right {
        position:fixed; top:0; right:0; bottom:0; z-index:45;
        width:min(360px, 92vw); padding:14px; background:#f0eff2;
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
    padding:9px 13px; border-bottom:1px solid #eef2f0; background:#f8f7fb;
    font-size:12px; font-weight:800; color:var(--brand-dark);
}
.ddv-panel-head i { color:var(--brand); }

/* ── Left: previews ── */
.ddv-left { min-height:0; }
.ddv-search { flex-shrink:0; padding:9px 11px 5px; position:relative; }
.ddv-filter-btn {
    position:relative; display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; flex-shrink:0; border-radius:7px; cursor:pointer;
    color:var(--brand-dark); background:#f2f0f7; border:1px solid #ddd9e7; transition:background .12s;
}
.ddv-filter-btn:hover { background:#e5e1ef; }
.ddv-filter-btn.on { background:#e1dcec; border-color:#c0b5d5; }
.ddv-filter-count {
    position:absolute; top:-5px; right:-5px; min-width:14px; height:14px; padding:0 3px;
    border-radius:8px; background:#c62828; color:#fff; font-size:8.5px; font-weight:800;
    display:flex; align-items:center; justify-content:center;
}
.ddv-filter-pop {
    display:none; position:absolute; left:11px; right:11px; top:calc(100% + 4px); z-index:40;
    background:#fff; border:1px solid var(--line); border-radius:12px; padding:11px;
    box-shadow:0 10px 30px rgba(58,40,93,.18);
}
.ddv-filter-pop.open { display:block; }
.ddv-fp-head { display:flex; justify-content:space-between; align-items:center; font-size:11.5px; font-weight:800; color:var(--brand-dark); }
.ddv-fp-head i { color:var(--brand); }
.ddv-fp-reset {
    display:inline-flex; align-items:center; gap:3px; border:none; background:transparent;
    color:#c62828; font-size:10px; font-weight:700; cursor:pointer; padding:2px 5px; border-radius:6px;
}
.ddv-fp-reset:hover { background:#fdf4f3; }
.ddv-fp-lbl { font-size:9px; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:#948ea5; margin:9px 0 3px; }
.ddv-fp-select {
    width:100%; margin-top:4px; border:1px solid #ddd9e7; border-radius:8px;
    font-size:11.5px; padding:5px 8px; color:#3c3846; background:#fff; outline:none;
}
.ddv-fp-select:focus { border-color:var(--brand); box-shadow:0 0 0 2px rgba(102,66,170,.15); }
.ddv-search-wrap { display:flex; align-items:center; gap:7px; border:1px solid #ddd9e7; border-radius:8px; background:#fff; padding:6px 10px; }
.ddv-search-wrap:focus-within { border-color:var(--brand); box-shadow:0 0 0 2px rgba(102,66,170,.15); }
.ddv-search-wrap i { color:var(--brand); font-size:14px; }
.ddv-search-wrap input { border:none; outline:none; flex:1; font-size:12px; min-width:0; background:transparent; }
.ddv-filter-seg {
    display:flex; gap:2px; padding:2px;
    background:#f0eff4; border:1px solid #ddd9e7; border-radius:9px;
}
.ddv-filter-seg button {
    flex:1; display:inline-flex; align-items:center; justify-content:center; gap:3px;
    border:none; background:transparent; border-radius:7px; cursor:pointer;
    font-size:10px; font-weight:700; color:#635f73; padding:4px 2px; transition:background .12s, color .12s;
}
.ddv-filter-seg button:hover:not(.on) { background:#e5e3ec; }
.ddv-filter-seg button.on { background:#fff; color:#4f3288; box-shadow:0 1px 3px rgba(58,40,93,.18); }
.ddv-filter-seg .fdot { width:6px; height:6px; border-radius:50%; }
.ddv-filter-seg .fdot.ok { background:#0f9d58; } .ddv-filter-seg .fdot.pend { background:#f7b84b; } .ddv-filter-seg .fdot.disa { background:#c62828; }
.ddv-flag-chips { display:flex; flex-wrap:wrap; gap:4px; }
.ddv-flag-chips button {
    display:inline-flex; align-items:center; gap:3px; padding:3px 8px; border-radius:20px;
    font-size:9.5px; font-weight:700; cursor:pointer; color:#a04545;
    background:#fff; border:1px dashed #f0c7c3; transition:all .12s;
}
.ddv-flag-chips button i { font-size:11px; }
.ddv-flag-chips button:hover:not(.on) { background:#fdf4f3; }
.ddv-flag-chips button.on {
    background:#fdecea; border-style:solid; border-color:#e8a9a3; color:#c62828;
    box-shadow:0 0 0 1px #f5c6cb inset;
}
.ddv-act-chips { display:flex; flex-wrap:wrap; gap:4px; }
.ddv-act-chips button {
    display:inline-flex; align-items:center; gap:3px; padding:3px 8px; border-radius:20px;
    font-size:9.5px; font-weight:700; cursor:pointer; color:var(--brand-dark);
    background:#fff; border:1px dashed #d7d3e3; transition:all .12s;
}
.ddv-act-chips button i { font-size:11px; }
.ddv-act-chips button:hover:not(.on) { background:#f6f4fa; }
.ddv-act-chips button.on {
    background:#eeeaf5; border-style:solid; border-color:#c0b5d5; color:#4f3288;
    box-shadow:0 0 0 1px #c0b5d5 inset;
}
.ddv-list { flex:1; overflow-y:auto; padding:5px 9px 9px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
.ddv-item {
    display:flex; align-items:center; gap:9px; width:100%;
    padding:6px 8px; margin-bottom:4px; text-align:left;
    background:#fff; border:1px solid #e8eeeb; border-radius:9px; cursor:pointer;
    transition:background .12s, border-color .12s;
}
.ddv-item:hover { background:#f8f6fb; border-color:#d7d0e5; }
.ddv-item.active { background:#eeeaf5; border-color:var(--brand); box-shadow:0 0 0 1px var(--brand) inset; }
.ddv-thumb {
    width:30px; height:39px; flex-shrink:0; border-radius:3px;
    background:#fffdf7; border:1px solid #d9d5c9; box-shadow:1px 1px 0 #e8e4d8;
    display:flex; flex-direction:column; align-items:center; padding-top:4px; gap:2px;
}
.ddv-thumb::before { content:''; width:70%; height:2px; background:#3a3a3a; border-radius:1px; }
.ddv-thumb span { display:block; width:78%; height:1.5px; background:#c9c4b4; }
/* Status tint: the paper itself hints the employee's approval state */
.ddv-thumb.ok        { background:#f0faf4; border-color:#c4e8d1; box-shadow:1px 1px 0 #ddf0e4; }
.ddv-thumb.ok::before   { background:#0f9d58; }
.ddv-thumb.ok span      { background:#b5dcc3; }
.ddv-thumb.pend      { background:#fffbef; border-color:#f0dfa8; box-shadow:1px 1px 0 #f5eccf; }
.ddv-thumb.pend::before { background:#c98a00; }
.ddv-thumb.pend span    { background:#e6d49a; }
.ddv-thumb.disa      { background:#fdf4f3; border-color:#f0c7c3; box-shadow:1px 1px 0 #f6dcd9; }
.ddv-thumb.disa::before { background:#c62828; }
.ddv-thumb.disa span    { background:#e8b8b3; }
.ddv-item-name { font-size:11px; font-weight:700; color:#3c3846; line-height:1.2; word-break:break-word; }
.ddv-item-sub  { font-size:9.5px; color:#948ea5; margin-top:1px; display:block; }
.ddv-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-left:auto; }
.ddv-dot.ok { background:#0f9d58; } .ddv-dot.pend { background:#f7b84b; } .ddv-dot.disa { background:#c62828; }
.ddv-list-empty { text-align:center; color:#948ea5; font-size:12px; padding:26px 8px; }
.ddv-pager { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:6px; padding:7px 11px; border-top:1px solid #eef2f0; background:#fbfbfd; }
.ddv-pg-btn {
    width:27px; height:27px; display:inline-flex; align-items:center; justify-content:center;
    border:1px solid #ddd9e7; background:#fff; color:var(--brand-dark); border-radius:7px; font-size:15px; cursor:pointer;
}
.ddv-pg-btn:hover:not(:disabled) { background:#f2f0f7; }
.ddv-pg-btn:disabled { opacity:.35; cursor:not-allowed; }
.ddv-pg-info { font-size:10.5px; color:#827d91; font-weight:600; }

/* ── Center: paper ── */
.ddv-center { min-width:0; min-height:0; display:flex; flex-direction:column; }
.ddv-doc-toolbar { flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; max-width:780px; margin:0 auto 9px; width:100%; }
.ddv-doc-nav { display:flex; align-items:center; gap:6px; }
.ddv-doc-pos { font-size:11px; color:#827d91; font-weight:600; }
.ddv-paper-scroll { flex:1; overflow:auto; min-height:0; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) transparent; padding-bottom:10px; }
/* grooveless bar over the paper backdrop — thumb only (see theme.css .sb-ghost) */
.ddv-paper-scroll::-webkit-scrollbar-track,
.ddv-paper-scroll::-webkit-scrollbar-corner { background:transparent; }
.ddv-paper-scroll::-webkit-scrollbar-thumb { border-color:transparent; }
/* block + margin:auto (not flex centering) so a zoomed-in sheet that overflows
   horizontally stays fully reachable by scrolling */
.ddv-paper-holder { display:block; }
.ddv-paper {
    background:#fffefb; width:100%; max-width:780px; margin:0 auto;
    border:1px solid #dcd8cc; border-radius:2px;
    box-shadow:0 2px 14px rgba(60,55,40,.14);
    padding:28px 32px 24px; font-family:'Times New Roman', Times, serif; color:#1a1a1a;
}
/* Form 48 paper styles now come from the shared global template:
   assets2/css/dtr-form48.css (rendered by window.DTRForm48.render).
   Only the .ddv-paper page frame stays here. */
.ddv-doc-empty { text-align:center; color:#948ea5; font-size:13px; padding:60px 10px; }

/* Zoom: --dtr-zoom is set inline on #ddv-paper by setZoom() */
#ddv-paper { zoom: var(--dtr-zoom, 1); }
.ddv-doc-zoom { display:flex; align-items:center; gap:5px; }
.ddv-doc-legend { display:flex; align-items:center; gap:9px; flex-wrap:wrap; font-size:10px; font-weight:600; color:#827d91; }
.ddv-doc-legend > span { display:inline-flex; align-items:center; gap:3px; }
.ddv-zoom-val {
    min-width:48px; height:27px; padding:0 7px; border-radius:7px; cursor:pointer;
    border:1px solid #ddd9e7; background:#fff; color:var(--brand-dark);
    font-size:11px; font-weight:700;
}
.ddv-zoom-val:hover { background:#f2f0f7; }

/* ── Right: one docs-style panel with sliding tabs (Summary / Records / …) ── */
.ddv-right { min-height:0; display:flex; flex-direction:column; }
.ddv-right > .ddv-panel { flex:1; min-height:0; }

/* Tab bar — Google-Docs-like: quiet gray labels, active gets the brand ink bar */
.ddv-rtabs {
    position:relative; flex-shrink:0; display:flex; align-items:stretch; gap:2px;
    padding:0 6px; background:#fff; border-bottom:1px solid #eceaf1;
}
.ddv-rtabs button[data-tab] {
    flex:1; min-width:0; display:inline-flex; align-items:center; justify-content:center; gap:5px;
    border:none; background:transparent; cursor:pointer; padding:11px 4px 10px;
    font-size:11px; font-weight:700; color:#8a8599; white-space:nowrap;
    border-radius:8px 8px 0 0; transition:color .15s, background .15s;
}
.ddv-rtabs button[data-tab] i { font-size:14px; }
.ddv-rtabs button[data-tab]:hover { color:var(--brand-dark); background:#faf9fc; }
.ddv-rtabs button[data-tab].on { color:var(--brand-dark); }
.ddv-rtab-ink {
    position:absolute; bottom:-1px; left:0; width:0; height:2.5px; border-radius:2px;
    background:var(--brand); transition:left .25s cubic-bezier(.4,0,.2,1), width .25s cubic-bezier(.4,0,.2,1);
}
.ddv-rtab-n {
    min-width:16px; height:15px; padding:0 4px; border-radius:8px;
    display:inline-flex; align-items:center; justify-content:center;
    background:#eeeaf5; color:#4f3288; font-size:9px; font-weight:800;
}
.ddv-rtab-n:empty { display:none; }
.ddv-rtab-dot { width:7px; height:7px; border-radius:50%; background:#3557b7; flex-shrink:0; }

/* Views slide horizontally as one track; each view is the scroller */
.ddv-rtab-views { flex:1; min-height:0; overflow:hidden; }
.ddv-rtab-track { display:flex; height:100%; transition:transform .3s cubic-bezier(.4,0,.2,1); }
.ddv-rtab-view {
    flex:0 0 100%; min-width:0; height:100%; overflow-y:auto; overflow-x:hidden;
    scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track);
}
.ddv-sum-body { padding:13px 15px; }
/* The name doubles as the trigger for the shared employee quick-view drawer */
.ddv-sum-emp {
    display:inline-flex; align-items:center; gap:5px; padding:0; text-align:left;
    border:none; background:transparent; cursor:pointer;
    font-size:12.5px; font-weight:800; color:#3c3846; font-family:inherit;
}
.ddv-sum-emp i { color:#b3a8ca; font-size:14px; transition:color .12s; }
.ddv-sum-emp:hover { color:var(--brand-dark); text-decoration:underline; }
.ddv-sum-emp:hover i { color:var(--brand); }
.ddv-sum-sub { font-size:10.5px; color:#948ea5; margin:1px 0 9px; }
.ddv-sum-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:7px; }
.ddv-sum-tile { border:1px solid #eceaf1; border-radius:10px; padding:8px 4px; background:#fff; text-align:center; }
.ddv-sum-tile .v { font-size:14.5px; font-weight:800; color:var(--brand); line-height:1.1; }
.ddv-sum-tile .l { font-size:8.5px; color:#948ea5; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.ddv-sum-tile.ot .v { color:#c98a00; } .ddv-sum-tile.ut .v { color:#1565c0; } .ddv-sum-tile.late .v { color:#c62828; }
.ddv-chips { display:flex; flex-wrap:wrap; gap:4px; margin-top:8px; }
.ddv-chip { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px; }
.ddv-chip.appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.ddv-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-chip.disa { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-attend-bar { margin-top:9px; }
.ddv-attend-lbl { display:flex; justify-content:space-between; font-size:10px; color:#827d91; font-weight:600; margin-bottom:3px; }
.ddv-bar { height:7px; border-radius:6px; background:#ecebf1; overflow:hidden; }
.ddv-bar > div { height:100%; border-radius:6px; background:linear-gradient(90deg,#6642aa,#967ec5); transition:width .3s; }
.ddv-emp-approve-all { width:100%; justify-content:center; margin-top:9px; }

/* Internal admin notes (admin-only, per employee) */
.ddv-notes { margin-top:11px; border-top:1px dashed #e7e6ed; padding-top:9px; }
.ddv-notes-hd { display:flex; align-items:center; gap:5px; font-size:10.5px; font-weight:800; color:#635f73; margin-bottom:6px; }
.ddv-notes-hd .lock { font-size:9px; font-weight:800; color:#948ea5; background:#eef2f0; border-radius:10px; padding:1px 6px; }
.ddv-note { display:flex; gap:7px; align-items:flex-start; padding:6px 8px; border-radius:8px; margin-bottom:5px; border:1px solid; }
.ddv-note .nt { flex:1; min-width:0; font-size:11px; font-weight:600; color:#434050; line-height:1.35; word-break:break-word; }
.ddv-note .nm { font-size:8.5px; color:#9d9baa; margin-top:2px; font-weight:600; }
.ddv-note .nx { border:none; background:transparent; color:#bbb9c3; cursor:pointer; font-size:13px; line-height:1; padding:0; flex-shrink:0; }
.ddv-note .nx:hover { color:#c62828; }
.ddv-note.info     { background:#eef4fd; border-color:#c9def7; } .ddv-note.info .lv     { color:#1565c0; }
.ddv-note.good     { background:#eefaf2; border-color:#bfe6cd; } .ddv-note.good .lv     { color:#0f9d58; }
.ddv-note.watch    { background:#fff8e6; border-color:#f2e0a6; } .ddv-note.watch .lv    { color:#c98a00; }
.ddv-note.critical { background:#fdecec; border-color:#f3c9c9; } .ddv-note.critical .lv { color:#c62828; }
.ddv-note .lv { font-size:14px; flex-shrink:0; line-height:1.2; }
.ddv-note-empty { font-size:10px; color:#a9a6b4; padding:2px 2px 6px; }
.ddv-note-add { display:flex; flex-direction:column; gap:6px; margin-top:2px; }
.ddv-lvpick { display:flex; gap:4px; }
.ddv-lvpick button {
    flex:1; min-width:0; display:inline-flex; align-items:center; justify-content:center; gap:3px;
    border:1px solid #e0dfe7; background:#fff; border-radius:7px; cursor:pointer;
    font-size:9.5px; font-weight:700; color:#827d91; padding:4px 2px; transition:all .12s;
    white-space:nowrap; overflow:hidden;
}
.ddv-lvpick button.on { color:#fff; }
.ddv-lvpick button[data-lv="info"].on     { background:#1565c0; border-color:#1565c0; }
.ddv-lvpick button[data-lv="good"].on     { background:#0f9d58; border-color:#0f9d58; }
.ddv-lvpick button[data-lv="watch"].on    { background:#c98a00; border-color:#c98a00; }
.ddv-lvpick button[data-lv="critical"].on { background:#c62828; border-color:#c62828; }
.ddv-note-in { display:flex; gap:5px; }
.ddv-note-in input { flex:1; min-width:0; border:1px solid #ddd9e7; border-radius:7px; font-size:11px; padding:5px 8px; outline:none; }
.ddv-note-in input:focus { border-color:var(--brand); box-shadow:0 0 0 2px rgba(102,66,170,.14); }
.ddv-note-in button { width:30px; flex-shrink:0; border:none; border-radius:7px; background:var(--brand); color:#fff; cursor:pointer; }
.ddv-note-in button:hover { background:var(--brand-dark); }
.ddv-note-tpls { display:flex; flex-wrap:wrap; gap:4px; }
.ddv-note-tpl { border:1px dashed #ddd9e7; background:#f9f9fb; color:#635f73; border-radius:12px; font-size:9px; font-weight:600; padding:2px 8px; cursor:pointer; }
.ddv-note-tpl:hover { background:#f2f0f7; border-style:solid; }

/* Records list (the tab view scrolls, not this box) */
.ddv-recs { padding:10px 12px; }
.ddv-rec {
    border:1px solid #eceaf1; border-radius:10px; padding:8px 10px; margin-bottom:7px; background:#fff;
}
.ddv-rec.is-appr { background:#f2fbf6; border-color:#cdeeda; }
.ddv-rec.is-disa { background:#fdf4f4; border-color:#f3d3d3; }
.ddv-rec-top { display:flex; align-items:center; justify-content:space-between; gap:6px; }
.ddv-rec-date { font-size:11px; font-weight:800; color:#3c3846; }
.ddv-rec-badge { font-size:9px; font-weight:800; padding:1px 8px; border-radius:10px; }
.ddv-rec-badge.b-appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.ddv-rec-badge.b-pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-rec-badge.b-disa { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-rec-logs { display:flex; flex-wrap:wrap; gap:3px; margin-top:5px; }
.ddv-log-chip { display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:3px; font-size:9.5px; font-weight:600; }
.ddv-log-chip.bio    { background:#eeeaf5; color:var(--brand); border:1px solid #c0b5d5; }
.ddv-log-chip.manual { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.ddv-log-chip i { font-size:10px; }
.ddv-rec-stats { display:flex; gap:8px; margin-top:5px; font-size:9.5px; color:#827d91; font-weight:600; }
.ddv-rec-stats b { color:var(--brand); font-size:10.5px; }
.ddv-rec-stats .ot b { color:#c98a00; } .ddv-rec-stats .ut b { color:#1565c0; } .ddv-rec-stats .late b { color:#c62828; }
.ddv-rec-flags { display:flex; flex-wrap:wrap; gap:3px; margin-top:5px; }
.ddv-flag { display:inline-flex; align-items:center; gap:3px; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:800; }
.ddv-flag.block { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.ddv-flag.info  { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
/* Day carries BOTH worked hours and a leave request — payroll caps the two at
   one day, so the reviewer should see it before approving. Blue, matching the
   leave marker (.dm-lv) on the Form 48 sheet. */
.ddv-flag.leave { background:#e3f2fd; color:#1565c0; border:1px solid #a8cff5; }

/* ── Cross-employee bulk selection ── */
.ddv-bulk {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    margin:0 9px 6px; padding:7px 9px; border-radius:8px;
    background:var(--app-primary-soft, #f3effa); border:1px solid var(--app-primary-border, #ddd2f0);
}
.ddv-bulk-txt  { font-size:10.5px; font-weight:700; color:#4e2b8e; }
.ddv-bulk-txt b { font-size:12px; }
.ddv-bulk-acts { margin-left:auto; display:flex; gap:4px; }
/* The employee card is itself a <button>, so the tick is a role="checkbox"
   span (same trick the chat button uses) — a nested <input> would be invalid. */
.ddv-item-chk {
    flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
    width:18px; height:18px; border-radius:4px; cursor:pointer;
    color:#b3adc2; font-size:15px; line-height:1;
}
.ddv-item-chk:hover { color:var(--app-primary, #673bb6); }
.ddv-item-chk.on    { color:var(--app-primary, #673bb6); }
.ddv-item.is-picked { background:var(--app-primary-soft, #f3effa); }
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
.ddv-mini-btn.msg  { background:#fff8e1; color:#c98a00; border-color:#ffe082; }
.ddv-mini-btn.msg.has { box-shadow:0 0 0 1px #ffe082 inset; }

/* Per-record admin ↔ employee conversation — a single floating popover so it
   never occupies layout space in the record card */
.ddv-chat-pop {
    display:none; position:fixed; z-index:60; width:300px; max-width:calc(100vw - 24px);
    background:#fff; border:1px solid var(--line); border-radius:12px;
    box-shadow:0 12px 34px rgba(58,40,93,.24); overflow:hidden;
}
.ddv-chat-pop.open { display:flex; flex-direction:column; }
.ddv-chat-pop-head {
    display:flex; align-items:center; justify-content:space-between; gap:8px;
    padding:8px 11px; border-bottom:1px solid #eef2f0; background:#f8f7fb;
    font-size:11.5px; font-weight:800; color:var(--brand-dark);
}
.ddv-chat-pop-head i { color:var(--brand); }
.ddv-chat-pop-head button { border:none; background:transparent; color:#827d91; cursor:pointer; font-size:16px; line-height:1; padding:0; }
.ddv-chat-pop-head button:hover { color:var(--brand-dark); }
.ddv-chat-pop-head #ddv-chat-refresh.spin i { animation:ddv-spin .7s linear infinite; display:inline-block; }
.ddv-chat-list { display:flex; flex-direction:column; gap:5px; max-height:230px; overflow-y:auto; padding:9px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
.ddv-bub { max-width:85%; padding:5px 9px; border-radius:10px; font-size:11px; line-height:1.35; word-break:break-word; }
.ddv-bub.me   { align-self:flex-end; background:#e1dcec; color:#4f3288; border-bottom-right-radius:3px; }
.ddv-bub.them { align-self:flex-start; background:#f1f3f2; color:#3c3846; border-bottom-left-radius:3px; }
.ddv-bub .m { font-size:8.5px; opacity:.7; margin-top:2px; }
.ddv-chat-empty { font-size:10.5px; color:#948ea5; text-align:center; padding:14px 4px; }
.ddv-chat-in { display:flex; gap:5px; padding:7px; border-top:1px solid #eef2f0; }
.ddv-chat-in input { flex:1; min-width:0; border:1px solid #ddd9e7; border-radius:7px; font-size:11.5px; padding:6px 9px; outline:none; }
.ddv-chat-in input:focus { border-color:var(--brand); box-shadow:0 0 0 2px rgba(102,66,170,.15); }
.ddv-chat-in button { width:32px; flex-shrink:0; border:none; border-radius:7px; background:var(--brand); color:#fff; cursor:pointer; }
.ddv-chat-in button:hover { background:var(--brand-dark); }
.ddv-mini-btn:hover:not(:disabled) { filter:brightness(.96); }
.ddv-mini-btn:disabled { opacity:.4; cursor:not-allowed; }
/* ── Employee Review Progress (ported from dtr-details.php) ── */
.drp-body { padding:12px 15px; }
.drp-counts { display:flex; gap:5px; flex-wrap:wrap; }
.drp-chip { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:12px; }
.drp-chip.appr { background:#eafaf0; color:#0f9d58; border:1px solid #b7e4c7; }
.drp-chip.disp { background:#fdecea; color:#c62828; border:1px solid #f5c6cb; }
.drp-chip.pend { background:#fff8e1; color:#c98a00; border:1px solid #ffe082; }
.drp-act-btn { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:12px; border:1px solid; cursor:pointer; text-decoration:none; }
.drp-act-btn.remind { background:#fff8e1; color:#c98a00; border-color:#ffe082; }
.drp-act-btn.export { background:#eef2fb; color:#394b7c; border-color:#c3c9e0; }
.drp-act-btn.resolve { margin-top:5px; background:#e9f7ef; color:#0f9d58; border-color:#b7e4c7; }
.drp-act-btn:hover { filter:brightness(.97); }
.drp-disputes { margin-top:9px; display:flex; flex-direction:column; gap:3px; }
.drp-disputes.is-scroll { max-height:210px; overflow-y:auto; padding-right:4px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
/* ── Name-only sign-off rows (mirrors payroll_calculations.php) ────────────
   Icon, name, and a chat button when the employee left a message; the message
   itself opens in #modal-emp-review. UNREAD until someone opens it. */
.drp-chip.unread { background:#eef2fd; color:#3557b7; border:1px solid #ccd9f7; }
.drp-note-pill { display:inline-flex; align-items:center; gap:3px; background:#fff6e2; border:1px solid #f2dfae;
    color:#a9700a; border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.drp-confirms { margin-top:6px; display:flex; flex-direction:column; gap:3px; }
.drp-confirms.is-scroll { max-height:190px; overflow-y:auto; padding-right:4px; scrollbar-width:thin; scrollbar-color:var(--sb-thumb) var(--sb-track); }
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
.drp-row.has-msg:focus-visible { outline:2px solid var(--brand); outline-offset:1px; }

/* ── The sign-off conversation popup (plain overlay — no Bootstrap JS here) ── */
.ddv-rvm { display:none; position:fixed; inset:0; z-index:1200; background:rgba(43,35,63,.45);
    align-items:center; justify-content:center; padding:20px; }
.ddv-rvm.open { display:flex; }
.ddv-rvm-card { width:min(520px, 100%); background:#fff; border-radius:12px; overflow:hidden;
    box-shadow:0 18px 50px rgba(46,35,85,.3); display:flex; flex-direction:column; max-height:90vh; }
.ddv-rvm-head { display:flex; align-items:center; justify-content:space-between; gap:8px;
    padding:10px 14px; background:#0f9d58; color:#fff; font-size:12.5px; font-weight:800; }
.ddv-rvm-head i { color:#fff; }
.ddv-rvm-head button { border:none; background:transparent; color:#fff; cursor:pointer; font-size:17px; line-height:1; padding:0; }
.ddv-rvm-body { padding:14px; background:#f4f3f7; overflow-y:auto; }
.ddv-rvm-foot { display:flex; justify-content:flex-end; gap:6px; padding:9px 14px; border-top:1px solid #eef2f0; background:#fff; }
#modal-emp-review .mer-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
#modal-emp-review .mer-name { font-size:14px; font-weight:800; color:#3c3846; }
.mer-badge { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:800;
    padding:2px 9px; border-radius:12px; border:1px solid; }
.mer-badge.ok   { background:#eafaf0; color:#0f9d58; border-color:#b7e4c7; }
.mer-badge.disp { background:#fdecea; color:#c62828; border-color:#f5c6cb; }
.mer-when { font-size:10.5px; color:#948ea5; margin-left:auto; }
.mer-empty { text-align:center; color:#948ea5; font-size:12.5px; padding:22px 12px; }
.mer-chat { display:flex; flex-direction:column; gap:6px; }
.drp-names { margin-top:9px; }
.drp-names > summary { list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; color:#0f9d58; user-select:none; }
.drp-names > summary::-webkit-details-marker { display:none; }
.drp-names > summary:hover .drp-names-hint { text-decoration:underline; }
.drp-count-pill { background:#e9f7ef; border:1px solid #b7e4c7; color:#0f9d58; border-radius:10px; padding:0 6px; font-size:10px; font-weight:700; }
.drp-names-hint { font-weight:500; color:#8a8a8a; }
.drp-names .lbl-hide, .drp-names[open] .lbl-show { display:none; }
.drp-names[open] .lbl-hide { display:inline; }
.drp-empty { font-size:11px; color:#948ea5; padding:2px; }
/* Chat button on an employee card — they left a message with their sign-off */
.ddv-item-msg { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
    width:20px; height:20px; border-radius:6px; cursor:pointer; font-size:11px;
    background:#eafaf0; border:1px solid #b7e4c7; color:#0f9d58; position:relative; }
.ddv-item-msg.disp { background:#fdecea; border-color:#f5c6cb; color:#c62828; }
.ddv-item-msg:hover { background:#0f9d58; border-color:#0f9d58; color:#fff; }
.ddv-item-msg.disp:hover { background:#c62828; border-color:#c62828; color:#fff; }
.ddv-item-msg.new::after { content:''; position:absolute; top:-3px; right:-3px; width:7px; height:7px;
    border-radius:50%; background:#3557b7; border:1.5px solid #fff; }

.ddv-batch-row { display:flex; justify-content:space-between; align-items:center; padding:8px 15px; font-size:11px; color:#635f73; border-bottom:1px solid #f2f0f6; }
.ddv-batch-row:last-child { border-bottom:none; }
.ddv-batch-row b { color:var(--brand-dark); font-size:11.5px; }

/* Loader */
.ddv-loader { display:none; align-items:center; justify-content:center; gap:10px; padding:40px 0; color:var(--brand-dark); font-size:12px; font-weight:700; }
.ddv-loader.show { display:flex; }
.ddv-ring { width:24px; height:24px; border-radius:50%; border:3px solid #e1dcec; border-top-color:var(--brand); animation:ddv-spin .8s linear infinite; }
@keyframes ddv-spin { to { transform:rotate(360deg); } }

/* Edit dialog inputs */
.ddv-edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:9px; text-align:left; }
.ddv-edit-grid label { font-size:11px; font-weight:700; color:#635f73; display:block; margin-bottom:2px; }
.ddv-edit-grid input { width:100%; border:1px solid #ddd9e7; border-radius:7px; padding:6px 8px; font-size:13px; }

/* Print: only the paper sheet — or, in print-all mode, every sheet */
#ddv-print-all { display:none; }
@media print {
    body { overflow:visible; }
    body * { visibility:hidden; }
    body:not(.print-all) #ddv-paper, body:not(.print-all) #ddv-paper * { visibility:visible; }
    body:not(.print-all) #ddv-paper { position:absolute; left:0; top:0; width:100%; max-width:none; border:none; box-shadow:none; padding:10px 24px; zoom:1 !important; }
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
                    data-tip="Pending records with something unusual (no time-out, zero hours, or high OT). Bulk approval skips these — decide them per employee.">
                    <i class="ri-error-warning-line"></i> <span id="hdr-exc-n"><?= $excPending ?></span> exception<?= $excPending === 1 ? '' : 's' ?>
                </span>
                <button class="ddv-btn warn ddv-tip" id="btn-approve-all-batch" onclick="approveAllBatch()" style="<?= $cleanPending > 0 ? '' : 'display:none;' ?>"
                    data-tip="Approve every pending record that has no exception flags. Flagged records stay pending.">
                    <i class="ri-checkbox-multiple-line"></i> Approve Clean
                    <span class="ddv-pend-pill" id="hdr-clean"><?= $cleanPending ?></span>
                </button>
                <?php if ($batchStatus === 1): ?>
                    <button class="ddv-btn primary ddv-tip" id="btn-send-review" onclick="sendForReview()" <?= $pendingRecs > 0 ? 'disabled' : '' ?>
                        data-tip="Decide all records first, then send to employees for review.">
                        <i class="ri-user-received-2-line"></i> Send for Review
                    </button>
                <?php elseif ($batchStatus === 3): ?>
                    <button class="ddv-btn primary ddv-tip" onclick="finalApprove()" data-tip="Final approve this batch for payroll processing.">
                        <i class="ri-checkbox-circle-line"></i> Final Approve
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canEdit && $batchStatus !== 2): ?>
                <button class="ddv-btn ddv-tip" onclick="recomputeBatch()"
                    data-tip="Re-derive every record's hours / late / undertime / OT from its raw logs using current schedules and the holiday calendar.">
                    <i class="ri-refresh-line"></i> Recompute
                </button>
            <?php endif; ?>
            <button class="ddv-btn ddv-tip" id="ddv-print" onclick="window.print()" data-tip="Print the selected employee's DTR sheet."><i class="ri-printer-line"></i> Print</button>
            <button class="ddv-btn ddv-tip" id="ddv-print-all-btn" onclick="printAll()" data-tip="Print every employee's DTR sheet in this batch."><i class="ri-printer-cloud-line"></i> Print All</button>
        </div>
    </div>

    <!-- ── Workspace ── -->
    <div class="ddv-wrap">

        <!-- LEFT -->
        <div class="ddv-panel ddv-left">
            <div class="ddv-panel-head"><span><i class="ri-team-line"></i> Employees</span><span id="ddv-total" style="font-weight:600;color:#827d91;font-size:10.5px;"></span></div>
            <div class="ddv-search">
                <div class="ddv-search-wrap">
                    <i class="ri-search-2-line"></i>
                    <input id="ddv-q" type="text" placeholder="Search name, no., position...">
                    <button type="button" class="ddv-filter-btn" id="ddv-filter-btn" onclick="toggleFilterPop()" title="Filters">
                        <i class="ri-filter-3-line"></i>
                        <span class="ddv-filter-count" id="ddv-filter-count" style="display:none;">0</span>
                    </button>
                </div>
                <div class="ddv-filter-pop" id="ddv-filter-pop">
                    <div class="ddv-fp-head">
                        <span><i class="ri-filter-3-line"></i> Filters</span>
                        <button type="button" class="ddv-fp-reset" onclick="resetFilters()"><i class="ri-restart-line"></i> Reset all</button>
                    </div>
                    <div class="ddv-fp-lbl">Approval status</div>
                    <div class="ddv-filter-seg" id="ddv-status-seg">
                        <button type="button" data-st="" class="on">All</button>
                        <button type="button" data-st="pending"><span class="fdot pend"></span>Pending</button>
                        <button type="button" data-st="approved"><span class="fdot ok"></span>Approved</button>
                        <button type="button" data-st="disapproved"><span class="fdot disa"></span>Rejected</button>
                    </div>
                    <div class="ddv-fp-lbl">Exceptions</div>
                    <div class="ddv-flag-chips" id="ddv-flag-chips" title="Click again to clear">
                        <button type="button" data-fl="any"><i class="ri-error-warning-line"></i> Flagged</button>
                        <button type="button" data-fl="no_out"><i class="ri-logout-box-r-line"></i> No time-out</button>
                        <button type="button" data-fl="zero_hours"><i class="ri-time-line"></i> Zero hrs</button>
                        <button type="button" data-fl="high_ot"><i class="ri-sun-line"></i> High OT</button>
                        <button type="button" data-fl="manual"><i class="ri-edit-line"></i> Manual</button>
                        <button type="button" data-fl="low_att"><i class="ri-calendar-close-line"></i> Low attend.</button>
                    </div>
                    <div class="ddv-fp-lbl">Activity</div>
                    <div class="ddv-act-chips" id="ddv-act-chips" title="Click again to clear">
                        <button type="button" data-ac="notes"><i class="ri-sticky-note-line"></i> Has notes</button>
                        <button type="button" data-ac="msgs"><i class="ri-chat-3-line"></i> Has messages</button>
                    </div>
                    <?php if (in_array($batchStatus, [2, 3], true)): ?>
                    <?php /* The employees' own sign-off — pull up every disputed DTR,
                             or everyone still silent, in one click. */ ?>
                    <div class="ddv-fp-lbl">Employee review</div>
                    <div class="ddv-act-chips" id="ddv-erv-chips" title="Click again to clear">
                        <button type="button" data-erv="2"><i class="ri-error-warning-fill" style="color:#c62828;"></i> Disputed</button>
                        <button type="button" data-erv="1"><i class="ri-checkbox-circle-fill" style="color:#0f9d58;"></i> Confirmed</button>
                        <button type="button" data-erv="0"><i class="ri-time-line" style="color:#c98a00;"></i> Awaiting</button>
                        <button type="button" data-erv="m"><i class="ri-chat-3-fill" style="color:#a9700a;"></i> With message</button>
                    </div>
                    <?php endif; ?>
                    <div class="ddv-fp-lbl">Employee</div>
                    <select id="ddv-f-dep" class="ddv-fp-select" onchange="filterSel('dep', this.value)"><option value="">Department: All</option></select>
                    <select id="ddv-f-pos" class="ddv-fp-select" onchange="filterSel('pos', this.value)"><option value="">Position: All</option></select>
                    <select id="ddv-f-sch" class="ddv-fp-select" onchange="filterSel('sch', this.value)"><option value="">Schedule: All</option></select>
                    <div class="ddv-fp-lbl">Has</div>
                    <div class="ddv-flag-chips" id="ddv-has-chips" title="Click again to clear">
                        <button type="button" data-has="ot"><i class="ri-sun-line"></i> Overtime</button>
                        <button type="button" data-has="leave"><i class="ri-calendar-check-line"></i> Leave</button>
                        <button type="button" data-has="late"><i class="ri-alarm-warning-line"></i> Late</button>
                        <button type="button" data-has="ut"><i class="ri-logout-circle-line"></i> Undertime</button>
                        <button type="button" data-has="nsd"><i class="ri-moon-line"></i> Night diff</button>
                        <button type="button" data-has="hol"><i class="ri-flag-line"></i> Holiday duty</button>
                        <button type="button" data-has="req"><i class="ri-file-list-3-line"></i> Request</button>
                        <button type="button" data-has="lvclash" title="Worked hours AND an approved leave on the same day"><i class="ri-error-warning-line"></i> Leave clash</button>
                    </div>
                </div>
            </div>
            <!-- Cross-employee bulk bar: shown only once employees are ticked, so
                 it never takes room from the list when there is nothing to do. -->
            <div class="ddv-bulk" id="ddv-bulk" style="display:none;">
                <span class="ddv-bulk-txt">
                    <b id="ddv-bulk-emps">0</b> employee/s ·
                    <b id="ddv-bulk-recs">0</b> pending record/s
                </span>
                <span class="ddv-bulk-acts">
                    <button type="button" class="ddv-mini-btn ok" onclick="approvePicked(1)" title="Approve every pending record of the ticked employees"><i class="ri-check-double-line"></i> Approve</button>
                    <button type="button" class="ddv-mini-btn no" onclick="approvePicked(2)" title="Disapprove every pending record of the ticked employees"><i class="ri-close-line"></i> Reject</button>
                    <button type="button" class="ddv-mini-btn" onclick="clearPicked()" title="Clear selection"><i class="ri-eraser-line"></i></button>
                </span>
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
                <div class="ddv-doc-legend dtrf48-legend" title="Day markers on the sheet — hover a marker for details">
                    <span><span class="dm dm-hol">H</span> Holiday</span>
                    <span><span class="dm dm-lv">L</span> Leave</span>
                    <span><span class="dm dm-off">D</span> Day off</span>
                    <span><span class="dm dm-req">R</span> Request</span>
                    <span><span class="dm dm-sch dm-sch-day"><i class="ri-calendar-2-line"></i></span> Day shift</span>
                    <span><span class="dm dm-sch dm-sch-eve"><i class="ri-calendar-2-line"></i></span> Afternoon</span>
                    <span><span class="dm dm-sch dm-sch-noc"><i class="ri-calendar-2-line"></i></span> Night</span>
                </div>
                <div class="ddv-doc-zoom">
                    <button class="ddv-pg-btn" id="ddv-zoom-out" title="Zoom out (Ctrl+scroll or −)"><i class="ri-zoom-out-line"></i></button>
                    <button type="button" class="ddv-zoom-val" id="ddv-zoom-val" title="Reset zoom (0)">100%</button>
                    <button class="ddv-pg-btn" id="ddv-zoom-in" title="Zoom in (Ctrl+scroll or +)"><i class="ri-zoom-in-line"></i></button>
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

        <!-- RIGHT: one docs-style panel — sliding tabs instead of stacked boxes -->
        <div class="ddv-right">
            <div class="ddv-panel">
                <div class="ddv-rtabs" id="ddv-rtabs">
                    <button type="button" data-tab="summary" class="on"><i class="ri-user-3-line"></i> Summary</button>
                    <button type="button" data-tab="records"><i class="ri-fingerprint-line"></i> Records <span class="ddv-rtab-n" id="ddv-rec-count"></span></button>
                    <?php if (in_array($batchStatus, [2, 3], true)): ?>
                    <button type="button" data-tab="review"><i class="ri-user-received-2-line"></i> Review<?php if ($ddvUnreadMsgs > 0): ?> <span class="ddv-rtab-dot" id="ddv-rtab-review-dot" title="Unread employee messages"></span><?php endif; ?></button>
                    <?php endif; ?>
                    <button type="button" data-tab="batch"><i class="ri-stack-line"></i> Batch</button>
                    <button type="button" class="ddv-pg-btn" onclick="toggleDrawer(false)" style="display:none;align-self:center;margin-left:4px;" id="ddv-drawer-close" title="Close"><i class="ri-close-line"></i></button>
                    <span class="ddv-rtab-ink"></span>
                </div>
                <div class="ddv-rtab-views">
                    <div class="ddv-rtab-track" id="ddv-rtab-track">
                <section class="ddv-rtab-view" data-view="summary">
                <div class="ddv-sum-body" id="ddv-emp-summary">
                    <div style="font-size:12px;color:#948ea5;">No employee selected.</div>
                </div>
                </section>
                <section class="ddv-rtab-view" data-view="records">
                <div class="ddv-recs" id="ddv-recs">
                    <div style="font-size:11.5px;color:#948ea5;padding:8px;">No employee selected.</div>
                </div>
                </section>
            <?php if (in_array($batchStatus, [2, 3], true)): ?>
                <section class="ddv-rtab-view" data-view="review">
                <div class="drp-body">
                    <div class="drp-counts">
                        <?php if ($batchStatus === 3): ?><span class="ddv-status-badge st-rev">In progress</span><?php else: ?><span class="ddv-status-badge st-appr">Approved</span><?php endif; ?>
                        <span class="drp-chip appr"><i class="ri-checkbox-circle-line"></i> <?= $reviewConfirmed ?> Confirmed</span>
                        <span class="drp-chip disp"><i class="ri-error-warning-line"></i> <?= $reviewDisputed ?> Disputed</span>
                        <span class="drp-chip pend"><i class="ri-time-line"></i> <?= $reviewPending ?> Awaiting</span>
                        <?php if ($ddvUnreadMsgs > 0): ?>
                        <span class="drp-chip unread" id="drp-unread-chip" title="Employee messages nobody has opened yet">
                            <i class="ri-mail-unread-line"></i> <span id="drp-unread-n"><?= $ddvUnreadMsgs ?></span> unread
                        </span>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <?php if ($batchStatus === 3 && $reviewPending > 0): ?>
                            <button type="button" class="drp-act-btn remind" onclick="remindDtrReview(<?= $id ?>)"><i class="ri-notification-badge-line"></i> Remind (<?= $reviewPending ?>)</button>
                            <?php endif; ?>
                            <a class="drp-act-btn export" href="ajax.php?action=export_dtr_reviews&id=<?= $id ?>"><i class="ri-download-2-line"></i> Export</a>
                        <?php endif; ?>
                    </div>
                    <?php
                    // One line per employee — icon, name, and a chat button when they
                    // left a message. The message opens in #modal-emp-review so a long
                    // comment can never stretch this panel (mirrors the payroll page).
                    $drpRow = function ($rv, $kind) {
                        $hasMsg = trim((string)$rv['comment']) !== '';
                        $unread = $hasMsg && empty($rv['seen_at']);
                        $eid    = (int)$rv['employee_id'];
                        ?>
                        <div class="drp-row <?= $kind ?><?= $hasMsg ? ' has-msg' : '' ?><?= $unread ? ' unread' : '' ?>"
                             data-emp="<?= $eid ?>"
                             <?= $hasMsg ? 'role="button" tabindex="0" onclick="ddvOpenReviewConvo(' . $eid . ')" title="View this employee\'s message"' : '' ?>>
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
                            <span style="color:#0f9d58;font-weight:700;"><i class="ri-checkbox-circle-line"></i> Confirmed by</span>
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
                    <?php if ($reviewConfirmed === 0 && $reviewDisputed === 0): ?>
                    <div class="drp-empty">No employees have reviewed yet.</div>
                    <?php endif; ?>
                </div>
                </section>
            <?php endif; ?>
                <section class="ddv-rtab-view" data-view="batch">
                <div class="ddv-batch-rows" id="ddv-batch">
                    <div class="ddv-batch-row"><span>Employees</span><b data-b="employees"><?= (int)($agg['employees'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Total work hours</span><b data-b="work_hours"><?= number_format((float)($agg['work_hours'] ?? 0), 2) ?></b></div>
                    <div class="ddv-batch-row"><span>Approved</span><b data-b="approved" style="color:#0f9d58;"><?= (int)($agg['approved'] ?? 0) ?> / <?= (int)($agg['total'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Pending</span><b data-b="pending" style="color:#c98a00;"><?= (int)($agg['pending'] ?? 0) ?></b></div>
                    <div class="ddv-batch-row"><span>Disapproved</span><b data-b="disapproved" style="color:#c62828;"><?= (int)($agg['disapproved'] ?? 0) ?></b></div>
                </div>
                </section>
                    </div>
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

<!-- Floating conversation popover (single, reused for every record) -->
<div class="ddv-chat-pop" id="ddv-chat-pop">
    <div class="ddv-chat-pop-head">
        <span id="ddv-chat-title"><i class="ri-chat-3-line"></i> Conversation</span>
        <span style="display:flex;gap:4px;">
            <button type="button" id="ddv-chat-refresh" onclick="refreshChat()" title="Refresh — check for new replies"><i class="ri-refresh-line"></i></button>
            <button type="button" onclick="closeChat()" title="Close"><i class="ri-close-line"></i></button>
        </span>
    </div>
    <div class="ddv-chat-list" id="ddv-chat-list"></div>
    <div class="ddv-chat-in" id="ddv-chat-in">
        <input type="text" id="ddv-chat-input" maxlength="500" placeholder="Message the employee…"
            onkeydown="if(event.key==='Enter'){event.preventDefault();sendChat();}">
        <button type="button" onclick="sendChat()" title="Send — the employee gets a portal notification"><i class="ri-send-plane-2-line"></i></button>
    </div>
</div>

<!-- DTR sign-off conversation — what the employee wrote when they confirmed or
     disputed this batch, plus HR's reply. Opened from the Employee Review panel.
     A hand-rolled overlay, not a Bootstrap modal: this page loads Bootstrap's
     CSS but not its JS, so bootstrap.Modal does not exist here. -->
<div class="ddv-rvm" id="modal-emp-review" role="dialog" aria-modal="true" aria-label="DTR review message">
    <div class="ddv-rvm-card">
        <div class="ddv-rvm-head">
            <span><i class="ri-user-received-2-line"></i> DTR Review Message</span>
            <button type="button" onclick="ddvCloseReviewConvo()" title="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="ddv-rvm-body" id="mer-body"></div>
        <div class="ddv-rvm-foot">
            <button type="button" class="ddv-btn primary" id="mer-reply" style="display:none;">
                <i class="ri-chat-check-line"></i> Resolve &amp; Reply
            </button>
            <button type="button" class="ddv-btn" onclick="ddvCloseReviewConvo()">Close</button>
        </div>
    </div>
</div>

<script>
// employee_id → their DTR sign-off (decision, message, HR reply, unread flag)
window.DDV_REVIEWS = <?= json_encode($ddvReviewConvo, JSON_UNESCAPED_UNICODE) ?>;
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

// picked = employee ids ticked for a cross-employee bulk decision. Scoped to
// the loaded page (st.emps); loadPage clears it so a page change can never act
// on employees you can no longer see.
const st = { page: 0, size: 20, q: '', flag: '', status: '', dep: '', pos: '', sch: '', has: '', act: '', erv: '', total: 0, emps: [], sel: -1, seq: 0, chatRec: null, picked: new Set() };

const $id = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const toast = msg => Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: msg, timer: 1600, showConfirmButton: false });

// ── Data ────────────────────────────────────────────────────────────────────
async function loadPage(keepSel) {
    const seq = ++st.seq;
    $id('ddv-list').innerHTML = '<div class="ddv-loader show"><span class="ddv-ring"></span> Loading...</div>';
    try {
        const u = `dtr-employee-server.php?action=docs&id=${DDTR_ID}&offset=${st.page * st.size}&limit=${st.size}&q=${encodeURIComponent(st.q)}`
            + (st.flag === 'any' ? '&flagged=1' : (st.flag ? '&flag=' + st.flag : ''))
            + (st.status ? '&status=' + st.status : '')
            + (st.dep ? '&dep=' + st.dep : '')
            + (st.pos ? '&pos=' + st.pos : '')
            + (st.sch ? '&sch=' + st.sch : '')
            + (st.has ? '&has=' + st.has : '')
            + (st.act ? '&act=' + st.act : '')
            + (st.erv !== '' ? '&erv=' + st.erv : '');
        const r = await fetch(u);
        const j = await r.json();
        if (seq !== st.seq) return;
        if (!j.result) throw new Error(j.message || 'Failed');
        st.total = j.total;
        st.emps  = j.employees || [];
        st.sel   = st.emps.length ? Math.min(keepSel ?? 0, st.emps.length - 1) : -1;
        renderList(); renderPager(); renderSelected(); syncBulkBar();
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
        if (e.notes && e.notes.length) {
            const NC = { info:'#1565c0', good:'#0f9d58', watch:'#c98a00', critical:'#c62828' };
            const order = ['critical','watch','info','good'];
            const top = order.find(l => e.notes.some(n => n.level === l)) || 'info';
            marks.push(`<i class="ri-sticky-note-fill" style="color:${NC[top]};font-size:13px;flex-shrink:0;" title="${e.notes.length} internal note(s)"></i>`);
        }
        // Chat button for employees who left a message with their DTR sign-off —
        // a span, not a button, since the card itself is already a <button>.
        const cv = (window.DDV_REVIEWS || {})[e.id];
        if (cv && cv.c) {
            marks.push(`<span role="button" tabindex="0" class="ddv-item-msg${cv.st === 2 ? ' disp' : ''}${cv.new ? ' new' : ''}"
                data-convo="${e.id}" title="${cv.new ? 'UNREAD — ' : ''}${cv.st === 2 ? 'Disputed' : 'Confirmed'} their DTR with a message — click to read"><i class="ri-chat-3-fill"></i></span>`);
        }
        // Tick only offered where there is something to decide and the user may
        // decide it — an employee with no pending records can't be bulk-approved.
        const pickable = CAN_EDIT && e.pend > 0;
        const picked   = st.picked.has(e.id);
        const chk = pickable
            ? `<span role="checkbox" aria-checked="${picked}" tabindex="0" class="ddv-item-chk${picked ? ' on' : ''}"
                     data-pick="${e.id}" title="Select for bulk approve/reject (${e.pend} pending)"><i class="${picked ? 'ri-checkbox-fill' : 'ri-checkbox-blank-line'}"></i></span>`
            : '<span class="ddv-item-chk" style="visibility:hidden;"></span>';
        return `<button type="button" class="ddv-item ${i === st.sel ? 'active' : ''}${picked ? ' is-picked' : ''}" data-i="${i}">
            ${chk}
            <span class="ddv-thumb ${dot}"><span></span><span></span><span></span><span></span></span>
            <span style="min-width:0;">
                <span class="ddv-item-name">${esc(e.lastname)}, ${esc(e.firstname)}</span>
                <span class="ddv-item-sub">${esc(e.no)}${e.position ? ' · ' + esc(e.position) : ''}</span>
            </span>
            ${marks.join('')}<span class="ddv-dot ${dot}" style="${marks.length ? 'margin-left:4px;' : ''}"></span>
        </button>`;
    }).join('');
}
$id('ddv-list').addEventListener('click', ev => {
    // Tick toggles bulk selection without selecting/opening the card.
    const pk = ev.target.closest('[data-pick]');
    if (pk) {
        ev.stopPropagation();
        togglePick(parseInt(pk.dataset.pick, 10));
        return;
    }
    // Chat button opens the sign-off message without selecting the card.
    const cv = ev.target.closest('[data-convo]');
    if (cv) {
        ev.stopPropagation();
        ddvOpenReviewConvo(parseInt(cv.dataset.convo, 10));
        return;
    }
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
    if (typeof closeChat === 'function') closeChat();   // don't leave a chat popover open across employees
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
    // Delegate to the shared global Form 48 template so the admin sheet and the
    // employee portal render identically (now with Work Hrs / OT / Late columns
    // and a full totals row).
    return window.DTRForm48.render({
        name: `${e.lastname}, ${e.firstname} ${e.middlename || ''}`.trim(),
        periodLabel: fmtPeriod(),
        dateFrom: DATE_FROM,
        dateTo: DATE_TO,
        logMode: LOG_MODE,
        days: e.days,
        totals: e.totals,
        marks: e.marks,
    });
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
    if (!e) { box.innerHTML = '<div style="font-size:12px;color:#948ea5;">No employee selected.</div>'; return; }
    const daysPresent = Object.keys(e.days).length;
    let periodDays = 0; eachDay(() => periodDays++);
    const pct = periodDays ? Math.round(daysPresent / periodDays * 100) : 0;
    box.innerHTML = `
        <button type="button" class="ddv-sum-emp" data-emp-view="${e.id}" title="View employee details">${esc(e.lastname)}, ${esc(e.firstname)} ${esc(e.middlename || '')} <i class="ri-id-card-line"></i></button>
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
            <i class="ri-checkbox-circle-line"></i> Approve All Pending (${e.pend})</button>` : ''}
        ${notesHTML(e)}`;
}

// ── Internal admin notes (admin-only, per employee) ──────────────────────────
const NOTE_LV = {
    info:     { icon: '🔵', lbl: 'Info' },
    good:     { icon: '🟢', lbl: 'Good' },
    watch:    { icon: '🟠', lbl: 'Watch' },
    critical: { icon: '🔴', lbl: 'Critical' },
};
// Recommended quick-notes per level (admin picks then tweaks).
const NOTE_TEMPLATES = [
    'Attendance improved this period',
    'Frequent no time-out — remind employee',
    'Repeated tardiness — monitor',
    'Pending incident report to resolve',
    'Verified with supervisor',
];
let noteLevel = 'info';

function notesHTML(e) {
    const notes = e.notes || [];
    const list = notes.length
        ? notes.map(n => {
            const m = NOTE_LV[n.level] || NOTE_LV.info;
            return `<div class="ddv-note ${esc(n.level)}">
                <span class="lv">${m.icon}</span>
                <span class="nt">${esc(n.note)}<span class="nm">${esc(n.by || 'Admin')}${n.at ? ' · ' + esc(n.at) : ''}</span></span>
                ${CAN_EDIT && n.id ? `<button class="nx" title="Delete note" data-note-id="${n.id}" onclick="deleteNote(this)"><i class="ri-close-line"></i></button>` : ''}
            </div>`;
        }).join('')
        : '<div class="ddv-note-empty">No notes yet.</div>';

    const adder = CAN_EDIT ? `
        <div class="ddv-note-add">
            <div class="ddv-lvpick" id="ddv-lvpick">
                ${Object.keys(NOTE_LV).map(k => `<button type="button" data-lv="${k}" class="${k === noteLevel ? 'on' : ''}" onclick="pickNoteLevel('${k}')">${NOTE_LV[k].icon} ${NOTE_LV[k].lbl}</button>`).join('')}
            </div>
            <div class="ddv-note-in">
                <input type="text" id="ddv-note-input" maxlength="500" placeholder="Add an internal note…"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();addNote();}">
                <button type="button" onclick="addNote()" title="Add note"><i class="ri-add-line"></i></button>
            </div>
            <div class="ddv-note-tpls">
                ${NOTE_TEMPLATES.map(t => `<span class="ddv-note-tpl" onclick="fillNote(this)">${esc(t)}</span>`).join('')}
            </div>
        </div>` : '';

    return `<div class="ddv-notes">
        <div class="ddv-notes-hd"><i class="ri-sticky-note-line"></i> Internal Notes <span class="lock"><i class="ri-lock-2-line"></i> admin only</span></div>
        ${list}${adder}
    </div>`;
}

function pickNoteLevel(lv) {
    noteLevel = lv;
    document.querySelectorAll('#ddv-lvpick button').forEach(b => b.classList.toggle('on', b.dataset.lv === lv));
}
function fillNote(el) {
    const inp = $id('ddv-note-input');
    if (inp) { inp.value = el.textContent; inp.focus(); }
}
function addNote() {
    const e = st.emps[st.sel];
    const inp = $id('ddv-note-input');
    const note = (inp?.value || '').trim();
    if (!e || !note) return;
    inp.disabled = true;
    $.ajax({
        url: 'ajax.php?action=save_dtr_note', method: 'POST', dataType: 'JSON',
        data: { ddtr_id: DDTR_ID, employee_id: e.id, level: noteLevel, note: note },
        success: r => {
            if (!(r && r.result)) { inp.disabled = false; return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' }); }
            e.notes = e.notes || [];
            e.notes.push({ id: r.id, level: noteLevel, note: note, by: r.by || ME, at: r.at || '' });
            renderList();          // show the note indicator in the left list
            renderSummary(e);
            toast('Note added');
        },
        error: () => { inp.disabled = false; Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }); },
    });
}
function deleteNote(arg) {
    // Accept either the button element (data-note-id) or a raw id.
    const id = parseInt(typeof arg === 'object' && arg ? arg.dataset.noteId : arg, 10);
    const e = st.emps[st.sel];
    if (!e || !id) { if (!id) Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not identify the note. Reload and try again.' }); return; }
    $.ajax({
        url: 'ajax.php?action=delete_dtr_note', method: 'POST', dataType: 'JSON',
        data: { id: id },
        success: r => {
            if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
            e.notes = (e.notes || []).filter(n => n.id !== id);
            renderList();          // refresh the left-list note indicator
            renderSummary(e);
            toast('Note deleted');
        },
        error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
    });
}

// ── Right: records & logs ────────────────────────────────────────────────────
function renderRecords(e) {
    const box = $id('ddv-recs');
    if (!e) { box.innerHTML = '<div style="font-size:11.5px;color:#948ea5;padding:8px;">No employee selected.</div>'; $id('ddv-rec-count').textContent = ''; return; }
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
            // Leave-vs-attendance conflict: this date carries a leave request AND
            // real worked hours. Legitimate (half-day, or leave taken mid-shift),
            // but payroll caps worked + leave at one day — so show it here, where
            // the record is actually approved, not just as an "L" on the sheet.
            const lv = ((e.marks || {})[date] || []).find(m => m.k === 'leave');
            const lvFlag = (lv && Number(r.wh) > 0)
                ? `<span class="ddv-flag leave" title="${esc(String(lv.lbl || 'Leave').toUpperCase())}${lv.half ? ' · half day' : ''} — this day also has ${Number(r.wh).toFixed(2)} worked hours; payroll pays worked + leave capped at one day"><i class="ri-calendar-check-line"></i>${lv.s === 1 ? 'Also on leave' : 'Leave pending'}</span>`
                : '';
            const note = (r.status === 2 && r.note)
                ? `<div class="ddv-rec-note"><i class="ri-chat-1-line"></i> ${esc(r.note)}</div>` : '';
            const audit = (r.status !== 0 && r.by)
                ? `<div style="margin-top:4px;font-size:9px;color:#9d9baa;"><i class="ri-user-follow-line"></i> ${r.status === 1 ? 'Approved' : 'Disapproved'} by ${esc(r.by)}${r.at ? ' · ' + esc(r.at) : ''}</div>` : '';
            // Conversation thread lives in a single floating popover (openChat),
            // so it never adds height to the record card.
            const msgs = r.msgs || [];
            const msgBtn = `<button class="ddv-mini-btn msg ${msgs.length ? 'has' : ''}" data-rec="${r.id}" onclick="openChat(${r.id}, this)" title="Conversation with the employee about this date">
                        <i class="ri-chat-3-line"></i>${msgs.length ? ' ' + msgs.length : ''}
                    </button>`;
            html += `<div class="ddv-rec ${cls}" id="rec-${r.id}">
                <div class="ddv-rec-top"><span class="ddv-rec-date"><i class="ri-calendar-event-line" style="color:#6642aa;"></i> ${dLbl}</span>${badge}</div>
                <div class="ddv-rec-logs">${logs}</div>
                ${(flags || lvFlag) ? `<div class="ddv-rec-flags">${flags}${lvFlag}</div>` : ''}
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
                    <button class="ddv-mini-btn edit" onclick="editRec(${r.id})" title="Edit hours"><i class="ri-pencil-line"></i></button>
                    ${msgBtn}
                    <button class="ddv-mini-btn del"  onclick="deleteRec(${r.id})" title="Delete record"><i class="ri-delete-bin-6-line"></i></button>
                </div>` : (msgs.length ? `<div class="ddv-rec-actions">${msgBtn}</div>` : '')}
            </div>`;
        });
    });
    $id('ddv-rec-count').textContent = n || '';
    positionInk();   // the count pill changes the Records tab's width
    box.innerHTML = html || '<div style="font-size:11.5px;color:#948ea5;padding:8px;">No records.</div>';
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
function rerenderAll() { renderList(); renderSelected(); refreshBatch(); syncBulkBar(); }

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

// ── Cross-employee bulk approve / reject ─────────────────────────────────────
// Works on the PENDING records of every ticked employee. The server already
// accepts an explicit id list (decide_dtr_details), so this only has to gather
// the ids — the decision, the required reject reason and the audit stamp all
// go through the same decideRecs path a single record uses.
function togglePick(empId) {
    if (st.picked.has(empId)) st.picked.delete(empId); else st.picked.add(empId);
    renderList();
    syncBulkBar();
}

function clearPicked() {
    st.picked.clear();
    renderList();
    syncBulkBar();
}

// The tick is a role="checkbox" span, so Space/Enter has to be wired by hand.
$id('ddv-list').addEventListener('keydown', ev => {
    const pk = ev.target.closest && ev.target.closest('[data-pick]');
    if (!pk || (ev.key !== ' ' && ev.key !== 'Enter')) return;
    ev.preventDefault();
    ev.stopPropagation();
    togglePick(parseInt(pk.dataset.pick, 10));
});

// Still-undecided record ids for one employee.
function pendingRecIds(e) {
    const ids = [];
    Object.values(e.days || {}).forEach(d => (d.recs || []).forEach(r => {
        if (r.status !== 1 && r.status !== 2) ids.push(r.id);
    }));
    return ids;
}

// Pending record ids across the ticked employees, in list order.
function pickedRecIds() {
    return st.emps.filter(e => st.picked.has(e.id)).reduce((a, e) => a.concat(pendingRecIds(e)), []);
}

// Self-cleaning: forgets employees that left the page or ran out of pending
// records, so the bar can never offer an action with nothing behind it (and a
// finished bulk approve dismisses itself).
function syncBulkBar() {
    const bar = $id('ddv-bulk');
    if (!bar) return;
    Array.from(st.picked).forEach(id => {
        const e = st.emps.find(x => x.id === id);
        if (!e || !pendingRecIds(e).length) st.picked.delete(id);
    });
    const n = st.picked.size;
    bar.style.display = n ? 'flex' : 'none';
    if (!n) return;
    $id('ddv-bulk-emps').textContent = n;
    $id('ddv-bulk-recs').textContent = pickedRecIds().length;
}

function approvePicked(decision) {
    const ids = pickedRecIds();
    if (!ids.length) {
        return Swal.fire({ icon: 'info', title: 'Nothing pending',
            text: 'The selected employee(s) have no pending records left.' });
    }
    const names = st.emps.filter(e => st.picked.has(e.id))
        .map(e => `${e.lastname}, ${e.firstname}`);
    const who = names.length <= 3 ? names.join('; ') : `${names.slice(0, 3).join('; ')} and ${names.length - 3} more`;
    const verb = decision === 1 ? 'approved' : 'disapproved';
    decideRecs(ids, decision,
        `${ids.length} pending record(s) across ${names.length} employee(s) will be ${verb}: ${who}.`);
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

function recomputeBatch() {
    Swal.fire({
        title: 'Recompute this batch?',
        html: 'Every record\'s hours, late, undertime, OT and night-diff will be <b>re-derived from its raw logs</b> ' +
              'using current schedules and the holiday calendar.<br><br>' +
              '<b>Approved records whose figures change go back to Pending</b> for re-approval; ' +
              'unchanged records are left untouched.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#6642aa', confirmButtonText: 'Yes, recompute',
    }).then(res => {
        if (!res.isConfirmed) return;
        Swal.fire({ title: 'Recomputing…', text: 'Re-deriving figures from raw logs', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: 'ajax.php?action=recompute_dtr', method: 'POST', dataType: 'JSON',
            data: { id: DDTR_ID },
            success: async r => {
                if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
                await loadPage(st.sel);
                await refreshBatch();
                Swal.fire({
                    icon: 'success', title: 'Recomputed',
                    html: `${r.scanned} record(s) scanned — <b>${r.changed}</b> updated, <b>${r.repending}</b> sent back to Pending.`,
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
        confirmButtonColor: '#6642aa', confirmButtonText: 'Yes, send',
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
        showCancelButton: true, confirmButtonColor: '#6642aa', confirmButtonText: 'Save changes',
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

// ── Per-record conversation — a single floating popover (no layout space) ────
function renderChatBubbles() {
    const hit = st.chatRec != null ? findRec(st.chatRec) : null;
    const list = $id('ddv-chat-list');
    const msgs = hit ? (hit.r.msgs || []) : [];
    list.innerHTML = msgs.length
        ? msgs.map(m => `<div class="ddv-bub ${m.from === 'emp' ? 'them' : 'me'}">
                <div>${esc(m.msg)}</div>
                <div class="m">${esc(m.from === 'emp' ? 'Employee' : (m.by || 'Support'))}${m.at ? ' · ' + esc(m.at) : ''}</div>
            </div>`).join('')
        : '<div class="ddv-chat-empty">No messages yet — ask the employee about this day.</div>';
    list.scrollTop = list.scrollHeight;
}

function positionChat(btn) {
    const pop = $id('ddv-chat-pop');
    const b = btn.getBoundingClientRect();
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    // Prefer opening to the LEFT of the button (records panel sits at the right
    // edge); fall back to the right, then clamp inside the viewport.
    let left = b.left - pw - 8;
    if (left < 8) left = b.right + 8;
    if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
    let top = b.top;
    if (top + ph > window.innerHeight - 8) top = window.innerHeight - ph - 8;
    if (top < 8) top = 8;
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
}

function openChat(recId, btn) {
    if (st.chatRec === recId && $id('ddv-chat-pop').classList.contains('open')) { closeChat(); return; }
    const hit = findRec(recId);
    if (!hit) return;
    st.chatRec = recId;
    const dLbl = new Date(hit.date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    $id('ddv-chat-title').innerHTML = `<i class="ri-chat-3-line"></i> ${esc(hit.e.lastname)}, ${esc(hit.e.firstname)} · ${dLbl}`;
    $id('ddv-chat-in').style.display = CAN_EDIT ? '' : 'none';
    renderChatBubbles();
    const pop = $id('ddv-chat-pop');
    pop.classList.add('open');
    positionChat(btn);                      // measure after it's displayed
    if (CAN_EDIT) { const i = $id('ddv-chat-input'); i.value = ''; i.focus(); }
}

function closeChat() {
    $id('ddv-chat-pop').classList.remove('open');
    st.chatRec = null;
}

// Keep the popover glued to its record's chat button while the list scrolls;
// hide it only once that button scrolls out of the records panel.
function followChat() {
    const pop = $id('ddv-chat-pop');
    if (!pop.classList.contains('open') || st.chatRec == null) return;
    const btn = document.querySelector('.ddv-mini-btn.msg[data-rec="' + st.chatRec + '"]');
    const scroller = document.querySelector('.ddv-recs');
    if (!btn || !scroller) { closeChat(); return; }
    const b = btn.getBoundingClientRect(), s = scroller.getBoundingClientRect();
    // Off the visible records area → hide (the popover would point at nothing).
    if (b.bottom < s.top || b.top > s.bottom) { pop.style.display = 'none'; return; }
    pop.style.display = 'flex';
    positionChat(btn);
}

// Dismiss on outside click / Escape
document.addEventListener('mousedown', ev => {
    const pop = $id('ddv-chat-pop');
    if (!pop.classList.contains('open')) return;
    if (ev.target.closest('#ddv-chat-pop') || ev.target.closest('.ddv-mini-btn.msg')) return;
    closeChat();
});
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeChat(); });
document.addEventListener('scroll', followChat, true);
window.addEventListener('resize', followChat);

// Pull the latest thread for the open record (employee may have replied)
function refreshChat() {
    if (st.chatRec == null) return;
    const hit = findRec(st.chatRec);
    if (!hit) return;
    const icon = $id('ddv-chat-refresh');
    icon.classList.add('spin');
    fetch(`dtr-employee-server.php?action=rec_msgs&id=${DDTR_ID}&rec=${st.chatRec}`)
        .then(r => r.json())
        .then(j => {
            if (j && j.result) {
                hit.r.msgs = j.msgs || [];
                renderChatBubbles();
                renderRecords(st.emps[st.sel]);   // update the badge count
            }
        })
        .finally(() => icon.classList.remove('spin'));
}

function sendChat() {
    const inp = $id('ddv-chat-input');
    const msg = (inp.value || '').trim();
    if (!msg || st.chatRec == null) return;
    const hit = findRec(st.chatRec);
    if (!hit) return;
    inp.disabled = true;
    $.ajax({
        url: 'ajax.php?action=message_dtr_record', method: 'POST', dataType: 'JSON',
        data: { id: st.chatRec, message: msg },
        success: r => {
            inp.disabled = false;
            if (!(r && r.result)) return Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
            hit.r.msgs = hit.r.msgs || [];
            hit.r.msgs.push({ from: 'admin', msg: msg, by: r.by || ME, at: r.at || '' });
            inp.value = '';
            renderRecords(st.emps[st.sel]); // refresh the record's message-count badge
            renderChatBubbles();            // popover is a separate fixed node — survives the rerender
            inp.focus();
        },
        error: () => { inp.disabled = false; Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }); },
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

// ── Filter popover ───────────────────────────────────────────────────────────
function toggleFilterPop() {
    $id('ddv-filter-pop').classList.toggle('open');
}
document.addEventListener('click', ev => {
    const pop = $id('ddv-filter-pop');
    if (!pop.classList.contains('open')) return;
    if (ev.target.closest('#ddv-filter-pop') || ev.target.closest('#ddv-filter-btn')) return;
    pop.classList.remove('open');
});

// Active-filter badge on the funnel icon
function updateFilterCount() {
    const n = [st.status, st.flag, st.dep, st.pos, st.sch, st.has, st.act].filter(Boolean).length + (st.erv !== '' ? 1 : 0);
    const b = $id('ddv-filter-count');
    b.style.display = n ? 'flex' : 'none';
    b.textContent = n;
    $id('ddv-filter-btn').classList.toggle('on', n > 0);
}

// Dropdown filters (department / position / shift)
function filterSel(key, val) {
    st[key] = val;
    st.page = 0;
    loadPage();
    updateFilterCount();
}

function resetFilters() {
    st.status = st.flag = st.dep = st.pos = st.sch = st.has = st.act = st.erv = '';
    document.querySelectorAll('#ddv-status-seg button').forEach(x => x.classList.toggle('on', x.dataset.st === ''));
    document.querySelectorAll('#ddv-flag-chips button, #ddv-has-chips button').forEach(x => x.classList.remove('on'));
    document.querySelectorAll('#ddv-act-chips button, #ddv-erv-chips button').forEach(x => x.classList.remove('on'));
    ['ddv-f-dep', 'ddv-f-pos', 'ddv-f-sch'].forEach(i => { $id(i).value = ''; });
    st.page = 0;
    loadPage();
    updateFilterCount();
}

// ── "Has" chips (OT / leave / late / … — single-select, click again to clear) ─
$id('ddv-has-chips').addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    st.has = (st.has === b.dataset.has) ? '' : b.dataset.has;
    document.querySelectorAll('#ddv-has-chips button').forEach(x => x.classList.toggle('on', x.dataset.has === st.has));
    st.page = 0;
    loadPage();
    updateFilterCount();
});

// ── Activity chips (has notes / has messages — single-select) ────────────────
$id('ddv-act-chips').addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    st.act = (st.act === b.dataset.ac) ? '' : b.dataset.ac;
    document.querySelectorAll('#ddv-act-chips button').forEach(x => x.classList.toggle('on', x.dataset.ac === st.act));
    st.page = 0;
    loadPage();
    updateFilterCount();
});

// ── Employee sign-off chips (single-select; only when out for review) ───────
const ervChips = $id('ddv-erv-chips');
if (ervChips) ervChips.addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    st.erv = (st.erv === b.dataset.erv) ? '' : b.dataset.erv;
    document.querySelectorAll('#ddv-erv-chips button').forEach(x => x.classList.toggle('on', x.dataset.erv === st.erv));
    st.page = 0;
    loadPage();
    updateFilterCount();
});

// Options only contain values present in this batch (action=filter_opts)
async function loadFilterOpts() {
    try {
        const r = await fetch(`dtr-employee-server.php?action=filter_opts&id=${DDTR_ID}`);
        const j = await r.json();
        if (!j.result) return;
        const fill = (id, rows, label) => {
            $id(id).innerHTML = `<option value="">${label}: All</option>`
                + (rows || []).map(o => `<option value="${o.id}">${esc(o.name)}</option>`).join('');
        };
        fill('ddv-f-dep', j.departments, 'Department');
        fill('ddv-f-pos', j.positions, 'Position');
        fill('ddv-f-sch', j.schedules, 'Schedule');
    } catch (e) { /* filters simply stay at "All" */ }
}
loadFilterOpts();

// ── Status filter (All / Pending / Approved / Rejected) ──────────────────────
$id('ddv-status-seg').addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b || b.dataset.st === st.status) return;
    st.status = b.dataset.st;
    document.querySelectorAll('#ddv-status-seg button').forEach(x => x.classList.toggle('on', x === b));
    st.page = 0;
    loadPage();
    updateFilterCount();
});

// ── Exception-flag chips (single-select, click again to clear) ───────────────
$id('ddv-flag-chips').addEventListener('click', ev => {
    const b = ev.target.closest('button');
    if (!b) return;
    st.flag = (st.flag === b.dataset.fl) ? '' : b.dataset.fl;
    document.querySelectorAll('#ddv-flag-chips button').forEach(x => x.classList.toggle('on', x.dataset.fl === st.flag));
    st.page = 0;
    loadPage();
    updateFilterCount();
});

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

// ── Zoom (buttons, Ctrl+scroll, +/−/0 keys) ─────────────────────────────────
const ZOOM_MIN = 50, ZOOM_MAX = 200, ZOOM_STEP = 10;
let zoom = parseInt(localStorage.getItem('ddv_zoom'), 10) || 100;
function setZoom(z) {
    zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
    $id('ddv-paper').style.setProperty('--dtr-zoom', zoom / 100);
    $id('ddv-zoom-val').textContent = zoom + '%';
    $id('ddv-zoom-out').disabled = zoom <= ZOOM_MIN;
    $id('ddv-zoom-in').disabled  = zoom >= ZOOM_MAX;
    localStorage.setItem('ddv_zoom', zoom);
}
$id('ddv-zoom-in').onclick  = () => setZoom(zoom + ZOOM_STEP);
$id('ddv-zoom-out').onclick = () => setZoom(zoom - ZOOM_STEP);
$id('ddv-zoom-val').onclick = () => setZoom(100);
document.querySelector('.ddv-paper-scroll').addEventListener('wheel', ev => {
    if (!ev.ctrlKey && !ev.metaKey) return;
    ev.preventDefault();
    setZoom(zoom + (ev.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP));
}, { passive: false });
setZoom(zoom);

// ── Employee Review Progress actions (ported from dtr-details.js) ────────────
function remindDtrReview(id) {
    Swal.fire({
        title: 'Send reminder?',
        text: "Only employees who haven't reviewed yet will be notified.",
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#f7b84b', confirmButtonText: 'Yes, remind them',
    }).then(res => {
        if (!res.isConfirmed) return;
        Swal.fire({ title: 'Sending…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: 'ajax.php?action=remind_dtr_review', method: 'POST', dataType: 'JSON', data: { id: id },
            success: r => {
                if (r && r.result) Swal.fire({ icon: 'success', title: 'Reminder sent', text: r.message });
                else Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

// isDispute=false is a confirmation that carried a message — same endpoint,
// softer wording, since there is no problem to "resolve".
function openResolveDispute(type, reviewId, empName, isDispute) {
    const disp = isDispute !== false;
    Swal.fire({
        title: disp ? 'Resolve dispute' : 'Reply to employee',
        html: 'Reply to <b>' + esc(empName || 'employee') + '</b>. They will be notified.',
        input: 'textarea',
        inputPlaceholder: disp ? 'Explain what was checked / corrected…' : 'Answer their question or note…',
        showCancelButton: true, confirmButtonColor: '#0f9d58',
        confirmButtonText: disp ? 'Resolve & notify' : 'Send reply',
        preConfirm: v => { if (!v || !v.trim()) { Swal.showValidationMessage('A reply is required.'); return false; } return v.trim(); },
    }).then(res => {
        if (!res.isConfirmed) return;
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: 'ajax.php?action=resolve_review_dispute', method: 'POST', dataType: 'JSON',
            data: { type: type, id: reviewId, reply: res.value },
            success: r => {
                if (r && r.result) Swal.fire({ icon: 'success', title: disp ? 'Resolved' : 'Reply sent', text: r.message }).then(x => { if (x.isConfirmed) location.reload(); });
                else Swal.fire({ icon: 'error', title: 'Error!', text: (r && r.message) || 'Failed.' });
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error!', text: 'Request failed.' }),
        });
    });
}

// ── DTR sign-off conversation popup ──────────────────────────────────────────
// Reads window.DDV_REVIEWS (employee_id → decision + message + HR reply) and
// shows it as a two-bubble thread; opening it clears the UNREAD marker.
const DDV_RV_META = {
    1: { cls: 'ok',   ic: 'ri-checkbox-circle-line', lbl: 'Confirmed' },
    2: { cls: 'disp', ic: 'ri-error-warning-line',   lbl: 'Disputed' },
};
function ddvOpenReviewConvo(empId) {
    const r = (window.DDV_REVIEWS || {})[empId];
    const box = $id('mer-body'), btn = $id('mer-reply');
    if (!box) return;
    if (!r) {
        box.innerHTML = '<div class="mer-empty"><i class="ri-chat-off-line"></i> This employee has not reviewed yet.</div>';
        btn.style.display = 'none';
    } else {
        const meta = DDV_RV_META[r.st] || DDV_RV_META[1];
        let h = '<div class="mer-head">'
            + '<span class="mer-name">' + esc(r.name) + '</span>'
            + '<span class="mer-badge ' + meta.cls + '"><i class="' + meta.ic + '"></i>' + meta.lbl + '</span>'
            + (r.at ? '<span class="mer-when">' + esc(r.at) + '</span>' : '')
            + '</div><div class="mer-chat">';
        h += r.c
            ? '<div class="ddv-bub them">' + esc(r.c) + '<div class="m">' + esc(r.name) + (r.at ? ' · ' + esc(r.at) : '') + '</div></div>'
            : '<div class="mer-empty">' + meta.lbl + ' without a message.</div>';
        if (r.rep) h += '<div class="ddv-bub me">' + esc(r.rep) + '<div class="m">Reply' + (r.rep_at ? ' · ' + esc(r.rep_at) : '') + '</div></div>';
        h += '</div>';
        box.innerHTML = h;
        // Anything with a message and no reply yet can be answered.
        const canReply = CAN_EDIT && !r.rep && (r.st === 2 || (r.st === 1 && !!r.c));
        const isDisp = r.st === 2;
        btn.style.display = canReply ? '' : 'none';
        btn.innerHTML = isDisp
            ? '<i class="ri-chat-check-line me-1"></i>Resolve &amp; Reply'
            : '<i class="ri-chat-1-line me-1"></i>Reply';
        btn.onclick = canReply ? () => openResolveDispute('dtr', r.id, r.name, isDisp) : null;
        if (r.new) ddvMarkReviewSeen(empId, r);
    }
    $id('modal-emp-review').classList.add('open');
}
function ddvCloseReviewConvo() { $id('modal-emp-review').classList.remove('open'); }
// Backdrop click + Esc close it, the way a real dialog would.
$id('modal-emp-review').addEventListener('click', ev => {
    if (ev.target === ev.currentTarget) ddvCloseReviewConvo();
});
document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape' && $id('modal-emp-review').classList.contains('open')) ddvCloseReviewConvo();
});

// Opening a message clears its UNREAD state, server first then the badges.
function ddvMarkReviewSeen(empId, r) {
    r.new = 0;
    fetch('ajax.php?action=mark_review_seen', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=dtr&id=' + encodeURIComponent(r.id),
    }).catch(() => { /* the badge still clears locally */ });

    const row = document.querySelector('.drp-row[data-emp="' + empId + '"]');
    if (row) {
        row.classList.remove('unread');
        const tag = row.querySelector('.drp-row-new');
        if (tag) tag.remove();
    }
    const n = $id('drp-unread-n'), chip = $id('drp-unread-chip');
    if (n) {
        const left = Math.max(0, (parseInt(n.textContent, 10) || 0) - 1);
        n.textContent = left;
        if (!left) {
            if (chip) chip.style.display = 'none';
            const dot = $id('ddv-rtab-review-dot');
            if (dot) { dot.remove(); positionInk(); }
        }
    }
}

// role="button" divs need Enter/Space wired up by hand.
document.addEventListener('keydown', ev => {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    const row = ev.target.closest ? ev.target.closest('.drp-row.has-msg') : null;
    if (!row) return;
    ev.preventDefault();
    row.click();
});

// ── Right-panel sliding tabs (Summary / Records / Review / Batch) ────────────
const rtabsBar = $id('ddv-rtabs');
function positionInk() {
    const ink = rtabsBar.querySelector('.ddv-rtab-ink');
    const on  = rtabsBar.querySelector('button[data-tab].on');
    if (!ink || !on) return;
    ink.style.left  = on.offsetLeft + 'px';
    ink.style.width = on.offsetWidth + 'px';
}
function setRTab(name) {
    const views = Array.from(document.querySelectorAll('.ddv-rtab-view'));
    const i = views.findIndex(v => v.dataset.view === name);
    if (i < 0) return;
    rtabsBar.querySelectorAll('button[data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === name));
    $id('ddv-rtab-track').style.transform = `translateX(-${i * 100}%)`;
    positionInk();
}
rtabsBar.addEventListener('click', ev => {
    const b = ev.target.closest('button[data-tab]');
    if (b) setRTab(b.dataset.tab);
});
window.addEventListener('resize', positionInk);
window.addEventListener('load', positionInk);   // reposition once fonts settle
setRTab('summary');

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
    // Zoom keys (plain keys only — Ctrl/Cmd+/- stays browser zoom)
    if (!ev.ctrlKey && !ev.metaKey) {
        if (ev.key === '+' || ev.key === '=') { ev.preventDefault(); setZoom(zoom + ZOOM_STEP); }
        if (ev.key === '-')                   { ev.preventDefault(); setZoom(zoom - ZOOM_STEP); }
        if (ev.key === '0')                   { ev.preventDefault(); setZoom(100); }
    }
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
