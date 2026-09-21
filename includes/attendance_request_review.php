<?php
/**
 * Shared "Review request" modal — the one place an approver decides an
 * attendance request.
 *
 * Two screens need it and they hold different amounts of the row: the requests
 * queue (attendance-requests.php) has every field on the <tr>, while a DTR
 * record card (dtr-documents.php) knows only that some request covers this
 * date. Rather than build the form twice — and let the two drift on which
 * fields are editable, which ceiling is enforced, or whether the employee is
 * told what changed — both open THIS modal, which reads the request itself
 * from get_attendance_request.
 *
 * Usage (after including this file once per page):
 *
 *     AttReqReview.open(requestId, {
 *         onSaved:   function (req) { ... },   // edits saved, still pending
 *         onDecided: function (status, req) { ... },  // 1 approved, 2 rejected
 *     });
 *
 * Both callbacks are optional — they exist so each page can repaint its own
 * view in place instead of reloading.
 *
 * Requires (already global on admin pages): Bootstrap 5, SweetAlert2.
 */
if (!defined('ATT_REQ_REVIEW_RENDERED')) {
    define('ATT_REQ_REVIEW_RENDERED', true);
    require_once __DIR__ . '/../db_connect.php';

    // Same labels the queue and the portal print, kept in one place here so the
    // modal's dropdown can never offer a reason the lists cannot name.
    $__arr_reasons = [
        'forgot_scan'   => 'Forgot to Scan',
        'device_error'  => 'Device/Scanner Error',
        'system_down'   => 'System Down',
        'overtime'      => 'Overtime Authorization',
        'rest_day_work' => 'Rest Day / Day-Off Work',
        'undertime'     => 'Undertime / Early Out',
        'other'         => 'Other',
    ];
?>
<div class="modal fade" id="arr-modal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="ri-file-search-line me-1"></i>Review request</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="arr-id">

        <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
          <span id="arr-emp" class="fw-semibold"></span>
          <span id="arr-type"></span>
          <span class="text-muted small"><i class="ri-calendar-event-line me-1"></i><span id="arr-date"></span></span>
        </div>
        <div class="text-muted mb-2" style="font-size:11px;">Filed <span id="arr-filed"></span></div>

        <!-- Where the approval chain stands (same stages as leave) and whether it
             is THIS user's turn — the buttons below follow it. -->
        <div id="arr-chain" class="small mb-2 p-2 rounded border" style="line-height:1.5;background:#faf9fd;border-color:#e7e3f2 !important;"></div>

        <!-- What the record behind the request actually says (ot_request_limit). -->
        <div id="arr-limit" class="small mb-2" style="line-height:1.4;"></div>

        <div class="mb-2">
          <label class="form-label small mb-1">Reason</label>
          <select id="arr-reason" class="form-select form-select-sm">
            <?php foreach ($__arr_reasons as $rk => $rv): ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rv) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row g-2 arr-incident d-none">
          <div class="col-6">
            <label class="form-label small mb-1">Claimed time in</label>
            <input type="time" id="arr-in" class="form-control form-control-sm">
          </div>
          <div class="col-6">
            <label class="form-label small mb-1">Claimed time out</label>
            <input type="time" id="arr-out" class="form-control form-control-sm">
          </div>
        </div>

        <div class="arr-hours d-none">
          <label class="form-label small mb-1" id="arr-hours-label">Hours</label>
          <input type="number" id="arr-hours" class="form-control form-control-sm" min="<?= OT_REQUEST_MIN_HOURS ?>" step="<?= OT_REQUEST_STEP_HOURS ?>">
          <div class="form-text" style="font-size:11px;">Adjust it to what you are authorizing — the employee is told what changed.</div>
          <!-- What the employee said about WHEN. Read-only on purpose: it is
               their account of the day, not a figure the approver authorizes,
               and saveEdits() deliberately has no field for it. -->
          <div id="arr-ot-start" class="form-text d-none" style="font-size:11px;"></div>
        </div>

        <div class="mt-2">
          <label class="form-label small mb-1">Notes</label>
          <textarea id="arr-notes" class="form-control form-control-sm" rows="2"></textarea>
        </div>

        <div id="arr-attach" class="mt-2 small"></div>
        <div id="arr-decided" class="mt-2 small text-muted d-none"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-danger"  id="arr-reject"><i class="ri-close-line me-1"></i>Reject</button>
        <button type="button" class="btn btn-sm btn-success" id="arr-approve"><i class="ri-check-double-line me-1"></i>Approve</button>
      </div>
    </div>
  </div>
</div>

