<?php
// Government Remittance Report — included via index.php (Payroll Tools)
// Aggregates SSS / PhilHealth / Pag-IBIG (from payroll_items.contributions JSON),
// plus SSS Provident Fund and Withholding Tax (columns), per payroll period.

// Contribution name → bucket mapping (handles common aliases)
function rr_bucket($name) {
    $n = strtoupper(trim($name));
    if (strpos($n, 'SSS') !== false)        return 'SSS';
    if (strpos($n, 'PHIC') !== false || strpos($n, 'PHILHEALTH') !== false || strpos($n, 'PHIL') !== false) return 'PhilHealth';
    if (strpos($n, 'HDMF') !== false || strpos($n, 'PAG') !== false) return 'Pag-IBIG';
    return $name; // keep other contributions under their own name
}

// Payroll list for the selector
$payrolls = [];
$pr = $conn->query("SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status
                    FROM payroll p WHERE p.status >= 1 ORDER BY p.date_from DESC");
while ($r = $pr->fetch_assoc()) $payrolls[] = $r;

$sel_id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sel_pay  = null;
$rows     = [];           // per-employee remittance rows
$contrib_names = [];      // map contribution_id => name
$totals   = ['SSS'=>0,'PhilHealth'=>0,'Pag-IBIG'=>0,'SSS Fund'=>0,'Tax'=>0,'grand'=>0];

if ($sel_id) {
    // selected payroll header
    $st = $conn->prepare("SELECT id, ref_no, date_from, date_to, status FROM payroll WHERE id = ?");
    $st->bind_param('i', $sel_id); $st->execute();
    $sel_pay = $st->get_result()->fetch_assoc();

    if ($sel_pay) {
        // contribution id → name
        $cn = $conn->query("SELECT id, contribution FROM contributions");
        while ($c = $cn->fetch_assoc()) $contrib_names[(int)$c['id']] = $c['contribution'];

        // Fallback map: employee_id => [contribution_id => amount] from employee_contributions
        $ec_map = [];
        $ecq = $conn->query("SELECT employee_id, contribution_id, MAX(amount) AS amount
                             FROM employee_contributions GROUP BY employee_id, contribution_id");
        if ($ecq) while ($e = $ecq->fetch_assoc()) {
            $ec_map[(int)$e['employee_id']][(int)$e['contribution_id']] = (float)$e['amount'];
        }

        // per-employee items
        $it = $conn->prepare("
            SELECT pi.employee_id, e.employee_no, e.sss_no, e.ph_no, e.hdmf_no, e.tin_no,
                   CONCAT(e.lastname, ', ', e.firstname) AS name,
                   pi.contributions, pi.sss_fund, pi.tax
            FROM payroll_items pi
            INNER JOIN employee e ON e.id = pi.employee_id
            WHERE pi.payroll_id = ?
            ORDER BY e.lastname, e.firstname
        ");
        $it->bind_param('i', $sel_id); $it->execute();
        $res = $it->get_result();

        while ($row = $res->fetch_assoc()) {
            $b = ['SSS'=>0,'PhilHealth'=>0,'Pag-IBIG'=>0];
            $extra = [];
            $json = json_decode($row['contributions'], true);
            if (is_array($json)) {
                foreach ($json as $c) {
                    $cid = (int)($c['contribution_id'] ?? 0);
                    $amt = (float)($c['amount'] ?? 0);
                    if ($amt == 0) continue;
                    $bk = rr_bucket($contrib_names[$cid] ?? 'Other');
                    if (isset($b[$bk])) $b[$bk] += $amt; else { $extra[$bk] = ($extra[$bk] ?? 0) + $amt; }
                }
            }
            // Fallback: if the payslip had no itemised contributions, use the
            // employee's configured employee_contributions amounts.
            if ($b['SSS'] == 0 && $b['PhilHealth'] == 0 && $b['Pag-IBIG'] == 0 && empty($extra)
                && isset($ec_map[(int)$row['employee_id']])) {
                foreach ($ec_map[(int)$row['employee_id']] as $cid => $amt) {
                    if ($amt == 0) continue;
                    $bk = rr_bucket($contrib_names[$cid] ?? 'Other');
                    if (isset($b[$bk])) $b[$bk] += $amt; else { $extra[$bk] = ($extra[$bk] ?? 0) + $amt; }
                }
            }
            $sssfund = (float)$row['sss_fund'];
            $tax     = (float)$row['tax'];
            $line_total = $b['SSS'] + $b['PhilHealth'] + $b['Pag-IBIG'] + $sssfund + $tax + array_sum($extra);

            $rows[] = [
                'employee_id' => $row['employee_id'],
                'name' => $row['name'], 'employee_no' => $row['employee_no'],
                'sss_no' => $row['sss_no'], 'ph_no' => $row['ph_no'], 'hdmf_no' => $row['hdmf_no'], 'tin_no' => $row['tin_no'],
                'SSS' => $b['SSS'], 'PhilHealth' => $b['PhilHealth'], 'Pag-IBIG' => $b['Pag-IBIG'],
                'SSS Fund' => $sssfund, 'Tax' => $tax, 'total' => $line_total,
            ];
            $totals['SSS']        += $b['SSS'];
            $totals['PhilHealth'] += $b['PhilHealth'];
            $totals['Pag-IBIG']   += $b['Pag-IBIG'];
            $totals['SSS Fund']   += $sssfund;
            $totals['Tax']        += $tax;
            $totals['grand']      += $line_total;
        }
    }
}
function rr_money($v){ return '₱'.number_format((float)$v, 2); }
?>
<style>
    .rr-stat { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:14px 16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 6px rgba(0,0,0,.05); height:100%; }
    .rr-stat .ic { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .rr-stat .lbl { font-size:10px; color:#9aa3ad; text-transform:uppercase; letter-spacing:.4px; }
    .rr-stat .val { font-size:16px; font-weight:800; line-height:1.15; }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-government-line me-2" style="color:#673bb6;"></i>Government Remittance Report</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="javascript:void(0);">Payroll Tools</a></li>
                <li class="breadcrumb-item active">Remittance Report</li>
            </ol>
        </div>
    </div></div>

    <?php if ($sel_id && $sel_pay): ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['SSS','ri-shield-check-line','#d32f2f','#fdecec'],
            ['PhilHealth','ri-heart-pulse-line','#1976d2','#e3f2fd'],
            ['Pag-IBIG','ri-home-4-line','#f57c00','#fff3e0'],
            ['SSS Fund','ri-safe-2-line','#6f42c1','#eef0f8'],
            ['Tax','ri-government-line','#673bb6','#eeeaf5'],
        ];
        foreach ($cards as $c): ?>
        <div class="col-6 col-md">
            <div class="rr-stat">
                <div class="ic" style="background:<?= $c[3] ?>;color:<?= $c[2] ?>;"><i class="<?= $c[1] ?>"></i></div>
                <div><div class="lbl"><?= $c[0] ?></div><div class="val" style="color:<?= $c[2] ?>;"><?= rr_money($totals[$c[0]]) ?></div></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Grand total banner -->
    <div style="background:linear-gradient(135deg,#673bb6,#4e3483);border-radius:12px;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;color:#fff;margin-bottom:14px;">
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.85;">Total Remittance</div>
            <div style="font-size:11px;opacity:.8;margin-top:2px;"><?= count($rows) ?> employees</div>
        </div>
        <div style="font-size:24px;font-weight:900;"><?= rr_money($totals['grand']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Detail table -->
    <div class="card rpt-card mb-4">
        <div class="card-header align-items-center d-flex">
            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-government-line me-1" style="color:#673bb6;"></i>Remittance Detail</h5>
            <div class="d-flex gap-2">
                <?php if ($sel_id): ?>
                <a href="index.php?page=remittance-report" class="btn btn-sm btn-outline-secondary"><i class="ri-close-line me-1"></i>Clear</a>
                <?php endif; ?>
                <?php if ($sel_id && $sel_pay): ?>
                <a href="remittance-export.php?id=<?= $sel_id ?>" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</a>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-remittance">
                    <i class="ri-filter-3-line me-1"></i>Filter
                </button>
            </div>
        </div>
        <div class="card-body">

            <?php if ($sel_pay): ?>
            <div class="rpt-filter-bar">
                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                <span>Payroll: <span class="val"><?= htmlspecialchars($sel_pay['ref_no']) ?></span></span>
                <span>Period: <span class="val"><?= date('M d', strtotime($sel_pay['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($sel_pay['date_to'])) ?></span></span>
                <span>Status: <span class="val"><?= (int)$sel_pay['status'] === 2 ? 'Locked' : 'Open' ?></span></span>
                <span class="ms-auto"><span class="val"><?= count($rows) ?></span> employee(s)</span>
            </div>
            <?php endif; ?>

            <?php if (!$sel_id): ?>
                <div class="text-center text-muted py-5">
                    <i class="ri-government-line" style="font-size:40px;opacity:.4;display:block;margin-bottom:8px;"></i>
                    <p class="mt-2">Pick a payroll period from <b>Filter</b> to view SSS, PhilHealth, Pag-IBIG and Tax remittances.</p>
                    <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;" data-bs-toggle="modal" data-bs-target="#modal-filter-remittance">
                        <i class="ri-filter-3-line me-1"></i>Choose payroll period
                    </button>
                </div>
            <?php elseif (!$sel_pay): ?>
                <div class="alert alert-warning mb-0">Payroll period not found.</div>
            <?php else: ?>
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="rr-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th class="rpt-num">SSS</th>
                            <th class="rpt-num">PhilHealth</th>
                            <th class="rpt-num">Pag-IBIG</th>
                            <th class="rpt-num">SSS Fund</th>
                            <th class="rpt-num">Tax</th>
                            <th class="rpt-num rpt-net">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!count($rows)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No payroll items in this period.</td></tr>
                        <?php else: $i=0; foreach ($rows as $r): $i++; ?>
                        <tr>
                            <td style="color:#aaa;"><?= $i ?></td>
                            <td>
                                <div><a href="index.php?page=employee-details&id=<?= (int)$r['employee_id'] ?>" data-emp-quickview="<?= (int)$r['employee_id'] ?>" class="rpt-emp-link" title="View employee details"><?= htmlspecialchars($r['name']) ?></a></div>
                                <div class="rpt-gov">
                                    <?= htmlspecialchars($r['employee_no']) ?>
                                    <?php if ($r['sss_no']): ?> &bull; SSS <?= htmlspecialchars($r['sss_no']) ?><?php endif; ?>
                                    <?php if ($r['tin_no']): ?> &bull; TIN <?= htmlspecialchars($r['tin_no']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="rpt-num"><?= $r['SSS'] ? rr_money($r['SSS']) : '<span style="color:#ddd;">—</span>' ?></td>
                            <td class="rpt-num"><?= $r['PhilHealth'] ? rr_money($r['PhilHealth']) : '<span style="color:#ddd;">—</span>' ?></td>
                            <td class="rpt-num"><?= $r['Pag-IBIG'] ? rr_money($r['Pag-IBIG']) : '<span style="color:#ddd;">—</span>' ?></td>
                            <td class="rpt-num"><?= $r['SSS Fund'] ? rr_money($r['SSS Fund']) : '<span style="color:#ddd;">—</span>' ?></td>
                            <td class="rpt-num"><?= $r['Tax'] ? rr_money($r['Tax']) : '<span style="color:#ddd;">—</span>' ?></td>
                            <td class="rpt-num rpt-net"><?= rr_money($r['total']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">TOTAL (<?= count($rows) ?>)</td>
                            <td class="rpt-num"><?= rr_money($totals['SSS']) ?></td>
                            <td class="rpt-num"><?= rr_money($totals['PhilHealth']) ?></td>
                            <td class="rpt-num"><?= rr_money($totals['Pag-IBIG']) ?></td>
                            <td class="rpt-num"><?= rr_money($totals['SSS Fund']) ?></td>
                            <td class="rpt-num"><?= rr_money($totals['Tax']) ?></td>
                            <td class="rpt-num rpt-net"><?= rr_money($totals['grand']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div></div></div>

<!-- Filter modal — same shape as the Payroll List Report's filter -->
<div class="modal fade" id="modal-filter-remittance" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="remittance-report">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#673bb6;"></i>Filter Remittance Report</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;"><i class="ri-calendar-2-line me-1"></i>Payroll Period</label>
                        <select name="id" id="rr-period" class="form-control" data-cs-icon="ri-calendar-2-line" data-cs-search="true">
                            <option value="">— Select payroll period —</option>
                            <?php foreach ($payrolls as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $sel_id == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['ref_no']) ?> | <?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?>
                                <?= $p['status'] == 2 ? '(Locked)' : '(Open)' ?>
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
