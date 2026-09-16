<?php
require_once __DIR__ . '/includes/leave_timeline.php';
$my_role = (int) ($_SESSION['login_role'] ?? 0);
$my_uid  = (int) ($_SESSION['login_id'] ?? 0);
// Attendance requests run the SAME four-stage, area-based chain as leave
// (Section/Unit Head → Supervisor → Department Head → HR). Who may act is
// decided per ROW below (leave_user_can_act at the row's current stage), not
// per page: one person can hold different stages in different wards.
// Administrator is view-only on the chain, as on leave; delete stays with
// Admin + HR.
$is_admin_view = ($my_role === 1);
$in_chain      = in_array($my_role, [8, 9, 10, 11], true) && can_edit('attendance-requests');
$can_delete    = in_array($my_role, [1, 9], true);
// Cancelling an APPROVED request (rewinds its DTR / payroll effect) is the same
// HR/Admin pair — admin_class::cancel_attendance_request enforces it server-side.
$can_cancel    = in_array($my_role, [1, 9], true);
$att_stage_defs = leave_stages();

// Approvers only see their own areas' requests (dept-scope.php emits an area
// predicate for area-scoped accounts); HR and Admin see everything.
require_once 'dept-scope.php';

$counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'awaiting' => 0];
$cq = $conn->query("SELECT status, COUNT(*) AS c FROM attendance_requests WHERE 1=1" . dept_scope_emp_sql('employee_id') . " GROUP BY status");
if ($cq) while ($r = $cq->fetch_assoc()) {
    $counts['total'] += (int)$r['c'];
    if ($r['status'] == 0) $counts['pending']   = (int)$r['c'];
    if ($r['status'] == 1) $counts['approved']  = (int)$r['c'];
    if ($r['status'] == 2) $counts['rejected']  = (int)$r['c'];
    if ($r['status'] == 3) $counts['cancelled'] = (int)$r['c'];
}
if ($in_chain) {
    $counts['awaiting'] = stage_awaiting_me_count($conn, $my_uid, 'attendance_requests', dept_scope_emp_sql('employee_id'));
}
$att_timelines = [];   // request id => timeline HTML for the trail modal
$att_meta      = [];   // request id => employee / type / stage for the modal header

