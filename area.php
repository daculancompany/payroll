<?php
/**
 * Areas — the ward/section layer under Department.
 *
 * A department is the payroll unit; an area is the operating unit. Four nurse
 * stations share one department but each has its own head, its own roster and
 * its own leave chain, so the approvers live here rather than on the user.
 *
 * Approvers are stored per (area, stage), which is what lets one person be
 * Section Head of their own ward and Supervisor of three others, and lets two
 * co-heads share a slot — either of them may then act.
 */
$__stages = function_exists('leave_stages') ? leave_stages() : [];
// HR is one office for the whole hospital and is deliberately not stored per
// area; only the three ward-level stages are assignable here.
$__assignable = array_intersect_key($__stages, array_flip(['sec', 'sup', 'admin']));
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Areas</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Areas</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-node-tree me-2 text-success"></i>Area List
                        </h4>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-area">
                            <i class="ri-add-line me-1"></i>Add Area
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light border d-flex align-items-start" style="font-size:12px;">
                            <i class="ri-information-line me-2 mt-1 text-primary"></i>
                            <div>
                                Leave runs <strong>Section&nbsp;Head → Supervisor → Dept/Division&nbsp;Head → HR</strong>.
                                The two middle stages are optional — leave them empty and they are skipped.
                                Only the <strong>Dept/Division Head</strong> may edit that area's duty roster;
                                the other two see it read-only.
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="area-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Area</th>
                                        <th>Department</th>
                                        <th class="text-center" style="width:90px;">Employees</th>
                                        <?php foreach ($__assignable as $k => $s): ?>
                                            <th><i class="<?= htmlspecialchars($s['icon']) ?> me-1"></i><?= htmlspecialchars($s['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-center" style="width:130px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $areas = [];
                                    $q = $conn->query("
                                        SELECT a.id, a.name, a.status, a.department_id,
                                               d.name AS dept_name,
                                               (SELECT COUNT(*) FROM employee e WHERE e.area_id = a.id AND e.status = 1) AS emp_count
                                        FROM area a
                                        LEFT JOIN department d ON d.id = a.department_id
                                        ORDER BY d.name ASC, a.name ASC
                                    ");
                                    while ($q && ($row = $q->fetch_assoc())) $areas[(int)$row['id']] = $row;

                                    // One query for every approver rather than three per row.
                                    $byArea = [];
                                    $aq = $conn->query("
                                        SELECT ap.area_id, ap.stage, ap.user_id, u.name
                                        FROM area_approver ap
                                        JOIN users u ON u.id = ap.user_id
                                        ORDER BY u.name ASC
                                    ");
                                    while ($aq && ($r = $aq->fetch_assoc())) {
                                        $byArea[(int)$r['area_id']][$r['stage']][] = ['id' => (int)$r['user_id'], 'name' => $r['name']];
                                    }

                                    foreach ($areas as $aid => $row):
                                        $mine = $byArea[$aid] ?? [];
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['name']) ?></td>
                                        <td style="font-size:12px;"><?= htmlspecialchars($row['dept_name'] ?? '—') ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success rounded-pill"><?= (int)$row['emp_count'] ?></span>
                                        </td>
                                        <?php foreach ($__assignable as $k => $s):
                                            $people = $mine[$k] ?? []; ?>
                                            <td style="font-size:12px;">
                                                <?php if ($people): ?>
                                                    <?= htmlspecialchars(implode(' / ', array_column($people, 'name'))) ?>
                                                <?php else: ?>
                                                    <span class="text-muted"><i class="ri-subtract-line me-1"></i><?= empty($s['optional']) ? 'Not assigned' : 'Skipped' ?></span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-center text-nowrap">
                                            <button class="btn btn-sm btn-outline-primary" title="Edit area"
                                                onclick='editArea(<?= json_encode(["id"=>(int)$row["id"],"name"=>$row["name"],"department_id"=>(int)$row["department_id"]], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                                <i class="ri-edit-line"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-success" title="Assign approvers"
                                                onclick='editApprovers(<?= json_encode(["id"=>(int)$row["id"],"name"=>$row["name"],"a"=>$mine], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                                <i class="ri-user-settings-line"></i>
                                            </button>
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

<!-- Add / Edit area -->
<div class="modal fade" id="modal-area" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-area">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="area-modal-title"><i class="ri-node-tree me-2" style="color:#673bb6;"></i>Add Area</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="area-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Area Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="area-name" name="name" placeholder="e.g. NURSE STATION 4" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select class="form-control" id="area-dept" name="department_id" required>
                            <option value="">— Select department —</option>
                            <?php
                            $dq = $conn->query("SELECT id, name FROM department ORDER BY name ASC");
                            while ($dq && ($d = $dq->fetch_assoc())):
                            ?>
                                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <div class="form-text" style="font-size:11px;">Payroll still reports by department; the area only splits it for leave and rosters.</div>
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

<!-- Assign approvers -->
<div class="modal fade" id="modal-approvers" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-approvers">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="ri-user-settings-line me-2" style="color:#673bb6;"></i>Approvers — <span id="ap-area-name"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ap-area-id" name="area_id" value="">
                    <?php
                    $users = [];
                    $uq = $conn->query("SELECT id, name, role FROM users WHERE status = 1 ORDER BY name ASC");
                    while ($uq && ($u = $uq->fetch_assoc())) $users[] = $u;
                    foreach ($__assignable as $k => $s):
                    ?>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="<?= htmlspecialchars($s['icon']) ?> me-1"></i><?= htmlspecialchars($s['label']) ?>
                            <?php if (!empty($s['optional'])): ?>
                                <span class="badge bg-light text-muted border ms-1" style="font-size:10px;">optional — empty = skipped</span>
                            <?php else: ?>
                                <span class="badge bg-light text-danger border ms-1" style="font-size:10px;">required</span>
                            <?php endif; ?>
                            <?php if ($k === 'admin'): ?>
                                <span class="badge bg-success-subtle text-success border ms-1" style="font-size:10px;">edits the duty roster</span>
                            <?php endif; ?>
                        </label>
                        <select class="form-control ap-select" multiple size="5" name="stage[<?= $k ?>][]" data-stage="<?= $k ?>">
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                    <div class="alert alert-light border mb-0" style="font-size:11.5px;">
                        <i class="ri-information-line me-1 text-primary"></i>
                        Pick two people for a stage when the ward has co-heads — whichever of them acts first
                        records the decision. Nobody ever approves their own leave: if the requester holds a
                        stage, that stage is skipped for their request.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success">Save approvers</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#area-table')) {
        jQuery('#area-table').DataTable({
            order: [[1, 'asc'], [0, 'asc']],
            pageLength: 25,
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { search: '', searchPlaceholder: 'Search area…' }
        });
    }
});

