<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Leave Types</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Leave Management</a></li>
                                <li class="breadcrumb-item active">Leave Types</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-settings-4-line me-2 text-success"></i>Leave Types &amp; Credits
                        </h4>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-leave-type">
                            <i class="ri-add-line me-1"></i>Add Leave Type
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ltype-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Leave Type</th>
                                        <th class="text-center" style="width:140px;">Days Allowed</th>
                                        <th>Description</th>
                                        <th class="text-center" style="width:110px;">Status</th>
                                        <th class="text-center" style="width:120px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $lt = $conn->query("SELECT * FROM leave_types ORDER BY name ASC");
                                    if ($lt) while ($row = $lt->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><b><i class="ri-calendar-event-line me-1 text-success"></i><?= htmlspecialchars($row['name']) ?></b></td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle fs-12"><?= (int)$row['days_allowed'] ?> day<?= (int)$row['days_allowed'] == 1 ? '' : 's' ?></span>
                                        </td>
                                        <td class="text-muted"><?= htmlspecialchars($row['description'] ?? '') ?></td>
                                        <td class="text-center">
                                            <?php if ($row['status'] == 1): ?>
                                                <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill bg-secondary"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary"
                                                onclick='editLeaveType(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="ri-edit-line"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteLeaveType(<?= $row['id'] ?>)">
                                                <i class="ri-delete-bin-line"></i>
                                            </button>
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

<!-- Add/Edit Leave Type Modal -->
<div class="modal fade" id="modal-leave-type" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-leave-type">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="ltype-modal-title">
                        <i class="ri-calendar-event-line me-2" style="color:#009688;"></i>Add Leave Type
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ltype-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Leave Type Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="ltype-name" name="name" placeholder="e.g. Sick Leave" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Days Allowed (per year) <span class="text-danger">*</span></label>
                        <input type="number" min="0" step="1" class="form-control" id="ltype-days" name="days_allowed" placeholder="e.g. 15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" id="ltype-desc" name="description" rows="2" placeholder="Optional notes"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="ltype-status" name="status" value="1" checked>
                        <label class="form-check-label" for="ltype-status">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#ltype-table')) {
        jQuery('#ltype-table').DataTable({
            order: [[0, 'asc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: 4 }],
            language: { search: '', searchPlaceholder: 'Search leave type…' }
        });
    }
});

function resetLeaveTypeModal() {
    document.getElementById('ltype-id').value = '';
    document.getElementById('form-leave-type').reset();
    document.getElementById('ltype-status').checked = true;
    document.getElementById('ltype-modal-title').innerHTML = '<i class="ri-calendar-event-line me-2" style="color:#009688;"></i>Add Leave Type';
}

function editLeaveType(row) {
    document.getElementById('ltype-id').value = row.id;
    document.getElementById('ltype-name').value = row.name;
    document.getElementById('ltype-days').value = row.days_allowed;
    document.getElementById('ltype-desc').value = row.description || '';
    document.getElementById('ltype-status').checked = (row.status == 1);
    document.getElementById('ltype-modal-title').innerHTML = '<i class="ri-calendar-event-line me-2" style="color:#009688;"></i>Edit Leave Type';
    new bootstrap.Modal(document.getElementById('modal-leave-type')).show();
}

document.getElementById('modal-leave-type').addEventListener('hidden.bs.modal', resetLeaveTypeModal);

document.getElementById('form-leave-type').addEventListener('submit', async function (e) {
    e.preventDefault();
    // Ensure status sends 0 when unchecked
    const data = new URLSearchParams(new FormData(this));
    if (!document.getElementById('ltype-status').checked) data.set('status', '0');
    const res = await fetch('ajax.php?action=save_leave_type', { method: 'POST', body: data });
    const json = await res.json();
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-leave-type')).hide();
        Swal.fire({ icon: 'success', title: 'Success', text: json.message, timer: 1200, showConfirmButton: false })
            .then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to save.' });
    }
});

async function deleteLeaveType(id) {
    const c = await Swal.fire({ title: 'Delete leave type?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
    if (!c.isConfirmed) return;
    const res = await fetch('ajax.php?action=delete_leave_type', { method: 'POST', body: new URLSearchParams({ id }) });
    const json = await res.json();
    if (json?.result) {
        Swal.fire({ icon: 'success', title: 'Deleted', text: json.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: json?.message || 'Failed to delete.' });
    }
}
</script>
