<?php
// Active Loans Dashboard — included via index.php
$loan_stats = $conn->query("
    SELECT
        COUNT(DISTINCT l.employee_id)          AS borrowers,
        COALESCE(SUM(l.loan_balance), 0)       AS total_balance,
        COALESCE(SUM(l.loan_amount), 0)        AS total_loaned,
        COALESCE(SUM(l.damount), 0)            AS total_monthly
    FROM loans l
    WHERE l.loan_status = 0 AND l.loan_balance > 0
")->fetch_assoc();

$loans_res = $conn->query("
    SELECT
        l.*,
        CONCAT(e.lastname, ', ', e.firstname) AS emp_name,
        e.employee_no,
        COALESCE(d.name, '—')                 AS dept_name,
        COALESCE(p.name, '—')                 AS position_name,
        COALESCE(clt.loan_type, '—')          AS loan_type_name,
        (l.loan_amount - l.loan_balance)       AS amount_paid,
        ROUND((l.loan_amount - l.loan_balance) / NULLIF(l.loan_amount,0) * 100, 1) AS pct_paid
    FROM loans l
    INNER JOIN employee e ON l.employee_id = e.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN position   p ON e.position_id  = p.id
    LEFT JOIN contribution_loan_types clt ON l.loan_type = clt.clt_id
    WHERE l.loan_status = 0 AND l.loan_balance > 0
    ORDER BY l.loan_balance DESC
");

// Loan type master list with usage counts (all loans, active or paid).
// Add + rename only in the UI for now; the delete_loan_type endpoint exists
// (refuses types still referenced by a loan) but has no button here yet.
$loan_types_res = $conn->query("
    SELECT clt.clt_id, clt.loan_type, COUNT(l.loan_id) AS in_use
    FROM contribution_loan_types clt
    LEFT JOIN loans l ON l.loan_type = clt.clt_id
    GROUP BY clt.clt_id, clt.loan_type
    ORDER BY clt.loan_type ASC
");
$loan_types_editable = function_exists('can_edit') ? can_edit('loans') : true;
?>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Title -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0"><i class="ri-bank-line me-2" style="color:#6642aa;"></i>Active Loans</h4>
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                            <li class="breadcrumb-item active">Active Loans</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #6642aa;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#f0ecf6;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-group-line" style="color:#6642aa;"></i></div>
                            <div>
                                <div style="font-size:22px;font-weight:800;color:#6642aa;"><?= number_format($loan_stats['borrowers']) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Borrowers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #e83e8c;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#fdf0f6;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-money-dollar-circle-line" style="color:#e83e8c;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#e83e8c;">₱<?= number_format($loan_stats['total_balance'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Total Outstanding</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #6f42c1;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#f2eefb;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-wallet-3-line" style="color:#6f42c1;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#6f42c1;">₱<?= number_format($loan_stats['total_loaned'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Total Loaned</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card" style="border-top:3px solid #fd7e14;">
                        <div class="card-body d-flex align-items-center gap-3 py-3">
                            <div style="width:46px;height:46px;border-radius:10px;background:#fff4ec;display:flex;align-items:center;justify-content:center;font-size:22px;"><i class="ri-calendar-line" style="color:#fd7e14;"></i></div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#fd7e14;">₱<?= number_format($loan_stats['total_monthly'], 2) ?></div>
                                <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.4px;">Deducted / Period</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loan Types (master list behind the Add Loan dropdown) -->
            <div class="card" id="loan-types-card">
                <div class="card-header d-flex align-items-center py-2">
                    <h6 class="card-title mb-0 flex-grow-1"><i class="ri-price-tag-3-line me-2" style="color:#6642aa;"></i>Loan Types</h6>
                    <?php if ($loan_types_editable): ?>
                        <button type="button" class="btn btn-sm" style="background:#6642aa;color:#fff;" onclick="loanTypeOpen()">
                            <i class="ri-add-line align-bottom me-1"></i>Add Loan Type
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body py-2">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="loan-types-table">
                            <thead>
                                <tr>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Loan Type</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;width:140px;">Loans Using It</th>
                                    <?php if ($loan_types_editable): ?>
                                        <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;width:100px;">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($loan_types_res->num_rows === 0): ?>
                                    <tr><td colspan="3" class="text-center text-muted" style="padding:14px;font-size:12px;">No loan types yet. Add one so it appears in the Add Loan dropdown.</td></tr>
                                <?php endif; ?>
                                <?php while ($t = $loan_types_res->fetch_assoc()): $inUse = (int) $t['in_use']; ?>
                                    <tr>
                                        <td style="padding:8px 12px;">
                                            <span style="background:#f0ecf6;color:#4e3483;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;"><?= htmlspecialchars($t['loan_type']) ?></span>
                                        </td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;color:<?= $inUse ? '#4e3483' : '#aaa' ?>;font-weight:<?= $inUse ? '700' : '400' ?>;"><?= number_format($inUse) ?></td>
                                        <?php if ($loan_types_editable): ?>
                                            <td style="padding:6px 12px;text-align:right;white-space:nowrap;">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loanTypeOpen(<?= (int) $t['clt_id'] ?>, this)" data-name="<?= htmlspecialchars($t['loan_type'], ENT_QUOTES) ?>">Edit</button>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header d-flex align-items-center py-2">
                    <h6 class="card-title mb-0 flex-grow-1"><i class="ri-list-check me-2" style="color:#6642aa;"></i>Loan Records</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0" id="loans-table">
                            <thead>
                                <tr>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Employee</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Department</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Loan Type</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Loan Amount</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Paid</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Balance</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;text-align:right;">Per Period</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Progress</th>
                                    <th style="background:#6642aa;color:#fff;padding:9px 12px;font-size:11px;border:none;">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($l = $loans_res->fetch_assoc()): $pct = max(0, min(100, (float)$l['pct_paid'])); ?>
                                    <tr>
                                        <td style="padding:8px 12px;">
                                            <div style="font-weight:700;font-size:12px;"><?= htmlspecialchars($l['emp_name']) ?></div>
                                            <div style="font-size:10px;color:#aaa;font-family:monospace;"><?= htmlspecialchars($l['employee_no']) ?></div>
                                        </td>
                                        <td style="padding:8px 12px;font-size:12px;"><?= htmlspecialchars($l['dept_name']) ?></td>
                                        <td style="padding:8px 12px;">
                                            <span style="background:#f0ecf6;color:#4e3483;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;"><?= htmlspecialchars($l['loan_type_name']) ?></span>
                                        </td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;">₱<?= number_format($l['loan_amount'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;color:#28a745;">₱<?= number_format($l['amount_paid'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;font-weight:800;color:#e83e8c;">₱<?= number_format($l['loan_balance'], 2) ?></td>
                                        <td style="padding:8px 12px;text-align:right;font-size:12px;">₱<?= number_format($l['damount'], 2) ?></td>
                                        <td style="padding:8px 12px;min-width:120px;">
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <div style="flex:1;height:6px;border-radius:3px;background:#eee;overflow:hidden;">
                                                    <div style="width:<?= $pct ?>%;height:100%;border-radius:3px;background:linear-gradient(90deg,#6642aa,#4e3483);"></div>
                                                </div>
                                                <span style="font-size:10px;color:#888;white-space:nowrap;"><?= $pct ?>%</span>
                                            </div>
                                        </td>
                                        <td style="padding:8px 12px;font-size:11px;color:#888;"><?= date('M d, Y', strtotime($l['loan_date'])) ?></td>
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

<script>
    // DataTable: search / sort / paging (Progress column not sortable)
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#loans-table')) {
            jQuery('#loans-table').DataTable({
                order: [
                    [0, 'asc']
                ],
                pageLength: 10,
                columnDefs: [{
                    orderable: false,
                    targets: 7
                }],
                language: {
                    search: '',
                    searchPlaceholder: 'Search employee…'
                }
            });
        }
    });
</script>

<?php if ($loan_types_editable): ?>
<!-- Loan Type add / edit modal -->
<div class="modal fade" id="modal-loan-type" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="form-loan-type" method="post" novalidate>
            <input type="hidden" name="id" id="loan-type-id" value="">
            <div class="modal-header">
                <h6 class="modal-title" id="loan-type-title"><i class="ri-price-tag-3-line me-2" style="color:#6642aa;"></i>Add Loan Type</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="loan-type-name" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#673bb6;">
                    Loan Type Name <span class="text-danger">*</span>
                </label>
                <input type="text" class="form-control" id="loan-type-name" name="loan_type" maxlength="100" placeholder="e.g. SSS Calamity Loan" autocomplete="off" required>
                <div class="form-text" style="font-size:11px;">Shown in the Add Loan dropdown, payslips and loan reports.</div>
                <div class="text-danger mt-2 d-none" id="loan-type-error" style="font-size:12px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm" id="loan-type-submit" style="background:#6642aa;color:#fff;">Save Loan Type</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function() {
        var modalEl, modal, form, nameInput, idInput, titleEl, errorEl, submitBtn;

        function els() {
            if (modalEl) return true;
            modalEl   = document.getElementById('modal-loan-type');
            if (!modalEl) return false;
            form      = document.getElementById('form-loan-type');
            nameInput = document.getElementById('loan-type-name');
            idInput   = document.getElementById('loan-type-id');
            titleEl   = document.getElementById('loan-type-title');
            errorEl   = document.getElementById('loan-type-error');
            submitBtn = document.getElementById('loan-type-submit');
            modal     = (window.bootstrap && bootstrap.Modal) ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

            form.addEventListener('submit', onSubmit);
            modalEl.addEventListener('shown.bs.modal', function() { nameInput.focus(); nameInput.select(); });
            modalEl.addEventListener('hidden.bs.modal', function() { showError(''); form.reset(); idInput.value = ''; });
            return true;
        }

        function showError(msg) {
            if (!errorEl) return;
            errorEl.textContent = msg || '';
            errorEl.classList.toggle('d-none', !msg);
        }

        function setBusy(busy) {
            submitBtn.disabled = busy;
            submitBtn.innerHTML = busy ? '<i class="ri-loader-4-line ri-spin me-1"></i>Saving…' : 'Save Loan Type';
        }

        function postForm(action, data) {
            var body = new URLSearchParams(data);
            return fetch('ajax.php?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().catch(function() {
                    return { status: 'error', message: r.status === 403 ? 'Your role cannot manage loan types.' : 'Unexpected server response.' };
                });
            });
        }

        function toast(icon, title, text) {
            if (window.Swal) return Swal.fire({ icon: icon, title: title, text: text || '' });
            alert(title + (text ? '\n' + text : ''));
            return Promise.resolve();
        }

        function onSubmit(e) {
            e.preventDefault();
            var name = nameInput.value.replace(/\s+/g, ' ').trim();
            if (!name) { showError('Loan type name is required.'); nameInput.focus(); return; }
            showError('');
            setBusy(true);
            postForm('save_loan_type', { id: idInput.value, loan_type: name }).then(function(resp) {
                setBusy(false);
                if (resp && resp.status === 'ok') {
                    if (modal) modal.hide();
                    toast('success', resp.updated ? 'Loan type updated' : 'Loan type added', '"' + name + '" ' + (resp.updated ? 'has been renamed.' : 'is now available in the Add Loan dropdown.'))
                        .then(function() { window.location.reload(); });
                } else {
                    showError((resp && resp.message) || 'Could not save the loan type.');
                }
            }).catch(function() {
                setBusy(false);
                showError('Network error. Please try again.');
            });
        }

        // Open for add (no id) or edit (id + button carrying data-name).
        window.loanTypeOpen = function(id, btn) {
            if (!els()) return;
            var editing = !!id;
            idInput.value = editing ? id : '';
            nameInput.value = editing && btn ? (btn.getAttribute('data-name') || '') : '';
            titleEl.innerHTML = '<i class="ri-price-tag-3-line me-2" style="color:#6642aa;"></i>' + (editing ? 'Edit Loan Type' : 'Add Loan Type');
            showError('');
            if (modal) modal.show(); else modalEl.style.display = 'block';
        };
    })();
</script>
<?php endif; ?>