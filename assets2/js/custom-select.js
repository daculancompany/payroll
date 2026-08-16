/* ============================================================================
   Custom Select — app-wide <select> enhancement.
   Pairs with assets2/css/custom-select.css. Loaded globally from index.php.

   Progressive enhancement, not a replacement: the native <select> stays in the
   DOM and remains the single source of truth. Everything already written
   against it keeps working unchanged —
       $('#x').val('3').trigger('change')     → label updates (change listener)
       $('#x').html('<option>…')              → menu rebuilds (MutationObserver)
       form.submit() / Parsley / .val() reads → untouched, it's the same node.

   Two structural decisions worth knowing:

   1. The menu is appended to <body>, not to the wrapper. position:fixed is
      normally immune to an ancestor's overflow, but ANY ancestor with a
      transform becomes the containing block for fixed descendants — and this
      app has several (.roster-actionbar uses translate(-50%,0)). Parenting to
      <body> is the only placement that can't be clipped or mis-offset.

   2. Selects owned by bootstrap-select (anything that went through the
      .select2() shim in index.php) are skipped. The shim also calls
      CustomSelect.destroy() first, so a lazy .select2() call on an
      already-enhanced select hands over cleanly instead of stacking two UIs.

   Per-<select> options (attributes):
     data-no-cs               skip this select entirely
     data-cs-title="Shift"    menu header title
     data-cs-icon="ri-x-line" leading icon on the trigger
     data-cs-search="true"    force the filter box on/off (auto: >8 options)
     data-cs-multi="true"     opt a <select multiple> into this UI (default:
                               multi-selects are left native — see isEligible)

   Per-<option> options (all optional — plain selects need none of this):
     data-cs-name="8-5"                  row title (defaults to option text)
     data-cs-sub="8:00 AM – 5:00 PM"     second line
     data-cs-display="8-5 · 8:00 AM…"    trigger label when selected
     data-cs-icon="ri-sun-fill"          row icon
     data-cs-icon-class="cs-tod-morning" row icon chip palette
     data-cs-badge="Night"               trailing badge
   ========================================================================= */
