<?php
// Leave Balances Report — company-wide entitlement / used / remaining ledger
// per employee and per leave type for a leave year. Included via index.php
// (?page=leave-balances-report). Excel + PDF exports reuse the same builder in
// includes/leave_balances_report.php so the figures always agree.

require_once 'includes/leave_balances_report.php';

$f    = lbr_filters($_GET);
$rep  = lbr_data($conn, $f);
$types      = $rep['types'];
$rows       = $rep['rows'];
$typeTotals = $rep['type_totals'];
$T          = $rep['totals'];

$departments = lbr_departments($conn);
$allTypes    = lbr_all_types($conn);

// Query string shared by the export links (kept in sync with the filter form).
$qs = http_build_query([
    'year'       => $f['year'],
    'dept'       => $f['dept'],
    'type'       => $f['type'],
    'view'       => $f['view'],
    'search'     => $f['search'],
    'ineligible' => $f['ineligible'] ? 1 : 0,
]);

// Watchlists — the two rosters HR actually acts on.
$exhausted = array_values(array_filter($rows, fn($r) => $r['tot']['remaining'] <= 0));
$untouched = array_values(array_filter($rows, fn($r) => $r['tot']['used'] <= 0 && $r['tot']['credits'] > 0));
usort($exhausted, fn($a, $b) => $a['tot']['remaining'] <=> $b['tot']['remaining']);
usort($untouched, fn($a, $b) => $b['tot']['credits'] <=> $a['tot']['credits']);

