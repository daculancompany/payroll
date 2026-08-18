<?php
// Punch Logs — every biometric/manual scan as a PUNCH, flat and chronological,
// separate from the day rows that interpret it. Included via index.php
// (?page=punch-logs).
//
// Why this screen exists: every other attendance screen reads DTR_details, the
// DAY row. A punch is only ever visible through the day it is attached to, so a
// punch filed under the wrong day is invisible except on the wrong day. This
// view shows the punch itself — what the device recorded — next to the shift day
// payroll assigned it to, so the two can be compared. The "+N" column is the
// whole point: it says a punch counts toward a day it did not happen on, which
// is correct for a night shift and a bug for anything else.
//
// Read-only by design. Re-dating happens where it belongs — fix the shift on the
// duty roster and Recompute (Action::repairPunchDates does the move).

$f_from = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y/m/d', strtotime('first day of this month'));
$f_to   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y/m/d', strtotime('last day of this month'));
$f_emp  = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;
$f_cross = !empty($_GET['cross']);

$pl_month_first = date('Y/m/d', strtotime('first day of this month'));
$pl_month_last  = date('Y/m/d', strtotime('last day of this month'));
$pl_range_label = ($f_from === $pl_month_first && $f_to === $pl_month_last)
    ? 'This Month'
    : date('M j, Y', strtotime($f_from)) . ' – ' . date('M j, Y', strtotime($f_to));

