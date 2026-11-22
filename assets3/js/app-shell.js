/* v2 shell chrome: sidebar (collapse on desktop, drawer on mobile),
 * light/dark quick toggle, ⌘K command palette, switch back to classic. */
(function () {
    'use strict';

    var T = window.UITheme;
    var root = document.documentElement;
    var body = document.body;

    /* ── sidebar ────────────────────────────────────────────────────── */
    function closeDrawer() { body.classList.remove('v2-drawer-open'); }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-v2-collapse]')) {
            T.set({ sidebarCollapsed: !T.get().sidebarCollapsed });
        } else if (e.target.closest('[data-v2-sidebar-open]')) {
            body.classList.add('v2-drawer-open');
        } else if (e.target.closest('[data-v2-sidebar-close]') || e.target.closest('.v2-sidebar a[href]')) {
            closeDrawer();
        }
    });
    window.addEventListener('v2:navigated', closeDrawer);

    /* ── tree groups ────────────────────────────────────────────────── */
    var NAV_KEY = 'pr_nav_open';
    function setOpen(g, on) {
        g.classList.toggle('is-open', on);
        var h = g.querySelector('[data-v2-tree]');
        if (h) h.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    function saveOpen() {
        try {
            var keys = Array.prototype.map.call(document.querySelectorAll('.v2-tree.is-open'), function (g) { return g.dataset.tree; });
            localStorage.setItem(NAV_KEY, JSON.stringify(keys));
        } catch (e) {}
    }
    function railMode() {
        return root.classList.contains('ui-sidebar-collapsed') && window.matchMedia('(min-width: 992px)').matches;
    }
    document.addEventListener('click', function (e) {
        var head = e.target.closest('[data-v2-tree]');
        if (!head) return;
        var g = head.closest('.v2-tree');
        if (railMode()) {
            // The icon rail has no room for leaves: widen the sidebar and open this group.
            T.set({ sidebarCollapsed: false });
            setOpen(g, true);
        } else {
            setOpen(g, !g.classList.contains('is-open'));
        }
        saveOpen();
    });
    // After an in-place navigation the router moves .is-active; make sure its group is open.
    window.addEventListener('v2:navigated', function () {
        var a = document.querySelector('.v2-tree .v2-nav__item.is-active');
        if (a) setOpen(a.closest('.v2-tree'), true);
    });

    /* ── light / dark quick toggle ──────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-v2-mode-toggle]')) return;
        var s = T.get();
        var dark = T.mode() === 'dark';
        // From a colour theme or "system", flip to the explicit opposite.
        T.set({ theme: dark ? 'light' : (s.theme === 'black' ? 'black' : 'dark'), colorTheme: '' });
    });

    /* ── back to classic ────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-v2-classic]')) return;
        T.save({ ui: 'classic' }).then(function () {
            var page = (window.V2 && window.V2.page) || 'home';
            location.href = 'index.php?page=' + encodeURIComponent(page) + location.search.replace(/^\?/, '&');
        });
    });

    /* ── command palette ────────────────────────────────────────────── */
    var pal = document.getElementById('v2-palette');
    var input = document.getElementById('v2-palette-input');
    var list = document.getElementById('v2-palette-list');
    var items = [], sel = 0;

    function collect() {
        items = Array.prototype.map.call(document.querySelectorAll('.v2-nav .v2-nav__item[href]'), function (a) {
            var group = a.closest('.v2-nav__group');
            return {
                href: a.getAttribute('href'),
                label: a.querySelector('span').textContent.trim(),
                icon: a.querySelector('i').className,
                group: group ? (group.dataset.title || '') : '',
                nav: a.hasAttribute('data-nav')
            };
        });
    }
    function render() {
        var q = input.value.trim().toLowerCase();
        var hits = items.filter(function (it) { return !q || (it.label + ' ' + it.group).toLowerCase().indexOf(q) !== -1; });
        sel = Math.min(sel, Math.max(0, hits.length - 1));
        list.innerHTML = hits.length ? '' : '<div class="v2-palette__empty">No pages match “' + q.replace(/[<>&"]/g, '') + '”</div>';
        hits.forEach(function (it, i) {
            var a = document.createElement('a');
            a.href = it.href;
            a.className = 'v2-palette__item' + (i === sel ? ' is-active' : '');
            a.setAttribute('role', 'option');
            if (it.nav) a.setAttribute('data-nav', '');
            a.innerHTML = '<i></i><span></span><small></small>';
            a.firstChild.className = it.icon;
            a.children[1].textContent = it.label;
            a.children[2].textContent = it.group;
            list.appendChild(a);
        });
    }
    function open() {
        collect(); input.value = ''; sel = 0; render();
        pal.hidden = false; body.classList.add('v2-palette-open');
        setTimeout(function () { input.focus(); }, 10);
    }
    function close() { pal.hidden = true; body.classList.remove('v2-palette-open'); }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-v2-palette]')) { e.preventDefault(); open(); }
        else if (e.target.closest('[data-v2-palette-close]') || e.target.closest('.v2-palette__item')) close();
    });
    input.addEventListener('input', function () { sel = 0; render(); });
    input.addEventListener('keydown', function (e) {
        var n = list.querySelectorAll('.v2-palette__item');
        if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, n.length - 1); render(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); render(); e.preventDefault(); }
        else if (e.key === 'Enter' && n[sel]) { e.preventDefault(); n[sel].click(); }
        else if (e.key === 'Escape') close();
    });

    document.addEventListener('keydown', function (e) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.hidden ? open() : close(); }
        else if (e.key === 'Escape' && !pal.hidden) close();
        else if (e.key === '[' && !typing && !document.querySelector('.modal.show')) T.set({ sidebarCollapsed: !T.get().sidebarCollapsed });
    });

    // Show Ctrl instead of ⌘ off Apple devices.
    if (!/Mac|iPhone|iPad/.test(navigator.platform || '')) {
        Array.prototype.forEach.call(document.querySelectorAll('.v2-search kbd'), function (k) { k.textContent = 'Ctrl K'; });
    }
})();
