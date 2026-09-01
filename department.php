<?php
/**
 * Departments — the payroll unit. Areas (the operating unit) sit under it and
 * carry the approvers, so the columns below roll the area assignments up rather
 * than reading users.department_id, which nothing has written since the leave
 * chain moved to area_approver.
 */
$__stages = function_exists('leave_stages') ? leave_stages() : [];
// HR approves for the whole hospital and is never stored per area/department.
$__assignable = array_intersect_key($__stages, array_flip(['sec', 'sup', 'admin']));
?>
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
                        <div class="alert alert-light border d-flex align-items-start" style="font-size:12px;">
                            <i class="ri-information-line me-2 mt-1 text-primary"></i>
                            <div>
                                Approvers are assigned per <strong>area</strong>, not per department — the names below are
                                every approver across this department's areas. Change them in
                                <a href="area">Areas</a>.
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="dept-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Department Name</th>
                                        <th class="text-center" style="width:80px;">Areas</th>
                                        <th class="text-center" style="width:90px;">Employees</th>
                                        <?php foreach ($__assignable as $k => $s): ?>
                                            <th><i class="<?= htmlspecialchars($s['icon']) ?> me-1"></i><?= htmlspecialchars($s['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-center" style="width:110px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $depts = [];
                                    $q = $conn->query("
                                        SELECT d.id, d.name,
                                               (SELECT COUNT(*) FROM area a WHERE a.department_id = d.id) AS area_count,
                                               (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM area a WHERE a.department_id = d.id) AS area_names,
                                               (SELECT COUNT(*) FROM employee e WHERE e.department_id = d.id AND e.status = 1) AS emp_count
                                        FROM department d
                                        ORDER BY d.name ASC
                                    ");
                                    while ($q && ($row = $q->fetch_assoc())) $depts[(int)$row['id']] = $row;

                                    // One query for every approver in the hospital, rolled up department →
                                    // stage → distinct people. A head of three wards in the same department
                                    // is one name here, not three.
                                    $byDept = [];
                                    $aq = $conn->query("
                                        SELECT a.department_id, ap.stage, u.name
                                        FROM area_approver ap
                                        JOIN area a ON a.id = ap.area_id
                                        JOIN users u ON u.id = ap.user_id AND u.status = 1
                                        ORDER BY u.name ASC
                                    ");
                                    while ($aq && ($r = $aq->fetch_assoc())) {
                                        $d = (int)$r['department_id'];
                                        if (!in_array($r['name'], $byDept[$d][$r['stage']] ?? [], true)) {
                                            $byDept[$d][$r['stage']][] = $r['name'];
                                        }
                                    }

                                    foreach ($depts as $did => $row):
                                        $mine = $byDept[$did] ?? [];
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-primary-subtle text-primary rounded-pill"
                                                  title="<?= htmlspecialchars($row['area_names'] ?? 'No areas yet') ?>"><?= (int)$row['area_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success rounded-pill"><?= (int)$row['emp_count'] ?></span>
                                        </td>
                                        <?php foreach ($__assignable as $k => $s):
                                            $people = $mine[$k] ?? [];
                                            // Long lists are trimmed in the cell; the full roll-up stays in the tooltip.
                                            $shown  = array_slice($people, 0, 3);
                                            $extra  = count($people) - count($shown); ?>
                                            <td style="font-size:12px;" title="<?= htmlspecialchars(implode(', ', $people)) ?>">
                                                <?php if ($people): ?>
                                                    <i class="<?= htmlspecialchars($s['icon']) ?> me-1 text-success"></i><?= htmlspecialchars(implode(' / ', $shown)) ?><?php if ($extra > 0): ?><span class="text-muted"> +<?= $extra ?> more</span><?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted"><i class="ri-subtract-line me-1"></i><?= empty($s['optional']) ? 'Not assigned' : 'Skipped' ?></span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
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
                                    <?php endforeach; ?>
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
        <!-- novalidate hands validation to Parsley — without it the browser's own
             "Please fill out this field" bubble fires first and the styled
             .parsley-errors-list message never shows. -->
        <form id="form-department" data-parsley-validate novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="dept-modal-title">
                        <i class="ri-building-3-line me-2" style="color:#673bb6;"></i>Add Department
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="dept-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="dept-name" name="name" placeholder="Enter department name"
                               data-parsley-required-message="Department name is required."
                               data-parsley-maxlength="100"
                               data-parsley-maxlength-message="Keep the name under 100 characters."
                               required>
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
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { search: '', searchPlaceholder: 'Search department…' }
        });
    }
});

function editDept(id, name) {
    document.getElementById('dept-id').value = id;
    document.getElementById('dept-name').value = name;
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#673bb6;"></i>Edit Department';
    new bootstrap.Modal(document.getElementById('modal-department')).show();
}

document.getElementById('modal-department').addEventListener('hidden.bs.modal', function () {
    document.getElementById('dept-id').value = '';
    document.getElementById('dept-name').value = '';
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#673bb6;"></i>Add Department';
    // Clear the error list too, or the next open still shows the last message.
    if (window.jQuery && jQuery.fn.parsley) jQuery('#form-department').parsley().reset();
});

document.getElementById('form-department').addEventListener('submit', async function (e) {
    e.preventDefault();

    // Same gate the other CRUD pages use (position.js, sites.js, clusters.js).
    if (window.jQuery && jQuery.fn.parsley) {
        const form = jQuery(this);
        form.parsley().validate();
        if (!form.parsley().isValid()) return;
    }

    const name = document.getElementById('dept-name');
    name.value = name.value.trim();          // "   " must not pass as a name
    if (!name.value) { if (window.jQuery && jQuery.fn.parsley) jQuery(this).parsley().validate(); return; }

    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const data = new FormData(this);
        const res  = await fetch('ajax.php?action=save_department', { method: 'POST', body: new URLSearchParams(data) });
        // save_department() returns a bare 1 (inserted) or 2 (updated) — not JSON.
        const body = (await res.text()).trim();

        if (body === '1' || body === '2') {
            bootstrap.Modal.getInstance(document.getElementById('modal-department')).hide();
            Swal.fire({
                icon: 'success', title: 'Success',
                text: body === '1' ? 'New department saved.' : 'Department updated.',
                timer: 1200, showConfirmButton: false
            }).then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: body || 'Failed to save.' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server.' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = label;
    }
});

async function deleteDept(id) {
    const confirm = await Swal.fire({ title: 'Delete department?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
    if (!confirm.isConfirmed) return;
    const res = await fetch('ajax.php?action=delete_department', { method: 'POST', body: new URLSearchParams({ id }) });
    location.reload();
}
</script>
