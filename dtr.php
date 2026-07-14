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
?>
<style>
    .dtr-period { font-weight:700; color:#009688; font-size:13px; font-family:'Segoe UI',monospace; }
    .dtr-period small { display:block; font-size:10px; color:#888; font-weight:400; font-family:inherit; margin-top:1px; }
    .dtr-site-code { background:#009688; color:#fff; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:700; display:inline-block; margin-bottom:3px; }
    .dtr-site-name { font-size:12px; font-weight:600; color:#222; }
    .dtr-site-addr { font-size:11px; color:#888; }
    .dtr-user { font-size:13px; font-weight:600; }
    .dtr-user small { font-size:10px; color:#888; font-weight:400; display:block; }
    .dtr-action { display:flex; gap:4px; justify-content:center; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-time-line me-2" style="color:#009688;"></i>Daily Time Record</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Daily Time Record</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card" style="border-top:3px solid #009688;">
                    <div class="card-header align-items-center d-flex flex-wrap gap-2 py-2">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-time-line me-2" style="color:#009688;"></i>DTR List
                        </h4>
                        <button type="button" id="btn-bulk-send-dtr" class="btn btn-sm btn-info" onclick="bulkSendDTRForReview()" disabled>
                            <i class="ri-user-received-2-line me-1"></i>Send Selected for Review (<span id="dtr-bulk-count">0</span>)
                        </button>
                    </div>

                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="data-table" class="table table-hover table-bordered dt-responsive nowrap align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width:34px;" class="text-center" data-orderable="false">
                                            <input type="checkbox" id="dtr-check-all" title="Select all reviewable">
                                        </th>
                                        <th><i class="ri-calendar-range-line me-1"></i>Period</th>
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
                                        SUM(CASE WHEN DTR_details.status = 2 THEN 1 ELSE 0 END) AS disapproved_count
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
                                        ORDER BY DTR.id DESC");
                                    while ($row = $query->fetch_assoc()):
                                        $status = (int)$row['status'];
                                        $period = date("M d", strtotime($row['date_from'])) . ' &ndash; ' . date("M j, Y", strtotime($row['date_to']));
                                        $viewUrl = "index.php?page=dtr-details&id=" . base64_encode($row['id'])
                                            . "&timekeeper_name=" . base64_encode($row['timekeeper_name'])
                                            . "&device_id=" . base64_encode($row['device_id'])
                                            . "&site_id=" . base64_encode($row['site_id'])
                                            . "&status=" . base64_encode($row['status']);
                                    ?>
                                        <tr>
                                            <td class="text-center">
                                                <?php if ($status === 1): ?>
                                                    <input type="checkbox" class="dtr-bulk-check" value="<?= (int)$row['id'] ?>">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="dtr-period">
                                                    <i class="ri-calendar-2-line me-1 text-muted"></i><?= $period ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="dtr-site-code"><?= htmlspecialchars($row['site_code']) ?></span>
                                                <div class="dtr-site-name"><?= htmlspecialchars($row['site_name']) ?></div>
                                                <div class="dtr-site-addr"><?= htmlspecialchars($row['site_address']) ?></div>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-1 flex-wrap">
                                                    <span class="badge bg-warning text-dark"><?= (int)$row['pending_count'] ?> Pending</span>
                                                    <span class="badge bg-success"><?= (int)$row['approved_count'] ?> Approved</span>
                                                    <span class="badge bg-danger"><?= (int)$row['disapproved_count'] ?> Disapproved</span>
                                                </div>
                                            </td>
                                            <td class="text-center"><?= dtr_status_badge($row['status']) ?></td>
                                            <td>
                                                <?php if (!empty($row['approve_by'])): ?>
                                                <div class="dtr-user"><i class="ri-shield-check-line me-1 text-muted"></i><?= htmlspecialchars($row['approve_by']) ?></div>
                                                <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="dtr-action">
                                                    <a href="<?= $viewUrl ?>" class="btn btn-sm btn-outline-success"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="View DTR Details">
                                                        <i class="ri-eye-line"></i>
                                                    </a>
                                                    <?php if ($status === 1): ?>
                                                    <button onclick="sendDTRForReview(<?= $row['id'] ?>)" type="button"
                                                        class="btn btn-sm btn-outline-info"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Send to Employees for Review">
                                                        <i class="ri-user-received-2-line"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($status === 3): ?>
                                                    <button onclick="approveDTR(<?= $row['id'] ?>)" type="button"
                                                        class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Final Approve for Payroll">
                                                        <i class="ri-checkbox-circle-line"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php // Biometric batches have no source file to re-import — never deletable. ?>
                                                    <?php if ($status !== 2 && $row['file'] !== 'biometric'): ?>
                                                    <button onclick="deleteDTR(<?= $row['id'] ?>)" type="button"
                                                        class="btn btn-sm btn-outline-danger"
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
