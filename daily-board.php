<?php
// Daily Attendance Board — pick a date, see who's on which shift and whether they've
// actually clocked in yet, grouped by Shift (default) or Department.
//
// All the reasoning lives in includes/daily_board_data.php, which the CSV export
// (export-daily-board.php) reads too. This file is presentation only.

require_once __DIR__ . '/includes/daily_board_data.php';

$target_date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$B = daily_board_data($conn, $target_date);

$target_date = $B['date'];
$is_future   = $B['is_future'];
$is_today    = $B['is_today'];
$employees   = $B['employees'];
$summary     = $B['summary'];
$shift_rows  = $B['shift_rows'];
$holiday     = $B['holiday'];

$pending_total = 0;
foreach ($employees as $r) if (!empty($r['pending'])) $pending_total += $r['pending']['n'];

// Group by shift (ordered by start time, unassigned last)
$by_shift = [];
foreach ($shift_rows as $s) $by_shift[$s['id']] = ['label' => $s['description'], 'time' => date('h:i A', strtotime($s['start_time'])) . ' – ' . date('h:i A', strtotime($s['end_time'])), 'employees' => []];
$by_shift['__unassigned__'] = ['label' => 'Unassigned', 'time' => '', 'employees' => []];
foreach ($employees as $r) {
    $key = ($r['shift_id'] && isset($by_shift[$r['shift_id']])) ? $r['shift_id'] : '__unassigned__';
    $by_shift[$key]['employees'][] = $r;
}

// Group by department (alphabetical, unassigned last)
$by_dept = [];
foreach ($employees as $r) {
    $key = $r['dept_name'] ?: '__unassigned__';
    if (!isset($by_dept[$key])) $by_dept[$key] = ['label' => $r['dept_name'] ?: 'Unassigned', 'time' => '', 'employees' => []];
    $by_dept[$key]['employees'][] = $r;
}
ksort($by_dept);
if (isset($by_dept['__unassigned__'])) { $u = $by_dept['__unassigned__']; unset($by_dept['__unassigned__']); $by_dept['__unassigned__'] = $u; }

// The group key each employee belongs to in either mode — the cards are rendered
// once and moved between the two sets of group heads by daily-board.js.
$shift_key_of = [];
$dept_key_of  = [];
foreach ($by_shift as $k => $g) foreach ($g['employees'] as $e) $shift_key_of[$e['id']] = $k;
foreach ($by_dept as $k => $g)  foreach ($g['employees'] as $e) $dept_key_of[$e['id']]  = $k;