$employees = [];
$eq = $conn->query("SELECT id, employee_no, CONCAT(lastname, ', ', firstname) AS emp
                    FROM employee WHERE status = 1 ORDER BY lastname, firstname");
while ($eq && ($e = $eq->fetch_assoc())) $employees[] = $e;

// One shared decoder for this screen and the employee portal — see
// dtr_punch_feed() in db_connect.php.
$punches = dtr_punch_feed($conn, [
    'employee_id' => $f_emp,
    'from'        => date('Y-m-d', strtotime($f_from)),
    'to'          => date('Y-m-d', strtotime($f_to)),
    'with_names'  => true,
]);

$stat = ['total' => count($punches), 'cross' => 0, 'manual' => 0, 'emps' => []];
foreach ($punches as $p) {
    if ($p['day_offset'] != 0)   $stat['cross']++;
    if ($p['source'] !== 'bio')  $stat['manual']++;
    $stat['emps'][$p['employee_id']] = true;
}
$stat['emps'] = count($stat['emps']);

if ($f_cross) $punches = array_values(array_filter($punches, function ($p) { return $p['day_offset'] != 0; }));

// Workbench links, built once per batch rather than per punch — same URL shape
// dtr.php uses so the row opens in the DTR the admin already knows.
$batchUrl = [];
$ids = array_unique(array_map(function ($p) { return $p['ddtr_id']; }, $punches));
if ($ids) {
    $bq = $conn->query("SELECT id, timekeeper_name, device_id, site_id, status FROM DTR WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")");
    while ($bq && ($b = $bq->fetch_assoc())) {
        $batchUrl[(int) $b['id']] = 'dtr-documents.php?id=' . base64_encode($b['id'])
            . '&timekeeper_name=' . base64_encode($b['timekeeper_name'])
            . '&device_id=' . base64_encode($b['device_id'])
            . '&site_id=' . base64_encode($b['site_id'])
            . '&status=' . base64_encode($b['status']);
    }
}

$batchLbl = [0 => 'Collecting', 1 => 'Pending', 2 => 'Approved', 3 => 'For review'];
?>
<style>
.pl-stat{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.pl-stat .s{flex:1 1 150px;background:#fff;border:1px solid #ece9f3;border-left:3px solid #673bb6;border-radius:10px;padding:10px 14px;}
.pl-stat .s .v{font-size:20px;font-weight:800;color:#4e3483;line-height:1.1;}
.pl-stat .s .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#999;font-weight:700;margin-top:2px;}
.pl-stat .s.warn{border-left-color:#e6a817;} .pl-stat .s.warn .v{color:#c98a00;}
.pl-table td{vertical-align:middle;}
.pl-time{font-weight:700;color:#3a285d;white-space:nowrap;}
.pl-date{font-size:11px;color:#999;}
.pl-src{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.pl-src.bio{background:#eef4ff;color:#3b5bbf;} .pl-src.manual{background:#fff6e0;color:#c98a00;}
.pl-src.kiosk{background:#eafaf0;color:#0f9d58;}
.pl-seq{font-size:10px;color:#aaa;font-weight:700;}
.pl-off{display:inline-block;font-size:10px;font-weight:800;padding:1px 6px;border-radius:8px;background:#fdecea;color:#c62828;margin-left:6px;}
.pl-off.ok{background:#efe9fb;color:#5b3ea8;}
.pl-shift{font-size:11px;color:#777;white-space:nowrap;}
.pl-shift .noc{background:#2b2b3a;color:#fff;border-radius:6px;padding:0 5px;font-size:9px;margin-left:4px;}
tr.pl-cross{background:#fffdf5;}
.pl-batch{font-size:10px;font-weight:700;padding:2px 7px;border-radius:9px;background:#f0eff3;color:#666;}
.pl-batch.b1{background:#fff6e0;color:#c98a00;} .pl-batch.b2{background:#eafaf0;color:#0f9d58;}
.pl-legend{font-size:11.5px;color:#7a7391;background:#f7f5fc;border:1px solid #e9e4f5;border-radius:10px;padding:9px 13px;margin-bottom:14px;line-height:1.55;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-fingerprint-line me-2" style="color:#673bb6;"></i>Punch Logs</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item">Attendance</li>
                <li class="breadcrumb-item active">Punch Logs</li>
            </ol>
        </div>
    </div></div>

    <div class="pl-legend">
        <i class="ri-information-line me-1"></i>
        Raw scans exactly as the device recorded them &mdash; <b>not</b> the in/out per day.
        <b>Assigned to</b> is the shift day payroll counts the punch toward; a
        <span class="pl-off ok">+1</span> means it counts toward the previous day, which is
        normal for a night shift and a red flag on a day shift.
        To move a punch, fix the shift on the Duty Roster and press Recompute &mdash; this screen is read-only.
    </div>

    <div class="card rpt-card mb-3" style="border-top:3px solid #673bb6;">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="punch-logs">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;"><i class="ri-calendar-range-line me-1"></i>Date Range</label>
                    <div id="pl-daterange" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="pl-range-label"><?= htmlspecialchars($pl_range_label) ?></span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <input type="hidden" name="from" id="from" value="<?= htmlspecialchars($f_from) ?>">
                    <input type="hidden" name="to" id="to" value="<?= htmlspecialchars($f_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Employee</label>
                    <select name="emp" class="form-control" data-placeholder="All employees">
                        <option value="0">All employees</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $f_emp === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['emp']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="cross" value="1" id="pl-cross" <?= $f_cross ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pl-cross" style="font-size:12px;">Cross-day only</label>
                    </div>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm" style="background:#673bb6;color:#fff;font-weight:700;"><i class="ri-filter-3-line me-1"></i>Filter</button>
                    <button type="button" onclick="plExportCSV('pl-table','punch-logs.csv')" class="btn btn-sm btn-outline-success"><i class="ri-file-excel-2-line me-1"></i>CSV</button>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="ri-printer-line"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="pl-stat">
        <div class="s"><div class="v"><?= number_format($stat['total']) ?></div><div class="l">Punches</div></div>
        <div class="s"><div class="v"><?= number_format($stat['emps']) ?></div><div class="l">Employees</div></div>
        <div class="s <?= $stat['cross'] ? 'warn' : '' ?>"><div class="v"><?= number_format($stat['cross']) ?></div><div class="l">Cross-day (+N)</div></div>
        <div class="s"><div class="v"><?= number_format($stat['manual']) ?></div><div class="l">Manual entries</div></div>
    </div>

    <div class="card rpt-card mb-4">
        <div class="card-body p-0">
            <div class="rpt-scroll">
                <table class="table table-sm mb-0 rpt-table pl-table" id="pl-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Punch</th><th>Source</th><th>Employee</th>
                            <th>Scan</th><th>Assigned to</th><th>Shift on that day</th><th>DTR batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$punches): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No punches in this range.</td></tr>
                        <?php else: $i = 1; foreach ($punches as $p):
                            $off  = (int) $p['day_offset'];
                            $noc  = (int) $p['sched_graveyard'] === 1;
                            $url  = $batchUrl[$p['ddtr_id']] ?? null;
                            $bst  = (int) $p['batch_status'];
                        ?>
                            <tr class="<?= $off != 0 ? 'pl-cross' : '' ?>">
                                <td><?= $i++ ?></td>
                                <td>
                                    <div class="pl-time"><?= date('g:i A', $p['ts']) ?></div>
                                    <div class="pl-date"><?= date('D, M j, Y', $p['ts']) ?></div>
                                </td>
                                <td>
                                    <span class="pl-src <?= htmlspecialchars($p['source']) ?>"><?= htmlspecialchars($p['source']) ?></span>
                                    <?php if ($p['authorized_by']): ?><div class="pl-date">by <?= htmlspecialchars($p['authorized_by']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <a href="index.php?page=employee-details&id=<?= (int)$p['employee_id'] ?>" data-emp-quickview="<?= (int)$p['employee_id'] ?>" class="rpt-emp-link"><?= htmlspecialchars($p['employee']) ?></a>
                                    <div class="pl-date"><?= htmlspecialchars($p['employee_no']) ?></div>
                                </td>
                                <td><span class="pl-seq"><?= $p['seq'] ?> of <?= $p['of'] ?></span></td>
                                <td>
                                    <span class="pl-time" style="font-size:12px;"><?= date('M j, Y', strtotime($p['assigned_date'])) ?></span>
                                    <?php if ($off != 0): ?>
                                        <span class="pl-off <?= $noc ? 'ok' : '' ?>" title="<?= $noc
                                            ? 'Night shift — counting toward the day the shift started is correct.'
                                            : 'This punch counts toward a day it did not happen on, and the shift is not a night shift. Check the roster for that day.' ?>"><?= sprintf('%+d', $off) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="pl-shift">
                                    <?php if ($p['sched_start']): ?>
                                        <?= date('g:i A', strtotime($p['sched_start'])) ?>&ndash;<?= date('g:i A', strtotime($p['sched_end'])) ?>
                                        <?php if ($noc): ?><span class="noc">NOC</span><?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">not stamped</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($url): ?>
                                        <a href="<?= $url ?>" target="_blank" class="pl-batch b<?= $bst ?>">#<?= $p['ddtr_id'] ?> · <?= $batchLbl[$bst] ?? $bst ?> <i class="ri-external-link-line"></i></a>
                                    <?php else: ?>
                                        <span class="pl-batch">#<?= $p['ddtr_id'] ?></span>
                                    <?php endif; ?>
                                </td>
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
    // Date range picker — same setup as attendance-summary.php's #as-daterange.
    // jQuery/daterangepicker load in index.php's footer, AFTER this inline block
    // runs, so wait for DOMContentLoaded.
    document.addEventListener('DOMContentLoaded', function () {
        var $ = window.jQuery;
        if (!$) return;
        var $picker = $('#pl-daterange');
        if (!$picker.length || !$.fn.daterangepicker) return;
        $picker.daterangepicker({
            autoUpdateInput: false,
            opens: 'right',
            showDropdowns: true,
            startDate: moment('<?= date('Y-m-d', strtotime($f_from)) ?>'),
            endDate: moment('<?= date('Y-m-d', strtotime($f_to)) ?>'),
            locale: { format: 'YYYY-MM-DD', cancelLabel: 'Clear', applyLabel: 'Apply' },
            ranges: {
                'Today':        [moment(), moment()],
                'Yesterday':    [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month':   [moment().startOf('month'), moment().endOf('month')],
                'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
        $picker.on('apply.daterangepicker', function (ev, picker) {
            $('#pl-range-label').text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
            $('#from').val(picker.startDate.format('YYYY-MM-DD'));
            $('#to').val(picker.endDate.format('YYYY-MM-DD'));
        });
    });

    function plExportCSV(tableId, filename) {
        var t = document.getElementById(tableId);
        if (!t) return;
        var csv = [];
        t.querySelectorAll('tr').forEach(function (tr) {
            var row = [];
            tr.querySelectorAll('th,td').forEach(function (c) {
                row.push('"' + c.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"');
            });
            if (row.length) csv.push(row.join(','));
        });
        var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    }
</script>
