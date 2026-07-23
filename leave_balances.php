<?php
// Editable by Admin (1), Department Head (8), HR (9).
$can_edit_credits = in_array((int)($_SESSION['login_role'] ?? 0), [1, 8, 9]);
$can_rollover     = in_array((int)($_SESSION['login_role'] ?? 0), [1, 9]);   // Admin + HR
$sel_emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$leave_year = leave_current_year();   // credits are tracked per calendar year

// Department Heads only see employees in their own department.
require_once 'dept-scope.php';

// Resolve selected employee (if any)
$sel_emp_row = null;
if ($sel_emp > 0) {
    $eq = $conn->query("
        SELECT e.id, e.employee_no, e.firstname, e.lastname, COALESCE(d.name,'—') AS dept,
               UPPER(COALESCE(cl.clasification,'')) AS clasif, e.leave_override
        FROM employee e
        LEFT JOIN department d ON d.id = e.department_id
        LEFT JOIN clasification cl ON cl.id = e.clasification_id
        WHERE e.id = " . $sel_emp . dept_scope_sql('e.department_id'));
    $sel_emp_row = $eq ? $eq->fetch_assoc() : null;
    if (!$sel_emp_row) $sel_emp = 0;   // outside scope (or missing) — treat as no selection
}
// Eligibility: per-employee override first, else classification (Regular/Executive).
$emp_leave_eligible = $sel_emp_row && leave_eligibility_from($sel_emp_row['clasif'], $sel_emp_row['leave_override']);
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
                                        $emps = $conn->query("SELECT id, employee_no, firstname, lastname FROM employee WHERE status = 1" . dept_scope_sql('department_id') . " ORDER BY lastname ASC");
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

                <?php if ($can_rollover): ?>
                <!-- Year-End Rollover -->
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-body d-flex flex-wrap align-items-end gap-2">
                            <div class="flex-grow-1">
                                <b><i class="ri-calendar-todo-line me-1 text-success"></i>Year-End Rollover</b>
                                <div class="text-muted" style="font-size:12px;">Carry over or reset every eligible employee's credits into the next year, per each leave type's policy.</div>
                            </div>
                            <div>
                                <label class="form-label mb-1" style="font-size:11px;font-weight:700;color:#009688;">From year</label>
                                <select id="rollover-from" class="form-select form-select-sm" style="width:120px;">
                                    <?php $cy = (int)date('Y'); for ($y = $cy + 1; $y >= $cy - 3; $y--): ?>
                                        <option value="<?= $y ?>" <?= $y === $cy ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="align-self-center text-muted pb-1"><i class="ri-arrow-right-line"></i></div>
                            <div>
                                <label class="form-label mb-1" style="font-size:11px;font-weight:700;color:#009688;">To year</label>
                                <input id="rollover-to" class="form-control form-control-sm text-center" style="width:100px;" readonly value="<?= (int)date('Y') + 1 ?>">
                            </div>
                            <button id="rollover-preview" class="btn btn-sm btn-outline-success"><i class="ri-eye-line me-1"></i>Preview</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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

                <?php if ($can_edit_credits): ?>
                <!-- Leave eligibility override -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-body d-flex flex-wrap align-items-center gap-2">
                            <div class="flex-grow-1">
                                <b><i class="ri-shield-user-line me-1 text-success"></i>Leave Eligibility</b>
                                <div class="text-muted" style="font-size:12px;">
                                    Currently <b class="<?= $emp_leave_eligible ? 'text-success' : 'text-danger' ?>"><?= $emp_leave_eligible ? 'Eligible' : 'Not eligible' ?></b>
                                    · Classification: <?= htmlspecialchars(ucfirst(strtolower($sel_emp_row['clasif'] ?: 'unclassified'))) ?>
                                    <?php if ($sel_emp_row['leave_override'] !== null): ?>
                                        · <span class="badge bg-info-subtle text-info">Overridden</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php $ov = $sel_emp_row['leave_override']; ?>
                            <select id="leave-override" class="form-select form-select-sm" style="max-width:240px;">
                                <option value=""  <?= ($ov === null) ? 'selected' : '' ?>>Auto (by classification)</option>
                                <option value="1" <?= ($ov !== null && (int)$ov === 1) ? 'selected' : '' ?>>Always allowed (override)</option>
                                <option value="0" <?= ($ov !== null && (int)$ov === 0) ? 'selected' : '' ?>>Always blocked (override)</option>
                            </select>
                            <button id="leave-override-save" class="btn btn-sm btn-success" data-employee="<?= $sel_emp ?>"><i class="ri-save-line me-1"></i>Apply</button>
                        </div>
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
                                        LEFT JOIN employee_leave_credits c ON c.leave_type_id = lt.id AND c.employee_id = " . $sel_emp . " AND c.year = " . $leave_year . "
                                        LEFT JOIN (
                                            SELECT leave_type_id, SUM(duration) AS used
                                            FROM leave_requests WHERE employee_id = " . $sel_emp . " AND status = 1 AND YEAR(date_from) = " . $leave_year . " GROUP BY leave_type_id
                                        ) u ON u.leave_type_id = lt.id
                                        WHERE lt.status = 1 AND lt.is_paid = 1 ORDER BY lt.name ASC
                                    ");
                                    $fmt = function ($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.'); };
                                    if ($cr) while ($c = $cr->fetch_assoc()):
                                        $avail = (float)$c['credits']; $used = (float)$c['used']; $rem = $avail - $used;
                                    ?>
                                    <tr>
                                        <td><b><i class="ri-calendar-event-line me-1 text-success"></i><?= htmlspecialchars($c['name']) ?></b></td>
                                        <td class="text-center">
                                            <?php if ($can_edit_credits && $emp_leave_eligible): ?>
                                                <div class="input-group input-group-sm" style="max-width:230px;margin:0 auto;">
                                                    <input type="number" min="0" step="0.5" class="form-control text-center leave-credit-input"
                                                        value="<?= $fmt($avail) ?>" data-employee="<?= $sel_emp ?>" data-type="<?= $c['id'] ?>">
                                                    <button class="btn btn-success leave-credit-save" type="button" title="Set balance"><i class="ri-save-line"></i></button>
                                                    <button class="btn btn-outline-success leave-credit-adjust" type="button" data-mode="add"
                                                        data-employee="<?= $sel_emp ?>" data-type="<?= $c['id'] ?>" data-type-name="<?= htmlspecialchars($c['name']) ?>" title="Add credits"><i class="ri-add-line"></i></button>
                                                    <button class="btn btn-outline-danger leave-credit-adjust" type="button" data-mode="deduct"
                                                        data-employee="<?= $sel_emp ?>" data-type="<?= $c['id'] ?>" data-type-name="<?= htmlspecialchars($c['name']) ?>" title="Deduct credits"><i class="ri-subtract-line"></i></button>
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
                                    <?php
                                    $ctMap = ['set' => ['Set', 'bg-secondary-subtle text-secondary'], 'add' => ['Add', 'bg-success-subtle text-success'], 'deduct' => ['Deduct', 'bg-danger-subtle text-danger']];
                                    while ($h = $hist->fetch_assoc()):
                                        $up = (float)$h['new_credits'] >= (float)$h['old_credits'];
                                        $ct = $h['change_type'] ?? 'set';
                                        [$ctLabel, $ctClass] = $ctMap[$ct] ?? $ctMap['set'];
                                        $delta = (float)$h['new_credits'] - (float)$h['old_credits'];
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y g:i A', strtotime($h['created_at'])) ?></td>
                                        <td>
                                            <?= htmlspecialchars($h['type_name']) ?>
                                            <?php if (!empty($h['reason'])): ?>
                                                <div class="text-muted" style="font-size:10px;" title="<?= htmlspecialchars($h['reason']) ?>"><i class="ri-chat-1-line"></i> <?= htmlspecialchars(mb_strimwidth($h['reason'], 0, 40, '…')) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $ctClass ?>" style="font-size:9px;"><?= $ctLabel ?><?= $ct !== 'set' ? ' ' . ($delta >= 0 ? '+' : '') . $fmt($delta) : '' ?></span>
                                            <div>
                                                <span class="text-muted"><?= $fmt($h['old_credits']) ?></span>
                                                <i class="ri-arrow-right-line <?= $up ? 'text-success' : 'text-danger' ?>"></i>
                                                <b class="<?= $up ? 'text-success' : 'text-danger' ?>"><?= $fmt($h['new_credits']) ?></b>
                                            </div>
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
                                            <th>Reason</th>
                                            <?php foreach (leave_stages() as $sdef): ?>
                                            <th class="text-center"><?= htmlspecialchars($sdef['label']) ?></th>
                                            <?php endforeach; ?>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $rq = $conn->query("
                                            SELECT lr.*, lt.name AS type_name, su.name AS sup_name, hu.name AS hr_name, au.name AS admin_name
                                            FROM leave_requests lr
                                            INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
                                            LEFT JOIN users su ON su.id = lr.sup_by
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
                                            <?php foreach (leave_stages() as $skey => $sdef): ?>
                                            <td class="text-center" title="<?= htmlspecialchars($sdef['label'] . (!empty($r[$skey . '_name']) ? ' · ' . $r[$skey . '_name'] : '')) ?>"><?= $stage($r[$skey . '_status']) ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-center"><span class="badge <?= $sclass ?> rounded-pill"><?= $slabel ?></span></td>
                                        </tr>
                                        <?php endwhile; ?>
                                        <?php if (!$rq || !$rq->num_rows): ?>
                                        <tr><td colspan="<?= 5 + count(leave_stages()) ?>" class="text-center text-muted py-3">No leave requests for this employee.</td></tr>
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

<!-- Year-End Rollover Preview Modal -->
<div class="modal fade" id="modal-rollover" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="ri-calendar-todo-line me-2" style="color:#009688;"></i>Year-End Rollover Preview</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="rollover-summary" class="alert alert-info py-2 mb-2" style="font-size:13px;"></div>
                <div class="table-responsive" style="max-height:50vh;overflow:auto;">
                    <table class="table table-sm table-bordered align-middle mb-0" style="font-size:12px;">
                        <thead class="table-light" style="position:sticky;top:0;">
                            <tr><th>Employee</th><th>Leave Type</th><th class="text-center">Policy</th><th class="text-center">Leftover</th><th class="text-center">Carried</th><th class="text-center">New Balance</th></tr>
                        </thead>
                        <tbody id="rollover-rows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-success" id="rollover-run"><i class="ri-check-double-line me-1"></i>Run Rollover</button>
            </div>
        </div>
    </div>
</div>

<!-- Add / Deduct Credit Modal -->
<div class="modal fade" id="modal-adjust-credit" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="form-adjust-credit" class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="adjust-title"><i class="ri-coins-line me-2" style="color:#009688;"></i>Adjust Credits</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="adj-employee">
                <input type="hidden" id="adj-type">
                <input type="hidden" id="adj-mode">
                <p class="mb-3 text-muted" id="adj-desc" style="font-size:13px;"></p>
                <div class="mb-3">
                    <label class="form-label">Amount (days) <span class="text-danger">*</span></label>
                    <input type="number" min="0.5" step="0.5" class="form-control" id="adj-amount" placeholder="e.g. 2" required>
                </div>
                <div class="mb-1">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="adj-reason" rows="2" placeholder="Why this adjustment? (e.g. carry-over, correction)" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-success" id="adj-submit"><i class="ri-check-line me-1"></i>Apply</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#emp-select').select2({ placeholder: 'Search employee…', width: '100%' });
    }
});