<script>
window.AttReqReview = (function () {
    var TYPE_BADGE = {
        incident: '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="ri-error-warning-line me-1"></i>Incident</span>',
        rest_day: '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="ri-moon-line me-1"></i>Rest Day</span>',
        overtime: '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="ri-timer-flash-line me-1"></i>Overtime</span>',
        undertime: '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="ri-logout-box-r-line me-1"></i>Undertime</span>'
    };
    var HOUR_TYPES = ['overtime', 'rest_day', 'undertime'];
    var cur = null, cbs = {}, modal = null, seq = 0;

    function $id(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Open by id: the caller may hold the whole row or nothing but the id, so
    // the request is always read fresh — and that also means a request decided
    // in another tab opens read-only here instead of being decided twice.
    function open(id, callbacks) {
        cbs = callbacks || {};
        var mySeq = ++seq;
        fetch('ajax.php?action=get_attendance_request', {
            method: 'POST', body: new URLSearchParams({ id: id })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (mySeq !== seq) return;
            if (!(res && res.result)) {
                return Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not load the request.' });
            }
            fill(res.request);
            modal = new bootstrap.Modal($id('arr-modal'));
            modal.show();
        });
    }

    // One line per stage: verdict icon, stage name, who / when / why.
    function chainHtml(q) {
        var h = '';
        (q.stages || []).forEach(function (s) {
            var ic, col, txt;
            if (s.status === 1 && !s.by && s.remarks) { ic = 'ri-skip-forward-line'; col = '#8a8a99'; txt = 'Skipped'; }
            else if (s.status === 1) { ic = 'ri-checkbox-circle-fill'; col = '#1b8a3e'; txt = 'Approved' + (s.name ? ' · ' + esc(s.name) : '') + (s.at ? ' · ' + esc(s.at) : ''); }
            else if (s.status === 2) { ic = 'ri-close-circle-fill'; col = '#c62828'; txt = 'Rejected' + (s.name ? ' · ' + esc(s.name) : '') + (s.remarks ? ' — ' + esc(s.remarks) : ''); }
            else if (s.key === q.current_stage) { ic = 'ri-time-fill'; col = '#e6a817'; txt = '<b>Awaiting</b>' + ((q.awaiting_names || []).length ? ' · ' + esc(q.awaiting_names.join(' / ')) : ' · <span style="color:#c77700;">no approver assigned</span>'); }
            else { ic = 'ri-checkbox-blank-circle-line'; col = '#c5c5cf'; txt = 'Pending'; }
            h += '<div><i class="' + ic + ' me-1" style="color:' + col + ';"></i><span style="font-weight:700;color:#4e3483;">' + esc(s.label) + '</span> <span class="text-muted">' + txt + '</span></div>';
        });
        if (Number(q.status) === 0 && q.current_stage && !q.can_act) {
            h += '<div class="mt-1" style="color:#8a8a99;"><i class="ri-eye-line me-1"></i>View only — this request is waiting on the ' + esc(q.current_stage_label) + '.</div>';
        }
        return h;
    }

    function fill(q) {
        cur = q;
        var isHours = HOUR_TYPES.indexOf(q.type) !== -1;
        // "pending" here means: still open AND it is this user's turn. Anyone
        // else (Admin, an approver at a later stage) sees it read-only.
        var pending = Number(q.status) === 0 && !!q.can_act;
        $id('arr-chain').innerHTML = chainHtml(q);

        $id('arr-id').value        = q.id;
        $id('arr-emp').textContent = q.employee;
        $id('arr-type').innerHTML  = TYPE_BADGE[q.type] || '';
        $id('arr-date').textContent  = q.date;
        $id('arr-filed').textContent = q.filed_at;

        // Assigning .value alone leaves the custom-select wrapper showing what
        // it last painted — it repaints on a dispatched 'change'.
        var reason = $id('arr-reason');
        reason.value = q.reason || '';
        reason.dispatchEvent(new Event('change', { bubbles: true }));

        $id('arr-notes').value = q.notes || '';
        $id('arr-in').value    = (q.time_in || '').slice(0, 5);
        $id('arr-out').value   = (q.time_out || '').slice(0, 5);
        $id('arr-hours').value = (q.ot_hours === null || q.ot_hours === undefined) ? '' : q.ot_hours;
        var isUt = q.type === 'undertime';
        $id('arr-hours-label').textContent = isUt ? 'Undertime hours to excuse' : (q.type === 'rest_day' ? 'Rest day hours rendered' : 'OT hours');
        $id('arr-hours').min  = isUt ? 0.25 : <?= OT_REQUEST_MIN_HOURS ?>;
        $id('arr-hours').step = isUt ? 0.01 : <?= OT_REQUEST_STEP_HOURS ?>;

        // The window as the employee filed it, shown beneath the hours and worded
        // server-side by att_request_time_span so it reads the same here as in
        // the queue. Never an input — it is context for the decision, not part
        // of it.
        var startBox = $id('arr-ot-start');
        var span = String(q.ot_span || '');
        startBox.classList.toggle('d-none', !(isHours && span));
        startBox.innerHTML = span
            ? '<i class="ri-time-line me-1"></i>Employee filed it as: <b>' + esc(span) + '</b>'
            : '';

        document.querySelector('.arr-incident').classList.toggle('d-none', q.type !== 'incident');
        document.querySelector('.arr-hours').classList.toggle('d-none', !isHours);

        $id('arr-attach').innerHTML = q.attachment
            ? '<i class="ri-attachment-2 me-1"></i><a href="uploads/' + encodeURIComponent(q.attachment) + '" target="_blank" rel="noopener">View attachment</a>'
            : '';

        // A decided request is read-only: it has already written to the DTR, and
        // a silent edit afterwards would leave the record and the request
        // telling different stories. The server refuses it too.
        var decided = $id('arr-decided');
        decided.classList.toggle('d-none', pending);
        decided.innerHTML = pending ? ''
            : (Number(q.status) === 0
                ? '<i class="ri-lock-line me-1"></i>Not your turn — only the ' + esc(q.current_stage_label || 'current approver') + ' can edit or decide it now.'
                : '<i class="ri-lock-line me-1"></i>Already ' + ({1: 'approved', 2: 'rejected', 3: 'cancelled'}[Number(q.status)] || 'decided') + ' — no longer editable.');
        ['arr-reason', 'arr-notes', 'arr-in', 'arr-out', 'arr-hours'].forEach(function (f) { $id(f).disabled = !pending; });
        $id('arr-approve').classList.toggle('d-none', !pending);
        $id('arr-reject').classList.toggle('d-none', !pending);

        var hint = $id('arr-limit');
        hint.innerHTML = '';
        hint.removeAttribute('style');
        if (!isHours) return;

        hint.className = 'small mb-2 text-muted';
        hint.textContent = "Checking the day's scans…";
        // This request is excluded from the day's "already filed" total, so the
        // ceiling shown is what the scans support — not what is left after it.
        fetch('ajax.php?action=attendance_request_limit', {
            method: 'POST',
            body: new URLSearchParams({ employee_id: q.employee_id, request_date: q.date, exclude_id: q.id, request_type: q.type })
        }).then(function (r) { return r.json(); }).then(function (res) {
            var lim = res && res.limit;
            if (!lim || cur !== q) { hint.innerHTML = ''; return; }
            hint.className = 'small mb-2 p-2 rounded border';
            if (lim.allowed) {
                $id('arr-hours').max = lim.max_hours;
                hint.style.background = '#eef6ee'; hint.style.borderColor = '#c6e6c9'; hint.style.color = '#2e7d32';
                if (isUt) {
                    hint.innerHTML = '<i class="ri-fingerprint-line me-1"></i>'
                        + (lim.has_record
                            ? '<b>Their DTR:</b> ' + esc(lim.time_in || '—') + ' &ndash; ' + esc(lim.time_out || '—')
                              + ' · shift ends ' + esc(lim.shift_end) + ' · <b>' + lim.undertime_hours + ' hr</b> undertime'
                            : '<b>No DTR record for this date yet</b> — the excuse is capped at the undertime the scans finally show')
                        + '<br>Up to <b>' + lim.max_hours + ' hr</b> can be excused for this date.';
                } else if (lim.advance) {
                    // Filed before the day was rendered: no scans to show, and
                    // the ceiling is the per-day cap. What is approved here is
                    // what payroll caps the scans' overtime at, never pay on
                    // its own — so the figure is the authorization, not the pay.
                    hint.style.background = '#f5f3f9'; hint.style.borderColor = '#e0dbea'; hint.style.color = '#4e3483';
                    hint.innerHTML = '<i class="ri-calendar-event-line me-1"></i><b>Filed in advance</b> — '
                        + (lim.time_in ? 'time-out not scanned yet' : 'no scans for this date yet')
                        + (lim.rest_day ? ' (rest day)' : ' · shift ends ' + esc(lim.shift_end))
                        + '<br>Up to <b>' + lim.max_hours + ' hr</b> can be authorized; pay is capped at the overtime the scans finally show.';
                } else {
                    hint.innerHTML = '<i class="ri-fingerprint-line me-1"></i><b>Their scans:</b> '
                        + esc(lim.time_in) + ' &ndash; ' + esc(lim.time_out)
                        + ' · <b>' + lim.rendered_hours + ' hrs</b> rendered'
                        + (lim.rest_day ? ' on a rest day' : ' · shift ends ' + esc(lim.shift_end))
                        + '<br>Up to <b>' + lim.max_hours + ' hr</b> can be approved for this date.';
                }
            } else {
                hint.style.background = '#fdeaea'; hint.style.borderColor = '#f7c9c9'; hint.style.color = '#b3261e';
                hint.innerHTML = '<i class="ri-error-warning-line me-1"></i>' + esc(lim.message || '');
            }
        }).catch(function () { hint.innerHTML = ''; });
    }

    // Save whatever the approver changed, if anything. Returns false when the
    // server refuses it — a decision must never proceed on a figure that was
    // rejected (over the day's ceiling, decided elsewhere meanwhile, …).
    function saveEdits() {
        var next = {
            reason: $id('arr-reason').value,
            notes:  $id('arr-notes').value,
            in:     $id('arr-in').value,
            out:    $id('arr-out').value,
            hours:  $id('arr-hours').value
        };
        var dirty = next.reason !== (cur.reason || '')
                 || next.notes  !== (cur.notes || '')
                 || next.in     !== (cur.time_in || '').slice(0, 5)
                 || next.out    !== (cur.time_out || '').slice(0, 5)
                 || String(next.hours) !== String(cur.ot_hours === null || cur.ot_hours === undefined ? '' : cur.ot_hours);
        if (!dirty) return Promise.resolve(true);

        return fetch('ajax.php?action=update_attendance_request', {
            method: 'POST',
            body: new URLSearchParams({
                id: cur.id, reason: next.reason, notes: next.notes,
                claimed_time_in: next.in, claimed_time_out: next.out,
                ot_hours_requested: next.hours
            })
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (!(json && json.result)) {
                Swal.fire({ icon: 'error', title: 'Not saved', text: (json && json.message) || 'Failed to update request.' });
                return false;
            }
            cur.reason   = json.request.reason;
            cur.notes    = json.request.notes;
            cur.time_in  = json.request.time_in;
            cur.time_out = json.request.time_out;
            cur.ot_hours = json.request.ot_hours;
            if (cbs.onSaved) cbs.onSaved(cur);
            return true;
        });
    }

    function decide(status, remarks) {
        return fetch('ajax.php?action=decide_attendance_request', {
            method: 'POST',
            body: new URLSearchParams({ id: cur.id, stage: cur.current_stage || '', status: status, remarks: remarks || '' })
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (!(json && json.result)) {
                Swal.fire({ icon: 'error', title: 'Error', text: (json && json.message) || 'Failed to decide the request.' });
                return false;
            }
            // Overall status moves only when the chain reaches a verdict; a
            // mid-chain approval leaves it pending (json.final says which).
            cur.status = json.final ? Number(json.status) : 0;
            cur.final  = !!json.final;
            if (modal) modal.hide();
            if (cbs.onDecided) cbs.onDecided(cur.status, cur, json);
            // The message names the next stage, or carries the DTR-write warning
            // on a final OT approval — worth reading, not flashing.
            var text = json.message || '';
            var hold = text.indexOf('Warning') !== -1 || !json.final;
            Swal.fire({
                icon: 'success',
                title: status === 2 ? 'Rejected' : (json.final ? 'Fully approved' : 'Approved — next stage'),
                text: text,
                timer: hold ? undefined : 1300,
                showConfirmButton: hold
            });
            return true;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        $id('arr-approve').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            // The approver has the scans and the figure in front of them here —
            // a second "Are you sure?" on top of that is a click, not a safeguard.
            saveEdits().then(function (ok) { return ok ? decide(1) : null; })
                       .finally(function () { btn.disabled = false; });
        });
        $id('arr-reject').addEventListener('click', function () {
            var btn = this;
            Swal.fire({
                title: 'Reject this request?',
                text: cur && cur.current_stage_label ? 'Rejecting at the ' + cur.current_stage_label + ' stage halts the chain.' : '',
                input: 'textarea', inputLabel: 'Reason for rejection',
                inputPlaceholder: 'e.g. No supporting logs / filed on the wrong date',
                inputAttributes: { maxlength: 255 },
                inputValidator: function (v) { return !String(v || '').trim() ? 'A reason is required to reject.' : undefined; },
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#c62828', confirmButtonText: 'Yes, reject',
            }).then(function (res) {
                if (!res.isConfirmed) return;
                btn.disabled = true;
                decide(2, String(res.value || '').trim()).finally(function () { btn.disabled = false; });
            });
        });
    });

    return { open: open };
})();
</script>
<?php } ?>
