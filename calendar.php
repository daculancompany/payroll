<?php
// Admin (1) and HR (9) maintain the holiday calendar. Supervisors and
// Department Heads can reach this page from their leave-only menu, but the
// calendar is org-wide policy — they read it, they do not edit it.
$can_edit_cal = in_array((int)($_SESSION['login_role'] ?? 0), [1, 8, 9])
             && !is_leave_approver();
?>
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
                                    $up = $conn->query("SELECT * FROM calendar_events WHERE YEAR(start_date) = YEAR(CURDATE()) AND COALESCE(end_date,start_date) >= CURDATE() ORDER BY start_date ASC LIMIT 30");
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
                    <h6 class="modal-title" id="event-modal-title"><i class="ri-calendar-event-line me-2" style="color:#673bb6;"></i>Add Event</h6>
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
                            <option value="1">🔴 Legal Holiday (200% pay)</option>
                            <option value="3">🟡 Special Holiday (130% pay)</option>
                            <option value="2">📌 Activity (informational)</option>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="ev-start" name="start_date" placeholder="YYYY-MM-DD" autocomplete="off" required>
                                <span class="input-group-text ev-cal-btn" data-for="start" role="button"><i class="ri-calendar-line"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date <span class="text-muted">(optional)</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="ev-end" name="end_date" placeholder="YYYY-MM-DD" autocomplete="off">
                                <span class="input-group-text ev-cal-btn" data-for="end" role="button"><i class="ri-calendar-line"></i></span>
                            </div>
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
                <div class="modal-footer justify-content-between">
                    <!-- Editing only: clicking an event on the calendar opens this
                         modal, and deleting from here saves hunting for the same
                         event in the Upcoming list (which only reaches 30 days). -->
                    <button type="button" class="btn btn-sm btn-outline-danger" id="ev-delete" style="display:none;">
                        <i class="ri-delete-bin-line me-1"></i>Delete
                    </button>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-success"><i class="ri-save-line me-1"></i>Save</button>
                    </div>
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

    // ── Date fields ─────────────────────────────────────────────────────
    // The same bootstrap-datetimepicker the rest of the admin panel uses,
    // in place of the browser's native <input type="date"> (whose look and
    // wording follow the OS, not the app). index.php loads jQuery / moment /
    // the plugin *after* this page's markup, so wait for them the way the
    // calendar itself waits for FullCalendar.
    var evPickers = null;
    function initDatePickers() {
        if (!window.jQuery || !window.moment || !jQuery.fn.datetimepicker) { setTimeout(initDatePickers, 150); return; }
        var opts = {
            format: 'YYYY-MM-DD',
            useCurrent: false,
            showClear: true,
            showClose: true,
            showTodayButton: true,
            allowInputToggle: true,                                          // tapping the field opens it, not just the icon
            widgetPositioning: { horizontal: 'auto', vertical: 'bottom' },   // keep the month header reachable inside the modal
            icons: {
                date: 'ri-calendar-line', time: 'ri-time-line',
                up: 'ri-arrow-up-s-line', down: 'ri-arrow-down-s-line',
                previous: 'ri-arrow-left-s-line', next: 'ri-arrow-right-s-line',
                today: 'ri-focus-3-line', clear: 'ri-eraser-line', close: 'ri-check-line'
            }
        };
        var $s = jQuery('#ev-start').datetimepicker(opts),
            $e = jQuery('#ev-end').datetimepicker(opts);
        // A range can't run backwards — each end caps the other's reachable days.
        $s.on('dp.change', function (x) { $e.data('DateTimePicker').minDate(x.date || false); });
        $e.on('dp.change', function (x) { $s.data('DateTimePicker').maxDate(x.date || false); });
        evPickers = { start: $s.data('DateTimePicker'), end: $e.data('DateTimePicker') };

        // The plugin only wires up an addon when it owns the whole input-group
        // (and it looks for Bootstrap 3's .input-group-addon), so the calendar
        // buttons are toggled by hand.
        document.querySelectorAll('.ev-cal-btn').forEach(function (b) {
            b.addEventListener('click', function () { evPickers[this.getAttribute('data-for')].toggle(); });
        });
    }
    initDatePickers();

    function setEvDate(which, val) {
        var el = document.getElementById(which === 'start' ? 'ev-start' : 'ev-end');
        if (evPickers) evPickers[which].date(val ? moment(val, 'YYYY-MM-DD') : null);
        else el.value = val || '';                                           // plugin still loading — plain text is a valid fallback
    }

    function openModal(ev) {
        document.getElementById('ev-id').value = ev.id || '';
        document.getElementById('ev-title').value = ev.title || '';
        document.getElementById('ev-type').value = ev.type || 1;
        // Drop the previous event's range limits first, or they clamp the dates
        // being loaded now.
        if (evPickers) { evPickers.start.maxDate(false); evPickers.end.minDate(false); }
        setEvDate('start', ev.start_date);
        setEvDate('end', ev.end_date);
        document.getElementById('ev-blocks').checked = (ev.blocks_leave == 1);
        document.getElementById('ev-note').value = ev.note || '';
        document.getElementById('event-modal-title').innerHTML = (ev.id ? '<i class="ri-edit-line me-2" style="color:#673bb6;"></i>Edit Event' : '<i class="ri-calendar-event-line me-2" style="color:#673bb6;"></i>Add Event');
        // Delete belongs to an existing event only — there is nothing to remove
        // while adding one.
        const delBtn = document.getElementById('ev-delete');
        if (delBtn) {
            delBtn.style.display = ev.id ? '' : 'none';
            delBtn.dataset.id = ev.id || '';
        }
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
        // The fields are typable as well as pickable, so free text has to be
        // caught here — the server only ever accepts YYYY-MM-DD.
        const isDate = s => /^\d{4}-\d{2}-\d{2}$/.test(s) && (!window.moment || moment(s, 'YYYY-MM-DD', true).isValid());
        const sd = document.getElementById('ev-start').value.trim();
        const ed = document.getElementById('ev-end').value.trim();
        if (!isDate(sd) || (ed && !isDate(ed))) {
            Swal.fire({ icon: 'warning', title: 'Check the dates', text: 'Pick the dates from the calendar (format YYYY-MM-DD).' });
            return;
        }
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

    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Holiday dates are read by the payroll calculation itself — they set each
    // employee's holiday day count and keep monthly staff from being marked
    // absent for a paid non-working day. Deleting one is therefore a PAY change,
    // not just a calendar edit, so the confirmation names the runs it reaches
    // rather than warning in the abstract.
    window.delEvent = async function (id) {
        let impact = null;
        try {
            const r = await fetch('ajax.php?action=calendar_event_impact', {
                method: 'POST', body: new URLSearchParams({ id })
            });
            impact = await r.json();
        } catch (e) { /* fall back to the generic warning below */ }

        let html = '<p class="mb-2">This removes the event from the calendar.</p>';

        if (impact && impact.result && impact.is_holiday) {
            html = '<div class="text-start" style="font-size:13.5px;">'
                 + '<p class="mb-2"><b>' + esc(impact.title) + '</b> is a holiday, and payroll reads this '
                 + 'calendar directly.</p>'
                 + '<p class="mb-2">Deleting it means that on the next <b>Recalculate</b>:</p>'
                 + '<ul class="mb-2 ps-3">'
                 + '<li>holiday pay for these dates is removed from every affected employee;</li>'
                 + '<li>monthly-rate staff who did not work the day become <b>absent</b> for it.</li>'
                 + '</ul>';

            const ps = impact.payrolls || [];
            if (ps.length) {
                html += '<p class="mb-1">Payroll periods covering these dates:</p><ul class="mb-2 ps-3">';
                ps.forEach(function (p) {
                    html += '<li>' + esc(p.ref_no) + ' <span class="text-muted">(' + esc(p.period) + ')</span>'
                          + (p.locked ? ' <span class="badge bg-danger">LOCKED</span>' : '')
                          + (p.paid ? ' — <b>' + p.paid + '</b> employee(s) currently paid holiday here' : '')
                          + '</li>';
                });
                html += '</ul>';
                if (impact.locked) {
                    html += '<div class="alert alert-danger py-2 px-2 mb-0" style="font-size:12.5px;">'
                          + '<i class="ri-lock-2-line me-1"></i>A <b>locked</b> payroll above was already paid '
                          + 'using this holiday. Deleting it will not undo that payment, but the calendar will '
                          + 'no longer explain it.</div>';
                }
            } else {
                html += '<p class="text-muted mb-0" style="font-size:12.5px;">No payroll period covers these dates yet.</p>';
            }
            html += '</div>';
        }

        const c = await Swal.fire({
            title: 'Delete this event?',
            html: html,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33',
            reverseButtons: true,
            width: (impact && impact.is_holiday) ? 560 : undefined
        });
        if (!c.isConfirmed) return;

        const res = await fetch('ajax.php?action=delete_calendar_event', { method: 'POST', body: new URLSearchParams({ id }) });
        const j = await res.json();
        if (j && j.result) Swal.fire({ icon: 'success', title: 'Deleted', timer: 900, showConfirmButton: false }).then(() => location.reload());
        else Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'Failed.' });
    };

    // Modal delete → same confirmation, then close the modal behind it.
    (function () {
        const delBtn = document.getElementById('ev-delete');
        if (!delBtn) return;
        delBtn.addEventListener('click', function () {
            const id = this.dataset.id;
            if (!id) return;
            const m = bootstrap.Modal.getInstance(document.getElementById('modal-event'));
            if (m) m.hide();
            window.delEvent(id);
        });
    })();
})();
</script>
