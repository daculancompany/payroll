<?php
// Employee Masterlist — HRIS roster export. Included via index.php (?page=employee-masterlist).

$depts = [];
$dq = $conn->query("SELECT id, name FROM department ORDER BY name ASC");
while ($d = $dq->fetch_assoc()) $depts[] = $d;

$f_dept   = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$f_status = isset($_GET['status']) ? $_GET['status'] : 'active'; // active | inactive | all
$f_q      = isset($_GET['q']) ? trim($_GET['q']) : '';

$where = [];
if ($f_dept) $where[] = "e.department_id = " . $f_dept;
if ($f_status === 'active')   $where[] = "e.status = 1";
if ($f_status === 'inactive') $where[] = "e.status = 0";
if ($f_q !== '') {
    $q = $conn->real_escape_string($f_q);
    $where[] = "(e.employee_no LIKE '%$q%' OR e.firstname LIKE '%$q%' OR e.lastname LIKE '%$q%')";
}
$wsql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$q = $conn->query("
    SELECT e.id, e.employee_no, CONCAT(e.lastname, ', ', e.firstname, ' ', COALESCE(e.middlename,'')) AS name,
           e.salary, e.sss_no, e.ph_no, e.hdmf_no, e.tin_no, e.status, e.bday,
           p.name AS position, d.name AS department, c.clasification AS classification
    FROM employee e
    LEFT JOIN position p ON p.id = e.position_id
    LEFT JOIN department d ON d.id = e.department_id
    LEFT JOIN clasification c ON c.id = e.clasification_id
    $wsql
    ORDER BY e.lastname, e.firstname
");
while ($r = $q->fetch_assoc()) $rows[] = $r;
$active = 0; foreach ($rows as $r) if ((int)$r['status'] === 1) $active++;
?>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-team-line me-2" style="color:#673bb6;"></i>Employee Masterlist</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Employee Masterlist</li>
            </ol>
        </div>
    </div></div>

    <div class="card rpt-card mb-3" style="border-top:3px solid #673bb6;">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="employee-masterlist">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Search</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" class="form-control" placeholder="Name or employee no…">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Department</label>
                    <select name="dept" class="form-control">
                        <option value="0">All departments</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $f_dept==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Status</label>
                    <select name="status" class="form-control">
                        <option value="active"   <?= $f_status==='active'?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $f_status==='inactive'?'selected':'' ?>>Inactive</option>
                        <option value="all"      <?= $f_status==='all'?'selected':'' ?>>All</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm w-100" style="background:#673bb6;color:#fff;font-weight:700;"><i class="ri-filter-3-line me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="card rpt-card"><div class="card-body py-2"><div style="font-size:10px;color:#9aa3ad;text-transform:uppercase;">Employees Shown</div><div style="font-size:18px;font-weight:800;color:#673bb6;"><?= count($rows) ?></div></div></div></div>
        <div class="col-md-3 col-6"><div class="card rpt-card"><div class="card-body py-2"><div style="font-size:10px;color:#9aa3ad;text-transform:uppercase;">Active</div><div style="font-size:18px;font-weight:800;color:#2e7d32;"><?= $active ?></div></div></div></div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-2">
        <button type="button" onclick="repExportCSV('ml-table','employee-masterlist.csv')" class="btn btn-sm" style="background:#673bb6;color:#fff;font-weight:700;"><i class="ri-file-excel-2-line me-1"></i>Export CSV</button>
        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line me-1"></i>Print</button>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-body p-0">
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="ml-table">
                    <thead>
                        <tr>
                            <th>Emp No</th><th>Name</th><th>Position</th><th>Department</th><th>Classification</th>
                            <th class="rpt-num">Salary</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>TIN</th>
                            <th>Birthday</th><th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="12" class="text-center text-muted py-4">No employees match the filter.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($r['employee_no']) ?></td>
                                <td><a href="index.php?page=employee-details&id=<?= (int)$r['id'] ?>" data-emp-quickview="<?= (int)$r['id'] ?>" class="rpt-emp-link" title="View employee details"><?= htmlspecialchars(trim($r['name'])) ?></a></td>
                                <td><?= htmlspecialchars($r['position'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['department'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['classification'] ?? '—') ?></td>
                                <td class="rpt-num"><?= $r['salary'] !== null ? '₱' . number_format((float)$r['salary'], 2) : '—' ?></td>
                                <td class="rpt-gov"><?= htmlspecialchars($r['sss_no'] ?: '—') ?></td>
                                <td class="rpt-gov"><?= htmlspecialchars($r['ph_no'] ?: '—') ?></td>
                                <td class="rpt-gov"><?= htmlspecialchars($r['hdmf_no'] ?: '—') ?></td>
                                <td class="rpt-gov"><?= htmlspecialchars($r['tin_no'] ?: '—') ?></td>
                                <td><?= ($r['bday'] && $r['bday']!=='0000-00-00') ? date('M d, Y', strtotime($r['bday'])) : '—' ?></td>
                                <td class="text-center"><?= (int)$r['status']===1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
