<?php
// Payroll List Report — every payroll run with employer, period, status, employees
// and net total. Filter by date range (Bootstrap datetimepicker) + status + employer
// (searchable select). Included via index.php (?page=payroll-report).

$employers = [];
$eq = $conn->query("SELECT id, employer_name FROM employers ORDER BY employer_name ASC");
if ($eq) while ($e = $eq->fetch_assoc()) $employers[] = $e;

$f_from     = isset($_GET['from']) ? trim($_GET['from']) : '';
$f_to       = isset($_GET['to'])   ? trim($_GET['to'])   : '';
$f_status   = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$f_employer = isset($_GET['employer_id']) && $_GET['employer_id'] !== '' ? (int)$_GET['employer_id'] : 0;
$has_filter = ($f_from !== '' || $f_to !== '' || $f_status !== -1 || $f_employer);

$where = ['1'];
if ($f_from !== '' && $f_to !== '') {
    $from = $conn->real_escape_string(date('Y-m-d', strtotime($f_from)));
    $to   = $conn->real_escape_string(date('Y-m-d', strtotime($f_to)));
    $where[] = "p.date_from <= '$to' AND p.date_to >= '$from'"; // period overlaps range
} elseif ($f_from !== '') {
    $from = $conn->real_escape_string(date('Y-m-d', strtotime($f_from)));
    $where[] = "p.date_to >= '$from'";
} elseif ($f_to !== '') {
    $to = $conn->real_escape_string(date('Y-m-d', strtotime($f_to)));
    $where[] = "p.date_from <= '$to'";
}
if ($f_status !== -1)  $where[] = "p.status = " . $f_status;
if ($f_employer)       $where[] = "p.employer_id = " . $f_employer;
$wsql = implode(' AND ', $where);

