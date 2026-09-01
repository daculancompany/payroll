<?php
// ── BIR Alphalist (annex to Form 1604-C) — included via index.php ────────
// Alphabetical list of employees for a calendar year with annual gross
// compensation, non-taxable components (gov't contributions + 13th month up
// to the ₱90,000 cap), taxable compensation and tax withheld.
//
// Gross per cutoff mirrors get_payroll_rows_data() (rate-type aware), summed
// across the year's payrolls, plus the year's 13th month pay and signed
// adjustments (they are compensation actually received).

// BIR_13TH_CAP now lives in db_connect.php so the payroll calculation can
// see it too — the cap has to apply when WITHHOLDING, not only when reporting.

$al_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$al_years = [];
$yq = $conn->query("SELECT DISTINCT YEAR(date_from) AS y FROM payroll ORDER BY y DESC");
if ($yq) while ($y = $yq->fetch_assoc()) $al_years[] = (int) $y['y'];
if (!in_array((int) date('Y'), $al_years, true)) array_unshift($al_years, (int) date('Y'));
if (!in_array($al_year, $al_years, true)) $al_years[] = $al_year;

// Contribution id → gov't bucket (same aliasing as the remittance report).
$al_gov_ids = [];
$cq = $conn->query("SELECT id, contribution FROM contributions");
if ($cq) while ($c = $cq->fetch_assoc()) {
    $n = strtoupper($c['contribution']);
    if (strpos($n, 'SSS') !== false || strpos($n, 'PHIC') !== false || strpos($n, 'PHIL') !== false
        || strpos($n, 'HDMF') !== false || strpos($n, 'PAG') !== false) {
        $al_gov_ids[(int) $c['id']] = true;
    }
}

// 13th month per employee for the year (override wins when set).
$al_13th = [];
$al_13th_final = true;
$tq = $conn->query("SELECT employee_id, amount, override_amount, status FROM thirteenth_month WHERE year = $al_year");
if ($tq) while ($t = $tq->fetch_assoc()) {
    $al_13th[(int) $t['employee_id']] = $t['override_amount'] !== null ? (float) $t['override_amount'] : (float) $t['amount'];
    if ((int) $t['status'] !== 1) $al_13th_final = false;
}

