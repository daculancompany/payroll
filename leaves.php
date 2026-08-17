<?php
// Blocked (holiday) dates so the leave picker can disable them.
$blocked_dates = [];
$bdq = $conn->query("SELECT start_date, end_date FROM calendar_events WHERE blocks_leave = 1 AND COALESCE(end_date,start_date) >= CURDATE()");
if ($bdq) while ($b = $bdq->fetch_assoc()) {
    $d = strtotime($b['start_date']); $e = strtotime($b['end_date'] ?: $b['start_date']);
    while ($d <= $e) { $blocked_dates[] = date('Y-m-d', $d); $d = strtotime('+1 day', $d); }
}

require_once __DIR__ . '/includes/leave_timeline.php';

// Current user's role drives which approval actions are available. The workflow
// itself (order, roles, labels) is defined by LEAVE_APPROVAL_STAGES.
$my_role = (int) ($_SESSION['login_role'] ?? 0);
$my_uid  = (int) ($_SESSION['login_id'] ?? 0);
$leave_stage_defs = leave_stages();
// A per-role single stage no longer decides button visibility (see the row
// loop below) — someone can hold two stages across different areas, e.g. a
// nurse supervisor who is Section Head of her own ward and Supervisor of
// three others. $my_stage survives only where it is genuinely one-role-one-
// stage: it is not read anywhere below.
$my_stage = leave_stage_for_role($my_role);
// Administrator (role 1) does not take part in the approval chain: no approve,
// no edit. Deleting is the one write it keeps — see $can_delete_leave below.
$is_admin_view = ($my_role === 1);
// Deleting a request is an HR/Admin call only (role 1 + role 9). Approvers in
// the chain (Section Head / Supervisor / Dept Head) reject instead of delete.
// admin_class::delete_leave_request enforces the same list server-side.
$can_delete_leave = in_array($my_role, [1, 9], true);
// Timeline HTML per request id, collected during the row loop for the modal.
$leave_timelines = [];
// Employee + type per request id — used in the action confirmation dialogs.
$leave_meta = [];

// Department Heads only see their own department's requests.
require_once 'dept-scope.php';
$lv_scope_emp  = dept_scope_emp_sql('employee_id');       // bare leave_requests queries
$lv_scope_dept = dept_scope_sql('e.department_id');       // queries joining employee e

// Summary counts for the cards
$counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$cq = $conn->query("SELECT status, COUNT(*) AS c FROM leave_requests WHERE 1=1 $lv_scope_emp GROUP BY status");
if ($cq) while ($r = $cq->fetch_assoc()) {
    $counts['total'] += (int)$r['c'];
    if ($r['status'] == 0) $counts['pending']  = (int)$r['c'];
    if ($r['status'] == 1) $counts['approved'] = (int)$r['c'];
    if ($r['status'] == 2) $counts['rejected'] = (int)$r['c'];
}

// Active status tab (server-side filter — avoids client-side lag on large lists).
// ?lstatus= all | pending | approved | rejected
$tab_map    = ['all' => null, 'pending' => 0, 'approved' => 1, 'rejected' => 2];
$active_tab = strtolower(trim($_GET['lstatus'] ?? 'all'));
if (!array_key_exists($active_tab, $tab_map)) $active_tab = 'all';
$status_filter = $tab_map[$active_tab];
$where_sql = 'WHERE 1=1'
    . ($status_filter === null ? '' : ' AND lr.status = ' . (int) $status_filter)
    . $lv_scope_dept;

