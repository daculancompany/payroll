<?php
// PIN Attendance — kiosk punches made with an employee PIN instead of a face.
// Included via index.php / app.php (?page=pin-attendance). Admin only
// (ADMIN_ONLY_PAGES): these are DTR punches.
//
// Why this screen exists: when face recognition fails, the UareUApp kiosk lets
// the employee type their 6-digit PIN (set on Employee Details). Nothing
// biometric proves who typed it, so the kiosk takes a photo and the punch is
// marked PENDING here. It is already on the DTR — approving only records the
// review; rejecting takes it back out of the day and recomputes it
// (includes/kiosk_admin.php).

$pa_status = isset($_GET['status']) ? (string) $_GET['status'] : 'pending';
if (!in_array($pa_status, ['pending', 'approved', 'rejected', 'all'], true)) $pa_status = 'pending';
$pa_from = isset($_GET['from']) && $_GET['from'] ? (string) $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
$pa_to   = isset($_GET['to'])   && $_GET['to']   ? (string) $_GET['to']   : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pa_from)) $pa_from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pa_to))   $pa_to   = date('Y-m-d');
$pa_emp  = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;

// Pending ignores the date range: everything still waiting must be visible,
// however old — a forgotten pending punch is still sitting on the DTR.
$pa_use_range   = $pa_status !== 'pending';
$pa_range_label = date('M j, Y', strtotime($pa_from)) . ' – ' . date('M j, Y', strtotime($pa_to));

$pa_has_table = $conn->query("SHOW TABLES LIKE 'kiosk_punches'");
$pa_has_table = $pa_has_table && $pa_has_table->num_rows > 0;

$pa_employees = [];
$eq = $conn->query("SELECT id, CONCAT(lastname, ', ', firstname) AS emp FROM employee WHERE status = 1 ORDER BY lastname, firstname");
while ($eq && ($e = $eq->fetch_assoc())) $pa_employees[] = $e;