$reasonLabels = [
    'forgot_scan'  => 'Forgot to Scan',
    'device_error' => 'Device/Scanner Error',
    'system_down'  => 'System Down',
    'overtime'     => 'Overtime Authorization',
    'other'        => 'Other',
    'rest_day_work' => 'Rest Day / Day-Off Work',
];
?>
<!-- Stored-attachment view + in-app viewer (shared with the portal's request form) -->
<link rel="stylesheet" href="<?= av('assets2/css/attach-upload.css') ?>">
<script src="<?= av('assets2/js/attach-upload.js') ?>"></script>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Attendance Requests</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Attendance Requests</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6">
                    <div class="card card-animate">
                        <div class="card-body d-flex align-items-center">
                            <div class="rounded bg-primary-subtle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;">
                                <i class="ri-file-list-3-line fs-22 text-primary"></i>
                            </div>
                            <?php if ($in_chain): ?>
                                <div><p class="text-muted mb-1">Awaiting My Approval</p><h4 class="mb-0"><?= $counts['awaiting'] ?></h4></div>
                            <?php else: ?>
                                <div><p class="text-muted mb-1">Total Requests</p><h4 class="mb-0"><?= $counts['total'] ?></h4></div>
                            <?php endif; ?>
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
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <h4 class="card-title mb-0 flex-grow-1">
                                <i class="ri-error-warning-line me-2 text-success"></i>Incident Reports &amp; OT Requests
                            </h4>
                            <span class="badge bg-light text-dark border text-wrap text-start" title="Approval chain — same as leave, per area">
                                <i class="ri-shield-user-line me-1"></i><?= htmlspecialchars(implode(' → ', array_map(fn($s) => $s['label'], $att_stage_defs))) ?>
                            </span>
                        </div>
                        <div class="card-header border-bottom-dashed pt-3 pb-0">
                            <ul class="nav nav-tabs-custom card-header-tabs border-bottom-0" id="att-req-tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" data-status="all" href="javascript:void(0);" role="tab">
                                        <i class="ri-file-list-3-line me-1 align-bottom"></i>All
                                        <span class="badge bg-primary-subtle text-primary align-middle ms-1"><?= $counts['total'] ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-status="0" href="javascript:void(0);" role="tab">
                                        <i class="ri-time-line me-1 align-bottom"></i>Pending
                                        <span class="badge bg-warning-subtle text-warning align-middle ms-1"><?= $counts['pending'] ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-status="1" href="javascript:void(0);" role="tab">
                                        <i class="ri-checkbox-circle-line me-1 align-bottom"></i>Approved
                                        <span class="badge bg-success-subtle text-success align-middle ms-1"><?= $counts['approved'] ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-status="2" href="javascript:void(0);" role="tab">
                                        <i class="ri-close-circle-line me-1 align-bottom"></i>Rejected
                                        <span class="badge bg-danger-subtle text-danger align-middle ms-1"><?= $counts['rejected'] ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" data-status="3" href="javascript:void(0);" role="tab">
                                        <i class="ri-arrow-go-back-line me-1 align-bottom"></i>Cancelled
                                        <span class="badge bg-secondary-subtle text-secondary align-middle ms-1"><?= $counts['cancelled'] ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="att-req-table" class="table table-hover table-bordered align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th><i class="ri-calendar-line me-1"></i>Filed</th>
                                            <th><i class="ri-user-line me-1"></i>Employee</th>
                                            <th><i class="ri-bookmark-line me-1"></i>Type</th>
                                            <th><i class="ri-calendar-event-line me-1"></i>Date</th>
                                            <th><i class="ri-question-line me-1"></i>Reason</th>
                                            <th><i class="ri-time-line me-1"></i>Claimed Time / OT Hrs</th>
                                            <th><i class="ri-chat-1-line me-1"></i>Notes</th>
                                            <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                            <th class="text-center" style="width:140px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $q = $conn->query("
                                            SELECT ar.*, CONCAT(e.lastname, ', ', e.firstname) AS employee_name, e.employee_no,
                                                   ru.name AS reviewer_name,
                                                   se.name AS sec_name, su.name AS sup_name, au.name AS admin_name, hu.name AS hr_name,
                                                   cu.name AS cancelled_name
                                            FROM attendance_requests ar
                                            INNER JOIN employee e ON e.id = ar.employee_id
                                            LEFT JOIN users ru ON ru.id = ar.reviewed_by
                                            LEFT JOIN users cu ON cu.id = ar.cancelled_by
                                            LEFT JOIN users se ON se.id = ar.sec_by
                                            LEFT JOIN users su ON su.id = ar.sup_by
                                            LEFT JOIN users au ON au.id = ar.admin_by
                                            LEFT JOIN users hu ON hu.id = ar.hr_by
                                            WHERE 1=1 " . dept_scope_sql('e.department_id') . "
                                            ORDER BY ar.created_at DESC
                                        ");
                                        if ($q) while ($row = $q->fetch_assoc()):
                                            $statusMap = [
                                                0 => ['Pending',  'bg-warning'],
                                                1 => ['Approved', 'bg-success'],
                                                2 => ['Rejected', 'bg-danger'],
                                                3 => ['Cancelled', 'bg-secondary'],
                                            ];
                                            [$slabel, $sclass] = $statusMap[$row['status']] ?? ['Unknown', 'bg-secondary'];
                                            // Where the chain stands and whether it is THIS user's turn.
                                            $cur_stage   = leave_current_stage($row);
                                            $can_act_now = $cur_stage && $in_chain
                                                && leave_user_can_act($conn, $my_uid, $cur_stage, (int) $row['employee_id']);
                                            $awaiting_who = $cur_stage ? implode(' / ', leave_stage_approver_names($conn, $cur_stage, (int) $row['employee_id'])) : '';
                                            $att_timelines[$row['id']] = leave_timeline_html($row);
                                            $att_meta[$row['id']] = [
                                                'emp'   => $row['employee_name'],
                                                'type'  => att_request_label($row['request_type'], true),
                                                'date'  => date('M d, Y', strtotime($row['request_date'])),
                                                'stat'  => (int) $row['status'],
                                                'stage' => $cur_stage ? $att_stage_defs[$cur_stage]['label'] : '',
                                            ];
                                        ?>
                                        <tr data-status="<?= (int)$row['status'] ?>"
                                            data-req-id="<?= (int)$row['id'] ?>"
                                            data-emp-id="<?= (int)$row['employee_id'] ?>"
                                            data-emp-name="<?= htmlspecialchars($row['employee_name']) ?>"
                                            data-type="<?= htmlspecialchars($row['request_type']) ?>"
                                            data-date="<?= htmlspecialchars($row['request_date']) ?>"
                                            data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                            data-hours="<?= $row['ot_hours_requested'] !== null ? htmlspecialchars($row['ot_hours_requested']) : '' ?>"
                                            data-in="<?= htmlspecialchars($row['claimed_time_in'] ?? '') ?>"
                                            data-out="<?= htmlspecialchars($row['claimed_time_out'] ?? '') ?>"
                                            data-notes="<?= htmlspecialchars($row['notes'] ?? '') ?>"
                                            data-attach="<?= htmlspecialchars($row['attachment'] ?? '') ?>">
                                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                            <td>
                                                <a href="index.php?page=employee-details&id=<?= (int)$row['employee_id'] ?>" data-emp-quickview="<?= (int)$row['employee_id'] ?>" class="rpt-emp-link fw-semibold" title="View employee details"><?= htmlspecialchars($row['employee_name']) ?></a>
                                                <div class="text-muted" style="font-size:11px;"><i class="ri-hashtag"></i><?= htmlspecialchars($row['employee_no']) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($row['request_type'] === 'incident'): ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-error-warning-line me-1"></i>Incident</span>
                                                <?php elseif ($row['request_type'] === 'rest_day'): ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="ri-moon-line me-1"></i>Rest Day</span>
                                                <?php elseif ($row['request_type'] === 'undertime'): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="ri-logout-box-r-line me-1"></i>Undertime</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="ri-timer-flash-line me-1"></i>Overtime</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($row['request_date'])) ?></td>
                                            <td class="req-reason-cell"><span class="badge bg-light text-dark border"><?= htmlspecialchars($reasonLabels[$row['reason']] ?? $row['reason']) ?></span></td>
                                            <td class="req-detail-cell" style="font-size:12px;">
                                                <?php if ($row['claimed_time_in'] || $row['claimed_time_out']): ?>
                                                    <?= $row['claimed_time_in'] ? date('h:i A', strtotime($row['claimed_time_in'])) : '—' ?>
                                                    &ndash;
                                                    <?= $row['claimed_time_out'] ? date('h:i A', strtotime($row['claimed_time_out'])) : '—' ?>
                                                <?php endif; ?>
                                                <?php if ($row['ot_hours_requested']): ?>
                                                    <div><b><?= $row['ot_hours_requested'] ?> hrs</b> <?= $row['request_type'] === 'rest_day' ? 'rendered' : ($row['request_type'] === 'undertime' ? 'to excuse' : 'requested') ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width:180px;">
                                                <span class="text-muted req-notes-text"><?= nl2br(htmlspecialchars($row['notes'] ?? '')) ?></span>
                                                <?php if (!empty($row['attachment'])):
                                                    $attUrl   = 'uploads/' . rawurlencode($row['attachment']);
                                                    $attIsPdf = (bool) preg_match('/\.pdf$/i', $row['attachment']); ?>
                                                    <div style="margin-top:4px;">
                                                        <?php if ($attIsPdf): ?>
                                                            <a href="<?= $attUrl ?>" data-att-name="<?= htmlspecialchars($row['attachment']) ?>" class="att-view att-view-pdf" style="padding:4px 9px;font-size:11px;">
                                                                <i class="ri-file-pdf-2-fill"></i><span>View PDF</span><i class="ri-eye-line att-view-open"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= $attUrl ?>" data-att-name="<?= htmlspecialchars($row['attachment']) ?>" class="att-view att-view-img" style="max-width:110px;" title="View">
                                                                <img src="<?= $attUrl ?>" alt="attachment" style="max-height:64px;">
                                                                <span class="att-view-zoom"><i class="ri-zoom-in-line"></i> View</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center req-status-cell">
                                                <span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span>
                                                <!-- One chip per approval stage (icon coloured by verdict, stage in the tooltip) -->
                                                <div class="lv-chips mt-1" style="font-size:14px;line-height:1;"><?= leave_stage_chips($row) ?></div>
                                                <?php if ($cur_stage): ?>
                                                    <div class="text-muted" style="font-size:10px;margin-top:2px;">
                                                        Awaiting <b><?= htmlspecialchars($att_stage_defs[$cur_stage]['label']) ?></b>
                                                        <?php if ($awaiting_who !== ''): ?><br><span title="Who can act on this stage"><?= htmlspecialchars($awaiting_who) ?></span><?php else: ?><br><span class="text-warning">No approver assigned</span><?php endif; ?>
                                                    </div>
                                                <?php elseif ($row['status'] != 0): ?>
                                                    <div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($row['reviewer_name'] ?? '') ?></div>
                                                <?php endif; ?>
                                                <?php if ($row['reviewer_remarks']): ?>
                                                    <div class="text-muted" style="font-size:10px;" title="<?= htmlspecialchars($row['reviewer_remarks']) ?>"><i class="ri-information-line"></i> <?= htmlspecialchars(mb_strimwidth($row['reviewer_remarks'], 0, 24, '…')) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center req-actions-cell">
                                                <div class="d-inline-flex gap-1">
                                                <?php if ($can_act_now): ?>
                                                    <!-- Opens the review modal: the day's scans, the figures (editable
                                                         before the decision — update_attendance_request) and Approve in
                                                         one place, so the approver never decides on a number they have
                                                         not seen against the record behind it. -->
                                                    <button class="btn btn-sm btn-success" title="Review &amp; approve (<?= htmlspecialchars($att_stage_defs[$cur_stage]['label']) ?> stage)" onclick="reviewRequest(<?= $row['id'] ?>)"><i class="ri-check-double-line"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="Reject" onclick="decideRequest(<?= $row['id'] ?>,2)"><i class="ri-close-line"></i></button>
                                                <?php elseif ($is_admin_view || $my_role === 9): ?>
                                                    <button class="btn btn-sm btn-outline-secondary" title="Review (view)" onclick="reviewRequest(<?= $row['id'] ?>)"><i class="ri-eye-line"></i></button>
                                                <?php endif; ?>
                                                    <button class="btn btn-sm btn-outline-secondary" title="Approval trail" onclick="openAttTimeline(<?= $row['id'] ?>)"><i class="ri-route-line"></i></button>
                                                <?php if ($can_delete && $row['status'] == 0): ?>
                                                    <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="deleteRequest(<?= $row['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
                                                <?php endif; ?>
                                                <?php if ($can_cancel && $row['status'] == 1): ?>
                                                    <button class="btn btn-sm btn-outline-warning" title="Cancel approved request — undoes its DTR / payroll effect (HR / Admin only)" onclick="cancelRequest(<?= $row['id'] ?>)"><i class="ri-arrow-go-back-line"></i></button>
                                                <?php endif; ?>
                                                </div>
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