// Render an approval-stage badge with approver + reason tooltip.
function stageBadge($status, $by_name, $remarks, $at, $by_id = 0)
{
    // Auto-skipped stage: stored approved so the chain advances, but with no
    // approver. Distinguished so the column never credits a decision to nobody.
    if ($status == 1 && !$by_id && $remarks) {
        return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="' . htmlspecialchars($remarks) . '"><i class="ri-skip-forward-line me-1"></i>Skipped</span>';
    }
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
<!-- Stored-attachment view + in-app viewer (shared with the portal's leave form) -->
<link rel="stylesheet" href="<?= av('assets2/css/attach-upload.css') ?>">
<script src="<?= av('assets2/js/attach-upload.js') ?>"></script>
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
                                <i class="ri-flow-chart me-1"></i><?= implode(' &rarr; ', array_map(fn($s) => htmlspecialchars($s['label']), $leave_stage_defs)) ?>
                            </span>
                            <button type="button" class="btn btn-success btn-sm" id="btn-file-leave">
                                <i class="ri-add-line me-1"></i>File Leave
                            </button>
                        </div>
                        <!-- Status tabs (server-side filtering via ?lstatus=…) -->
                        <ul class="nav nav-tabs nav-tabs-custom nav-success px-3 pt-2" role="tablist">
                            <?php
                            $tabs = [
                                'all'      => ['All',      $counts['total'],    'bg-primary-subtle text-primary'],
                                'pending'  => ['Pending',  $counts['pending'],  'bg-warning-subtle text-warning'],
                                'approved' => ['Approved', $counts['approved'], 'bg-success-subtle text-success'],
                                'rejected' => ['Rejected', $counts['rejected'], 'bg-danger-subtle text-danger'],
                            ];
                            foreach ($tabs as $key => $t):
                                $is_active = ($active_tab === $key);
                            ?>
                                <li class="nav-item">
                                    <a class="nav-link <?= $is_active ? 'active' : '' ?>" href="index.php?page=leaves&lstatus=<?= $key ?>">
                                        <?= $t[0] ?>
                                        <span class="badge <?= $t[2] ?> ms-1"><?= (int) $t[1] ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
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
                                            <?php foreach ($leave_stage_defs as $sdef): ?>
                                            <th class="text-center"><i class="<?= htmlspecialchars($sdef['icon'] ?? 'ri-check-line') ?> me-1"></i><?= htmlspecialchars($sdef['label']) ?></th>
                                            <?php endforeach; ?>
                                            <th class="text-center"><i class="ri-pulse-line me-1"></i>Status</th>
                                            <th class="text-center" style="width:180px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $q = $conn->query("
                                            SELECT lr.*,
                                                CONCAT(e.lastname, ', ', e.firstname) AS employee_name,
                                                e.employee_no,
                                                lt.name AS leave_type_name,
                                                se.name AS sec_name,
                                                su.name AS sup_name,
                                                hu.name AS hr_name,
                                                au.name AS admin_name
                                            FROM leave_requests lr
                                            INNER JOIN employee e ON e.id = lr.employee_id
                                            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                                            LEFT JOIN users se ON se.id = lr.sec_by
                                            LEFT JOIN users su ON su.id = lr.sup_by
                                            LEFT JOIN users hu ON hu.id = lr.hr_by
                                            LEFT JOIN users au ON au.id = lr.admin_by
                                            $where_sql
                                            ORDER BY lr.date_applied DESC, lr.id DESC
                                        ");
                                        if ($q) while ($row = $q->fetch_assoc()):
                                            $statusMap = [
                                                0 => ['Pending',  'bg-warning'],
                                                1 => ['Approved', 'bg-success'],
                                                2 => ['Rejected', 'bg-danger'],
                                            ];
                                            [$slabel, $sclass] = $statusMap[$row['status']] ?? ['Unknown', 'bg-secondary'];
                                            // Editable only while no stage has been decided at all.
                                            $editable = true;
                                            foreach ($leave_stage_defs as $skey => $sdef) {
                                                if ((int) $row[$skey . '_status'] !== 0) { $editable = false; break; }
                                            }
                                            $cur_stage = leave_current_stage($row);         // stage awaiting action now
                                            // Per-row, per-employee: is THIS user the approver for the stage
                                            // this specific request is sitting at? Checked here rather than
                                            // by comparing against a single role-derived stage, because one
                                            // person can hold different stages in different areas — a fixed
                                            // "$my_stage" would hide the button on whichever stage did not
                                            // match their users.role.
                                            $can_act_now = $cur_stage && !$is_admin_view
                                                && leave_user_can_act($conn, $my_uid, $cur_stage, (int) $row['employee_id']);
                                            $leave_timelines[$row['id']] = leave_timeline_html($row);
                                            $leave_meta[$row['id']] = [
                                                'emp'  => $row['employee_name'],
                                                'type' => $row['leave_type_name'],
                                                'dur'  => rtrim(rtrim(number_format($row['duration'], 1), '0'), '.'),
                                            ];
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
                                                <?php if ($row['is_half_day']): ?>
                                                    <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">
                                                        <?= htmlspecialchars($row['half_period']) ?> Half<?= !empty($row['half_date']) && (float)$row['duration'] > 0.5 ? ' · ' . date('M j', strtotime($row['half_date'])) : '' ?>
                                                    </span>
                                                <?php endif; ?>
                                                <div class="text-muted" style="font-size:11px;">
                                                    <?= date('M d', strtotime($row['date_from'])) ?> &ndash; <?= date('M d, Y', strtotime($row['date_to'])) ?>
                                                </div>
                                            </td>
                                            <td style="max-width:200px;">
                                                <span class="text-muted"><?= nl2br(htmlspecialchars($row['reason'] ?? '')) ?></span>
                                                <?php if (!empty($row['attachment'])):
                                                    $lvAttUrl   = 'uploads/' . rawurlencode($row['attachment']);
                                                    $lvAttIsPdf = (bool) preg_match('/\.pdf$/i', $row['attachment']); ?>
                                                    <div style="margin-top:4px;">
                                                        <?php if ($lvAttIsPdf): ?>
                                                            <a href="<?= $lvAttUrl ?>" data-att-name="<?= htmlspecialchars($row['attachment']) ?>" class="att-view att-view-pdf" style="padding:4px 9px;font-size:11px;">
                                                                <i class="ri-file-pdf-2-fill"></i><span>View PDF</span><i class="ri-eye-line att-view-open"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= $lvAttUrl ?>" data-att-name="<?= htmlspecialchars($row['attachment']) ?>" class="att-view att-view-img" style="max-width:110px;" title="View">
                                                                <img src="<?= $lvAttUrl ?>" alt="attachment" style="max-height:64px;">
                                                                <span class="att-view-zoom"><i class="ri-zoom-in-line"></i> View</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($leave_stage_defs as $skey => $sdef): ?>
                                            <td class="text-center"><?= stageBadge($row[$skey . '_status'], $row[$skey . '_name'] ?? '', $row[$skey . '_remarks'], $row[$skey . '_at'], (int) ($row[$skey . '_by'] ?? 0)) ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-center"><span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span></td>
                                            <td class="text-center">
                                                <?php
                                                // No Delete on an APPROVED request — it already counts toward
                                                // balances/payroll and the server refuses it anyway
                                                // (delete_leave_request). Reject it instead.
                                                $show_delete = $can_delete_leave && (int) $row['status'] !== 1;
                                                ?>
                                                <?php if ($can_act_now): ?>
                                                    <button class="btn btn-sm btn-success" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($leave_stage_defs[$cur_stage]['label']) ?> Approve" onclick="decideLeave(<?= $row['id'] ?>,'<?= $cur_stage ?>',1)"><i class="ri-check-double-line"></i></button>
                                                    <button class="btn btn-sm btn-danger" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= htmlspecialchars($leave_stage_defs[$cur_stage]['label']) ?> Reject" onclick="decideLeave(<?= $row['id'] ?>,'<?= $cur_stage ?>',2)"><i class="ri-close-line"></i></button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-info" data-bs-toggle="tooltip" data-bs-placement="top" title="Approval timeline" onclick="openLeaveTimeline(<?= $row['id'] ?>)"><i class="ri-history-line"></i></button>
                                                <?php if ($editable && !$is_admin_view): ?>
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit request"
                                                        onclick='editLeave(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="ri-edit-line"></i></button>
                                                <?php endif; ?>
                                                <?php if ($show_delete): ?>
                                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete request (HR / Admin only)" onclick="deleteLeave(<?= $row['id'] ?>)"><i class="ri-delete-bin-line"></i></button>
                                                <?php endif; ?>
                                                <?php if ($is_admin_view && !$show_delete): ?>
                                                    <span class="text-muted" style="font-size:11px;"><i class="ri-eye-line me-1"></i>View only</span>
                                                <?php endif; ?>
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
                        <i class="ri-calendar-event-line me-2" style="color:#673bb6;"></i>File Leave
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="leave-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select class="form-control" id="leave-employee" name="employee_id" data-live-search="true" required>
                            <option value="">Select Employee</option>
                            <?php
                            $emps = $conn->query("SELECT id, employee_no, firstname, lastname FROM employee WHERE status = 1" . dept_scope_sql('department_id') . " ORDER BY lastname ASC");
                            if ($emps) while ($e = $emps->fetch_assoc()):
                            ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['lastname'] . ', ' . $e['firstname']) ?> (<?= htmlspecialchars($e['employee_no']) ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type of Leave <span class="text-danger">*</span></label>
                        <select class="form-control" id="leave-type" name="leave_type_id" required>
                            <option value="">Select Leave Type</option>
                            <?php
                            $types = $conn->query("SELECT id, name, days_allowed FROM leave_types WHERE status = 1 ORDER BY name ASC");
                            if ($types) while ($t = $types->fetch_assoc()):
                            ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= (int)$t['days_allowed'] ?> days/yr)</option>
                            <?php endwhile; ?>
                        </select>
                        <div id="leave-bal-hint" class="mt-1" style="display:none;font-size:11.5px;font-weight:700;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Leave Day(s) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leave-dates" placeholder="Pick one or more days…" readonly>
                        <input type="hidden" name="dates" id="leave-dates-hidden">
                        <small class="text-muted"><i class="ri-information-line"></i> Pick any individual days (e.g. Mon &amp; Wed). Holidays and this employee's already-filed leave dates are disabled.</small>
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
                    <button type="submit" id="leave-submit-btn" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Submit</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php leave_timeline_css(); ?>
<!-- Approval Timeline Modal -->
<div class="modal fade" id="modal-leave-timeline" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="ri-history-line me-2" style="color:#673bb6;"></i>Approval Timeline</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="leave-timeline-body"></div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
var LEAVE_BLOCKED = <?= json_encode(array_values(array_unique($blocked_dates))) ?>;
var LEAVE_TIMELINES = <?= json_encode($leave_timelines) ?>;
var LEAVE_META = <?= json_encode($leave_meta) ?>;
var LEAVE_STAGE_LABELS = <?= json_encode(array_map(fn($s) => $s['label'], $leave_stage_defs)) ?>;

// "Vacation Leave (3 days) — Dela Cruz, Juan" line for confirmation dialogs.
function leaveWho(id) {
    var m = LEAVE_META[id];
    if (!m) return 'this leave request';
    return m.type + ' (' + m.dur + ' day/s) — ' + m.emp;
}
var leaveFp = null;

// Action column sits after the fixed columns + one per approval stage.
var LEAVE_ACTION_COL = <?= 6 + count($leave_stage_defs) ?>;

function openLeaveTimeline(id) {
    document.getElementById('leave-timeline-body').innerHTML = LEAVE_TIMELINES[id] || '<div class="text-muted">No timeline available.</div>';
    new bootstrap.Modal(document.getElementById('modal-leave-timeline')).show();
}

// Action-button tooltips. Rows on other DataTables pages are not in the DOM at
// load, so this re-runs on every draw; already-initialized buttons are skipped.
function lvInitTooltips(scope) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
    (scope || document).querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el, { trigger: 'hover' });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#leave-table')) {
        jQuery('#leave-table').DataTable({
            order: [[0, 'desc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: LEAVE_ACTION_COL }],
            language: { search: '', searchPlaceholder: 'Search leave…' }
        }).on('draw.dt', function () { lvInitTooltips(this); });
    }
    lvInitTooltips();
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
                lvUpdateBalance();
            }
        });
    }
    // Balance + taken-dates react to who/what is being filed (select2 fires
    // jQuery change events, so wire through jQuery).
    if (window.jQuery) {
        jQuery('#leave-employee').on('change', lvFetchInfo);
        jQuery('#leave-type').on('change', lvUpdateBalance);
    }
});

