<?php
$my_role = (int) ($_SESSION['login_role'] ?? 0);
$can_decide = in_array($my_role, [1, 8, 9], true); // Admin, Department Head, HR Head

// Department Heads only see their own department's requests.
require_once 'dept-scope.php';

$counts = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$cq = $conn->query("SELECT status, COUNT(*) AS c FROM attendance_requests WHERE 1=1" . dept_scope_emp_sql('employee_id') . " GROUP BY status");
if ($cq) while ($r = $cq->fetch_assoc()) {
    $counts['total'] += (int)$r['c'];
    if ($r['status'] == 0) $counts['pending']  = (int)$r['c'];
    if ($r['status'] == 1) $counts['approved'] = (int)$r['c'];
    if ($r['status'] == 2) $counts['rejected'] = (int)$r['c'];
}

$reasonLabels = [
    'forgot_scan'  => 'Forgot to Scan',
    'device_error' => 'Device/Scanner Error',
    'system_down'  => 'System Down',
    'overtime'     => 'Overtime Authorization',
    'other'        => 'Other',
];
?>
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
                                <i class="ri-error-warning-line me-2 text-success"></i>Incident Reports &amp; OT Requests
                            </h4>
                            <span class="badge bg-light text-dark border" title="Who can decide">
                                <i class="ri-shield-user-line me-1"></i>Admin / Dept Head / HR Head
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
                                                   ru.name AS reviewer_name
                                            FROM attendance_requests ar
                                            INNER JOIN employee e ON e.id = ar.employee_id
                                            LEFT JOIN users ru ON ru.id = ar.reviewed_by
                                            WHERE 1=1 " . dept_scope_sql('e.department_id') . "
                                            ORDER BY ar.created_at DESC
                                        ");
                                        if ($q) while ($row = $q->fetch_assoc()):
                                            $statusMap = [
                                                0 => ['Pending',  'bg-warning'],
                                                1 => ['Approved', 'bg-success'],
                                                2 => ['Rejected', 'bg-danger'],
                                            ];
                                            [$slabel, $sclass] = $statusMap[$row['status']] ?? ['Unknown', 'bg-secondary'];
                                        ?>
                                        <tr data-status="<?= (int)$row['status'] ?>">
                                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                            <td>
                                                <a href="index.php?page=employee-details&id=<?= (int)$row['employee_id'] ?>" class="rpt-emp-link fw-semibold" title="View employee details"><?= htmlspecialchars($row['employee_name']) ?></a>
                                                <div class="text-muted" style="font-size:11px;"><i class="ri-hashtag"></i><?= htmlspecialchars($row['employee_no']) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($row['request_type'] === 'incident'): ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-error-warning-line me-1"></i>Incident</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="ri-timer-flash-line me-1"></i>Overtime</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($row['request_date'])) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($reasonLabels[$row['reason']] ?? $row['reason']) ?></span></td>
                                            <td style="font-size:12px;">
                                                <?php if ($row['claimed_time_in'] || $row['claimed_time_out']): ?>
                                                    <?= $row['claimed_time_in'] ? date('h:i A', strtotime($row['claimed_time_in'])) : '—' ?>
                                                    &ndash;
                                                    <?= $row['claimed_time_out'] ? date('h:i A', strtotime($row['claimed_time_out'])) : '—' ?>
                                                <?php endif; ?>
                                                <?php if ($row['ot_hours_requested']): ?>
                                                    <div><b><?= $row['ot_hours_requested'] ?> hrs</b> requested</div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width:180px;"><span class="text-muted"><?= nl2br(htmlspecialchars($row['notes'] ?? '')) ?></span></td>
                                            <td class="text-center">
                                                <span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span>
                                                <?php if ($row['status'] != 0): ?>
                                                    <div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($row['reviewer_name'] ?? '') ?></div>
                                                <?php endif; ?>
                                                <?php if ($row['reviewer_remarks']): ?>
                                                    <div class="text-muted" style="font-size:10px;" title="<?= htmlspecialchars($row['reviewer_remarks']) ?>"><i class="ri-information-line"></i> <?= htmlspecialchars(mb_strimwidth($row['reviewer_remarks'], 0, 24, '…')) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($can_decide && $row['status'] == 0): ?>
                                                    <button class="btn btn-sm btn-success" title="Approve" onclick="decideRequest(<?= $row['id'] ?>,1)"><i class="ri-check-double-line"></i></button>
                                                    <button class="btn btn-sm btn-danger" title="Reject" onclick="decideRequest(<?= $row['id'] ?>,2)"><i class="ri-close-line"></i></button>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
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

<script>
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

async function decideRequest(id, status) {
    let remarks = '';
    if (status === 2) {
        remarks = prompt('Reason for rejection (optional):') || '';
    }
    const res = await fetch('ajax.php?action=decide_attendance_request', {
        method: 'POST',
        body: new URLSearchParams({ id, status, remarks })
    });
    const json = await res.json();
    if (json?.result) {
        location.reload();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to update request.' });
    }
}
</script>
