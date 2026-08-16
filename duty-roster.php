<?php
/**
 * Duty Roster — standalone full-page cutoff grid for rotating staff.
 *
 * NOT routed through index.php (same as dtr-documents.php): the grid is 15-16
 * columns wide plus a sticky name column, and the app sidebar cost it ~270px
 * of the only axis that matters. The whole viewport is the planning surface.
 *
 * The Shift Roster page next door answers "which shift is this employee on,
 * from when to when". That is the right shape for fixed staff and the wrong one
 * for nurses, who rotate every couple of days and whose day off moves through
 * the week. Here the unit is ONE EMPLOYEE-DAY, painted onto a grid that matches
 * the sheet a head nurse already hands in every cutoff.
 *
 * The grid itself is drawn by assets2/js/duty-roster.js from a single
 * duty_roster_data call, because it re-renders on every filter change.
 */
require_once __DIR__ . '/includes/session_bootstrap.php';
if (empty($_SESSION['is_login'])) {
    header('Location: index.php');
    exit;
}
include 'db_connect.php';
// Standalone screen — index.php's router never sees it, so it enforces its own
// role boundary exactly as dtr-documents.php does.
require_page_access('duty-roster');

// Cutoff options — the halves of the month, a year back and three months
// forward, so a late correction and next cutoff's planning are both reachable.
$__curPer = date('Y-m') . '-' . ((int) date('j') <= 15 ? '1' : '2');
$__periods = [];
for ($i = -12; $i <= 3; $i++) {
    $t = strtotime(date('Y-m-01') . " $i month");
    foreach ([1, 2] as $half) {
        $last = (int) date('t', $t);
        $__periods[date('Y-m', $t) . '-' . $half] = $half === 1
            ? date('M 1', $t) . ' – ' . date('M 15, Y', $t)
            : date('M 16', $t) . ' – ' . date("M $last, Y", $t);
    }
}

// A Department Head / Supervisor is pinned to their own ward: they get one
// option, not a picker with everyone else's name in it. The server enforces
// this regardless (see dutyRosterEmployees), but offering a choice that is
// silently overridden is worse than offering none.
require_once __DIR__ . '/dept-scope.php';
$__scope = dept_scope_id();
$__areas = area_scope_ids();

