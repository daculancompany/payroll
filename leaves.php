<?php
// Blocked (holiday) dates so the leave picker can disable them.
$blocked_dates = [];
$bdq = $conn->query("SELECT start_date, end_date FROM calendar_events WHERE blocks_leave = 1 AND COALESCE(end_date,start_date) >= CURDATE()");
if ($bdq) while ($b = $bdq->fetch_assoc()) {
    $d = strtotime($b['start_date']); $e = strtotime($b['end_date'] ?: $b['start_date']);
    while ($d <= $e) { $blocked_dates[] = date('Y-m-d', $d); $d = strtotime('+1 day', $d); }
}

// Current user's role drives which approval actions are available.
$my_role = (int) ($_SESSION['login_role'] ?? 0);
$can_hr    = in_array($my_role, [1, 9], true);   // HR or Admin
$can_admin = in_array($my_role, [1, 8], true);   // Admin or Department Head

// Summary counts for the cards
$counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$cq = $conn->query("SELECT status, COUNT(*) AS c FROM leave_requests GROUP BY status");
if ($cq) while ($r = $cq->fetch_assoc()) {
    $counts['total'] += (int)$r['c'];
    if ($r['status'] == 0) $counts['pending']  = (int)$r['c'];
    if ($r['status'] == 1) $counts['approved'] = (int)$r['c'];
    if ($r['status'] == 2) $counts['rejected'] = (int)$r['c'];
}