$pa_rows  = [];
$pa_count = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
if ($pa_has_table) {
    $cq = $conn->query("SELECT approval, COUNT(*) AS n FROM kiosk_punches
                        WHERE entry_type = 'pin' AND punch IN ('TIME_IN','TIME_OUT') AND approval IS NOT NULL
                        GROUP BY approval");
    while ($cq && ($c = $cq->fetch_assoc())) {
        if (isset($pa_count[$c['approval']])) $pa_count[$c['approval']] = (int) $c['n'];
    }

    $where = ["kp.entry_type = 'pin'", "kp.punch IN ('TIME_IN','TIME_OUT')"];
    $types = '';
    $args  = [];
    if ($pa_status !== 'all') { $where[] = 'kp.approval = ?'; $types .= 's'; $args[] = $pa_status; }
    if ($pa_use_range) {
        $where[] = 'kp.scan_time BETWEEN ? AND ?';
        $types  .= 'ss';
        $args[]  = $pa_from . ' 00:00:00';
        $args[]  = $pa_to . ' 23:59:59';
    }
    if ($pa_emp) { $where[] = 'kp.employee_id = ?'; $types .= 'i'; $args[] = $pa_emp; }

    $sql = "SELECT kp.*, CONCAT(e.lastname, ', ', e.firstname) AS emp, e.employee_no,
                   s.site_name, u.username AS reviewer, b.status AS batch_status
            FROM kiosk_punches kp
            INNER JOIN employee e ON e.id = kp.employee_id
            LEFT JOIN sites s        ON s.id = kp.site_id
            LEFT JOIN users u        ON u.id = kp.reviewed_by
            LEFT JOIN DTR_details d  ON d.id = kp.dtr_detail_id
            LEFT JOIN DTR b          ON b.id = d.ddtr_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY kp.scan_time DESC, kp.id DESC
            LIMIT 1000";
    $st = $conn->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $rs = $st->get_result();
    while ($rs && ($row = $rs->fetch_assoc())) $pa_rows[] = $row;
}

$pa_can_review = in_array((int) ($login_role ?? 0), PAYROLL_DTR_ROLES, true);

/** Tab link that keeps the other filters. */
function pa_tab_url($status, $from, $to, $emp)
{
    $q = ['status' => $status];
    if ($status !== 'pending') { $q['from'] = $from; $q['to'] = $to; }
    if ($emp) $q['emp'] = $emp;
    return 'pin-attendance?' . http_build_query($q);
}
?>
<style>
.pa-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;}
.pa-tab{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;border:1px solid var(--v2-border,#e4e1ec);background:var(--v2-surface,#fff);color:var(--v2-text-2,#555);font-weight:600;font-size:12.5px;text-decoration:none;}
.pa-tab:hover{background:var(--v2-hover,#f6f4fb);color:var(--v2-text,#222);}
.pa-tab.on{border-color:var(--app-primary,#673bb6);color:var(--app-primary,#673bb6);background:var(--app-primary-soft,rgba(103,59,182,.08));}
.pa-tab .n{font-size:11px;font-weight:800;padding:1px 7px;border-radius:9px;background:var(--v2-surface-3,#f0eef5);color:var(--v2-text-2,#555);}
.pa-tab.pending .n{background:rgba(230,168,23,.18);color:#b07800;}
.pa-legend{font-size:12px;color:var(--v2-text-2,#6f6a80);background:var(--v2-surface-2,#f7f5fc);border:1px solid var(--v2-border,#e9e4f5);border-radius:10px;padding:9px 13px;margin-bottom:14px;line-height:1.55;}
.pa-table td{vertical-align:middle;}
.pa-time{font-weight:700;white-space:nowrap;}
.pa-sub{font-size:11px;color:var(--v2-text-3,#999);}
.pa-chip{display:inline-block;font-size:10px;font-weight:800;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
.pa-chip.in{background:rgba(46,160,67,.14);color:#23853a;}
.pa-chip.out{background:rgba(59,91,191,.14);color:#3b5bbf;}
.pa-chip.pending{background:rgba(230,168,23,.18);color:#b07800;}
.pa-chip.approved{background:rgba(46,160,67,.14);color:#23853a;}
.pa-chip.rejected{background:rgba(198,40,40,.13);color:#c62828;}
.pa-chip.locked{background:var(--v2-surface-3,#eee);color:var(--v2-text-2,#666);}
.pa-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--v2-border,#e4e1ec);cursor:zoom-in;display:block;}
.pa-nophoto{width:48px;height:48px;border-radius:8px;border:1px dashed var(--v2-border-strong,#d5d0e2);display:flex;align-items:center;justify-content:center;color:var(--v2-text-3,#aaa);font-size:18px;}
.pa-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;}
.pa-bulk{display:none;align-items:center;gap:10px;}
.pa-bulk.show{display:flex;}
@media (max-width:767.98px){ .pa-hide-sm{display:none;} }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3"><div class="col-12">
        <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
            <h4 class="mb-sm-0"><i class="ri-lock-password-line me-2" style="color:var(--app-primary,#673bb6);"></i>PIN Attendance</h4>
            <ol class="breadcrumb m-0">
                <li class="breadcrumb-item">Attendance</li>
                <li class="breadcrumb-item active">PIN Attendance</li>
            </ol>
        </div>
    </div></div>

    <div class="pa-legend">
        <i class="ri-information-line me-1"></i>
        When the kiosk cannot recognise a face, the employee can punch with their <b>6-digit PIN</b> (set on Employee Details).
        The kiosk takes a photo and the punch goes on the DTR straight away, marked <span class="pa-chip pending">pending</span>.
        <b>Approve</b> if the photo is the right person. <b>Reject</b> removes the punch from the DTR and recomputes that day.
        A day in a final-approved DTR batch must be reopened in DTR Review before its punch can be rejected.
    </div>

    <?php if (!$pa_has_table): ?>
    <div class="alert alert-warning"><i class="ri-error-warning-line me-1"></i>The kiosk tables are missing. Run <code>migrations/2026_11_kiosk_api.sql</code> on this database.</div>
    <?php endif; ?>

    <div class="pa-tabs">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
            <a class="pa-tab <?= $k ?> <?= $pa_status === $k ? 'on' : '' ?>" href="<?= htmlspecialchars(pa_tab_url($k, $pa_from, $pa_to, $pa_emp)) ?>">
                <?= $label ?>
                <?php if ($k !== 'all'): ?><span class="n"><?= (int) $pa_count[$k] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card rpt-card mb-3">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="pin-attendance">
                <input type="hidden" name="status" value="<?= htmlspecialchars($pa_status) ?>">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;">
                        <i class="ri-calendar-2-line me-1"></i>Date Range
                        <?php if (!$pa_use_range): ?><span class="pa-sub" style="text-transform:none;">(not used on Pending)</span><?php endif; ?>
                    </label>
                    <div id="pa-daterange" class="att-range-picker">
                        <i class="ri-calendar-2-line"></i>
                        <span id="pa-range-label"><?= htmlspecialchars($pa_range_label) ?></span>
                        <i class="ri-arrow-down-s-line" style="margin-left:auto;color:#aaa;"></i>
                    </div>
                    <input type="hidden" name="from" id="pa-from" value="<?= htmlspecialchars($pa_from) ?>">
                    <input type="hidden" name="to" id="pa-to" value="<?= htmlspecialchars($pa_to) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;">Employee</label>
                    <select name="emp" class="form-control" data-placeholder="All employees">
                        <option value="0">All employees</option>
                        <?php foreach ($pa_employees as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= $pa_emp === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['emp']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill"><i class="ri-filter-3-line me-1"></i>Filter</button>
                    <a href="pin-attendance" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card rpt-card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="fw-bold"><i class="ri-list-check-2 me-1"></i><?= count($pa_rows) ?> PIN punch<?= count($pa_rows) === 1 ? '' : 'es' ?></span>
            <?php if ($pa_can_review): ?>
            <div class="pa-bulk" id="pa-bulk">
                <span class="pa-sub" id="pa-bulk-n">0 selected</span>
                <button class="btn btn-success btn-sm" onclick="paApproveSelected()"><i class="ri-check-double-line me-1"></i>Approve selected</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 pa-table">
                    <thead class="table-light"><tr>
                        <?php if ($pa_can_review): ?><th style="width:34px;"><input type="checkbox" class="form-check-input" id="pa-all" aria-label="Select all pending"></th><?php endif; ?>
                        <th style="width:64px;">Photo</th>
                        <th>Employee</th>
                        <th style="width:130px;">Punch</th>
                        <th style="width:120px;" class="pa-hide-sm">DTR day</th>
                        <th style="width:150px;" class="pa-hide-sm">Kiosk</th>
                        <th style="width:170px;">Status</th>
                        <th style="width:170px;"></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$pa_rows): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">
                            <?= $pa_status === 'pending' ? 'No PIN punches are waiting for review.' : 'No PIN punches in this range.' ?>
                        </td></tr>
                    <?php else: foreach ($pa_rows as $r):
                        $is_pending = $r['approval'] === 'pending';
                        $locked     = (int) ($r['batch_status'] ?? 0) === 2;
                        $photo      = $r['selfie'] ? $r['selfie'] : null; ?>
                        <tr id="pa-row-<?= (int) $r['id'] ?>">
                            <?php if ($pa_can_review): ?>
                            <td><?php if ($is_pending): ?><input type="checkbox" class="form-check-input pa-pick" value="<?= (int) $r['id'] ?>" aria-label="Select"><?php endif; ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($photo): ?>
                                    <img class="pa-thumb" src="<?= htmlspecialchars($photo) ?>" alt="Photo at punch" loading="lazy" onclick="kioskPhotoView(this.src, <?= htmlspecialchars(json_encode($r['emp'] . ' · ' . date('M j, g:i A', strtotime($r['scan_time']))), ENT_QUOTES) ?>)">
                                <?php else: ?>
                                    <div class="pa-nophoto" title="No photo was uploaded"><i class="ri-camera-off-line"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <b><?= htmlspecialchars($r['emp']) ?></b>
                                <div class="pa-sub">#<?= (int) $r['employee_id'] ?> <?= htmlspecialchars($r['employee_no'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="pa-chip <?= $r['punch'] === 'TIME_IN' ? 'in' : 'out' ?>"><?= $r['punch'] === 'TIME_IN' ? 'Time in' : 'Time out' ?></span>
                                <div class="pa-time mt-1"><?= date('g:i:s A', strtotime($r['scan_time'])) ?></div>
                                <div class="pa-sub"><?= date('D, M j, Y', strtotime($r['scan_time'])) ?></div>
                            </td>
                            <td class="pa-hide-sm">
                                <?= $r['dtr_date'] ? date('M j, Y', strtotime($r['dtr_date'])) : '<span class="text-muted">—</span>' ?>
                                <?php if ($locked): ?><div class="mt-1"><span class="pa-chip locked" title="Final-approved DTR batch"><i class="ri-lock-line"></i> Locked</span></div><?php endif; ?>
                            </td>
                            <td class="pa-hide-sm">
                                <?= htmlspecialchars($r['site_name'] ?? '—') ?>
                                <div class="pa-sub"><?= htmlspecialchars($r['device_id'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="pa-chip <?= htmlspecialchars($r['approval']) ?>"><?= htmlspecialchars($r['approval']) ?></span>
                                <?php if (!$is_pending && $r['reviewed_at']): ?>
                                    <div class="pa-sub mt-1"><?= htmlspecialchars($r['reviewer'] ?? '') ?> · <?= date('M j, g:i A', strtotime($r['reviewed_at'])) ?></div>
                                    <?php if ($r['review_note']): ?><div class="pa-sub"><?= htmlspecialchars($r['review_note']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pa_can_review && $is_pending): ?>
                                <div class="pa-actions">
                                    <button class="btn btn-outline-success btn-sm py-0" onclick="paApprove(<?= (int) $r['id'] ?>)"><i class="ri-check-line"></i> Approve</button>
                                    <button class="btn btn-outline-danger btn-sm py-0" onclick="paReject(<?= (int) $r['id'] ?>, <?= $locked ? 'true' : 'false' ?>)"><i class="ri-close-line"></i> Reject</button>
                                </div>
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

<?php
// Click-to-enlarge, shared with Punch Logs and the face tiles.
require_once __DIR__ . '/component/photo_viewer.php';
echo render_photo_viewer();
?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var $ = window.jQuery;
        if ($ && $.fn.daterangepicker && $('#pa-daterange').length) {
            var $picker = $('#pa-daterange');
            $picker.daterangepicker({
                autoUpdateInput: false,
                opens: 'right',
                showDropdowns: true,
                startDate: moment('<?= htmlspecialchars($pa_from) ?>'),
                endDate: moment('<?= htmlspecialchars($pa_to) ?>'),
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
                $('#pa-range-label').text(picker.startDate.format('MMM D, YYYY') + ' – ' + picker.endDate.format('MMM D, YYYY'));
                $('#pa-from').val(picker.startDate.format('YYYY-MM-DD'));
                $('#pa-to').val(picker.endDate.format('YYYY-MM-DD'));
            });
        }

        var all = document.getElementById('pa-all');
        if (all) all.addEventListener('change', function () {
            document.querySelectorAll('.pa-pick').forEach(function (c) { c.checked = all.checked; });
            paSyncBulk();
        });
        document.querySelectorAll('.pa-pick').forEach(function (c) { c.addEventListener('change', paSyncBulk); });
    });

    function paSyncBulk() {
        var n = document.querySelectorAll('.pa-pick:checked').length;
        var bar = document.getElementById('pa-bulk');
        if (!bar) return;
        bar.classList.toggle('show', n > 0);
        document.getElementById('pa-bulk-n').textContent = n + ' selected';
    }

    async function paPost(action, data) {
        var res = await fetch('ajax.php?action=' + action, { method: 'POST', body: new URLSearchParams(data) });
        return res.json().catch(function () { return { result: false, message: 'The server sent an unreadable answer.' }; });
    }

    async function paApprove(id) {
        var j = await paPost('approve_pin_punch', { id: id });
        if (!j.result) { alert(j.message || 'Could not approve.'); return; }
        location.reload();
    }

    async function paApproveSelected() {
        var ids = Array.prototype.map.call(document.querySelectorAll('.pa-pick:checked'), function (c) { return c.value; });
        if (!ids.length) return;
        if (!confirm('Approve ' + ids.length + ' PIN punch' + (ids.length === 1 ? '' : 'es') + '?')) return;
        var j = await paPost('approve_pin_punch', { ids: ids.join(',') });
        if (!j.result) { alert(j.message || 'Could not approve.'); return; }
        location.reload();
    }

    async function paReject(id, locked) {
        if (locked) {
            alert('This day is in a final-approved DTR batch. Reopen the batch in DTR Review first, then reject the punch.');
            return;
        }
        var note = prompt('Reject this PIN punch? It will be removed from the DTR and the day recomputed.\n\nReason (optional):', '');
        if (note === null) return;
        var j = await paPost('reject_pin_punch', { id: id, note: note });
        if (!j.result) { alert(j.message || 'Could not reject.'); return; }
        location.reload();
    }
</script>