// Heaviest consumers — used days, descending.
$topUsers = $rows;
usort($topUsers, fn($a, $b) => $b['tot']['used'] <=> $a['tot']['used']);
$topUsers = array_slice(array_filter($topUsers, fn($r) => $r['tot']['used'] > 0), 0, 8);
?>
<style>
    /* ── KPI tiles ── */
    .lb-kpi { background:#fff; border:1px solid #ddd9e7; border-radius:12px; padding:14px 16px; height:100%; border-top:3px solid #673bb6; }
    .lb-kpi .lb-kpi-lbl { font-size:10.5px; text-transform:uppercase; letter-spacing:.5px; color:#7a828c; font-weight:800; }
    .lb-kpi .lb-kpi-val { font-size:24px; font-weight:800; color:#4f3288; line-height:1.15; margin-top:2px; font-variant-numeric:tabular-nums; }
    .lb-kpi .lb-kpi-sub { font-size:11px; color:#96a0a8; }
    .lb-kpi .lb-kpi-ic  { float:right; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; background:#eae6f2; color:#673bb6; }

    /* ── Utilization bar ── */
    .lb-bar { height:6px; border-radius:4px; background:#efeaf8; overflow:hidden; min-width:70px; }
    .lb-bar > span { display:block; height:100%; border-radius:4px; background:#673bb6; }
    .lb-bar.warn > span { background:#f5a623; }
    .lb-bar.danger > span { background:#e05c5c; }

    /* ── Matrix table: two sticky header rows + sticky employee column ── */
    .lb-matrix thead tr:first-child th { top:0; }
    .lb-matrix thead tr:nth-child(2) th { top:31px; }
    .lb-matrix th.lb-group { text-align:center; background:#dad4e6; border-left:2px solid #c4bbd7; }
    .lb-matrix th.lb-group.lb-tot-group { background:#dbe8fb; color:#1e50a0; border-left:2px solid #cddcf3; }
    .lb-matrix td.lb-sep, .lb-matrix th.lb-sep { border-left:2px solid #e3e1ea; }
    .lb-matrix td.rpt-net, .lb-matrix th.rpt-net { border-left:2px solid #cddcf3; }
    .lb-matrix .lb-zero { color:#b8c0c6; }
    .lb-pill { display:inline-block; min-width:34px; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:700; }
    .lb-pill-ok   { background:#e3f4e8; color:#1c7a43; }
    .lb-pill-low  { background:#fdf1dc; color:#a76b09; }
    .lb-pill-none { background:#fbe3e3; color:#b3352f; }

    .lb-mini-table td, .lb-mini-table th { font-size:12px; padding:6px 10px; }
    .lb-section-title { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#4f3288; }

    @media print {
        .lb-noprint, .app-menu, .navbar-header, .footer { display:none !important; }
        .rpt-scroll { max-height:none !important; overflow:visible !important; }
    }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-coins-line me-2" style="color:#673bb6;"></i>Leave Balances Report
                <span class="badge bg-success-subtle text-success align-middle ms-1" style="font-size:11px;"><?= (int) $f['year'] ?></span>
            </h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item"><a href="index.php?page=leave_balances">Leave Management</a></li>
                <li class="breadcrumb-item active">Leave Balances</li>
            </ol>
        </div>
    </div></div>

    <!-- ── Filters ─────────────────────────────────────────────────────── -->
    <div class="card rpt-card mb-3 lb-noprint" style="border-top:3px solid #673bb6;">
        <div class="card-body py-3">
            <form method="get" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="leave-balances-report">
                <div class="col-md-2 col-6">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Leave Year</label>
                    <select name="year" class="form-select form-select-sm">
                        <?php $cy = (int) date('Y'); for ($y = $cy + 1; $y >= $cy - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === (int) $f['year'] ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Department</label>
                    <select name="dept" class="form-select form-select-sm">
                        <option value="0">All departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= $f['dept'] == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Leave Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="0">All types</option>
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $f['type'] == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">View</label>
                    <select name="view" class="form-select form-select-sm">
                        <?php foreach ([
                            'all'       => 'All employees',
                            'remaining' => 'With remaining',
                            'exhausted' => 'Fully consumed',
                            'unused'    => 'No leave taken',
                            'pending'   => 'Has pending',
                        ] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $f['view'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Search</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="text" name="search" value="<?= htmlspecialchars($f['search']) ?>" class="form-control" placeholder="Name or employee no.">
                    </div>
                </div>
                <div class="col-12 d-flex flex-wrap align-items-center gap-2 pt-1">
                    <div class="form-check form-switch me-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="lb-inelig" name="ineligible" value="1" <?= $f['ineligible'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="lb-inelig" style="font-size:12px;">Include non-eligible employees</label>
                    </div>
                    <button type="submit" class="btn btn-sm" style="background:#673bb6;color:#fff;font-weight:700;"><i class="ri-filter-3-line me-1"></i>Apply Filters</button>
                    <a href="index.php?page=leave-balances-report" class="btn btn-sm btn-outline-secondary"><i class="ri-refresh-line me-1"></i>Reset</a>
                    <div class="vr d-none d-md-block mx-1"></div>
                    <a href="export-leave-balances.php?format=xlsx&<?= htmlspecialchars($qs) ?>" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>Excel</a>
                    <a href="export-leave-balances.php?format=pdf&<?= htmlspecialchars($qs) ?>" class="btn btn-sm btn-outline-danger"><i class="ri-download-2-line me-1"></i>PDF</a>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line me-1"></i>Print</button>
                    <span class="ms-auto text-muted" style="font-size:11.5px;">
                        <i class="ri-information-line"></i>
                        <?= count($rows) ?> employee(s) listed<?= $T['ineligible'] ? ' · ' . $T['ineligible'] . ' non-eligible hidden' : '' ?>
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- ── KPI tiles ───────────────────────────────────────────────────── -->
    <?php
    $kpis = [
        ['Employees',      number_format($T['employees']),        'ri-team-line',          count($types) . ' paid leave type(s)'],
        ['Total Entitled', lbr_fmt($T['credits']) . ' days',      'ri-award-line',         'Credits granted for ' . (int) $f['year']],
        ['Days Used',      lbr_fmt($T['used']) . ' days',         'ri-calendar-check-line', $T['utilization'] . '% of entitlement'],
        ['Days Remaining', lbr_fmt($T['remaining']) . ' days',    'ri-wallet-3-line',      $T['exhausted'] . ' employee(s) at zero'],
        ['Pending',        lbr_fmt($T['pending']) . ' days',      'ri-time-line',          'Awaiting approval'],
        ['No Leave Taken', number_format($T['untouched']),        'ri-user-follow-line',   'Employees with 0 days used'],
    ];
    ?>
    <div class="row g-2 mb-3">
        <?php foreach ($kpis as $k): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="lb-kpi">
                <div class="lb-kpi-ic"><i class="<?= $k[2] ?>"></i></div>
                <div class="lb-kpi-lbl"><?= $k[0] ?></div>
                <div class="lb-kpi-val"><?= $k[1] ?></div>
                <div class="lb-kpi-sub"><?= htmlspecialchars($k[3]) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
        <!-- ── Per leave type summary ──────────────────────────────────── -->
        <div class="col-lg-7">
            <div class="card rpt-card h-100">
                <div class="card-header bg-white d-flex align-items-center" style="border-bottom:1px solid var(--rpt-card-border);">
                    <span class="lb-section-title flex-grow-1"><i class="ri-pie-chart-2-line me-1"></i>Utilization by Leave Type</span>
                    <span class="text-muted" style="font-size:11px;">Year <?= (int) $f['year'] ?></span>
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0 rpt-table lb-mini-table">
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th class="rpt-num">Entitled</th>
                                <th class="rpt-num">Used</th>
                                <th class="rpt-num">Pending</th>
                                <th class="rpt-num">Remaining</th>
                                <th class="rpt-num">Takers</th>
                                <th style="min-width:120px;">Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$types): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No paid leave types configured.</td></tr>
                            <?php else: foreach ($types as $tid => $t):
                                $tt  = $typeTotals[$tid];
                                $pct = $tt['credits'] > 0 ? min(100, round($tt['used'] / $tt['credits'] * 100)) : 0;
                                $cls = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : '');
                            ?>
                                <tr>
                                    <td>
                                        <b style="color:#4f3288;"><?= htmlspecialchars($t['name']) ?></b>
                                        <?php if (!empty($t['no_limit'])): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:9px;">No limit</span>
                                        <?php endif; ?>
                                        <div class="text-muted" style="font-size:10.5px;">
                                            <?= lbr_fmt($t['days_allowed']) ?> day reference ·
                                            <?= $t['carryover'] ? 'Carry-over' . ($t['carryover_cap'] !== null ? ' (cap ' . lbr_fmt($t['carryover_cap']) . ')' : '') : 'Resets yearly' ?>
                                        </div>
                                    </td>
                                    <td class="rpt-num"><?= lbr_fmt($tt['credits']) ?></td>
                                    <td class="rpt-num"><?= lbr_fmt($tt['used']) ?></td>
                                    <td class="rpt-num"><?= $tt['pending'] > 0 ? '<span class="badge bg-warning-subtle text-warning">' . lbr_fmt($tt['pending']) . '</span>' : '<span class="lb-zero">—</span>' ?></td>
                                    <td class="rpt-num"><b><?= lbr_fmt($tt['remaining']) ?></b></td>
                                    <td class="rpt-num"><?= (int) $tt['takers'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="lb-bar <?= $cls ?>" style="flex:1;"><span style="width:<?= $pct ?>%;"></span></div>
                                            <span style="font-size:11px;font-weight:700;color:#7a828c;min-width:32px;text-align:right;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL</td>
                                <td class="rpt-num"><?= lbr_fmt($T['credits']) ?></td>
                                <td class="rpt-num"><?= lbr_fmt($T['used']) ?></td>
                                <td class="rpt-num"><?= lbr_fmt($T['pending']) ?></td>
                                <td class="rpt-num"><?= lbr_fmt($T['remaining']) ?></td>
                                <td class="rpt-num">—</td>
                                <td><?= $T['utilization'] ?>%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Watchlists ──────────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div class="card rpt-card h-100">
                <div class="card-header bg-white" style="border-bottom:1px solid var(--rpt-card-border);">
                    <span class="lb-section-title"><i class="ri-alarm-warning-line me-1"></i>Watchlist</span>
                </div>
                <div class="card-body p-0">
                    <ul class="nav nav-tabs nav-tabs-custom px-2 pt-2" role="tablist" style="font-size:12px;">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#lb-tab-exh" role="tab">
                            Fully consumed <span class="badge bg-danger-subtle text-danger ms-1"><?= count($exhausted) ?></span></a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lb-tab-top" role="tab">
                            Top users <span class="badge bg-info-subtle text-info ms-1"><?= count($topUsers) ?></span></a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lb-tab-idle" role="tab">
                            Untouched <span class="badge bg-secondary-subtle text-secondary ms-1"><?= count($untouched) ?></span></a></li>
                    </ul>
                    <div class="tab-content" style="max-height:300px;overflow:auto;">
                        <?php
                        $miniList = function (array $list, string $metricLabel, callable $metric, string $empty) {
                            if (!$list) { echo '<div class="text-center text-muted py-4" style="font-size:12px;"><i class="ri-check-double-line d-block fs-22 mb-1"></i>' . htmlspecialchars($empty) . '</div>'; return; }
                            echo '<table class="table table-sm mb-0 lb-mini-table"><tbody>';
                            foreach (array_slice($list, 0, 20) as $r) {
                                echo '<tr><td><a class="rpt-emp-link" href="index.php?page=leave_balances&emp=' . (int) $r['id'] . '">' . htmlspecialchars($r['name']) . '</a>'
                                   . '<div class="text-muted" style="font-size:10.5px;">' . htmlspecialchars($r['dept']) . ' · ' . htmlspecialchars($r['employee_no']) . '</div></td>'
                                   . '<td class="rpt-num" style="white-space:nowrap;">' . $metric($r) . '<div class="text-muted" style="font-size:10px;">' . htmlspecialchars($metricLabel) . '</div></td></tr>';
                            }
                            echo '</tbody></table>';
                        };
                        ?>
                        <div class="tab-pane active" id="lb-tab-exh" role="tabpanel">
                            <?php $miniList($exhausted, 'remaining',
                                fn($r) => '<span class="lb-pill lb-pill-none">' . lbr_fmt($r['tot']['remaining']) . '</span>',
                                'Nobody has run out of credits.'); ?>
                        </div>
                        <div class="tab-pane" id="lb-tab-top" role="tabpanel">
                            <?php $miniList($topUsers, 'days used',
                                fn($r) => '<b>' . lbr_fmt($r['tot']['used']) . '</b>',
                                'No approved leave in this period.'); ?>
                        </div>
                        <div class="tab-pane" id="lb-tab-idle" role="tabpanel">
                            <?php $miniList($untouched, 'days unused',
                                fn($r) => '<span class="lb-pill lb-pill-ok">' . lbr_fmt($r['tot']['remaining']) . '</span>',
                                'Everyone has taken leave this year.'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Employee × leave type matrix ────────────────────────────────── -->
    <div class="card rpt-card mb-4">
        <div class="card-header bg-white d-flex align-items-center" style="border-bottom:1px solid var(--rpt-card-border);">
            <span class="lb-section-title flex-grow-1"><i class="ri-table-line me-1"></i>Employee Leave Ledger</span>
            <span class="text-muted" style="font-size:11px;">A = available · U = used · R = remaining</span>
        </div>
        <div class="card-body p-0">
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table lb-matrix" id="lb-table">
                    <thead>
                        <tr>
                            <th rowspan="2">#</th>
                            <th rowspan="2">Employee</th>
                            <th rowspan="2">Department</th>
                            <?php foreach ($types as $t): ?>
                                <th colspan="3" class="lb-group"><?= htmlspecialchars($t['name']) ?></th>
                            <?php endforeach; ?>
                            <th colspan="4" class="lb-group lb-tot-group">Total</th>
                        </tr>
                        <tr>
                            <?php foreach ($types as $t): ?>
                                <th class="rpt-num lb-sep" title="Available">A</th>
                                <th class="rpt-num" title="Used">U</th>
                                <th class="rpt-num" title="Remaining">R</th>
                            <?php endforeach; ?>
                            <th class="rpt-num rpt-net">Entitled</th>
                            <th class="rpt-num rpt-net">Used</th>
                            <th class="rpt-num rpt-net">Pending</th>
                            <th class="rpt-num rpt-net">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="<?= 7 + count($types) * 3 ?>" class="text-center text-muted py-4">
                                <i class="ri-inbox-line d-block fs-22 mb-1"></i>No employees match these filters.
                            </td></tr>
                        <?php else: $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <a href="index.php?page=leave_balances&emp=<?= (int) $r['id'] ?>" class="rpt-emp-link" title="Open leave balances"><?= htmlspecialchars($r['name']) ?></a>
                                    <?php if (!$r['eligible']): ?><span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:9px;">Not eligible</span><?php endif; ?>
                                    <div class="text-muted" style="font-size:10.5px;"><?= htmlspecialchars($r['employee_no']) ?> · <?= htmlspecialchars($r['position']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($r['dept']) ?><div class="text-muted" style="font-size:10.5px;"><?= htmlspecialchars($r['clasif']) ?></div></td>
                                <?php foreach ($types as $tid => $t):
                                    $c   = $r['cells'][$tid];
                                    $unl = !empty($t['no_limit']);
                                    $pil = $unl ? 'lb-pill-ok' : ($c['remaining'] <= 0 ? 'lb-pill-none' : ($c['credits'] > 0 && $c['remaining'] / max($c['credits'], .01) <= .25 ? 'lb-pill-low' : 'lb-pill-ok'));
                                ?>
                                    <td class="rpt-num lb-sep <?= (!$unl && $c['credits'] <= 0) ? 'lb-zero' : '' ?>" title="<?= $unl ? 'No credit limit — filing never blocked' : '' ?>"><?= $unl ? '∞' : lbr_fmt($c['credits']) ?></td>
                                    <td class="rpt-num <?= $c['used'] <= 0 ? 'lb-zero' : '' ?>">
                                        <?= $c['used'] > 0 ? lbr_fmt($c['used']) : '—' ?>
                                        <?php if ($c['pending'] > 0): ?><i class="ri-time-line text-warning" title="<?= lbr_fmt($c['pending']) ?> day(s) pending"></i><?php endif; ?>
                                    </td>
                                    <td class="rpt-num"><span class="lb-pill <?= $pil ?>"><?= $unl ? '∞' : lbr_fmt($c['remaining']) ?></span></td>
                                <?php endforeach; ?>
                                <td class="rpt-num rpt-net"><?= lbr_fmt($r['tot']['credits']) ?></td>
                                <td class="rpt-num rpt-net"><?= lbr_fmt($r['tot']['used']) ?></td>
                                <td class="rpt-num rpt-net"><?= $r['tot']['pending'] > 0 ? lbr_fmt($r['tot']['pending']) : '—' ?></td>
                                <td class="rpt-num rpt-net"><b><?= lbr_fmt($r['tot']['remaining']) ?></b></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end">TOTAL (<?= count($rows) ?> employees)</td>
                            <?php foreach ($types as $tid => $t): $tt = $typeTotals[$tid]; ?>
                                <td class="rpt-num lb-sep"><?= lbr_fmt($tt['credits']) ?></td>
                                <td class="rpt-num"><?= lbr_fmt($tt['used']) ?></td>
                                <td class="rpt-num"><?= lbr_fmt($tt['remaining']) ?></td>
                            <?php endforeach; ?>
                            <td class="rpt-num rpt-net"><?= lbr_fmt($T['credits']) ?></td>
                            <td class="rpt-num rpt-net"><?= lbr_fmt($T['used']) ?></td>
                            <td class="rpt-num rpt-net"><?= lbr_fmt($T['pending']) ?></td>
                            <td class="rpt-num rpt-net"><?= lbr_fmt($T['remaining']) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="text-muted mb-4" style="font-size:11px;">
        <i class="ri-information-line"></i>
        Available credits come from each employee's <?= (int) $f['year'] ?> balance, falling back to the leave type's default entitlement when no balance has been set.
        Used days count <b>approved</b> requests that start within <?= (int) $f['year'] ?>; pending days are filed but not yet fully approved and are <b>not</b> deducted from remaining.
        Only paid, active leave types are included.
    </div>

</div>
</div>
</div>
