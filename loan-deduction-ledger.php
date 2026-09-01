<?php
// Loan & Deduction Ledger — running balances + full payment history for loans
// and amortizing employee deductions. Included via index.php (?page=loan-deduction-ledger).

$f_status = isset($_GET['status']) ? $_GET['status'] : 'active';   // active | paid | all
$f_kind   = isset($_GET['kind'])   ? $_GET['kind']   : 'all';      // all | loan | deduction
$f_q      = isset($_GET['q']) ? trim($_GET['q']) : '';

// ── Pull loans + amortizing deductions into one ledger ──
// The kind filter skips whole queries; the rest is filtered in PHP below.
$rows = [];

if ($f_kind !== 'deduction') {
    $lq = $conn->query("
        SELECT l.loan_id, l.employee_id, l.loan_amount AS original, l.damount AS per_period,
               l.loan_balance AS balance, l.loan_status AS status,
               COALESCE(l.effective_date, l.loan_date) AS start_date,
               clt.loan_type AS name, e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS emp
        FROM loans l
        INNER JOIN contribution_loan_types clt ON clt.clt_id = l.loan_type
        INNER JOIN employee e ON e.id = l.employee_id
        ORDER BY e.lastname, e.firstname
    ");
    while ($r = $lq->fetch_assoc()) {
        $rows[] = ['kind' => 'Loan', 'key' => 'L' . $r['loan_id'], 'id' => (int)$r['loan_id']] + $r;
    }
}

if ($f_kind !== 'loan') {
    $dq = $conn->query("
        SELECT ed.id, ed.employee_id, ed.total_amount AS original, ed.amount AS per_period,
               ed.balance, ed.status, ed.effective_date AS start_date,
               d.deduction AS name, e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS emp
        FROM employee_deductions ed
        INNER JOIN deductions d ON d.id = ed.deduction_id
        INNER JOIN employee e ON e.id = ed.employee_id
        WHERE ed.total_amount > 0
        ORDER BY e.lastname, e.firstname
    ");
    while ($r = $dq->fetch_assoc()) {
        $rows[] = ['kind' => 'Deduction', 'key' => 'D' . $r['id'], 'id' => (int)$r['id']] + $r;
    }
}

// ── Apply filters + totals ──
$t_orig = $t_bal = $t_paid = 0;
$view = [];
foreach ($rows as $r) {
    $paid = (float)$r['original'] - (float)$r['balance'];
    $isPaid = ((int)$r['status'] === 1) || (float)$r['balance'] <= 0;
    if ($f_status === 'active' && $isPaid) continue;
    if ($f_status === 'paid'   && !$isPaid) continue;
    if ($f_q !== '' && stripos($r['emp'] . ' ' . $r['employee_no'] . ' ' . $r['name'], $f_q) === false) continue;
    $r['paid'] = $paid; $r['isPaid'] = $isPaid;
    $view[] = $r;
    $t_orig += (float)$r['original']; $t_bal += (float)$r['balance']; $t_paid += $paid;
}

// ── Payment history only for the ledgers actually displayed (not the whole
//    loan_history / deduction_history tables) ──
$hist = [];
$loanIds = $dedIds = [];
foreach ($view as $r) {
    if ($r['kind'] === 'Loan') $loanIds[] = $r['id']; else $dedIds[] = $r['id'];
}
if ($loanIds) {
    $in = implode(',', $loanIds);
    $lh = $conn->query("SELECT lh.loan_id AS k, lh.amount, lh.current_bal, lh.new_bal, p.ref_no, p.date_from, p.date_to
                        FROM loan_history lh LEFT JOIN payroll p ON p.id = lh.payroll_id
                        WHERE lh.loan_id IN ($in) ORDER BY lh.loan_his_id ASC");
    while ($h = $lh->fetch_assoc()) $hist['L' . $h['k']][] = $h;
}
if ($dedIds) {
    $in = implode(',', $dedIds);
    $dh = $conn->query("SELECT dh.ded_id AS k, dh.amount, dh.current_bal, dh.new_bal, p.ref_no, p.date_from, p.date_to
                        FROM deduction_history dh LEFT JOIN payroll p ON p.id = dh.payroll_id
                        WHERE dh.ded_id IN ($in) ORDER BY dh.ded_his_id ASC");
    while ($h = $dh->fetch_assoc()) $hist['D' . $h['k']][] = $h;
}
function ldl_money($v){ return '₱' . number_format((float)$v, 2); }

// "Active (unpaid)" + "All types" is the default view, so it alone is not a
// filter worth echoing back in the summary bar.
$has_filter = ($f_q !== '' || $f_kind !== 'all' || $f_status !== 'active');
$kind_lbl   = ['all' => 'All', 'loan' => 'Loans only', 'deduction' => 'Deductions only'];
$status_lbl = ['active' => 'Active (unpaid)', 'paid' => 'Fully paid', 'all' => 'All'];
?>
<style>
    .ldl-stat { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:14px 16px; box-shadow:0 1px 6px rgba(0,0,0,.05); height:100%; }
    .ldl-stat .lbl { font-size:10px; color:#9aa3ad; text-transform:uppercase; letter-spacing:.4px; }
    .ldl-stat .val { font-size:18px; font-weight:800; }
    /* Expandable payment-history sub-row (keeps its own look above the global zebra) */
    #ldl-table > tbody > tr.ldl-hist > td { background:#fbfdfc !important; }
    .ldl-hist table { margin:0; font-size:11px; }
    .ldl-hist th { background:#edebf3; color:#4e3483; padding:5px 8px; }
    .ldl-hist td { padding:5px 8px; }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-bank-card-line me-2" style="color:#673bb6;"></i>Loan &amp; Deduction Ledger</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Loan &amp; Deduction Ledger</li>
            </ol>
        </div>
    </div></div>

    <!-- Summary -->
    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="ldl-stat"><div class="lbl">Accounts</div><div class="val" style="color:#673bb6;"><?= count($view) ?></div></div></div>
        <div class="col-md-3 col-6"><div class="ldl-stat"><div class="lbl">Original Total</div><div class="val"><?= ldl_money($t_orig) ?></div></div></div>
        <div class="col-md-3 col-6"><div class="ldl-stat"><div class="lbl">Paid to Date</div><div class="val" style="color:#2e7d32;"><?= ldl_money($t_paid) ?></div></div></div>
        <div class="col-md-3 col-6"><div class="ldl-stat"><div class="lbl">Outstanding</div><div class="val" style="color:#d32f2f;"><?= ldl_money($t_bal) ?></div></div></div>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-header align-items-center d-flex">
            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-bank-card-line me-1" style="color:#673bb6;"></i>Ledger Accounts</h5>
            <div class="d-flex gap-2">
                <?php if ($has_filter): ?>
                <a href="index.php?page=loan-deduction-ledger" class="btn btn-sm btn-outline-secondary"><i class="ri-close-line me-1"></i>Clear</a>
                <?php endif; ?>
                <button type="button" onclick="repExportCSV('ldl-table','loan-deduction-ledger.csv')" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-ledger">
                    <i class="ri-filter-3-line me-1"></i>Filter
                </button>
            </div>
        </div>
        <div class="card-body">

            <?php if ($has_filter): ?>
            <div class="rpt-filter-bar">
                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                <?php if ($f_q !== ''): ?><span>Search: <span class="val"><?= htmlspecialchars($f_q) ?></span></span><?php endif; ?>
                <span>Type: <span class="val"><?= $kind_lbl[$f_kind] ?? 'All' ?></span></span>
                <span>Status: <span class="val"><?= $status_lbl[$f_status] ?? 'Active (unpaid)' ?></span></span>
                <span class="ms-auto"><span class="val"><?= count($view) ?></span> account(s)</span>
            </div>
            <?php endif; ?>

            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="ldl-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th class="rpt-num">Original</th>
                            <th class="rpt-num">Per Period</th>
                            <th class="rpt-num">Paid</th>
                            <th class="rpt-num">Balance</th>
                            <th>Start</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$view): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No matching loans or deductions.</td></tr>
                        <?php else: foreach ($view as $r): $h = $hist[$r['key']] ?? []; ?>
                            <tr class="main">
                                <td class="text-center">
                                    <?php if ($h): ?>
                                    <button type="button" class="btn btn-sm btn-link p-0" onclick="ldlToggle('<?= $r['key'] ?>', this)" title="Payment history"><i class="ri-arrow-right-s-line"></i></button>
                                    <?php endif; ?>
                                </td>
                                <td><a href="index.php?page=employee-details&id=<?= (int)$r['employee_id'] ?>" data-emp-quickview="<?= (int)$r['employee_id'] ?>" class="rpt-emp-link" title="View employee details"><?= htmlspecialchars($r['emp']) ?></a><br><small class="text-muted"><?= htmlspecialchars($r['employee_no']) ?></small></td>
                                <td><span class="badge <?= $r['kind']==='Loan'?'bg-danger':'bg-warning text-dark' ?>"><?= $r['kind'] ?></span></td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td class="rpt-num"><?= ldl_money($r['original']) ?></td>
                                <td class="rpt-num"><?= ldl_money($r['per_period']) ?></td>
                                <td class="rpt-num" style="color:#2e7d32;"><?= ldl_money($r['paid']) ?></td>
                                <td class="rpt-num" style="color:#d32f2f;font-weight:700;"><?= ldl_money($r['balance']) ?></td>
                                <td><?= $r['start_date'] && $r['start_date']!=='0000-00-00' ? date('M d, Y', strtotime($r['start_date'])) : '—' ?></td>
                                <td class="text-center"><?= $r['isPaid'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Active</span>' ?></td>
                            </tr>
                            <?php if ($h): ?>
                            <tr class="ldl-hist" id="hist-<?= $r['key'] ?>" style="display:none;">
                                <td></td>
                                <td colspan="9">
                                    <table class="table table-sm table-bordered">
                                        <thead><tr><th>Payroll</th><th>Period</th><th class="rpt-num">Deducted</th><th class="rpt-num">Balance Before</th><th class="rpt-num">Balance After</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($h as $x): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($x['ref_no'] ?? '—') ?></td>
                                                <td><?= $x['date_from'] ? date('M d', strtotime($x['date_from'])) . ' – ' . date('M d, Y', strtotime($x['date_to'])) : '—' ?></td>
                                                <td class="rpt-num"><?= ldl_money($x['amount']) ?></td>
                                                <td class="rpt-num"><?= ldl_money($x['current_bal']) ?></td>
                                                <td class="rpt-num"><?= ldl_money($x['new_bal']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end">TOTAL</td>
                            <td class="rpt-num"><?= ldl_money($t_orig) ?></td>
                            <td></td>
                            <td class="rpt-num"><?= ldl_money($t_paid) ?></td>
                            <td class="rpt-num"><?= ldl_money($t_bal) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>
</div>
</div>

<!-- Filter modal — same shape as the Payroll List Report's filter -->
<div class="modal fade" id="modal-filter-ledger" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="loan-deduction-ledger">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#673bb6;"></i>Filter Loan &amp; Deduction Ledger</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-search-line me-1"></i>Search <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" class="form-control" placeholder="Employee, ID or item…">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-price-tag-3-line me-1"></i>Type</label>
                        <select name="kind" class="form-control" data-cs-icon="ri-price-tag-3-line">
                            <option value="all"       <?= $f_kind==='all'?'selected':'' ?>>All</option>
                            <option value="loan"      <?= $f_kind==='loan'?'selected':'' ?>>Loans only</option>
                            <option value="deduction" <?= $f_kind==='deduction'?'selected':'' ?>>Deductions only</option>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-pulse-line me-1"></i>Status</label>
                        <select name="status" class="form-control" data-cs-icon="ri-pulse-line">
                            <option value="active" <?= $f_status==='active'?'selected':'' ?>>Active (unpaid)</option>
                            <option value="paid"   <?= $f_status==='paid'?'selected':'' ?>>Fully paid</option>
                            <option value="all"    <?= $f_status==='all'?'selected':'' ?>>All</option>
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

<script>
    function ldlToggle(key, btn) {
        var row = document.getElementById('hist-' + key);
        if (!row) return;
        var open = row.style.display !== 'none';
        row.style.display = open ? 'none' : '';
        btn.querySelector('i').className = open ? 'ri-arrow-right-s-line' : 'ri-arrow-down-s-line';
    }
    // Shared CSV export (skips the expandable history sub-rows).
    function repExportCSV(tableId, filename) {
        var t = document.getElementById(tableId);
        if (!t) return;
        var csv = [];
        t.querySelectorAll('tr').forEach(function (tr) {
            if (tr.classList.contains('ldl-hist')) return;
            var cells = tr.querySelectorAll('th,td'), row = [];
            cells.forEach(function (c, i) {
                if (i === 0) return; // skip toggle column
                var txt = c.innerText.replace(/\s+/g, ' ').replace(/₱/g, '').trim();
                row.push('"' + txt.replace(/"/g, '""') + '"');
            });
            if (row.length) csv.push(row.join(','));
        });
        var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    }
</script>