// Render an approval-stage badge with approver + reason tooltip.
function stageBadge($status, $by_name, $remarks, $at)
{
    if ($status == 1) {
        $t = 'Approved' . ($by_name ? ' by ' . htmlspecialchars($by_name) : '') . ($at ? ' • ' . date('M d, Y', strtotime($at)) : '');
        return '<span class="badge bg-success-subtle text-success border border-success-subtle" title="' . $t . '"><i class="ri-check-line me-1"></i>Approved</span>'
             . ($by_name ? '<div class="text-muted" style="font-size:10px;">' . htmlspecialchars($by_name) . '</div>' : '');
    }
    if ($status == 2) {
        $t = 'Rejected' . ($by_name ? ' by ' . htmlspecialchars($by_name) : '') . ($remarks ? ' — ' . htmlspecialchars($remarks) : '');
        return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="' . $t . '"><i class="ri-close-line me-1"></i>Rejected</span>'
             . ($remarks ? '<div class="text-danger" style="font-size:10px;" title="' . htmlspecialchars($remarks) . '"><i class="ri-information-line"></i> ' . htmlspecialchars(mb_strimwidth($remarks, 0, 28, '…')) . '</div>' : '');
    }
    return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-time-line me-1"></i>Pending</span>';
}
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Leave Requests</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Leave Management</a></li>
                                <li class="breadcrumb-item active">Leave Requests</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Summary cards -->
                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-primary-subtle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                                <i class="ri-file-list-3-line fs-22 text-primary"></i>
                            </div>
                            <div><p class="text-muted mb-1">Total Requests</p><h4 class="mb-0"><?= $counts['total'] ?></h4></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-warning-subtle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                                <i class="ri-time-line fs-22 text-warning"></i>
                            </div>
                            <div><p class="text-muted mb-1">Pending</p><h4 class="mb-0"><?= $counts['pending'] ?></h4></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-success-subtle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                                <i class="ri-checkbox-circle-line fs-22 text-success"></i>
                            </div>
                            <div><p class="text-muted mb-1">Approved</p><h4 class="mb-0"><?= $counts['approved'] ?></h4></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-danger-subtle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                                <i class="ri-close-circle-line fs-22 text-danger"></i>
                            </div>
                            <div><p class="text-muted mb-1">Rejected</p><h4 class="mb-0"><?= $counts['rejected'] ?></h4></div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h4 class="card-title mb-0 flex-grow-1">
                                <i class="ri-calendar-event-line me-2 text-success"></i>Leave Requests
                            </h4>
                            <span class="badge bg-light text-dark border me-2" title="Approval flow">
                                <i class="ri-flow-chart me-1"></i>HR &rarr; Admin/Head
                            </span>
                            <button type="button" class="btn btn-success btn-sm" id="btn-file-leave">
                                <i class="ri-add-line me-1"></i>File Leave
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="leave-table" class="table table-hover table-bordered align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th><i class="ri-calendar-line me-1"></i>Date Applied</th>
                                            <th><i class="ri-user-line me-1"></i>Employee</th>
                                            <th><i class="ri-bookmark-line me-1"></i>Type</th>
                                            <th class="text-center"><i class="ri-time-line me-1"></i>Duration</th>
                                            <th><i class="ri-chat-1-line me-1"></i>Reason</th>
                                            <th class="text-center">HR Approval</th>
                                            <th class="text-center">Final Approval</th>
                                            <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                            <th class="text-center" style="width:160px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $q = $conn->query("
                                            SELECT lr.*,
                                                CONCAT(e.lastname, ', ', e.firstname) AS employee_name,
                                                e.employee_no,
                                                lt.name AS leave_type_name,
                                                hu.name AS hr_name,
                                                au.name AS admin_name
                                            FROM leave_requests lr
                                            INNER JOIN employee e ON e.id = lr.employee_id
                                            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                                            LEFT JOIN users hu ON hu.id = lr.hr_by
                                            LEFT JOIN users au ON au.id = lr.admin_by
                                            ORDER BY lr.date_applied DESC, lr.id DESC
                                        ");
                                        if ($q) while ($row = $q->fetch_assoc()):
                                            $statusMap = [
                                                0 => ['Pending',  'bg-warning'],
                                                1 => ['Approved', 'bg-success'],
                                                2 => ['Rejected', 'bg-danger'],
                                            ];
                                            [$slabel, $sclass] = $statusMap[$row['status']] ?? ['Unknown', 'bg-secondary'];
                                            $editable = ($row['hr_status'] == 0 && $row['admin_status'] == 0);
                                        ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($row['date_applied'])) ?></td>
                                            <td>
                                                <b><?= htmlspecialchars($row['employee_name']) ?></b>
                                                <div class="text-muted" style="font-size:11px;"><i class="ri-hashtag"></i><?= htmlspecialchars($row['employee_no']) ?></div>
                                            </td>
                                            <td><span class="badge bg-info-subtle text-info border border-info-subtle"><?= htmlspecialchars($row['leave_type_name']) ?></span></td>
                                            <td class="text-center">
                                                <b><?= rtrim(rtrim(number_format($row['duration'], 1), '0'), '.') ?></b> day(s)
                                                <div class="text-muted" style="font-size:11px;">
                                                    <?= date('M d', strtotime($row['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($row['date_to'])) ?>
                                                </div>
                                            </td>
                                            <td style="max-width:200px;"><span class="text-muted"><?= nl2br(htmlspecialchars($row['reason'] ?? '')) ?></span></td>
                                            <td class="text-center"><?= stageBadge($row['hr_status'], $row['hr_name'], $row['hr_remarks'], $row['hr_at']) ?></td>
                                            <td class="text-center"><?= stageBadge($row['admin_status'], $row['admin_name'], $row['admin_remarks'], $row['admin_at']) ?></td>
                                            <td class="text-center"><span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span></td>
                                            <td class="text-center">
                                                <?php if ($can_hr && $row['hr_status'] == 0): ?>
                                                    <button class="btn btn-sm btn-success" title="HR Approve" onclick="decideLeave(<?= $row['id'] ?>,'hr',1)"><i class="ri-check-double-line"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="HR Reject" onclick="decideLeave(<?= $row['id'] ?>,'hr',2)"><i class="ri-close-line"></i></button>
                                                <?php endif; ?>
                                                <?php if ($can_admin && $row['hr_status'] == 1 && $row['admin_status'] == 0): ?>
                                                    <button class="btn btn-sm btn-success" title="Final Approve" onclick="decideLeave(<?= $row['id'] ?>,'admin',1)"><i class="ri-shield-check-line"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="Final Reject" onclick="decideLeave(<?= $row['id'] ?>,'admin',2)"><i class="ri-close-line"></i></button>
                                                <?php endif; ?>
                                                <?php if ($editable): ?>
                                                    <button class="btn btn-sm btn-outline-primary" title="Edit"
                                                        onclick='editLeave(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="ri-edit-line"></i></button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="deleteLeave(<?= $row['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- File / Edit Leave Modal -->
<div class="modal fade" id="modal-leave" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-leave">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="leave-modal-title">
                        <i class="ri-calendar-event-line me-2" style="color:#009688;"></i>File Leave
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leave-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select class="form-control" id="leave-employee" name="employee_id" data-live-search="true" required>
                            <option value=""></option>
                            <?php
                            $emps = $conn->query("SELECT id, employee_no, firstname, lastname FROM employee WHERE status = 1 ORDER BY lastname ASC");
                            if ($emps) while ($e = $emps->fetch_assoc()):
                            ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['lastname'] . ', ' . $e['firstname']) ?> (<?= htmlspecialchars($e['employee_no']) ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type of Leave <span class="text-danger">*</span></label>
                        <select class="form-control" id="leave-type" name="leave_type_id" required>
                            <option value=""></option>
                            <?php
                            $types = $conn->query("SELECT id, name, days_allowed FROM leave_types WHERE status = 1 ORDER BY name ASC");
                            if ($types) while ($t = $types->fetch_assoc()):
                            ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= (int)$t['days_allowed'] ?> days/yr)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Leave Day(s) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leave-dates" placeholder="Pick one or more days…" readonly>
                        <input type="hidden" name="dates" id="leave-dates-hidden">
                        <small class="text-muted"><i class="ri-information-line"></i> Pick any individual days (e.g. Mon &amp; Wed). Holiday dates are disabled.</small>
                    </div>
                    <div class="alert alert-info py-2 px-3 mb-3" id="leave-duration-info" style="display:none;">
                        <i class="ri-time-line me-1"></i>Total: <b id="leave-duration-val">0</b> day(s)
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Reason / Purpose <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="leave-reason" name="reason" rows="3" placeholder="State the reason for the leave" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Submit</button>
                </div>
            </div>
        </form>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
var LEAVE_BLOCKED = <?= json_encode(array_values(array_unique($blocked_dates))) ?>;
var leaveFp = null;

document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#leave-table')) {
        jQuery('#leave-table').DataTable({
            order: [[0, 'desc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: 8 }],
            language: { search: '', searchPlaceholder: 'Search leave…' }
        });
    }
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#leave-employee').select2({ dropdownParent: jQuery('#modal-leave'), placeholder: 'Search employee…', width: '100%' });
        jQuery('#leave-type').select2({ dropdownParent: jQuery('#modal-leave'), placeholder: 'Select leave type', width: '100%' });
    }
    if (typeof flatpickr !== 'undefined') {
        leaveFp = flatpickr('#leave-dates', {
            mode: 'multiple', dateFormat: 'Y-m-d', disable: LEAVE_BLOCKED,
            onChange: function (sel) {
                document.getElementById('leave-dates-hidden').value = sel.map(function (d) { return flatpickr.formatDate(d, 'Y-m-d'); }).join(',');
                const box = document.getElementById('leave-duration-info');
                if (sel.length) { document.getElementById('leave-duration-val').textContent = sel.length; box.style.display = 'block'; }
                else box.style.display = 'none';
            }
        });
    }
});