<?php include __DIR__ . '/includes/attendance_request_review.php'; ?>
<?php leave_timeline_css(); ?>

<!-- Approval trail — same timeline the leave queue shows, one modal per page -->
<div class="modal fade" id="att-timeline-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="ri-route-line me-1"></i>Approval trail</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="att-timeline-subject" class="mb-2 small"></div>
        <div id="att-timeline-body"></div>
      </div>
    </div>
  </div>
</div>

<script>
// ── Review & approve ─────────────────────────────────────────────────────────
// The modal itself lives in includes/attendance_request_review.php — shared with
// the DTR review screen, so both decide requests through one form. An edit
// repaints the row in place; a DECISION reloads, because the row's stage
// chips, the "awaiting" line, the tile counts and the action buttons all
// change with it (exactly what leaves.php does).
var REASON_LABELS = <?= json_encode($reasonLabels) ?>;
var ME_NAME       = <?= json_encode($_SESSION['login_name'] ?? $_SESSION['login_username'] ?? '') ?>;
var ATT_TIMELINES = <?= json_encode($att_timelines) ?>;
var ATT_META      = <?= json_encode($att_meta) ?>;

function reqRow(id) { return document.querySelector('tr[data-req-id="' + id + '"]'); }

function openAttTimeline(id) {
    var m = ATT_META[id] || {};
    var out;
    if (m.stat === 1)      out = '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="ri-checkbox-circle-fill me-1"></i>Fully approved</span>';
    else if (m.stat === 2) out = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="ri-close-circle-fill me-1"></i>Rejected</span>';
    else if (m.stat === 3) out = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><i class="ri-arrow-go-back-line me-1"></i>Cancelled</span>';
    else                   out = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-time-fill me-1"></i>' + (m.stage ? 'Awaiting ' + escHtml(m.stage) : 'Pending') + '</span>';
    document.getElementById('att-timeline-subject').innerHTML =
        '<b>' + escHtml(m.emp || '') + '</b> · ' + escHtml(m.type || '') + ' · ' + escHtml(m.date || '') + ' &nbsp;' + out;
    document.getElementById('att-timeline-body').innerHTML = ATT_TIMELINES[id] || '<div class="text-muted">No trail.</div>';
    new bootstrap.Modal(document.getElementById('att-timeline-modal')).show();
}

