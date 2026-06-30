<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Department</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Department</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-building-3-line me-2 text-success"></i>Department List
                        </h4>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-department">
                            <i class="ri-add-line me-1"></i>Add Department
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="dept-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Department Name</th>
                                        <th class="text-center">Employees</th>
                                        <th class="text-center" style="width:110px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = $conn->query("
                                        SELECT d.*, COUNT(e.id) AS emp_count
                                        FROM department d
                                        LEFT JOIN employee e ON e.department_id = d.id AND e.status = 1
                                        GROUP BY d.id ORDER BY d.name ASC
                                    ");
                                    if ($q) while ($row = $q->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['name']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success rounded-pill"><?= $row['emp_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary"
                                                onclick="editDept(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['name'])) ?>')">
                                                <i class="ri-edit-line"></i>
                                            </button>
                                            <!-- <button class="btn btn-sm btn-outline-danger"
                                                onclick="deleteDept(<?= $row['id'] ?>)">
                                                <i class="ri-delete-bin-line"></i>
                                            </button> -->
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
<div class="modal fade" id="modal-department" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-department">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="dept-modal-title">
                        <i class="ri-building-3-line me-2" style="color:#009688;"></i>Add Department
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="dept-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="dept-name" name="name" placeholder="Enter department name" required>
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
// Initialise DataTable (search / sort / paging) — Action column not sortable
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#dept-table')) {
        jQuery('#dept-table').DataTable({
            order: [[0, 'asc']],
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: 2 }],
            language: { search: '', searchPlaceholder: 'Search department…' }
        });
    }
});

function editDept(id, name) {
    document.getElementById('dept-id').value = id;
    document.getElementById('dept-name').value = name;
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#009688;"></i>Edit Department';
    new bootstrap.Modal(document.getElementById('modal-department')).show();
}

document.getElementById('modal-department').addEventListener('hidden.bs.modal', function () {
    document.getElementById('dept-id').value = '';
    document.getElementById('dept-name').value = '';
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#009688;"></i>Add Department';
});

document.getElementById('form-department').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = new FormData(this);
    const res = await fetch('ajax.php?action=save_department', { method: 'POST', body: new URLSearchParams(data) });
    const json = await res.json();
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-department')).hide();
        location.reload();
    } else {
        alert(json?.message || 'Failed to save.');
    }
});

async function deleteDept(id) {
    const confirm = await Swal.fire({ title: 'Delete department?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
    if (!confirm.isConfirmed) return;
    const res = await fetch('ajax.php?action=delete_department', { method: 'POST', body: new URLSearchParams({ id }) });
    location.reload();
}
</script>
