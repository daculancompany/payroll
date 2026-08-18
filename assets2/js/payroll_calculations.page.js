/* ==========================================================================
   Payroll Details / Pay Computation Sheet — page script
   Extracted verbatim from payroll_calculations.php. Only the server data
   bridge (PCW_DATA / PCW_REVIEWS / PCW_EXTRA_CATALOG / PCW_META /
   PCW_IS_ADMIN) stays inline in the page; everything below is unchanged.
   ========================================================================== */
// employee_id → their payslip sign-off (decision, message, HR reply)
// Suggestion lists for the one-off item picker: {n: name, d: description, u: 1 if used before}

(function () {
    var D = window.PCW_DATA || [];
    var M = window.PCW_META || {};
    // S.ps holds the payslip selection ({id: true}) — the card list is the only
    // selection UI now that the table preview has no checkbox column.
    var S = { q: '', dept: '', area: '', pos: '', rate: '', sch: '', has: '', rv: '', erv: '', lock: '', exc: '', sel: null, zoom: 1, list: [], ps: {} };
    // Exception predicates — reused by the Insights chips and the left-list filter.
    function excPred(key, e) {
        switch (key) {
            case 'negnet':   return e.net <= 0;
            case 'noatt':    return e.net > 0 && e.dtr_days === 0 && e.rate_type !== 'fixed';
            case 'swing':    return e.prev_net != null && e.prev_net != 0 && Math.abs((e.net - e.prev_net) / e.prev_net) >= 0.30;
            case 'disputed': return e.emp_rv === 2;
            case 'absent':   return e.absent > 0;
            case 'late':     return e.late_min > 0;
            // Worked 10PM–6AM but earned ₱0 ND — the shift's has_nsd flag is off
            // (or nsd_rate is 0), so the premium was silently never priced.
            case 'nd0':      return e.nsd_hrs > 0 && !(e.nsd_amt > 0);
        }
        return true;
    }
    var EXC_DEFS = [
        { k: 'negnet',   lbl: 'Zero / negative net',  cls: 'danger', ic: 'ri-error-warning-line' },
        { k: 'noatt',    lbl: 'Paid, no attendance',  cls: 'warn',   ic: 'ri-calendar-close-line' },
        { k: 'swing',    lbl: 'Net moved ≥30%',  cls: 'info',   ic: 'ri-line-chart-line', needPrev: true },
        { k: 'disputed', lbl: 'Disputed by employee', cls: 'purple', ic: 'ri-chat-off-line' },
        { k: 'absent',   lbl: 'Has absences',         cls: 'warn',   ic: 'ri-user-unfollow-line' },
        { k: 'late',     lbl: 'Has late',             cls: 'warn',   ic: 'ri-time-line' },
        { k: 'nd0',      lbl: 'Night hours, ₱0 ND',   cls: 'danger', ic: 'ri-moon-clear-line' }
    ];
    var GROUPS = { 1: 'Contributions', 3: 'Loans', 2: 'Other Deductions' };
    // Review-mark meta — colour + icon shown in the left list per employee.
    var RV_META = {
        1: { cls: 'rv-ok',    ic: 'ri-lock-2-fill',        lbl: 'Verified', t: 'Okay — figures verified &amp; locked' },
        2: { cls: 'rv-issue', ic: 'ri-error-warning-fill', lbl: 'Issue',    t: 'Something wrong — needs correction' },
        3: { cls: 'rv-chk',   ic: 'ri-loader-4-line',      lbl: 'Reviewing', t: 'Ongoing review' }
    };

    var byId = function (x) { return document.getElementById(x); };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    var fmt = function (n) { return (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    // Day counts: whole numbers stay whole ("7"), fractions keep the half ("6.5")
    var fmt2 = function (n) { n = Number(n) || 0; return n % 1 === 0 ? String(n) : n.toFixed(1); };
    // Day counts are fractions of a working day (hours / shift length), so they
    // arrive as long repeating decimals — 12.858571428571 is not a figure anyone
    // can read. Whole days stay whole; anything else shows 2dp.
    var days2 = function (n) { n = Number(n) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); };
    var peso = function (n) { return '₱ ' + fmt(n); };
    var initials = function (e) { return ((e.first || ' ')[0] + (e.last || ' ')[0]).toUpperCase(); };

    function flags(e) {
        return {
            neg: e.net <= 0,
            abs: e.absent > 0,
            late: e.late_min > 0,
            disp: e.emp_rv === 2,
            issue: e.rv === 2,
            ok: e.emp_rv === 1
        };
    }

    function empById(id) {
        for (var i = 0; i < D.length; i++) if (D[i].id == id) return D[i];
        return null;
    }

    // ── Left list ──
    function buildList() {
        S.list = D.filter(function (e) {
            if (S.q && (e.name + ' ' + e.no + ' ' + e.pos).toLowerCase().indexOf(S.q) === -1) return false;
            if (S.dept && e.dept !== S.dept) return false;
            if (S.area && e.area !== S.area) return false;
            if (S.pos && e.pos !== S.pos) return false;
            if (S.rate && e.rate_type !== S.rate) return false;
            if (S.sch && e.sch !== S.sch) return false;
            // Pay-component chips: payslip carries the selected line
            if (S.has === 'leave' && !(e.pleave > 0)) return false;
            if (S.has === 'ot' && !(e.ot_hrs > 0)) return false;
            if (S.has === 'nsd' && !(e.nsd_hrs > 0)) return false;
            if (S.has === 'hol' && !(e.legal > 0 || e.spc > 0)) return false;
            if (S.has === 'rest' && !(e.rest > 0)) return false;
            if (S.rv !== '' && String(e.rv) !== S.rv) return false;
            // Employee sign-off: '1'/'2'/'0' by decision, 'm' = left a message
            if (S.erv !== '') {
                var cv = (window.PCW_REVIEWS || {})[e.emp];
                if (S.erv === 'm') { if (!cv || !cv.c) return false; }
                else if (String(e.emp_rv || 0) !== S.erv) return false;
            }
            // Edit lock: u = reopened for correction, l = still frozen
            if (S.lock === 'u' && !e.unlocked) return false;
            if (S.lock === 'l' && e.unlocked) return false;
            if (S.exc && !excPred(S.exc, e)) return false;
            return true;
        });
        var html = '';
        S.list.forEach(function (e) {
            var f = flags(e);
            var chk = S.ps[e.id] ? { checked: true } : null;
            var dots = '';
            if (f.neg) dots += '<span class="pcw-fdot neg" title="Zero / negative net"></span>';
            if (f.abs) dots += '<span class="pcw-fdot abs" title="' + e.absent + ' absent"></span>';
            if (f.late) dots += '<span class="pcw-fdot lt" title="' + Math.round(e.late_min) + ' min late"></span>';
            if (f.disp) dots += '<span class="pcw-fdot disp" title="Disputed by employee"></span>';
            if (f.ok) dots += '<span class="pcw-fdot ok" title="Confirmed by employee"></span>';
            // Review-mark badge: colour + icon follow the mark (green lock = OK &
            // figures locked, red = issue, blue = ongoing review).
            var rvm = RV_META[e.rv];
            var rvBadge = rvm
                ? '<span class="pcw-rv-ic ' + rvm.cls + '" title="' + rvm.t + (e.rv_c ? ' — ' + esc(e.rv_c) : '') + '"><i class="' + rvm.ic + '"></i></span>'
                : '';
            var rvTag = rvm
                ? '<span class="pcw-rv-tag ' + rvm.cls + '"><i class="' + rvm.ic + '"></i>' + rvm.lbl + '</span>'
                : '';
            // Activity badges: review requests sent, messages, internal notes
            var tags = rvTag;
            if (e.sent_n > 0) {
                tags += '<span class="pcw-tag-sent" title="Review request sent ' + e.sent_n + '×'
                    + (e.sent_at ? ' — last ' + esc(e.sent_at) : '') + '"><i class="ri-send-plane-fill"></i>' + e.sent_n + '× sent</span>';
            }
            // Reopened for correction — without this the "Unlocked" filter would
            // return rows you cannot tell apart from the frozen ones.
            if (e.unlocked) {
                tags += '<span class="pcw-tag-open" title="Unlocked for editing'
                    + (e.unlock_why ? ' — ' + esc(e.unlock_why) : '') + '"><i class="ri-lock-unlock-line"></i>open</span>';
            }
            var nMsg = (e.msgs || []).length, nNote = (e.notes || []).length;
            // Attendance-record messages (calendar icon) vs the payslip sign-off
            // message (speech bubble, below) — two different conversations, so
            // they get two clearly different icons.
            if (nMsg) tags += '<span class="pcw-tag-cnt msg" title="' + nMsg + ' attendance-record message(s)"><i class="ri-calendar-2-line"></i>' + nMsg + '</span>';
            if (nNote) tags += '<span class="pcw-tag-cnt note" title="' + nNote + ' internal note(s)"><i class="ri-sticky-note-fill"></i>' + nNote + '</span>';
            // Chat button — only for employees who left a message with their
            // payslip sign-off; opens the same popup as the review panel.
            var conv = (window.PCW_REVIEWS || {})[e.emp];
            var convBtn = '';
            if (conv && conv.c) {
                var cCls = 'pcw-item-msg' + (conv.st === 2 ? ' disp' : '') + (conv.new ? ' new' : '');
                convBtn = '<button type="button" class="' + cCls + '" data-convo="' + e.emp + '" title="'
                    + (conv.new ? 'UNREAD — ' : '') + (conv.st === 2 ? 'Disputed' : 'Confirmed')
                    + ' their payslip with a message — click to read"><i class="ri-chat-3-fill"></i></button>';
            }
            html += '<div class="pcw-item' + (S.sel == e.id ? ' active' : '') + (rvm ? ' ' + rvm.cls : '') + '" data-eid="' + e.id + '">'
                + '<input type="checkbox" data-ps="' + e.id + '"' + (chk && chk.checked ? ' checked' : '') + ' title="Select this employee for bulk actions">'
                + '<div class="pcw-item-avwrap"><div class="pcw-item-av rv-' + e.rv + '">' + esc(initials(e)) + '</div>' + rvBadge + '</div>'
                + '<div class="pcw-item-main"><div class="pcw-item-name">' + esc(e.name) + '</div>'
                + '<div class="pcw-item-sub">' + esc(e.no) + (e.pos ? ' · ' + esc(e.pos) : '') + '</div>'
                + (tags ? '<div class="pcw-item-tags">' + tags + '</div>' : '')
                + '</div>'
                + '<div class="pcw-item-right"><div class="pcw-item-net' + (e.net <= 0 ? ' neg' : '') + '">' + fmt(e.net) + '</div>'
                + '<div class="pcw-item-flags">' + convBtn + dots + '</div></div>'
                + '</div>';
        });
        byId('pcw-list').innerHTML = html || '<div class="pcw-list-empty"><i class="ri-user-search-line" style="font-size:22px;opacity:.5;"></i><br>No employees match.</div>';
        byId('pcw-total').textContent = S.list.length + ' of ' + D.length;
        updNav();
    }

    function select(id) {
        var e = empById(id);
        if (!e) return;
        S.sel = e.id;
        document.querySelectorAll('.pcw-item').forEach(function (it) {
            it.classList.toggle('active', it.getAttribute('data-eid') == String(e.id));
        });
        renderPaper(e);
        renderSum(e);
        updNav();
    }

    function updNav() {
        var idx = -1;
        S.list.forEach(function (e, i) { if (e.id == S.sel) idx = i; });
        byId('pcw-pos').textContent = idx >= 0 ? (idx + 1) + ' / ' + S.list.length : '';
        byId('pcw-prev').disabled = idx <= 0;
        byId('pcw-next').disabled = idx < 0 || idx >= S.list.length - 1;
    }

    // ── Center: categorized computation sheet ──
    function deltaBadge(e) {
        if (M.prev_label == null) return '';
        if (e.prev_net == null) return '<span class="pp-delta new" title="Not in previous payroll (' + esc(M.prev_label) + ')">NEW</span>';
        if (!e.prev_net) return '';
        var pct = ((e.net - e.prev_net) / Math.abs(e.prev_net)) * 100;
        var tip = 'Previous (' + esc(M.prev_label) + '): ' + peso(e.prev_net);
        if (Math.abs(pct) < 0.5) return '<span class="pp-delta flat" title="' + tip + '">≈ same</span>';
        var cls = pct > 0 ? 'up' : 'down';
        var arr = pct > 0 ? '▲' : '▼';
        return '<span class="pp-delta ' + cls + '" title="' + tip + '">' + arr + ' ' + Math.abs(pct).toFixed(1) + '%</span>';
    }

    // The table row's matching input (the classic table stays the data store)
    function tIn(e, t, dd) {
        return document.querySelector('tr[data-row-id="' + e.id + '"] input[data-type="' + t + '"]'
            + (dd != null ? '[data-dd_id="' + dd + '"]' : ''));
    }
    // Editable dashed field when the table row still has a live input for it
    // (payroll open + row not review-locked); plain text otherwise.
    function fld(e, t, fallback, dd, cls) {
        var inp = tIn(e, t, dd);
        if (!inp || inp.readOnly) return esc(String(fallback));
        return '<input type="text" class="pp-in' + (cls ? ' ' + cls : '') + '" data-t="' + t + '"'
            + (dd != null ? ' data-dd="' + esc(String(dd)) + '"' : '') + ' value="' + esc(inp.value) + '">';
    }
    // Amount cell: editable input when possible, formatted figure otherwise
    function edAmt(e, t, amt, dd) {
        var inp = tIn(e, t, dd);
        if (!inp || inp.readOnly) return amt;
        return fld(e, t, amt, dd, 'amt-in');
    }
    function canEditRow(e) {
        var inp = document.querySelector('tr[data-row-id="' + e.id + '"] input.input-class');
        return !!inp && !inp.readOnly;
    }
    function earnRow(label, qty, amt, neg, idAttr) {
        var amtHtml = (typeof amt === 'string' && amt.charAt(0) === '<')
            ? amt
            : (neg ? '(' + fmt(amt) + ')' : fmt(amt));
        return '<tr><td>' + label + '</td><td class="qty">' + (qty || '') + '</td>'
            + '<td class="amt' + (neg ? ' neg' : '') + '"' + (idAttr ? ' id="' + idAttr + '"' : '') + '>' + amtHtml + '</td></tr>';
    }

    // ── Amount in words (for the sheet's net-pay line) ───────────────────────
    var PW_ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    var PW_TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    function pwChunk(n) {   // 0–999 → words
        var s = '';
        if (n >= 100) { s += PW_ONES[Math.floor(n / 100)] + ' Hundred'; n %= 100; if (n) s += ' '; }
        if (n >= 20) { s += PW_TENS[Math.floor(n / 10)]; if (n % 10) s += '-' + PW_ONES[n % 10]; }
        else if (n > 0) s += PW_ONES[n];
        return s;
    }
    function pesoWords(v) {
        v = Number(v) || 0;
        var neg = v < 0;
        v = Math.abs(v);
        var pesos = Math.floor(v + 1e-6);
        var cents = Math.round((v - pesos) * 100);
        if (cents === 100) { pesos++; cents = 0; }
        var s = '', rem = pesos;
        [['Billion', 1e9], ['Million', 1e6], ['Thousand', 1e3]].forEach(function (u) {
            var q = Math.floor(rem / u[1]);
            if (q) { s += (s ? ' ' : '') + pwChunk(q) + ' ' + u[0]; rem %= u[1]; }
        });
        if (rem || !s) s += (s ? ' ' : '') + (pwChunk(rem) || 'Zero');
        s += ' Peso' + (pesos === 1 ? '' : 's');
        s += cents ? ' and ' + pwChunk(cents) + ' Centavo' + (cents === 1 ? '' : 's') : ' Only';
        return (neg ? 'Negative ' : '') + s;
    }

    // ── Named one-off items on a single payslip ───────────────────────────────
    // kind 1 deducts (section C), kind 2 adds (section B). Unlimited per
    // employee, applied without recalculating the batch.
    function extrasOfKind(e, kind) {
        return (e.extras || []).filter(function (x) { return x.kind === kind; });
    }
    function extraRow(e, x, neg) {
        var editable = canEditRow(e);
        var act = editable
            ? '<span class="pp-x-act">'
              + '<button type="button" class="pp-x-btn" title="Edit this item" onclick="pcwEditExtra(' + e.id + ',' + x.id + ')"><i class="ri-pencil-line"></i></button>'
              + '<button type="button" class="pp-x-btn del" title="Remove this item" onclick="pcwDeleteExtra(' + e.id + ',' + x.id + ')"><i class="ri-delete-bin-line"></i></button>'
              + '</span>'
            : '';
        return '<tr><td>' + esc(x.label) + act + '</td><td class="qty">one-off</td>'
            + '<td class="amt' + (neg ? ' neg' : '') + '">' + (neg ? '(' + fmt(x.amt) + ')' : fmt(x.amt)) + '</td></tr>';
    }
    function addExtraRow(e, kind) {
        return '<tr><td colspan="3" style="padding-top:5px;">'
            + '<button type="button" class="pp-x-add" onclick="pcwAddExtra(' + e.id + ',' + kind + ')">'
            + '<i class="ri-add-line"></i> Add ' + (kind === 2 ? 'allowance' : 'deduction') + '</button></td></tr>';
    }

    // Add / edit share one dialog; the server upserts on the id.
    window.pcwAddExtra  = function (itemId, kind) { extraDialog(itemId, kind, null); };
    window.pcwEditExtra = function (itemId, xid) {
        var e = empById(itemId);
        var x = (e && (e.extras || []).find(function (i) { return i.id === xid; })) || null;
        if (x) extraDialog(itemId, x.kind, x);
    };
    // ── The add / edit dialog ──────────────────────────────────────────────
    // A Bootstrap modal rather than a Swal prompt, so the name field can carry
    // a real type-ahead over the allowances / deductions masters. The picker is
    // a convenience only: payroll_item_extras stores the label as text, so a
    // name that isn't in either master is saved verbatim as typed — no schema
    // change, no id to resolve.
    var XI = { item: 0, kind: 1, existing: null, list: [], hi: -1, wired: false, quietFocus: false };
    var XI_MAX = 9999999.99;

    function xiEl(id) { return document.getElementById(id); }
    function xiCatalog(kind) {
        var c = window.PCW_EXTRA_CATALOG || {};
        return (kind === 2 ? c.allow : c.deduct) || [];
    }
    function xiSetErr(field, msg) {
        var box = xiEl('xi-' + field + '-err'), inp = xiEl('xi-' + field);
        if (box) { box.textContent = msg || ''; box.classList.toggle('show', !!msg); }
        if (inp) inp.classList.toggle('is-bad', !!msg);
    }
    function extraDialog(itemId, kind, existing) {
        var isAllow = kind === 2;
        var e = empById(itemId);
        XI.item = itemId; XI.kind = kind; XI.existing = existing || null; XI.hi = -1;

        xiEl('xi-title').innerHTML = '<i class="ri-' + (isAllow ? 'gift-line' : 'scissors-cut-line')
            + ' me-1" style="color:' + (isAllow ? '#107c41' : '#c62828') + ';"></i>'
            + (existing ? 'Edit ' : 'Add ') + (isAllow ? 'allowance' : 'deduction');
        xiEl('xi-who').textContent = e && e.name ? e.name + ' only' : 'this employee only';
        xiEl('xi-label').value = existing ? existing.label : '';
        xiEl('xi-label').placeholder = isAllow
            ? 'Search allowances, or type a new name' : 'Search deductions, or type a new name';
        xiEl('xi-amount').value = existing ? existing.amt : '';

        var save = xiEl('xi-save');
        save.innerHTML = '<i class="ri-' + (existing ? 'save-line' : 'add-line') + ' me-1"></i>'
            + (existing ? 'Save changes' : 'Add ' + (isAllow ? 'allowance' : 'deduction'));

        xiSetErr('label', ''); xiSetErr('amount', ''); xiCloseMenu(); xiCheckDuplicate();
        xiWire();
        bootstrap.Modal.getOrCreateInstance(xiEl('modal-extra-item')).show();
    }

    // ── Type-ahead ─────────────────────────────────────────────────────────
    function xiCloseMenu() {
        xiEl('xi-menu').classList.remove('open');
        xiEl('xi-label').setAttribute('aria-expanded', 'false');
        XI.hi = -1;
    }
    function xiMark(text, term) {
        var i = term ? text.toLowerCase().indexOf(term) : -1;
        if (i < 0) return esc(text);
        return esc(text.slice(0, i)) + '<em>' + esc(text.slice(i, i + term.length)) + '</em>'
            + esc(text.slice(i + term.length));
    }
    function xiOpenMenu() {
        var q = (xiEl('xi-label').value || '').trim();
        var term = q.toLowerCase();
        var all = xiCatalog(XI.kind);
        var hits = !term ? all.slice() : all.filter(function (o) {
            return o.n.toLowerCase().indexOf(term) > -1 || (o.d && o.d.toLowerCase().indexOf(term) > -1);
        }).sort(function (a, b) {
            // Names that start with what was typed come first, then the rest A–Z.
            var ai = a.n.toLowerCase().indexOf(term) === 0 ? 0 : 1;
            var bi = b.n.toLowerCase().indexOf(term) === 0 ? 0 : 1;
            return ai - bi || a.n.localeCompare(b.n);
        });
        XI.list = hits.slice(0, 60);
        var exact = term && all.some(function (o) { return o.n.toLowerCase() === term; });
        // Offer what was typed as a brand-new name, last — Enter should land on
        // a real match when the masters had one.
        if (q && !exact) XI.list.push({ n: q, d: '', u: 0, isNew: true });
        XI.hi = -1;

        var h = '';
        if (!XI.list.length) {
            h = '<div class="xi-empty">No saved ' + (XI.kind === 2 ? 'allowances' : 'deductions')
                + ' yet — type a name to create this line.</div>';
        } else {
            XI.list.forEach(function (o, i) {
                h += '<div class="xi-opt' + (o.isNew ? ' is-new' : '') + '" role="option" data-i="' + i + '">'
                    + (o.isNew ? '<span class="tag">New name</span>' : (o.u ? '<span class="tag">Used before</span>' : ''))
                    + xiMark(o.n, term)
                    + (o.d ? '<span class="d">' + esc(o.d) + '</span>' : '')
                    + '</div>';
            });
        }
        var menu = xiEl('xi-menu');
        menu.innerHTML = h;
        menu.classList.add('open');
        menu.scrollTop = 0;
        xiEl('xi-label').setAttribute('aria-expanded', 'true');
    }
    function xiHighlight(n) {
        var opts = xiEl('xi-menu').querySelectorAll('.xi-opt');
        if (!opts.length) return;
        XI.hi = (n + opts.length) % opts.length;
        opts.forEach(function (el, i) { el.classList.toggle('on', i === XI.hi); });
        opts[XI.hi].scrollIntoView({ block: 'nearest' });
    }
    function xiPick(i) {
        var o = XI.list[i];
        if (!o) return;
        xiEl('xi-label').value = o.n;
        xiCloseMenu();
        xiSetErr('label', '');
        xiCheckDuplicate();
        xiEl('xi-amount').focus();
    }
    // Same name already on this payslip? Almost always a double entry, but a
    // second "Cash advance" line is legitimate — warn, don't block.
    function xiCheckDuplicate() {
        var box = xiEl('xi-dup');
        var name = (xiEl('xi-label').value || '').trim().toLowerCase();
        var e = empById(XI.item);
        var dup = name && e && (e.extras || []).some(function (x) {
            return x.kind === XI.kind && String(x.label).trim().toLowerCase() === name
                && (!XI.existing || x.id !== XI.existing.id);
        });
        box.classList.toggle('show', !!dup);
        if (dup) box.querySelector('span').textContent =
            'This payslip already has a ' + (XI.kind === 2 ? 'allowance' : 'deduction')
            + ' named that. Saving adds a second line — both are paid.';
    }

    // ── Validation ─────────────────────────────────────────────────────────
    // Mirrors what save_payroll_item_extra() enforces, so a rejection is shown
    // on the field rather than as a failed round trip.
    function xiValidate() {
        var label = (xiEl('xi-label').value || '').replace(/\s+/g, ' ').trim();
        var raw = String(xiEl('xi-amount').value || '').trim();
        var amt = parseFloat(raw);
        var ok = true;
        xiSetErr('label', ''); xiSetErr('amount', '');

        if (!label) { xiSetErr('label', 'Give the line a name — it prints on the payslip.'); ok = false; }
        else if (label.length > 120) { xiSetErr('label', 'Keep the name to 120 characters or fewer.'); ok = false; }

        if (!raw) { xiSetErr('amount', 'Enter an amount.'); ok = false; }
        else if (isNaN(amt)) { xiSetErr('amount', 'Amount must be a number.'); ok = false; }
        else if (amt <= 0) { xiSetErr('amount', 'Amount must be greater than zero.'); ok = false; }
        else if (amt > XI_MAX) { xiSetErr('amount', 'That amount looks too large — please check it.'); ok = false; }

        if (!ok) {
            // Focus the first bad field, but keep the suggestion list shut —
            // opening it here would cover the error that was just shown.
            XI.quietFocus = true;
            xiEl(xiEl('xi-label').classList.contains('is-bad') ? 'xi-label' : 'xi-amount').focus();
            XI.quietFocus = false;
            return null;
        }
        return { label: label, amount: Math.round(amt * 100) / 100 };
    }
    function xiSubmit() {
        var v = xiValidate();
        if (!v) return;
        // The row may have been locked (review sign-off, payroll locked) while
        // the dialog sat open — the server gate is authoritative, this just
        // avoids a pointless round trip.
        var e = empById(XI.item);
        if (e && !canEditRow(e)) {
            xiSetErr('label', 'This payslip is no longer editable — close and reopen the payroll.');
            return;
        }
        bootstrap.Modal.getOrCreateInstance(xiEl('modal-extra-item')).hide();
        pcwPostExtra('save_payroll_item_extra', {
            item_id: XI.item, id: XI.existing ? XI.existing.id : 0,
            kind: XI.kind, label: v.label, amount: v.amount
        }, XI.item);
    }

    // Listeners are bound once, on first open — the modal markup is static.
    function xiWire() {
        if (XI.wired) return;
        XI.wired = true;
        var inp = xiEl('xi-label'), menu = xiEl('xi-menu');

        inp.addEventListener('focus', function () { if (!XI.quietFocus) xiOpenMenu(); });
        // The open list overlays the footer, so it has to get out of the way
        // before a click can reach Cancel / Save. Option rows cancel their own
        // mousedown, so picking one still beats this blur.
        inp.addEventListener('blur', xiCloseMenu);
        inp.addEventListener('input', function () { xiOpenMenu(); xiSetErr('label', ''); xiCheckDuplicate(); });
        inp.addEventListener('keydown', function (ev) {
            var open = menu.classList.contains('open');
            if (ev.key === 'ArrowDown') { ev.preventDefault(); open ? xiHighlight(XI.hi + 1) : xiOpenMenu(); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); if (open) xiHighlight(XI.hi - 1); }
            else if (ev.key === 'Escape') { if (open) { ev.stopPropagation(); xiCloseMenu(); } }
            else if (ev.key === 'Enter') {
                ev.preventDefault();
                if (open && XI.hi >= 0) xiPick(XI.hi);
                else { xiCloseMenu(); xiEl('xi-amount').focus(); }
            }
        });
        // mousedown, not click — blur would tear the menu down first.
        menu.addEventListener('mousedown', function (ev) {
            var opt = ev.target.closest('.xi-opt');
            if (!opt) return;
            ev.preventDefault();
            xiPick(parseInt(opt.getAttribute('data-i'), 10));
        });
        document.addEventListener('mousedown', function (ev) {
            if (menu.classList.contains('open') && !ev.target.closest('.xi-ac')) xiCloseMenu();
        });

        xiEl('xi-amount').addEventListener('input', function () { xiSetErr('amount', ''); });
        xiEl('xi-amount').addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); xiSubmit(); }
        });
        xiEl('xi-save').addEventListener('click', xiSubmit);
        xiEl('modal-extra-item').addEventListener('shown.bs.modal', function () { xiEl('xi-label').focus(); });
        xiEl('modal-extra-item').addEventListener('hidden.bs.modal', xiCloseMenu);
    }
    window.pcwDeleteExtra = function (itemId, xid) {
        Swal.fire({
            title: 'Remove this item?', text: 'It will disappear from the payslip and the net pay recomputes.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#c62828', confirmButtonText: 'Remove'
        }).then(function (r) {
            if (r.isConfirmed) pcwPostExtra('delete_payroll_item_extra', { id: xid }, itemId);
        });
    };
    // One round trip: save, then pull the row's recomputed figures back so the
    // sheet, the card list and the batch totals all agree — without a reload.
    function pcwPostExtra(action, data, itemId) {
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        $.ajax({
            url: 'ajax.php?action=' + action, method: 'POST', dataType: 'JSON', data: data,
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); },
            success: function (res) {
                if (!res || !res.result) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed.' });
                    return;
                }
                var e = empById(itemId);
                if (e) e.extras = res.extras || [];
                // refreshPayrollRows repaints the table from server-computed
                // figures (which now include extras); syncRow pulls the row's
                // new gross / deductions / net back into the payload object.
                refreshPayrollRows(M.id).then(function () {
                    var fresh = empById(itemId);
                    if (fresh) { syncRow(fresh); renderPaper(fresh); renderSum(fresh); }
                    buildList(); renderInsights();
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success',
                        title: res.message, showConfirmButton: false, timer: 2400 });
                });
            }
        });
    }

    function renderPaper(e) {
        var isMonthlyBatch = M.type === 5;
        var monthlyRate = e.rate_type === 'monthly' || e.rate_type === 'fixed';
        var h = '';

        h += '<div class="pp-head"><h1>PAY COMPUTATION SHEET</h1>'
            + '<div class="pp-sub">Payroll Period: ' + esc(M.from) + ' – ' + esc(M.to)
            + ' &nbsp;·&nbsp; ' + (isMonthlyBatch ? 'Monthly Payroll' : 'Payroll') + ' #' + M.id + '</div>'
            + '<div class="pp-head-tags">'
            + '<span class="pp-head-tag">Ref PR-' + M.id + '-' + e.id + '</span>'
            + '<span class="pp-head-tag">' + esc(e.rate_type) + ' rate</span>'
            + (e.dept ? '<span class="pp-head-tag">' + esc(e.dept) + '</span>' : '')
            + '</div></div>';

        h += '<div class="pp-emp-grid">'
            + '<div><div class="lbl">Employee Name</div><div class="val">'
            + '<a class="pp-emp-link" href="javascript:void(0);" data-emp-quickview="' + e.emp + '" title="Employee quick view">' + esc(e.name) + '</a>'
            + '</div></div>'
            + '<div><div class="lbl">Employee No.</div><div class="val">' + esc(e.no) + '</div></div>'
            + '<div><div class="lbl">Position</div><div class="val">' + esc(e.pos || '—') + '</div></div>'
            + '<div><div class="lbl">Department</div><div class="val">' + esc(e.dept || '—') + '</div></div>'
            + '<div><div class="lbl">Rate Type</div><div class="val" style="text-transform:capitalize;">' + esc(e.rate_type) + '</div></div>'
            + '<div><div class="lbl">' + (monthlyRate || isMonthlyBatch ? 'Monthly Basic Pay' : 'Rate per Day') + '</div><div class="val">'
            + peso(monthlyRate || isMonthlyBatch ? e.monthly : e.per_day) + '</div></div>'
            + '</div>';

        // At-a-glance strip — the three figures everyone looks for first
        h += '<div class="pp-glance">'
            + '<div class="pg"><div class="l">Gross Pay</div><div class="v" id="pp-glance-gross">' + fmt(e.gross) + '</div></div>'
            + '<div class="pg ded"><div class="l">Total Deductions</div><div class="v" id="pp-glance-ded">(' + fmt(e.total_ded) + ')</div></div>'
            + '<div class="pg net"><div class="l">Net Pay</div><div class="v" id="pp-glance-net">' + fmt(e.net) + '</div></div>'
            + '</div>';

        var ed = canEditRow(e);
        if (ed) {
            h += '<div class="pp-edit-hint"><i class="ri-edit-line"></i> Dashed fields are editable — type a figure, then Save. Totals recompute on Save.</div>';
        }

        // A. Attendance
        h += '<div class="pp-sec"><div class="pp-sec-title"><span class="ltr">A</span> Attendance'
            + '<span class="sec-amt" id="pp-sect-att">' + days2(e.present) + ' day(s) on duty</span></div><table class="pp-table">';
        // Two different quantities sat side by side here unexplained: dtr_days is
        // how many DTR rows were approved, present is paid day-equivalents
        // (hours worked / the shift's own day length). They differ whenever a
        // day is short or the shift is not 8 hours, which read as an error.
        h += '<tr><td>Days on Duty</td><td class="qty">'
            + (e.dtr_days ? e.dtr_days + ' DTR day(s) approved &rarr; paid day-equivalents' : '')
            + '</td><td class="amt">' + fld(e, 'present', days2(e.present)) + '</td></tr>';
        if (e.pleave > 0) {
            h += '<tr><td>Paid Leave</td><td class="qty">approved leave'
                + (monthlyRate ? ' — not counted as absence' : '') + '</td><td class="amt">' + fmt2(e.pleave) + '</td></tr>';
        }
        if (ed || e.absent > 0) h += earnRow('Absences', fld(e, 'absent', e.absent) + ' day(s) × ' + fmt(e.per_day), e.absent_amt, true);
        if (ed || e.late_min > 0) h += earnRow('Late', fld(e, 'late', Math.round(e.late_min)) + ' min', e.late_amt, true);
        if (e.ut_min > 0) h += earnRow('Undertime', fmt2(e.ut_min) + ' min', e.ut_amt, true);
        if (!ed && !(e.absent > 0) && !(e.late_min > 0) && !(e.ut_min > 0)) h += '<tr><td colspan="3" style="color:#4a7d4a;font-size:11.5px;">No absences or tardiness this period.</td></tr>';
        h += '</table></div>';

        // B. Earnings
        h += '<div class="pp-sec"><div class="pp-sec-title"><span class="ltr">B</span> Earnings'
            + '<span class="sec-amt" id="pp-sect-earn">' + fmt(e.gross) + '</span></div><table class="pp-table">';
        if (isMonthlyBatch) {
            h += earnRow('Half-Month Basic (Quinsena)', '(' + fmt(e.monthly) + ' + allowance − absences) ÷ 2', e.basic_earned, false);
        } else if (monthlyRate) {
            h += earnRow('Half-Month Basic', '(' + fmt(e.monthly) + ' − absences) ÷ 2', e.basic_earned, false);
        } else if (e.pleave > 0) {
            // Daily rate with approved paid leave: split the basic figure so the
            // leave credit is a visible line (worked + leave = total basic rate).
            var pleaveAmt = e.pleave * e.per_day;
            h += earnRow('Basic Pay (worked)', days2(e.present) + ' day(s) × ' + fld(e, 'per_day', fmt(e.per_day)), e.basic_earned - pleaveAmt, false);
            h += earnRow('Paid Leave', fmt2(e.pleave) + ' day(s) × ' + fmt(e.per_day), pleaveAmt, false);
        } else {
            h += earnRow('Basic Pay', days2(e.present) + ' day(s) × ' + fld(e, 'per_day', fmt(e.per_day)), e.basic_earned, false);
        }
        if (ed || e.allow_amt) h += earnRow('Allowance' + (monthlyRate && !isMonthlyBatch ? ' (½ applied to gross)' : ''), fld(e, 'allowance_days', e.allow_days) + ' day(s) × ' + fmt(e.allow_rate), e.allow_amt, false);
        if (ed || e.legal_amt) h += earnRow('Legal Holiday Pay', fld(e, 'legal_holiday', e.legal) + ' × ' + fmt(e.per_day), e.legal_amt, false);
        // Rest-day pay is a PREMIUM for daily staff — the day itself is already
        // counted in Days on Duty above, so the line has to say "+30%" and show
        // the premium, not a full day's rate that was never added to gross.
        if (ed || e.rest_amt) {
            var restLbl  = monthlyRate ? 'Rest Day Duty' : 'Rest Day Premium';
            var restCalc = monthlyRate
                ? fld(e, 'sunday_duty', e.rest) + ' × ' + fmt(e.per_day)
                : fld(e, 'sunday_duty', e.rest) + ' × ' + fmt(e.per_day) + ' × 30%';
            h += earnRow(restLbl, restCalc, e.rest_amt, false);
        }
        if (ed || e.spc_amt) h += earnRow('Special Holiday Pay', fld(e, 'special_holiday', e.spc) + ' day(s)', e.spc_amt, false);
        if (ed || e.ot_amt) h += earnRow('Overtime', fld(e, 'ot', e.ot_hrs) + ' hr(s) × ' + fmt(e.ot_rate), e.ot_amt, false);
        // Night differential — always on the sheet (even at 0) so it is never
        // invisible-because-empty; the amount is the editable figure.
        h += earnRow('Night Differential', fmt2(e.nsd_hrs) + ' hr(s) 10PM–6AM', edAmt(e, 'nsd_amount', e.nsd_amt), false);
        // Was Math.round() here while section A prints the same figure to 2dp —
        // one sheet, one quantity, two different numbers.
        if (e.late_amt) h += earnRow('Less: Late', fmt2(e.late_min) + ' min', e.late_amt, true);
        // Undertime is deducted inside payroll_earnings' gross, so the sheet
        // must print it — without this line the B-section rows summed to MORE
        // than the gross they claim to explain (by exactly this amount).
        if (e.ut_amt) h += earnRow('Less: Undertime', fmt2(e.ut_min) + ' min', e.ut_amt, true);
        // Named one-off allowances for this employee only.
        extrasOfKind(e, 2).forEach(function (x) {
            h += extraRow(e, x, false);
        });
        if (ed) h += addExtraRow(e, 2);
        h += '<tr class="totalrow"><td>GROSS PAY</td><td class="qty"></td><td class="amt" id="pp-gross-amt">' + fmt(e.gross) + '</td></tr>';
        h += '</table></div>';

        // C. Deductions — categorized: contributions / loans / other / company & tax
        h += '<div class="pp-sec"><div class="pp-sec-title"><span class="ltr">C</span> Deductions'
            + '<span class="sec-amt neg" id="pp-sect-ded">(' + fmt(e.total_ded) + ')</span></div><table class="pp-table">';
        [1, 3, 2].forEach(function (g) {
            var rows = (e.deds || []).filter(function (d) { return d.g === g; });
            if (!rows.length) return;
            var dtype = g === 1 ? 'contribution' : (g === 3 ? 'loan' : 'deduction');
            var sub = 0;
            h += '<tr class="subgroup"><td colspan="3">' + GROUPS[g] + '</td></tr>';
            rows.forEach(function (d) {
                sub += d.amt;
                h += earnRow(esc(d.label), '', edAmt(e, dtype, d.amt, d.id), d.amt > 0);
            });
            h += '<tr class="subtotal"><td>Subtotal — ' + GROUPS[g] + '</td><td class="qty"></td><td class="amt neg" id="pp-sub-g' + g + '">(' + fmt(sub) + ')</td></tr>';
        });
        var fixed = [
            ['SSS Provident Fund', 'sss_fund', e.sss_fund],
            ['JEI Advance', 'jei_advances', e.jei],
            ['JCC Advances', 'jcc_advances', e.jcc],
            ['Tax', 'tax', e.tax]
        ];
        var fsub = 0;
        h += '<tr class="subgroup"><td colspan="3">Company Advances &amp; Tax</td></tr>';
        fixed.forEach(function (fd) {
            fsub += fd[2];
            h += earnRow(fd[0], '', edAmt(e, fd[1], fd[2]), fd[2] > 0);
        });
        h += '<tr class="subtotal"><td>Subtotal — Company Advances &amp; Tax</td><td class="qty"></td><td class="amt neg" id="pp-sub-fx">(' + fmt(fsub) + ')</td></tr>';
        // Named one-off deductions for this employee only.
        var xDed = extrasOfKind(e, 1);
        if (xDed.length || ed) {
            h += '<tr class="subgroup"><td colspan="3">One-off Items</td></tr>';
            xDed.forEach(function (x) { h += extraRow(e, x, true); });
            if (ed) h += addExtraRow(e, 1);
        }
        h += '<tr class="totalrow"><td>TOTAL DEDUCTIONS</td><td class="qty"></td><td class="amt neg" id="pp-totded-amt">(' + fmt(e.total_ded) + ')</td></tr>';
        h += '</table></div>';

        // D. Refunds
        if ((e.refunds || []).length) {
            h += '<div class="pp-sec"><div class="pp-sec-title"><span class="ltr">D</span> Refunds'
                + '<span class="sec-amt" id="pp-sect-ref">' + fmt(e.total_ref) + '</span></div><table class="pp-table">';
            e.refunds.forEach(function (r) { h += earnRow(esc(r.label), '', edAmt(e, 'refund', r.amt, r.id), false); });
            h += '<tr class="totalrow"><td>TOTAL REFUNDS</td><td class="qty"></td><td class="amt" id="pp-totref-amt">' + fmt(e.total_ref) + '</td></tr>';
            h += '</table></div>';
        }

        // E. Adjustment (signed, non-monthly batches only)
        var adjEditable = !!tIn(e, 'adjustment') && !tIn(e, 'adjustment').readOnly;
        if (!isMonthlyBatch && (adjEditable || e.adj || e.adj_rem)) {
            h += '<div class="pp-sec"><div class="pp-sec-title"><span class="ltr">' + ((e.refunds || []).length ? 'E' : 'D') + '</span> Adjustment</div><table class="pp-table">';
            h += earnRow('Manual Adjustment (+ adds to net, − deducts)', '', adjEditable ? edAmt(e, 'adjustment', e.adj) : Math.abs(e.adj), !adjEditable && e.adj < 0);
            h += '</table>';
            if (adjEditable) {
                h += '<div class="pp-note">Remarks: ' + fld(e, 'adjustment_remarks', e.adj_rem || '—', null, 'txt-in') + '</div>';
            } else if (e.adj_rem) {
                h += '<div class="pp-note">Remarks: ' + esc(e.adj_rem) + '</div>';
            }
            h += '</div>';
        }

        // How the net was arrived at — compact equation, then the net band
        h += '<div class="pp-eq">Gross <b id="pp-eq-g">' + fmt(e.gross) + '</b>'
            + '<span class="op">−</span>Deductions <b id="pp-eq-d">' + fmt(e.total_ded) + '</b>'
            + ((e.refunds || []).length ? '<span class="op">+</span>Refunds <b id="pp-eq-r">' + fmt(e.total_ref) + '</b>' : '')
            + ((!isMonthlyBatch && e.adj) ? '<span class="op">' + (e.adj < 0 ? '−' : '+') + '</span>Adjustment <b id="pp-eq-a">' + fmt(Math.abs(e.adj)) + '</b>' : '')
            + '<span class="op">=</span><b id="pp-eq-n">' + fmt(e.net) + '</b></div>';
        h += '<div class="pp-net"><span class="lbl">NET PAY</span>'
            + '<span><span class="amt' + (e.net <= 0 ? ' neg' : '') + '" id="pp-net-amt">' + peso(e.net) + '</span>' + deltaBadge(e) + '</span></div>';
        h += '<div class="pp-words">Amount in words: <b id="pp-words-txt">' + esc(pesoWords(e.net)) + '</b></div>';

        // Status chips
        var chips = '';
        if (e.rv === 1) chips += '<span class="pp-chip g">✓ Reviewer: verified</span>';
        else if (e.rv === 2) chips += '<span class="pp-chip r">! Reviewer: issue' + (e.rv_c ? ' — ' + esc(e.rv_c) : '') + '</span>';
        else if (e.rv === 3) chips += '<span class="pp-chip b">● Reviewer: checking</span>';
        if (M.status === 2 || M.status === 3) {
            if (e.emp_rv === 1) chips += '<span class="pp-chip g">Employee confirmed</span>';
            else if (e.emp_rv === 2) chips += '<span class="pp-chip r">Employee disputed</span>';
            else chips += '<span class="pp-chip o">Awaiting employee review</span>';
        }
        chips += '<span class="pp-chip ' + (e.dtr_days ? 'g' : 'o') + '">' + e.dtr_days + ' approved DTR day(s)</span>';
        if (e.pleave > 0) chips += '<span class="pp-chip b">' + fmt2(e.pleave) + ' paid leave day(s)</span>';
        if (chips) h += '<div class="pp-chips">' + chips + '</div>';

        // Signatures
        h += '<div class="pp-sign">'
            + '<div><div class="line"></div>Prepared by</div>'
            + '<div><div class="line"></div>Checked by</div>'
            + '<div><div class="line">' + esc(e.first + ' ' + e.last) + '</div>Employee’s Signature</div>'
            + '</div>';

        byId('pcw-paper').innerHTML = h;
    }

    // ── Internal admin notes — read-only list (same content/style as
    // dtr-documents.php, minus the add form) ──
    var NOTE_ICONS = { info: '🔵', good: '🟢', watch: '🟠', critical: '🔴' };
    function notesHTML(e) {
        var notes = e.notes || [];
        var list = notes.length
            ? notes.map(function (n) {
                return '<div class="ddv-note ' + esc(n.level) + '">'
                    + '<span class="lv">' + (NOTE_ICONS[n.level] || NOTE_ICONS.info) + '</span>'
                    + '<span class="nt">' + esc(n.note)
                    + '<span class="nm">' + esc(n.by || 'Admin') + (n.at ? ' · ' + esc(n.at) : '') + '</span></span>'
                    + '</div>';
            }).join('')
            : '<div class="ddv-note-empty">No notes yet.</div>';
        return '<div class="ddv-notes">'
            + '<div class="ddv-notes-hd"><i class="ri-sticky-note-line"></i> Internal Notes <span class="lock"><i class="ri-lock-2-line"></i> admin only</span></div>'
            + list + '</div>';
    }

    // ── Per-employee unlock while the batch is out for employee review ────────
    // Correcting one disputed payslip should not mean recalling the whole batch
    // and voiding everyone else's sign-off, so a single row can be reopened.
    // Admin only; the server enforces the same rule in payroll_item_write_block().

    function unlockBanner(e) {
        if (M.status !== 3 || !e.unlocked) return '';
        return '<div class="pcw-unlock-note"><i class="ri-lock-unlock-line"></i>'
            + '<span><b>Open for editing.</b> Saving a change voids this employee’s review and asks them to confirm again.'
            + (e.unlock_why ? '<br><span class="why">Reason: ' + esc(e.unlock_why) + '</span>' : '')
            + '</span></div>';
    }
    function unlockButton(e) {
        if (M.status !== 3 || !PCW_IS_ADMIN) return '';
        return e.unlocked
            ? '<button type="button" class="pcw-btn warn" onclick="pcwRelockEmployee(' + e.id + ')" title="Freeze this employee again"><i class="ri-lock-line"></i> Lock</button>'
            : '<button type="button" class="pcw-btn" onclick="pcwUnlockEmployee(' + e.id + ')" title="Reopen just this employee so their figures can be corrected"><i class="ri-lock-unlock-line"></i> Unlock</button>';
    }

    window.pcwUnlockEmployee = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        // Unlocking someone who already confirmed is the risky case — say so.
        var warn = e.emp_rv === 1
            ? '<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:8px 10px;margin-bottom:8px;font-size:12.5px;color:#8a6100;text-align:left;">'
              + '<b>' + esc(e.name) + ' has already confirmed this payslip.</b><br>'
              + 'Saving any change will void that confirmation and ask them to review again.</div>'
            : '';
        Swal.fire({
            title: 'Unlock ' + esc(e.name) + '?',
            html: warn + '<div style="font-size:12.5px;color:#555;text-align:left;">'
                + 'Only this employee becomes editable — the rest of the batch stays frozen.<br>'
                + 'Give a reason for the audit log.</div>',
            input: 'text',
            inputPlaceholder: 'e.g. Missing OT for Jun 8–9, per their dispute',
            inputAttributes: { maxlength: 255 },
            showCancelButton: true,
            confirmButtonColor: '#6642aa',
            confirmButtonText: 'Unlock for editing',
            preConfirm: function (v) {
                if (!v || !v.trim()) { Swal.showValidationMessage('A reason is required.'); return false; }
                return v.trim();
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            pcwPostLock('unlock_payroll_item', { id: itemId, reason: r.value }, true, r.value);
        });
    };

    window.pcwRelockEmployee = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        Swal.fire({
            title: 'Lock ' + esc(e.name) + '?',
            text: 'Their figures become read-only again. Unsaved edits are lost.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#c98a00',
            confirmButtonText: 'Lock'
        }).then(function (r) {
            if (r.isConfirmed) pcwPostLock('relock_payroll_item', { id: itemId }, false);
        });
    };

    // Applied in place — no page reload. The row's inputs are always in the DOM
    // while a batch is Open or in review; readonly is what gates editing, so
    // flipping it is all that is needed. Selection, filters and scroll survive.
    function pcwPostLock(action, data, unlocking, reason) {
        Swal.fire({ title: 'Saving…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
        $.ajax({
            url: 'ajax.php?action=' + action, method: 'POST', dataType: 'JSON', data: data,
            success: function (res) {
                if (!res || !res.result) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed.' });
                    return;
                }
                pcwApplyLockState(data.id, unlocking, reason);
                Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: res.message, showConfirmButton: false, timer: 3200, timerProgressBar: true
                });
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Request failed.' }); }
        });
    }

    // Flip one row between editable and frozen, and refresh everything that
    // reflects that state.
    function pcwApplyLockState(itemId, unlocked, reason) {
        var e = empById(itemId);
        if (!e) return;
        e.unlocked = unlocked ? 1 : 0;
        e.unlock_why = unlocked ? (reason || '') : '';
        e.editable = e.unlocked;

        var tr = document.querySelector('tr[data-row-id="' + itemId + '"]');
        if (tr) {
            tr.dataset.unlocked = unlocked ? '1' : '0';
            // setReviewInputLock honours the unlock, so a green row unfreezes too.
            setReviewInputLock(tr, !unlocked && tr.classList.contains('review-ok'));
            if (!unlocked) {
                tr.querySelectorAll('.input-class').forEach(function (i) { i.readOnly = true; });
            }
        }

        // Header count + Save button follow the number of open rows.
        var open = D.filter(function (x) { return x.unlocked; }).length;
        var flag = document.querySelector('.pcw-unlock-flag');
        if (flag) {
            flag.innerHTML = '<i class="ri-lock-unlock-line"></i> ' + open + ' unlocked';
            flag.style.display = open ? '' : 'none';
        }
        var save = byId('pcw-save');
        if (save && !open && M.status === 3) save.style.display = 'none';

        if (S.sel == itemId) { renderPaper(e); renderSum(e); }
        buildList();
    }

    // ── Right: employee summary ──
    function renderSum(e) {
        var sentLine = e.sent_n > 0
            ? '<div class="pcw-sum-sent"><i class="ri-send-plane-fill"></i> Review request sent <b>' + e.sent_n + '×</b>'
              + (e.sent_at ? ' · last ' + esc(e.sent_at) : '') + '</div>'
            : '';
        // One-line sub-caption; the untruncated text lives in the tooltip.
        var sub = e.no + (e.pos ? ' · ' + e.pos : '') + (e.dept ? ' · ' + e.dept : '');
        var h = '<div class="pcw-sum-emp" title="' + esc(e.name) + ' — employee quick view">'
            + '<a href="javascript:void(0);" data-emp-quickview="' + e.emp + '">' + esc(e.name) + ' <i class="ri-user-3-line"></i></a></div>'
            + '<div class="pcw-sum-sub" title="' + esc(sub) + '">' + esc(sub) + '</div>'
            + sentLine
            + '<div class="pcw-sum-grid">'
            + '<div class="pcw-sum-tile"><div class="v">' + fmt(e.gross) + '</div><div class="l">Gross</div></div>'
            + '<div class="pcw-sum-tile ded"><div class="v">' + fmt(e.total_ded) + '</div><div class="l">Deductions</div></div>'
            + '<div class="pcw-sum-tile net"><div class="v">' + fmt(e.net) + '</div><div class="l">Net Pay</div></div>'
            + '<div class="pcw-sum-tile"><div class="v">' + days2(e.present) + '</div><div class="l">Days</div></div>'
            + '<div class="pcw-sum-tile abs"><div class="v">' + e.absent + '</div><div class="l">Absent</div></div>'
            + '<div class="pcw-sum-tile lt"><div class="v">' + Math.round(e.late_min) + '</div><div class="l">Late (min)</div></div>'
            + '</div>';
        // Where the gross went — take-home vs deductions, values in the legend
        var splitBase = Math.max(0, e.net) + Math.max(0, e.total_ded);
        if (e.net > 0 && splitBase > 0) {
            var pNet = Math.max(0, e.net) / splitBase * 100;
            h += '<div class="pcw-chart-card" style="margin-top:8px;">'
                + '<div class="pcw-ins-sec" style="margin:0;">Where the gross went</div>'
                + '<div class="pcw-split" title="Take-home ' + peso(e.net) + ' · Deductions ' + peso(e.total_ded) + '">'
                + '<span class="sg-net" style="width:' + pNet + '%"></span>'
                + '<span class="sg-ded" style="width:' + (100 - pNet) + '%"></span></div>'
                + '<div class="pcw-cl">'
                + '<div class="pcw-cl-row"><span class="sw" style="background:#2a78d6"></span><span class="lb">Take-home</span><span class="vl">' + fmt(e.net) + '</span><span class="pc">' + Math.round(pNet) + '%</span></div>'
                + '<div class="pcw-cl-row"><span class="sw" style="background:#eb6834"></span><span class="lb">Deductions</span><span class="vl">' + fmt(e.total_ded) + '</span><span class="pc">' + Math.round(100 - pNet) + '%</span></div>'
                + '</div></div>';
        }
        h += unlockBanner(e)
            + '<div class="pcw-sum-actions">'
            + '<button type="button" class="pcw-btn" onclick="openPayslipPreview(' + e.id + ')"><i class="ri-file-pdf-2-line"></i> Payslip PDF</button>'
            + '<button type="button" class="pcw-btn" onclick="pcwOpenDtr(' + e.id + ')"><i class="ri-calendar-check-line"></i> DTR Details (' + e.dtr_days + ')</button>'
            + '<button type="button" class="pcw-btn" onclick="openReviewMark(' + e.id + ')"><i class="ri-checkbox-multiple-line"></i> Review Mark</button>'
            + unlockButton(e)
            + '<a class="pcw-btn" href="javascript:void(0);" data-emp-quickview="' + e.emp + '" title="Employee quick view — Full details opens from the drawer"><i class="ri-user-3-line"></i> Profile</a>'
            + '</div>';
        byId('pcw-sum').innerHTML = h;
    }

    // ── Right: Batch Insights (live totals, review progress, exception chips) ──
    function renderInsights() {
        var box = byId('pcw-insights');
        if (!box) return;
        var t = { gross: 0, ded: 0, net: 0, ref: 0 };
        var rv = { 1: 0, 2: 0, 3: 0, 0: 0 };
        D.forEach(function (e) {
            t.gross += e.gross; t.ded += e.total_ded; t.net += e.net; t.ref += e.total_ref;
            rv[e.rv === 1 ? 1 : e.rv === 2 ? 2 : e.rv === 3 ? 3 : 0]++;
        });
        var N = D.length || 1;
        var hasPrev = M.prev_label != null;

        var h = '';
        // Totals
        h += '<div class="pcw-ins-sec">Payroll totals</div>'
            + '<div class="pcw-ins-stats">'
            + '<div class="pcw-ins-tile gross"><div class="v">' + peso(t.gross) + '</div><div class="l">Gross</div></div>'
            + '<div class="pcw-ins-tile ded"><div class="v">' + peso(t.ded) + '</div><div class="l">Deductions</div></div>'
            + '<div class="pcw-ins-tile net"><div class="v">' + peso(t.net) + '</div><div class="l">Net pay</div></div>'
            + '<div class="pcw-ins-tile"><div class="v">' + D.length + '</div><div class="l">Employees</div></div>'
            + '</div>';

        // Review progress
        var pctOk = Math.round(rv[1] / N * 100);
        h += '<div class="pcw-ins-sec">Reviewer progress <span class="pcw-ins-pct">' + pctOk + '% verified</span></div>'
            + '<div class="pcw-ins-bar">'
            + '<span class="b-ok" style="width:' + (rv[1] / N * 100) + '%"></span>'
            + '<span class="b-issue" style="width:' + (rv[2] / N * 100) + '%"></span>'
            + '<span class="b-chk" style="width:' + (rv[3] / N * 100) + '%"></span>'
            + '<span class="b-none" style="width:' + (rv[0] / N * 100) + '%"></span>'
            + '</div>'
            + '<div class="pcw-ins-legend">'
            + '<span class="pcw-ins-lg"><span class="dot ok"></span>Verified ' + rv[1] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot issue"></span>Issue ' + rv[2] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot chk"></span>Reviewing ' + rv[3] + '</span>'
            + '<span class="pcw-ins-lg"><span class="dot none"></span>Unmarked ' + rv[0] + '</span>'
            + '</div>';

        // Deductions mix — part-of-whole donut, legend carries the values
        // (visible labels are the relief for the low-contrast aqua/yellow slots)
        var mix = [
            { l: 'Contributions',    v: 0, c: '#2a78d6' },
            { l: 'Loans',            v: 0, c: '#eb6834' },
            { l: 'Other deductions', v: 0, c: '#1baf7a' },
            { l: 'Advances & tax',   v: 0, c: '#eda100' }
        ];
        D.forEach(function (e) {
            (e.deds || []).forEach(function (d) {
                if (d.g === 1) mix[0].v += d.amt; else if (d.g === 3) mix[1].v += d.amt; else mix[2].v += d.amt;
            });
            mix[3].v += (e.sss_fund || 0) + (e.jei || 0) + (e.jcc || 0) + (e.tax || 0) + (e.other_ded || 0);
        });
        var mixTot = mix.reduce(function (s, m) { return s + Math.max(0, m.v); }, 0);
        if (mixTot > 0) {
            h += '<div class="pcw-ins-sec">Deductions mix</div>'
                + '<div class="pcw-chart-card"><div class="pcw-donut-wrap">'
                + donutSVG(mix, mixTot) + '<div class="pcw-cl">';
            mix.forEach(function (m) {
                if (m.v <= 0) return;
                h += '<div class="pcw-cl-row"><span class="sw" style="background:' + m.c + '"></span>'
                    + '<span class="lb" title="' + esc(m.l) + ' — ' + peso(m.v) + '">' + m.l + '</span>'
                    + '<span class="vl">' + pesoShort(m.v) + '</span>'
                    + '<span class="pc">' + Math.round(m.v / mixTot * 100) + '%</span></div>';
            });
            h += '</div></div></div>';
        }

        // Net pay by department — one measure, one hue; click a bar to filter
        var depts = {};
        D.forEach(function (e) {
            var k = e.dept || 'No department';
            depts[k] = (depts[k] || 0) + e.net;
        });
        var dl = Object.keys(depts).map(function (k) { return { l: k, v: depts[k] }; })
            .sort(function (a, b) { return b.v - a.v; });
        if (dl.length > 1) {
            var topD = dl.slice(0, 6), restD = dl.slice(6);
            if (restD.length) topD.push({ l: 'Other (' + restD.length + ' depts)', v: restD.reduce(function (s, d) { return s + d.v; }, 0), other: true });
            var mxD = topD.reduce(function (s, d) { return Math.max(s, d.v); }, 0) || 1;
            h += '<div class="pcw-ins-sec">Net pay by department</div><div class="pcw-chart-card"><div class="pcw-hbar">';
            topD.forEach(function (d) {
                var on = !d.other && S.dept === d.l;
                h += '<div class="pcw-hbar-row' + (on ? ' on' : '') + '"' + (d.other ? '' : ' data-dept="' + esc(d.l) + '"')
                    + ' title="' + esc(d.l) + ' — ' + peso(d.v) + (d.other ? '' : ' (click to filter the list)') + '">'
                    + '<div class="pcw-hbar-top"><span class="lb">' + esc(d.l) + '</span><span class="vl">' + pesoShort(d.v) + '</span></div>'
                    + '<div class="pcw-hbar-track"><span class="bar-fill" style="width:' + Math.max(2, d.v / mxD * 100) + '%"></span></div>'
                    + '</div>';
            });
            h += '</div></div>';
        }

        // Exceptions — clickable chips that filter the left list
        var defs = EXC_DEFS.filter(function (d) { return !d.needPrev || hasPrev; });
        var counts = {}, totalExc = 0;
        defs.forEach(function (d) {
            counts[d.k] = D.filter(function (e) { return excPred(d.k, e); }).length;
            totalExc += counts[d.k];
        });
        h += '<div class="pcw-ins-sec">Exceptions to review'
            + (S.exc ? ' <span id="pcw-ins-clear" style="float:right;color:#c62828;cursor:pointer;font-weight:700;"><i class="ri-close-circle-line"></i> clear filter</span>' : '')
            + '</div>';
        if (totalExc === 0) {
            h += '<div class="pcw-ins-allclear"><i class="ri-checkbox-circle-line"></i> No exceptions — this batch looks clean.</div>';
        } else {
            h += '<div class="pcw-exc-chips">';
            defs.forEach(function (d) {
                var n = counts[d.k];
                var muted = n === 0 ? ' muted' : '';
                var on = S.exc === d.k ? ' on' : '';
                h += '<button type="button" class="pcw-exc ' + d.cls + muted + on + '" data-exc="' + d.k + '"' + (n === 0 ? ' disabled' : '') + '>'
                    + '<span class="ic"><i class="' + d.ic + '"></i></span>'
                    + '<span class="tx">' + d.lbl + '</span>'
                    + '<span class="n">' + n + '</span>'
                    + '</button>';
            });
            h += '</div>';
        }

        // Biggest net movers vs previous payroll
        if (hasPrev) {
            var movers = D.filter(function (e) { return e.prev_net != null && e.prev_net != 0; })
                .map(function (e) { return { name: e.name, id: e.id, pct: (e.net - e.prev_net) / e.prev_net * 100 }; })
                .sort(function (a, b) { return Math.abs(b.pct) - Math.abs(a.pct); })
                .slice(0, 5);
            if (movers.length) {
                h += '<div class="pcw-ins-sec">Biggest changes vs ' + esc(M.prev_label) + '</div>';
                movers.forEach(function (m) {
                    var up = m.pct >= 0;
                    h += '<div class="pcw-ins-mover" data-eid="' + m.id + '" style="cursor:pointer;">'
                        + '<span class="nm">' + esc(m.name) + '</span>'
                        + '<span class="pct ' + (up ? 'up' : 'down') + '">' + (up ? '▲' : '▼') + ' ' + Math.abs(m.pct).toFixed(0) + '%</span>'
                        + '</div>';
                });
            }
        }

        box.innerHTML = h;
    }

    // ── Chart helpers (inline SVG / divs — no libraries) ──
    // Compact peso figure for chart labels; the full amount lives in the tooltip.
    function pesoShort(n) {
        n = Number(n) || 0;
        var a = Math.abs(n);
        if (a >= 1e6) return '₱' + (n / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M';
        if (a >= 1e3) return '₱' + (n / 1e3).toFixed(a >= 1e5 ? 0 : 1) + 'k';
        return '₱' + Math.round(n);
    }
    // Donut with a 2px surface gap between slices and the total in the center.
    function donutSVG(items, total) {
        var r = 40, C = 2 * Math.PI * r, gap = 2, acc = 0;
        var svg = '<svg class="pcw-donut" width="110" height="110" viewBox="0 0 110 110" role="img" aria-label="Deductions mix">';
        items.forEach(function (m) {
            if (m.v <= 0) return;
            var len = m.v / total * C;
            var vis = Math.max(0.5, len - gap);
            svg += '<circle r="' + r + '" cx="55" cy="55" fill="none" stroke="' + m.c + '" stroke-width="17"'
                + ' stroke-dasharray="' + vis + ' ' + (C - vis) + '" stroke-dashoffset="' + (-acc) + '"'
                + ' transform="rotate(-90 55 55)"><title>' + esc(m.l) + ' — ' + peso(m.v) + '</title></circle>';
            acc += len;
        });
        svg += '<text x="55" y="53" text-anchor="middle" class="dc-v">' + pesoShort(total) + '</text>'
            + '<text x="55" y="65" text-anchor="middle" class="dc-l">deductions</text></svg>';
        return svg;
    }

    // Insights interactions: exception chips + dept bars filter the list;
    // movers jump to an employee; the inline "clear filter" link resets.
    (function () {
        var box = byId('pcw-insights');
        if (box) box.addEventListener('click', function (ev) {
            if (ev.target.closest('#pcw-ins-clear')) {
                S.exc = '';
                buildList();
                renderInsights();
                return;
            }
            var chip = ev.target.closest('.pcw-exc[data-exc]');
            if (chip && !chip.disabled) {
                var k = chip.getAttribute('data-exc');
                S.exc = (S.exc === k) ? '' : k;
                buildList();
                renderInsights();
                return;
            }
            var db = ev.target.closest('.pcw-hbar-row[data-dept]');
            if (db) {
                var dept = db.getAttribute('data-dept');
                S.dept = (S.dept === dept) ? '' : dept;
                byId('pcw-dept').value = S.dept;
                csRefresh(byId('pcw-dept'));
                // Same cascade as the Department dropdown — this bar is just
                // another way to set it. (Hoisted; defined with the filters.)
                pcwSyncAreas();
                buildList();
                pcwUpdFilterCount();
                renderInsights();
                return;
            }
            var mv = ev.target.closest('.pcw-ins-mover[data-eid]');
            if (mv) select(parseInt(mv.getAttribute('data-eid'), 10));
        });
    })();

    // ── Excel export of the Table View ──────────────────────────────────────
    // PhpSpreadsheet's HTML reader understands inline style attributes only —
    // not classes or <style> blocks — so the page palette is stamped onto the
    // clone here, cell by cell. Exporting the rendered DOM (rather than redoing
    // ~40 columns of arithmetic server-side) keeps the workbook and the screen
    // permanently in agreement.
    var XL_FILL = {
        // group band (header row 1) → [background, text]
        head1: {
            'primary-header': ['#e1dcec', '#4f3288'],
            'info-header':    ['#dde9f8', '#1e50a0'],
            'success-header': ['#d9eedd', '#107c41'],
            'danger-header':  ['#fbe3e6', '#b02a37']
        },
        // column labels (header row 2) — lighter tint of the same family
        head2: {
            'primary-header': ['#f0edf5', '#4f3288'],
            'info-header':    ['#eef4fc', '#1e50a0'],
            'success-header': ['#ebf7ee', '#107c41'],
            'danger-header':  ['#fdf0f1', '#b02a37']
        },
        // reviewer colour marks carried over as row tints
        row: { 'review-ok': '#e9f7ee', 'review-issue': '#fff4e2', 'review-checking': '#e8f1fb' }
    };
    function xlHeadStyle(th, map) {
        var key = ['primary-header', 'info-header', 'success-header', 'danger-header']
            .find(function (c) { return th.classList.contains(c); }) || 'primary-header';
        var c = map[key];
        return 'background-color:' + c[0] + ';color:' + c[1] + ';font-weight:bold;'
            + 'border:1px solid #cfc8de;text-align:center;';
    }
    window.pcwExportTableExcel = function (payrollId) {
        var src = document.getElementById('table-1');
        if (!src) { Swal.fire({ icon: 'error', title: 'Nothing to export', text: 'Open Table View first.' }); return; }
        Swal.fire({ title: 'Building workbook…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        // Let the spinner paint before the (synchronous) DOM walk on a big batch.
        setTimeout(function () {
            try {
                // cloneNode copies an input's ATTRIBUTE, not its live value, so an
                // open payroll the user has just edited would export stale figures.
                // Stamp the live values onto the attributes first — harmless on the
                // page, since a dirty input keeps showing its own value.
                src.querySelectorAll('input').forEach(function (inp) {
                    if (inp.type !== 'checkbox') inp.setAttribute('value', inp.value);
                });

                var t = src.cloneNode(true);

                // Inputs are the live data store — replace each with its value so
                // the export carries figures, not empty form controls.
                t.querySelectorAll('input').forEach(function (inp) {
                    if (inp.type === 'checkbox') { inp.parentNode.removeChild(inp); return; }
                    var span = document.createElement('span');
                    span.textContent = (inp.value || '').replace(/,/g, '');   // raw number → Excel parses it
                    inp.parentNode.replaceChild(span, inp);
                });
                // Buttons / icons / avatars / badges carry no meaning in a
                // spreadsheet, and their text would run into the real content
                // ("QAABAD, QUERWIN" from the avatar initials).
                t.querySelectorAll('button, .review-mark-btn, i, svg, .rv-dot, .emp-avatar, .pcw-fdot, .nd-badge, .pp-delta, .pay-badge, [data-badges]')
                    .forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });

                // The Name cell stacks name + employee no. In one flat Excel cell
                // those would concatenate ("ABAD, QUERWIN2026-00062"), so keep the
                // name here and move the number to its own column.
                var splitNo = t.querySelector('td .emp-cell') !== null;
                t.querySelectorAll('td .emp-cell').forEach(function (cell) {
                    var nm = cell.querySelector('.emp-name-link');
                    var no = cell.querySelector('.emp-no');
                    var td = cell.closest('td');
                    td.textContent = nm ? nm.textContent.trim() : cell.textContent.trim();
                    if (no) {
                        var extra = document.createElement('td');
                        extra.textContent = no.textContent.trim();
                        td.parentNode.insertBefore(extra, td.nextSibling);
                    }
                });
                // …and give that column a header in both header rows.
                if (splitNo) {
                    var hrows = t.querySelectorAll('thead tr');
                    if (hrows[0]) {
                        var th = document.createElement('th');
                        th.textContent = 'Employee No.';
                        th.setAttribute('rowspan', '2');
                        th.className = 'primary-header';
                        th.setAttribute('style', xlHeadStyle(th, XL_FILL.head1));
                        hrows[0].insertBefore(th, hrows[0].children[2] || null);
                    }
                    // tfoot's TOTAL cell spans No.+Name — widen it over the new column.
                    var tot = t.querySelector('tfoot .tfoot-total-cell');
                    if (tot) tot.setAttribute('colspan', String((parseInt(tot.getAttribute('colspan'), 10) || 2) + 1));
                }
                // The Adjustment cell stacks the amount over its remark ("1,000.00"
                // / "For other payment"). Flattened they concatenate and the column
                // stops being numeric, so the remark moves to its own column.
                // Pass 1: locate the column (only some rows carry a remark).
                var adjIdx = -1;
                t.querySelectorAll('tbody tr').forEach(function (tr) {
                    if (adjIdx !== -1) return;
                    for (var i = 0; i < tr.children.length; i++) {
                        if (tr.children[i].querySelector('.text-muted') && tr.children[i].querySelector('b')) { adjIdx = i; return; }
                    }
                });
                // Pass 2: split it on EVERY row — a row with no remark still needs
                // the placeholder cell, or the columns after it shift left.
                if (adjIdx !== -1) {
                    t.querySelectorAll('tbody tr').forEach(function (tr) {
                        var cell = tr.children[adjIdx];
                        if (!cell) return;
                        var note = cell.querySelector('.text-muted');
                        var amt = cell.querySelector('b');
                        var extra = document.createElement('td');
                        extra.textContent = note ? note.textContent.trim() : '';
                        cell.textContent = (amt ? amt.textContent : cell.textContent).trim().replace(/,/g, '');
                        tr.insertBefore(extra, cell.nextSibling);
                    });
                }
                if (adjIdx !== -1) {
                    // Header: the Adjustment th spans both header rows, so the new
                    // label only needs inserting into row 1.
                    var h0 = t.querySelectorAll('thead tr')[0];
                    if (h0) {
                        var col = 0, at = null;
                        for (var k = 0; k < h0.children.length; k++) {
                            if (col === adjIdx) { at = h0.children[k]; break; }
                            col += h0.children[k].colSpan || 1;
                        }
                        var rth = document.createElement('th');
                        rth.textContent = 'Adjustment Remark';
                        rth.setAttribute('rowspan', '2');
                        rth.className = 'primary-header';
                        rth.setAttribute('style', xlHeadStyle(rth, XL_FILL.head1));
                        if (at) h0.insertBefore(rth, at.nextSibling); else h0.appendChild(rth);
                    }
                    // tfoot: walk colspans to the same column, then pad it.
                    t.querySelectorAll('tfoot tr').forEach(function (tr) {
                        var col = 0;
                        for (var k = 0; k < tr.children.length; k++) {
                            var span = tr.children[k].colSpan || 1;
                            if (col + span > adjIdx) {
                                tr.insertBefore(document.createElement('th'), tr.children[k].nextSibling);
                                return;
                            }
                            col += span;
                        }
                    });
                }

                // The table repeats the row number in a trailing "No." column so
                // the index stays visible when scrolled far right. A spreadsheet
                // has row numbers of its own, so drop that last column entirely.
                var lastHead = t.querySelectorAll('thead tr')[0];
                var lastTh = lastHead && lastHead.lastElementChild;
                if (lastTh && lastTh.textContent.trim() === 'No.' && lastTh.rowSpan === 2) {
                    lastTh.parentNode.removeChild(lastTh);
                    t.querySelectorAll('tbody tr, tfoot tr').forEach(function (tr) {
                        if (tr.lastElementChild) tr.removeChild(tr.lastElementChild);
                    });
                }

                // One-off items are already inside the Gross / Total Deduction /
                // Net figures, but nothing in the workbook says WHAT they were.
                // Append a trailing annotation column naming them per employee.
                if (D.some(function (x) { return (x.extras || []).length; })) {
                    var h0x = t.querySelectorAll('thead tr')[0];
                    if (h0x) {
                        var xth = document.createElement('th');
                        xth.textContent = 'One-off Items';
                        xth.setAttribute('rowspan', '2');
                        xth.className = 'primary-header';
                        xth.setAttribute('style', xlHeadStyle(xth, XL_FILL.head1));
                        h0x.appendChild(xth);
                    }
                    t.querySelectorAll('tbody tr').forEach(function (tr) {
                        var emp = empById(parseInt(tr.getAttribute('data-row-id'), 10));
                        var td = document.createElement('td');
                        td.textContent = ((emp && emp.extras) || []).map(function (x) {
                            return x.label + ' ' + (x.kind === 2 ? '+' : '−') + fmt(x.amt);
                        }).join('; ');
                        tr.appendChild(td);
                    });
                    t.querySelectorAll('tfoot tr').forEach(function (tr) {
                        tr.appendChild(document.createElement('th'));
                    });
                }

                // Leading text columns (No., Name, [Employee No.], Position) — the
                // server needs the count to know where the money columns begin.
                var identityCols = splitNo ? 4 : 3;

                // Header rows: group band, then column labels.
                var headRows = t.querySelectorAll('thead tr');
                if (headRows[0]) headRows[0].querySelectorAll('th').forEach(function (th) {
                    th.setAttribute('style', xlHeadStyle(th, XL_FILL.head1));
                });
                if (headRows[1]) headRows[1].querySelectorAll('th').forEach(function (th) {
                    th.setAttribute('style', xlHeadStyle(th, XL_FILL.head2));
                });

                // Body: reviewer mark tint + strip thousands separators so the
                // server can format the columns as real numbers.
                t.querySelectorAll('tbody tr').forEach(function (tr) {
                    var tint = Object.keys(XL_FILL.row).find(function (c) { return tr.classList.contains(c); });
                    tr.querySelectorAll('td').forEach(function (td) {
                        var txt = td.textContent.trim();
                        if (/^\(?-?[\d,]+\.?\d*\)?$/.test(txt) && txt.indexOf(',') !== -1) {
                            td.textContent = txt.replace(/,/g, '');
                        }
                        td.setAttribute('style', 'border:1px solid #e4e7e5;'
                            + (tint ? 'background-color:' + XL_FILL.row[tint] + ';' : ''));
                    });
                });

                // Totals row — bold on the same green the screen uses.
                t.querySelectorAll('tfoot th').forEach(function (th) {
                    th.textContent = th.textContent.trim().replace(/,/g, '');
                    th.setAttribute('style', 'background-color:#d9eedd;color:#0e6b37;font-weight:bold;border:1px solid #c0e0c8;');
                });

                // A plain form post lets the browser handle the download itself —
                // no blob juggling, and the file lands with the server's filename.
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = 'export-payroll-table.php';
                f.style.display = 'none';
                [['id', payrollId], ['identity', identityCols], ['html', t.outerHTML]].forEach(function (kv) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
                    f.appendChild(i);
                });
                document.body.appendChild(f);
                f.submit();
                setTimeout(function () { document.body.removeChild(f); Swal.close(); }, 1500);
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Export failed', text: err.message || 'Could not build the workbook.' });
            }
        }, 60);
    };

    // ── Right-column tabs (Summary · Insights · Review) ──
    // The last-open tab is remembered per browser; a remembered tab that no
    // longer exists (e.g. Review on an Open batch) quietly falls back to Summary.
    var PCW_RTAB_KEY = 'pcw-right-tab';
    function pcwSelectRightTab(tab) {
        var btn = document.querySelector('.pcw-rtab[data-rtab="' + tab + '"]');
        if (!btn) return;
        document.querySelectorAll('.pcw-rtab').forEach(function (b) { b.classList.toggle('active', b === btn); });
        document.querySelectorAll('.pcw-rpane').forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-rpane') === tab);
        });
        try { localStorage.setItem(PCW_RTAB_KEY, tab); } catch (e) { /* private mode */ }
    }
    (function () {
        var rtabs = byId('pcw-rtabs');
        if (!rtabs) return;
        rtabs.addEventListener('click', function (ev) {
            var b = ev.target.closest('.pcw-rtab');
            if (b) pcwSelectRightTab(b.getAttribute('data-rtab'));
        });
        try {
            var saved = localStorage.getItem(PCW_RTAB_KEY);
            if (saved) pcwSelectRightTab(saved);
        } catch (e) { /* keep the default tab */ }
    })();

    // ── Payslip sign-off conversation ──
    // Reads window.PCW_REVIEWS (employee_id → decision + message + HR reply)
    // and shows it as a two-bubble thread. Global: the Employee Review rows and
    // the left-list chat buttons both call it.
    var EMP_RV_META = {
        1: { cls: 'ok',   ic: 'ri-checkbox-circle-line', lbl: 'Confirmed' },
        2: { cls: 'disp', ic: 'ri-error-warning-line',   lbl: 'Disputed' }
    };
    window.pcwOpenReviewConvo = function (empId) {
        var r = (window.PCW_REVIEWS || {})[empId];
        var box = byId('mer-body'), btn = byId('mer-reply');
        if (!box) return;
        if (!r) {
            box.innerHTML = '<div class="mer-empty"><i class="ri-chat-off-line"></i> This employee has not reviewed their payslip yet.</div>';
            btn.style.display = 'none';
        } else {
            var meta = EMP_RV_META[r.st] || EMP_RV_META[1];
            var h = '<div class="mer-head">'
                + '<span class="mer-name">' + esc(r.name) + '</span>'
                + '<span class="mer-badge ' + meta.cls + '"><i class="' + meta.ic + '"></i>' + meta.lbl + '</span>'
                + (r.at ? '<span class="mer-when">' + esc(r.at) + '</span>' : '')
                + '</div>';
            h += '<div class="pcw-chat">';
            h += r.c
                ? '<div class="pcw-bub them">' + esc(r.c) + '<span class="m">' + esc(r.name) + (r.at ? ' · ' + esc(r.at) : '') + '</span></div>'
                : '<div class="mer-empty">' + meta.lbl + ' without a message.</div>';
            if (r.rep) {
                h += '<div class="pcw-bub me">' + esc(r.rep)
                    + '<span class="m">HR reply' + (r.rep_at ? ' · ' + esc(r.rep_at) : '') + '</span></div>';
            }
            h += '</div>';
            box.innerHTML = h;
            // Anything with a message and no reply yet can be answered — a
            // dispute to resolve, or a confirmation that asked something.
            var canReply = !r.rep && (r.st === 2 || (r.st === 1 && !!r.c));
            var isDisp = r.st === 2;
            btn.style.display = canReply ? '' : 'none';
            btn.innerHTML = isDisp
                ? '<i class="ri-chat-check-line me-1"></i>Resolve &amp; Reply'
                : '<i class="ri-chat-1-line me-1"></i>Reply';
            btn.onclick = canReply ? function () { openResolveDispute('payroll', r.id, r.name, isDisp); } : null;
            if (r.new) pcwMarkReviewSeen(empId, r);
        }
        bootstrap.Modal.getOrCreateInstance(byId('modal-emp-review')).show();
    };

    // Opening a message clears its UNREAD state — server first, then the badges
    // in the panel, the card list and the header count, without a reload.
    function pcwMarkReviewSeen(empId, r) {
        r.new = 0;
        var url = 'ajax.php?action=mark_review_seen';
        var body = 'type=payroll&id=' + encodeURIComponent(r.id);
        fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
        }).catch(function () { /* the badge still clears locally */ });

        var row = document.querySelector('.prp-row[data-emp="' + empId + '"]');
        if (row) {
            row.classList.remove('unread');
            var tag = row.querySelector('.prp-row-new');
            if (tag) tag.remove();
        }
        var n = byId('prp-unread-n'), chip = byId('prp-unread-chip');
        if (n) {
            var left = Math.max(0, (parseInt(n.textContent, 10) || 0) - 1);
            n.textContent = left;
            if (!left && chip) chip.style.display = 'none';
        }
        buildList();
    }
    // The review rows are role="button" divs, which browsers focus but do not
    // activate on Enter/Space the way a real <button> does — wire that up.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        var row = ev.target.closest ? ev.target.closest('.prp-row.has-msg') : null;
        if (!row) return;
        ev.preventDefault();
        row.click();
    });

    // Daily Time Record modal — renders the shared Form 48 sheet from this
    // payroll's approved DTR rows (identical template to dtr-documents.php).
    window.pcwOpenDtr = function (itemId) {
        var e = empById(itemId);
        if (!e) return;
        var body = document.getElementById('al-body');
        if (window.DTRForm48) {
            body.innerHTML = window.DTRForm48.render({
                name: e.name,
                periodLabel: M.from + ' – ' + M.to,
                dateFrom: M.from_iso,
                dateTo: M.to_iso,
                logMode: M.log_mode || 'single',
                compact: true,
                days: (e.dtr && e.dtr.days) || {},
                marks: (e.dtr && e.dtr.marks) || {},
                totals: (e.dtr && e.dtr.totals) || { wh: 0, ot: 0, ut: 0, late: 0 }
            });
        } else {
            body.innerHTML = '<div class="text-center text-muted py-4">DTR template failed to load.</div>';
        }
        // Approved Attendance Logs — the same per-day punch tables the old
        // Logs modal showed (# / Time / Direction / Source), kept below the
        // Form 48 sheet.
        var logsBox = document.getElementById('al-logs');
        if (logsBox) {
            var days = (e.dtr && e.dtr.days) || {};
            var dates = Object.keys(days).sort();
            var lh = '';
            dates.forEach(function (iso) {
                var d = days[iso];
                var dDate = new Date(iso + 'T00:00:00');
                var dLbl = dDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                    + ' (' + dDate.toLocaleDateString('en-US', { weekday: 'short' }) + ')';
                lh += '<div class="al-day">'
                    + '<div class="al-day-head">'
                    + '<span class="al-day-date"><i class="ri-calendar-line me-1"></i>' + esc(dLbl) + '</span>'
                    + '<span class="al-day-hrs">' + fmt(d.wh) + ' hrs</span>'
                    + '</div>';
                var lgs = d.logs || [];
                if (lgs.length) {
                    lh += '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0 al-table">'
                        + '<thead class="table-dark"><tr>'
                        + '<th class="text-center" style="width:36px;">#</th>'
                        + '<th><i class="ri-time-line me-1"></i>Time</th>'
                        + '<th class="text-center" style="width:80px;">Direction</th>'
                        + '<th class="text-center" style="width:80px;">Source</th>'
                        + '</tr></thead><tbody>';
                    lgs.forEach(function (l, li) {
                        var dir = li === 0 ? '<span class="att-log-dir att-log-in">IN</span>'
                            : (li === lgs.length - 1 ? '<span class="att-log-dir att-log-out">OUT</span>'
                                : '<span class="text-muted small">&mdash;</span>');
                        var src = l.bio
                            ? '<span class="badge att-log-bio"><i class="ri-fingerprint-line me-1"></i>Bio</span>'
                            : '<span class="badge att-log-manual"><i class="ri-edit-line me-1"></i>Manual</span>';
                        lh += '<tr>'
                            + '<td class="text-center text-muted">' + (li + 1) + '</td>'
                            + '<td class="fw-semibold">' + esc(l.t) + '</td>'
                            + '<td class="text-center">' + dir + '</td>'
                            + '<td class="text-center">' + src + '</td>'
                            + '</tr>';
                    });
                    lh += '</tbody></table></div>';
                } else {
                    lh += '<span class="text-muted small">No logs</span>';
                }
                lh += '</div>';
            });
            logsBox.innerHTML = dates.length ? lh
                : '<div class="pcw-tab-empty"><i class="ri-history-line"></i> No approved attendance logs.</div>';
        }
        // Admin ↔ employee record messages (read-only conversation)
        var msgsBox = document.getElementById('al-msgs');
        var msgs = e.msgs || [];
        if (msgsBox) {
            if (msgs.length) {
                var mh = '<div class="pcw-chat">';
                msgs.forEach(function (m) {
                    mh += '<div class="pcw-bub ' + (m.from === 'emp' ? 'them' : 'me') + '">'
                        + (m.date ? '<span class="pcw-bub-day">' + esc(m.date) + '</span>' : '')
                        + esc(m.msg)
                        + '<span class="m">' + esc(m.by || (m.from === 'emp' ? 'Employee' : 'Admin')) + (m.at ? ' · ' + esc(m.at) : '') + '</span>'
                        + '</div>';
                });
                mh += '</div>';
                msgsBox.innerHTML = mh;
            } else {
                msgsBox.innerHTML = '<div class="pcw-tab-empty"><i class="ri-chat-off-line"></i> No messages on this employee\'s records.</div>';
            }
        }
        var notesBox = document.getElementById('al-notes');
        if (notesBox) notesBox.innerHTML = notesHTML(e);

        // Tab counts + reset to the DTR tab each time the modal opens
        var nMsgs = msgs.length, nNotes = (e.notes || []).length;
        var cM = byId('al-tab-msgs'), cN = byId('al-tab-notes');
        if (cM) { cM.textContent = nMsgs; cM.style.display = nMsgs ? '' : 'none'; }
        if (cN) { cN.textContent = nNotes; cN.style.display = nNotes ? '' : 'none'; }
        pcwSelectDtrTab('dtr');

        document.getElementById('al-employee').textContent = e.name;
        document.getElementById('al-days-count').textContent = e.dtr_days
            ? e.dtr_days + ' approved day' + (e.dtr_days === 1 ? '' : 's') : 'No approved attendance';

        // Footer totals — same figures the Form 48 TOTAL row shows, plus this
        // employee's standing in the batch's review round.
        var tot = (e.dtr && e.dtr.totals) || { wh: 0, ot: 0, ut: 0, late: 0 };
        // OT chip shows what payroll PAYS: per day min(rendered, approved
        // hours), 0 with no approved request — the same rule as the sheet's
        // TOTAL row and calculate_payroll, so all three always agree.
        var otPaid = 0, dtrDays = (e.dtr && e.dtr.days) || {}, dtrMarks = (e.dtr && e.dtr.marks) || {};
        Object.keys(dtrDays).forEach(function (iso) {
            var dot = Number(dtrDays[iso].ot || 0);
            if (dot <= 0) return;
            var appr = false, apprH = 0;
            (dtrMarks[iso] || []).forEach(function (m) {
                // Either hour-carrying filing authorizes the day (a rest-day
                // request names the whole day; the min() below caps it at what
                // the scans rendered) — same set payroll reads server-side.
                if (m.k === 'req' && (m.t === 'overtime' || m.t === 'rest_day') && m.s === 1) {
                    appr = true; apprH += Number(m.h || 0);
                }
            });
            if (appr) otPaid += apprH > 0 ? Math.min(dot, apprH) : dot;
        });
        [['al-tot-wh', tot.wh, 2], ['al-tot-ot', otPaid, 2],
         ['al-tot-ut', tot.ut, 2], ['al-tot-late', tot.late, 0]].forEach(function (t) {
            var el = byId(t[0]);
            if (!el) return;
            var v = Number(t[1]) || 0;
            el.textContent = v.toFixed(t[2]);
            el.parentNode.classList.toggle('zero', v === 0);
        });
        var rvChip = byId('al-emp-rv');
        if (rvChip) {
            var rvMap = {
                1: ['ok',   'ri-checkbox-circle-line',  'Employee confirmed'],
                2: ['bad',  'ri-error-warning-line',    'Employee disputed'],
                0: ['wait', 'ri-time-line',             'Awaiting employee review']
            };
            var rv = (M.status === 2 || M.status === 3) ? (rvMap[e.emp_rv] || rvMap[0]) : null;
            rvChip.className = 'al-fs rv' + (rv ? ' ' + rv[0] : '');
            rvChip.style.display = rv ? '' : 'none';
            if (rv) rvChip.innerHTML = '<i class="' + rv[1] + '"></i>' + rv[2];
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-att-logs')).show();
    };
    // Switch the active tab/pane inside the DTR Details modal.
    function pcwSelectDtrTab(tab) {
        document.querySelectorAll('#al-tabs .pcw-tab').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === tab);
        });
        document.querySelectorAll('.pcw-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.getAttribute('data-pane') === tab);
        });
    }
    var alTabs = document.getElementById('al-tabs');
    if (alTabs) alTabs.addEventListener('click', function (ev) {
        var b = ev.target.closest('.pcw-tab');
        if (b) pcwSelectDtrTab(b.getAttribute('data-tab'));
    });
    // Table Actions buttons carry "empId-siteId" keys — map them to an item.
    window.pcwOpenDtrByKey = function (key) {
        for (var i = 0; i < D.length; i++) {
            if (D[i].emp + '-' + D[i].site === key) return window.pcwOpenDtr(D[i].id);
        }
    };

    // ── Selection (the card list is the single source of truth) ──
    function psIds() { return Object.keys(S.ps).filter(function (k) { return S.ps[k]; }); }
    function updPsPill() {
        var n = psIds().length;
        var pill = byId('pcw-ps-count');
        if (pill) pill.textContent = n;
        var all = byId('pcw-sel-all');
        if (all) {
            var vis = S.list.length;
            var on = S.list.filter(function (e) { return S.ps[e.id]; }).length;
            all.checked = vis > 0 && on === vis;
            all.indeterminate = on > 0 && on < vis;
        }
        // Bulk bar slides in only while something is selected
        var bulk = byId('pcw-bulk');
        if (bulk) bulk.classList.toggle('show', n > 0);
        var bn = byId('pcw-bulk-count');
        if (bn) bn.textContent = n;
    }
    // printSelectedPayslips() (payroll_calculations.js) reads the selection here.
    window.pcwSelectedPayslipIds = psIds;
    window.pcwClearSelection = function () {
        S.ps = {};
        document.querySelectorAll('#pcw-list input[data-ps]').forEach(function (c) { c.checked = false; });
        updPsPill();
    };

    // ── Bulk progress bar ──
    function bulkProgress(done, total, label) {
        var box = byId('pcw-bulk-prog');
        if (!box) return;
        box.classList.add('show');
        byId('pcw-bulk-prog-txt').textContent = label || 'Working…';
        byId('pcw-bulk-prog-n').textContent = done + ' / ' + total;
        byId('pcw-bulk-bar-fill').style.width = (total ? (done / total * 100) : 0) + '%';
    }
    function bulkProgressDone() {
        var box = byId('pcw-bulk-prog');
        if (box) setTimeout(function () { box.classList.remove('show'); }, 900);
    }

    // ── Bulk: set the reviewer mark on every selected employee ──
    window.pcwBulkReview = function (statusVal) {
        var ids = psIds();
        if (!ids.length) return;
        var meta = RV_META[statusVal];
        var label = meta ? meta.lbl : 'No mark';
        Swal.fire({
            title: 'Mark ' + ids.length + ' employee(s) as "' + label + '"?',
            input: statusVal === 0 ? undefined : 'text',
            inputPlaceholder: 'Optional comment for all selected…',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#6642aa', confirmButtonText: 'Apply to ' + ids.length
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var comment = statusVal === 0 ? '' : (r.value || '').trim();
            var done = 0, failed = 0;
            bulkProgress(0, ids.length, 'Applying "' + label + '"…');
            // Sequential so a big selection can't flood the server.
            (function next(i) {
                if (i >= ids.length) {
                    bulkProgress(ids.length, ids.length, 'Done');
                    bulkProgressDone();
                    buildList(); renderInsights();
                    var cur = empById(S.sel);
                    if (cur) renderSum(cur);
                    showToast(failed
                        ? (done + ' updated, ' + failed + ' failed.')
                        : (done + ' employee(s) marked as "' + label + '".'), failed ? 'warning' : 'success');
                    return;
                }
                var id = ids[i];
                $.ajax({
                    url: 'ajax.php?action=set_payroll_item_review', method: 'POST',
                    data: { id: id, review_status: statusVal, review_comment: comment }
                }).done(function () {
                    var e = empById(id);
                    if (e) { e.rv = statusVal; e.rv_c = comment; }
                    // keep the (hidden) table row in step for saves/printing
                    if (typeof window.applyReviewToRow === 'function') {
                        var tr = document.querySelector('tr[data-row-id="' + id + '"]');
                        if (tr) {
                            tr.classList.remove('review-ok', 'review-issue', 'review-checking');
                            var cls = { 1: 'review-ok', 2: 'review-issue', 3: 'review-checking' }[statusVal];
                            if (cls) tr.classList.add(cls);
                            tr.setAttribute('data-review', statusVal);
                            tr.setAttribute('data-review-comment', comment);
                            setReviewInputLock(tr, statusVal === 1);   // honours an admin unlock
                        }
                    }
                    done++;
                }).fail(function () { failed++; })
                  .always(function () { bulkProgress(done + failed, ids.length, 'Applying "' + label + '"…'); next(i + 1); });
            })(0);
        });
    };

    // ── Bulk: ask the selected employees to review their payslip ──
    // Repeatable — every send bumps that employee's counter.
    window.pcwNotifySelected = function () {
        var ids = psIds();
        if (!ids.length) return;
        var again = ids.filter(function (id) { var e = empById(id); return e && e.sent_n > 0; }).length;
        Swal.fire({
            title: 'Notify ' + ids.length + ' employee(s)?',
            html: 'They will be asked to review their payslip.'
                + (again ? '<div style="margin-top:8px;font-size:12px;color:#c98a00;"><b>' + again + '</b> of them were already notified before — this sends again.</div>' : '')
                + '<div style="margin-top:8px;font-size:12px;color:#888;">The payroll status is not changed.</div>',
            icon: 'question', showCancelButton: true,
            confirmButtonColor: '#6642aa', confirmButtonText: 'Send now'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            bulkProgress(0, ids.length, 'Notifying employees…');
            $.ajax({
                url: 'ajax.php?action=notify_payroll_review_selected', method: 'POST', dataType: 'JSON',
                data: { id: M.id, item_ids: ids.join(',') }
            }).done(function (res) {
                if (!(res && res.result)) {
                    bulkProgressDone();
                    Swal.fire({ icon: 'error', title: 'Not sent', text: (res && res.message) || 'Request failed.' });
                    return;
                }
                // Apply the fresh per-employee counters returned by the server
                var sent = res.sent || {};
                ids.forEach(function (id) {
                    var e = empById(id);
                    if (!e) return;
                    if (sent[id]) { e.sent_n = sent[id].n; e.sent_at = sent[id].at; }
                    else { e.sent_n = (e.sent_n || 0) + 1; }
                });
                bulkProgress(ids.length, ids.length, 'Sent');
                bulkProgressDone();
                buildList(); renderInsights();
                var cur = empById(S.sel);
                if (cur) renderSum(cur);
                showToast(res.message || 'Employees notified.', 'success');
            }).fail(function () {
                bulkProgressDone();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server.' });
            });
        });
    };

    byId('pcw-list').addEventListener('click', function (ev) {
        var chk = ev.target.closest('input[data-ps]');
        if (chk) {
            S.ps[chk.getAttribute('data-ps')] = chk.checked;
            updPsPill();
            ev.stopPropagation();
            return;
        }
        // Chat button opens the sign-off message without selecting the card.
        var cv = ev.target.closest('button[data-convo]');
        if (cv) {
            ev.stopPropagation();
            window.pcwOpenReviewConvo(parseInt(cv.getAttribute('data-convo'), 10));
            return;
        }
        var it = ev.target.closest('.pcw-item');
        if (it) select(parseInt(it.getAttribute('data-eid'), 10));
    });

    // "All" ticks every employee currently listed (respects the active filters)
    byId('pcw-sel-all').addEventListener('change', function () {
        var on = this.checked;
        S.list.forEach(function (e) { S.ps[e.id] = on; });
        document.querySelectorAll('#pcw-list input[data-ps]').forEach(function (c) { c.checked = on; });
        updPsPill();
    });

    // ── Filters ──
    /* Area follows Department: repopulate the ward list from the picked
       department (all wards when none is picked) and drop a selection that the
       new department doesn't contain, so the two filters can never combine into
       an empty list by accident. Rewriting the <option>s is enough for the
       custom-select skin — it rebuilds on child changes. Returns true when the
       area selection was cleared, i.e. the caller's list is now stale. */
    /* The custom-select skin mirrors the native <select> but only re-reads it on
       a real 'change' event. Setting .value from script, or rewriting the
       <option>s, therefore leaves the visible control showing the OLD label /
       list — the MutationObserver in custom-select.js only looks for *new*
       selects to enhance and skips anything already inside a .cs-select. Every
       scripted write below has to hand it back. */
    function csRefresh(el) {
        if (el && window.CustomSelect && CustomSelect.refresh) CustomSelect.refresh(el);
    }

    var areaSel = byId('pcw-area-filter');   // absent when no one in the payroll has an area
    function pcwSyncAreas() {
        if (!areaSel) return false;
        var src  = window.PCW_AREAS || { all: [], by_dept: {} };
        var list = S.dept ? (src.by_dept[S.dept] || []) : (src.all || []);
        var keep = S.area && list.indexOf(S.area) !== -1;

        var html = '<option value="">All Areas</option>';
        list.forEach(function (a) {
            html += '<option value="' + esc(a) + '">' + esc(a) + '</option>';
        });
        areaSel.innerHTML = html;
        // A department with no wards of its own: nothing to choose, so say so
        // rather than showing a lone "All Areas" that does nothing.
        areaSel.disabled = list.length === 0;
        if (!keep) S.area = '';
        areaSel.value = S.area;
        csRefresh(areaSel);
        return !keep;
    }

    byId('pcw-q').addEventListener('input', function () { S.q = this.value.trim().toLowerCase(); buildList(); });
    byId('pcw-dept').addEventListener('change', function () {
        S.dept = this.value;
        pcwSyncAreas();
        buildList();
        pcwUpdFilterCount();
    });
    if (areaSel) areaSel.addEventListener('change', function () { S.area = this.value; buildList(); pcwUpdFilterCount(); });
    byId('pcw-pos-filter').addEventListener('change', function () { S.pos = this.value; buildList(); pcwUpdFilterCount(); });
    byId('pcw-rate-filter').addEventListener('change', function () { S.rate = this.value; buildList(); pcwUpdFilterCount(); });
    var schSel = byId('pcw-sch-filter');   // only rendered when the payroll has schedule data
    if (schSel) schSel.addEventListener('change', function () { S.sch = this.value; buildList(); pcwUpdFilterCount(); });

    // Pay-component chips (paid leave / OT / night diff / holiday / rest day)
    byId('pcw-has-chips').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.has = b.getAttribute('data-has');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    // Review-mark chips
    byId('pcw-rv-chips').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.rv = b.getAttribute('data-rv');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    // Employee sign-off chips (only rendered once the batch is out for review)
    // Edit-lock chips (only rendered while the batch is out for review)
    var lockChips = byId('pcw-lock-chips');
    if (lockChips) lockChips.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.lock = b.getAttribute('data-lock');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    var ervChips = byId('pcw-erv-chips');
    if (ervChips) ervChips.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        S.erv = b.getAttribute('data-erv');
        this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        buildList();
        pcwUpdFilterCount();
    });

    // Filter popover open/close + active-count badge (global — called from onclick)
    window.pcwToggleFilter = function () {
        var pop = byId('pcw-filter-pop'), btn = byId('pcw-filter-btn');
        var open = pop.classList.toggle('open');
        btn.classList.toggle('on', open);
    };
    window.pcwCloseFilter = function () {
        byId('pcw-filter-pop').classList.remove('open');
        byId('pcw-filter-btn').classList.toggle('on', pcwActiveFilters() > 0);
    };
    function pcwActiveFilters() { return (S.dept ? 1 : 0) + (S.area ? 1 : 0) + (S.pos ? 1 : 0) + (S.rate ? 1 : 0) + (S.sch ? 1 : 0) + (S.has !== '' ? 1 : 0) + (S.rv !== '' ? 1 : 0) + (S.erv !== '' ? 1 : 0) + (S.lock !== '' ? 1 : 0); }
    window.pcwUpdFilterCount = function () {
        var n = pcwActiveFilters();
        var badge = byId('pcw-filter-count'), btn = byId('pcw-filter-btn');
        badge.textContent = n;
        badge.style.display = n ? 'flex' : 'none';
        btn.classList.toggle('on', n > 0 || byId('pcw-filter-pop').classList.contains('open'));
    };
    window.pcwResetFilters = function () {
        S.dept = ''; S.area = ''; S.pos = ''; S.rate = ''; S.sch = ''; S.has = ''; S.rv = ''; S.erv = ''; S.lock = '';
        byId('pcw-dept').value = ''; csRefresh(byId('pcw-dept'));
        pcwSyncAreas();                     // back to the full ward list (refreshes itself)
        byId('pcw-pos-filter').value = ''; csRefresh(byId('pcw-pos-filter'));
        byId('pcw-rate-filter').value = ''; csRefresh(byId('pcw-rate-filter'));
        if (schSel) { schSel.value = ''; csRefresh(schSel); }
        byId('pcw-has-chips').querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-has') === ''); });
        byId('pcw-rv-chips').querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-rv') === ''); });
        if (ervChips) ervChips.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-erv') === ''); });
        if (lockChips) lockChips.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-lock') === ''); });
        buildList();
        pcwUpdFilterCount();
    };
    /* Close on an outside click — but working the filters is never "outside".
       The custom-select menus (Department / Area / Position / Rate / Shift) are
       appended to <body> and positioned fixed, so a click on one of their
       options lands OUTSIDE .pcw-filter-pop in the DOM even though the user is
       plainly still filtering; without the .cs-menu check, picking a department
       slammed the panel shut. Same for SweetAlert, which also mounts at body
       level. The X button and a genuine click elsewhere on the page are the
       only ways out. */
    document.addEventListener('click', function (ev) {
        var pop = byId('pcw-filter-pop');
        if (!pop.classList.contains('open')) return;
        var t = ev.target;
        if (pop.contains(t) || byId('pcw-filter-btn').contains(t)) return;
        if (t.closest && t.closest('.cs-menu, .swal2-container, .select2-container')) return;
        pcwCloseFilter();
    });
    // Esc closes it too — the keyboard equivalent of the X.
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        var pop = byId('pcw-filter-pop');
        if (!pop.classList.contains('open')) return;
        // A select menu open on top owns the Esc first; it closes itself.
        if (document.querySelector('.cs-menu.open')) return;
        pcwCloseFilter();
    });

    // ── Prev / next ──
    byId('pcw-prev').addEventListener('click', function () {
        var idx = S.list.findIndex(function (e) { return e.id == S.sel; });
        if (idx > 0) select(S.list[idx - 1].id);
    });
    byId('pcw-next').addEventListener('click', function () {
        var idx = S.list.findIndex(function (e) { return e.id == S.sel; });
        if (idx >= 0 && idx < S.list.length - 1) select(S.list[idx + 1].id);
    });

    // ── Zoom ──
    function setZoom(z) {
        S.zoom = Math.min(1.6, Math.max(0.5, z));
        byId('pcw-paper').style.setProperty('--pcw-zoom', S.zoom);
        byId('pcw-zoom-val').textContent = Math.round(S.zoom * 100) + '%';
    }
    byId('pcw-zoom-in').addEventListener('click', function () { setZoom(S.zoom + 0.1); });
    byId('pcw-zoom-out').addEventListener('click', function () { setZoom(S.zoom - 0.1); });
    byId('pcw-zoom-val').addEventListener('click', function () { setZoom(1); });

    // ── Sync workbench data from the sheet editor's live table ──
    function syncRow(e) {
            var tr = document.querySelector('tr[data-row-id="' + e.id + '"]');
            if (!tr) return;
            var num = function (el) {
                if (!el) return null;
                var v = parseFloat(String(el.value !== undefined && el.tagName === 'INPUT' ? el.value : el.textContent).replace(/,/g, ''));
                return isNaN(v) ? null : v;
            };
            var inp = function (t) { return num(tr.querySelector('input[data-type="' + t + '"]')); };
            var cell = function (k) { return num(tr.querySelector('[data-computed="' + k + '"]')); };
            var pick = function (v, old) { return v === null ? old : v; };

            e.present = pick(inp('present'), e.present);
            e.absent = pick(inp('absent'), e.absent);
            e.late_min = pick(inp('late'), e.late_min);
            e.ot_hrs = pick(inp('ot'), e.ot_hrs);
            e.nsd_amt = pick(inp('nsd_amount'), e.nsd_amt);
            e.per_day = pick(inp('per_day'), e.per_day);
            e.allow_days = pick(inp('allowance_days'), e.allow_days);
            e.legal = pick(inp('legal_holiday'), e.legal);
            e.rest = pick(inp('sunday_duty'), e.rest);
            e.spc = pick(inp('special_holiday'), e.spc);
            e.sss_fund = pick(inp('sss_fund'), e.sss_fund);
            e.jei = pick(inp('jei_advances'), e.jei);
            e.jcc = pick(inp('jcc_advances'), e.jcc);
            e.tax = pick(inp('tax'), e.tax);
            e.other_ded = pick(inp('other_deduction'), e.other_ded);
            e.adj = pick(inp('adjustment'), e.adj);
            var rem = tr.querySelector('input[data-type="adjustment_remarks"]');
            if (rem) e.adj_rem = rem.value;

            tr.querySelectorAll('input[data-dd_id]').forEach(function (f) {
                var t = f.getAttribute('data-type'), dd = f.getAttribute('data-dd_id'), v = parseFloat(f.value) || 0;
                if (t === 'refund') {
                    (e.refunds || []).forEach(function (r) { if (String(r.id) === String(dd)) r.amt = v; });
                } else {
                    var g = t === 'contribution' ? 1 : (t === 'loan' ? 3 : 2);
                    (e.deds || []).forEach(function (d) { if (d.g === g && String(d.id) === String(dd)) d.amt = v; });
                }
            });

            e.absent_amt = pick(cell('absent_amount'), e.absent_amt);
            e.allow_amt = pick(cell('allowance_total'), e.allow_amt);
            e.legal_amt = pick(cell('legal_amount'), e.legal_amt);
            e.rest_amt = pick(cell('sunday_amount'), e.rest_amt);
            e.spc_amt = pick(cell('special_amount'), e.spc_amt);
            e.ot_amt = pick(cell('overtime_amount'), e.ot_amt);
            e.late_amt = pick(cell('late_amount'), e.late_amt);
            var be = cell('total_basic_rate');
            if (be === null) be = cell('total_amount');
            if (be !== null) e.basic_earned = be;
            e.gross = pick(cell('gross'), e.gross);
            e.net = pick(cell('net'), e.net);
            var td = cell('total_deductions');
            e.total_ded = td !== null ? td
                : (e.deds || []).reduce(function (s, d) { return s + d.amt; }, 0) + e.sss_fund + e.jei + e.jcc + e.tax + e.other_ded;
            e.total_ref = (e.refunds || []).reduce(function (s, r) { return s + r.amt; }, 0);
            e.rv = parseInt(tr.getAttribute('data-review') || '0', 10);
            e.rv_c = tr.getAttribute('data-review-comment') || '';
    }

    function pcwSync() {
        D.forEach(syncRow);
        buildList();
        renderInsights();
        var cur = empById(S.sel);
        if (cur) { renderPaper(cur); renderSum(cur); }
        updPsPill();
    }
    window.pcwSync = pcwSync;

    // Keep the workbench current: after saves refresh the table, then re-sync;
    // also re-sync when the sheet editor closes (live, unsaved edits included).
    window.addEventListener('load', function () {
        if (typeof window.refreshPayrollRows === 'function') {
            var orig = window.refreshPayrollRows;
            window.refreshPayrollRows = function (pid) {
                return Promise.resolve(orig(pid)).then(function (r) { pcwSync(); return r; });
            };
        }
    });
    var editorModal = document.getElementById('modal-table-editor');
    if (editorModal) {
        editorModal.addEventListener('shown.bs.modal', function () {
            if (typeof fitTableToViewport === 'function') fitTableToViewport();
            if (typeof fixStickyHeaderGap === 'function') fixStickyHeaderGap();
            if (typeof fixFrozenColumns === 'function') fixFrozenColumns();
        });
        editorModal.addEventListener('hidden.bs.modal', pcwSync);
    }

    // ── Inline editing on the computation sheet ──
    // Every keystroke writes through to the hidden table's matching input and
    // fires its 'input' event, so changedInputs, the live table recalc, and
    // Save behave exactly as they did when the table was edited directly.
    function updPaperTotals(e) {
        var set = function (id, v, neg) {
            var el = byId(id);
            if (el) el.textContent = neg ? '(' + fmt(v) + ')' : fmt(v);
        };
        set('pp-gross-amt', e.gross);
        [1, 3, 2].forEach(function (g) {
            var sub = (e.deds || []).filter(function (d) { return d.g === g; })
                .reduce(function (s, d) { return s + d.amt; }, 0);
            set('pp-sub-g' + g, sub, true);
        });
        set('pp-sub-fx', e.sss_fund + e.jei + e.jcc + e.tax + e.other_ded, true);
        set('pp-totded-amt', e.total_ded, true);
        set('pp-totref-amt', e.total_ref);
        var net = byId('pp-net-amt');
        if (net) { net.textContent = peso(e.net); net.classList.toggle('neg', e.net <= 0); }
        // Glance strip, section totals, equation and amount-in-words stay live too
        set('pp-glance-gross', e.gross);
        set('pp-glance-ded', e.total_ded, true);
        set('pp-glance-net', e.net);
        set('pp-sect-earn', e.gross);
        set('pp-sect-ded', e.total_ded, true);
        set('pp-sect-ref', e.total_ref);
        set('pp-eq-g', e.gross);
        set('pp-eq-d', e.total_ded);
        set('pp-eq-r', e.total_ref);
        set('pp-eq-a', Math.abs(e.adj));
        set('pp-eq-n', e.net);
        var att = byId('pp-sect-att');
        if (att) att.textContent = days2(e.present) + ' day(s) on duty';
        var w = byId('pp-words-txt');
        if (w) w.textContent = pesoWords(e.net);
    }

    byId('pcw-paper').addEventListener('input', function (ev) {
        var f = ev.target.closest('.pp-in');
        if (!f) return;
        var e = empById(S.sel);
        if (!e) return;
        var inp = tIn(e, f.getAttribute('data-t'), f.hasAttribute('data-dd') ? f.getAttribute('data-dd') : null);
        if (!inp) return;
        inp.value = f.value;
        inp.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // On blur: pull the row's (possibly cleaned/recomputed) values back and
    // refresh totals without rebuilding the sheet, so typing focus is kept.
    byId('pcw-paper').addEventListener('change', function (ev) {
        var f = ev.target.closest('.pp-in');
        if (!f) return;
        var e = empById(S.sel);
        if (!e) return;
        var inp = tIn(e, f.getAttribute('data-t'), f.hasAttribute('data-dd') ? f.getAttribute('data-dd') : null);
        if (inp && inp.value !== f.value) f.value = inp.value;
        syncRow(e);
        updPaperTotals(e);
        renderSum(e);
        buildList();
        renderInsights();
    });

    // Mirror the table ribbon's unsaved counter onto the workbench Save button
    window.addEventListener('load', function () {
        if (typeof window.countUnsaved === 'function') {
            var origCount = window.countUnsaved;
            window.countUnsaved = function () {
                origCount();
                var b = byId('pcw-save');
                if (!b) return;
                var n = 0;
                try { n = changedInputs.length; } catch (err) { n = 0; }
                b.style.display = n ? 'inline-flex' : 'none';
                var p = byId('pcw-unsaved-n');
                if (p) p.textContent = n;
            };
        }
    });

    // Reviewer marks saved from the modal reflect immediately in the workbench
    if (typeof window.applyReviewToRow === 'function') {
        var origApply = window.applyReviewToRow;
        window.applyReviewToRow = function (itemId, st, c) {
            origApply(itemId, st, c);
            var e = empById(itemId);
            if (e) { e.rv = st; e.rv_c = c || ''; }
            buildList();
            renderInsights();
            if (S.sel == itemId && e) { renderPaper(e); renderSum(e); }
        };
    }

    // ── Init ──
    buildList();
    renderInsights();
    if (S.list.length) select(S.list[0].id);
    updPsPill();

    // ── Hide the loading overlay once the workbench is up ──
    // The panels are already rendered here; wait for full load (fonts/icons/
    // deferred scripts) before fading out, with an 8s safety cap.
    function pcwHideBoot() {
        var b = byId('pcw-boot');
        if (!b || b.classList.contains('hide')) return;
        document.body.classList.remove('pcw-booting');   // reveal the workbench
        b.classList.add('hide');                         // fade the overlay out
        setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 450);
    }
    if (document.readyState === 'complete') pcwHideBoot();
    else window.addEventListener('load', pcwHideBoot);
    setTimeout(pcwHideBoot, 8000);
})();