function reviewRequest(id) {
    AttReqReview.open(id, {
        onSaved: function (q) {
            var tr = reqRow(q.id);
            if (!tr) return;
            tr.dataset.reason = q.reason;
            tr.dataset.notes  = q.notes || '';
            tr.dataset.in     = q.time_in || '';
            tr.dataset.out    = q.time_out || '';
            tr.dataset.hours  = (q.ot_hours === null || q.ot_hours === undefined) ? '' : q.ot_hours;
            paintRequestRow(tr);
        },
        onDecided: function () { setTimeout(function () { window.location.reload(); }, 1400); }
    });
}

function deleteRequest(id) {
    Swal.fire({ title: 'Delete this request?', text: 'The employee will have to file it again.', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#c62828', confirmButtonText: 'Yes, delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        fetch('ajax.php?action=delete_attendance_request', { method: 'POST', body: new URLSearchParams({ id: id }) })
            .then(function (x) { return x.json(); })
            .then(function (j) {
                if (j && j.result) { window.location.reload(); }
                else Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Could not delete.' });
            });
    });
}

// HR / Admin: cancel an APPROVED request. The server flips it to Cancelled and
// rewinds what the approval wrote to the DTR (incident punches, OT hours);
// payroll stops counting it on its own.
function cancelRequest(id) {
    var m = ATT_META[id] || {};
    Swal.fire({
        title: 'Cancel this approved request?',
        text: (m.emp || '') + ' · ' + (m.type || '') + ' · ' + (m.date || '') + ' — its DTR / payroll effect will be undone.',
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Reason for cancelling',
        inputPlaceholder: 'Enter the reason…',
        inputValidator: function (v) { return (!v || !v.trim()) ? 'A reason is required to cancel.' : undefined; },
        showCancelButton: true, confirmButtonText: 'Cancel request', cancelButtonText: 'Keep', confirmButtonColor: '#f7b84b'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Cancelling…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        fetch('ajax.php?action=cancel_attendance_request', { method: 'POST', body: new URLSearchParams({ id: id, reason: r.value.trim() }) })
            .then(function (x) { return x.json(); })
            .then(function (j) {
                if (j && j.result) {
                    Swal.fire({ icon: 'success', title: 'Cancelled', text: j.message, confirmButtonText: 'OK' }).then(function () { window.location.reload(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Could not cancel.' });
                }
            })
            .catch(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Could not cancel.' }); });
    });
}

// Repaint the cells an edit can change, from the row's own dataset — the same
// shape the PHP above renders, so an edited row and a freshly loaded one look
// identical without a reload.
function paintRequestRow(tr) {
    var d = tr.dataset;
    var reasonCell = tr.querySelector('.req-reason-cell');
    if (reasonCell) reasonCell.innerHTML = '<span class="badge bg-light text-dark border">'
        + escHtml(REASON_LABELS[d.reason] || d.reason) + '</span>';

    var detail = tr.querySelector('.req-detail-cell');
    if (detail) {
        var html = '';
        if (d.in || d.out) html += fmt12(d.in) + ' &ndash; ' + fmt12(d.out);
        if (d.hours) html += '<div><b>' + escHtml(d.hours) + ' hrs</b> '
            + (d.type === 'rest_day' ? 'rendered' : 'requested') + '</div>';
        detail.innerHTML = html;
    }
    // Only the text — the attachment preview beside it is not ours to redraw.
    var notes = tr.querySelector('.req-notes-text');
    if (notes) notes.innerHTML = escHtml(d.notes || '').replace(/\n/g, '<br>');
}

// The status badge + actions after a decision.
function paintRequestDecision(tr, status) {
    tr.setAttribute('data-status', String(status));
    var map = { 1: ['Approved', 'bg-success'], 2: ['Rejected', 'bg-danger'] };
    var cell = tr.querySelector('.req-status-cell');
    if (cell) cell.innerHTML = '<span class="badge ' + map[status][1] + ' rounded-pill">' + map[status][0] + '</span>'
        + '<div class="text-muted" style="font-size:10px;">' + escHtml(ME_NAME) + '</div>';
    var act = tr.querySelector('.req-actions-cell');
    if (act) act.innerHTML = '<span class="text-muted">&mdash;</span>';
}

function escHtml(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function fmt12(t) {
    if (!t) return '—';
    var p = String(t).split(':'), h = parseInt(p[0], 10), m = p[1] || '00';
    var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    return h + ':' + m + ' ' + ap;
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#att-req-table')) {
        var currentStatus = 'all';

        // Filter rows by the status stored on each <tr data-status="…">
        jQuery.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'att-req-table' || currentStatus === 'all') return true;
            var row = settings.aoData[dataIndex].nTr;
            return row && row.getAttribute('data-status') === currentStatus;
        });

        var table = jQuery('#att-req-table').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            columnDefs: [{ orderable: false, targets: 8 }],
            language: { search: '', searchPlaceholder: 'Search requests…' }
        });

        jQuery('#att-req-tabs .nav-link').on('click', function () {
            currentStatus = jQuery(this).data('status').toString();
            jQuery('#att-req-tabs .nav-link').removeClass('active');
            jQuery(this).addClass('active');
            table.draw();
        });
    }
});