// An area-scoped viewer is pinned to their wards, and the picker lists those
// wards rather than the departments containing them — four nurse stations share
// one department, so a department name would not tell them which sheet is open.
// The counts come from employee.area_id for the same reason.
$__depts = [];
if ($__areas !== []) {
    $in = implode(',', array_map('intval', $__areas));
    $__dq = $conn->query("SELECT a.department_id AS id, a.name, COUNT(e.id) AS n
                          FROM area a LEFT JOIN employee e ON e.area_id = a.id AND e.status = 1
                          WHERE a.id IN ($in)
                          GROUP BY a.id, a.name ORDER BY a.name ASC");
} else {
    $__dq = $conn->query("SELECT d.id, d.name, COUNT(e.id) AS n
                          FROM department d LEFT JOIN employee e ON e.department_id = d.id AND e.status = 1
                          " . ($__scope > 0 ? "WHERE d.id = $__scope " : "") . "
                          GROUP BY d.id, d.name ORDER BY d.name ASC");
}
while ($__dq && $__d = $__dq->fetch_assoc()) $__depts[] = $__d;

// Scoped viewers see their own headcount, not the hospital's — the "All" total
// is meaningless to someone who can only ever open one ward.
if ($__areas !== []) {
    $in = implode(',', array_map('intval', $__areas));
    $__allCount = (int) ($conn->query("SELECT COUNT(*) c FROM employee WHERE status = 1 AND area_id IN ($in)")->fetch_assoc()['c'] ?? 0);
} else {
    $__allCount = (int) ($conn->query("SELECT COUNT(*) c FROM employee WHERE status = 1")->fetch_assoc()['c'] ?? 0);
}

// Read-only roles get the grid without the paint palette or the write buttons.
$__can_edit = function_exists('can_edit') ? can_edit('duty-roster') : true;
// The admin freeze switch. Folded straight into $__can_edit so every palette
// button, dirty bar and hint already gated on it disappears the same way it
// does for a read-only role — one flag, not a second set of conditionals
// sprinkled through this template. The server enforces it again on every
// write endpoint regardless (dutyRosterLockDeny), so a stale page can never
// slip a change through.
$__is_admin = current_role() === 1;
$__lock = function_exists('duty_roster_lock_info') ? duty_roster_lock_info() : ['locked' => false, 'by' => '', 'at' => ''];
$__can_edit = $__can_edit && !$__lock['locked'];
// Publishing is a separate permission from planning: a Department Head fills
// their own ward's sheet, but the cutoff is released by whoever owns all of it.
// Asked as a question about the ENDPOINT so the button and the server can never
// disagree about who may press it.
$__can_publish = function_exists('action_allowed') ? action_allowed('duty_roster_publish') : $__can_edit;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Duty Roster</title>
    <meta name="robots" content="noindex, nofollow">
    <!-- This page renders its own <head> rather than includes/header.php, so the
         CSRF token has to be published here too — every save/publish call hits
         ajax.php, which requires it. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '', ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets2/js/csrf.js"></script>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/icons.min.css" rel="stylesheet">
    <!-- App-wide custom <select> control. index.php loads this for every routed
         page; a standalone page has to bring it itself, or its dropdowns fall
         back to the browser's native list with no filter box. -->
    <link rel="stylesheet" href="<?= av('assets2/css/custom-select.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root { --brand:#6642aa; --brand-dark:#4e3483; --line:#e1dfdd; --sb-thumb:#cfc4e6; }
html, body { height:100%; }
body { margin:0; background:#f0eff2; font-family:'Segoe UI',system-ui,Arial,sans-serif; overflow:hidden;
       display:flex; flex-direction:column; }
/* Soft purple scrollbars — standalone page, no theme.css to inherit from. */
* { scrollbar-width:thin; scrollbar-color:var(--sb-thumb) transparent; }
*::-webkit-scrollbar { width:10px; height:10px; }
*::-webkit-scrollbar-track, *::-webkit-scrollbar-corner { background:transparent; }
*::-webkit-scrollbar-thumb { background:var(--sb-thumb); border-radius:8px; border:2px solid transparent; background-clip:content-box; }
*::-webkit-scrollbar-thumb:hover { background:#b7a7d9; background-clip:content-box; }

/* ── Top bar ── */
.dr-header {
    flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:10px 16px; background:#fff; border-bottom:1px solid var(--line);
    box-shadow:0 1px 6px rgba(0,0,0,.06); z-index:20; flex-wrap:wrap;
}
.dr-h-left { display:flex; align-items:center; gap:12px; min-width:0; }
.dr-back-btn {
    display:inline-flex; align-items:center; gap:6px; flex-shrink:0;
    padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:700;
    color:var(--brand-dark); background:#f2f0f7; border:1px solid #ddd9e7; text-decoration:none;
}
.dr-back-btn:hover { background:#e5e1ef; color:var(--brand-dark); }
.dr-title-icon {
    width:38px; height:38px; border-radius:11px; flex-shrink:0;
    background:linear-gradient(135deg,#6642aa,#7654b8); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:18px;
    box-shadow:0 3px 8px rgba(102,66,170,.30);
}
.dr-h-title { font-size:15px; font-weight:800; color:#2b2639; display:flex; align-items:center; gap:8px; }
.dr-meta-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:3px; }
.dr-meta-chip {
    display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:600; color:#5d5870;
    background:#f2f0f7; border:1px solid #ddd9e7; border-radius:20px; padding:2px 9px;
}
.dr-h-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.dr-btn {
    display:inline-flex; align-items:center; gap:6px; cursor:pointer;
    padding:7px 13px; border-radius:8px; font-size:12.5px; font-weight:700;
    color:var(--brand-dark); background:#f2f0f7; border:1px solid #ddd9e7; transition:background .12s;
    text-decoration:none;
}
.dr-btn:hover:not(:disabled) { background:#e5e1ef; border-color:#c0b5d5; }
.dr-btn:disabled { opacity:.5; cursor:not-allowed; }
/* Publish is the only irreversible act on this page — it notifies every
   employee named on the sheet. It used to be a pale lavender indistinguishable
   from "Copy last" and the two navigation links beside it, so the row read as
   five equal choices. It is solid now, and everything else steps back. */
.dr-btn.primary { background:var(--brand); border-color:var(--brand); color:#fff;
                  box-shadow:0 1px 3px rgba(102,66,170,.35); }
.dr-btn.primary:hover:not(:disabled) { background:#583894; border-color:#583894;
                                       box-shadow:0 2px 7px rgba(102,66,170,.45); }
.dr-btn.primary:disabled { box-shadow:none; }
.dr-btn.danger { background:#fff; border-color:#f0cfcc; color:#c0453f; }
.dr-btn.danger:hover:not(:disabled) { background:#fdf0ef; border-color:#e3b0ac; color:#a5322d; }
/* Links OUT of this page, not actions on it. Quietest of the three tiers. */
.dr-btn.ghost { background:transparent; border-color:transparent; color:#6b6878; font-weight:600; }
.dr-btn.ghost:hover { background:#f2f0f7; border-color:#e5e1ef; color:var(--brand-dark); }
.dr-h-sep { width:1px; align-self:stretch; margin:2px 2px; background:#e6e2ee; flex-shrink:0; }
.dr-btn.warn { background:#fff8e1; border-color:#ffe082; color:#c98a00; }

/* ── Body: controls strip + the grid filling everything left ── */
.dr-body { flex:1; min-height:0; display:flex; flex-direction:column; padding:12px 16px 0; gap:9px; }
/* The filters and the palette are one surface, divided by a hairline rather
   than by a gap of page background. */
.dr-controls { flex-shrink:0; background:#fff; border:1px solid var(--line); border-radius:12px;
               box-shadow:0 1px 2px rgba(40,34,59,.05); overflow:hidden; }
.dr-toolbar { flex-shrink:0; background:transparent; border:0; border-radius:0; padding:10px 12px; }
.dr-controls .dr-palette-row { border:0; border-top:1px solid #eeebf4; border-radius:0;
                               box-shadow:none; background:#fcfbfe; }
.dr-toolbar .lbl { font-size:10.5px; font-weight:700; color:#7a7688; margin-bottom:3px; text-transform:uppercase; letter-spacing:.3px; }
.dr-toolbar .form-select, .dr-toolbar .form-control { font-size:13px; }

/* ── Shift palette ── */
/* Painting beats typing on a 40 x 15 grid: pick once, then click or drag.
   The active swatch is the only mode indicator, so it has to be loud.
   One line that scrolls sideways, not a wrapping block: there are a dozen
   shifts on file and only three or four matter to any one ward. */
/* One toolbar strip, the way an editor puts its tools: a single raised surface
   with the swatches on the left and the file actions pinned right, rather than
   two loose rows of controls floating on the page background. */
.dr-palette-row { flex-shrink:0; display:flex; align-items:center; gap:8px; flex-wrap:nowrap;
                  background:#fff; border:1px solid var(--line); border-radius:11px;
                  padding:6px 8px; box-shadow:0 1px 2px rgba(40,34,59,.05); }
.dr-palette-label { display:inline-flex; align-items:center; gap:5px; flex-shrink:0;
                    font-size:11px; font-weight:600; letter-spacing:.3px; text-transform:uppercase;
                    color:#9895a3; padding-right:2px; }
.dr-palette { display:flex; flex-wrap:nowrap; gap:5px; align-items:center; overflow-x:auto; min-width:0; flex:1;
              padding:1px; scrollbar-width:thin; }
.dr-palette::-webkit-scrollbar { height:5px; }
.dr-palette::-webkit-scrollbar-thumb { background:#dcd8e6; border-radius:3px; }
.dr-palette::-webkit-scrollbar-track { background:transparent; }

/* The swatch IS the colour now — same fill the grid cell gets — instead of a
   white pill with a colour dot and the times spelled out beside it. A dozen
   shifts used to run off the end of the row; the hours moved into the tooltip,
   which is where you look once rather than read a dozen times. */
.dr-swatch { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
             min-width:46px; height:27px; padding:0 10px; border-radius:7px;
             font-size:11.5px; font-weight:700; letter-spacing:.2px; color:#28223b;
             border:1px solid rgba(0,0,0,.10); box-shadow:0 1px 1px rgba(40,34,59,.05);
             cursor:pointer; user-select:none; white-space:nowrap;
             transition:transform .1s, box-shadow .12s, border-color .12s; }
.dr-swatch:hover { transform:translateY(-1px); box-shadow:0 3px 9px rgba(40,34,59,.16); }
.dr-swatch.on { border-color:#28223b; box-shadow:0 0 0 2.5px rgba(102,66,170,.30); }
.dr-swatch.dr-sw-clear { background:#fff; color:#a9a5b5; border-style:dashed; border-color:#cfcada; }

/* Combine-with-shift toggle — only meaningful while a shift swatch is picked
   (rest and clear already carry their own r value), so it dims otherwise
   rather than disabling outright: still checkable ahead of picking a shift. */
.dr-also-rest { display:inline-flex; align-items:center; gap:5px; flex-shrink:0;
    height:27px; padding:0 10px; margin-left:2px; border-radius:7px; font-size:11px; font-weight:700;
    color:#8c8998; border:1px dashed #cfcada; cursor:pointer; user-select:none;
    transition:background .12s, color .12s, border-color .12s; }
.dr-also-rest input { margin:0; accent-color:var(--brand); }
.dr-also-rest.dimmed { opacity:.5; }
.dr-also-rest.on { color:#4f3288; border-style:solid; border-color:#c9bce8; background:#f4f1fa; }
/* Flashed when a paint action is attempted with nothing selected — the toast
   says what to do, this says where. */
@keyframes dr-flash { 0%,100% { background:transparent; } 35% { background:#ede4ff; } }

/* File actions, pinned right of the palette so they never scroll away with it. */
.dr-tools { display:flex; align-items:center; gap:3px; flex-shrink:0; padding-left:8px;
            border-left:1px solid #e9e6f0; margin-left:2px; }
.dr-tool { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px;
           border:1px solid transparent; border-radius:8px; background:transparent; color:#6b6878;
           font-size:16px; cursor:pointer; transition:background .12s, color .12s, border-color .12s; }
.dr-tool:hover:not(:disabled) { background:#f2f0f7; border-color:#e0dbec; color:var(--brand-dark); }
.dr-tool:active:not(:disabled) { background:#e9e4f5; }
.dr-tool:disabled { opacity:.35; cursor:not-allowed; }

/* ── Rotation pattern modal ── */
.dr-modal { position:fixed; inset:0; z-index:3500; display:none; align-items:center; justify-content:center;
            background:rgba(28,24,42,.42); padding:20px; }
.dr-modal.show { display:flex; }
.dr-modal-box { background:#fff; border-radius:14px; width:100%; max-width:600px; max-height:90vh;
                display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(28,24,42,.35); overflow:hidden; }
.dr-modal-head { display:flex; align-items:flex-start; gap:10px; padding:14px 16px; border-bottom:1px solid var(--line); }
.dr-modal-head .t { font-weight:700; color:var(--brand-dark); font-size:15px; }
.dr-modal-head .s { font-size:12px; color:#9895a3; margin-top:2px; }
.dr-modal-x { margin-left:auto; border:0; background:transparent; color:#9895a3; font-size:20px; cursor:pointer;
              line-height:1; padding:0 2px; }
.dr-modal-x:hover { color:#28223b; }
.dr-modal-body { padding:14px 16px; overflow:auto; }
.dr-modal-foot { display:flex; align-items:center; gap:8px; padding:12px 16px; border-top:1px solid var(--line);
                 background:#faf9fc; }

/* ── Print preview modal — same shell as the rotation-pattern dialog above,
   just wide and tall enough to hold a full-page PDF instead of a form. ── */
.dr-modal-wide { max-width:96vw; width:1400px; height:92vh; }
.dr-modal-body-pdf { flex:1; display:flex; padding:0; background:#5c5670; }
.dr-modal-body-pdf iframe { flex:1; width:100%; height:100%; border:0; }
.dr-pat-lbl { font-size:11px; font-weight:600; letter-spacing:.3px; text-transform:uppercase; color:#9895a3;
              margin-bottom:5px; }
.dr-pat-steps { display:flex; flex-direction:column; gap:6px; }
.dr-pat-step { display:flex; align-items:center; gap:6px; }
.dr-pat-step .n { width:20px; text-align:center; font-size:11px; color:#c0bdc9; font-weight:700; flex-shrink:0; }
.dr-pat-step select { flex:1; min-width:0; }
.dr-pat-step input { width:64px; flex-shrink:0; text-align:center; }
.dr-pat-step .x { border:0; background:transparent; color:#c0bdc9; font-size:16px; cursor:pointer; padding:0 4px; }
.dr-pat-step .x:hover { color:#c62828; }
.dr-pat-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
.dr-pat-help { font-size:11px; color:#9895a3; margin-top:4px; line-height:1.45; }
.dr-pat-scope { flex:1; font-size:12px; color:#6b6878; }
/* The preview is the whole point of the screen: a rotation is easy to describe
   and hard to picture, so the first cycle is drawn out in the real cell colours
   before anything is painted. */
.dr-pat-preview { margin-top:14px; padding:10px; border:1px solid var(--line); border-radius:9px; background:#faf9fc; }
.dr-pat-preview .h { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.3px;
                     color:#9895a3; margin-bottom:7px; }
.dr-pat-strip { display:flex; gap:3px; align-items:center; flex-wrap:wrap; margin-bottom:5px; }
.dr-pat-strip .who { font-size:10.5px; color:#807c8d; width:74px; flex-shrink:0; }
.dr-pat-cell { width:32px; height:22px; border-radius:5px; font-size:10px; font-weight:700;
               display:inline-flex; align-items:center; justify-content:center; border:1px solid rgba(0,0,0,.10); }
.dr-pat-warn { font-size:12px; color:#ad6800; background:#fff8e1; border:1px solid #ffe082;
               border-radius:8px; padding:7px 9px; margin-top:9px; }

/* Tooltip. Parented to <body> and position:fixed because the palette is an
   overflow-x scroller: anything drawn inside it with ::after is clipped at the
   strip's edge, which is exactly where a tooltip needs to go. */
.dr-tip { position:fixed; z-index:4000; max-width:250px; white-space:pre-line;
          background:#28223b; color:#fff; font-size:11.5px; font-weight:500; line-height:1.5;
          padding:6px 9px; border-radius:7px; box-shadow:0 6px 20px rgba(40,34,59,.28);
          pointer-events:none; opacity:0; transform:translateY(-3px);
          transition:opacity .12s ease, transform .12s ease; }
.dr-tip.show { opacity:1; transform:none; }
.dr-tip b { font-weight:700; }
.dr-tip .k { color:#c9bdf0; }

/* ── Grid ── */
/* flex:0 1 auto, NOT flex:1 — the card is as tall as its content and no
   taller. A ward of six left a third of the screen as blank white below the
   coverage rows, which reads as a page that failed to finish loading rather
   than a short list. It still shrinks (and scrolls) when the roster is longer
   than the space, which is the case that actually needed the flex. */
.dr-scroll { flex:0 1 auto; min-height:0; overflow:auto; border:1px solid var(--line);
             border-radius:12px; background:#fff; box-shadow:0 1px 2px rgba(40,34,59,.05); }
/* table-layout:fixed so the name column is exactly 210px and the day columns
   split whatever is left EVENLY. Under the default `auto` algorithm the
   browser treats per-cell min/max-width as suggestions and hands the surplus
   out unevenly — a 16-column grid in a 1700px pane gave the name column 400px
   and every day 88px. The JS sets min-width to the grid's natural size
   (210 + 46 x days), so a narrow screen scrolls instead of squeezing. */
table.dr-grid { border-collapse:separate; border-spacing:0; font-size:12px; margin:0; width:100%; table-layout:fixed; }
table.dr-grid th, table.dr-grid td { border-right:1px solid #eef0f4; border-bottom:1px solid #eef0f4; padding:0; }
/* The name column and the date header both stay put while the other axis
   scrolls — without this you lose track of whose row you are painting. */
table.dr-grid thead th { position:sticky; top:0; z-index:3; background:#f4f2f9;
                         box-shadow:inset 0 -1px 0 #ded9ea; }
table.dr-grid .dr-emp { position:sticky; left:0; z-index:2; background:#fff; width:210px;
                        padding:4px 8px; text-align:left; }
table.dr-grid thead .dr-emp { z-index:4; background:#f4f2f9; }
/* The frozen column needs a visible edge, not just a hairline: when the grid
   scrolls sideways the day cells slide UNDER it, and with a 1px rule they
   appeared to be part of the name column for the moment they crossed it. */
table.dr-grid .dr-emp { box-shadow:1px 0 0 #ded9ea, 3px 0 6px -3px rgba(40,34,59,.14); }

/* Zebra + a whole-row hover. Reading across fifteen columns to the right
   person is the single most common thing done on this screen, and on a plain
   white grid the eye loses the line halfway. */
table.dr-grid tbody tr:nth-child(even) .dr-emp { background:#fbfaFd; }
table.dr-grid tbody tr:nth-child(even) td.dr-cell:not([style*="background"]) { background:#fcfbfe; }
table.dr-grid tbody tr:hover .dr-emp { background:#f3effc; }
table.dr-grid tbody tr:hover td { border-bottom-color:#d9d2ea; }
.dr-emp-name { font-weight:600; font-size:11.5px; color:#28223b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dr-emp-sub  { font-size:9.5px; color:#9895a3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
/* Name opens the shared quick-view drawer. Kept as a real <a> so it still
   middle-clicks / opens in a new tab to the full detail page. */
.dr-emp-link, .dr-emp-plain { display:block; min-width:0; flex:1; text-decoration:none; }
.dr-emp-link:hover .dr-emp-name { color:var(--brand); text-decoration:underline; }
.dr-emp-fill { border:0; background:transparent; color:#a9a5b6; cursor:pointer; font-size:11px; padding:0 2px; }
.dr-emp-fill:hover { color:var(--brand); }

.dr-dayhead { text-align:center; padding:4px 2px; cursor:pointer; }
.dr-dayhead:hover { background:#ede9f5; }
.dr-dom { font-size:12px; font-weight:700; color:#28223b; line-height:1.1; }
.dr-dow { font-size:9.5px; color:#9895a3; text-transform:uppercase; }
.dr-dayhead.dr-we .dr-dom, .dr-dayhead.dr-we .dr-dow { color:#c1544f; }
.dr-dayhead.dr-hol { background:#fff4e6; }
.dr-dayhead.dr-hol .dr-dom { color:#d46b08; }

.dr-cell { height:30px; text-align:center; cursor:pointer; position:relative;
           font-size:10.5px; font-weight:700; color:#28223b; user-select:none; }
.dr-cell:hover { outline:2px solid var(--brand); outline-offset:-2px; }
.dr-cell.dr-empty { color:#d5d2de; }
.dr-cell.dr-rest  { background:#f4f4f6; color:#a9a5b6; }
/* Rest/empty read as icons, not a bare dot — restores the icon this grid used
   to drop (see the legend comment below) but this time drawn from the SAME
   rule the legend swatch inherits, so the two can never show different marks.
   Coloured (not inherited) because dr-rest's own background is always the
   same flat grey — a fixed colour is safe here, unlike the punched marker
   below, which sits on whatever colour the shift underneath it happens to be. */
.dr-cell.dr-rest::before  { content:"\ef75"; font-family:"remixicon"; font-size:13px; color:#7c5cbf; }
.dr-cell.dr-empty::before { content:"\f1af"; font-family:"remixicon"; font-size:10px; }
/* Rest day WITH a shift on file: the shift code is the label, centered same
   as any other cell, and the moon sits as a small badge pinned to the right
   edge rather than displacing the text. Hovering still gives the full shift
   name and time range (see the "shift on file" tooltip line in duty-roster.js).
   Orange, not the plain rest grey — a day that is BOTH off and worked is its
   own state, and needs to read as one at a glance, not as a shift cell that
   happens to also carry a moon. Same orange the Shifts export tab uses for
   this combo, so the web grid and the sheet agree on what it looks like. */
.dr-cell.dr-rest.dr-rest-shift { background:#ffe0b2; color:#7a3c00; }
.dr-cell.dr-rest.dr-rest-shift::before {
    position:absolute; right:2px; top:50%; transform:translateY(-50%); font-size:11px; color:#b8580a;
}
/* Zone styling. These are not decoration — they are the whole answer to
   "can I still change this day", so they must read at a glance. */
.dr-cell.dr-locked { cursor:not-allowed; background:#f0f0f2 !important; color:#bdbac6 !important; }
.dr-cell.dr-locked::after { content:"\f0221"; font-family:"remixicon"; position:absolute; right:2px; bottom:0;
                            font-size:9px; color:#a9a5b6; }
/* A punched day is still editable, but its DTR figures were frozen under the
   old shift — the corner fingerprint warns that a Recompute is coming if you
   touch it. ::after (not ::before, which .dr-rest's icon now owns) — a rest
   day that already has a punch on it needs both icons at once.
   White + dark halo instead of a flat colour: this marker can land on ANY
   shift's own background (pastel day colours through near-black night ones),
   and no single flat colour reads on both ends of that range. */
.dr-cell.dr-punched::after { content:"\ed31"; font-family:"remixicon"; position:absolute; left:1px; top:1px;
                             font-size:13px; color:#fff;
                             text-shadow:0 0 2px rgba(20,16,32,.9), 0 0 4px rgba(20,16,32,.6); }
.dr-cell.dr-draft::after { content:""; position:absolute; right:2px; top:2px; width:0; height:0;
                           border-top:6px solid #faad14; border-left:6px solid transparent; }
.dr-cell.dr-dirty { box-shadow:inset 0 0 0 2px var(--brand); }

/* Approved leave. Two different things, and only one is a problem:
   dr-onleave  — the day is free or a rest day. Just context, kept quiet.
   dr-leaveclash — a SHIFT is planned on a day they are on approved leave.
   That nurse will not come in, the day reads as an absence against a shift
   nobody was ever going to work, and it surfaces in payroll weeks later. It
   gets the loud treatment: hatching plus a corner flag.

   The on-leave mark is drawn by a real child <i class="dr-lv"> element rather
   than a pseudo on the cell, because BOTH cell pseudos are already spoken for
   on exactly the cells that can carry it: ::before is the centred rest moon /
   empty dash, and ::after is the punched, locked, draft and rest-clash corner
   markers. Owning its own element gives the leave stripe AND the suitcase a
   pseudo each, and — the reason it changed — stops the old
   `.dr-onleave::before` stripe from overwriting the moon on a rest day taken
   during leave, which silently erased the rest marker on every such cell.
   The glyph is defined once here, so the grid cell and the legend swatch (an
   empty <i class="dr-lv"> in the same markup) cannot drift apart. */
/* Tint only the otherwise-blank cell. A rest day (grey) and a rest+shift day
   (orange) already say something with their background, and leave must not
   repaint a state the planner reads colour-first. */
.dr-cell.dr-empty.dr-onleave { background-color:#f7f4fd; }
.dr-lv { position:absolute; inset:0; pointer-events:none; }
/* Suitcase, top-centre: the one spot on the cell nothing else claims — the
   corners are the punched print, the draft triangle, the lock and the rest
   clash, the middle is the label (shift code / rest moon / empty dash) and the
   bottom edge is the leave stripe below. The white halo is there for the same
   reason the punched marker has one: on a rest+shift day this lands on that
   state's orange, not on white. */
.dr-lv::before { content:"\f1b9"; font-family:"remixicon"; position:absolute; left:50%; top:0;
                 transform:translateX(-50%); font-size:9.5px; line-height:1; color:#6f4fbb;
                 text-shadow:0 0 2px #fff, 0 0 3px #fff; }
.dr-lv::after { content:""; position:absolute; left:0; bottom:0; width:100%; height:3px;
                background:repeating-linear-gradient(90deg,#b9a3e8 0 3px,transparent 3px 6px); }
.dr-cell.dr-leaveclash {
    background-image:repeating-linear-gradient(45deg,rgba(198,40,40,.20) 0 4px,transparent 4px 8px);
    box-shadow:inset 0 0 0 2px #c62828;
}
.dr-cell.dr-leaveclash::after { content:"\eb97"; font-family:"remixicon"; position:absolute; right:1px; top:0;
                                font-size:10px; color:#c62828; border:0; width:auto; height:auto; }

/* Less than MIN_REST_HOURS between yesterday's shift end and today's shift
   start (duty-roster.js restGapHours) — a fatigue risk, not a data problem,
   so it gets the same loud hatch+corner treatment as a leave clash but in
   the file's existing "soft warning" amber rather than leave's red, and at
   the one corner nothing else claims (top-left punched, top-right draft/
   leave, bottom-right locked, so bottom-left is free). */
.dr-cell.dr-restclash {
    background-image:repeating-linear-gradient(45deg,rgba(201,138,0,.22) 0 4px,transparent 4px 8px);
    box-shadow:inset 0 0 0 2px #c98a00;
}
.dr-cell.dr-restclash::after { content:"\ea1d"; font-family:"remixicon"; position:absolute; left:1px; bottom:1px;
                               font-size:13px; color:#c98a00; }

/* The clash banner above the grid — a cell marker is only found by someone
   already looking at the right cell. */
.dr-clash { display:flex; align-items:flex-start; gap:9px; padding:9px 12px; margin:0 0 8px;
            background:#fdefee; border:1px solid #f2ccc9; border-radius:10px; flex-shrink:0; }
.dr-clash .t { font-size:12.5px; color:#8a2c28; line-height:1.5; }
.dr-clash .t b { color:#6d1f1c; }
.dr-clash .who { font-family:inherit; font-size:12px; color:#a3403c; }
.dr-clash .dr-more { color:#8a2c28; font-weight:700; text-decoration:underline; white-space:nowrap; }
.dr-clash .dr-more:hover { color:#5f1c1a; }
.dr-clash.soft .dr-more { color:#8a6100; }
.dr-clash.soft .dr-more:hover { color:#5c4100; }
/* A long unbroken run of work days is worth saying, but it is a question, not
   an error — some wards run 12-day stretches on purpose. Quieter palette so it
   never competes with a real leave clash. */
.dr-clash.soft { background:#fff8e1; border-color:#ffe082; }
.dr-clash.soft .t { color:#8a6100; }
.dr-clash.soft .t b { color:#6b4a00; }
.dr-clash.soft .who { color:#a67c1a; }
.dr-clash.soft > i { color:#c98a00 !important; }

/* ── Coverage footer (collapsible) ── */
/* Every row is sticky to its own offset — see stackFooter() in the JS. The
   summary row is the last to be given an offset, so it sits on top of the
   stack and reads as the panel's header. */
table.dr-grid tfoot th, table.dr-grid tfoot td { position:sticky; bottom:0; background:#f7f6fa; z-index:2; }
table.dr-grid tfoot .dr-emp { z-index:3; background:#f7f6fa; }
.dr-cov { text-align:center; font-size:11px; font-weight:700; color:var(--brand-dark); padding:3px 2px; }
.dr-cov.dr-cov-low { background:#fff1f0; color:#cf1322; }
.dr-cov-label { font-size:10.5px; font-weight:700; color:var(--brand-dark); padding:3px 4px 3px 22px;
                display:flex; align-items:center; gap:5px; }
.dr-cov-label .dr-chip { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
.dr-cov-time { font-weight:500; color:#9895a3; }

/* Summary row — the one that never folds away, and the only line on the page
   that answers "is this day covered". It carries the weight to match. */
table.dr-grid tfoot tr.dr-cov-head th,
table.dr-grid tfoot tr.dr-cov-head td { background:#eae5f6; border-top:2px solid #c9c0e0; }
.dr-cov-total { font-size:13px; font-weight:800; color:#3d2b6b; }
/* The per-shift rows sit visually beneath the total rather than beside it. */
table.dr-grid tfoot tr.dr-cov-row th,
table.dr-grid tfoot tr.dr-cov-row td { background:#faf9fc; }
table.dr-grid tfoot tr.dr-cov-row:hover th,
table.dr-grid tfoot tr.dr-cov-row:hover td { background:#f4f1fa; }
.dr-cov-toggle {
    display:flex; align-items:center; gap:6px; width:100%; padding:4px 4px; border:0; background:transparent;
    cursor:pointer; font-size:11px; font-weight:800; color:var(--brand-dark); text-align:left;
}
.dr-cov-toggle:hover .dr-cov-title { text-decoration:underline; }
.dr-cov-caret { font-size:15px; line-height:1; transition:transform .18s ease; color:#7c72a0; }
tfoot.collapsed .dr-cov-caret { transform:rotate(-90deg); }
.dr-cov-title { letter-spacing:.2px; }
.dr-cov-n { background:#ded6ee; color:#4f3288; border-radius:20px; padding:1px 7px; font-size:9.5px; font-weight:800; }
.dr-cov-warn { display:inline-flex; align-items:center; gap:3px; margin-left:auto; background:#fdecea; color:#c62828;
               border:1px solid #f5c6cb; border-radius:20px; padding:1px 7px; font-size:9.5px; font-weight:800; }
.dr-cov-warn i { font-size:10px; }
tfoot.collapsed .dr-cov-row { display:none; }

/* ── Footer strip: legend + recompute + unsaved ── */
/* margin-top:auto pins the key to the bottom of the viewport now that the grid
   card hugs its content — otherwise a short roster left it floating in the
   middle of the page attached to nothing. */
.dr-foot { flex-shrink:0; display:flex; align-items:center; gap:10px; padding:10px 16px 12px;
           flex-wrap:wrap; margin-top:auto; }

/* The key is ONE pill holding five items, not five separate pills.
   Spelling each state out in full — "already punched — a change needs
   Recompute" — made the row as wide as the page and as heavy as the grid it
   was annotating, and it is reference material you consult once. Each item is
   now a single term, with the sentence moved into the hover the rest of this
   page already uses. */
.dr-legend { display:inline-flex; align-items:center; flex-wrap:wrap; gap:0;
             font-size:11px; color:#6b6878;
             background:#fff; border:1px solid #e6e2ee; border-radius:22px;
             padding:3px 6px; box-shadow:0 1px 2px rgba(40,34,59,.04); }
.dr-legend-title { font-size:9.5px; font-weight:700; letter-spacing:.11em; text-transform:uppercase;
                   color:#b0abbe; padding:0 9px 0 6px; flex-shrink:0; }
.dr-legend > span { display:inline-flex; align-items:center; gap:6px; white-space:nowrap;
                    padding:3px 9px; border-radius:16px; cursor:help;
                    transition:background .12s, color .12s; }
.dr-legend > span:hover { background:#f4f2f9; color:#28223b; }
/* Each legend swatch is a real .dr-cell wearing the same state class as the
   grid, so the key can never drift from what the cells actually look like.
   It used to use stand-in icons — a moon for "rest day" that the grid never
   draws — which is what made the plain "·" cells unreadable. .lg-sw comes
   after .dr-cell in this sheet, so it wins on sizing only. */
/* Sized a little larger than the grid cell it mirrors: the state markers are a
   5px dot, a 6px corner triangle and a 9px lock glyph, and at the cell's own
   scale — surrounded by white in a legend rather than in a run of other cells —
   they were too small to tell apart. */
.lg-sw { display:inline-flex; align-items:center; justify-content:center; width:30px; height:21px;
         border:1px solid #ddd9e7; border-radius:4px; background:#fff; position:relative;
         font-size:11px; font-weight:700; cursor:inherit; flex-shrink:0; }
/* Reusing .dr-cell for the swatch is what keeps the key honest, but it drags
   the grid's INTERACTION styling along with its appearance: hovering a legend
   item drew the paint-target outline inside its own swatch, so the key looked
   like something you could click and paint. Appearance is borrowed here;
   behaviour is not. */
.dr-legend .lg-sw:hover { outline:none; }
/* The rest+shift swatch is the one legend item carrying BOTH a text label and
   a badge icon — wider than the shared 30px so "6-2" and the moon badge each
   get their own room instead of fighting for it, at the normal 11px size. */
.lg-sw.dr-rest-shift { width:60px; }

/* The instructions, not the key. Only useful the first few times, so it steps
   back rather than sitting at the same weight as everything else. */
.dr-hint { font-size:10.5px; color:#a5a1b0; display:inline-flex; align-items:center; gap:5px; }
.dr-hint i { color:#c0bcca; }
.dr-hint kbd { background:#f4f2f9; border:1px solid #e6e2ee; border-radius:4px; padding:0 4px;
               font-family:inherit; font-size:10px; font-weight:700; color:#6b6878; }
.dr-elsewhere { flex-shrink:0; border:1px solid #a8cff5; background:linear-gradient(90deg,#e8f2fd,#f5faff);
                border-radius:9px; padding:8px 12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.dr-elsewhere .t { font-size:12px; color:#1565c0; font-weight:600; }
.dr-elsewhere .go { display:inline-flex; align-items:center; gap:5px; border:1px solid #a8cff5; background:#fff;
                    color:#1565c0; border-radius:20px; padding:3px 11px; font-size:11.5px; font-weight:700; cursor:pointer; }
.dr-elsewhere .go:hover { background:#dbeafe; }
.dr-elsewhere .go small { font-weight:500; color:#6b93bf; }
.dr-recompute { flex-shrink:0; border:1px solid #ffd591; background:linear-gradient(90deg,#fff7e6,#fffbf0);
                border-radius:9px; padding:8px 12px; display:flex; align-items:center; gap:10px; margin:0 16px; }
.dr-recompute .t { font-size:12px; color:#ad6800; font-weight:600; }
.dr-dirtybar { position:fixed; left:50%; bottom:18px; transform:translate(-50%,180%); z-index:1030;
               background:#fff; border:1px solid #e4e3e9; border-radius:14px; padding:10px 14px;
               box-shadow:0 14px 40px -12px rgba(47,35,73,.30); display:flex; align-items:center; gap:12px;
               transition:transform .28s cubic-bezier(.2,.9,.3,1.2); }
.dr-dirtybar.show { transform:translate(-50%,0); }
.dr-dirtybar .n { background:var(--brand); color:#fff; border-radius:8px; min-width:26px; height:26px; padding:0 6px;
                  display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; }
/* Deliberately NOT called .dr-empty: that class already means "cell with no
   shift painted on it", and a layout rule landing on 2,000 table cells turned
   every one of them into a flex box with rounded corners. */
.dr-placeholder { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
            color:#9895a3; font-size:13px; background:#fff; border:1px solid var(--line); border-radius:10px; }

@media (max-width:820px) {
    .dr-meta-chips { display:none; }
    .dr-body { padding:10px 10px 0; }
}
</style>
</head>
<body>

<!-- ── Top bar ── -->
<div class="dr-header">
    <div class="dr-h-left">
        <?php
        /* Back has to land somewhere this role can actually open. A Department
           Head cannot reach schedule-roster, so the old fixed link bounced them
           through a redirect to their own landing page — the button appeared to
           throw them somewhere at random. role_landing_page() is the answer the
           app already has for "where does this role live". */
        $__back = page_allowed('schedule-roster')
            ? 'index.php?page=schedule-roster'
            : 'index.php?page=' . (function_exists('role_landing_page') ? role_landing_page() : 'home');
        ?>
        <a class="dr-back-btn" href="<?= htmlspecialchars($__back) ?>"><i class="ri-arrow-left-line"></i> Back</a>
        <div class="dr-title-icon"><i class="ri-calendar-schedule-line"></i></div>
        <div>
            <div class="dr-h-title">Duty Roster</div>
            <div class="dr-meta-chips">
                <span class="dr-meta-chip" id="chip-period"><i class="ri-calendar-2-line"></i>—</span>
                <span class="dr-meta-chip" id="chip-dept"><i class="ri-building-line"></i>No department</span>
                <span class="dr-meta-chip" id="chip-count"><i class="ri-team-line"></i>0 employees</span>
                <span class="dr-meta-chip" id="chip-drafts" style="display:none;"><i class="ri-draft-line"></i>0 drafts</span>
            </div>
        </div>
    </div>
    <div class="dr-h-actions">
        <?php /* Same rule: a link to a screen this role is refused is not a
                 shortcut, it is a trapdoor. */ ?>
        <?php if (page_allowed('schedule-roster')): ?>
            <a class="dr-btn ghost" href="index.php?page=schedule-roster"><i class="ri-team-line"></i> Shift Roster</a>
        <?php endif; ?>
        <?php if (page_allowed('work-schedules')): ?>
            <a class="dr-btn ghost" href="index.php?page=work-schedules"><i class="ri-time-line"></i> Manage Shifts</a>
        <?php endif; ?>
        <?php if ($__can_edit && (page_allowed('schedule-roster') || page_allowed('work-schedules'))): ?>
            <span class="dr-h-sep"></span>
        <?php endif; ?>
        <?php if ($__can_edit): ?>
            <button class="dr-btn" id="dr-copy"
                    data-tip="Seed this cutoff from the previous one, continuing the rotation. Existing days are left alone.">
                <i class="ri-file-copy-line"></i> Copy last
            </button>
            <!-- Both start disabled. They act on DRAFT days, and until the grid
                 has loaded we do not know whether there are any; enabling them
                 first and disabling on load makes them flicker live-then-dead. -->
            <button class="dr-btn danger" id="dr-discard" disabled
                    data-tip="Delete every unpublished draft day in this cutoff. Published days are untouched.">
                <i class="ri-delete-bin-line"></i> Discard drafts
            </button>
            <?php if ($__can_publish): ?>
            <button class="dr-btn primary" id="dr-publish" disabled
                    data-tip="Make draft days real and notify the employees.">
                <i class="ri-send-plane-line"></i> Publish
            </button>
            <?php else: ?>
            <span class="dr-btn" style="cursor:default;opacity:.75;"
                  data-tip="Your drafts are saved here and released by the scheduling office. They are not visible to employees until then.">
                <i class="ri-draft-line"></i> Saved as drafts
            </span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($__is_admin): ?>
            <span class="dr-h-sep"></span>
            <?php /* Admin-only, always rendered regardless of $__can_edit — this
                     is the one control that has to survive the lock it sets, or
                     an admin who locks the page could never reach Unlock again. */ ?>
            <button class="dr-btn <?= $__lock['locked'] ? 'warn' : '' ?>" id="dr-lock-toggle"
                    data-lock="<?= $__lock['locked'] ? '0' : '1' ?>"
                    data-tip="<?= $__lock['locked']
                        ? 'Unlock the roster so heads and the scheduling office can edit it again.'
                        : 'Freeze this screen for everyone, including you, until you unlock it again.' ?>">
                <i class="ri-<?= $__lock['locked'] ? 'lock-unlock-line' : 'lock-line' ?>"></i>
                <?= $__lock['locked'] ? 'Unlock roster' : 'Lock roster' ?>
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="dr-body">

    <!-- Filters + palette: ONE card. They were two, and with the clash banner
         and the grid card that made four stacked white bands above the roster
         — the page read as a stack of boxes rather than a workspace. -->
    <div class="dr-controls">

    <!-- Filters -->
    <div class="dr-toolbar">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <div class="lbl"><i class="ri-calendar-2-line me-1"></i>Cutoff</div>
                <?php /* Plain <select> — the global custom-select control renders it.
                         data-cs-* only tune the menu; the native element stays the
                         source of truth, so the JS reads it exactly as before. */ ?>
                <?php /* autocomplete=off stops the browser silently restoring the previous
                         selection on reload AFTER the custom select has already rendered its
                         label — which left the control reading "— Select a department —"
                         while the grid had loaded the remembered ward. */ ?>
                <select class="form-select form-select-sm" id="dr-period" autocomplete="off"
                        data-cs-title="Cutoff period" data-cs-icon="ri-calendar-2-line" data-cs-search="true">
                    <?php foreach ($__periods as $k => $lbl): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $k === $__curPer ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?= $__areas === [] ? 'col-md-3' : 'col-md-6' ?>">
                <div class="lbl"><i class="ri-building-line me-1"></i>Department</div>
                <select class="form-select form-select-sm" id="dr-dept" autocomplete="off"
                        data-cs-title="Department" data-cs-icon="ri-building-line" data-cs-search="true">
                    <?php /* "" is "nothing chosen yet"; "0" is the real ALL option. They are
                             deliberately different values — collapsing them would make an
                             empty page load fetch every employee in the company. */ ?>
                    <?php
                    /* An area-scoped viewer never sees "all departments" — the
                       widest thing they may open is their own set of wards, so
                       the label says that instead of promising the hospital. */
                    $__allLabel = $__areas !== [] ? 'All my wards' : 'All departments';
                    ?>
                    <?php if ($__scope <= 0): ?>
                    <option value="">— Select a <?= $__areas !== [] ? 'ward' : 'department' ?> —</option>
                    <option value="0"
                            data-cs-name="<?= $__allLabel ?>"
                            data-cs-sub="<?= $__allCount ?> employees · slower to load"
                            data-cs-icon="ri-global-line"
                            data-cs-display="<?= $__allLabel ?>"><?= $__allLabel ?> (<?= $__allCount ?>)</option>
                    <?php endif; ?>
                    <?php foreach ($__depts as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"
                                data-cs-name="<?= htmlspecialchars($d['name']) ?>"
                                data-cs-sub="<?= (int) $d['n'] ?> employee<?= (int) $d['n'] === 1 ? '' : 's' ?>"
                                data-cs-display="<?= htmlspecialchars($d['name']) ?>"><?= htmlspecialchars($d['name']) ?> (<?= (int) $d['n'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($__areas === []): ?>
            <!-- Area (ward) within the chosen department — populated by duty_roster_areas
                 on department change. Only for unscoped viewers: an area-scoped session
                 already sees its own wards as the "Department" picker above (relabeled),
                 so a second area filter here would just repeat that choice. -->
            <div class="col-md-3">
                <div class="lbl"><i class="ri-map-pin-line me-1"></i>Area</div>
                <select class="form-select form-select-sm" id="dr-area" autocomplete="off" disabled
                        data-cs-title="Area" data-cs-icon="ri-map-pin-line" data-cs-search="true">
                    <option value="">All areas</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <div class="lbl"><i class="ri-search-line me-1"></i>Find employee</div>
                <input type="text" class="form-control form-control-sm" id="dr-search" placeholder="Name or ID">
            </div>

            <?php /* ── "Min per shift" — HIDDEN, not removed ────────────────────────
                 Turned off on request: it is advisory only (nothing downstream
                 reads it) and the wards did not need the warning yet.

                 The logic behind it is untouched and still live in
                 assets2/js/duty-roster.js — renderCoverage() reads $min.val(),
                 which is undefined while this input is absent, so the threshold
                 evaluates to 0 and no day is ever flagged. Nothing else changes.

                 TO BRING IT BACK: uncomment the block below and set the "Find
                 employee" column above from col-md-5 back to col-md-3, so the
                 row adds up to 12 again.

            <div class="col-md-2">
                <div class="lbl" title="Highlight a shift's coverage in red when a day falls below this many people. 0 = never warn.">
                    <i class="ri-alert-line me-1"></i>Min per shift
                </div>
                <input type="number" class="form-control form-control-sm" id="dr-min" min="0" step="1" value="0">
            </div>
            ───────────────────────────────────────────────────────────────── */ ?>
        </div>
    </div>

    <?php if ($__can_edit): ?>
    <!-- Paint palette -->
    <div class="dr-palette-row">
        <span class="dr-palette-label"><i class="ri-brush-line"></i>Paint</span>
        <div class="dr-palette" id="dr-palette"></div>
        <label class="dr-also-rest" id="dr-also-rest" data-tip="Also mark as rest day
Combine with a shift swatch: the day keeps its rest flag even though a specific shift is painted — for planned duty on someone's day off.">
            <input type="checkbox" id="dr-also-rest-chk"><i class="ri-moon-line"></i> Rest day too
        </label>
        <div class="dr-tools">
            <button type="button" class="dr-tool" id="dr-print"
                    data-tip="Print / PDF
Opens a formatted sheet in a new tab, ready for Ctrl+P — same colours and codes as the grid, plus a coverage summary.">
                <i class="ri-printer-line"></i>
            </button>
            <button type="button" class="dr-tool" id="dr-export"
                    data-tip="Export to Excel
One row per employee, one column per day — with shift dropdowns and live coverage counts.">
                <i class="ri-file-excel-2-line"></i>
            </button>
            <button type="button" class="dr-tool" id="dr-pattern"
                    data-tip="Apply a rotation
Describe the cycle once — 2 days AM, 2 days NOC, 1 day off — and it repeats across the whole cutoff for everyone shown.">
                <i class="ri-repeat-2-line"></i>
            </button>
            <button type="button" class="dr-tool" id="dr-import"
                    data-tip="Import a filled-in sheet
Nothing is saved on upload. You are shown what would change, then it is painted on the grid for you to Save and Publish as usual.">
                <i class="ri-upload-2-line"></i>
            </button>
            <!-- Reset on every open, so re-picking the SAME file still fires change. -->
            <input type="file" id="dr-import-file" accept=".xlsx,.xls" style="display:none">
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /.dr-controls -->

    <?php if ($__lock['locked']): ?>
    <!-- The admin freeze. Shown to every role, not just the ones who'd
         otherwise see edit buttons — a Supervisor with view-only access has
         no other way to learn why a head's request to fix a day is stuck. -->
    <div class="dr-recompute" style="margin:0 0 8px;border-color:#f0cfcc;background:linear-gradient(90deg,#fdefee,#fff7f6);">
        <i class="ri-lock-2-line" style="color:#a5322d;font-size:18px;"></i>
        <div class="t" style="color:#8a2c28;">
            <b>Locked by the administrator.</b> No changes can be made on this screen right now.
            <?php if ($__lock['by']): ?>
                Locked by <?= htmlspecialchars($__lock['by']) ?><?= $__lock['at'] ? ' on ' . htmlspecialchars($__lock['at']) : '' ?>.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Shown when this cutoff is empty but the same people have days elsewhere -->
    <div class="dr-elsewhere d-none" id="dr-elsewhere">
        <i class="ri-compass-3-line" style="color:#1565c0;font-size:17px;"></i>
        <div class="t">Nothing planned in this cutoff. This group has duty days in:</div>
        <div class="d-flex gap-2 flex-wrap" id="dr-elsewhere-links"></div>
    </div>

    <!-- Rostered on a day they have approved leave -->
    <div class="dr-clash d-none" id="dr-clash">
        <i class="ri-calendar-close-line" style="color:#c62828;font-size:17px;"></i>
        <div class="t" id="dr-clash-text"></div>
    </div>

    <!-- Shown after a save/publish that touched days already punched -->
    <div class="dr-recompute d-none" id="dr-recompute-bar" style="margin:0;">
        <i class="ri-error-warning-line" style="color:#ad6800;font-size:18px;"></i>
        <div class="t" id="dr-recompute-text"></div>
        <?php if (!function_exists('action_allowed') || action_allowed('duty_roster_recompute')): ?>
        <button class="dr-btn warn ms-auto" id="dr-recompute-btn"><i class="ri-refresh-line"></i> Recompute now</button>
        <?php endif; ?>
    </div>

    <div class="dr-scroll" id="dr-scroll">
        <table class="dr-grid" id="dr-grid">
            <thead></thead>
            <tbody></tbody>
            <tfoot></tfoot>
        </table>
    </div>
    <div class="dr-placeholder d-none" id="dr-placeholder">
        <i class="ri-inbox-line d-block mb-2" style="font-size:28px;"></i>
        Choose a department to start planning this cutoff.
    </div>
</div>

<div class="dr-foot">
    <div class="dr-legend">
        <span class="dr-legend-title">Key</span>
        <span data-tip="Rest day
Their day off. It is a plotted day, not a blank — the roster is saying they are off, rather than saying nothing. Tick &quot;Rest day too&quot; while painting a shift to keep the rest flag AND assign a shift — for planned duty on someone's day off.">
            <span class="lg-sw dr-cell dr-rest"></span> Rest day</span>
        <span data-tip="Rest day + shift
Both at once — a shift is on file for a day that is still marked as their rest day, e.g. planned duty on someone's day off. Painted with the &quot;Rest day too&quot; toggle.">
            <span class="lg-sw dr-cell dr-rest dr-rest-shift">6-2</span> + Shift</span>
        <span data-tip="Not planned
No entry on the day grid for this date, so their fixed shift from the Shift Roster applies.">
            <span class="lg-sw dr-cell dr-empty"></span> Not planned</span>
        <span data-tip="Already punched
Attendance is recorded on this day. You can still change it, but the DTR figures were frozen under the old shift and will need a Recompute.">
            <span class="lg-sw dr-cell dr-punched"></span> Punched</span>
        <span data-tip="Locked
The DTR batch covering this day is approved. Changes here are refused — by the grid, and by the importer.">
            <span class="lg-sw dr-cell dr-locked"></span> Locked</span>
        <span data-tip="Draft
Saved but not published. Employees cannot see it, and it does not reach DTR figures until you press Publish.">
            <span class="lg-sw dr-cell dr-draft"></span> Draft</span>
        <span data-tip="On approved leave
Approved leave on this day, with nothing rostered against it — a free day or a rest day. Nothing to fix; it is here so a planner filling the column can see who is already away.">
            <span class="lg-sw dr-cell dr-empty dr-onleave"><i class="dr-lv"></i></span> On leave</span>
        <span data-tip="Leave clash
A SHIFT is planned on a day this person already has approved leave. They will not come in, so the day lands in the DTR as an absence against a shift nobody was going to work. Change the shift to OFF, or cancel the leave.">
            <span class="lg-sw dr-cell dr-leaveclash"></span> Leave clash</span>
        <span data-tip="Rest clash
Less than 8 hours between the end of the previous day's shift and the start of this one — a fatigue risk, not just a scheduling oddity.">
            <span class="lg-sw dr-cell dr-restclash"></span> Rest clash</span>
    </div>
    <?php if ($__can_edit): ?>
    <div class="dr-hint ms-auto">
        <i class="ri-cursor-line"></i>
        <span>Drag to paint · click a <kbd>date</kbd> to fill a column · <kbd>&#8942;</kbd> beside a name to fill a row</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($__can_edit): ?>
<!-- Unsaved-changes bar -->
<?php if ($__can_edit): ?>
<!-- ── Rotation pattern ────────────────────────────────────────────────────
     Painting a cutoff by hand is 15 clicks per nurse; a ward of 40 is 600.
     But a rotation is a SHORT repeating cycle, so the whole sheet is really
     one sentence: "2 days AM, 2 days NOC, 1 off". This describes that once.

     The stagger is the part that makes it a roster rather than a copy: with
     every nurse on the same cycle the whole ward works and rests together and
     coverage collapses. Offsetting each one down the cycle is what spreads
     them out. -->
<div class="dr-modal" id="dr-pattern-modal">
    <div class="dr-modal-box">
        <div class="dr-modal-head">
            <div>
                <div class="t"><i class="ri-repeat-2-line"></i> Apply a rotation</div>
                <div class="s">Describe one cycle. It repeats across the cutoff.</div>
            </div>
            <button type="button" class="dr-modal-x" id="dr-pat-close"><i class="ri-close-line"></i></button>
        </div>

        <div class="dr-modal-body">
            <div class="dr-pat-lbl">The cycle</div>
            <div id="dr-pat-steps" class="dr-pat-steps"></div>
            <button type="button" class="dr-btn" id="dr-pat-add" style="margin-top:6px;">
                <i class="ri-add-line"></i> Add step
            </button>

            <div class="dr-pat-row">
                <div>
                    <div class="dr-pat-lbl">Stagger</div>
                    <select class="form-select form-select-sm" id="dr-pat-stagger">
                        <option value="1" selected>Shift each employee 1 day down the cycle</option>
                        <option value="2">Shift each employee 2 days down</option>
                        <option value="0">No stagger — everyone identical</option>
                    </select>
                    <div class="dr-pat-help" id="dr-pat-stagger-help"></div>
                </div>
                <div>
                    <div class="dr-pat-lbl">Days already planned</div>
                    <select class="form-select form-select-sm" id="dr-pat-overwrite">
                        <option value="0" selected>Leave them alone</option>
                        <option value="1">Overwrite them</option>
                    </select>
                    <div class="dr-pat-help">Locked days are never touched either way.</div>
                </div>
            </div>

            <div class="dr-pat-preview" id="dr-pat-preview"></div>
        </div>

        <div class="dr-modal-foot">
            <span class="dr-pat-scope" id="dr-pat-scope"></span>
            <button type="button" class="dr-btn" id="dr-pat-cancel">Cancel</button>
            <button type="button" class="dr-btn primary" id="dr-pat-apply">
                <i class="ri-brush-line"></i> Paint it on
            </button>
        </div>
    </div>
</div>

<!-- Print preview — the same PDF print-duty-roster.php would open in a new
     tab, shown here in a full-width modal instead so the roster stays open
     underneath. -->
<div class="dr-modal" id="dr-print-modal">
    <div class="dr-modal-box dr-modal-wide">
        <div class="dr-modal-head">
            <div>
                <div class="t"><i class="ri-printer-line"></i> Print Preview</div>
                <div class="s">Ready for Ctrl+P — the roster's own copy, colours and all.</div>
            </div>
            <a href="#" id="dr-print-open" target="_blank" class="dr-modal-x" title="Open in a new tab"><i class="ri-external-link-line"></i></a>
            <button type="button" class="dr-modal-x" id="dr-print-close" title="Close"><i class="ri-close-line"></i></button>
        </div>
        <div class="dr-modal-body dr-modal-body-pdf">
            <iframe id="dr-print-frame" src="" title="Duty roster print preview"></iframe>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="dr-dirtybar" id="dr-dirtybar">
    <span class="n" id="dr-dirty-count">0</span>
    <span style="font-size:13px;font-weight:600;color:#28223b;">unsaved day(s)</span>
    <button class="dr-btn" id="dr-revert">Revert</button>
    <button class="dr-btn primary" id="dr-save"><i class="ri-save-line"></i> Save</button>
</div>
<?php endif; ?>

<?php
// Shared employee quick-view drawer — the grid's name links carry
// data-emp-quickview, and this component binds them globally. Standalone pages
// include it themselves; index.php does it for the routed ones.
include 'component/employee_quick_view.php';
?>

<script>
    // Whether this role may paint. The palette and buttons are already absent
    // for read-only roles; the flag stops the JS from binding writes at all.
    window.DR_CAN_EDIT = <?= $__can_edit ? 'true' : 'false' ?>;
    // The name link opens the employee quick view, which is a different screen
    // with its own permission. A role that may read this grid is not thereby
    // allowed to read salaries, so the link is only rendered when the endpoint
    // behind it would actually answer.
    window.DR_CAN_QUICKVIEW = <?= (function_exists('action_allowed') && action_allowed('employee_quick_view')) ? 'true' : 'false' ?>;

    <?php if ($__is_admin): ?>
    // Admin lock/unlock. A full reload afterward is deliberate: locking (or
    // unlocking) changes which buttons this page even renders server-side —
    // patching the DOM in place would have to duplicate that logic here.
    jQuery(function ($) {
        $('#dr-lock-toggle').on('click', function () {
            var $b = $(this), lock = $b.data('lock');
            Swal.fire({
                icon: lock ? 'warning' : 'question',
                title: lock ? 'Lock the duty roster?' : 'Unlock the duty roster?',
                text: lock
                    ? 'No one — including you — will be able to paint, save, publish, copy, import or recompute until you unlock it again.'
                    : 'Heads and the scheduling office will be able to edit this cutoff again.',
                showCancelButton: true,
                confirmButtonText: lock ? 'Lock it' : 'Unlock it',
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $b.prop('disabled', true);
                $.post('ajax.php?action=duty_roster_set_lock', { lock: lock })
                    .done(function (res) {
                        var j = res;
                        try { if (typeof res === 'string') j = JSON.parse(res); } catch (e) { j = null; }
                        if (!j || !j.result) {
                            Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Request failed.' });
                            $b.prop('disabled', false);
                            return;
                        }
                        window.location.reload();
                    })
                    .fail(function () {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' });
                        $b.prop('disabled', false);
                    });
            });
        });
    });
    <?php endif; ?>
</script>
<script src="<?= av('assets2/js/custom-select.js') ?>"></script>
<script src="<?= av('assets2/js/duty-roster.js') ?>"></script>
</body>
</html>
