<?php
// Deductions Report — what was actually withheld, per employee and per deduction /
// loan type, over a date range of payroll runs. Covers recurring deductions
// (COOP, PHARMACY…), balance deductions, loans and one-off item deductions —
// the per-type breakdown the Payroll Register only shows as one total.
// Included via index.php (?page=deductions-report).

$f_from   = isset($_GET['from']) && strtotime($_GET['from']) ? date('Y-m-d', strtotime($_GET['from'])) : date('Y-01-01');
$f_to     = isset($_GET['to'])   && strtotime($_GET['to'])   ? date('Y-m-d', strtotime($_GET['to']))   : date('Y-m-d');
$f_status = isset($_GET['status']) && $_GET['status'] === 'all' ? 'all' : 'locked';   // locked | all
$f_kind   = isset($_GET['kind']) && in_array($_GET['kind'], ['ded', 'loan', 'extra'], true) ? $_GET['kind'] : 'all';
$f_type   = isset($_GET['type']) ? (string) $_GET['type'] : '';
$f_q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$f_payroll = isset($_GET['payroll']) ? (int) $_GET['payroll'] : 0;   // one cutoff; overrides From/To
if ($f_from > $f_to) { $t = $f_from; $f_from = $f_to; $f_to = $t; }

// Payroll cutoffs for the picker, newest first.
$cutoffs = [];
$cq = $conn->query("SELECT id, ref_no, date_from, date_to, status FROM payroll
                    WHERE status >= 1 AND date_from <> '0000-00-00' ORDER BY date_from DESC, id DESC");
while ($cq && ($c = $cq->fetch_assoc())) {
    $c['label'] = date('M d', strtotime($c['date_from'])) . ' – ' . date('M d, Y', strtotime($c['date_to']))
                . ' (' . $c['ref_no'] . ')' . ((int) $c['status'] !== 2 ? ' — not locked' : '');
    $cutoffs[(int) $c['id']] = $c;
}
if ($f_payroll && !isset($cutoffs[$f_payroll])) $f_payroll = 0;

require_once __DIR__ . '/includes/deductions_withheld.php';
[$ded_names, $loan_names] = dw_type_names($conn);

// ── Payroll runs: the one picked cutoff (locked or not), else every run in range (by period end) ──
$pays = [];
if ($f_payroll) {
    $pays[$f_payroll] = $cutoffs[$f_payroll];
} else {
    $st_sql = $f_status === 'all' ? 'p.status >= 1' : 'p.status = 2';
    $ps = $conn->prepare("SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status FROM payroll p
                          WHERE $st_sql AND p.date_to BETWEEN ? AND ? ORDER BY p.date_to ASC, p.id ASC");
    $ps->bind_param('ss', $f_from, $f_to);
    $ps->execute();
    foreach ($ps->get_result()->fetch_all(MYSQLI_ASSOC) as $p) $pays[(int) $p['id']] = $p;
}
// Carried on the per-type links so drilling into one type keeps the same payrolls.
$keep_qs = $f_payroll ? ['payroll' => $f_payroll] : ['from' => $f_from, 'to' => $f_to, 'status' => $f_status];
$csv_tag = $f_payroll ? $cutoffs[$f_payroll]['date_from'] . '_' . $cutoffs[$f_payroll]['date_to'] : $f_from . '_' . $f_to;

// ── Collect: $cols = every type seen; $emps[eid] = totals per type + per payroll ──
$dw = dw_collect($conn, $pays, ['q' => $f_q, 'kind' => $f_kind, 'type' => $f_type]);
$cols = $dw['cols'];
$emps = $dw['emps'];

// Totals per type and per kind.
$col_tot = array_fill_keys(array_keys($cols), 0.0);
$col_emp = array_fill_keys(array_keys($cols), 0);
$kind_tot = ['ded' => 0.0, 'loan' => 0.0, 'extra' => 0.0];
foreach ($emps as $e) {
    foreach ($e['tot'] as $k => $v) { $col_tot[$k] += $v; $col_emp[$k]++; $kind_tot[$cols[$k]['kind']] += $v; }
}
$grand = array_sum($col_tot);
$pays_used = [];
foreach ($emps as $e) foreach ($e['pay'] as $pid => $_) $pays_used[$pid] = true;

// Type picker options: every known type, so a filter can target one that has
// no rows in the current range too.
$type_opts = [];
foreach ($ded_names as $id => $n)  $type_opts['D' . $id] = $n . ' (deduction)';
foreach ($loan_names as $id => $n) $type_opts['L' . $id] = $n . ' (loan)';
asort($type_opts);

if (!function_exists('dr_money')) { function dr_money($v) { return '₱' . number_format((float) $v, 2); } }
$kind_badge = ['ded' => ['Deduction', 'bg-warning text-dark'], 'loan' => ['Loan', 'bg-danger'], 'extra' => ['One-off', 'bg-info text-dark']];
$kind_lbl   = ['all' => 'All', 'ded' => 'Deductions', 'loan' => 'Loans', 'extra' => 'One-off'];
$ncols = count($cols) + 2;
?>
<style>
    .dr-stat { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:14px 16px; box-shadow:0 1px 6px rgba(0,0,0,.05); height:100%; }
    .dr-stat .lbl { font-size:10px; color:#9aa3ad; text-transform:uppercase; letter-spacing:.4px; }
    .dr-stat .val { font-size:18px; font-weight:800; }
    .dr-stat .sub { font-size:11px; color:#9aa3ad; }
    #dr-matrix th.dr-col { white-space:normal; min-width:96px; font-size:11px; line-height:1.25; }
    #dr-matrix th.dr-col small { display:block; font-weight:600; opacity:.75; text-transform:none; }
    #dr-matrix td.zero { color:#c5c9d0; }
    #dr-matrix > tbody > tr.dr-hist > td { background:#fbfdfc !important; }
    .dr-hist table { margin:0; font-size:11px; }
    .dr-hist th { background:#edebf3; color:#4e3483; padding:5px 8px; }
    .dr-hist td { padding:5px 8px; }
    .dr-type-row td { font-size:12.5px; }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-scissors-cut-line me-2" style="color:#673bb6;"></i>Deductions Report</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Deductions Report</li>
            </ol>
        </div>
    </div></div>

    <!-- Summary -->
    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="dr-stat"><div class="lbl">Total Withheld</div><div class="val" style="color:#673bb6;"><?= dr_money($grand) ?></div><div class="sub"><?= count($emps) ?> employee(s) · <?= count($pays_used) ?> payroll(s)</div></div></div>
        <div class="col-md-3 col-6"><div class="dr-stat"><div class="lbl">Deductions</div><div class="val" style="color:#f59f00;"><?= dr_money($kind_tot['ded']) ?></div><div class="sub">Pharmacy, COOP, Hospital…</div></div></div>
        <div class="col-md-3 col-6"><div class="dr-stat"><div class="lbl">Loans</div><div class="val" style="color:#d32f2f;"><?= dr_money($kind_tot['loan']) ?></div><div class="sub">SSS / Pag-IBIG / other</div></div></div>
        <div class="col-md-3 col-6"><div class="dr-stat"><div class="lbl">One-off</div><div class="val" style="color:#1098ad;"><?= dr_money($kind_tot['extra']) ?></div><div class="sub">added on the payroll itself</div></div></div>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-header align-items-center d-flex">
            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-scissors-cut-line me-1" style="color:#673bb6;"></i>Withheld per Employee</h5>
            <div class="d-flex gap-2">
                <button type="button" onclick="drExportCSV()" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-dr">
                    <i class="ri-filter-3-line me-1"></i>Filter
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="rpt-filter-bar">
                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                <?php if ($f_payroll): ?>
                <span>Cutoff: <span class="val"><?= htmlspecialchars($cutoffs[$f_payroll]['label']) ?></span></span>
                <?php else: ?>
                <span>Period: <span class="val"><?= date('M d, Y', strtotime($f_from)) ?> – <?= date('M d, Y', strtotime($f_to)) ?></span></span>
                <span>Payrolls: <span class="val"><?= $f_status === 'all' ? 'Calculated + locked' : 'Locked only' ?></span></span>
                <?php endif; ?>
                <?php if ($f_kind !== 'all'): ?><span>Kind: <span class="val"><?= $kind_lbl[$f_kind] ?></span></span><?php endif; ?>
                <?php if ($f_type !== ''): ?><span>Type: <span class="val"><?= htmlspecialchars($type_opts[$f_type] ?? $f_type) ?></span></span><?php endif; ?>
                <?php if ($f_q !== ''): ?><span>Search: <span class="val"><?= htmlspecialchars($f_q) ?></span></span><?php endif; ?>
            </div>

            <?php if (!$emps): ?>
                <div class="text-center text-muted py-5">
                    <i class="ri-inbox-line" style="font-size:34px;opacity:.5;display:block;"></i>
                    No deductions withheld in this period<?= !$f_payroll && $f_status === 'locked' ? ' (locked payrolls only — try “Calculated + locked” in Filter)' : '' ?>.
                </div>
            <?php else: ?>

            <!-- Totals per type -->
            <div class="mb-3" style="max-width:720px;">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>Type</th><th>Kind</th><th class="text-end">Employees</th><th class="text-end">Total Withheld</th></tr></thead>
                    <tbody>
                        <?php foreach ($cols as $k => $c): ?>
                        <tr class="dr-type-row">
                            <td><a href="index.php?<?= htmlspecialchars(http_build_query(['page' => 'deductions-report', 'type' => $k] + $keep_qs + ($f_q !== '' ? ['q' => $f_q] : []))) ?>" title="Show only this type"><?= htmlspecialchars($c['label']) ?></a></td>
                            <td><span class="badge <?= $kind_badge[$c['kind']][1] ?>"><?= $kind_badge[$c['kind']][0] ?></span></td>
                            <td class="text-end"><?= $col_emp[$k] ?></td>
                            <td class="text-end fw-bold"><?= dr_money($col_tot[$k]) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Employee × type matrix -->
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="dr-matrix">
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Employee</th>
                            <?php foreach ($cols as $c): ?>
                            <th class="rpt-num dr-col"><?= htmlspecialchars($c['label']) ?><small><?= $kind_badge[$c['kind']][0] ?></small></th>
                            <?php endforeach; ?>
                            <th class="rpt-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emps as $eid => $e): ?>
                        <tr class="main">
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-link p-0" onclick="drToggle(<?= $eid ?>, this)" title="Per payroll"><i class="ri-arrow-right-s-line"></i></button>
                            </td>
                            <td><a href="index.php?page=employee-details&id=<?= $eid ?>" data-emp-quickview="<?= $eid ?>" class="rpt-emp-link"><?= htmlspecialchars($e['emp']) ?></a><br><small class="text-muted"><?= htmlspecialchars($e['no']) ?><?= $e['dept'] !== '' ? ' · ' . htmlspecialchars($e['dept']) : '' ?></small></td>
                            <?php foreach ($cols as $k => $c): $v = $e['tot'][$k] ?? 0; ?>
                            <td class="rpt-num<?= $v ? '' : ' zero' ?>"><?= $v ? dr_money($v) : '—' ?></td>
                            <?php endforeach; ?>
                            <td class="rpt-num fw-bold"><?= dr_money($e['sum']) ?></td>
                        </tr>
                        <tr class="dr-hist" id="dr-hist-<?= $eid ?>" style="display:none;">
                            <td></td>
                            <td colspan="<?= $ncols - 1 ?>">
                                <table class="table table-sm table-bordered">
                                    <thead><tr><th>Payroll</th><th>Period</th><th>Type</th><th class="text-end">Withheld</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($e['pay'] as $pid => $amts): $p = $pays[$pid]; foreach ($amts as $k => $v): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['ref_no']) ?><?= (int) $p['status'] !== 2 ? ' <span class="badge bg-secondary">not locked</span>' : '' ?></td>
                                            <td><?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?></td>
                                            <td><?= htmlspecialchars($cols[$k]['label']) ?>
                                                <?php foreach ($e['notes'][$pid][$k] ?? [] as $n): ?>
                                                <div class="text-muted" style="font-size:10.5px;font-style:italic;">↳ <?= htmlspecialchars($n['desc']) ?><?= count($e['notes'][$pid][$k]) > 1 ? ' — ' . dr_money($n['amount']) : '' ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="text-end"><?= dr_money($v) ?></td>
                                        </tr>
                                        <?php endforeach; endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end">TOTAL</td>
                            <?php foreach ($cols as $k => $c): ?><td class="rpt-num"><?= dr_money($col_tot[$k]) ?></td><?php endforeach; ?>
                            <td class="rpt-num"><?= dr_money($grand) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>
</div>

<!-- Filter modal — same shape as the other report filters -->
<div class="modal fade" id="modal-filter-dr" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="deductions-report">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#673bb6;"></i>Filter Deductions Report</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php $lblCss = 'font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;'; ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-calendar-check-line me-1"></i>Payroll cutoff</label>
                        <select name="payroll" id="dr-payroll" class="form-control" data-cs-icon="ri-calendar-check-line">
                            <option value="">Date range below</option>
                            <?php foreach ($cutoffs as $pid => $c): ?>
                            <option value="<?= $pid ?>" <?= $f_payroll === $pid ? 'selected' : '' ?>><?= htmlspecialchars($c['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Pick one cutoff (kinsena) to match its Remittance and Department Summary.</small>
                    </div>
                    <div class="row g-2 mb-3" id="dr-range">
                        <div class="col-6">
                            <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-calendar-line me-1"></i>From</label>
                            <input type="date" name="from" value="<?= $f_from ?>" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-calendar-line me-1"></i>To</label>
                            <input type="date" name="to" value="<?= $f_to ?>" class="form-control">
                        </div>
                        <div class="col-12"><small class="text-muted">Payroll runs whose period ends inside this range.</small></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-search-line me-1"></i>Search <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" class="form-control" placeholder="Employee, ID or department…">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-price-tag-3-line me-1"></i>Kind</label>
                            <select name="kind" class="form-control" data-cs-icon="ri-price-tag-3-line">
                                <?php foreach ($kind_lbl as $k => $l): ?><option value="<?= $k ?>" <?= $f_kind === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6" id="dr-status">
                            <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-lock-line me-1"></i>Payrolls</label>
                            <select name="status" class="form-control" data-cs-icon="ri-lock-line">
                                <option value="locked" <?= $f_status === 'locked' ? 'selected' : '' ?>>Locked only</option>
                                <option value="all" <?= $f_status === 'all' ? 'selected' : '' ?>>Calculated + locked</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="<?= $lblCss ?>"><i class="ri-list-check me-1"></i>Type</label>
                        <select name="type" class="form-control" data-cs-icon="ri-list-check">
                            <option value="">All types</option>
                            <?php foreach ($type_opts as $k => $l): ?><option value="<?= htmlspecialchars($k) ?>" <?= $f_type === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <a href="index.php?page=deductions-report" class="btn btn-sm btn-outline-secondary me-auto"><i class="ri-refresh-line me-1"></i>Reset</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="ri-close-line me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;"><i class="ri-search-line me-1"></i>Apply Filter</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function drToggle(id, btn) {
        var row = document.getElementById('dr-hist-' + id);
        if (!row) return;
        var open = row.style.display !== 'none';
        row.style.display = open ? 'none' : '';
        btn.querySelector('i').className = open ? 'ri-arrow-right-s-line' : 'ri-arrow-down-s-line';
    }
    // CSV of the matrix (skips the per-payroll sub-rows and the toggle column).
    function drExportCSV() {
        var t = document.getElementById('dr-matrix');
        if (!t) return;
        var csv = [];
        t.querySelectorAll('tr').forEach(function (tr) {
            if (tr.classList.contains('dr-hist') || tr.closest('.dr-hist')) return;
            var row = [];
            tr.querySelectorAll(':scope > th, :scope > td').forEach(function (c, i) {
                if (i === 0 && !c.hasAttribute('colspan')) return;
                var txt = c.innerText.replace(/\s+/g, ' ').replace(/₱/g, '').replace(/—/g, '0').trim();
                row.push('"' + txt.replace(/"/g, '""') + '"');
            });
            if (row.length) csv.push(row.join(','));
        });
        var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'deductions-report-<?= $csv_tag ?>.csv';
        a.click();
    }
    // A picked cutoff replaces the date range and the locked/calculated choice.
    (function () {
        var sel = document.getElementById('dr-payroll');
        if (!sel) return;
        function sync() {
            var on = sel.value !== '';
            ['dr-range', 'dr-status'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.style.display = on ? 'none' : '';
            });
        }
        sel.addEventListener('change', sync);
        sync();
    })();
</script>