(function (window, document) {
    'use strict';

    var SEARCH_THRESHOLD = 8;    // show the filter box past this many options
    var MENU_MAX_W = 420;
    var OPTS_MAX_H = 280;
    var GAP = 6;                 // px between trigger and menu

    var openWrap = null;         // only one menu open at a time

    // ---- helpers ------------------------------------------------------------

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* Menu header title: explicit data-cs-title wins, else the field's own
       label (<label for>, an .form-label sibling, or aria-label) so most
       selects get a sensible header for free. */
    function deriveTitle(sel) {
        if (sel.dataset.csTitle != null) return sel.dataset.csTitle;
        var txt = '';
        if (sel.id) {
            var lab = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(sel.id) : sel.id) + '"]');
            if (lab) txt = lab.textContent;
        }
        if (!txt && sel.parentElement) {
            var sib = sel.parentElement.querySelector('.form-label, label');
            if (sib) txt = sib.textContent;
        }
        if (!txt) txt = sel.getAttribute('aria-label') || '';
        txt = txt.replace(/\s+/g, ' ').trim().replace(/[:*]\s*$/, '');
        return txt.length && txt.length <= 40 ? txt : '';
    }

    function isEligible(sel) {
        if (!sel || sel.tagName !== 'SELECT') return false;
        // Multi-selects are left native UNLESS the page explicitly opts in
        // (data-cs-multi) — most <select multiple> in this app (e.g. area.php's
        // size="5" listbox) want the browser's own UI, not this dropdown.
        if (sel.multiple && !sel.hasAttribute('data-cs-multi')) return false;
        if (sel.dataset.csDone) return false;                 // already ours
        if (sel.hasAttribute('data-no-cs')) return false;     // explicit opt-out
        if (sel.classList.contains('no-cs')) return false;
        if (sel.classList.contains('selectpicker')) return false;   // bootstrap-select
        if (sel.closest('.bootstrap-select')) return false;
        if (sel.closest('.cs-select')) return false;
        if (window.jQuery && window.jQuery(sel).data('bs-select')) return false;
        // SweetAlert2's popup template ships a hidden <select class="swal2-select">
        // in EVERY dialog; enhancing it drew an empty control in the middle of
        // every confirm box. More generally: if the page hides a select itself
        // (swal2, .d-none, style="display:none"), our wrapper carries no such
        // rule and would render in its place — so leave those alone.
        // NB: an ancestor being display:none does NOT make the child compute to
        // none, so selects inside closed modals / inactive tabs still enhance.
        if (sel.classList.contains('swal2-select')) return false;
        // DataTables' page-size select lives inside a "Show [ ] entries"
        // sentence — wrapping it in this control's block-level trigger breaks
        // that inline flow (the label wraps onto three lines). It's styled to
        // match natively instead — see the .dataTables_length rules in
        // custom-select.css.
        if (sel.closest('.dataTables_length')) return false;
        if (window.getComputedStyle(sel).display === 'none') return false;
        return true;
    }

    // ---- build --------------------------------------------------------------

    function buildOptionRow(opt) {
        var d = opt.dataset;
        var row = el('button', 'cs-opt');
        row.type = 'button';
        row.setAttribute('role', 'option');
        row.dataset.value = opt.value;
        if (opt.disabled) row.disabled = true;

        var name = d.csName || opt.textContent.trim();
        var sub = d.csSub || '';
        row.dataset.display = d.csDisplay || (sub ? name + ' · ' + sub : name);
        row.dataset.search = (name + ' ' + sub + ' ' + opt.textContent).toLowerCase();

        var html = '';
        if (d.csIcon) {
            html += '<span class="cs-tod ' + esc(d.csIconClass || '') + '"><i class="' + esc(d.csIcon) + '"></i></span>';
        }
        html += '<span class="cs-opt-main"><span class="cs-opt-name">' + esc(name) + '</span>';
        if (sub) html += '<span class="cs-opt-time">' + esc(sub) + '</span>';
        html += '</span>';
        if (d.csBadge) html += '<span class="cs-night">' + esc(d.csBadge) + '</span>';
        html += '<i class="ri-check-line cs-opt-check"></i>';
        row.innerHTML = html;
        return row;
    }

    /* (Re)build the option rows from the live <select>. Called on enhance and
       whenever the select's children change (ajax-populated dropdowns). */
    function rebuild(sel) {
        var st = sel._cs;
        if (!st) return;
        st.opts.innerHTML = '';
        st.rows = [];

        Array.prototype.forEach.call(sel.children, function (child) {
            if (child.tagName === 'OPTGROUP') {
                if (child.label) st.opts.appendChild(el('div', 'cs-group', esc(child.label)));
                Array.prototype.forEach.call(child.children, function (o) {
                    if (o.tagName === 'OPTION') addRow(st, o);
                });
            } else if (child.tagName === 'OPTION') {
                addRow(st, child);
            }
        });

        // The filter box only earns its space on long lists.
        var forced = sel.dataset.csSearch;
        var wantSearch = forced === 'true' ? true
            : forced === 'false' ? false
            : st.rows.length > SEARCH_THRESHOLD;
        st.searchWrap.style.display = wantSearch ? '' : 'none';
        if (!st.title && !wantSearch) st.top.style.display = 'none';
        else st.top.style.display = '';

        syncLabel(sel);
    }

    function addRow(st, opt) {
        var row = buildOptionRow(opt);
        row.addEventListener('click', function () {
            if (row.disabled) return;
            pick(st.sel, row.dataset.value);
        });
        st.opts.appendChild(row);
        st.rows.push(row);
    }

    /* Push the native select's current value into the visual control. */
    function syncLabel(sel) {
        var st = sel._cs;
        if (!st) return;
        if (sel.multiple) { syncLabelMulti(sel); return; }
        var opt = sel.options[sel.selectedIndex];
        var val = opt ? opt.value : '';
        var row = null;
        st.rows.forEach(function (r) {
            var on = r.dataset.value === val;
            r.classList.toggle('sel', on);
            r.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) row = r;
        });

        var display = row ? row.dataset.display : (opt ? opt.textContent.trim() : '');
        st.label.textContent = display || '';
        // A blank value is the placeholder state (e.g. "— Select shift —").
        st.label.classList.toggle('cs-empty', !val);
        st.wrap.classList.toggle('cs-disabled', !!sel.disabled);
        st.trigger.disabled = !!sel.disabled;
    }

    /* Multi-select variant: every matching row gets checked (not just one),
       and the trigger shows a name list or a "N selected" count instead of a
       single value. */
    function syncLabelMulti(sel) {
        var st = sel._cs;
        var names = [];
        st.rows.forEach(function (r) {
            var on = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === r.dataset.value) { on = sel.options[i].selected; break; }
            }
            r.classList.toggle('sel', on);
            r.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) names.push(r.dataset.display);
        });

        var display = !names.length ? (sel.dataset.placeholder || sel.getAttribute('placeholder') || '')
            : names.length <= 2 ? names.join(', ')
            : names.length + ' selected';
        st.label.textContent = display || '';
        st.label.classList.toggle('cs-empty', !names.length);
        st.wrap.classList.toggle('cs-disabled', !!sel.disabled);
        st.trigger.disabled = !!sel.disabled;
        if (st.clearBtn) st.clearBtn.hidden = !names.length;
    }

    function pick(sel, value) {
        if (sel.multiple) { pickMulti(sel, value); return; }
        if (sel.value === value) { close(); return; }
        sel.value = value;
        close();
        syncLabel(sel);
        // Native + jQuery listeners both — this app mixes the two freely.
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery) window.jQuery(sel).trigger('change');
    }

    /* Toggle one option and keep the menu open — picking is cumulative here,
       unlike the single-select case which closes on the first choice. */
    function pickMulti(sel, value) {
        var opt = null;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === value) { opt = sel.options[i]; break; }
        }
        if (!opt) return;
        opt.selected = !opt.selected;
        syncLabel(sel);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery) window.jQuery(sel).trigger('change');
    }

    /* "Clear all" button — multi-select only. Deselects everything without
       closing the menu, so the user can immediately pick something else. */
    function clearAll(sel) {
        var changed = false;
        Array.prototype.forEach.call(sel.options, function (o) {
            if (o.selected) { o.selected = false; changed = true; }
        });
        if (!changed) return;
        syncLabel(sel);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        if (window.jQuery) window.jQuery(sel).trigger('change');
    }

    // ---- open / close / position -------------------------------------------

    function position(sel) {
        var st = sel._cs;
        var r = st.trigger.getBoundingClientRect();
        var vw = window.innerWidth, vh = window.innerHeight;

        st.menu.style.maxWidth = Math.min(MENU_MAX_W, vw - 24) + 'px';
        st.menu.style.minWidth = r.width + 'px';

        // Measure with the menu laid out but invisible.
        var below = vh - r.bottom - GAP - 8;
        var above = r.top - GAP - 8;
        st.opts.style.maxHeight = Math.min(OPTS_MAX_H, Math.max(below, above) - 52) + 'px';

        var h = st.menu.offsetHeight, w = st.menu.offsetWidth;
        var up = below < h && above > below;   // flip up only when it genuinely helps

        st.menu.classList.toggle('cs-menu-up', up);
        st.wrap.classList.toggle('cs-up', up);
        st.menu.style.top = (up ? Math.max(8, r.top - GAP - h) : r.bottom + GAP) + 'px';
        st.menu.style.left = Math.round(Math.min(Math.max(8, r.left), Math.max(8, vw - w - 8))) + 'px';
    }

    function open(sel) {
        var st = sel._cs;
        if (sel.disabled) return;
        if (openWrap && openWrap !== st.wrap) close();

        st.wrap.classList.add('open');
        st.menu.classList.add('open');
        st.trigger.setAttribute('aria-expanded', 'true');
        openWrap = st.wrap;

        if (st.search) { st.search.value = ''; filter(sel, ''); }
        position(sel);

        var cur = st.opts.querySelector('.cs-opt.sel');
        if (cur) cur.scrollIntoView({ block: 'nearest' });
        setActive(sel, cur ? st.rows.indexOf(cur) : -1);
        if (st.searchWrap.style.display !== 'none' && st.search) st.search.focus({ preventScroll: true });
    }

    function close() {
        if (!openWrap) return;
        var sel = openWrap._csSel;
        openWrap.classList.remove('open');
        if (sel && sel._cs) {
            sel._cs.menu.classList.remove('open');
            sel._cs.trigger.setAttribute('aria-expanded', 'false');
        }
        openWrap = null;
    }

    function filter(sel, q) {
        var st = sel._cs;
        q = (q || '').trim().toLowerCase();
        var shown = 0;
        st.rows.forEach(function (r) {
            var hit = !q || r.dataset.search.indexOf(q) !== -1;
            r.hidden = !hit;
            if (hit) shown++;
        });
        // Group headers are meaningless while filtering.
        Array.prototype.forEach.call(st.opts.querySelectorAll('.cs-group'), function (g) { g.hidden = !!q; });
        st.none.hidden = shown > 0;
        setActive(sel, -1);
    }

    function visibleRows(st) {
        return st.rows.filter(function (r) { return !r.hidden && !r.disabled; });
    }

    function setActive(sel, idx) {
        var st = sel._cs;
        st.rows.forEach(function (r) { r.classList.remove('cs-active'); });
        st.activeIdx = idx;
        if (idx >= 0 && st.rows[idx]) {
            st.rows[idx].classList.add('cs-active');
            st.rows[idx].scrollIntoView({ block: 'nearest' });
        }
    }

    function move(sel, dir) {
        var st = sel._cs;
        var vis = visibleRows(st);
        if (!vis.length) return;
        var cur = st.rows[st.activeIdx];
        var i = vis.indexOf(cur);
        i = i === -1 ? (dir > 0 ? 0 : vis.length - 1) : Math.min(vis.length - 1, Math.max(0, i + dir));
        setActive(sel, st.rows.indexOf(vis[i]));
    }

    // ---- enhance / destroy --------------------------------------------------

    function enhance(sel) {
        if (!isEligible(sel)) return;

        var wrap = el('div', 'cs-select');
        if (sel.classList.contains('form-select-sm') || sel.classList.contains('form-control-sm')) {
            wrap.classList.add('cs-sm');
        }

        var icon = sel.dataset.csIcon || '';
        var title = deriveTitle(sel);

        var trigger = el('button', 'cs-trigger',
            (icon ? '<i class="' + esc(icon) + ' cs-ic"></i>' : '') +
            '<span class="cs-value cs-empty"></span>' +
            '<i class="ri-arrow-down-s-line cs-caret"></i>');
        trigger.type = 'button';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (sel.id) trigger.id = 'cs-trigger-' + sel.id;

        var menu = el('div', 'cs-menu');
        menu.setAttribute('role', 'listbox');
        if (title) menu.setAttribute('aria-label', title);

        var top = el('div', 'cs-top');
        if (title) top.appendChild(el('span', 'cs-title', (icon ? '<i class="' + esc(icon) + '"></i>' : '') + esc(title)));
        var searchWrap = el('div', 'cs-search', '<i class="ri-search-line"></i><input type="text" placeholder="Filter…" autocomplete="off">');
        top.appendChild(searchWrap);
        var clearBtn = null;
        if (sel.multiple) {
            clearBtn = el('button', 'cs-clear-all', 'Clear all');
            clearBtn.type = 'button';
            clearBtn.hidden = true;   // shown once something is selected
            top.appendChild(clearBtn);
        }
        var closeBtn = el('button', 'cs-close', '<i class="ri-close-line"></i>');
        closeBtn.type = 'button';
        closeBtn.title = 'Close';
        top.appendChild(closeBtn);
        menu.appendChild(top);

        var opts = el('div', 'cs-opts');
        var none = el('div', 'cs-none', 'No match.');
        none.hidden = true;
        menu.appendChild(opts);
        menu.appendChild(none);

        // Put the wrapper where the select was, then move the select inside it.
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        wrap.appendChild(trigger);
        /* See header note #1 for why the menu leaves the wrapper. Inside a
           Bootstrap modal the menu must stay INSIDE the modal element: the
           modal's focus trap yanks focus off anything outside it, which made
           the menu's filter box untypable. The .modal element itself is
           position:fixed with no transform, so fixed-position math still
           resolves against the viewport there. */
        (sel.closest('.modal') || document.body).appendChild(menu);
        sel.classList.add('cs-native');
        sel.dataset.csDone = '1';

        sel._cs = {
            sel: sel, wrap: wrap, trigger: trigger, menu: menu, top: top, opts: opts,
            none: none, searchWrap: searchWrap, search: searchWrap.querySelector('input'),
            label: trigger.querySelector('.cs-value'), title: title, rows: [], activeIdx: -1,
            clearBtn: clearBtn
        };
        wrap._csSel = sel;

        trigger.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (openWrap === wrap) close(); else open(sel);
        });
        closeBtn.addEventListener('click', function (e) { e.stopPropagation(); close(); trigger.focus(); });
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) { e.stopPropagation(); clearAll(sel); });
        }

        sel._cs.search.addEventListener('input', function () { filter(sel, this.value); });
        sel._cs.search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var vis = visibleRows(sel._cs);
                var act = sel._cs.rows[sel._cs.activeIdx];
                // Enter takes the highlighted row, or the only remaining match.
                if (act && !act.hidden) pick(sel, act.dataset.value);
                else if (vis.length === 1) pick(sel, vis[0].dataset.value);
            } else if (e.key === 'ArrowDown') { e.preventDefault(); move(sel, 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(sel, -1); }
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                if (openWrap !== wrap) { e.preventDefault(); open(sel); }
            }
        });

        // Programmatic updates: .val().trigger('change') and ajax-swapped options.
        sel.addEventListener('change', function () { syncLabel(sel); });
        var mo = new MutationObserver(function () { rebuild(sel); });
        mo.observe(sel, { childList: true, subtree: true });
        sel._cs.mo = mo;

        rebuild(sel);
    }

    function destroy(sel) {
        var st = sel && sel._cs;
        if (!st) return;
        if (openWrap === st.wrap) close();
        if (st.mo) st.mo.disconnect();
        if (st.menu.parentNode) st.menu.parentNode.removeChild(st.menu);
        sel.classList.remove('cs-native');
        delete sel.dataset.csDone;
        if (st.wrap.parentNode) {
            st.wrap.parentNode.insertBefore(sel, st.wrap);
            st.wrap.parentNode.removeChild(st.wrap);
        }
        delete sel._cs;
    }

    function scan(root) {
        var scope = root || document;
        if (scope.tagName === 'SELECT') { enhance(scope); return; }
        if (!scope.querySelectorAll) return;
        Array.prototype.forEach.call(scope.querySelectorAll('select'), enhance);
    }

    // ---- global listeners ---------------------------------------------------

    document.addEventListener('click', function (e) {
        if (!openWrap) return;
        var sel = openWrap._csSel;
        if (openWrap.contains(e.target) || (sel && sel._cs && sel._cs.menu.contains(e.target))) return;
        close();
    });

    document.addEventListener('keydown', function (e) {
        if (!openWrap) return;
        var sel = openWrap._csSel;
        if (e.key === 'Escape') { close(); if (sel && sel._cs) sel._cs.trigger.focus(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); move(sel, 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(sel, -1); }
        else if (e.key === 'Enter') {
            var act = sel._cs.rows[sel._cs.activeIdx];
            if (act && !act.hidden) { e.preventDefault(); pick(sel, act.dataset.value); }
        }
    });

    // The menu is body-parented, so it has to follow its trigger on scroll.
    window.addEventListener('scroll', function () {
        if (openWrap && openWrap._csSel) position(openWrap._csSel);
    }, true);
    window.addEventListener('resize', function () {
        if (openWrap && openWrap._csSel) position(openWrap._csSel);
    });

    // Modals: a menu left open while the dialog closes would float over the page.
    document.addEventListener('hide.bs.modal', close, true);

    /* Initial sweep runs on DOMContentLoaded. jQuery registered its own
       DOMContentLoaded handler when it loaded (earlier than this file), so
       every $(document).ready() — including the .select2() calls that hand
       selects to bootstrap-select — has already run by the time we look. */
    /* Date pills (daterangepicker triggers) are plain <div>s, so they have no
       focus state of their own. daterangepicker's show/hide events bubble as
       jQuery custom events, so one delegated pair here flags every pill on
       every page — see the .is-open rules in custom-select.css. */
    function bindDatePills() {
        if (!window.jQuery) return;
        window.jQuery(document)
            .on('show.daterangepicker', function (e) { window.jQuery(e.target).addClass('is-open'); })
            .on('hide.daterangepicker', function (e) { window.jQuery(e.target).removeClass('is-open'); });
    }

    function boot() {
        scan(document);
        bindDatePills();
        // Late arrivals: ajax-rendered modals, DataTables' length menu, etc.
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (n) {
                    if (n.nodeType !== 1) return;
                    if (n.classList && (n.classList.contains('cs-menu') || n.classList.contains('cs-select'))) return;
                    if (n.closest && n.closest('.cs-select, .cs-menu, .bootstrap-select')) return;
                    scan(n);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    window.CustomSelect = {
        scan: scan, enhance: enhance, destroy: destroy,
        refresh: function (sel) { if (sel && sel._cs) rebuild(sel); },
        close: close
    };
})(window, document);
