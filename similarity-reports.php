<?php
// Fingerprint Similarity Reports — what the desktop scanner saw when ONE live
// scan verified against MORE THAN ONE employee. Included via index.php
// (?page=similarity-reports).
//
// Why this screen exists: a look-alike / duplicate enrollment shows up on the
// DTR only as "why do I have a punch I never made?" on the WRONG employee. The
// scanner now posts every multi-employee match here (biometric-api.php
// report-similar) with the ranked candidate list it saw, so the admin can see
// exactly which two fingers collide and re-enroll them — instead of guessing
// from complaints. Read-mostly: the only writes are "mark reviewed" + delete.

$sr_from = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
$sr_to   = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
$sr_emp  = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;
$sr_dec  = isset($_GET['decision']) ? trim($_GET['decision']) : '';
$sr_open = !empty($_GET['open']);   // unreviewed only

$sr_range_label = date('M j, Y', strtotime($sr_from)) . ' – ' . date('M j, Y', strtotime($sr_to));

// The scanner auto-creates the table on first report; the page must not fatal
// on a fresh install that has never received one.
$sr_has_table = $conn->query("SHOW TABLES LIKE 'biometric_similarity_reports'");
$sr_has_table = $sr_has_table && $sr_has_table->num_rows > 0;

$employees = [];
$eq = $conn->query("SELECT id, employee_no, CONCAT(lastname, ', ', firstname) AS emp
                    FROM employee ORDER BY lastname, firstname");
while ($eq && ($e = $eq->fetch_assoc())) $employees[(int) $e['id']] = $e;

$reports = [];
if ($sr_has_table) {
    $where = ["r.scan_time BETWEEN ? AND ?"];
    $types = 'ss';
    $args  = [$sr_from . ' 00:00:00', $sr_to . ' 23:59:59'];
    if ($sr_dec !== '' && in_array($sr_dec, ['saved', 'ambiguous', 'debug', 'audit', 'nomatch'], true)) {
        $where[] = "r.decision = ?"; $types .= 's'; $args[] = $sr_dec;
    }
    if ($sr_open) {
        $where[] = "r.reviewed_at IS NULL";
    }
    if ($sr_emp) {
        // Matched employee OR anywhere in the candidate list.
        $where[] = "(r.matched_employee_id = ? OR r.candidates LIKE ?)";
        $types .= 'is'; $args[] = $sr_emp; $args[] = '%"employee_id":"' . $sr_emp . '"%';
    }
    $sql = "SELECT r.*, CONCAT(e.lastname, ', ', e.firstname) AS matched_name, e.employee_no AS matched_no,
                   u.username AS reviewer
            FROM biometric_similarity_reports r
            LEFT JOIN employee e ON e.id = r.matched_employee_id
            LEFT JOIN users u ON u.id = r.reviewed_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.scan_time DESC, r.id DESC LIMIT 1000";
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    $rs = $st->get_result();
    while ($rs && ($row = $rs->fetch_assoc())) {
        $row['list'] = json_decode($row['candidates'], true) ?: [];
        $reports[] = $row;
    }
}

// Stats + "which pairs keep colliding" — the actionable summary. A pair is the
// matched/best employee together with every OTHER employee that verified in the
// same scan; count how many reports each unordered pair appears in.
$stat = ['total' => count($reports), 'ambiguous' => 0, 'open' => 0];
$pairs = [];
foreach ($reports as $r) {
    if ($r['decision'] === 'ambiguous') $stat['ambiguous']++;
    if (!$r['reviewed_at']) $stat['open']++;
    $ver = array_values(array_filter($r['list'], function ($c) { return !empty($c['verified']); }));
    for ($i = 0; $i < count($ver); $i++) {
        for ($j = $i + 1; $j < count($ver); $j++) {
            $a = $ver[$i]; $b = $ver[$j];
            if ((string) $a['employee_id'] === (string) $b['employee_id']) continue;
            $k = min((int) $a['employee_id'], (int) $b['employee_id']) . '-' . max((int) $a['employee_id'], (int) $b['employee_id']);
            if (!isset($pairs[$k])) {
                $pairs[$k] = ['a' => $a, 'b' => $b, 'n' => 0, 'last' => $r['scan_time'], 'fingers' => []];
            }
            $pairs[$k]['n']++;
            $pairs[$k]['fingers'][$a['employee_id'] . ':' . ($a['finger'] ?? '')] = true;
            $pairs[$k]['fingers'][$b['employee_id'] . ':' . ($b['finger'] ?? '')] = true;
        }
    }
}
uasort($pairs, function ($x, $y) { return $y['n'] <=> $x['n']; });
$stat['pairs'] = count($pairs);

$sr_can_write = (int) ($login_role ?? 0) !== 6;

function sr_finger($f) {
    $f = str_replace('_', ' ', strtolower((string) $f));
    return ucwords($f);
}
function sr_name($c, $employees) {
    $id = (int) ($c['employee_id'] ?? 0);
    if (isset($employees[$id])) return $employees[$id]['emp'];
    return $c['name'] ?? ('Employee ' . $id);
}
?>
<style>
.sr-stat{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.sr-stat .s{flex:1 1 150px;background:#fff;border:1px solid #ece9f3;border-left:3px solid #673bb6;border-radius:10px;padding:10px 14px;}
.sr-stat .s .v{font-size:20px;font-weight:800;color:#4e3483;line-height:1.1;}
.sr-stat .s .l{font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#999;font-weight:700;margin-top:2px;}
.sr-stat .s.warn{border-left-color:#e6a817;} .sr-stat .s.warn .v{color:#c98a00;}
.sr-stat .s.bad{border-left-color:#c62828;} .sr-stat .s.bad .v{color:#c62828;}
.sr-table td{vertical-align:middle;}
.sr-time{font-weight:700;color:#3a285d;white-space:nowrap;}
.sr-date{font-size:11px;color:#999;}
.sr-dec{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.sr-dec.saved{background:#fff6e0;color:#c98a00;} .sr-dec.ambiguous{background:#fdecea;color:#c62828;} .sr-dec.debug{background:#eef4ff;color:#3b5bbf;} .sr-dec.audit{background:#efe9fb;color:#5b3ea8;} .sr-dec.nomatch{background:#f0f0f0;color:#555;}
.sr-cand{display:flex;flex-direction:column;gap:3px;}
.sr-cand .c{display:flex;align-items:center;gap:8px;font-size:12px;}
.sr-cand .c .nm{font-weight:600;color:#3a285d;}
.sr-cand .c .fg{font-size:10.5px;color:#888;}
.sr-cand .c.other .nm{color:#c62828;}
.sr-bar{position:relative;width:110px;height:8px;background:#eee;border-radius:5px;overflow:hidden;flex:0 0 auto;}
.sr-bar i{position:absolute;left:0;top:0;bottom:0;border-radius:5px;background:#c85a5a;}
.sr-bar.v i{background:#2ea043;}
.sr-pct{font-size:10.5px;font-weight:700;color:#666;width:34px;text-align:right;}
.sr-rev{font-size:11px;color:#0f9d58;font-weight:700;}
.sr-rev small{display:block;color:#999;font-weight:400;}
.sr-legend{font-size:11.5px;color:#7a7391;background:#f7f5fc;border:1px solid #e9e4f5;border-radius:10px;padding:9px 13px;margin-bottom:14px;line-height:1.55;}
tr.sr-open{background:#fffdf5;}
.sr-pair .n{font-size:16px;font-weight:800;color:#c62828;}
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-fingerprint-2-line me-2" style="color:#673bb6;"></i>Fingerprint Similarity Reports</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item">Attendance</li>
                <li class="breadcrumb-item active">Similarity Reports</li>
            </ol>
        </div>
    </div></div>

    <div class="sr-legend">
        <i class="ri-information-line me-1"></i>
        Each row is one live scan on the fingerprint scanner that <b>verified against more than one employee</b>.
        <span class="sr-dec saved">saved</span> the punch was recorded for the strongest match but another employee also verified &mdash;
        <span class="sr-dec ambiguous">ambiguous</span> the two were too close, the scan was <b>rejected</b> and the person asked to rescan &mdash;
        <span class="sr-dec audit">audit</span> found during an audit sweep in the scanner's Check Fingerprint window (no punch involved) &mdash;
        <span class="sr-dec nomatch">nomatch</span> someone failed to match <b>3&times; in a row</b>; the closest templates hint who it was &mdash; re-enroll that finger.
        Either way the fingers listed in red are look-alikes: open the scanner, <b>Menu &rarr; Check Fingerprint / Find Similar</b>, and re-enroll the weaker finger.
        Mark a row reviewed once handled.
    </div>

    <?php if (!$sr_has_table): ?>
    <div class="alert alert-info"><i class="ri-information-line me-1"></i>No reports yet &mdash; the scanner creates this log the first time a scan matches two employees (needs the scanner build with the Similar Matches tab).</div>
    <?php endif; ?>

    <div class="card rpt-card mb-3" style="border-top:3px solid #673bb6;">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="similarity-reports">
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;"><i class="ri-calendar-range-line me-1"></i>Date Range</label>
                    <div id="sr-daterange" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="sr-range-label"><?= htmlspecialchars($sr_range_label) ?></span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <input type="hidden" name="from" id="from" value="<?= htmlspecialchars($sr_from) ?>">
                    <input type="hidden" name="to" id="to" value="<?= htmlspecialchars($sr_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Employee (matched or candidate)</label>
                    <select name="emp" class="form-control" data-placeholder="All employees">
                        <option value="0">All employees</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $sr_emp === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['emp']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#673bb6;">Decision</label>
                    <select name="decision" class="form-control">
                        <option value="">All</option>
                        <option value="saved" <?= $sr_dec === 'saved' ? 'selected' : '' ?>>Saved (with warning)</option>
                        <option value="ambiguous" <?= $sr_dec === 'ambiguous' ? 'selected' : '' ?>>Ambiguous (rejected)</option>
                        <option value="audit" <?= $sr_dec === 'audit' ? 'selected' : '' ?>>Audit sweep (Check Fingerprint)</option>
                        <option value="nomatch" <?= $sr_dec === 'nomatch' ? 'selected' : '' ?>>No match ×3 (weak / unenrolled finger)</option>
                        <option value="debug" <?= $sr_dec === 'debug' ? 'selected' : '' ?>>Debug</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="open" value="1" id="sr-open" <?= $sr_open ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sr-open" style="font-size:12px;">Unreviewed only</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill"><i class="ri-filter-3-line me-1"></i>Filter</button>
                    <a href="similarity-reports" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="sr-stat">
        <div class="s"><div class="v"><?= $stat['total'] ?></div><div class="l">Reports</div></div>
        <div class="s bad"><div class="v"><?= $stat['ambiguous'] ?></div><div class="l">Rejected (ambiguous)</div></div>
        <div class="s warn"><div class="v"><?= $stat['open'] ?></div><div class="l">Unreviewed</div></div>
        <div class="s"><div class="v"><?= $stat['pairs'] ?></div><div class="l">Look-alike pairs</div></div>
    </div>

    <?php if ($pairs): ?>
    <div class="card rpt-card mb-3">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="fw-bold" style="color:#4e3483;"><i class="ri-user-shared-line me-1"></i>Look-alike pairs in this range (most frequent first)</span>
            <span class="text-muted" style="font-size:11px;">Re-enroll the listed finger(s) of ONE person in each pair, then check again with Find Similar.</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 sr-table">
                    <thead class="table-light"><tr>
                        <th style="width:70px;">Times</th><th>Employee A</th><th>Employee B</th><th>Fingers involved</th><th>Last seen</th><th style="width:120px;"></th>
                    </tr></thead>
                    <tbody>
                    <?php $shown = 0; foreach ($pairs as $k => $p): if (++$shown > 15) break; ?>
                        <tr class="sr-pair">
                            <td><span class="n"><?= (int)$p['n'] ?>&times;</span></td>
                            <td><b><?= htmlspecialchars(sr_name($p['a'], $employees)) ?></b> <span class="text-muted" style="font-size:11px;">#<?= (int)$p['a']['employee_id'] ?></span></td>
                            <td><b><?= htmlspecialchars(sr_name($p['b'], $employees)) ?></b> <span class="text-muted" style="font-size:11px;">#<?= (int)$p['b']['employee_id'] ?></span></td>
                            <td style="font-size:11.5px;color:#666;">
                                <?php foreach (array_keys($p['fingers']) as $fk): list($eid, $fg) = explode(':', $fk, 2); ?>
                                    <span class="badge bg-light text-dark border me-1">#<?= (int)$eid ?> <?= htmlspecialchars(sr_finger($fg)) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="sr-date"><?= date('M j, Y g:i A', strtotime($p['last'])) ?></td>
                            <td><a class="btn btn-outline-secondary btn-sm py-0" href="similarity-reports?emp=<?= (int)$p['a']['employee_id'] ?>&from=<?= $sr_from ?>&to=<?= $sr_to ?>">Show scans</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card rpt-card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="fw-bold" style="color:#4e3483;"><i class="ri-list-check-2 me-1"></i>Scans</span>
            <button class="btn btn-outline-secondary btn-sm py-0" onclick="srExportCSV('sr-table','similarity-reports.csv')"><i class="ri-download-2-line me-1"></i>CSV</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 sr-table" id="sr-table">
                    <thead class="table-light"><tr>
                        <th style="width:130px;">Scan time</th>
                        <th style="width:95px;">Decision</th>
                        <th>Punch saved for</th>
                        <th>Candidates the scanner saw (best first)</th>
                        <th style="width:110px;">Device</th>
                        <th style="width:130px;">Reviewed</th>
                        <th style="width:150px;"></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$reports): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No similarity reports in this range.</td></tr>
                    <?php else: foreach ($reports as $r): ?>
                        <tr id="sr-row-<?= (int)$r['id'] ?>" class="<?= $r['reviewed_at'] ? '' : 'sr-open' ?>">
                            <td>
                                <div class="sr-time"><?= date('g:i:s A', strtotime($r['scan_time'])) ?></div>
                                <div class="sr-date"><?= date('D, M j, Y', strtotime($r['scan_time'])) ?></div>
                            </td>
                            <td><span class="sr-dec <?= htmlspecialchars($r['decision']) ?>"><?= htmlspecialchars($r['decision']) ?></span>
                                <div class="sr-date"><?= $r['decision'] === 'nomatch' ? 'closest only' : (int)$r['candidate_count'] . ' verified' ?></div></td>
                            <td>
                                <?php if ($r['matched_employee_id']): ?>
                                    <b><?= htmlspecialchars($r['matched_name'] ?: ('Employee ' . $r['matched_employee_id'])) ?></b>
                                    <div class="sr-date">#<?= (int)$r['matched_employee_id'] ?> <?= htmlspecialchars($r['matched_no'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="text-danger fw-bold"><?= $r['decision'] === 'audit' ? 'Audit scan (no punch)' : ($r['decision'] === 'nomatch' ? 'No match &mdash; see closest' : 'Nobody &mdash; rejected') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="sr-cand">
                                <?php $k = 0; foreach ($r['list'] as $c): if (++$k > 6) { echo '<div class="sr-date">&hellip; ' . (count($r['list']) - 6) . ' more (similar only)</div>'; break; }
                                    $isMatched = $r['matched_employee_id'] && (string)$c['employee_id'] === (string)$r['matched_employee_id'];
                                    $ver = !empty($c['verified']); $pct = (int)($c['percent'] ?? 0); ?>
                                    <div class="c <?= $ver && !$isMatched ? 'other' : '' ?>">
                                        <span class="sr-bar <?= $ver ? 'v' : '' ?>"><i style="width:<?= max(2, min(100, $pct)) ?>%"></i></span>
                                        <span class="sr-pct"><?= $pct ?>%</span>
                                        <span class="nm"><?= htmlspecialchars(sr_name($c, $employees)) ?></span>
                                        <span class="fg">#<?= (int)$c['employee_id'] ?> &middot; <?= htmlspecialchars(sr_finger($c['finger'] ?? '')) ?>
                                            <?= $ver ? ($isMatched ? '&middot; <b style="color:#2ea043">match</b>' : '&middot; <b style="color:#c62828">also verified</b>') : '&middot; similar only' ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </td>
                            <td style="font-size:11.5px;color:#666;"><?= htmlspecialchars($r['device'] ?? '') ?></td>
                            <td class="sr-review-cell">
                                <?php if ($r['reviewed_at']): ?>
                                    <span class="sr-rev"><i class="ri-check-double-line"></i> <?= htmlspecialchars($r['reviewer'] ?? '') ?>
                                        <small><?= date('M j, g:i A', strtotime($r['reviewed_at'])) ?></small>
                                        <?php if ($r['review_note']): ?><small><?= htmlspecialchars($r['review_note']) ?></small><?php endif; ?></span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:11px;">Open</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($sr_can_write): ?>
                                    <?php if (!$r['reviewed_at']): ?>
                                    <button class="btn btn-outline-success btn-sm py-0" onclick="srReview(<?= (int)$r['id'] ?>)"><i class="ri-check-line"></i> Reviewed</button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger btn-sm py-0" onclick="srDelete(<?= (int)$r['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
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
    document.addEventListener('DOMContentLoaded', function () {
        var $ = window.jQuery;
        if (!$) return;
        var $picker = $('#sr-daterange');
        if (!$picker.length || !$.fn.daterangepicker) return;
        $picker.daterangepicker({
            autoUpdateInput: false,
            opens: 'right',
            showDropdowns: true,
            startDate: moment('<?= htmlspecialchars($sr_from) ?>'),
            endDate: moment('<?= htmlspecialchars($sr_to) ?>'),
            locale: { format: 'YYYY-MM-DD', cancelLabel: 'Clear', applyLabel: 'Apply' },
            ranges: {
                'Today':        [moment(), moment()],
                'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month':   [moment().startOf('month'), moment().endOf('month')],
                'Last Month':   [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
        $picker.on('apply.daterangepicker', function (ev, picker) {
            $('#sr-range-label').text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
            $('#from').val(picker.startDate.format('YYYY-MM-DD'));
            $('#to').val(picker.endDate.format('YYYY-MM-DD'));
        });
    });

    async function srReview(id) {
        var note = prompt('Reviewed — what was done? (optional, e.g. "re-enrolled Namo right pinky")', '');
        if (note === null) return;
        var res = await fetch('ajax.php?action=review_similarity_report', { method: 'POST', body: new URLSearchParams({ id: id, note: note }) });
        var j = await res.json().catch(function () { return { result: false, message: 'Bad response' }; });
        if (!j.result) { alert(j.message || 'Failed'); return; }
        location.reload();
    }

    async function srDelete(id) {
        if (!confirm('Delete this similarity report? (does not touch attendance)')) return;
        var res = await fetch('ajax.php?action=delete_similarity_report', { method: 'POST', body: new URLSearchParams({ id: id }) });
        var j = await res.json().catch(function () { return { result: false, message: 'Bad response' }; });
        if (!j.result) { alert(j.message || 'Failed'); return; }
        var tr = document.getElementById('sr-row-' + id);
        if (tr) tr.remove();
    }

    function srExportCSV(tableId, filename) {
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
