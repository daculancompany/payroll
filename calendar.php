<?php $can_edit_cal = in_array((int)($_SESSION['login_role'] ?? 0), [1, 8, 9]); ?>
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Holiday Calendar</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Leave Management</a></li>
                                <li class="breadcrumb-item active">Calendar</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <div class="col-xl-8">
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h5 class="card-title mb-0 flex-grow-1"><i class="ri-calendar-2-line me-2 text-success"></i>Calendar</h5>
                            <span class="badge me-2" style="background:#dc3545;">🛑 Holiday (blocks leave)</span>
                            <span class="badge" style="background:#0d6efd;">📌 Activity</span>
                            <?php if ($can_edit_cal): ?>
                            <button class="btn btn-success btn-sm ms-2" id="cal-add"><i class="ri-add-line me-1"></i>Add Event</button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="ri-list-check-2 me-2 text-success"></i>Upcoming</h5></div>
                        <div class="card-body p-0" style="max-height:520px;overflow-y:auto;">
                            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                                <tbody>
                                    <?php
                                    $up = $conn->query("SELECT * FROM calendar_events WHERE COALESCE(end_date,start_date) >= CURDATE() ORDER BY start_date ASC LIMIT 30");
                                    if ($up && $up->num_rows) while ($e = $up->fetch_assoc()):
                                        $isHol = $e['type'] == 1;
                                        $range = date('M d, Y', strtotime($e['start_date'])) . ($e['end_date'] && $e['end_date'] != $e['start_date'] ? ' – ' . date('M d, Y', strtotime($e['end_date'])) : '');
                                    ?>
                                    <tr>
                                        <td style="width:6px;background:<?= htmlspecialchars($e['color']) ?>;padding:0;"></td>
                                        <td>
                                            <div style="font-weight:700;"><?= $isHol ? '🛑' : '📌' ?> <?= htmlspecialchars($e['title']) ?></div>
                                            <div class="text-muted" style="font-size:11px;"><i class="ri-calendar-line me-1"></i><?= $range ?>
                                                <?php if ($e['blocks_leave']): ?><span class="badge bg-danger-subtle text-danger ms-1">No leave</span><?php endif; ?>
                                            </div>
                                            <?php if ($e['note']): ?><div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($e['note']) ?></div><?php endif; ?>
                                        </td>
                                        <?php if ($can_edit_cal): ?>
                                        <td class="text-end" style="white-space:nowrap;">
                                            <button class="btn btn-sm btn-outline-primary cal-edit" data-ev='<?= json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'><i class="ri-edit-line"></i></button>
                                            <button class="btn btn-sm btn-outline-danger cal-del" data-id="<?= $e['id'] ?>"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if (!$up || !$up->num_rows): ?>
                                    <tr><td class="text-center text-muted py-4">No upcoming events.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($can_edit_cal): ?>
<!-- Event Modal -->
<div class="modal fade" id="modal-event" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-event">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="event-modal-title"><i class="ri-calendar-event-line me-2" style="color:#009688;"></i>Add Event</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ev-id" name="id" value="">
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="ev-title" name="title" placeholder="e.g. Christmas Day" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select class="form-control" id="ev-type" name="type">
                            <option value="1">🛑 Holiday (blocks leave)</option>
                            <option value="2">📌 Activity (informational)</option>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="ev-start" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-muted">(optional)</span></label>
                            <input type="date" class="form-control" id="ev-end" name="end_date">
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="ev-blocks" name="blocks_leave" value="1" checked>
                        <label class="form-check-label" for="ev-blocks">Block leave applications on these dates</label>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Note</label>
                        <input type="text" class="form-control" id="ev-note" name="note" placeholder="Optional">
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
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
(function () {
    const canEdit = <?= $can_edit_cal ? 'true' : 'false' ?>;

    function initCalendar() {
        const el = document.getElementById('calendar');
        if (!el || typeof FullCalendar === 'undefined') { setTimeout(initCalendar, 150); return; }
        const cal = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            height: 620,
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
            events: 'ajax.php?action=get_calendar_events',
            eventClick: function (info) {
                if (!canEdit) return;
                const p = info.event.extendedProps;
                openModal({
                    id: info.event.id, title: p.raw_title, type: p.type,
                    start_date: p.raw_start, end_date: p.raw_end || '', blocks_leave: p.blocks_leave, note: p.note || ''
                });
            }
        });
        cal.render();
        window.__cal = cal;
    }
    initCalendar();

    if (!canEdit) return;

    function openModal(ev) {
        document.getElementById('ev-id').value = ev.id || '';
        document.getElementById('ev-title').value = ev.title || '';
        document.getElementById('ev-type').value = ev.type || 1;
        document.getElementById('ev-start').value = ev.start_date || '';
        document.getElementById('ev-end').value = ev.end_date || '';
        document.getElementById('ev-blocks').checked = (ev.blocks_leave == 1);
        document.getElementById('ev-note').value = ev.note || '';
        document.getElementById('event-modal-title').innerHTML = (ev.id ? '<i class="ri-edit-line me-2" style="color:#009688;"></i>Edit Event' : '<i class="ri-calendar-event-line me-2" style="color:#009688;"></i>Add Event');
        new bootstrap.Modal(document.getElementById('modal-event')).show();
    }
    window.__openEvModal = openModal;

    document.getElementById('cal-add').addEventListener('click', function () {
        openModal({ blocks_leave: 1, type: 1 });
    });

    // Default blocks_leave to match type when type changes
    document.getElementById('ev-type').addEventListener('change', function () {
        document.getElementById('ev-blocks').checked = (this.value === '1');
    });

    document.querySelectorAll('.cal-edit').forEach(function (b) {
        b.addEventListener('click', function () {
            const e = JSON.parse(this.getAttribute('data-ev'));
            openModal({ id: e.id, title: e.title, type: e.type, start_date: e.start_date, end_date: e.end_date || '', blocks_leave: e.blocks_leave, note: e.note || '' });
        });
    });
    document.querySelectorAll('.cal-del').forEach(function (b) {
        b.addEventListener('click', function () { delEvent(this.getAttribute('data-id')); });
    });

    document.getElementById('form-event').addEventListener('submit', async function (e) {
        e.preventDefault();
        const data = new URLSearchParams(new FormData(this));
        if (!document.getElementById('ev-blocks').checked) data.set('blocks_leave', '0');
        const res = await fetch('ajax.php?action=save_calendar_event', { method: 'POST', body: data });
        const j = await res.json();
        if (j && j.result) {
            bootstrap.Modal.getInstance(document.getElementById('modal-event')).hide();
            Swal.fire({ icon: 'success', title: 'Saved', text: j.message, timer: 1100, showConfirmButton: false }).then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed to save.' });
        }
    });

    window.delEvent = async function (id) {
        const c = await Swal.fire({ title: 'Delete this event?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#d33' });
        if (!c.isConfirmed) return;
        const res = await fetch('ajax.php?action=delete_calendar_event', { method: 'POST', body: new URLSearchParams({ id }) });
        const j = await res.json();
        if (j && j.result) Swal.fire({ icon: 'success', title: 'Deleted', timer: 900, showConfirmButton: false }).then(() => location.reload());
        else Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed.' });
    };
})();
</script>