function editArea(a) {
    document.getElementById('area-id').value = a.id;
    document.getElementById('area-name').value = a.name;
    document.getElementById('area-dept').value = a.department_id || '';
    document.getElementById('area-modal-title').innerHTML = '<i class="ri-node-tree me-2" style="color:#673bb6;"></i>Edit Area';
    new bootstrap.Modal(document.getElementById('modal-area')).show();
}

document.getElementById('modal-area').addEventListener('hidden.bs.modal', function () {
    document.getElementById('form-area').reset();
    document.getElementById('area-id').value = '';
    document.getElementById('area-modal-title').innerHTML = '<i class="ri-node-tree me-2" style="color:#673bb6;"></i>Add Area';
});

function editApprovers(a) {
    document.getElementById('ap-area-id').value = a.id;
    document.getElementById('ap-area-name').textContent = a.name;
    document.querySelectorAll('.ap-select').forEach(function (sel) {
        const chosen = (a.a && a.a[sel.dataset.stage]) ? a.a[sel.dataset.stage].map(x => String(x.id)) : [];
        Array.from(sel.options).forEach(o => { o.selected = chosen.includes(o.value); });
    });
    new bootstrap.Modal(document.getElementById('modal-approvers')).show();
}

async function postForm(action, form) {
    const res = await fetch('ajax.php?action=' + action, { method: 'POST', body: new FormData(form) });
    const txt = await res.text();
    try { return JSON.parse(txt); } catch (e) { return { result: false, message: txt.slice(0, 200) }; }
}

document.getElementById('form-area').addEventListener('submit', async function (e) {
    e.preventDefault();
    const json = await postForm('save_area', this);
    if (json && json.result) { location.reload(); }
    else { Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to save.' }); }
});

document.getElementById('form-approvers').addEventListener('submit', async function (e) {
    e.preventDefault();
    const json = await postForm('save_area_approvers', this);
    if (json && json.result) { location.reload(); }
    else { Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to save.' }); }
});
</script>
