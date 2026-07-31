/**
 * EmployeeView — shared read-only employee quick-view drawer.
 *
 * One <script> tag turns any element into an employee peek:
 *
 *   <span data-emp-view="123">Dela Cruz, Juan</span>     ← click opens the drawer
 *   EmployeeView.open(123)                               ← or open programmatically
 *
 * The component injects its own CSS and DOM on first use, fetches
 * employee-view-server.php (which enforces session, department scope and
 * role-based pay hiding), caches per employee for the page's lifetime, and
 * renders a docs-style slide-in drawer: identity header, employment,
 * schedule, compensation (when the role may see it), masked gov IDs / bank.
 *
 * Loaded globally from includes/header.php, so every index.php-routed page
 * (DTR, payroll, leaves, …) can use it; standalone pages just add the tag.
 */
(function () {
    'use strict';
    if (window.EmployeeView) return;   // idempotent — safe to include twice

    var cache = {};      // employee_id -> payload (page-lifetime)
    var lastFocus = null;

    var CSS = [
        '.evw-overlay{position:fixed;inset:0;z-index:1300;background:rgba(43,35,63,.40);opacity:0;pointer-events:none;transition:opacity .22s;}',
        '.evw-overlay.open{opacity:1;pointer-events:auto;}',
        '.evw{position:fixed;top:0;right:0;bottom:0;z-index:1301;width:min(400px,94vw);display:flex;flex-direction:column;',
        '  background:#fff;box-shadow:-10px 0 34px rgba(46,35,85,.22);transform:translateX(105%);transition:transform .26s cubic-bezier(.4,0,.2,1);',
        "  font-family:'Segoe UI',system-ui,Arial,sans-serif;color:#3c3846;}",
        '.evw.open{transform:translateX(0);}',
        '.evw *{box-sizing:border-box;}',
        /* soft purple scrollbar, matching the app theme */
        '.evw-body{scrollbar-width:thin;scrollbar-color:#cfc4e6 transparent;}',
        '.evw-body::-webkit-scrollbar{width:9px;}',
        '.evw-body::-webkit-scrollbar-track{background:transparent;}',
        '.evw-body::-webkit-scrollbar-thumb{background:#cfc4e6;border-radius:8px;border:2px solid #fff;}',
        '.evw-body::-webkit-scrollbar-thumb:hover{background:#b7a7d9;}',
        '.evw-head{flex-shrink:0;display:flex;align-items:center;gap:11px;padding:15px 16px;border-bottom:1px solid #eceaf1;}',
        '.evw-avatar{width:44px;height:44px;flex-shrink:0;border-radius:13px;display:flex;align-items:center;justify-content:center;',
        '  background:linear-gradient(135deg,#6642aa,#7654b8);color:#fff;font-size:15px;font-weight:800;letter-spacing:.5px;box-shadow:0 3px 8px rgba(102,66,170,.30);}',
        '.evw-id{min-width:0;flex:1;}',
        '.evw-name{font-size:14px;font-weight:800;color:#363242;line-height:1.2;word-break:break-word;}',
        '.evw-sub{font-size:11px;color:#948ea5;margin-top:2px;}',
        '.evw-badge{display:inline-flex;align-items:center;gap:4px;font-size:9.5px;font-weight:800;padding:2px 9px;border-radius:12px;margin-top:5px;border:1px solid;}',
        '.evw-badge.on{background:#eafaf0;color:#0f9d58;border-color:#b7e4c7;}',
        '.evw-badge.off{background:#f3f2f1;color:#605e5c;border-color:#e1dfdd;}',
        '.evw-x{flex-shrink:0;align-self:flex-start;border:none;background:#f2f0f7;color:#4e3483;width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:15px;line-height:1;}',
        '.evw-x:hover{background:#e5e1ef;}',
        '.evw-body{flex:1;min-height:0;overflow-y:auto;padding:6px 16px 16px;}',
        '.evw-sec{margin-top:13px;}',
        '.evw-sec-t{font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#948ea5;',
        '  display:flex;align-items:center;gap:6px;padding-bottom:6px;border-bottom:1px solid #eceaf1;}',
        '.evw-sec-t i{color:#6642aa;font-size:13px;}',
        '.evw-row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding:7px 1px;border-bottom:1px solid #f4f2f8;font-size:12px;}',
        '.evw-row:last-child{border-bottom:none;}',
        '.evw-row .k{color:#827d91;font-weight:600;flex-shrink:0;}',
        '.evw-row .v{color:#3c3846;font-weight:700;text-align:right;word-break:break-word;min-width:0;}',
        '.evw-row .v.money{color:#4f3288;}',
        '.evw-foot{flex-shrink:0;padding:11px 16px;border-top:1px solid #eceaf1;background:#faf9fc;}',
        '.evw-link{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;text-decoration:none;',
        '  font-size:12px;font-weight:700;color:#4f3288;background:#e1dcec;border:1px solid #c0b5d5;}',
        '.evw-link:hover{background:#d2cae2;color:#4f3288;}',
        '.evw-load{display:flex;align-items:center;justify-content:center;gap:10px;padding:46px 0;color:#4e3483;font-size:12px;font-weight:700;}',
        '.evw-ring{width:22px;height:22px;border-radius:50%;border:3px solid #e1dcec;border-top-color:#6642aa;animation:evw-spin .8s linear infinite;}',
        '@keyframes evw-spin{to{transform:rotate(360deg);}}',
        '.evw-err{text-align:center;color:#c62828;font-size:12px;font-weight:600;padding:36px 14px;}',
        '[data-emp-view]{cursor:pointer;}',
    ].join('\n');

    function ensureDom() {
        if (document.getElementById('evw-drawer')) return;
        var style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);

        var overlay = document.createElement('div');
        overlay.className = 'evw-overlay';
        overlay.id = 'evw-overlay';
        overlay.addEventListener('click', close);

        var aside = document.createElement('aside');
        aside.className = 'evw';
        aside.id = 'evw-drawer';
        aside.setAttribute('role', 'dialog');
        aside.setAttribute('aria-modal', 'true');
        aside.setAttribute('aria-label', 'Employee details');
        document.body.appendChild(overlay);
        document.body.appendChild(aside);

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && aside.classList.contains('open')) close();
        });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(n) {
        return Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function row(k, v, cls) {
        if (v == null || v === '') return '';
        return '<div class="evw-row"><span class="k">' + esc(k) + '</span><span class="v ' + (cls || '') + '">' + v + '</span></div>';
    }
    function sec(icon, title, rows) {
        if (!rows) return '';
        return '<div class="evw-sec"><div class="evw-sec-t"><i class="' + icon + '"></i>' + esc(title) + '</div>' + rows + '</div>';
    }

    function render(e) {
        var initials = (e.name || '?').split(',').map(function (p) { return p.trim().charAt(0); }).join('').substring(0, 2).toUpperCase();
        var head =
            '<div class="evw-head">' +
            '  <div class="evw-avatar">' + esc(initials) + '</div>' +
            '  <div class="evw-id">' +
            '    <div class="evw-name">' + esc(e.name) + '</div>' +
            '    <div class="evw-sub">' + esc(e.employee_no || '') + (e.position ? ' · ' + esc(e.position) : '') + '</div>' +
            '    <span class="evw-badge ' + (e.active ? 'on' : 'off') + '"><i class="' + (e.active ? 'ri-checkbox-circle-line' : 'ri-forbid-line') + '"></i>' + (e.active ? 'Active' : 'Inactive') + '</span>' +
            '  </div>' +
            '  <button type="button" class="evw-x" onclick="EmployeeView.close()" title="Close (Esc)"><i class="ri-close-line"></i></button>' +
            '</div>';

        var employment =
            row('Department', esc(e.department)) +
            row('Position', esc(e.position)) +
            row('Classification', esc(e.classification)) +
            row('Employee code', esc(e.employee_code)) +
            row('Birthday', esc(e.bday));

        var schedule = e.schedule
            ? row('Shift', esc(e.schedule.label)) + row('Hours', esc(e.schedule.time)) + row('Rest days', esc(e.schedule.rest))
            : '';

        var pay = e.pay
            ? row('Rate type', esc((e.pay.rate_type || '').charAt(0).toUpperCase() + (e.pay.rate_type || '').slice(1))) +
              row(e.pay.rate_type === 'monthly' ? 'Monthly salary' : 'Daily rate', '₱ ' + money(e.pay.rate), 'money') +
              (e.pay.allowance > 0 ? row('Allowance', '₱ ' + money(e.pay.allowance), 'money') : '')
            : '';

        var gov = e.gov
            ? row('SSS', esc(e.gov.sss)) + row('PhilHealth', esc(e.gov.philhealth)) +
              row('Pag-IBIG', esc(e.gov.pagibig)) + row('TIN', esc(e.gov.tin))
            : '';

        var bank = e.bank ? row('Bank', esc(e.bank.name)) + row('Account', esc(e.bank.account)) : '';

        return head +
            '<div class="evw-body">' +
            sec('ri-briefcase-4-line', 'Employment', employment) +
            sec('ri-time-line', 'Work Schedule', schedule) +
            sec('ri-money-peso-circle-line', 'Compensation', pay) +
            sec('ri-shield-check-line', 'Government IDs', gov) +
            sec('ri-bank-line', 'Bank', bank) +
            '</div>' +
            '<div class="evw-foot"><a class="evw-link" href="' + esc(e.profile_url) + '"><i class="ri-user-3-line"></i> Open full profile</a></div>';
    }

    function open(empId) {
        empId = parseInt(empId, 10);
        if (!empId) return;
        ensureDom();
        lastFocus = document.activeElement;
        var drawer = document.getElementById('evw-drawer');
        var overlay = document.getElementById('evw-overlay');
        overlay.classList.add('open');
        drawer.classList.add('open');

        if (cache[empId]) {
            drawer.innerHTML = render(cache[empId]);
            return;
        }
        drawer.innerHTML = '<div class="evw-load"><span class="evw-ring"></span> Loading…</div>';
        fetch('employee-view-server.php?id=' + empId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.result) throw new Error(j.message || 'Failed');
                cache[empId] = j.employee;
                if (drawer.classList.contains('open')) drawer.innerHTML = render(j.employee);
            })
            .catch(function (err) {
                drawer.innerHTML =
                    '<div class="evw-head"><div class="evw-id"><div class="evw-name">Employee details</div></div>' +
                    '<button type="button" class="evw-x" onclick="EmployeeView.close()"><i class="ri-close-line"></i></button></div>' +
                    '<div class="evw-err"><i class="ri-error-warning-line"></i> ' + esc(err.message || 'Could not load employee.') + '</div>';
            });
    }

    function close() {
        var drawer = document.getElementById('evw-drawer');
        var overlay = document.getElementById('evw-overlay');
        if (drawer) drawer.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    // Any element with data-emp-view="<id>" opens the drawer. Capture phase, so
    // a trigger nested inside something clickable (a card header, a profile
    // link) opens the drawer INSTEAD of firing the parent's own click.
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest ? ev.target.closest('[data-emp-view]') : null;
        if (!t) return;
        ev.preventDefault();
        ev.stopPropagation();
        open(t.getAttribute('data-emp-view'));
    }, true);

    window.EmployeeView = { open: open, close: close };
})();
