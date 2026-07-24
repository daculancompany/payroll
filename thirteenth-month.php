<?php
// ── 13th Month Pay (PD 851) ─────────────────────────────────────────────
// Computes each employee's 13th month = basic salary earned in the year ÷ 12,
// sourced from the year's payroll runs. Draft rows are editable (override +
// remarks); Finalize locks the year. Basis knobs live in Pay Settings (th13_*).

$th13_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// Years that have payrolls, plus the current year.
$th13_years = [];
$yq = $conn->query("SELECT DISTINCT YEAR(date_from) AS y FROM payroll ORDER BY y DESC");
if ($yq) while ($y = $yq->fetch_assoc()) $th13_years[] = (int) $y['y'];
if (!in_array((int) date('Y'), $th13_years, true)) array_unshift($th13_years, (int) date('Y'));
if (!in_array($th13_year, $th13_years, true)) $th13_years[] = $th13_year;

// Basis flags (Pay Settings) — displayed so the admin knows what's counted.
$th13_flags = ['th13_include_paid_leave' => 1, 'th13_include_allowance' => 0, 'th13_round_to_peso' => 0];
$fq = $conn->query("SELECT setting_key, setting_value FROM pay_settings WHERE setting_key LIKE 'th13%'");
if ($fq) while ($f = $fq->fetch_assoc()) $th13_flags[$f['setting_key']] = (float) $f['setting_value'];

