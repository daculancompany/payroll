<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Branches</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Branches</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-building-2-line me-2 text-success"></i>Branch List
                        </h4>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-branch">
                            <i class="ri-add-line me-1"></i>Add Branch
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Code</th>
                                        <th>Branch Name</th>
                                        <th>City</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center" style="width:110px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = $conn->query("SELECT * FROM branches ORDER BY branch_name ASC");
                                    $i = 1;
                                    if ($q) while ($row = $q->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td><span style="font-family:monospace;font-weight:700;color:#009688;"><?= htmlspecialchars($row['branch_code']) ?></span></td>
                                        <td><?= htmlspecialchars($row['branch_name']) ?></td>
                                        <td><?= htmlspecialchars($row['city'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($row['email'] ?? '—') ?></td>
                                        <td class="text-center">
                                            <?php if ($row['status']): ?>
                                                <span class="badge bg-success rounded-pill">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger rounded-pill">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary"
                                                onclick="editBranch(<?= htmlspecialchars(json_encode($row)) ?>)">
                                                <i class="ri-edit-line"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger"
                                                onclick="deleteBranch(<?= $row['id'] ?>)">
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

<!-- Add/Edit Modal -->
<div class="modal fade" id="modal-branch" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-branch">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="branch-modal-title">
                        <i class="ri-building-2-line me-2" style="color:#009688;"></i>Add Branch
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="branch-id" name="id" value="">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Branch Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="branch-code" name="branch_code" placeholder="e.g. BR-001" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Branch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="branch-name" name="branch_name" placeholder="e.g. Main Office" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">City</label>
                            <input type="text" class="form-control" id="branch-city" name="city" placeholder="e.g. Cebu City">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Phone</label>
                            <input type="text" class="form-control" id="branch-phone" name="phone" placeholder="e.g. 032-123-4567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Email</label>
                            <input type="email" class="form-control" id="branch-email" name="email" placeholder="e.g. branch@company.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Status</label>
                            <select class="form-control" id="branch-status" name="status">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:11px;text-transform:uppercase;color:#009688;">Address</label>
                            <textarea class="form-control" id="branch-address" name="address" rows="2" placeholder="Street address"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function editBranch(row) {
    document.getElementById('branch-id').value    = row.id;
    document.getElementById('branch-code').value  = row.branch_code;
    document.getElementById('branch-name').value  = row.branch_name;
    document.getElementById('branch-city').value  = row.city || '';
    document.getElementById('branch-phone').value = row.phone || '';
    document.getElementById('branch-email').value = row.email || '';
    document.getElementById('branch-address').value = row.address || '';
    document.getElementById('branch-status').value  = row.status;
    document.getElementById('branch-modal-title').innerHTML =
        '<i class="ri-building-2-line me-2" style="color:#009688;"></i>Edit Branch';
    new bootstrap.Modal(document.getElementById('modal-branch')).show();
}

document.getElementById('modal-branch').addEventListener('hidden.bs.modal', function () {
    document.getElementById('form-branch').reset();
    document.getElementById('branch-id').value = '';
    document.getElementById('branch-modal-title').innerHTML =
        '<i class="ri-building-2-line me-2" style="color:#009688;"></i>Add Branch';
});

document.getElementById('form-branch').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = new URLSearchParams(new FormData(this));
    const res  = await fetch('ajax.php?action=save_branch', { method: 'POST', body: data });
    const json = await res.json();
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-branch'))?.hide();
        location.reload();
    } else {
        alert(json?.message || 'Failed to save.');
    }
});

async function deleteBranch(id) {
    const c = await Swal.fire({ title: 'Delete branch?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
    if (!c.isConfirmed) return;
    await fetch('ajax.php?action=delete_branch', { method: 'POST', body: new URLSearchParams({ id }) });
    location.reload();
}
</script>
