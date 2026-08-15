<style>
    .usr-avatar { width:30px; height:30px; border-radius:50%; background:#673bb6; color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .usr-name { font-weight:600; font-size:13px; }
    .usr-username { font-family:monospace; font-size:12px; color:#673bb6; font-weight:600; }
    .usr-employer { font-size:13px; font-weight:600; }
    .usr-site-code { background:#673bb6; color:#fff; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:700; font-family:monospace; }
    .usr-site-name { font-size:11px; font-weight:600; color:#333; }
    .usr-site-addr { font-size:10px; color:#888; }
    .usr-site-item { border:1px solid #d0d7ee; border-radius:4px; padding:4px 8px; margin-bottom:4px; background:#f7f8fc; }
    .usr-action { display:flex; gap:4px; justify-content:center; flex-wrap:nowrap; }
</style>

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">
                            <i class="ri-shield-user-line me-2" style="color:#673bb6;"></i>User Management
                        </h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Users</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header align-items-center d-flex py-2">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-shield-user-line me-2" style="color:#673bb6;"></i>User List
                           
                        </h4>
                        <button type="button" class="btn btn-sm text-white" style="background:#673bb6;border-color:#673bb6;"
                            data-bs-toggle="modal" data-bs-target="#modal">
                            <i class="ri-user-add-line me-1"></i>Create User
                        </button>
                    </div>

                    <div class="card-body">
                        <?php
                        // Roles offered in this list (Administrator role 1 is excluded from the table).
                        $usr_roles = [11, 10, 8, 9, ROLE_TIMEKEEPER];
                        $usr_depts = $conn->query("SELECT id, name FROM department ORDER BY name ASC");
                        ?>
                        <!-- Filter / sort controls -->
                        <div class="row g-2 mb-3 align-items-end">
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1" style="font-size:11px;font-weight:700;color:#673bb6;text-transform:uppercase;letter-spacing:.3px;"><i class="ri-shield-check-line me-1"></i>Role</label>
                                <select id="filter-role" class="form-select form-select-sm">
                                    <option value="">All roles</option>
                                    <?php foreach ($usr_roles as $r): ?>
                                        <option value="<?= $r ?>"><?= htmlspecialchars(getRole($r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1" style="font-size:11px;font-weight:700;color:#673bb6;text-transform:uppercase;letter-spacing:.3px;"><i class="ri-community-line me-1"></i>Department</label>
                                <select id="filter-dept" class="form-select form-select-sm">
                                    <option value="">All departments</option>
                                    <?php if ($usr_depts) while ($d = $usr_depts->fetch_assoc()): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label mb-1" style="font-size:11px;font-weight:700;color:#673bb6;text-transform:uppercase;letter-spacing:.3px;"><i class="ri-pulse-line me-1"></i>Status</label>
                                <select id="filter-status" class="form-select form-select-sm">
                                    <option value="">All statuses</option>
                                    <option value="1">Active</option>
                                    <option value="2">Inactive</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <button type="button" id="filter-reset" class="btn btn-sm btn-outline-secondary w-100"><i class="ri-refresh-line me-1"></i>Reset</button>
                            </div>
                        </div>
                        <div class="table-responsive mt-2 mb-1">
                            <table id="data-table" class="table table-hover table-bordered dt-responsive nowrap align-middle">
                                <thead>
                                    <tr>
                                        <th><i class="ri-user-3-line me-1"></i>User</th>
                                        <th><i class="ri-shield-check-line me-1"></i>Role</th>
                                        <th><i class="ri-community-line me-1"></i>Department</th>
                                        <th class="text-center" style="width:90px;"><i class="ri-pulse-line me-1"></i>Status</th>
                                        <th class="text-center" style="width:160px;"><i class="ri-settings-3-line me-1"></i>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $query = $conn->query("
                                        SELECT users.*,
                                            employers.employer_name,
                                            department.name AS department_name,
                                            (SELECT GROUP_CONCAT(DISTINCT ar.name ORDER BY ar.name SEPARATOR ', ')
                                               FROM area_approver ap JOIN area ar ON ar.id = ap.area_id
                                              WHERE ap.user_id = users.id) AS area_names,
                                            GROUP_CONCAT(CONCAT(sites.site_code,'|',sites.site_name,'|',sites.site_address) SEPARATOR '||') AS site_data
                                        FROM users
                                        LEFT JOIN employers ON employers.id = users.employer_id
                                        LEFT JOIN department ON department.id = users.department_id
                                        LEFT JOIN sites ON sites.timekeeper_id = users.id
                                        WHERE users.role != 1
                                        GROUP BY users.id
                                        ORDER BY users.name ASC
                                    ");
                                    while ($row = $query->fetch_assoc()):
                                        $initials = strtoupper(substr($row['name'], 0, 1))
                                                  . strtoupper(substr(strstr($row['name'], ' ') ?: $row['name'], 1, 1));
                                    ?>
                                        <tr data-role="<?= (int)$row['role'] ?>" data-dept="<?= (int)($row['department_id'] ?? 0) ?>" data-status="<?= (int)$row['status'] ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="usr-avatar"><?= $initials ?></div>
                                                    <div>
                                                        <div class="usr-name"><?= htmlspecialchars($row['name']) ?></div>
                                                        <div class="usr-username"><i class="ri-at-line me-1"></i><?= htmlspecialchars($row['username']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?= getRole($row['role']) ?>
                                                <?php if ($row['role'] == 5 && !empty($row['site_data'])): ?>
                                                    <div class="mt-1">
                                                        <?php
                                                        foreach (explode('||', $row['site_data']) as $s):
                                                            [$code, $name, $address] = array_pad(explode('|', $s), 3, '');
                                                        ?>
                                                            <div class="usr-site-item">
                                                                <span class="usr-site-code"><?= htmlspecialchars($code) ?></span>
                                                                <span class="usr-site-name ms-1"><?= htmlspecialchars($name) ?></span>
                                                                <div class="usr-site-addr"><i class="ri-map-pin-line me-1"></i><?= htmlspecialchars($address) ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php elseif ($row['role'] == 5): ?>
                                                    <div class="text-muted" style="font-size:11px;margin-top:3px;"><i class="ri-information-line me-1"></i>No site assigned</div>
                                                <?php elseif (in_array((int)$row['role'], [8, 10, 11], true) && !empty($row['area_names'])): ?>
                                                    <?php /* Areas are the real scope for an approver; the department
                                                            below is only what pre-area accounts still carry. */ ?>
                                                    <div class="mt-1">
                                                        <div class="usr-site-item">
                                                            <span class="usr-site-name"><i class="ri-node-tree me-1"></i><?= htmlspecialchars($row['area_names']) ?></span>
                                                        </div>
                                                    </div>
                                                <?php elseif (in_array((int)$row['role'], [8, 10], true) && !empty($row['department_name'])): ?>
                                                    <div class="mt-1">
                                                        <div class="usr-site-item">
                                                            <span class="usr-site-name"><i class="ri-community-line me-1"></i><?= htmlspecialchars($row['department_name']) ?></span>
                                                        </div>
                                                    </div>
                                                <?php elseif (in_array((int)$row['role'], [8, 10, 11], true)): ?>
                                                    <div class="text-muted" style="font-size:11px;margin-top:3px;"><i class="ri-information-line me-1"></i>No area assigned — set it on the Areas page</div>
                                                <?php endif; ?>
                                            </td>
                                            <td data-order="<?= htmlspecialchars($row['department_name'] ?? '') ?>">
                                                <?php if (!empty($row['department_name'])): ?>
                                                    <span class="usr-site-name"><i class="ri-community-line me-1 text-muted"></i><?= htmlspecialchars($row['department_name']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($row['status'] == 1): ?>
                                                    <span class="badge rounded-pill bg-success"><i class="ri-checkbox-circle-line me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill bg-danger"><i class="ri-close-circle-line me-1"></i>Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="usr-action">
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                        id="<?= $row['id'] ?>"
                                                        name="<?= htmlspecialchars($row['name']) ?>"
                                                        username="<?= htmlspecialchars($row['username']) ?>"
                                                        employer_id="<?= htmlspecialchars($row['employer_id']) ?>"
                                                        role="<?= htmlspecialchars($row['role']) ?>"
                                                        department_id="<?= htmlspecialchars($row['department_id'] ?? '') ?>"
                                                        employee_id="<?= htmlspecialchars($row['employee_id'] ?? '') ?>"
                                                        onclick="edit_function(this)"
                                                        data-bs-toggle="tooltip" data-bs-placement="top" title="Edit User">
                                                        <i class="ri-edit-line me-1"></i>Edit
                                                    </button>
                                                    <?php if ($row['status'] == 1): ?>
                                                        <button onclick="updateUserStatus(<?= $row['id'] ?>, 2)"
                                                            class="btn btn-sm btn-outline-danger"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Set Inactive">
                                                            <i class="ri-forbid-line"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="updateUserStatus(<?= $row['id'] ?>, 1)"
                                                            class="btn btn-sm btn-outline-success"
                                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Set Active">
                                                            <i class="ri-checkbox-circle-line"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
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

<?php include 'component/add_user_form.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    });

    // Filter the user list by Role / Department / Status. The DataTable is created
    // in assets2/js/user.js; wait for it before wiring the custom search.
    var tries = 0;
    (function wireUserFilters() {
        if (!(window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable('#data-table'))) {
            if (tries++ < 60) { setTimeout(wireUserFilters, 50); }
            return;
        }
        var $ = window.jQuery;
        var table = $('#data-table').DataTable();
        var val = function (id) { return (document.getElementById(id) || {}).value || ''; };

        // Scoped custom search (only affects #data-table) reading the row's data-* attrs.
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'data-table') return true;
            var row = table.row(dataIndex).node();
            if (!row) return true;
            var fRole = val('filter-role'), fDept = val('filter-dept'), fStatus = val('filter-status');
            if (fRole && row.getAttribute('data-role') !== fRole) return false;
            if (fDept && row.getAttribute('data-dept') !== fDept) return false;
            if (fStatus && row.getAttribute('data-status') !== fStatus) return false;
            return true;
        });

        $('#filter-role, #filter-dept, #filter-status').on('change', function () { table.draw(); });
        $('#filter-reset').on('click', function () {
            $('#filter-role, #filter-dept, #filter-status').val('');
            table.draw();
        });
    })();
});
</script>
