<?php
$my_role   = (int)($_SESSION['login_role'] ?? 0);
$can_edit  = in_array($my_role, [1, 8, 9], true);

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_site = intval($_GET['site_id'] ?? 0);

// Load sites for filter
$sites = [];
$sq = $conn->query("SELECT id, site_name, site_code FROM sites WHERE status=1 ORDER BY site_name ASC");
if ($sq) while ($r = $sq->fetch_assoc()) $sites[] = $r;

// Load pay_settings
$ps = [];
$psq = $conn->query("SELECT setting_key, setting_value FROM pay_settings");
if ($psq) while ($r = $psq->fetch_assoc()) $ps[$r['setting_key']] = (float)$r['setting_value'];
$ps += ['legal_holiday_rate'=>2.0,'special_holiday_rate'=>1.3,'ot_regular_rate'=>1.25,'ot_holiday_multiplier'=>1.3,'nsd_rate'=>0.1];

// Check if filter_date is a holiday
$hol_row = $conn->query("SELECT title, type FROM calendar_events WHERE '$filter_date' BETWEEN start_date AND COALESCE(end_date, start_date) AND type IN (1,3) ORDER BY type ASC LIMIT 1")->fetch_assoc();
$hol_type  = $hol_row ? (int)$hol_row['type'] : 0;
$hol_title = $hol_row ? $hol_row['title'] : '';

// Build query
$where = "AND DATE(d.date_time) = '$filter_date'";
if ($filter_site) $where .= " AND dt.site_id = $filter_site";

