<?php
/**
 * Employee Quick-View drawer — shared slide-in summary of one employee.
 *
 * Include ONCE near the end of any page (index.php does it for every routed
 * page; standalone workbenches include it themselves), then put
 *     data-emp-quickview="<employee id>"
 * on any element — a delegated listener does the rest. Clicking it opens the
 * drawer with the essentials first — identity, org, today's shift, pay glance,
 * government IDs — and a "Full details" button that goes to the employee-details
 * page. The point is that a click on a name shows THIS first, not the full page.
 *
 * Data comes from ajax.php?action=employee_quick_view (read-only, gated to
 * whoever may open employee-details; pay is shown only for roles that may
 * see it — see admin_class.php).
 *
 * Self-contained on purpose: no bootstrap JS, no jQuery — some standalone
 * pages (dtr-documents.php) load bootstrap's CSS but not its bundle.
 *
 * Optional, set BEFORE the include:
 *   $eqv_full_target = '_blank';   // open "Full details" in a new tab
 *                                  // (standalone workbenches want this)
 */
$eqv_full_target = isset($eqv_full_target) ? $eqv_full_target : '';
?>
<style>
    #eqv-overlay { position: fixed; inset: 0; z-index: 1300; background: rgba(43,35,63,.40); opacity: 0; pointer-events: none; transition: opacity .22s; }
    #eqv-overlay.open { opacity: 1; pointer-events: auto; }
    #emp-quickview { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1301; width: min(380px, 94vw); display: flex; flex-direction: column; background: #fff; box-shadow: -10px 0 34px rgba(46,35,85,.22); transform: translateX(105%); transition: transform .26s cubic-bezier(.4,0,.2,1); }
    #emp-quickview.open { transform: translateX(0); }
    #emp-quickview .eqv-head { flex-shrink: 0; background: linear-gradient(135deg, #6f47b5, #5d36a6); color: #fff; padding: 18px 16px; display: flex; align-items: center; gap: 12px; }
    #emp-quickview .eqv-avatar { width: 52px; height: 52px; border-radius: 50%; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.45); color: #fff; font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    #emp-quickview .eqv-name { font-size: 15px; font-weight: 700; line-height: 1.25; word-break: break-word; }
    #emp-quickview .eqv-no { font-size: 11.5px; opacity: .85; }
    #emp-quickview .eqv-x { flex-shrink: 0; align-self: flex-start; border: none; background: rgba(255,255,255,.16); color: #fff; width: 28px; height: 28px; border-radius: 8px; cursor: pointer; font-size: 15px; line-height: 1; }
    #emp-quickview .eqv-x:hover { background: rgba(255,255,255,.30); }
    #emp-quickview .eqv-scroll { flex: 1; min-height: 0; overflow-y: auto; padding: 10px 16px 16px; scrollbar-width: thin; scrollbar-color: #cfc4e6 transparent; }
    #emp-quickview .eqv-scroll::-webkit-scrollbar { width: 9px; }
    #emp-quickview .eqv-scroll::-webkit-scrollbar-thumb { background: #cfc4e6; border-radius: 8px; border: 2px solid #fff; }
    #emp-quickview .eqv-sec { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #8f8aa0; margin: 14px 0 6px; }
    #emp-quickview .eqv-row { display: flex; align-items: flex-start; gap: 8px; padding: 5px 0; font-size: 12.5px; border-bottom: 1px dashed #f0eef6; }
    #emp-quickview .eqv-row:last-child { border-bottom: 0; }
    #emp-quickview .eqv-row i { color: #6f47b5; font-size: 14px; margin-top: 1px; flex-shrink: 0; }
    #emp-quickview .eqv-lbl { color: #888; width: 100px; flex-shrink: 0; }
    #emp-quickview .eqv-val { font-weight: 600; color: #2b2b33; min-width: 0; overflow-wrap: anywhere; }
    #emp-quickview .eqv-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 700; padding: 2px 9px; border-radius: 12px; margin-top: 4px; margin-right: 4px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.45); }
    #emp-quickview .eqv-spin { text-align: center; color: #8f8aa0; padding: 48px 0; font-size: 12.5px; }
    #emp-quickview .eqv-spin.err { color: #c62828; }
    #emp-quickview .eqv-foot { flex-shrink: 0; padding: 12px 16px; border-top: 1px solid #eceaf1; background: #faf9fc; }
    #emp-quickview .eqv-full { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 14px; border-radius: 8px; text-decoration: none; font-size: 12.5px; font-weight: 700; color: #fff; background: linear-gradient(135deg, #6f47b5, #5d36a6); }
    #emp-quickview .eqv-full:hover { color: #fff; filter: brightness(1.08); }
    [data-emp-quickview] { cursor: pointer; }
</style>

<div id="eqv-overlay"></div>
<aside id="emp-quickview" role="dialog" aria-modal="true" aria-label="Employee quick view">
    <div class="eqv-head">
        <div class="eqv-avatar" id="eqv-avatar">–</div>
        <div style="min-width:0;flex:1;">
            <div class="eqv-name" id="eqv-name">Employee</div>
            <div class="eqv-no" id="eqv-no"></div>
            <div id="eqv-badges"></div>
        </div>
        <button type="button" class="eqv-x" id="eqv-close" title="Close (Esc)"><i class="ri-close-line"></i></button>
    </div>
    <div class="eqv-scroll" id="eqv-body">
        <div class="eqv-spin"><i class="ri-loader-4-line d-block mb-1" style="font-size:22px;"></i>Loading…</div>
    </div>
    <div class="eqv-foot">
        <a href="javascript:void(0);" id="eqv-full-link" class="eqv-full"
           <?= $eqv_full_target !== '' ? 'target="' . htmlspecialchars($eqv_full_target) . '"' : '' ?>>
            <i class="ri-user-search-line"></i>Full details
        </a>
    </div>
