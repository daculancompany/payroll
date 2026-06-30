<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">Work Schedules</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                                <li class="breadcrumb-item active">Work Schedules</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <h4 class="card-title mb-0 flex-grow-1">
                            <i class="ri-time-line me-2 text-success"></i>Shift List
                        </h4>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modal-schedule">
                            <i class="ri-add-line me-1"></i>Add Shift
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="sched-table" class="table table-hover table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Description</th>
                                        <th class="text-center">Start</th>
                                        <th class="text-center">End</th>
                                        <th class="text-center">Hours</th>
                                        <th class="text-center">Graveyard</th>
                                        <th class="text-center">NSD</th>
                                        <th class="text-center">NSD Rate</th>
                                        <th class="text-center" style="width:100px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = $conn->query("SELECT * FROM work_schedules WHERE status=1 ORDER BY start_time ASC");
                                    while ($row = $q->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['description']) ?></strong></td>
                                        <td class="text-center"><?= date('h:i A', strtotime($row['start_time'])) ?></td>
                                        <td class="text-center"><?= date('h:i A', strtotime($row['end_time'])) ?></td>
                                        <td class="text-center"><span class="badge bg-success"><?= $row['total_hours'] ?> hrs</span></td>
                                        <td class="text-center">
                                            <?php if ($row['is_graveyard']): ?>
                                                <span class="badge bg-dark"><i class="ri-moon-line me-1"></i>Yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $row['has_nsd'] ? '<span class="badge bg-info">Yes</span>' : '<span class="badge bg-light text-muted">No</span>' ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $row['has_nsd'] ? '<span class="text-info fw-bold">' . ($row['nsd_rate'] * 100) . '%</span>' : '—' ?>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary me-1"
                                                onclick="editSchedule(<?= htmlspecialchars(json_encode($row)) ?>)">
                                                <i class="ri-edit-line"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger"
                                                onclick="deleteSchedule(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['description'])) ?>')">
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

<!-- Add / Edit Modal -->
<div class="modal fade" id="modal-schedule" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-schedule">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="sched-modal-title">
                        <i class="ri-time-line me-2" style="color:#009688;"></i>Add Shift
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="sched-id" name="id" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sched-desc" name="description"
                               placeholder="e.g. Morning Shift, Night Shift" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="sched-start" name="start_time" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">End Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="sched-end" name="end_time" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Hours</label>
                            <input type="number" class="form-control" id="sched-hours" name="total_hours"
                                   value="8" min="1" max="24" step="0.5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Break (minutes)</label>
                            <input type="number" class="form-control" id="sched-break" name="break_minutes"
                                   value="60" min="0" max="120" step="5">
                            <small class="text-muted">Subtracted from raw hours</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Graveyard Shift?</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="is_graveyard" id="grav-yes" value="1">
                                    <label class="form-check-label" for="grav-yes"><i class="ri-moon-line me-1"></i>Yes</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="is_graveyard" id="grav-no" value="0" checked>
                                    <label class="form-check-label" for="grav-no">No</label>
                                </div>
                            </div>
                            <small class="text-muted">Shift crosses midnight (e.g. 10PM–6AM)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Night Shift Differential?</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="has_nsd" id="nsd-yes" value="1"
                                           onchange="toggleNsdRate(true)">
                                    <label class="form-check-label" for="nsd-yes">Yes</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="has_nsd" id="nsd-no" value="0" checked
                                           onchange="toggleNsdRate(false)">
                                    <label class="form-check-label" for="nsd-no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="nsd-rate-group" style="display:none;">
                        <label class="form-label fw-semibold">Night Shift Differential Rate</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="sched-nsd" name="nsd_rate"
                                   value="0.10" min="0" max="1" step="0.01">
                            <span class="input-group-text">decimal (e.g. 0.10 = 10%)</span>
                        </div>
                        <small class="text-muted">10% NSD = enter 0.10</small>
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
    if (window.jQuery && jQuery.fn.DataTable && !jQuery.fn.DataTable.isDataTable('#sched-table')) {
        jQuery('#sched-table').DataTable({
            order: [[1, 'asc']],
            pageLength: 25,
            columnDefs: [{ orderable: false, targets: 7 }],
            language: { search: '', searchPlaceholder: 'Search shifts…' }
        });
    }
});

function toggleNsdRate(show) {
    document.getElementById('nsd-rate-group').style.display = show ? '' : 'none';
}

function editSchedule(row) {
    document.getElementById('sched-id').value        = row.id;
    document.getElementById('sched-desc').value      = row.description;
    document.getElementById('sched-start').value     = row.start_time;
    document.getElementById('sched-end').value       = row.end_time;
    document.getElementById('sched-hours').value     = row.total_hours;
    document.getElementById('sched-break').value     = row.break_minutes;
    document.getElementById('sched-nsd').value       = row.nsd_rate;
    document.querySelector('input[name="is_graveyard"][value="' + row.is_graveyard + '"]').checked = true;
    document.querySelector('input[name="has_nsd"][value="' + row.has_nsd + '"]').checked = true;
    toggleNsdRate(row.has_nsd == 1);
    document.getElementById('sched-modal-title').innerHTML =
        '<i class="ri-time-line me-2" style="color:#009688;"></i>Edit Shift';
    new bootstrap.Modal(document.getElementById('modal-schedule')).show();
}

document.getElementById('modal-schedule').addEventListener('hidden.bs.modal', function () {
    document.getElementById('form-schedule').reset();
    document.getElementById('sched-id').value = '';
    document.getElementById('nsd-rate-group').style.display = 'none';
    document.getElementById('sched-modal-title').innerHTML =
        '<i class="ri-time-line me-2" style="color:#009688;"></i>Add Shift';
});

document.getElementById('form-schedule').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = new FormData(this);
    const res  = await fetch('ajax.php?action=save_work_schedule', { method: 'POST', body: new URLSearchParams(data) });
    const json = await res.json();
    if (json?.result) {
        bootstrap.Modal.getInstance(document.getElementById('modal-schedule')).hide();
        location.reload();
    } else {
        alert(json?.message || 'Failed to save.');
    }
});

async function deleteSchedule(id, name) {
    if (!confirm('Delete shift "' + name + '"?')) return;
    const res  = await fetch('ajax.php?action=delete_work_schedule', { method: 'POST', body: new URLSearchParams({ id }) });
    const json = await res.json();
    if (json?.result) location.reload();
    else alert(json?.message || 'Failed to delete.');
}
</script>