// ── Per-employee filing info: remaining credits per leave type + dates already
// covered by pending/approved requests. Mirrors the employee portal's live
// balance hint and disables taken dates in the picker; the server re-checks
// both on save (save_leave_request), so this is UX, not the security boundary.
var lvInfo = { remain: {}, taken: [] };
function lvSetPickerDisable() {
    if (leaveFp) leaveFp.set('disable', LEAVE_BLOCKED.concat(lvInfo.taken));
}
function lvSelectedCount() {
    return (document.getElementById('leave-dates-hidden').value || '').split(',').filter(Boolean).length;
}
function lvTrim(v) { v = Number(v) || 0; return v % 1 === 0 ? String(v) : v.toFixed(1); }
function lvUpdateBalance() {
    var hint = document.getElementById('leave-bal-hint');
    var btn  = document.getElementById('leave-submit-btn');
    if (!hint) return;
    var tid = document.getElementById('leave-type').value;
    var rem = lvInfo.remain[tid];
    if (tid === '' || rem === undefined || rem === null) {   // no type picked, or an unpaid (LWOP) type — no credit cap
        hint.style.display = 'none';
        if (btn) { btn.disabled = false; btn.style.opacity = ''; }
        return;
    }
    var need = lvSelectedCount(), over = need > rem + 0.001;
    hint.style.display = 'block';
    hint.style.color = over ? '#c62828' : '#4e3483';
    hint.innerHTML = over
        ? '<i class="ri-error-warning-line"></i> Not enough credits — this needs <b>' + lvTrim(need) + '</b> day(s) but only <b>' + lvTrim(Math.max(0, rem)) + '</b> remain (pending requests included).'
        : (need > 0
            ? '<i class="ri-wallet-3-line"></i> Uses <b>' + lvTrim(need) + '</b> of <b>' + lvTrim(rem) + '</b> remaining day(s).'
            : '<i class="ri-wallet-3-line"></i> <b>' + lvTrim(rem) + '</b> day(s) left for this leave type.');
    if (btn) { btn.disabled = over; btn.style.opacity = over ? '.55' : ''; }
}
function lvFetchInfo() {
    var emp = document.getElementById('leave-employee').value;
    var exc = document.getElementById('leave-id').value || 0;
    lvInfo = { remain: {}, taken: [] };
    lvSetPickerDisable();
    lvUpdateBalance();
    if (!emp) return;
    fetch('ajax.php?action=get_leave_filing_info', {
        method: 'POST',
        body: new URLSearchParams({ employee_id: emp, exclude_id: exc })
    }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.result) {
            lvInfo = { remain: j.remain || {}, taken: j.taken || [] };
            lvSetPickerDisable();
            lvUpdateBalance();
        }
    }).catch(function () { /* server still validates on save */ });
}