</aside>

<script>
(function () {
    if (window.EmpQuickView) return;               // include twice safely
    var cache = {};                                // id -> response, per page load
    var lastFocus = null;

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function money(n) {
        return Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function row(icon, label, value) {
        if (value == null || value === '') return '';
        return '<div class="eqv-row"><i class="' + icon + '"></i>' +
               '<span class="eqv-lbl">' + label + '</span>' +
               '<span class="eqv-val">' + esc(value) + '</span></div>';
    }

    function render(data) {
        var e = data.employee, sh = data.shift, pay = data.pay;
        var name = e.lastname + ', ' + e.firstname +
            (e.middlename ? ' ' + e.middlename.charAt(0) + '.' : '') +
            (e.ext ? ' ' + e.ext : '');
        document.getElementById('eqv-avatar').textContent =
            (e.firstname.charAt(0) + e.lastname.charAt(0)).toUpperCase();
        document.getElementById('eqv-name').textContent = name;
        document.getElementById('eqv-no').textContent =
            e.employee_no + (e.employee_code ? ' · ' + e.employee_code : '');

        var badges = '<span class="eqv-badge"><i class="' +
            (parseInt(e.status, 10) === 1 ? 'ri-checkbox-circle-line' : 'ri-forbid-line') + '"></i>' +
            (parseInt(e.status, 10) === 1 ? 'Active' : 'Inactive') + '</span>';
        if (e.classification) badges += '<span class="eqv-badge">' + esc(e.classification) + '</span>';
        document.getElementById('eqv-badges').innerHTML = badges;

        var bday = '';
        if (e.bday) {
            var d = new Date(e.bday);
            bday = isNaN(d) ? e.bday : d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        // Compensation only arrives for roles that may see pay elsewhere.
        var payRows = '';
        if (pay) {
            payRows =
                '<div class="eqv-sec">Compensation</div>' +
                row('ri-money-dollar-circle-line', 'Rate type', pay.rate_type ? pay.rate_type.charAt(0).toUpperCase() + pay.rate_type.slice(1) : '') +
                row('ri-cash-line', pay.rate_type === 'monthly' ? 'Monthly salary' : 'Daily rate', '₱ ' + money(pay.rate)) +
                (pay.allowance > 0 ? row('ri-hand-coin-line', 'Allowance', '₱ ' + money(pay.allowance)) : '');
        }

        document.getElementById('eqv-body').innerHTML =
            '<div class="eqv-sec">Work</div>' +
            row('ri-briefcase-line', 'Position', e.position || '—') +
            row('ri-building-line', 'Department', e.department || '—') +
            row('ri-time-line', 'Shift', sh ? sh.description + ' · ' + sh.time : 'No shift assigned') +
            (sh && sh.rest ? row('ri-rest-time-line', 'Rest days', sh.rest) : '') +
            payRows +
            '<div class="eqv-sec">Personal</div>' +
            row('ri-cake-2-line', 'Birthday', bday || '—') +
            (e.bank_name ? row('ri-bank-line', 'Bank', e.bank_name) + row('ri-bank-card-line', 'Account', e.bank_account_no) : '') +
            '<div class="eqv-sec">Government IDs</div>' +
            row('ri-shield-user-line', 'SSS', e.sss_no || '—') +
            row('ri-heart-pulse-line', 'PhilHealth', e.ph_no || '—') +
            row('ri-home-4-line', 'Pag-IBIG', e.hdmf_no || '—') +
            row('ri-file-list-3-line', 'TIN', e.tin_no || '—');
    }

    function open(id) {
        id = parseInt(id, 10);
        if (!id) return;
        lastFocus = document.activeElement;
        document.getElementById('eqv-full-link').href = 'index.php?page=employee-details&id=' + id;
        // Reset to the loading state so a slow fetch never shows the previous employee.
        document.getElementById('eqv-avatar').textContent = '–';
        document.getElementById('eqv-name').textContent = 'Loading…';
        document.getElementById('eqv-no').textContent = '';
        document.getElementById('eqv-badges').innerHTML = '';
        document.getElementById('eqv-body').innerHTML =
            '<div class="eqv-spin"><i class="ri-loader-4-line d-block mb-1" style="font-size:22px;"></i>Loading…</div>';
        document.getElementById('eqv-overlay').classList.add('open');
        document.getElementById('emp-quickview').classList.add('open');

        if (cache[id]) return render(cache[id]);
        fetch('ajax.php?action=employee_quick_view&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.result) throw new Error(res && res.message || 'Failed');
                cache[id] = res;
                render(res);
            })
            .catch(function (err) {
                document.getElementById('eqv-body').innerHTML =
                    '<div class="eqv-spin err"><i class="ri-error-warning-line d-block mb-1" style="font-size:22px;"></i>' +
                    esc(err.message || 'Could not load employee.') + '</div>';
            });
    }

    function close() {
        document.getElementById('emp-quickview').classList.remove('open');
        document.getElementById('eqv-overlay').classList.remove('open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.getElementById('eqv-overlay').addEventListener('click', close);
    document.getElementById('eqv-close').addEventListener('click', close);
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && document.getElementById('emp-quickview').classList.contains('open')) close();
    });

    // Capture phase + stopPropagation, so a trigger nested inside something
    // clickable (a card header, a table row with its own onclick) opens the
    // drawer INSTEAD of firing the parent's own click.
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest ? ev.target.closest('[data-emp-quickview]') : null;
        if (!t) return;
        ev.preventDefault();
        ev.stopPropagation();
        open(t.getAttribute('data-emp-quickview'));
    }, true);

    window.EmpQuickView = { open: open, close: close };
})();
</script>
