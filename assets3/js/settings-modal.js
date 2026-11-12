/* v2 settings modal: wires the controls in includes/v2/settings_modal.php
 * to the theme engine (assets3/js/theme-engine.js). Every control applies
 * instantly; "Save to account" persists to users.settings. */
(function () {
    'use strict';

    var el = document.getElementById('v2-settings');
    if (!el || !window.UITheme || !window.bootstrap) return;

    var T = window.UITheme;
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    var $ = function (sel, ctx) { return (ctx || el).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || el).querySelectorAll(sel)); };

    var ACCENTS = [
        ['#0f9d8a', 'Teal (default)'], ['#2563eb', 'Royal Blue'], ['#4f46e5', 'Indigo'],
        ['#7c3aed', 'Violet'], ['#0891b2', 'Cyan'], ['#16a34a', 'Green'],
        ['#d97706', 'Amber'], ['#e11d48', 'Rose'], ['#475569', 'Slate']
    ];

    /* ── build swatch grids ─────────────────────────────────────────── */
    function swatch(fill, label, cls, data) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ps-swatch ' + cls;
        b.title = label;
        b.setAttribute('aria-label', label);
        b.style.background = fill;
        for (var k in data) b.dataset[k] = data[k];
        return b;
    }
    var ctGrid = $('[data-ps-colorthemes]');
    var none = swatch('var(--v2-surface-2)', 'No colour theme', 'ps-swatch--tile ps-swatch--none', { ct: '' });
    none.innerHTML = '<i class="ri-paint-fill"></i>';
    ctGrid.appendChild(none);
    ColorThemes.list.forEach(function (t) {
        ctGrid.appendChild(swatch(t.css, t.label, 'ps-swatch--tile', { ct: t.key }));
    });
    var acGrid = $('[data-ps-accents]');
    ACCENTS.forEach(function (a) { acGrid.appendChild(swatch(a[0], a[1], 'ps-swatch--round', { accent: a[0] })); });

    /* ── reflect state into the controls ────────────────────────────── */
    function sync() {
        var s = T.get();
        var usingCT = !!s.colorTheme;
        $('[data-ps="system"]').checked = !usingCT && s.theme === 'system';
        $$('[data-ps-theme]').forEach(function (b) {
            var on = !usingCT && b.dataset.psTheme === s.theme;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on);
        });
        var ds = $('[data-ps="darkSidebar"]');
        ds.checked = !!s.darkSidebar;
        ds.disabled = usingCT || T.mode() !== 'light';
        $$('[data-ct]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.ct === (s.colorTheme || '')); });

        var accent = (s.accent || '').toLowerCase();
        $$('[data-accent]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.accent === accent); });
        var shown = s.accent || getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || T.DEFAULT_ACCENT;
        $('[data-ps="accentPicker"]').value = shown;
        $('[data-ps-accent-hex]').textContent = shown;
        $('[data-ps-accent-reset]').disabled = !s.accent;

        $('[data-ps="density"]').value = s.density;
        $('[data-ps-density-val]').textContent = s.density + '%';
        $('[data-ps="fontFamily"]').value = s.fontFamily;
        $$('[data-ps-seg]').forEach(function (seg) {
            var key = seg.dataset.psSeg;
            $$('button', seg).forEach(function (b) { b.classList.toggle('is-active', b.dataset.v === s[key]); });
        });
        ['highContrast', 'compact', 'reduceMotion', 'brandGradient', 'paperWhite'].forEach(function (k) {
            $('[data-ps="' + k + '"]').checked = !!s[k];
        });
        var shell = $('[data-ps-shell]');   // admin only (no classic shell in the portal)
        if (shell) shell.checked = (window.__UI_SERVER || {}).ui === 'v2';
    }
    T.onChange(function () { if (el.classList.contains('show')) sync(); });

    /* ── controls ───────────────────────────────────────────────────── */
    el.addEventListener('click', function (e) {
        var t = e.target.closest('button, [data-ct], [data-accent]');
        if (!t || !el.contains(t)) return;
        if (t.dataset.psTheme) T.set({ theme: t.dataset.psTheme, colorTheme: '' });
        else if (t.dataset.ct !== undefined) T.set({ colorTheme: t.dataset.ct });
        else if (t.dataset.accent) T.set({ accent: t.dataset.accent });
        else if (t.hasAttribute('data-ps-accent-reset')) T.set({ accent: '' });
        else if (t.closest('[data-ps-seg]') && t.dataset.v) {
            var p = {}; p[t.closest('[data-ps-seg]').dataset.psSeg] = t.dataset.v; T.set(p);
        }
        else if (t.dataset.psTab) showTab(t.dataset.psTab);
        else if (t.hasAttribute('data-ps-reset')) { T.reset(); toast('success', 'Appearance reset to defaults.'); }
        else if (t.hasAttribute('data-ps-save')) save(t);
        else if (t.hasAttribute('data-ps-preview')) startPreview();
        sync();
    });

    el.addEventListener('change', function (e) {
        var k = e.target.dataset.ps;
        if (k === 'system') T.set({ theme: e.target.checked ? 'system' : (T.mode() === 'dark' ? 'dark' : 'light'), colorTheme: '' });
        else if (k === 'darkSidebar' || k === 'highContrast' || k === 'compact' || k === 'reduceMotion' || k === 'brandGradient' || k === 'paperWhite') {
            var p = {}; p[k] = e.target.checked; T.set(p);
        }
        else if (k === 'fontFamily') T.set({ fontFamily: e.target.value });
        else if (e.target.hasAttribute('data-ps-shell')) {
            var ui = e.target.checked ? 'v2' : 'classic';
            T.save({ ui: ui }).then(function (r) {
                if (r && r.result) { window.__UI_SERVER = r.settings; toast('success', ui === 'v2' ? 'The new interface opens by default now.' : 'Classic interface will open by default.'); }
            });
        }
        sync();
    });

    el.addEventListener('input', function (e) {
        var k = e.target.dataset.ps;
        if (k === 'density') { T.set({ density: +e.target.value }); $('[data-ps-density-val]').textContent = e.target.value + '%'; }
        if (k === 'accentPicker') { T.set({ accent: e.target.value }); $('[data-ps-accent-hex]').textContent = e.target.value; }
    });

    /* ── tabs + search ──────────────────────────────────────────────── */
    function showTab(key) {
        $$('[data-ps-tab]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.psTab === key); });
        $$('[data-ps-pane]').forEach(function (p) { p.hidden = p.dataset.psPane !== key; });
        var btn = $('[data-ps-tab="' + key + '"]');
        $('[data-ps-title]').textContent = btn ? btn.textContent.trim() : '';
    }
    $('[data-ps-search]').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase(), any = false;
        $$('[data-ps-group]').forEach(function (g) {
            var vis = 0;
            $$('[data-ps-tab]', g).forEach(function (b) {
                var on = !q || b.dataset.psKeywords.indexOf(q) !== -1;
                b.hidden = !on; if (on) vis++;
            });
            g.hidden = !vis; if (vis) any = true;
        });
        $('[data-ps-empty]').hidden = any;
        var first = $('[data-ps-tab]:not([hidden])');
        if (q && first) showTab(first.dataset.psTab);
    });

    /* ── save ───────────────────────────────────────────────────────── */
    function save(btn) {
        btn.disabled = true;
        var label = btn.textContent;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
        T.save().then(function (r) {
            if (r && r.result) { window.__UI_SERVER = r.settings; toast('success', 'Saved to your account.'); }
            else toast('error', (r && r.message) || 'Could not save settings.');
        }).catch(function () { toast('error', 'Could not reach the server.'); })
          .then(function () { btn.disabled = false; btn.textContent = label; });
    }

    function toast(type, msg) {
        if (window.toastr) { toastr.options.timeOut = 2500; toastr[type](msg); }
    }

    /* ── colour theme preview ───────────────────────────────────────── */
    var bar = document.getElementById('ps-preview-bar');
    var idx = 0;
    function previewAt(i) {
        var list = ColorThemes.list;
        idx = (i + list.length) % list.length;
        T.preview(list[idx].key);
        bar.querySelector('[data-ps-preview-name]').textContent = list[idx].label;
    }
    function startPreview() {
        var cur = T.get().colorTheme;
        var i = ColorThemes.list.findIndex(function (t) { return t.key === cur; });
        modal.hide();
        bar.hidden = false;
        previewAt(i < 0 ? 0 : i);
    }
    function endPreview(apply) {
        var key = ColorThemes.list[idx].key;
        T.endPreview();
        bar.hidden = true;
        if (apply) T.set({ colorTheme: key });
        modal.show();
    }
    bar.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.hasAttribute('data-ps-prev')) previewAt(idx - 1);
        if (b.hasAttribute('data-ps-next')) previewAt(idx + 1);
        if (b.hasAttribute('data-ps-preview-cancel')) endPreview(false);
        if (b.hasAttribute('data-ps-preview-apply')) endPreview(true);
    });
    document.addEventListener('keydown', function (e) {
        if (bar.hidden) return;
        if (e.key === 'ArrowLeft') previewAt(idx - 1);
        if (e.key === 'ArrowRight') previewAt(idx + 1);
        if (e.key === 'Escape') endPreview(false);
    });

    /* ── open ───────────────────────────────────────────────────────── */
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-v2-settings]');
        if (!o) return;
        e.preventDefault();
        showTab('theme');
        sync();
        modal.show();
    });
    el.addEventListener('show.bs.modal', sync);
    showTab('theme');

    window.V2Settings = { open: function (tab) { showTab(tab || 'theme'); sync(); modal.show(); } };
})();