function resetLeaveModal() {
    document.getElementById('leave-id').value = '';
    document.getElementById('form-leave').reset();
    document.getElementById('leave-duration-info').style.display = 'none';
    document.getElementById('leave-dates-hidden').value = '';
    if (leaveFp) leaveFp.clear();
    lvInfo = { remain: {}, taken: [] };
    lvSetPickerDisable();
    lvUpdateBalance();
    document.getElementById('leave-modal-title').innerHTML = '<i class="ri-calendar-event-line me-2" style="color:#673bb6;"></i>File Leave';
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
    document.getElementById('leave-modal-title').innerHTML = '<i class="ri-edit-line me-2" style="color:#673bb6;"></i>Edit Leave';
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
    // Client-side balance guard — same rule the server enforces on save.
    const tid = document.getElementById('leave-type').value;
    const rem = lvInfo.remain[tid];
    if (rem !== undefined && rem !== null && lvSelectedCount() > rem + 0.001) {
        Swal.fire({ icon: 'error', title: 'Not enough leave credits',
            text: 'This request needs ' + lvTrim(lvSelectedCount()) + ' day(s) but the employee only has '
                + lvTrim(Math.max(0, rem)) + ' left for this leave type (pending requests included).' });
        return;
    }
    // Confirm before saving — filing and editing both go through here.
    const isEdit = !!document.getElementById('leave-id').value;
    const c = await Swal.fire({
        title: isEdit ? 'Save changes?' : 'Submit leave request?',
        text: isEdit ? 'Update this leave request with the new details?' : 'File this leave request and send it for approval?',
        icon: 'question', showCancelButton: true,
        confirmButtonText: isEdit ? 'Save' : 'Submit', confirmButtonColor: '#28a745'
    });
    if (!c.isConfirmed) return;
    const json = await lvPost('save_leave_request', new URLSearchParams(new FormData(this)), isEdit ? 'Saving changes…' : 'Submitting request…');
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-leave')).hide();
        Swal.fire({ icon: 'success', title: 'Success', text: json.message, timer: 1400, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to save.' });
    }
});

