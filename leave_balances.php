<?php
// Editable by Admin (1), Department Head (8), HR (9).
$can_edit_credits = in_array((int)($_SESSION['login_role'] ?? 0), [1, 8, 9]);
$sel_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;

// Resolve selected employee (if any)
$sel_emp_row = null;
if ($sel_emp > 0) {
    $eq = $conn->query("
        SELECT e.id, e.employee_no, e.firstname, e.lastname, COALESCE(d.name,'—') AS dept,
               UPPER(COALESCE(cl.clasification,'')) AS clasif
        FROM employee e
        LEFT JOIN department d ON d.id = e.department_id
        LEFT JOIN clasification cl ON cl.id = e.clasification_id
        WHERE e.id = " . $sel_emp);
    $sel_emp_row = $eq ? $eq->fetch_assoc() : null;
}
// Only Regular / Executive employees are entitled to leave credits.
$emp_leave_eligible = $sel_emp_row && in_array($sel_emp_row['clasif'], LEAVE_ELIGIBLE_CLASSIFICATIONS, true);
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Leave Balances</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Leave Management</a></li>
                                <li class="breadcrumb-item active">Leave Balances</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Employee picker -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <form method="get" action="index.php" class="row g-2 align-items-end">
                                <input type="hidden" name="page" value="leave_balances">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#009688;">
                                        <i class="ri-user-search-line me-1"></i>Select Employee
                                    </label>
                                    <select name="emp" id="emp-select" class="form-control" data-live-search="true" required>
                                        <option value="">Search employee…</option>
                                        <?php
                                        $emps = $conn->query("SELECT id, employee_no, firstname, lastname FROM employee WHERE status = 1 ORDER BY lastname ASC");
                                        if ($emps) while ($e = $emps->fetch_assoc()):
                                        ?>
                                            <option value="<?= $e['id'] ?>" <?= $sel_emp == $e['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($e['lastname'] . ', ' . $e['firstname']) ?> (<?= htmlspecialchars($e['employee_no']) ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-success w-100"><i class="ri-search-line me-1"></i>View Balances</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if ($sel_emp_row): ?>
                <!-- Selected employee header -->
                <div class="col-12">
                    <div class="alert alert-success d-flex align-items-center" role="alert">
                        <i class="ri-user-3-line fs-22 me-2"></i>
                        <div>
                            <b><?= htmlspecialchars($sel_emp_row['lastname'] . ', ' . $sel_emp_row['firstname']) ?></b>
                            <span class="text-muted">&middot; <?= htmlspecialchars($sel_emp_row['dept']) ?> &middot; #<?= htmlspecialchars($sel_emp_row['employee_no']) ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!$emp_leave_eligible): ?>
                <div class="col-12">
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="ri-information-line fs-22 me-2"></i>
                        <div>This employee is <b><?= htmlspecialchars(ucfirst(strtolower($sel_emp_row['clasif'] ?: 'unclassified'))) ?></b>.
                        Only <b>Regular</b> and <b>Executive</b> employees are entitled to leave credits.</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Balances editor -->
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-coins-line me-2 text-success"></i>Leave Balances</h5>
                            <?php if (!$can_edit_credits): ?><span class="badge bg-secondary-subtle text-secondary">View only</span>
                            <?php elseif (!$emp_leave_eligible): ?><span class="badge bg-warning-subtle text-warning">Not eligible</span><?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Leave Type</th>
                                        <th class="text-center" style="width:160px;">Available</th>
                                        <th class="text-center" style="width:80px;">Used</th>
                                        <th class="text-center" style="width:110px;">Remaining</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cr = $conn->query("
                                        SELECT lt.id, lt.name, lt.days_allowed,
                                            COALESCE(c.credits, lt.days_allowed) AS credits,
                                            COALESCE(u.used, 0) AS used
                                        FROM leave_types lt
                                        LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = " . $sel_emp . "
                                        LEFT JOIN (
                                            SELECT leave_type_id, SUM(duration) AS used
                                            FROM leave_requests WHERE employee_id = " . $sel_emp . " AND status = 1 GROUP BY leave_type_id
                                        ) u ON u.leave_type_id = lt.id
                                        WHERE lt.status = 1 ORDER BY lt.name ASC
                                    ");
                                    $fmt = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
                                    if ($cr) while ($c = $cr->fetch_assoc()):
                                        $avail = (float)$c['credits']; $used = (float)$c['used']; $rem = $avail - $used;
                                    ?>
                                    <tr>
                                        <td><b><i class="ri-calendar-event-line me-1 text-success"></i><?= htmlspecialchars($c['name']) ?></b></td>
                                        <td class="text-center">
                                            <?php if ($can_edit_credits && $emp_leave_eligible): ?>
                                                <div class="input-group input-group-sm" style="max-width:150px;margin:0 auto;">
                                                    <input type="number" min="0" step="0.5" class="form-control text-center leave-credit-input"
                                                        value="<?= $fmt($avail) ?>" data-employee="<?= $sel_emp ?>" data-type="<?= $c['id'] ?>">
                                                    <button class="btn btn-success leave-credit-save" type="button" title="Save"><i class="ri-save-line"></i></button>
                                                </div>
                                            <?php else: ?>
                                                <?= $emp_leave_eligible ? $fmt($avail) : '<span class="text-muted">—</span>' ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge bg-warning-subtle text-warning"><?= $fmt($used) ?></span></td>
                                        <td class="text-center"><span class="badge <?= $rem <= 0 ? 'bg-danger' : 'bg-success' ?> rounded-pill"><?= $fmt($rem) ?></span></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Balance change history -->
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="ri-history-line me-2 text-success"></i>Balance Change History</h5></div>
                        <div class="card-body p-0" style="max-height:360px;overflow-y:auto;">
                            <?php
                            $hist = $conn->query("
                                SELECT h.*, lt.name AS type_name, u.name AS changed_name
                                FROM leave_credit_history h
                                INNER JOIN leave_types lt ON lt.id = h.leave_type_id
                                LEFT JOIN users u ON u.id = h.changed_by
                                WHERE h.employee_id = " . $sel_emp . "
                                ORDER BY h.created_at DESC LIMIT 50
                            ");
                            if ($hist && $hist->num_rows): ?>
                            <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                                <thead class="table-light"><tr><th>When</th><th>Type</th><th class="text-center">Change</th><th>By</th></tr></thead>
                                <tbody>
                                    <?php while ($h = $hist->fetch_assoc()):
                                        $up = (float)$h['new_credits'] >= (float)$h['old_credits'];
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($h['type_name']) ?></td>
                                        <td class="text-center">
                                            <span class="text-muted"><?= $fmt($h['old_credits']) ?></span>
                                            <i class="ri-arrow-right-line <?= $up ? 'text-success' : 'text-danger' ?>"></i>
                                            <b class="<?= $up ? 'text-success' : 'text-danger' ?>"><?= $fmt($h['new_credits']) ?></b>
                                        </td>
                                        <td><?= htmlspecialchars($h['changed_name'] ?? 'System') ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="text-center text-muted p-4"><i class="ri-history-line fs-22 d-block mb-1"></i>No balance changes recorded.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Request history -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="ri-file-list-3-line me-2 text-success"></i>Leave Request History</h5></div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered align-middle mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Date Applied</th><th>Type</th><th class="text-center">Duration</th>
                                            <th>Reason</th><th class="text-center">HR</th><th class="text-center">Final</th><th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $rq = $conn->query("
                                            SELECT lr.*, lt.name AS type_name, hu.name AS hr_name, au.name AS admin_name
                                            FROM leave_requests lr
                                            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                                            LEFT JOIN users hu ON hu.id = lr.hr_by
                                            LEFT JOIN users au ON au.id = lr.admin_by
                                            WHERE lr.employee_id = " . $sel_emp . "
                                            ORDER BY lr.date_applied DESC, lr.id DESC
                                        ");
                                        $stMap = [0 => ['Pending','bg-warning'], 1 => ['Approved','bg-success'], 2 => ['Rejected','bg-danger']];
                                        $stage = function ($s) {
                                            if ($s == 1) return '<span class="badge bg-success-subtle text-success"><i class="ri-check-line"></i></span>';
                                            if ($s == 2) return '<span class="badge bg-danger-subtle text-danger"><i class="ri-close-line"></i></span>';
                                            return '<span class="badge bg-warning-subtle text-warning"><i class="ri-time-line"></i></span>';
                                        };
                                        if ($rq && $rq->num_rows) while ($r = $rq->fetch_assoc()):
                                            [$slabel, $sclass] = $stMap[$r['status']] ?? ['Unknown','bg-secondary'];
                                        ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($r['date_applied'])) ?></td>
                                            <td><span class="badge bg-info-subtle text-info"><?= htmlspecialchars($r['type_name']) ?></span></td>
                                            <td class="text-center"><b><?= $fmt($r['duration']) ?></b> day(s)<div class="text-muted" style="font-size:11px;"><?= date('M d', strtotime($r['date_from'])) ?> – <?= date('M d', strtotime($r['date_to'])) ?></div></td>
                                            <td style="max-width:220px;"><span class="text-muted"><?= nl2br(htmlspecialchars($r['reason'] ?? '')) ?></span></td>
                                            <td class="text-center"><?= $stage($r['hr_status']) ?></td>
                                            <td class="text-center"><?= $stage($r['admin_status']) ?></td>
                                            <td class="text-center"><span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span></td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$rq || !$rq->num_rows): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No leave requests for this employee.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <div class="col-12">
                    <div class="card"><div class="card-body text-center text-muted py-5">
                        <i class="ri-user-search-line" style="font-size:40px;"></i>
                        <p class="mt-2 mb-0">Select an employee above to view and manage their leave balances.</p>
                    </div></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#emp-select').select2({ placeholder: 'Search employee…', width: '100%' });
    }
});

// Save balance (delegated; reloads to refresh remaining + history)
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.leave-credit-save');
    if (!btn) return;
    const input = btn.closest('.input-group').querySelector('.leave-credit-input');
    const body = new URLSearchParams({
        employee_id: input.dataset.employee,
        leave_type_id: input.dataset.type,
        credits: input.value
    });
    btn.disabled = true;
    fetch('ajax.php?action=save_leave_credit', { method: 'POST', body: body })
        .then(r => r.json())
        .then(j => {
            btn.disabled = false;
            if (j && j.result) {
                Swal.fire({ icon: 'success', title: 'Saved', text: j.message, timer: 1000, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to save.' });
            }
        })
        .catch(() => { btn.disabled = false; });
});
</script>
