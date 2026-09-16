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

/* Who may actually assign them. The approver editor writes area_approver, so it
   answers to the Areas screen's permissions, not this one's — HR can rename a
   department but has never been able to decide who signs off whose leave. Same
   test the save endpoint applies, so the form can never offer a write the server
   will refuse. */
$__can_assign = function_exists('can_edit') ? can_edit('area') : true;
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
                                every approver across this department's areas. Edit a department to assign or
                                remove them area by area, or manage the areas themselves in <a href="area">Areas</a>.
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

                                    /* One query for every area in the hospital, feeding two things:

                                       $byDept    — department → stage → distinct names, for the read-only
                                                    columns below. A head of three wards in the same
                                                    department is one name here, not three.
                                       $areasByDept — department → areas → stage → [{id,name}], the payload
                                                    the Edit modal builds its per-area pickers from.

                                       LEFT JOIN, not INNER: an area with nobody assigned still has to reach
                                       the modal. The editor rewrites a department's areas wholesale, so an
                                       area missing from the payload would come back as an area wiped. */
                                    $byDept = $areasByDept = [];
                                    $aq = $conn->query("
                                        SELECT a.department_id, a.id AS area_id, a.name AS area_name,
                                               ap.stage, ap.user_id, u.name AS user_name
                                        FROM area a
                                        LEFT JOIN area_approver ap ON ap.area_id = a.id
                                        LEFT JOIN users u ON u.id = ap.user_id AND u.status = 1
                                        ORDER BY a.name ASC, u.name ASC
                                    ");
                                    while ($aq && ($r = $aq->fetch_assoc())) {
                                        $d   = (int)$r['department_id'];
                                        $aid = (int)$r['area_id'];
                                        if (!isset($areasByDept[$d][$aid])) {
                                            $areasByDept[$d][$aid] = ['id' => $aid, 'name' => $r['area_name'], 'a' => []];
                                        }
                                        // A row with no approver, or one whose approver is deactivated,
                                        // carries the area and nothing else.
                                        if ($r['stage'] === null || $r['user_name'] === null) continue;
                                        $areasByDept[$d][$aid]['a'][$r['stage']][] = ['id' => (int)$r['user_id'], 'name' => $r['user_name']];
                                        if (!in_array($r['user_name'], $byDept[$d][$r['stage']] ?? [], true)) {
                                            $byDept[$d][$r['stage']][] = $r['user_name'];
                                        }
                                    }
                                    // Ordering above is by area first, so re-sort each stage to keep the
                                    // column text in the same alphabetical order it has always had.
                                    foreach ($byDept as &$__st) foreach ($__st as &$__names) sort($__names);
                                    unset($__st, $__names);

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
                                                onclick='editDept(<?= json_encode([
                                                    "id"    => (int)$did,
                                                    "name"  => $row["name"],
                                                    "areas" => array_values($areasByDept[$did] ?? []),
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
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
    <!-- editDept() widens this to modal-lg modal-dialog-scrollable and the reset
         narrows it again, so Add Department stays a small one-field dialog. -->
    <div class="modal-dialog" id="dept-modal-dialog">
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

                    <!-- Leave approvers, one block per area. Built by editDept() and torn
                         down on hide; empty (and skipped entirely) while creating. -->
                    <?php if ($__can_assign): ?>
                    <div id="dept-approvers" class="d-none">
                        <hr class="my-3">
                        <div class="d-flex align-items-center mb-1">
                            <label class="form-label mb-0 flex-grow-1">
                                <i class="ri-user-settings-line me-1" style="color:#673bb6;"></i>Leave approvers
                            </label>
                            <span class="text-muted" style="font-size:11px;" id="dept-area-count"></span>
                        </div>
                        <div class="text-muted mb-2" style="font-size:11px;">
                            Assigned per area. Section/Unit Head and Supervisor may be left empty — those
                            stages are then skipped. Click a name's <i class="ri-close-line"></i> to remove it.
                        </div>
                        <div id="dept-areas"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
/* Every active account, once, rather than inlined into each of a department's
   selects. Deliberately NOT filtered by role: 16 of the accounts holding the
   Department Head stage and 7 holding Section/Unit Head carry a different role,
   and because saving rewrites an area's rows wholesale, anyone missing from this
   list would be dropped on the next save. The optgroups below sort the expected
   role to the top instead. */
$__users = [];
$__uq = $conn->query("SELECT id, name, role FROM users WHERE status = 1 ORDER BY name ASC");
while ($__uq && ($__u = $__uq->fetch_assoc())) {
    $__users[] = ['id' => (int)$__u['id'], 'name' => $__u['name'], 'role' => (int)$__u['role']];
}
$__stage_meta = [];
foreach ($__assignable as $k => $s) {
    $__stage_meta[] = [
        'key'      => $k,
        'label'    => $s['label'],
        'icon'     => $s['icon'],
        'role'     => (int)$s['role'],
        'optional' => !empty($s['optional']),
    ];
}
?>
<script>
const DEPT_USERS  = <?= json_encode($__users, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const DEPT_STAGES = <?= json_encode($__stage_meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const DEPT_ROLE_LABELS = { 8: 'Department Heads', 9: 'HR', 10: 'Supervisors', 11: 'Section/Unit Heads' };

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

const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/* Options for one stage, grouped by role with the stage's own role first — a
   49-name flat list is unusable, and the menu's filter box handles the rest. */
function stageOptions(stage, chosen) {
    const order = [stage.role, 8, 10, 11, 9];
    const seen = new Set(), groups = [];
    order.forEach(r => {
        if (seen.has(r)) return;
        seen.add(r);
        const list = DEPT_USERS.filter(u => u.role === r);
        if (list.length) groups.push([DEPT_ROLE_LABELS[r] || 'Other accounts', list]);
    });
    const rest = DEPT_USERS.filter(u => !seen.has(u.role));
    if (rest.length) groups.push(['Other accounts', rest]);

    return groups.map(([label, list]) =>
        '<optgroup label="' + esc(label) + '">' +
        list.map(u => '<option value="' + u.id + '"' + (chosen.has(u.id) ? ' selected' : '') + '>' + esc(u.name) + '</option>').join('') +
        '</optgroup>'
    ).join('');
}

function stageBlock(area, stage) {
    const chosen = new Set(((area.a || {})[stage.key] || []).map(x => Number(x.id)));
    const badge = stage.optional
        ? '<span class="badge bg-light text-muted border ms-1" style="font-size:10px;">optional — empty = skipped</span>'
        : '<span class="badge bg-light text-danger border ms-1" style="font-size:10px;">required</span>';
    const roster = stage.key === 'admin'
        ? '<span class="badge bg-success-subtle text-success border ms-1" style="font-size:10px;">edits the duty roster</span>'
        : '';
    return '<div class="mb-2">'
        + '<label class="form-label mb-1" style="font-size:11px;">'
        + '<i class="' + esc(stage.icon) + ' me-1"></i>' + esc(stage.label) + badge + roster
        + '</label>'
        + '<select class="form-control form-control-sm dept-ap" multiple'
        + ' name="areas[' + area.id + '][' + stage.key + '][]"'
        + ' data-cs-multi="true" data-cs-search="true"'
        + ' data-cs-title="' + esc(stage.label) + '" data-cs-icon="' + esc(stage.icon) + '"'
        + ' data-placeholder="— nobody —"'
        + ' data-area="' + area.id + '" data-stage="' + stage.key + '">'
        + stageOptions(stage, chosen)
        + '</select>'
        + '<div class="dept-chips mt-1" data-for="' + area.id + '-' + stage.key + '"></div>'
        + '</div>';
}

/* The chips are the removal affordance: who is assigned, visible without opening
   anything, each with its own ×. Rebuilt from the select, never the other way. */
function renderChips(sel) {
    const box = document.querySelector('.dept-chips[data-for="' + sel.dataset.area + '-' + sel.dataset.stage + '"]');
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
        + esc(o.textContent)
        + '<button type="button" class="btn-close ms-1 dept-chip-x" data-value="' + esc(o.value) + '"'
        + ' style="font-size:.5rem;vertical-align:middle;" aria-label="Remove ' + esc(o.textContent) + '"></button>'
        + '</span>'
    ).join('');
}

function areaSummary(areaId) {
    const parts = DEPT_STAGES.map(st => {
        const sel = document.querySelector('.dept-ap[data-area="' + areaId + '"][data-stage="' + st.key + '"]');
        const n = sel ? sel.selectedOptions.length : 0;
        if (!n) return '—';
        return sel.selectedOptions[0].textContent.split(',')[0] + (n > 1 ? ' +' + (n - 1) : '');
    });
    const admin = document.querySelector('.dept-ap[data-area="' + areaId + '"][data-stage="admin"]');
    const warn = admin && !admin.selectedOptions.length
        ? ' <span class="badge bg-warning-subtle text-warning-emphasis border ms-1" style="font-size:10px;"><i class="ri-error-warning-line me-1"></i>no Department Head</span>'
        : '';
    return '<span class="text-muted" style="font-size:11px;">' + esc(parts.join(' · ')) + '</span>' + warn;
}

function refreshSummary(areaId) {
    const el = document.querySelector('.dept-area-summary[data-area="' + areaId + '"]');
    if (el) el.innerHTML = areaSummary(areaId);
}

function buildAreas(deptId, areas) {
    // Absent for a role that may rename a department but not assign approvers.
    const host = document.getElementById('dept-areas');
    const wrap = document.getElementById('dept-approvers');
    if (!host || !wrap) return;
    document.getElementById('dept-area-count').textContent =
        areas.length === 1 ? '1 area' : areas.length + ' areas';

    if (!areas.length) {
        wrap.classList.remove('d-none');
        host.innerHTML = '<div class="alert alert-light border mb-0" style="font-size:12px;">'
            + '<i class="ri-information-line me-1 text-primary"></i>No areas yet — add one in '
            + '<a href="area">Areas</a> before assigning approvers.</div>';
        return;
    }

    wrap.classList.remove('d-none');
    /* The manifest: every area of the department, whether or not it has anyone.
       An empty <select multiple> posts nothing at all, so without this the server
       cannot tell "nobody here" from "the form dropped this area" — and it
       rewrites an area wholesale, so guessing wrong would wipe it. The department
       id rides along under its own name; the form's own `id` field belongs to
       save_department. */
    const manifest = '<input type="hidden" name="department_id" value="' + deptId + '">'
        + areas.map(a => '<input type="hidden" name="area_ids[]" value="' + a.id + '">').join('');

    if (areas.length === 1) {
        // 13 of 20 departments land here — no accordion chrome for a single area.
        const a = areas[0];
        host.innerHTML = manifest
            + '<div class="border rounded p-2">'
            + '<div class="fw-semibold mb-2" style="font-size:12px;"><i class="ri-node-tree me-1 text-muted"></i>' + esc(a.name) + '</div>'
            + DEPT_STAGES.map(st => stageBlock(a, st)).join('')
            + '</div>';
    } else {
        host.innerHTML = manifest + '<div class="accordion accordion-flush border rounded" id="dept-area-acc">'
            + areas.map((a, i) =>
                '<div class="accordion-item">'
                + '<h2 class="accordion-header">'
                + '<button class="accordion-button ' + (i ? 'collapsed' : '') + ' py-2" type="button"'
                + ' style="font-size:12px;" data-bs-toggle="collapse" data-bs-target="#dept-area-' + a.id + '">'
                + '<span class="fw-semibold me-2">' + esc(a.name) + '</span>'
                + '<span class="dept-area-summary" data-area="' + a.id + '"></span>'
                + '</button></h2>'
                + '<div id="dept-area-' + a.id + '" class="accordion-collapse collapse ' + (i ? '' : 'show') + '" data-bs-parent="#dept-area-acc">'
                + '<div class="accordion-body py-2">' + DEPT_STAGES.map(st => stageBlock(a, st)).join('') + '</div>'
                + '</div></div>'
            ).join('')
            + '</div>';
    }

    // The global sweep only auto-enhances nodes it sees added; enhance explicitly
    // so the order is deterministic, then paint chips and headers from the DOM.
    host.querySelectorAll('.dept-ap').forEach(sel => {
        if (window.CustomSelect) window.CustomSelect.enhance(sel);
        renderChips(sel);
        refreshSummary(sel.dataset.area);
    });
}

// One delegated pair for every select and chip in the section.
document.getElementById('dept-areas')?.addEventListener('change', function (e) {
    const sel = e.target.closest('.dept-ap');
    if (!sel) return;
    renderChips(sel);
    refreshSummary(sel.dataset.area);
});

document.getElementById('dept-areas')?.addEventListener('click', function (e) {
    const x = e.target.closest('.dept-chip-x');
    if (!x) return;
    const box = x.closest('.dept-chips');
    const [areaId, stage] = box.dataset.for.split(/-(?=[^-]+$)/);
    const sel = document.querySelector('.dept-ap[data-area="' + areaId + '"][data-stage="' + stage + '"]');
    if (!sel) return;
    Array.from(sel.options).forEach(o => { if (o.value === x.dataset.value) o.selected = false; });
    // CustomSelect listens with addEventListener, so a jQuery .trigger('change')
    // would never reach it — the label and the posted value would drift apart.
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    if (window.CustomSelect) window.CustomSelect.refresh(sel);
});

function editDept(d) {
    document.getElementById('dept-id').value = d.id;
    document.getElementById('dept-name').value = d.name;
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#673bb6;"></i>Edit Department';
    document.getElementById('dept-modal-dialog').className = 'modal-dialog modal-lg modal-dialog-scrollable';
    buildAreas(d.id, d.areas || []);
    new bootstrap.Modal(document.getElementById('modal-department')).show();
}

document.getElementById('modal-department').addEventListener('hidden.bs.modal', function () {
    document.getElementById('dept-id').value = '';
    document.getElementById('dept-name').value = '';
    document.getElementById('dept-modal-title').innerHTML = '<i class="ri-building-3-line me-2" style="color:#673bb6;"></i>Add Department';
    document.getElementById('dept-modal-dialog').className = 'modal-dialog';

    // CustomSelect parents each menu outside the select it belongs to and has no
    // hook on node removal, so dropping the HTML without destroying first would
    // leak one orphan menu per select, every time the modal is opened.
    //
    // data-no-cs goes on first because destroy() unwraps by re-inserting the
    // select next to its wrapper, and it clears the data-cs-done marker as it
    // goes. That re-insert is an addedNode, so CustomSelect's global sweep would
    // enhance the select all over again — asynchronously, by which time the line
    // below has detached it, leaving the new menu stranded in <body> with no
    // wrapper to belong to. Measured: 33 orphan menus per open of an 11-area
    // department. The attribute dies with the markup a line later.
    const host = document.getElementById('dept-areas');
    if (host) {
        host.querySelectorAll('.dept-ap').forEach(sel => {
            sel.setAttribute('data-no-cs', '');
            if (window.CustomSelect) window.CustomSelect.destroy(sel);
        });
        host.innerHTML = '';
        document.getElementById('dept-approvers').classList.add('d-none');
        document.getElementById('dept-area-count').textContent = '';
    }
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

    const id = document.getElementById('dept-id').value;
    const hasAreas = this.querySelectorAll('input[name="area_ids[]"]').length > 0;

    // A required stage left empty is a warning, not a wall — an admin setting a
    // new department up should not be trapped by an approver they have not
    // created yet. The pill in the accordion header says the same thing.
    if (id && hasAreas) {
        const orphans = Array.from(this.querySelectorAll('.dept-ap[data-stage="admin"]'))
            .filter(s => !s.selectedOptions.length)
            .map(s => {
                const item = s.closest('.accordion-item') || s.closest('.border');
                const head = item ? item.querySelector('.fw-semibold') : null;
                return head ? head.textContent.trim() : 'an area';
            });
        if (orphans.length) {
            const go = await Swal.fire({
                icon: 'warning', title: 'No Department Head',
                html: '<div style="font-size:13px;text-align:left;">Leave requests from these areas will stop at that stage:<br><b>'
                    + orphans.join('</b>, <b>') + '</b></div>',
                showCancelButton: true, confirmButtonText: 'Save anyway', cancelButtonText: 'Go back'
            });
            if (!go.isConfirmed) return;
        }
    }

    const btn = this.querySelector('button[type="submit"]');
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    try {
        // Approvers first, deliberately: that write is the transactional one, so
        // if it refuses, nothing has changed anywhere and the modal stays open on
        // the admin's unsaved edits. Renaming first would strand them.
        if (id && hasAreas) {
            // FormData, not URLSearchParams — the areas[id][stage][] keys have to
            // survive as arrays.
            const apRes = await fetch('ajax.php?action=save_department_approvers', { method: 'POST', body: new FormData(this) });
            const apTxt = await apRes.text();
            let ap;
            try { ap = JSON.parse(apTxt); } catch (e) { ap = { result: false, message: apTxt.slice(0, 200) }; }
            if (!ap.result) {
                Swal.fire({ icon: 'error', title: 'Approvers not saved', text: ap.message || 'Failed to save approvers.' });
                return;
            }
        }

        const res  = await fetch('ajax.php?action=save_department', {
            method: 'POST',
            // save_department() runs extract($_POST); it has no business seeing
            // the approver arrays.
            body: new URLSearchParams({ id: id, name: document.getElementById('dept-name').value })
        });
        // save_department() returns a bare 1 (inserted) or 2 (updated) — not JSON.
        const body = (await res.text()).trim();

        if (body === '1' || body === '2') {
            bootstrap.Modal.getInstance(document.getElementById('modal-department')).hide();
            Swal.fire({
                icon: 'success', title: 'Success',
                text: body === '1' ? 'New department saved.' : 'Department updated.',
                timer: 1200, showConfirmButton: false
            }).then(() => location.reload());
        } else if (id && hasAreas) {
            // The approver write already committed — say so, or the admin redoes it.
            Swal.fire({
                icon: 'warning', title: 'Partly saved',
                text: 'Approvers were saved, but the department name could not be updated.'
            });
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
