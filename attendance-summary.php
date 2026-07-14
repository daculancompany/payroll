<?php
// Attendance / DTR Summary — present days, hours, late, undertime, overtime per
// employee for a date range. Included via index.php (?page=attendance-summary).

$sites = [];
$sq = $conn->query("SELECT id, site_name FROM sites WHERE status = 1 ORDER BY site_name ASC");
while ($s = $sq->fetch_assoc()) $sites[] = $s;

$f_from = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y/m/d', strtotime('first day of this month'));
$f_to   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y/m/d', strtotime('last day of this month'));
$f_site = isset($_GET['site']) ? (int)$_GET['site'] : 0;

// Normalise the picker's YYYY/MM/DD to a MySQL date for the query.
$from = $conn->real_escape_string(date('Y-m-d', strtotime($f_from)));
$to   = $conn->real_escape_string(date('Y-m-d', strtotime($f_to)));
$siteSql = $f_site ? (" AND d.site_id = " . $f_site) : "";

$rows = [];
$tot = ['days'=>0,'hours'=>0,'ot'=>0,'ut'=>0,'late'=>0];
$q = $conn->query("
    SELECT dd.employee_id, e.employee_no, CONCAT(e.lastname, ', ', e.firstname) AS emp,
           COUNT(DISTINCT DATE(dd.date_time)) AS present_days,
           COALESCE(SUM(dd.work_hours),0) AS hours,
           COALESCE(SUM(dd.overtime),0)   AS ot,
           COALESCE(SUM(dd.undertime),0)  AS ut,
           COALESCE(SUM(dd.late),0)       AS late
    FROM DTR_details dd
    INNER JOIN DTR d ON d.id = dd.ddtr_id
    INNER JOIN employee e ON e.id = dd.employee_id
    WHERE dd.date_time BETWEEN '$from' AND '$to'
      AND dd.status = 1
      $siteSql
    GROUP BY dd.employee_id, e.employee_no, emp
    ORDER BY e.lastname, e.firstname
");
if ($q) while ($r = $q->fetch_assoc()) {
    $rows[] = $r;
    $tot['days'] += (int)$r['present_days'];
    $tot['hours'] += (float)$r['hours'];
    $tot['ot'] += (float)$r['ot'];
    $tot['ut'] += (float)$r['ut'];
    $tot['late'] += (float)$r['late'];
}
function as_num($v, $d = 2){ return number_format((float)$v, $d); }
?>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-time-line me-2" style="color:#009688;"></i>Attendance / DTR Summary</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item"><a href="index.php?page=reports">Reports</a></li>
                <li class="breadcrumb-item active">Attendance Summary</li>
            </ol>
        </div>
    </div></div>

    <div class="card rpt-card mb-3" style="border-top:3px solid #009688;">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="attendance-summary">
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">From</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                        <input type="text" name="from" value="<?= htmlspecialchars($f_from) ?>" class="form-control datetimepicker" autocomplete="off" placeholder="YYYY/MM/DD">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">To</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-calendar-2-line"></i></span>
                        <input type="text" name="to" value="<?= htmlspecialchars($f_to) ?>" class="form-control datetimepicker" autocomplete="off" placeholder="YYYY/MM/DD">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Site</label>
                    <select name="site" class="form-control report-select2" data-placeholder="All sites">
                        <option value="0">All sites</option>
                        <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $f_site==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['site_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm" style="background:#009688;color:#fff;font-weight:700;"><i class="ri-filter-3-line me-1"></i>Filter</button>
                    <button type="button" onclick="repExportCSV('as-table','attendance-summary.csv')" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</button>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-body p-0">
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table" id="as-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Employee</th>
                            <th class="rpt-num">Present Days</th><th class="rpt-num">Hours Worked</th>
                            <th class="rpt-num">Overtime (hrs)</th><th class="rpt-num">Undertime</th><th class="rpt-num">Late (mins)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No attendance records for this range.</td></tr>
                        <?php else: $i=1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><a href="index.php?page=employee-details&id=<?= (int)$r['employee_id'] ?>" class="rpt-emp-link" title="View employee details"><?= htmlspecialchars($r['emp']) ?></a><br><small class="text-muted"><?= htmlspecialchars($r['employee_no']) ?></small></td>
                                <td class="rpt-num"><?= (int)$r['present_days'] ?></td>
                                <td class="rpt-num"><?= as_num($r['hours']) ?></td>
                                <td class="rpt-num"><?= as_num($r['ot']) ?></td>
                                <td class="rpt-num"><?= as_num($r['ut']) ?></td>
                                <td class="rpt-num"><?= as_num($r['late'], 0) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end">TOTAL</td>
                            <td class="rpt-num"><?= (int)$tot['days'] ?></td>
                            <td class="rpt-num"><?= as_num($tot['hours']) ?></td>
                            <td class="rpt-num"><?= as_num($tot['ot']) ?></td>
                            <td class="rpt-num"><?= as_num($tot['ut']) ?></td>
                            <td class="rpt-num"><?= as_num($tot['late'], 0) ?></td>
                        </tr>
                    </tfoot>
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