function board_initials($r)
{
    return strtoupper(substr($r['firstname'], 0, 1)) . strtoupper(substr($r['lastname'], 0, 1));
}
function board_name($r)
{
    return htmlspecialchars($r['lastname'] . ', ' . $r['firstname'] . ($r['middlename'] ? ' ' . substr($r['middlename'], 0, 1) . '.' : ''));
}
?>
<style>
    .db-toolbar { background:linear-gradient(135deg,#f2f0f6 0%,#ebe7f2 100%); border:1px solid #dad4e5; border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .db-nav-btn { width:34px; height:34px; border-radius:50%; border:1px solid #dad4e5; background:#fff; color:#673bb6; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:background .15s,color .15s,box-shadow .15s; }
    .db-nav-btn:hover { background:#673bb6; color:#fff; box-shadow:0 2px 6px rgba(103,59,182,.35); }
    .db-date-pill { display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #dad4e5; border-radius:20px; padding:6px 16px; font-weight:700; color:#57339d; cursor:pointer; font-size:14px; min-width:190px; justify-content:center; transition:border-color .15s, box-shadow .15s; }
    .db-date-pill:hover { border-color:#673bb6; box-shadow:0 2px 6px rgba(103,59,182,.15); }
    .db-today-badge { font-size:10px; background:#673bb6; color:#fff; border-radius:10px; padding:1px 8px; margin-left:6px; vertical-align:middle; }
    /* daterangepicker theme now lives globally in assets2/css/custom-select.css */
    .db-group-toggle .btn { padding:4px 10px; }
    .db-group-toggle .btn.active { background:#673bb6; border-color:#673bb6; color:#fff; }

    .db-search { position:relative; }
    .db-search i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#a397ba; font-size:14px; pointer-events:none; }
    .db-search input { border:1px solid #dad4e5; border-radius:20px; padding:6px 12px 6px 32px; font-size:12.5px; width:220px; outline:none; background:#fff; transition:border-color .15s, box-shadow .15s; }
    .db-search input:focus { border-color:#673bb6; box-shadow:0 0 0 3px rgba(103,59,182,.12); }

    .db-note { font-size:11px; border-radius:20px; padding:4px 12px; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
    .db-note-lock { color:#ad6800; background:#fffbe6; border:1px solid #ffe58f; }
    .db-note-hol  { color:#08979c; background:#e6fffb; border:1px solid #87e8de; font-weight:700; }
    .db-note-req  { color:#096dd9; background:#e6f7ff; border:1px solid #91d5ff; text-decoration:none; }
    .db-note-req:hover { color:#0050b3; border-color:#69c0ff; }

    .db-asof { font-size:11px; color:#666; display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
    .db-live { font-size:11px; color:#57339d; font-weight:700; display:inline-flex; align-items:center; gap:5px; background:#fff; border:1px solid #dad4e5; border-radius:20px; padding:3px 11px; cursor:pointer; user-select:none; }
    .db-live input { margin:0; cursor:pointer; }
    .db-live-dot { width:6px; height:6px; border-radius:50%; background:#c9c4d4; }
    .db-live.on .db-live-dot { background:#2eb872; box-shadow:0 0 0 3px rgba(46,184,114,.18); }

    .db-rate { display:flex; align-items:center; gap:10px; margin:12px 2px 0; }
    .db-rate-track { flex:1; height:7px; background:#eceff3; border-radius:4px; overflow:hidden; }
    .db-rate-fill { height:100%; border-radius:4px; background:linear-gradient(90deg,#6f47b5,#6339af); transition:width .4s ease; }
    .db-rate-lbl { font-size:11px; font-weight:700; color:#57339d; white-space:nowrap; }

    .db-summary { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0 16px; }
    .db-sum-card { flex:1; min-width:120px; border-radius:10px; padding:10px 12px; text-align:center; border:1px solid; cursor:pointer; user-select:none; position:relative; transition:transform .12s, box-shadow .12s; }
    .db-sum-card:hover { transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,.08); }
    .db-sum-card.filter-on { box-shadow:0 0 0 2px currentColor inset, 0 4px 10px rgba(0,0,0,.08); }
    .db-sum-ico { font-size:15px; opacity:.75; }
    .db-sum-val { font-size:21px; font-weight:800; line-height:1.1; }
    .db-sum-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.4px; font-weight:700; margin-top:2px; }
    .db-sum-card.success { background:#f0fbf5; border-color:#b7ebc6; color:#1a7f37; }
    .db-sum-card.warning { background:#fffbe6; border-color:#ffe58f; color:#ad6800; }
    .db-sum-card.attn    { background:#fff7e6; border-color:#ffd591; color:#d46b08; }
    .db-sum-card.danger  { background:#fff1f0; border-color:#ffccc7; color:#cf1322; }
    .db-sum-card.info    { background:#e6f7ff; border-color:#91d5ff; color:#096dd9; }
    .db-sum-card.secondary { background:#f5f5f5; border-color:#e0e0e0; color:#666; }
    .db-sum-card.leave { background:#f3e8ff; border-color:#dcc6fa; color:#7c3aed; }
    .db-sum-card.holiday { background:#e6fffb; border-color:#87e8de; color:#08979c; }

    .db-group { margin-bottom:16px; }
    .db-group-head { position:relative; display:flex; align-items:center; gap:8px; background:linear-gradient(135deg,#f2f0f6,#edeaf3); border:1px solid #dad4e5; border-radius:8px; padding:7px 12px 9px; cursor:pointer; user-select:none; overflow:hidden; transition:border-color .15s, box-shadow .15s, background .15s; }
    .db-group-head:hover { border-color:#c3b3e2; box-shadow:0 2px 8px rgba(103,59,182,.12); }
    .db-group-head:focus-visible { outline:2px solid #673bb6; outline-offset:2px; }
    .db-group-chevron { color:#8b7bb0; font-size:16px; line-height:1; flex-shrink:0; transition:transform .25s ease, color .15s; }
    .db-group-head:hover .db-group-chevron { color:#673bb6; }
    .db-group.collapsed .db-group-chevron { transform:rotate(-90deg); }
    .db-group-title { font-size:13px; font-weight:700; color:#57339d; }
    .db-group-time { font-size:11px; color:#746491; white-space:nowrap; }
    .db-group-in { font-size:11px; font-weight:700; color:#1a7f37; white-space:nowrap; }
    .db-group-count { flex-shrink:0; }
    .db-group-stats { display:flex; align-items:center; gap:5px; margin-left:auto; flex-wrap:wrap; justify-content:flex-end; }
    .db-stat-chip { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; line-height:1; padding:3px 8px; border-radius:10px; background:#fff; border:1px solid #e3ddee; color:#6b6580; white-space:nowrap; }
    .db-stat-chip .db-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
    .db-stat-chip.success .db-dot { background:#2eb872; } .db-stat-chip.success { color:#1a7f37; border-color:#c9ecd8; }
    .db-stat-chip.warning .db-dot { background:#f0a800; } .db-stat-chip.warning { color:#ad6800; border-color:#f6e4b5; }
    .db-stat-chip.attn    .db-dot { background:#fa8c16; } .db-stat-chip.attn    { color:#d46b08; border-color:#ffd591; }
    .db-stat-chip.danger  .db-dot { background:#e5484d; } .db-stat-chip.danger  { color:#cf1322; border-color:#f7cfcf; }
    .db-stat-chip.info    .db-dot { background:#3a9bdc; } .db-stat-chip.info    { color:#096dd9; border-color:#c9e4f7; }
    .db-stat-chip.secondary .db-dot { background:#b9bec9; }
    .db-stat-chip.leave .db-dot { background:#7c3aed; } .db-stat-chip.leave { color:#7c3aed; border-color:#dcc6fa; }
    .db-stat-chip.holiday .db-dot { background:#13c2c2; } .db-stat-chip.holiday { color:#08979c; border-color:#87e8de; }
    .db-group-bar { position:absolute; left:0; right:0; bottom:0; height:3px; background:rgba(103,59,182,.10); }
    .db-group-bar-fill { height:100%; background:linear-gradient(90deg,#6f47b5,#2eb872); transition:width .4s ease; }

    /* Collapse animation — 1fr → 0fr keeps the natural height without measuring it in JS */
    .db-group-body { display:grid; grid-template-rows:1fr; margin-top:8px; transition:grid-template-rows .28s ease, opacity .2s ease, margin-top .28s ease; }
    .db-group-body > .db-group-body-inner { overflow:hidden; min-height:0; }
    .db-group.collapsed .db-group-body { grid-template-rows:0fr; opacity:0; margin-top:0; }
    .db-group.no-anim .db-group-body { transition:none; }

    .db-collapse-toggle .btn { padding:4px 10px; }

    .db-card { border:1px solid #e2e5ee; border-left:3px solid #e2e5ee; border-radius:8px; padding:10px 12px; background:#fff; height:100%; transition:box-shadow .15s, transform .15s; }
    .db-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.10); transform:translateY(-1px); }
    .db-card.st-success { border-left-color:#2eb872; }
    .db-card.st-warning { border-left-color:#f0a800; }
    .db-card.st-attn    { border-left-color:#fa8c16; }
    .db-card.st-danger  { border-left-color:#e5484d; }
    .db-card.st-info    { border-left-color:#3a9bdc; }
    .db-card.st-secondary { border-left-color:#c5c9d3; }
    .db-card.st-leave { border-left-color:#7c3aed; }
    .db-card.st-holiday { border-left-color:#13c2c2; }
    .db-card-top { display:flex; align-items:center; gap:8px; }
    .db-avatar { width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#6f47b5,#5d36a6); color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .db-card.st-danger .db-avatar { background:linear-gradient(135deg,#adb5bd,#868e96); }
    .db-name { font-size:12.5px; font-weight:700; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .db-name-link { color:#1a1a1a; text-decoration:none; }
    .db-name-link:hover { color:#5d36a6; text-decoration:underline; }
    a.db-avatar:hover { box-shadow:0 0 0 3px rgba(103,59,182,.25); text-decoration:none; color:#fff; }
    .db-sub { font-size:10.5px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    /* One card, two captions: the sub-line names whichever axis you are NOT grouped by. */
    .db-sub-shift { display:none; }
    #db-board.mode-dept .db-sub-shift { display:inline; }
    #db-board.mode-dept .db-sub-dept  { display:none; }
    .db-tag { font-size:9.5px; color:#1976d2; }
    .db-status-row { margin-top:8px; padding-top:7px; border-top:1px dashed #eef0f8; display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:wrap; }
    .db-status-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; display:inline-flex; align-items:center; gap:3px; }
    .db-status-badge.success { background:#e6f9ee; color:#1a7f37; }
    .db-status-badge.warning { background:#fff7e0; color:#ad6800; }
    .db-status-badge.attn    { background:#fff7e6; color:#d46b08; }
    .db-status-badge.danger  { background:#ffefee; color:#cf1322; }
    .db-status-badge.info    { background:#e6f4ff; color:#096dd9; }
    .db-status-badge.secondary { background:#f0f0f0; color:#666; }
    .db-status-badge.leave { background:#f3e8ff; color:#7c3aed; }
    .db-status-badge.holiday { background:#e6fffb; color:#08979c; }
    .db-chip { font-size:9.5px; font-weight:700; padding:1px 6px; border-radius:8px; white-space:nowrap; }
    .db-ot-chip { background:#f3e8ff; color:#7c3aed; }
    .db-ut-chip { background:#fff1f0; color:#cf1322; }
    .db-late-chip { background:#fff7e0; color:#ad6800; }
    .db-pend-chip { background:#e6f7ff; color:#096dd9; }
    .db-leave-chip { background:#f0f0f0; color:#666; }
    .db-in-out { font-size:10px; color:#666; white-space:nowrap; }
    .db-no-match { display:none; text-align:center; color:#999; padding:28px 0; font-size:13px; }

    .db-adjust-btn { flex-shrink:0; width:24px; height:24px; border-radius:6px; border:1px solid #e2ddef; background:#faf9fc; color:#8b7bb0; display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:background .15s,color .15s,border-color .15s; }
    .db-adjust-btn:hover { background:#673bb6; color:#fff; border-color:#673bb6; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-dashboard-3-line me-2 text-success"></i>Daily Attendance Board</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Daily Board</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex gap-2 flex-wrap">
                        <h4 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-check-line me-2 text-success"></i>Shift Lineup</h4>
                        <div class="db-search">
                            <i class="ri-search-line"></i>
                            <input type="text" id="db-search-input" placeholder="Search name, ID, dept…" autocomplete="off">
                        </div>
                        <div class="btn-group db-group-toggle" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="btn-group-shift"><i class="ri-time-line"></i> Shift</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-group-dept"><i class="ri-building-3-line"></i> Department</button>
                        </div>
                        <div class="btn-group db-collapse-toggle" role="group">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-expand-all" title="Expand all groups"><i class="ri-arrow-down-s-line"></i> Expand</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-collapse-all" title="Collapse all groups"><i class="ri-arrow-up-s-line"></i> Collapse</button>
                        </div>
                        <a href="export-daily-board.php?date=<?= urlencode($target_date) ?>" id="db-export" class="btn btn-sm btn-outline-success" title="Download what is currently on screen as CSV"><i class="ri-file-excel-2-line"></i> Export</a>
                    </div>
                    <div class="card-body">

                        <!-- Date navigation -->
                        <div class="db-toolbar mb-2">
                            <a href="index.php?page=daily-board&date=<?= $B['prev_date'] ?>" class="db-nav-btn" id="db-prev-day" title="Previous day (←)"><i class="ri-arrow-left-s-line"></i></a>
                            <div class="db-date-pill" style="min-width: 250px;" id="db-date-pill" title="Click to pick a date">
                                <i class="ri-calendar-2-line"></i>
                                <span id="db-date-label"><?= date('l, F j, Y', strtotime($target_date)) ?></span>
                                <?php if ($is_today): ?><span class="db-today-badge">TODAY</span><?php endif; ?>
                            </div>
                            <a href="index.php?page=daily-board&date=<?= $B['next_date'] ?>" class="db-nav-btn" id="db-next-day" title="Next day (→)"><i class="ri-arrow-right-s-line"></i></a>
                            <input type="hidden" id="db-date-input" value="<?= htmlspecialchars($target_date) ?>">
                            <?php if (!$is_today): ?>
                            <a href="index.php?page=daily-board" class="btn btn-sm btn-outline-secondary"><i class="ri-calendar-check-line me-1"></i>Today</a>
                            <?php endif; ?>
                            <?php if ($holiday): ?>
                            <span class="db-note db-note-hol" title="Nobody is expected to clock in on this date"><i class="ri-flag-2-line"></i><?= htmlspecialchars($holiday['title']) ?> · <?= htmlspecialchars($holiday['kind']) ?></span>
                            <?php endif; ?>
                            <?php if ($pending_total > 0): ?>
                            <a href="index.php?page=attendance-requests" class="db-note db-note-req" title="Attendance corrections awaiting review for this date"><i class="ri-file-edit-line"></i><?= $pending_total ?> pending request<?= $pending_total > 1 ? 's' : '' ?></a>
                            <?php endif; ?>
                            <?php if ($B['duty_locked']): ?>
                            <span class="db-note db-note-lock"><i class="ri-lock-2-line"></i>Duty roster is locked by the administrator</span>
                            <?php endif; ?>
                            <div class="ms-auto d-flex align-items-center gap-2" style="font-size:11px;color:#666;">
                                <span><i class="ri-team-line me-1"></i><?= count($employees) ?> active employees</span>
                                <?php if ($is_today): ?>
                                <span class="db-asof"><i class="ri-time-line"></i>as of <?= date('h:i A') ?></span>
                                <a href="javascript:void(0);" class="db-nav-btn" id="db-refresh" title="Refresh now" style="width:26px;height:26px;"><i class="ri-refresh-line"></i></a>
                                <label class="db-live" id="db-live-wrap" title="Reload the board every 2 minutes. Pauses while you are searching or filtering.">
                                    <span class="db-live-dot"></span>
                                    <input type="checkbox" id="db-auto-refresh"> Live
                                </label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$is_future): ?>
                        <!-- Attendance rate -->
                        <div class="db-rate">
                            <div class="db-rate-track"><div class="db-rate-fill" style="width:<?= $B['att_rate'] ?>%;"></div></div>
                            <span class="db-rate-lbl"><?= $B['attended'] ?> of <?= $B['expected'] ?> clocked in · <?= $B['att_rate'] ?>%</span>
                        </div>
                        <?php endif; ?>

                        <!-- Day summary (click a card to filter the board by that status) -->
                        <div class="db-summary">
                            <?php
                            // Which cards are worth a slot: the three headline
                            // statuses always show on a past/current day (a zero
                            // Absent count is itself the news), the rest only when
                            // they actually happened.
                            $always = $is_future ? ['Scheduled'] : ['Present', 'Late', 'Absent'];
                            foreach (DAILY_BOARD_STATUSES as $label => $meta):
                                if ($is_future && !in_array($label, ['Scheduled', 'Day Off', 'On Leave', 'Holiday'], true)) continue;
                                if (!$is_future && $label === 'Scheduled') continue;
                                if ($label === 'Not Yet Due' && !$is_today) continue;
                                if (!in_array($label, $always, true) && $summary[$label] < 1) continue;
                            ?>
                            <div class="db-sum-card <?= $meta['class'] ?>" data-filter="<?= htmlspecialchars($label) ?>">
                                <div class="db-sum-ico"><i class="<?= $meta['icon'] ?>"></i></div>
                                <div class="db-sum-val"><?= $summary[$label] ?></div>
                                <div class="db-sum-lbl"><?= htmlspecialchars($label) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        /**
                         * One group. $withCards is false for the Department heads,
                         * which start empty — daily-board.js moves the single set of
                         * rendered cards between the two group sets on toggle, so a
                         * 400-employee board never renders 800 cards.
                         */
                        function board_render_group($gkey, $group, $isShiftGroup, $withCards)
                        {
                            if (empty($group['employees'])) return;
                            $total = count($group['employees']);
                            $in_count = 0;
                            $counts = [];
                            foreach ($group['employees'] as $e) {
                                $lbl = $e['att']['label'];
                                $counts[$lbl] = ($counts[$lbl] ?? 0) + 1;
                                if (in_array($lbl, DAILY_BOARD_IN_STATUSES, true)) $in_count++;
                            }
                            $bar_pct = $total > 0 ? round($in_count / $total * 100) : 0;
                            ?>
                            <div class="db-group" data-group-key="<?= htmlspecialchars($gkey) ?>">
                                <div class="db-group-head" role="button" tabindex="0" aria-expanded="true" title="Click to collapse / expand">
                                    <i class="ri-arrow-down-s-line db-group-chevron"></i>
                                    <i class="ri-<?= $isShiftGroup ? 'time' : 'building-3' ?>-line" style="color:#673bb6;"></i>
                                    <span class="db-group-title"><?= htmlspecialchars($group['label']) ?></span>
                                    <?php if (!empty($group['time'])): ?><span class="db-group-time"><?= htmlspecialchars($group['time']) ?></span><?php endif; ?>
                                    <span class="db-group-stats">
                                        <?php foreach (DAILY_BOARD_STATUSES as $lbl => $meta): ?>
                                        <span class="db-stat-chip <?= $meta['class'] ?><?= empty($counts[$lbl]) ? ' d-none' : '' ?>" data-chip-status="<?= htmlspecialchars($lbl) ?>" title="<?= htmlspecialchars($lbl) ?>"><span class="db-dot"></span><span class="db-chip-val"><?= (int) ($counts[$lbl] ?? 0) ?></span> <?= htmlspecialchars($lbl) ?></span>
                                        <?php endforeach; ?>
                                        <span class="db-group-in"><i class="ri-user-follow-line"></i> <?= $in_count ?> in</span>
                                        <span class="badge bg-secondary db-group-count"><?= $total ?></span>
                                    </span>
                                    <span class="db-group-bar"><span class="db-group-bar-fill" style="width:<?= $bar_pct ?>%;"></span></span>
                                </div>
                                <div class="db-group-body"><div class="db-group-body-inner">
                                <div class="row g-2">
                                    <?php if (!$withCards) { echo '</div></div></div></div>'; return; } ?>
                                    <?php foreach ($group['employees'] as $r): $att = $r['att'];
                                        $search = strtolower($r['lastname'] . ' ' . $r['firstname'] . ' ' . $r['employee_no'] . ' ' . ($r['dept_name'] ?? '') . ' ' . ($r['shift_desc'] ?? '')); ?>
                                    <div class="col-6 col-sm-4 col-md-3 col-xl-2 db-card-col"
                                         data-status="<?= htmlspecialchars($att['label']) ?>"
                                         data-search="<?= htmlspecialchars($search) ?>"
                                         data-ord="<?= (int) $GLOBALS['board_ord'][$r['id']] ?>"
                                         data-shift-key="<?= htmlspecialchars((string) $GLOBALS['board_shift_key'][$r['id']]) ?>"
                                         data-dept-key="<?= htmlspecialchars((string) $GLOBALS['board_dept_key'][$r['id']]) ?>">
                                        <div class="db-card st-<?= $att['class'] ?>">
                                            <div class="db-card-top">
                                                <!-- Quick-view drawer first; the full employee-details page
                                                     is one more click away inside the drawer. -->
                                                <a href="javascript:void(0);" data-emp-quickview="<?= (int)$r['id'] ?>" class="db-avatar" title="Employee quick view"><?= board_initials($r) ?></a>
                                                <div style="min-width:0;flex:1;">
                                                    <div class="db-name" title="<?= board_name($r) ?>"><a href="javascript:void(0);" data-emp-quickview="<?= (int)$r['id'] ?>" class="db-name-link" title="Employee quick view"><?= board_name($r) ?></a></div>
                                                    <div class="db-sub">
                                                        <span class="db-sub-dept"><?= !empty($r['dept_name']) ? htmlspecialchars($r['dept_name']) : '<span class="text-muted">No dept</span>' ?></span>
                                                        <span class="db-sub-shift"><?= $r['shift_desc'] ? htmlspecialchars($r['shift_desc']) : '<span class="text-muted">No shift</span>' ?></span>
                                                    </div>
                                                    <div class="db-tag"><?= htmlspecialchars($r['employee_no']) ?></div>
                                                </div>
                                                <?php if (!empty($r['can_adjust'])): ?>
                                                <button type="button" class="db-adjust-btn" data-adjust-emp="<?= (int)$r['id'] ?>" data-adjust-shift="<?= (int)($r['shift_id'] ?? 0) ?>" data-adjust-rest="<?= (int)$r['is_rest_day'] ?>" data-adjust-name="<?= board_name($r) ?>" title="Adjust duty for this date"><i class="ri-edit-2-line"></i></button>
                                                <?php endif; ?>
                                            </div>
                                            <div class="db-status-row">
                                                <span class="db-status-badge <?= $att['class'] ?>"><i class="<?= $att['icon'] ?>"></i><?= htmlspecialchars($att['label']) ?><?php if ($att['label'] === 'Late' && !empty($att['late'])): ?> <?= daily_board_hm((float) $att['late']) ?><?php endif; ?></span>
                                                <?php if ($att['label'] !== 'Late' && !empty($att['late'])): ?><span class="db-chip db-late-chip" title="Late">Late <?= daily_board_hm((float) $att['late']) ?></span><?php endif; ?>
                                                <?php if (!empty($att['ut'])): ?><span class="db-chip db-ut-chip" title="Undertime">UT <?= daily_board_hm((float) $att['ut']) ?></span><?php endif; ?>
                                                <?php if (!empty($att['ot'])): ?><span class="db-chip db-ot-chip" title="Overtime">OT <?= daily_board_hm((float) $att['ot']) ?></span><?php endif; ?>
                                                <?php if (!empty($att['leave_name'])): ?><span class="db-chip db-leave-chip"><?= htmlspecialchars($att['leave_name']) ?><?= !empty($att['leave_half']) ? ' (' . htmlspecialchars($att['leave_part']) . ')' : '' ?></span><?php endif; ?>
                                                <?php if (!empty($r['pending'])): ?><span class="db-chip db-pend-chip" title="<?= (int) $r['pending']['n'] ?> pending attendance request(s): <?= htmlspecialchars($r['pending']['types']) ?>"><i class="ri-file-edit-line"></i> <?= (int) $r['pending']['n'] ?></span><?php endif; ?>
                                                <?php if ($att['in']): ?>
                                                <span class="db-in-out"><?= date('h:i A', strtotime($att['in'])) ?><?= $att['out'] ? ' – ' . date('h:i A', strtotime($att['out'])) : '' ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                </div></div>
                            </div>
                            <?php
                        }

                        // Lookups the card renderer above reads (it is called from
                        // inside a function, so they are passed through globals).
                        $GLOBALS['board_shift_key'] = $shift_key_of;
                        $GLOBALS['board_dept_key']  = $dept_key_of;
                        $GLOBALS['board_ord']       = [];
                        foreach ($employees as $i => $r) $GLOBALS['board_ord'][$r['id']] = $i;
                        ?>

                        <div id="db-board" class="mode-shift">
                            <!-- Cards are rendered once, here. -->
                            <div class="db-board-mode" data-mode="shift">
                                <?php foreach ($by_shift as $gkey => $group) board_render_group($gkey, $group, true, true); ?>
                            </div>
                            <!-- Department heads only; the cards above move in on toggle. -->
                            <div class="db-board-mode d-none" data-mode="dept">
                                <?php foreach ($by_dept as $gkey => $group) board_render_group($gkey, $group, false, false); ?>
                            </div>
                            <?php if (empty($employees)): ?><div class="text-center text-muted py-4">No active employees found.</div><?php endif; ?>
                        </div>

                        <div class="db-no-match" id="db-no-match"><i class="ri-search-eye-line me-1"></i>No employees match the current search / filter.</div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Adjust-duty modal: one employee, one date. Saves through the same
     duty_roster_save/duty_roster_recompute endpoints the full grid uses. -->
<div class="modal fade" id="db-adjust-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#673bb6;">
                <h6 class="modal-title text-white"><i class="ri-edit-2-line me-2"></i>Adjust Duty</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2" style="font-size:12.5px;color:#666;">
                    <span id="db-adjust-name" style="font-weight:700;color:#333;"></span>
                    &middot; <?= htmlspecialchars(date('l, F j, Y', strtotime($target_date))) ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;">Shift</label>
                    <select class="form-select form-select-sm" id="db-adjust-shift">
                        <option value="">— No shift —</option>
                        <?php foreach ($shift_rows as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['description']) ?> (<?= date('h:i A', strtotime($s['start_time'])) ?>–<?= date('h:i A', strtotime($s['end_time'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="db-adjust-rest">
                    <label class="form-check-label" for="db-adjust-rest" style="font-size:12.5px;">Day off (rest day)</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" id="db-adjust-save"><i class="ri-save-line me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
<script>
    window.DB_ADJUST = {
        date: <?= json_encode($target_date) ?>,
        period: <?= json_encode($B['duty_period']) ?>,
        isToday: <?= $is_today ? 'true' : 'false' ?>
    };
</script>
<!-- Employee quick-view drawer is included globally by index.php -->
