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
        <!-- novalidate hands validation to Parsley — without it the browser's own
             "Please fill out this field" bubble fires first and the styled
             .parsley-errors-list message never shows. -->
        <form id="form-area" data-parsley-validate novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="area-modal-title"><i class="ri-node-tree me-2" style="color:#673bb6;"></i>Add Area</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="area-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Area Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="area-name" name="name" placeholder="e.g. NURSE STATION 4"
                               data-parsley-required-message="Area name is required."
                               data-parsley-maxlength="100"
                               data-parsley-maxlength-message="Keep the name under 100 characters."
                               required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select class="form-control" id="area-dept" name="department_id"
                                data-parsley-required-message="Pick the department this area belongs to." required>
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
                        <?php /* data-cs-multi hands this to the app's own multi-select: one line
                                 instead of a five-row listbox, a filter box, and — the point —
                                 click-to-toggle plus a Clear all, so taking someone off a stage
                                 is not a ctrl-click nobody discovers. The chips below repeat the
                                 selection with an × each, the same as the Department page. */ ?>
                        <select class="form-control form-control-sm ap-select" multiple
                                name="stage[<?= $k ?>][]" data-stage="<?= $k ?>"
                                data-cs-multi="true" data-cs-search="true"
                                data-cs-title="<?= htmlspecialchars($s['label']) ?>"
                                data-cs-icon="<?= htmlspecialchars($s['icon']) ?>"
                                data-placeholder="— nobody —">
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="ap-chips mt-1" data-for="<?= $k ?>"></div>
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
    const dept = document.getElementById('area-dept');
    dept.value = a.department_id || '';
    // The native select is right the moment .value is set, but CustomSelect has
    // painted a control over it and only re-reads on a real change event — so
    // without this the picker opens on "— Select department —" for an area that
    // plainly has one, and Parsley passes because the underlying value is fine.
    if (window.CustomSelect) window.CustomSelect.refresh(dept);
    document.getElementById('area-modal-title').innerHTML = '<i class="ri-node-tree me-2" style="color:#673bb6;"></i>Edit Area';
    new bootstrap.Modal(document.getElementById('modal-area')).show();
}

document.getElementById('modal-area').addEventListener('hidden.bs.modal', function () {
    document.getElementById('form-area').reset();
    document.getElementById('area-id').value = '';
    // form.reset() fires no change event either, so the picker would otherwise
    // carry the edited area's department into the next Add Area.
    if (window.CustomSelect) window.CustomSelect.refresh(document.getElementById('area-dept'));
    document.getElementById('area-modal-title').innerHTML = '<i class="ri-node-tree me-2" style="color:#673bb6;"></i>Add Area';
    // Clear the error list too, or the next open still shows the last message.
    if (window.jQuery && jQuery.fn.parsley) jQuery('#form-area').parsley().reset();
});

const apEsc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/* Who is on this stage, spelled out with an × each — the visible half of
   "remove". Always rebuilt from the select, never the other way round. */
function apRenderChips(sel) {
    const box = document.querySelector('.ap-chips[data-for="' + sel.dataset.stage + '"]');
    if (!box) return;
    const picked = Array.from(sel.selectedOptions);
    if (!picked.length) {
        const required = sel.dataset.stage === 'admin';
        box.innerHTML = '<span style="font-size:11px;" class="' + (required ? 'text-warning-emphasis' : 'text-muted') + '">'
            + (required ? '<i class="ri-error-warning-line me-1"></i>Not assigned' : '<i class="ri-subtract-line me-1"></i>Nobody — this stage is skipped')
            + '</span>';
        return;
    }
    box.innerHTML = picked.map(o =>
        '<span class="badge bg-primary-subtle text-primary rounded-pill me-1 mb-1" style="font-size:11px;font-weight:500;">'
        + apEsc(o.textContent)
        + '<button type="button" class="btn-close ms-1 ap-chip-x" data-value="' + apEsc(o.value) + '"'
        + ' style="font-size:.5rem;vertical-align:middle;" aria-label="Remove ' + apEsc(o.textContent) + '"></button>'
        + '</span>'
    ).join('');
}

document.getElementById('form-approvers').addEventListener('change', function (e) {
    const sel = e.target.closest('.ap-select');
    if (sel) apRenderChips(sel);
});

document.getElementById('form-approvers').addEventListener('click', function (e) {
    const x = e.target.closest('.ap-chip-x');
    if (!x) return;
    const sel = document.querySelector('.ap-select[data-stage="' + x.closest('.ap-chips').dataset.for + '"]');
    if (!sel) return;
    Array.from(sel.options).forEach(o => { if (o.value === x.dataset.value) o.selected = false; });
    // CustomSelect listens natively, so a jQuery .trigger('change') would never
    // reach it and the trigger label would drift from what actually posts.
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    if (window.CustomSelect) window.CustomSelect.refresh(sel);
});

function editApprovers(a) {
    document.getElementById('ap-area-id').value = a.id;
    document.getElementById('ap-area-name').textContent = a.name;
    document.querySelectorAll('.ap-select').forEach(function (sel) {
        const chosen = (a.a && a.a[sel.dataset.stage]) ? a.a[sel.dataset.stage].map(x => String(x.id)) : [];
        Array.from(sel.options).forEach(o => { o.selected = chosen.includes(o.value); });
        if (window.CustomSelect) window.CustomSelect.refresh(sel);
        apRenderChips(sel);
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

    // Same gate the other CRUD pages use (position.js, sites.js, clusters.js).
    if (window.jQuery && jQuery.fn.parsley) {
        const form = jQuery(this);
        form.parsley().validate();
        if (!form.parsley().isValid()) return;
    }

    const name = document.getElementById('area-name');
    name.value = name.value.trim();          // "   " must not pass as a name
    if (!name.value) { if (window.jQuery && jQuery.fn.parsley) jQuery(this).parsley().validate(); return; }

    // Read before the save: the reload wipes the form, and the hidden id is the
    // only thing that distinguishes a rename from a brand-new area.
    const isEdit = !!document.getElementById('area-id').value;

    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const json = await postForm('save_area', this);
        if (json && json.result) {
            // Same confirmation the Department page gives. Reloading straight
            // away looked like nothing had happened.
            Swal.fire({
                icon: 'success', title: 'Success',
                text: isEdit ? 'Area updated.' : 'New area saved.',
                timer: 1200, showConfirmButton: false
            }).then(() => location.reload());
            return;
        }
        Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to save.' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = label;
    }
});

document.getElementById('form-approvers').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        const json = await postForm('save_area_approvers', this);
        if (json && json.result) {
            const area = document.getElementById('ap-area-name').textContent.trim();
            Swal.fire({
                icon: 'success', title: 'Success',
                text: 'Approvers updated for ' + area + '.',
                timer: 1400, showConfirmButton: false
            }).then(() => location.reload());
            return;
        }
        Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to save.' });
    } finally {
        btn.disabled = false;
        btn.innerHTML = label;
    }
});
</script>