// Quick reject straight from the row — approving always goes through the review
// modal, which is the only path that shows the scans behind the figure.
async function decideRequest(id, status) {
    const dlg = status === 1
        ? await Swal.fire({
            title: 'Approve this request?',
            text: 'An approved incident report writes/repairs the actual DTR record. Are you sure?',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#0f9d58', confirmButtonText: 'Yes, approve',
        })
        : await Swal.fire({
            title: 'Reject this request?',
            text: (ATT_META[id] && ATT_META[id].stage) ? 'Rejecting at the ' + ATT_META[id].stage + ' stage halts the chain.' : '',
            input: 'textarea', inputLabel: 'Reason for rejection',
            inputPlaceholder: 'e.g. No supporting logs / filed on the wrong date',
            inputAttributes: { maxlength: 255 },
            inputValidator: (v) => (!String(v || '').trim() ? 'A reason is required to reject.' : undefined),
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#c62828', confirmButtonText: 'Yes, reject',
        });
    if (!dlg.isConfirmed) return;
    const remarks = status === 2 ? String(dlg.value || '').trim() : '';
    // The stage is the row's CURRENT one; the server re-derives it when omitted
    // and refuses anything out of order.
    const res = await fetch('ajax.php?action=decide_attendance_request', {
        method: 'POST',
        body: new URLSearchParams({ id, status, remarks })
    });
    const json = await res.json();
    if (json?.result) {
        // Reload: stage chips, "awaiting" line, tile counts and the buttons all
        // change with a decision. The message carries the next stage (or the
        // DTR-write warning on a final OT approval), so it is shown first.
        await Swal.fire({
            icon: 'success', title: status === 1 ? 'Approved' : 'Rejected',
            text: json.message || '',
            timer: json.message && json.message.length > 90 ? undefined : 1600,
            showConfirmButton: !!(json.message && json.message.length > 90),
        });
        window.location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to update request.' });
    }
}
</script>