function resetLeaveModal() {
    document.getElementById('leave-id').value = '';
    document.getElementById('form-leave').reset();
    document.getElementById('leave-duration-info').style.display = 'none';
    document.getElementById('leave-dates-hidden').value = '';
    if (leaveFp) leaveFp.clear();
    document.getElementById('leave-modal-title').innerHTML = '<i class="ri-calendar-event-line me-2" style="color:#009688;"></i>File Leave';
    if (window.jQuery) {
        jQuery('#leave-employee').val('').trigger('change');
        jQuery('#leave-type').val('').trigger('change');
    }
}

document.getElementById('btn-file-leave').addEventListener('click', function () {
    resetLeaveModal();
    new bootstrap.Modal(document.getElementById('modal-leave')).show();
});

function editLeave(row) {
    resetLeaveModal();
    document.getElementById('leave-id').value = row.id;
    document.getElementById('leave-reason').value = row.reason || '';
    document.getElementById('leave-modal-title').innerHTML = '<i class="ri-edit-line me-2" style="color:#009688;"></i>Edit Leave';
    // Prefill exact days from stored JSON (falls back to the from–to range).
    let days = [];
    try { days = row.dates ? JSON.parse(row.dates) : []; } catch (e) { days = []; }
    if (!days.length && row.date_from) {
        let d = new Date(row.date_from), end = new Date(row.date_to || row.date_from);
        while (d <= end) { days.push(flatpickr.formatDate(d, 'Y-m-d')); d.setDate(d.getDate() + 1); }
    }
    if (leaveFp) { leaveFp.setDate(days, true); }
    if (window.jQuery) {
        jQuery('#leave-employee').val(row.employee_id).trigger('change');
        jQuery('#leave-type').val(row.leave_type_id).trigger('change');
    }
    new bootstrap.Modal(document.getElementById('modal-leave')).show();
}

document.getElementById('modal-leave').addEventListener('hidden.bs.modal', resetLeaveModal);

document.getElementById('form-leave').addEventListener('submit', async function (e) {
    e.preventDefault();
    const res = await fetch('ajax.php?action=save_leave_request', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const json = await res.json();
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-leave')).hide();
        Swal.fire({ icon: 'success', title: 'Success', text: json.message, timer: 1400, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to save.' });
    }
});

// stage = 'hr' | 'admin', status = 1 approve / 2 reject
async function decideLeave(id, stage, status) {
    const stageLabel = stage === 'hr' ? 'HR' : 'Final';
    let remarks = '';
    if (status === 2) {
        const r = await Swal.fire({
            title: stageLabel + ' Rejection',
            input: 'textarea',
            inputLabel: 'Reason for rejection',
            inputPlaceholder: 'Enter the reason…',
            inputValidator: (v) => (!v ? 'A reason is required to reject.' : undefined),
            showCancelButton: true, confirmButtonText: 'Reject', confirmButtonColor: '#d33'
        });
        if (!r.isConfirmed) return;
        remarks = r.value || '';
    } else {
        const c = await Swal.fire({
            title: stageLabel + ' Approval',
            text: 'Approve this leave at the ' + stageLabel + ' stage?',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Approve', confirmButtonColor: '#28a745'
        });
        if (!c.isConfirmed) return;
    }
    const res = await fetch('ajax.php?action=decide_leave', { method: 'POST', body: new URLSearchParams({ id, stage, status, remarks }) });
    const json = await res.json();
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Done', text: json.message, timer: 1300, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to update.' });
    }
}

async function deleteLeave(id) {
    const c = await Swal.fire({ title: 'Delete this leave request?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
    if (!c.isConfirmed) return;
    const res = await fetch('ajax.php?action=delete_leave_request', { method: 'POST', body: new URLSearchParams({ id }) });
    const json = await res.json();
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Deleted', text: json.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to delete.' });
    }
}
</script>