// One pass over the year's payroll items, grouped per employee.
$al_rows = [];
$iq = $conn->query("
    SELECT pi.*, p.date_from AS pf, p.date_to AS pt,
           e.tin_no, e.employee_no, e.lastname, e.firstname, e.middlename
    FROM payroll_items pi
    INNER JOIN payroll p ON p.id = pi.payroll_id
    INNER JOIN employee e ON e.id = pi.employee_id
    WHERE YEAR(p.date_from) = $al_year
    ORDER BY e.lastname ASC, e.firstname ASC");
if ($iq) while ($r = $iq->fetch_assoc()) {
    $eid = (int) $r['employee_id'];
    if (!isset($al_rows[$eid])) {
        $al_rows[$eid] = [
            'name' => $r['lastname'] . ', ' . $r['firstname'] . ' ' . $r['middlename'],
            'employee_no' => $r['employee_no'],
            'tin' => trim((string) $r['tin_no']),
            'from' => $r['pf'], 'to' => $r['pt'],
            'gross' => 0.0, 'gov' => 0.0, 'tax' => 0.0, 'adj' => 0.0,
        ];
    }
    $a = &$al_rows[$eid];
    if ($r['pf'] < $a['from']) $a['from'] = $r['pf'];
    if ($r['pt'] > $a['to'])   $a['to'] = $r['pt'];

    // Per-cutoff gross — the ONE shared formula. This used to be a hand-rolled
    // copy that had drifted: it halved absences, and its daily branch dropped
    // holiday, rest-day and night-differential pay entirely, so the compensation
    // reported to BIR was lower than the employee was actually paid.
    $a['gross'] += payroll_earnings($r)['gross'];
    $a['adj']   += (float) ($r['adjustment'] ?? 0);
    $a['tax']   += (float) $r['tax'];

    // Gov't contributions (employee share) from the payslip JSON.
    $cons = json_decode($r['contributions'], true) ?: [];
    foreach ($cons as $c) {
        if (isset($al_gov_ids[(int) ($c['contribution_id'] ?? 0)])) $a['gov'] += (float) ($c['amount'] ?? 0);
    }
    unset($a);
}

// Derive the BIR columns per employee.
$tot = ['gross' => 0, 'g13' => 0, 'nt13' => 0, 'tx13' => 0, 'gov' => 0, 'nontax' => 0, 'taxable' => 0, 'tax' => 0];
$al_no_tin = 0;
foreach ($al_rows as $eid => &$a) {
    $g13 = $al_13th[$eid] ?? 0.0;
    $a['g13'] = $g13;
    $a['nt13'] = min($g13, BIR_13TH_CAP);          // non-taxable 13th month & benefits
    $a['tx13'] = max(0, $g13 - BIR_13TH_CAP);      // excess over the cap → taxable
    $a['total_gross'] = $a['gross'] + $a['adj'] + $g13;
    $a['nontax'] = $a['gov'] + $a['nt13'];
    $a['taxable'] = max(0, $a['total_gross'] - $a['nontax']);
    if ($a['tin'] === '') $al_no_tin++;
    $tot['gross'] += $a['total_gross'];
    $tot['g13'] += $g13;
    $tot['nt13'] += $a['nt13'];
    $tot['tx13'] += $a['tx13'];
    $tot['gov'] += $a['gov'];
    $tot['nontax'] += $a['nontax'];
    $tot['taxable'] += $a['taxable'];
    $tot['tax'] += $a['tax'];
}
unset($a);
?>
<style>
    .al-no-tin td { background: #fff8e1 !important; }
    .al-mono { font-family: ui-monospace, monospace; }
    .al-tbl th, .al-tbl td { font-size: 12px; white-space: nowrap; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">BIR Alphalist</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                                <li class="breadcrumb-item active">BIR Alphalist</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card rpt-card">
                        <div class="card-header align-items-center d-flex">
                            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-government-line me-1" style="color:#673bb6;"></i>BIR Alphalist</h5>
                            <div class="d-flex gap-2">
                                <?php if ($al_rows): ?>
                                <a class="btn btn-sm btn-outline-success" href="export-bir-alphalist.php?year=<?= $al_year ?>"><i class="ri-file-excel-2-line me-1"></i>CSV</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="ri-printer-line"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-alphalist">
                                    <i class="ri-filter-3-line me-1"></i>Filter
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="rpt-filter-bar">
                                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                                <span>Year: <span class="val"><?= $al_year ?></span></span>
                                <span>Annex to Form 1604-C &middot; 13th month exempt up to <span class="val">&#8369;<?= number_format(BIR_13TH_CAP, 0) ?></span></span>
                                <span class="ms-auto"><span class="val"><?= count($al_rows) ?></span> employee(s)</span>
                            </div>

                            <?php if ($al_no_tin > 0): ?>
                                <div class="alert alert-warning py-2 d-flex align-items-center gap-2" style="font-size:12.5px;">
                                    <i class="ri-error-warning-line fs-16"></i>
                                    <span><b><?= $al_no_tin ?></b> employee(s) have no TIN on file (highlighted) — the BIR e-submission will reject rows without a TIN.</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($al_13th && !$al_13th_final): ?>
                                <div class="alert alert-info py-2 d-flex align-items-center gap-2" style="font-size:12.5px;">
                                    <i class="ri-information-line fs-16"></i>
                                    <span>13th month pay for <?= $al_year ?> is still a <b>draft</b> — finalize it before filing.</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!$al_rows): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="ri-government-line" style="font-size:42px;"></i>
                                    <p class="mt-2">No payroll data for <b><?= $al_year ?></b>.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle al-tbl">
                                        <thead class="table-dark">
                                            <tr>
                                                <th rowspan="2" class="text-center" style="width:36px;">Seq</th>
                                                <th rowspan="2">TIN</th>
                                                <th rowspan="2">Employee Name</th>
                                                <th rowspan="2" class="text-center">Period Covered</th>
                                                <th rowspan="2" class="text-end">Gross Compensation</th>
                                                <th colspan="2" class="text-center">13th Month &amp; Benefits</th>
                                                <th rowspan="2" class="text-end">Gov't Contributions<br><small>(SSS/PHIC/HDMF)</small></th>
                                                <th rowspan="2" class="text-end">Total Non-Taxable</th>
                                                <th rowspan="2" class="text-end">Taxable Compensation</th>
                                                <th rowspan="2" class="text-end">Tax Withheld</th>
                                            </tr>
                                            <tr>
                                                <th class="text-end">Non-Taxable<br><small>(&le; cap)</small></th>
                                                <th class="text-end">Taxable<br><small>(excess)</small></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 0; foreach ($al_rows as $a): $i++; ?>
                                                <tr class="<?= $a['tin'] === '' ? 'al-no-tin' : '' ?>">
                                                    <td class="text-center"><?= $i ?></td>
                                                    <td class="al-mono"><?= $a['tin'] !== '' ? htmlspecialchars($a['tin']) : '<span class="text-danger fw-bold">No TIN</span>' ?></td>
                                                    <td><b><?= htmlspecialchars(trim($a['name'])) ?></b> <small class="text-muted"><?= htmlspecialchars($a['employee_no']) ?></small></td>
                                                    <td class="text-center"><?= date('M j', strtotime($a['from'])) ?> – <?= date('M j, Y', strtotime($a['to'])) ?></td>
                                                    <td class="text-end"><?= number_format($a['total_gross'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($a['nt13'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($a['tx13'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($a['gov'], 2) ?></td>
                                                    <td class="text-end"><?= number_format($a['nontax'], 2) ?></td>
                                                    <td class="text-end fw-bold"><?= number_format($a['taxable'], 2) ?></td>
                                                    <td class="text-end fw-bold"><?= number_format($a['tax'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-bold">
                                                <th colspan="4" class="text-end">TOTAL (<?= count($al_rows) ?> employees)</th>
                                                <th class="text-end"><?= number_format($tot['gross'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['nt13'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['tx13'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['gov'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['nontax'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['taxable'], 2) ?></th>
                                                <th class="text-end"><?= number_format($tot['tax'], 2) ?></th>
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
    </div>
</div>

<!-- Filter modal — same shape as the Payroll List Report's filter -->
<div class="modal fade" id="modal-filter-alphalist" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="bir-alphalist">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#673bb6;"></i>Filter BIR Alphalist</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-calendar-2-line me-1"></i>Calendar Year</label>
                        <select name="year" class="form-control" data-cs-icon="ri-calendar-2-line">
                            <?php foreach ($al_years as $y): ?>
                            <option value="<?= $y ?>" <?= $y === $al_year ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="ri-close-line me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;"><i class="ri-search-line me-1"></i>Apply Filter</button>
                </div>
            </div>
        </div>
    </form>
</div>