$records = $conn->query("
    SELECT d.*, e.firstname, e.lastname, e.employee_no,
           ws.description AS shift_name, ws.start_time AS shift_start, ws.end_time AS shift_end,
           s.site_name
    FROM DTR_details d
    INNER JOIN employee e ON e.id = d.employee_id
    LEFT JOIN DTR dt ON dt.id = d.ddtr_id
    LEFT JOIN sites s ON s.id = dt.site_id
    LEFT JOIN employee_schedules es ON es.employee_id = d.employee_id
        AND es.effective_from <= '$filter_date'
        AND (es.effective_to IS NULL OR es.effective_to >= '$filter_date')
    LEFT JOIN work_schedules ws ON ws.id = es.schedule_id
    WHERE d.attendance_type IN ('biometric','incident','manual')
    $where
    ORDER BY d.is_complete ASC, e.lastname ASC, e.firstname ASC
");

$dayBadge = [
    'regular'         => ['Regular Day', 'bg-secondary'],
    'legal_holiday'   => ['Legal Holiday 🔴', 'bg-danger'],
    'special_holiday' => ['Special Holiday 🟡', 'bg-warning text-dark'],
];
?>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                        <h4 class="mb-sm-0">
                            DTR Review
                            <?php if ($hol_type): ?>
                                <span class="badge <?= $hol_type==1 ? 'bg-danger' : 'bg-warning text-dark' ?> ms-2">
                                    <?= $hol_type==1 ? '🔴 Legal Holiday' : '🟡 Special Holiday' ?> — <?= htmlspecialchars($hol_title) ?>
                                    (<?= $hol_type==1 ? ($ps['legal_holiday_rate']*100).'%' : ($ps['special_holiday_rate']*100).'%' ?>)
                                </span>
                            <?php endif; ?>
                        </h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Attendance</a></li>
                                <li class="breadcrumb-item active">DTR Review</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Filter -->
                <div class="col-12 mb-3">
                    <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
                        <input type="hidden" name="page" value="biometric-dtr">
                        <div>
                            <label class="form-label mb-1 fw-semibold" style="font-size:12px;">Date</label>
                            <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                        </div>
                        <div>
                            <label class="form-label mb-1 fw-semibold" style="font-size:12px;">Site</label>
                            <select name="site_id" class="form-select form-select-sm" style="min-width:160px;">
                                <option value="0">All Sites</option>
                                <?php foreach ($sites as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filter_site==$s['id']?'selected':'' ?>>
                                    <?= htmlspecialchars($s['site_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm"><i class="ri-search-line me-1"></i>Filter</button>
                        <a href="?page=biometric-dtr&date=<?= date('Y-m-d', strtotime($filter_date.' -1 day')) ?>" class="btn btn-outline-secondary btn-sm"><i class="ri-arrow-left-s-line"></i> Prev</a>
                        <a href="?page=biometric-dtr&date=<?= date('Y-m-d', strtotime($filter_date.' +1 day')) ?>" class="btn btn-outline-secondary btn-sm">Next <i class="ri-arrow-right-s-line"></i></a>
                    </form>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered align-middle" style="font-size:13px;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Shift</th>
                                            <th class="text-center">Time In</th>
                                            <th class="text-center">Time Out</th>
                                            <th class="text-center">Work Hrs</th>
                                            <th class="text-center">OT Hrs</th>
                                            <th class="text-center">NSD Hrs</th>
                                            <th class="text-center">Late</th>
                                            <th class="text-center">Day Type</th>
                                            <th class="text-center">Status</th>
                                            <?php if ($can_edit): ?><th class="text-center" style="width:120px;">Action</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!$records || $records->num_rows === 0): ?>
                                        <tr><td colspan="11" class="text-center text-muted py-4"><i class="ri-calendar-line me-2"></i>No attendance records for this date.</td></tr>
                                    <?php else: while ($row = $records->fetch_assoc()):
                                        $logs    = json_decode($row['logs'] ?? '[]', true) ?? [];
                                        $times   = array_map(function($l){ return strtotime($l['dateTime']); }, $logs);
                                        $time_in  = !empty($times) ? date('h:i A', min($times)) : '—';
                                        $time_out = count($times) > 1 ? date('h:i A', max($times)) : '—';
                                        [$dtLabel, $dtClass] = $dayBadge[$row['day_type'] ?? 'regular'] ?? ['Regular','bg-secondary'];
                                    ?>
                                        <tr class="<?= !$row['is_complete'] ? 'table-warning' : '' ?>">
                                            <td>
                                                <b><?= htmlspecialchars($row['lastname'].', '.$row['firstname']) ?></b>
                                                <div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($row['employee_no']) ?></div>
                                            </td>
                                            <td style="font-size:11px;">
                                                <?= htmlspecialchars($row['shift_name'] ?? '—') ?>
                                                <?php if ($row['shift_start']): ?>
                                                <div class="text-muted"><?= date('h:i A', strtotime($row['shift_start'])) ?> – <?= date('h:i A', strtotime($row['shift_end'])) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= $time_in ?></td>
                                            <td class="text-center">
                                                <?php if ($time_out === '—'): ?>
                                                    <span class="badge bg-warning text-dark">No Out</span>
                                                <?php else: ?>
                                                    <?= $time_out ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><b><?= $row['work_hours'] > 0 ? $row['work_hours'].'h' : '—' ?></b></td>
                                            <td class="text-center">
                                                <?= $row['overtime'] > 0 ? '<span class="text-warning fw-bold">'.$row['overtime'].'h</span>' : '—' ?>
                                            </td>
                                            <td class="text-center">
                                                <?= $row['nsd_hours'] > 0 ? '<span class="text-info fw-bold">'.$row['nsd_hours'].'h</span>' : '—' ?>
                                            </td>
                                            <td class="text-center">
                                                <?= $row['late'] > 0 ? '<span class="text-danger">'.((int)round($row['late']*60)).'m</span>' : '—' ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $dtClass ?> rounded-pill" style="font-size:10px;"><?= $dtLabel ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($row['is_complete']): ?>
                                                    <span class="badge bg-success"><i class="ri-checkbox-circle-line me-1"></i>Complete</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="ri-error-warning-line me-1"></i>Incomplete</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($can_edit): ?>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-primary" title="Edit"
                                                    onclick='openEdit(<?= json_encode([
                                                        "id"=>$row["id"],
                                                        "time_in"=>count($times)>0?date("H:i",min($times)):"",
                                                        "time_out"=>count($times)>1?date("H:i",max($times)):"",
                                                        "name"=>$row["lastname"].', '.$row["firstname"]
                                                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                                    <i class="ri-edit-line"></i>
                                                </button>
                                                <?php if (!$row['is_complete']): ?>
                                                <button class="btn btn-sm btn-outline-success" title="Mark Complete"
                                                    onclick="finalizeRecord(<?= $row['id'] ?>)">
                                                    <i class="ri-check-double-line"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" title="Mark Absent"
                                                    onclick="markAbsent(<?= $row['id'] ?>)">
                                                    <i class="ri-user-unfollow-line"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Time Modal -->
<div class="modal fade" id="modal-edit-time" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-edit-time">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="ri-edit-line me-2 text-success"></i>Edit Attendance Time</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit-id" name="id">
                    <input type="hidden" id="edit-date" name="date" value="<?= $filter_date ?>">
                    <p class="text-muted mb-3" id="edit-name" style="font-size:13px;"></p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Time In</label>
                            <input type="time" class="form-control" id="edit-time-in" name="time_in" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Time Out</label>
                            <input type="time" class="form-control" id="edit-time-out" name="time_out">
                        </div>
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
function openEdit(row) {
    document.getElementById('edit-id').value       = row.id;
    document.getElementById('edit-time-in').value  = row.time_in;
    document.getElementById('edit-time-out').value = row.time_out;
    document.getElementById('edit-name').textContent = row.name;
    new bootstrap.Modal(document.getElementById('modal-edit-time')).show();
}

document.getElementById('form-edit-time').addEventListener('submit', async function(e) {
    e.preventDefault();
    const res  = await fetch('ajax.php?action=edit_dtr_time', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const json = await res.json();
    if (json?.result) { location.reload(); }
    else alert(json?.message || 'Failed to save.');
});

async function finalizeRecord(id) {
    const res  = await fetch('ajax.php?action=finalize_dtr', { method: 'POST', body: new URLSearchParams({ id }) });
    const json = await res.json();
    if (json?.result) location.reload();
    else alert(json?.message || 'Failed.');
}

async function markAbsent(id) {
    if (!confirm('Mark this record as absent? It will be excluded from payroll.')) return;
    const res  = await fetch('ajax.php?action=delete_dtr_record', { method: 'POST', body: new URLSearchParams({ id }) });
    const json = await res.json();
    if (json?.result) location.reload();
    else alert(json?.message || 'Failed.');
}
</script>