// Blocking "working…" dialog while an ajax call is in flight, so a slow
// response never looks like a dead button. Returns the parsed JSON, or a
// {result:false} shape on network / parse failure.
function lvBusy(title) {
    Swal.fire({ title: title || 'Please wait…', text: 'Talking to the server.',
        allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false,
        didOpen: () => Swal.showLoading() });
}
async function lvPost(action, params, busyTitle) {
    lvBusy(busyTitle);
    try {
        const res = await fetch('ajax.php?action=' + action, { method: 'POST', body: params });
        const json = await res.json();
        return json && typeof json === 'object' ? json : { result: false, message: 'Unexpected server response.' };
    } catch (e) {
        return { result: false, message: 'Network error — please check your connection and try again.' };
    }
}

// stage = a key in LEAVE_APPROVAL_STAGES, status = 1 approve / 2 reject
async function decideLeave(id, stage, status) {
    const stageLabel = LEAVE_STAGE_LABELS[stage] || stage;
    let remarks = '';
    if (status === 2) {
        const r = await Swal.fire({
            title: stageLabel + ' Rejection',
            text: leaveWho(id),
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
            text: 'Approve ' + leaveWho(id) + ' at the ' + stageLabel + ' stage?',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Approve', confirmButtonColor: '#28a745'
        });
        if (!c.isConfirmed) return;
    }
    const json = await lvPost('decide_leave', new URLSearchParams({ id, stage, status, remarks }), status === 2 ? 'Rejecting…' : 'Approving…');
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Done', text: json.message, timer: 1300, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to update.' });
    }
}

async function deleteLeave(id) {
    const c = await Swal.fire({
        title: 'Delete this leave request?',
        text: leaveWho(id) + ' — this cannot be undone.',
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33'
    });
    if (!c.isConfirmed) return;
    const json = await lvPost('delete_leave_request', new URLSearchParams({ id }), 'Deleting…');
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Deleted', text: json.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to delete.' });
    }
}
</script>