// Rows for the chosen year.
$th13_rows = [];
$th13_finalized = false;
$rq = $conn->query("SELECT t.*, e.employee_no, e.lastname, e.firstname, d.name AS department
    FROM thirteenth_month t
    INNER JOIN employee e ON e.id = t.employee_id
    LEFT JOIN department d ON d.id = e.department_id
    WHERE t.year = $th13_year
    ORDER BY e.lastname ASC, e.firstname ASC");
if ($rq) while ($r = $rq->fetch_assoc()) {
    $th13_rows[] = $r;
    if ((int) $r['status'] === 1) $th13_finalized = true;
}
$t_basic = 0; $t_amount = 0; $t_final = 0; $t_unlocked = 0;
foreach ($th13_rows as $r) {
    $t_basic  += (float) $r['basic_earned'];
    $t_amount += (float) $r['amount'];
    $t_final  += $r['override_amount'] !== null ? (float) $r['override_amount'] : (float) $r['amount'];
    $t_unlocked += (int) $r['unlocked_cutoffs'];
}
?>
<style>
    .th13-input { font-size: 12px; padding: 3px 8px; text-align: right; }
    .th13-remarks { font-size: 11px; padding: 3px 8px; }
    .th13-table td { vertical-align: middle; }
    .th13-computed { font-weight: 700; color: #107c41; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <div class="d-flex align-items-center gap-2">
                            <h4 class="mb-sm-0">13th Month Pay</h4>
                            <?php if ($th13_finalized): ?>
                                <span class="badge bg-danger"><i class="ri-lock-fill me-1"></i>Finalized</span>
                            <?php elseif ($th13_rows): ?>
                                <span class="badge bg-success"><i class="ri-draft-line me-1"></i>Draft</span>
                            <?php endif; ?>
                        </div>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Payroll</a></li>
                                <li class="breadcrumb-item active">13th Month Pay</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <select id="th13-year" class="form-select form-select-sm" style="width:110px;"
                                onchange="location.href='index.php?page=thirteenth-month&year=' + this.value">
                                <?php foreach ($th13_years as $y): ?>
                                    <option value="<?= $y ?>" <?= $y === $th13_year ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-success" onclick="th13Generate()" <?= $th13_finalized ? 'disabled' : '' ?>>
                                <i class="ri-refresh-line me-1"></i><?= $th13_rows ? 'Recompute' : 'Generate' ?>
                            </button>
                            <?php if ($th13_rows && !$th13_finalized): ?>
                                <button class="btn btn-sm btn-danger" onclick="th13Finalize(1)">
                                    <i class="ri-lock-line me-1"></i>Finalize <?= $th13_year ?>
                                </button>
                            <?php elseif ($th13_finalized && (int)($_SESSION['login_role'] ?? 0) === 1): ?>
                                <button class="btn btn-sm btn-outline-warning" onclick="th13Finalize(0)">
                                    <i class="ri-lock-unlock-line me-1"></i>Unfinalize
                                </button>
                            <?php endif; ?>
                            <?php if ($th13_rows): ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick="window.open('pdf-payroll.php?src=13th&id=<?= $th13_year ?>', '_blank')">
                                    <i class="ri-printer-line me-1"></i>Print Register
                                </button>
                            <?php endif; ?>
                            <span class="ms-auto text-muted" style="font-size:11px;">
                                Basis: basic pay<?= $th13_flags['th13_include_paid_leave'] >= 1 ? ' + paid leave' : '' ?><?= $th13_flags['th13_include_allowance'] >= 1 ? ' + allowance' : '' ?>
                                &middot; <?= $th13_flags['th13_round_to_peso'] >= 1 ? 'rounded to peso' : 'centavo-exact' ?>
                                &middot; <a href="index.php?page=pay-settings">change</a>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if ($t_unlocked > 0 && !$th13_finalized): ?>
                                <div class="alert alert-warning d-flex align-items-center gap-2 py-2" style="font-size:12.5px;">
                                    <i class="ri-alert-line fs-16"></i>
                                    <span><b><?= $t_unlocked ?></b> counted cutoff(s) come from payrolls that are not yet <b>Locked</b> — those amounts can still change. Recompute after locking.</span>
                                </div>
                            <?php endif; ?>

                            <?php if (!$th13_rows): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="ri-hand-coin-line" style="font-size:42px;"></i>
                                    <p class="mt-2 mb-1">No 13th month data for <b><?= $th13_year ?></b> yet.</p>
                                    <p style="font-size:12px;">Click <b>Generate</b> to compute from the year's payroll runs.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered align-middle th13-table">
                                        <thead class="table-dark">
                                            <tr>
                                                <th style="width:36px;" class="text-center">#</th>
                                                <th>Employee</th>
                                                <th>Department</th>
                                                <th class="text-center">Cutoffs</th>
                                                <th class="text-end">Basic Earned</th>
                                                <th class="text-end">&divide; 12</th>
                                                <th class="text-end" style="width:140px;">Final Amount</th>
                                                <th style="width:220px;">Remarks</th>
                                                <?php if (!$th13_finalized): ?><th style="width:60px;" class="text-center">Save</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 0; foreach ($th13_rows as $r): $i++;
                                                $final = $r['override_amount'] !== null ? (float) $r['override_amount'] : (float) $r['amount']; ?>
                                                <tr data-row="<?= (int) $r['id'] ?>">
                                                    <td class="text-center"><?= $i ?></td>
                                                    <td>
                                                        <b><?= htmlspecialchars($r['lastname'] . ', ' . $r['firstname']) ?></b>
                                                        <div class="text-muted" style="font-size:10.5px;"><?= htmlspecialchars($r['employee_no']) ?></div>
                                                    </td>
                                                    <td style="font-size:12px;"><?= htmlspecialchars($r['department'] ?? '—') ?></td>
                                                    <td class="text-center">
                                                        <?= (int) $r['cutoffs'] ?>
                                                        <?php if ((int) $r['unlocked_cutoffs'] > 0 && !$th13_finalized): ?>
                                                            <i class="ri-alert-line text-warning" title="<?= (int) $r['unlocked_cutoffs'] ?> cutoff(s) not yet locked"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end"><?= number_format($r['basic_earned'], 2) ?></td>
                                                    <td class="text-end th13-computed"><?= number_format($r['amount'], 2) ?></td>
                                                    <td class="text-end">
                                                        <?php if ($th13_finalized): ?>
                                                            <b><?= number_format($final, 2) ?></b>
                                                        <?php else: ?>
                                                            <input type="number" step="0.01" min="0" class="form-control th13-input th13-override"
                                                                value="<?= $r['override_amount'] !== null ? htmlspecialchars($r['override_amount']) : '' ?>"
                                                                placeholder="<?= number_format($r['amount'], 2, '.', '') ?>"
                                                                title="Leave blank to use the computed amount">
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($th13_finalized): ?>
                                                            <span style="font-size:11px;"><?= htmlspecialchars($r['remarks'] ?? '') ?></span>
                                                        <?php else: ?>
                                                            <input type="text" maxlength="255" class="form-control th13-remarks th13-remarks-input"
                                                                value="<?= htmlspecialchars($r['remarks'] ?? '') ?>" placeholder="Remarks (optional)">
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if (!$th13_finalized): ?>
                                                        <td class="text-center">
                                                            <button class="btn btn-sm btn-outline-success py-0 px-2" onclick="th13SaveRow(<?= (int) $r['id'] ?>, this)" title="Save this row">
                                                                <i class="ri-save-line"></i>
                                                            </button>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="fw-bold">
                                                <th colspan="4" class="text-end">TOTAL</th>
                                                <th class="text-end"><?= number_format($t_basic, 2) ?></th>
                                                <th class="text-end"><?= number_format($t_amount, 2) ?></th>
                                                <th class="text-end">&#8369; <?= number_format($t_final, 2) ?></th>
                                                <th colspan="<?= $th13_finalized ? 1 : 2 ?>"></th>
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

<script>
var TH13_YEAR = <?= (int) $th13_year ?>;

function th13Generate() {
    Swal.fire({ title: 'Computing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.post('ajax.php?action=th13_generate', { year: TH13_YEAR }, function (res) {
        if (res?.result) {
            location.href = 'index.php?page=thirteenth-month&year=' + TH13_YEAR;
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res?.message || 'Failed.' });
        }
    }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }));
}

function th13SaveRow(id, btn) {
    var tr = btn.closest('tr');
    var override = tr.querySelector('.th13-override').value.trim();
    var remarks = tr.querySelector('.th13-remarks-input').value.trim();
    $.post('ajax.php?action=th13_save_row', { id: id, override_amount: override, remarks: remarks }, function (res) {
        if (res?.result) {
            location.href = 'index.php?page=thirteenth-month&year=' + TH13_YEAR;
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res?.message || 'Failed.' });
        }
    }, 'json');
}

function th13Finalize(to) {
    Swal.fire({
        title: to === 1 ? 'Finalize ' + TH13_YEAR + '?' : 'Unfinalize ' + TH13_YEAR + '?',
        text: to === 1
            ? 'All rows will be locked. Only an administrator can unfinalize.'
            : 'Rows become editable and can be recomputed again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: to === 1 ? '#d33' : '#f0ad4e',
        confirmButtonText: to === 1 ? 'Yes, finalize' : 'Yes, unfinalize',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.post('ajax.php?action=th13_set_final', { year: TH13_YEAR, finalize: to }, function (res) {
            if (res?.result) {
                location.href = 'index.php?page=thirteenth-month&year=' + TH13_YEAR;
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res?.message || 'Failed.' });
            }
        }, 'json');
    });
}
</script>
