<?php
$site_ids = [];
if ($login_role === 6) {
    $user_id = $_SESSION["login_id"];
    $query = $conn->query("SELECT A.* FROM sites AS A WHERE pic = $user_id ORDER BY A.site_name ASC");
    while ($row = $query->fetch_assoc()) {
        array_push($site_ids, $row['id']);
    }
    $commaSeparatedSites = implode(',', $site_ids);
    $filter_query = "AND sites.id IN ($commaSeparatedSites) ";
} else {
    $filter_query = '';
}

// Status badge helper for server-rendered rows.
function dtr_status_badge($s) {
    $s = (int)$s;
    if ($s === 2) return '<span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>Approved</span>';
    if ($s === 3) return '<span class="badge bg-info text-dark"><i class="ri-user-received-2-line me-1"></i>Ready for Review</span>';
    if ($s === 1) return '<span class="badge bg-warning text-dark"><i class="ri-time-line me-1"></i>Pending</span>';
    return '<span class="badge bg-secondary"><i class="ri-loader-2-line me-1"></i>Open</span>';
}

// Hours, trimmed: 304.1 h, 1,650.2 h, 0 h.
function dtr_hrs($h) {
    return rtrim(rtrim(number_format((float)$h, 1), '0'), '.') . ' h';
}

// Payroll run per period (DTR dates are 'Y-m-d' strings, payroll's are DATE —
// same text), so each batch can show which payroll uses it.
$dtr_payrolls = [];
$pq = $conn->query("SELECT id, ref_no, status, date_from, date_to FROM payroll ORDER BY id DESC");
while ($pq && ($p = $pq->fetch_assoc())) {
    $k = $p['date_from'] . '|' . $p['date_to'];
    if (!isset($dtr_payrolls[$k])) $dtr_payrolls[$k] = $p;   // newest run for that period
}
$dtr_payroll_status = [0 => ['New', 'primary'], 1 => ['Calculated', 'success'], 3 => ['Ready for Review', 'warning'], 2 => ['Locked', 'danger']];
?>
<style>
    .dtr-period { font-weight:700; color:#673bb6; font-size:13px; font-family:'Segoe UI',monospace; }
    .dtr-period small { display:block; font-size:10px; color:#888; font-weight:400; font-family:inherit; margin-top:1px; }
    .dtr-site-code { background:#673bb6; color:#fff; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:700; display:inline-block; margin-bottom:3px; }
    .dtr-site-name { font-size:12px; font-weight:600; color:#222; }
    .dtr-site-addr { font-size:11px; color:#888; }
    .dtr-user { font-size:13px; font-weight:600; }
    .dtr-user small { font-size:10px; color:#888; font-weight:400; display:block; }
    .dtr-action { display:flex; gap:4px; justify-content:center; }
    /* Coverage / totals / payroll lines under the main figures */
    .dtr-sub { font-size:11px; color:var(--bs-secondary-color, #888); margin-top:4px; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .dtr-sub i { opacity:.8; }
    .dtr-progress { height:4px; max-width:160px; margin-top:4px; background:var(--bs-secondary-bg, #e9ecef); border-radius:2px; overflow:hidden; }
    .dtr-progress > span { display:block; height:100%; background:var(--bs-primary, #673bb6); border-radius:2px; }
    .dtr-chip { font-size:10.5px; font-weight:600; }
    .dtr-payroll { font-size:11px; margin-top:5px; white-space:nowrap; }
    .dtr-payroll a { font-weight:700; text-decoration:none; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-time-line me-2" style="color:#673bb6;"></i>Daily Time Record</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Daily Time Record</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card" style="border-top:3px solid #673bb6;">
                    <div class="card-header align-items-center d-flex flex-wrap gap-2 py-2">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-time-line me-2" style="color:#673bb6;"></i>DTR List
                        </h4>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="data-table" class="table table-hover table-bordered dt-responsive nowrap align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="ri-calendar-2-line me-1"></i>Period</th>
                                        <th><i class="ri-map-pin-2-line me-1"></i>Site</th>
                                        <th><i class="ri-file-list-3-line me-1"></i>Attendance Summary</th>
                                        <th class="text-center"><i class="ri-flag-line me-1"></i>Status</th>
                                        <th><i class="ri-shield-check-line me-1"></i>Approved By</th>
                                        <th class="text-center" style="width:140px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $query = $conn->query("SELECT DTR.*, sites.site_code, sites.site_name, sites.site_address,
                                        timekeeper.name AS timekeeper_name, uploaded.name AS uploaded_by, approved.name AS approve_by, employer_name,
                                        SUM(CASE WHEN DTR_details.status = 0 THEN 1 ELSE 0 END) AS pending_count,
                                        SUM(CASE WHEN DTR_details.status = 1 THEN 1 ELSE 0 END) AS approved_count,
                                        SUM(CASE WHEN DTR_details.status = 2 THEN 1 ELSE 0 END) AS disapproved_count,
                                        COUNT(DISTINCT DTR_details.employee_id) AS emp_count,
                                        COUNT(DISTINCT DTR_details.date_time) AS day_count,
                                        COALESCE(SUM(DTR_details.is_complete = 0), 0) AS incomplete_count,
                                        COALESCE(SUM(DTR_details.attendance_type = 'manual'), 0) AS manual_count,
                                        COALESCE(SUM(DTR_details.late), 0) AS late_hrs,
                                        COALESCE(SUM(DTR_details.undertime), 0) AS ut_hrs,
                                        COALESCE(SUM(DTR_details.overtime), 0) AS ot_hrs,
                                        COALESCE(SUM(DTR_details.nsd_hours), 0) AS nd_hrs
                                        FROM DTR
                                        LEFT JOIN sites ON DTR.site_id = sites.id
                                        LEFT JOIN users AS timekeeper ON DTR.timekeeper_id = timekeeper.id
                                        LEFT JOIN users AS uploaded ON DTR.uploaded_by = uploaded.id
                                        LEFT JOIN users AS approved ON DTR.approved_by = approved.id
                                        LEFT JOIN employers ON sites.employer_id = employers.id
                                        LEFT JOIN DTR_details ON DTR_details.ddtr_id = DTR.id
                                        WHERE 1=1
                                        $filter_query
                                        GROUP BY DTR.id
                                        ORDER BY DTR.date_from DESC, DTR.id DESC");
                                    while ($row = $query->fetch_assoc()):
                                        $status = (int)$row['status'];
                                        $period = date("M d", strtotime($row['date_from'])) . ' &ndash; ' . date("M j, Y", strtotime($row['date_to']));
                                        $periodDays = max(1, (int) round((strtotime($row['date_to']) - strtotime($row['date_from'])) / 86400) + 1);
                                        $dayCount   = min((int) $row['day_count'], $periodDays);
                                        $empCount   = (int) $row['emp_count'];
                                        $payrollRun = $dtr_payrolls[$row['date_from'] . '|' . $row['date_to']] ?? null;
                                        $viewUrl = "dtr-documents.php?id=" . base64_encode($row['id'])
                                            . "&timekeeper_name=" . base64_encode($row['timekeeper_name'])
                                            . "&device_id=" . base64_encode($row['device_id'])
                                            . "&site_id=" . base64_encode($row['site_id'])
                                            . "&status=" . base64_encode($row['status']);
                                    ?>
                                        <tr>
                                            <td data-order="<?= (int)strtotime($row['date_from']) ?>">
                                                <div class="dtr-period">
                                                    <i class="ri-calendar-2-line me-1 text-muted"></i><?= $period ?>
                                                </div>
                                                <div class="dtr-sub">
                                                    <i class="ri-group-line me-1"></i><?= number_format($empCount) ?> employee<?= $empCount === 1 ? '' : 's' ?>
                                                    &middot; <?= $dayCount ?> of <?= $periodDays ?> days
                                                </div>
                                                <?php if ($dayCount < $periodDays && $status !== 2): // still filling in ?>
                                                <div class="dtr-progress" title="<?= $dayCount ?> of <?= $periodDays ?> days have punches"><span style="width:<?= round($dayCount / $periodDays * 100) ?>%"></span></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="dtr-site-code"><?= htmlspecialchars($row['site_code']) ?></span>
                                                <div class="dtr-site-name"><?= htmlspecialchars($row['site_name']) ?></div>
                                                <div class="dtr-site-addr"><?= htmlspecialchars($row['site_address']) ?></div>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 flex-wrap">
                                                    <?php // Counts are DAY RECORDS (one per employee per day), not employees. ?>
                                                    <span class="badge bg-warning text-dark" title="Day records waiting for a decision"><?= number_format((int)$row['pending_count']) ?> days pending</span>
                                                    <span class="badge bg-success"><?= number_format((int)$row['approved_count']) ?> approved</span>
                                                    <span class="badge bg-danger"><?= number_format((int)$row['disapproved_count']) ?> disapproved</span>
                                                </div>
                                                <?php if ($row['incomplete_count'] > 0 || $row['manual_count'] > 0): ?>
                                                <div class="d-flex gap-1 flex-wrap mt-1">
                                                    <?php if ($row['incomplete_count'] > 0): ?>
                                                    <span class="badge dtr-chip bg-danger-subtle text-danger" title="Days with a missing time-in or time-out — fix before approving"><i class="ri-error-warning-line me-1"></i><?= number_format((int)$row['incomplete_count']) ?> incomplete</span>
                                                    <?php endif; ?>
                                                    <?php if ($row['manual_count'] > 0): ?>
                                                    <span class="badge dtr-chip bg-info-subtle text-info" title="Days entered or edited by hand, not from the device"><i class="ri-edit-line me-1"></i><?= number_format((int)$row['manual_count']) ?> manual</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="dtr-sub" title="Totals for the whole batch">
                                                    Late <?= dtr_hrs($row['late_hrs']) ?> &middot; UT <?= dtr_hrs($row['ut_hrs']) ?>
                                                    &middot; OT <?= dtr_hrs($row['ot_hrs']) ?> &middot; ND <?= dtr_hrs($row['nd_hrs']) ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?= dtr_status_badge($row['status']) ?>
                                                <?php if ($payrollRun):
                                                    [$plabel, $ptone] = $dtr_payroll_status[(int) $payrollRun['status']] ?? ['Unknown', 'secondary']; ?>
                                                <div class="dtr-payroll">
                                                    <i class="ri-money-dollar-circle-line text-muted"></i>
                                                    <a href="index.php?page=payroll_calculations&id=<?= (int) $payrollRun['id'] ?>" title="Open this period's payroll"><?= htmlspecialchars($payrollRun['ref_no']) ?></a>
                                                    <span class="text-<?= $ptone ?>">&middot; <?= $plabel ?></span>
                                                </div>
                                                <?php if ($status !== 2): ?>
                                                <span class="badge dtr-chip bg-warning-subtle text-warning mt-1" title="A payroll already exists for this period but the DTR isn't approved — its figures may still change"><i class="ri-alert-line me-1"></i>Payroll before approval</span>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['approve_by'])): ?>
                                                <div class="dtr-user"><i class="ri-shield-check-line me-1 text-muted"></i><?= htmlspecialchars($row['approve_by']) ?></div>
                                                <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm pr-actions" role="group" aria-label="DTR actions">
                                                    <a href="<?= $viewUrl ?>" class="btn pr-act pr-act--primary"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Open the DTR batch">
                                                        <i class="ri-eye-line"></i><span>View</span>
                                                    </a>
                                                    <?php // Employee sign-off is optional (DTR_EMPLOYEE_REVIEW_ENABLED, db_connect.php).
                                                          // Off: skip it and final-approve straight from Pending. ?>
                                                    <?php if ($status === 1 && DTR_EMPLOYEE_REVIEW_ENABLED): ?>
                                                    <button onclick="sendDTRForReview(<?= $row['id'] ?>)" type="button"
                                                        class="btn pr-act"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Send to employees for review">
                                                        <i class="ri-user-received-2-line"></i><span>Send for review</span>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($status === 3 || ($status === 1 && !DTR_EMPLOYEE_REVIEW_ENABLED)): ?>
                                                    <button onclick="approveDTR(<?= $row['id'] ?>)" type="button"
                                                        class="btn pr-act pr-act--ok"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Final approve for payroll">
                                                        <i class="ri-checkbox-circle-line"></i><span>Approve</span>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php // Biometric batches have no source file to re-import — never deletable. ?>
                                                    <?php if ($status !== 2 && $row['file'] !== 'biometric'): ?>
                                                    <button onclick="deleteDTR(<?= $row['id'] ?>)" type="button"
                                                        class="btn pr-act pr-act--icon pr-act--danger" aria-label="Delete DTR"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Delete DTR">
                                                        <i class="ri-delete-bin-line"></i>
                                                    </button>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });
});
</script>