// Save the per-employee leave eligibility override.
document.getElementById('leave-override-save')?.addEventListener('click', function () {
    const btn = this;
    const body = new URLSearchParams({
        employee_id: btn.dataset.employee,
        override: document.getElementById('leave-override').value
    });
    btn.disabled = true;
    fetch('ajax.php?action=save_leave_override', { method: 'POST', body: body })
        .then(r => r.json())
        .then(j => {
            btn.disabled = false;
            if (j && j.result) {
                Swal.fire({ icon: 'success', title: 'Saved', text: j.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to save.' });
            }
        })
        .catch(() => { btn.disabled = false; });
});

// Open the Add/Deduct modal prefilled for the chosen row + mode.
document.addEventListener('click', function (e) {
    const b = e.target.closest('.leave-credit-adjust');
    if (!b) return;
    const mode = b.dataset.mode;
    document.getElementById('adj-employee').value = b.dataset.employee;
    document.getElementById('adj-type').value = b.dataset.type;
    document.getElementById('adj-mode').value = mode;
    document.getElementById('adj-amount').value = '';
    document.getElementById('adj-reason').value = '';
    const isAdd = mode === 'add';
    document.getElementById('adjust-title').innerHTML =
        '<i class="ri-coins-line me-2" style="color:#009688;"></i>' + (isAdd ? 'Add' : 'Deduct') + ' Credits';
    document.getElementById('adj-desc').innerHTML =
        (isAdd ? 'Add days to' : 'Deduct days from') + ' <b>' + b.dataset.typeName + '</b> balance.';
    document.getElementById('adj-submit').className = 'btn btn-sm ' + (isAdd ? 'btn-success' : 'btn-danger');
    new bootstrap.Modal(document.getElementById('modal-adjust-credit')).show();
});

// Submit an Add/Deduct adjustment.
document.getElementById('form-adjust-credit').addEventListener('submit', function (e) {
    e.preventDefault();
    const submit = document.getElementById('adj-submit');
    const body = new URLSearchParams({
        employee_id: document.getElementById('adj-employee').value,
        leave_type_id: document.getElementById('adj-type').value,
        mode: document.getElementById('adj-mode').value,
        amount: document.getElementById('adj-amount').value,
        reason: document.getElementById('adj-reason').value
    });
    submit.disabled = true;
    fetch('ajax.php?action=save_leave_credit', { method: 'POST', body: body })
        .then(r => r.json())
        .then(j => {
            submit.disabled = false;
            if (j && j.result) {
                bootstrap.Modal.getInstance(document.getElementById('modal-adjust-credit')).hide();
                Swal.fire({ icon: 'success', title: 'Done', text: j.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to apply.' });
            }
        })
        .catch(() => { submit.disabled = false; });
});

// ── Year-End Rollover ────────────────────────────────────────────────────────
(function () {
    const fromSel = document.getElementById('rollover-from');
    if (!fromSel) return;   // not admin/HR
    const toInp = document.getElementById('rollover-to');
    const fmt = n => (Math.round(n * 10) / 10).toString();

    fromSel.addEventListener('change', function () { toInp.value = (parseInt(this.value, 10) + 1); });

    document.getElementById('rollover-preview').addEventListener('click', function () {
        const btn = this;
        const from = fromSel.value, to = toInp.value;
        btn.disabled = true;
        fetch('ajax.php?action=run_leave_rollover', { method: 'POST', body: new URLSearchParams({ mode: 'preview', from_year: from, to_year: to }) })
            .then(r => r.json())
            .then(j => {
                btn.disabled = false;
                if (!j || !j.result) { Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Preview failed.' }); return; }
                const rows = j.rows || [];
                document.getElementById('rollover-summary').innerHTML =
                    '<b>' + j.employees + '</b> eligible employee(s) · ' + from + ' → ' + to +
                    (j.truncated ? ' · showing first 500 rows' : '') +
                    '<div style="font-size:11px;">Review below, then click <b>Run Rollover</b> to apply.</div>';
                document.getElementById('rollover-rows').innerHTML = rows.length ? rows.map(function (r) {
                    const policy = r.policy === 'carry'
                        ? '<span class="badge bg-info-subtle text-info">Carry</span>'
                        : '<span class="badge bg-secondary-subtle text-secondary">Reset</span>';
                    return '<tr><td>' + r.employee + '</td><td>' + r.type + '</td><td class="text-center">' + policy +
                        '</td><td class="text-center">' + fmt(r.leftover) + '</td><td class="text-center">' + fmt(r.carried) +
                        '</td><td class="text-center"><b>' + fmt(r['new']) + '</b> <span class="text-muted" style="font-size:10px;">(was ' + fmt(r.old) + ')</span></td></tr>';
                }).join('') : '<tr><td colspan="6" class="text-center text-muted py-3">No eligible employees / paid leave types.</td></tr>';
                document.getElementById('rollover-run').dataset.from = from;
                document.getElementById('rollover-run').dataset.to = to;
                new bootstrap.Modal(document.getElementById('modal-rollover')).show();
            })
            .catch(() => { btn.disabled = false; });
    });

    document.getElementById('rollover-run').addEventListener('click', async function () {
        const btn = this;
        const c = await Swal.fire({
            title: 'Run year-end rollover?',
            html: 'This will set every eligible employee\'s <b>' + btn.dataset.to + '</b> starting balance. Changes are logged and can be adjusted afterwards.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Run Rollover', confirmButtonColor: '#198754'
        });
        if (!c.isConfirmed) return;
        btn.disabled = true;
        fetch('ajax.php?action=run_leave_rollover', { method: 'POST', body: new URLSearchParams({ mode: 'run', from_year: btn.dataset.from, to_year: btn.dataset.to }) })
            .then(r => r.json())
            .then(j => {
                btn.disabled = false;
                if (j && j.result) {
                    bootstrap.Modal.getInstance(document.getElementById('modal-rollover')).hide();
                    Swal.fire({ icon: 'success', title: 'Rollover complete', text: j.message, timer: 2000, showConfirmButton: false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Rollover failed.' });
                }
            })
            .catch(() => { btn.disabled = false; });
    });
})();

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
