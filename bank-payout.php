<?php
// ── Bank Payout report — included via index.php ─────────────────────────
// Pick a payroll → per-employee net pay with bank + account number, grouped
// by bank, ready for bank-transfer upload (CSV) or printing. Employees with
// no bank/account on file are flagged so payouts don't silently bounce.

$payrolls = [];
$pr = $conn->query("SELECT id, ref_no, date_from, date_to, status FROM payroll WHERE status >= 1 ORDER BY date_from DESC");
if ($pr) while ($r = $pr->fetch_assoc()) $payrolls[] = $r;

$sel_id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$sel_pay = null;
$rows    = [];
$byBank  = [];      // bank name => ['count' => n, 'total' => x]
$noBank  = 0;
$t_net   = 0;

if ($sel_id) {
    $st = $conn->prepare("SELECT id, ref_no, date_from, date_to, status FROM payroll WHERE id = ?");
    $st->bind_param('i', $sel_id);
    $st->execute();
    $sel_pay = $st->get_result()->fetch_assoc();

    if ($sel_pay) {
        $it = $conn->prepare("
            SELECT pi.employee_id, SUM(pi.net) AS net,
                   e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS name,
                   e.bank_account_no, b.bank_name, d.name AS department
            FROM payroll_items pi
            INNER JOIN employee e ON e.id = pi.employee_id
            LEFT JOIN banks b ON b.id = e.bank_id
            LEFT JOIN department d ON d.id = e.department_id
            WHERE pi.payroll_id = ?
            GROUP BY pi.employee_id
            ORDER BY b.bank_name IS NULL, b.bank_name ASC, e.lastname ASC
        ");
        $it->bind_param('i', $sel_id);
        $it->execute();
        $res = $it->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
            $t_net += (float) $row['net'];
            $bk = $row['bank_name'] ?: '— No bank on file —';
            if (!isset($byBank[$bk])) $byBank[$bk] = ['count' => 0, 'total' => 0];
            $byBank[$bk]['count']++;
            $byBank[$bk]['total'] += (float) $row['net'];
            if (empty($row['bank_name']) || empty($row['bank_account_no'])) $noBank++;
        }
    }
}
?>
<style>
    .bp-missing td { background: #fff8e1 !important; }
    .bp-acct { font-family: ui-monospace, monospace; letter-spacing: .3px; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Bank Payout</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                                <li class="breadcrumb-item active">Bank Payout</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card rpt-card">
                        <div class="card-header align-items-center d-flex">
                            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-bank-line me-1" style="color:#673bb6;"></i>Bank Payout Register</h5>
                            <div class="d-flex gap-2">
                                <?php if ($sel_id): ?>
                                <a href="index.php?page=bank-payout" class="btn btn-sm btn-outline-secondary"><i class="ri-close-line me-1"></i>Clear</a>
                                <?php endif; ?>
                                <?php if ($rows): ?>
                                <a class="btn btn-sm btn-outline-success" href="export-bank-payout.php?id=<?= $sel_id ?>"><i class="ri-file-excel-2-line me-1"></i>CSV</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="ri-printer-line"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-bankpayout">
                                    <i class="ri-filter-3-line me-1"></i>Filter
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($sel_pay): ?>
                            <div class="rpt-filter-bar">
                                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                                <span>Payroll: <span class="val"><?= htmlspecialchars($sel_pay['ref_no']) ?></span></span>
                                <span>Period: <span class="val"><?= date('M j', strtotime($sel_pay['date_from'])) ?> &ndash; <?= date('M j, Y', strtotime($sel_pay['date_to'])) ?></span></span>
                                <span>Status: <span class="val"><?= (int) $sel_pay['status'] === 2 ? 'Locked' : 'Not locked' ?></span></span>
                                <span class="ms-auto"><span class="val"><?= count($rows) ?></span> employee(s)</span>
                            </div>
                            <?php endif; ?>

                            <?php if ($sel_pay && (int) $sel_pay['status'] !== 2): ?>
                                <div class="alert alert-warning py-2 d-flex align-items-center gap-2" style="font-size:12.5px;">
                                    <i class="ri-alert-line fs-16"></i>
                                    <span>Payroll not yet locked — amounts may still change.</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!$sel_id): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="ri-bank-line" style="font-size:42px;"></i>
                                    <p class="mt-2">Pick a payroll period from <b>Filter</b> to build the payout list.</p>
                                    <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-bankpayout">
                                        <i class="ri-filter-3-line me-1"></i>Choose payroll period
                                    </button>
                                </div>
                            <?php elseif (!$rows): ?>
                                <div class="text-center text-muted py-5">No payroll items found for this period.</div>
                            <?php else: ?>
                                <?php if ($noBank > 0): ?>
                                    <div class="alert alert-warning py-2 d-flex align-items-center gap-2" style="font-size:12.5px;">
                                        <i class="ri-error-warning-line fs-16"></i>
                                        <span><b><?= $noBank ?></b> employee(s) have no bank or account number on file (highlighted below) — set them in the employee form, or pay these in cash.</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Per-bank summary -->
                                <div class="row g-2 mb-3">
                                    <?php foreach ($byBank as $bk => $s): ?>
                                        <div class="col-auto">
                                            <div style="border:1px solid #e6eaf0;border-radius:8px;padding:6px 14px;background:#f8f9fa;">
                                                <div style="font-size:11px;font-weight:700;color:#555;"><?= htmlspecialchars($bk) ?></div>
                                                <div style="font-size:13px;font-weight:800;color:#107c41;">&#8369; <?= number_format($s['total'], 2) ?> <small class="text-muted fw-normal">(<?= $s['count'] ?>)</small></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle">
                                        <thead class="table-dark">
                                            <tr>
                                                <th style="width:36px;" class="text-center">#</th>
                                                <th>Employee No.</th>
                                                <th>Name</th>
                                                <th>Department</th>
                                                <th>Bank</th>
                                                <th>Account Number</th>
                                                <th class="text-end">Net Pay</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 0; foreach ($rows as $r): $i++;
                                                $missing = empty($r['bank_name']) || empty($r['bank_account_no']); ?>
                                                <tr class="<?= $missing ? 'bp-missing' : '' ?>">
                                                    <td class="text-center"><?= $i ?></td>
                                                    <td><?= htmlspecialchars($r['employee_no']) ?></td>
                                                    <td><b><?= htmlspecialchars($r['name']) ?></b></td>
                                                    <td style="font-size:12px;"><?= htmlspecialchars($r['department'] ?? '—') ?></td>
                                                    <td>
                                                        <?= $r['bank_name'] ? htmlspecialchars($r['bank_name']) : '<span class="text-danger fw-bold">No bank</span>' ?>
                                                    </td>
                                                    <td class="bp-acct">
                                                        <?= $r['bank_account_no'] ? htmlspecialchars($r['bank_account_no']) : '<span class="text-danger">—</span>' ?>
                                                    </td>
                                                    <td class="text-end fw-bold"><?= number_format($r['net'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-bold">
                                                <th colspan="6" class="text-end">TOTAL (<?= count($rows) ?> employees)</th>
                                                <th class="text-end">&#8369; <?= number_format($t_net, 2) ?></th>
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
<div class="modal fade" id="modal-filter-bankpayout" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="bank-payout">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#673bb6;"></i>Filter Bank Payout</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-calendar-range-line me-1"></i>Payroll Period</label>
                        <select name="id" class="form-control" data-cs-icon="ri-calendar-range-line" data-cs-search="true">
                            <option value="">— Select payroll period —</option>
                            <?php foreach ($payrolls as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $sel_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['ref_no']) ?> — <?= date('M j', strtotime($p['date_from'])) ?>–<?= date('M j, Y', strtotime($p['date_to'])) ?><?= (int) $p['status'] === 2 ? ' (Locked)' : '' ?>
                                </option>
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