$rows = [];
$tot_net = 0; $tot_emp = 0;
$q = $conn->query("
    SELECT p.id, p.ref_no, p.date_from, p.date_to, p.status, e.employer_name,
           pi.emp_ct, pi.net_total
    FROM payroll p
    LEFT JOIN employers e ON e.id = p.employer_id
    LEFT JOIN (SELECT payroll_id, COUNT(*) AS emp_ct, SUM(net) AS net_total FROM payroll_items GROUP BY payroll_id) pi
           ON pi.payroll_id = p.id
    WHERE $wsql
    ORDER BY p.date_from DESC
");
if ($q) while ($r = $q->fetch_assoc()) {
    $rows[] = $r;
    $tot_net += (float)$r['net_total'];
    $tot_emp += (int)$r['emp_ct'];
}

function plr_status($s) {
    switch ((int)$s) {
        case 0: return '<span class="badge rounded-pill bg-primary">New</span>';
        case 1: return '<span class="badge rounded-pill bg-success">Calculated</span>';
        case 3: return '<span class="badge rounded-pill bg-warning text-dark">Ready for Review</span>';
        case 2: return '<span class="badge rounded-pill bg-danger">Locked</span>';
        default:return '<span class="badge rounded-pill bg-secondary">—</span>';
    }
}
function plr_money($v){ return '₱' . number_format((float)$v, 2); }
?>
<style>
    .plr-filter-bar { background:#009688; color:#fff; border-radius:6px; padding:9px 16px; margin-bottom:12px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; font-size:12px; }
    .plr-filter-bar .val { font-weight:700; }
    .plr-ref { font-weight:700; color:#1976d2; font-family:monospace; }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-list-check-2 me-2" style="color:#009688;"></i>Payroll List Report</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Payroll List Report</li>
            </ol>
        </div>
    </div></div>

    <div class="card rpt-card">
        <div class="card-header align-items-center d-flex">
            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-money-dollar-circle-line me-1 text-success"></i>Payroll Runs</h5>
            <div class="d-flex gap-2">
                <?php if ($has_filter): ?>
                <a href="index.php?page=payroll-report" class="btn btn-sm btn-outline-secondary"><i class="ri-close-line me-1"></i>Clear</a>
                <?php endif; ?>
                <button type="button" onclick="repExportCSV('report-table','payroll-list-report.csv')" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                <button type="button" class="btn btn-sm text-white" style="background:#009688;border-color:#009688;" data-bs-toggle="modal" data-bs-target="#modal-filter-add">
                    <i class="ri-filter-3-line me-1"></i>Filter
                </button>
            </div>
        </div>
        <div class="card-body">

            <?php if ($has_filter): ?>
            <div class="plr-filter-bar">
                <i class="ri-filter-3-line" style="opacity:.85;"></i>
                <?php if ($f_from !== '' || $f_to !== ''): ?>
                    <span>Period: <span class="val"><?= $f_from !== '' ? htmlspecialchars($f_from) : '…' ?> &ndash; <?= $f_to !== '' ? htmlspecialchars($f_to) : '…' ?></span></span>
                <?php endif; ?>
                <?php if ($f_status !== -1): ?><span>Status: <span class="val"><?= strip_tags(plr_status($f_status)) ?></span></span><?php endif; ?>
                <?php if ($f_employer): foreach ($employers as $e) if ($e['id']==$f_employer): ?><span>Employer: <span class="val"><?= htmlspecialchars($e['employer_name']) ?></span></span><?php endif; endif; ?>
                <span class="ms-auto"><span class="val"><?= count($rows) ?></span> payroll(s)</span>
            </div>
            <?php endif; ?>

            <div class="rpt-scroll">
                <table id="report-table" class="table align-middle mb-0 rpt-table">
                    <thead>
                        <tr>
                            <th>Payroll ID</th>
                            <th>Employer</th>
                            <th>Period</th>
                            <th class="text-center">Employees</th>
                            <th class="rpt-num rpt-net">Net Total</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No payroll runs match the filter.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><span class="plr-ref"><?= htmlspecialchars($r['ref_no']) ?></span></td>
                                <td><?= htmlspecialchars($r['employer_name'] ?? '—') ?></td>
                                <td><?= date('M d', strtotime($r['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($r['date_to'])) ?></td>
                                <td class="text-center"><?= (int)$r['emp_ct'] ?></td>
                                <td class="rpt-num rpt-net"><?= $r['net_total'] !== null ? plr_money($r['net_total']) : '—' ?></td>
                                <td class="text-center"><?= plr_status($r['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end">TOTAL</td>
                            <td class="text-center"><?= $tot_emp ?></td>
                            <td class="rpt-num rpt-net"><?= plr_money($tot_net) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</div>
</div>
</div>

<!-- Filter modal (date range picker + searchable selects) -->
<div class="modal fade" id="modal-filter-add" tabindex="-1" role="dialog">
    <form method="get" action="" novalidate>
        <input type="hidden" name="page" value="payroll-report">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0"><i class="ri-filter-3-line me-2" style="color:#009688;"></i>Filter Payroll List</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;">
                            <i class="ri-calendar-range-line me-1"></i>Date Range <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">From</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                                    <input name="from" class="form-control datetimepicker" autocomplete="off" placeholder="YYYY/MM/DD" value="<?= htmlspecialchars($f_from) ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted mb-1">To</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                                    <input name="to" class="form-control datetimepicker" autocomplete="off" placeholder="YYYY/MM/DD" value="<?= htmlspecialchars($f_to) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;"><i class="ri-pulse-line me-1"></i>Status</label>
                        <select name="status" class="form-control report-select2" data-placeholder="— All Statuses —">
                            <option value="" <?= $f_status===-1?'selected':'' ?>>— All Statuses —</option>
                            <option value="0" <?= $f_status===0?'selected':'' ?>>New</option>
                            <option value="1" <?= $f_status===1?'selected':'' ?>>Calculated</option>
                            <option value="3" <?= $f_status===3?'selected':'' ?>>Ready for Review</option>
                            <option value="2" <?= $f_status===2?'selected':'' ?>>Locked</option>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;"><i class="ri-building-2-line me-1"></i>Employer <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="employer_id" class="form-control report-select2" data-placeholder="— All Employers —">
                            <option value="" <?= !$f_employer?'selected':'' ?>>— All Employers —</option>
                            <?php foreach ($employers as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $f_employer==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['employer_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8f9fa;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="ri-close-line me-1"></i>Cancel</button>
                    <button type="submit" class="btn btn-sm text-white" style="background:#009688;border-color:#009688;"><i class="ri-search-line me-1"></i>Apply Filter</button>
                </div>
            </div>
        </div>
    </form>
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
