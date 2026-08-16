<?php
// Payroll Register — per-run accounting summary: gross, contributions, deductions,
// loans, tax and net for every employee. Included via index.php (?page=payroll-register).

$payrolls = [];
$pr = $conn->query("SELECT id, ref_no, date_from, date_to, status FROM payroll WHERE status >= 1 ORDER BY date_from DESC");
while ($r = $pr->fetch_assoc()) $payrolls[] = $r;

$sel_id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sel_pay = null;
$rows = [];
$tot = ['basic'=>0,'allow'=>0,'ot'=>0,'contrib'=>0,'deduct'=>0,'loans'=>0,'tax'=>0,'net'=>0];

if ($sel_id) {
    $st = $conn->prepare("SELECT id, ref_no, date_from, date_to, status FROM payroll WHERE id = ?");
    $st->bind_param('i', $sel_id); $st->execute();
    $sel_pay = $st->get_result()->fetch_assoc();

    if ($sel_pay) {
        $it = $conn->prepare("
            SELECT pi.*, e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS emp
            FROM payroll_items pi
            INNER JOIN employee e ON e.id = pi.employee_id
            WHERE pi.payroll_id = ?
            ORDER BY e.lastname, e.firstname
        ");
        $it->bind_param('i', $sel_id); $it->execute();
        $res = $it->get_result();
        while ($row = $res->fetch_assoc()) {
            $loan_sum = 0;
            $lj = json_decode($row['loans'], true);
            if (is_array($lj)) foreach ($lj as $l) $loan_sum += (float)($l['amount'] ?? 0);

            $basic  = (float)$row['basic_pay'];
            $allow  = (float)$row['allowance_amount'];
            $ot     = (float)$row['ot_amount'];
            $contrib= (float)$row['contribute_amount'];
            $deduct = (float)$row['deduction_amount'];
            $tax    = (float)$row['tax'];
            $net    = (float)$row['net'];

            $rows[] = compact('basic','allow','ot','contrib','deduct','tax','net','loan_sum') + [
                'emp' => $row['emp'], 'employee_no' => $row['employee_no'],
                'employee_id' => $row['employee_id'],
            ];
            $tot['basic']+=$basic; $tot['allow']+=$allow; $tot['ot']+=$ot; $tot['contrib']+=$contrib;
            $tot['deduct']+=$deduct; $tot['loans']+=$loan_sum; $tot['tax']+=$tax; $tot['net']+=$net;
        }
    }
}
function pr_money($v){ return '₱' . number_format((float)$v, 2); }
?>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-file-list-3-line me-2" style="color:#673bb6;"></i>Payroll Register</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Payroll Register</li>
            </ol>
        </div>
    </div></div>

    <div class="card rpt-card mb-3" style="border-top:3px solid #673bb6;">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="payroll-register">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;"><i class="ri-calendar-2-line me-1"></i>Payroll Period</label>
                    <select name="id" class="form-control" data-cs-icon="ri-calendar-2-line" onchange="this.form.submit()">
                        <option value="">Select a payroll period…</option>
                        <?php foreach ($payrolls as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $sel_id==$p['id']?'selected':'' ?>>
                            <?= htmlspecialchars($p['ref_no']) ?> | <?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?> <?= $p['status']==2?'(Locked)':'(Open)' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <?php if ($sel_id && $sel_pay): ?>
                    <button type="button" onclick="repExportCSV('pr-table','payroll-register.csv')" class="btn btn-sm" style="background:#673bb6;color:#fff;font-weight:700;"><i class="ri-file-excel-2-line me-1"></i>Export CSV</button>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line me-1"></i>Print</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$sel_id): ?>
        <div class="card rpt-card"><div class="card-body text-center py-5 text-muted">
            <i class="ri-file-list-3-line" style="font-size:40px;opacity:.4;display:block;margin-bottom:8px;"></i>
            Select a payroll period to view the register.
        </div></div>
    <?php elseif (!$sel_pay): ?>
        <div class="alert alert-warning">Payroll period not found.</div>
    <?php else: ?>

    <div style="background:linear-gradient(135deg,#673bb6,#4e3483);border-radius:12px;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;color:#fff;margin-bottom:14px;">
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.85;">Net Payroll — <?= htmlspecialchars($sel_pay['ref_no']) ?></div>
            <div style="font-size:11px;opacity:.8;margin-top:2px;"><?= date('M d', strtotime($sel_pay['date_from'])) ?> – <?= date('M d, Y', strtotime($sel_pay['date_to'])) ?> &bull; <?= count($rows) ?> employees</div>
        </div>
        <div style="font-size:24px;font-weight:900;"><?= pr_money($tot['net']) ?></div>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-body p-0">
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="pr-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Employee</th>
                            <th class="rpt-num">Basic</th><th class="rpt-num">Allowance</th><th class="rpt-num">OT</th>
                            <th class="rpt-num">Contributions</th><th class="rpt-num">Deductions</th><th class="rpt-num">Loans</th>
                            <th class="rpt-num">Tax</th><th class="rpt-num rpt-net">Net Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No payslip items for this period.</td></tr>
                        <?php else: $i=1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><a href="index.php?page=employee-details&id=<?= (int)$r['employee_id'] ?>" class="rpt-emp-link" title="View employee details"><?= htmlspecialchars($r['emp']) ?></a><br><small class="text-muted"><?= htmlspecialchars($r['employee_no']) ?></small></td>
                                <td class="rpt-num"><?= pr_money($r['basic']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['allow']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['ot']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['contrib']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['deduct']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['loan_sum']) ?></td>
                                <td class="rpt-num"><?= pr_money($r['tax']) ?></td>
                                <td class="rpt-num rpt-net"><?= pr_money($r['net']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end">TOTAL</td>
                            <td class="rpt-num"><?= pr_money($tot['basic']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['allow']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['ot']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['contrib']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['deduct']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['loans']) ?></td>
                            <td class="rpt-num"><?= pr_money($tot['tax']) ?></td>
                            <td class="rpt-num rpt-net"><?= pr_money($tot['net']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>
</div>

<script>
    function repExportCSV(tableId, filename) {
        var t = document.getElementById(tableId);
        if (!t) return;
        var csv = [];
        t.querySelectorAll('tr').forEach(function (tr) {
            var cells = tr.querySelectorAll('th,td'), row = [];
            cells.forEach(function (c) {
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
