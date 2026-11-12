/* v2 theme engine. Loads in <head>, before first paint.
 *
 * Everything is applied to <html>, so it is in place before <body> exists:
 *   data-bs-theme   light | dark          Bootstrap 5.3 native colour mode
 *   data-theme      light | dark | black  which default theme is chosen
 *   data-radius     sharp | default | rounded
 *   data-header     auto | solid | translucent
 *   classes         ui-ct (colour theme on), ui-dark-sidebar, ui-hc,
 *                   ui-compact, ui-brand-gradient, ui-reduce-motion,
 *                   ui-sidebar-collapsed
 *   CSS vars        --ct-gradient --ct-base --ct-raised, --brand-50..900,
 *                   --brand-rgb, --app-font, root font-size (density)
 *
 * Persistence:
 *   localStorage `pr_ui`  applies instantly on every change (this device)
 *   users.settings        "Save to account" (ajax save_ui_settings)
 * Both carry `savedAt`. On load the newer one wins, so saving on one device
 * shows up on the next device you open.
 */
(function (global) {
    'use strict';

    var KEY = 'pr_ui';
    var PREVIEW_KEY = 'pr_ui_preview';
    var root = document.documentElement;
    var mql = global.matchMedia ? global.matchMedia('(prefers-color-scheme: dark)') : null;

    var DEFAULTS = {
        theme: 'light',
        colorTheme: '',
        accent: '',
        darkSidebar: true,
        fontFamily: 'default',
        density: 100,
        radius: 'default',
        headerStyle: 'auto',
        highContrast: false,
        compact: false,
        brandGradient: false,
        reduceMotion: false,
        sidebarCollapsed: false,
        savedAt: 0
    };
    var DEFAULT_ACCENT = '#0f9d8a';   // v2 brand teal

    var FONTS = {
        'default': '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        system: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        rounded: '"Nunito", ui-rounded, "Segoe UI", sans-serif',
        serif: 'Georgia, "Times New Roman", serif',
        mono: '"JetBrains Mono", "SFMono-Regular", Consolas, monospace'
    };

    function readLocal() {
        try { return JSON.parse(global.localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
    }
    function writeLocal(s) {
        try { global.localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* private mode */ }
    }
    function readPreview() {
        try { return global.sessionStorage.getItem(PREVIEW_KEY) || ''; } catch (e) { return ''; }
    }

    function merge(base, patch) {
        var out = {}, k;
        for (k in DEFAULTS) out[k] = DEFAULTS[k];
        for (k in base || {}) if (k in DEFAULTS) out[k] = base[k];
        for (k in patch || {}) if (k in DEFAULTS) out[k] = patch[k];
        return out;
    }

    // Newer of this device's copy and the account copy.
    var local = readLocal();
    var server = global.__UI_SERVER || {};
    var state = merge((!local || (server.savedAt || 0) > (local.savedAt || 0)) ? server : local);

    function hexToRgb(hex) {
        var n = parseInt(String(hex).replace('#', ''), 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }
    function mixRgb(c, t, a) { return c.map(function (v) { return Math.round(v + (t - v) * a); }); }
    function rgbHex(c) { return '#' + c.map(function (v) { return ('0' + v.toString(16)).slice(-2); }).join(''); }

    /* A full 50..900 ramp from one accent colour, so buttons, soft badges,
       focus rings and links all come from the same hue. */
    function setBrand(hex) {
        var c = hexToRgb(hex);
        var ramp = {
            50: mixRgb(c, 255, 0.92), 100: mixRgb(c, 255, 0.82), 200: mixRgb(c, 255, 0.64),
            300: mixRgb(c, 255, 0.42), 400: mixRgb(c, 255, 0.2), 500: c,
            600: mixRgb(c, 0, 0.12), 700: mixRgb(c, 0, 0.26), 800: mixRgb(c, 0, 0.42), 900: mixRgb(c, 0, 0.58)
        };
        for (var k in ramp) root.style.setProperty('--brand-' + k, rgbHex(ramp[k]));
        root.style.setProperty('--brand-rgb', c.join(','));
        root.style.setProperty('--brand', hex);
    }

    function activeColorTheme() {
        var key = readPreview() || state.colorTheme;
        return (global.ColorThemes && global.ColorThemes.get(key)) || null;
    }

    function resolvedMode() {
        var ct = activeColorTheme();
        if (ct) return ct.mode;
        if (state.theme === 'system') return mql && mql.matches ? 'dark' : 'light';
        return state.theme === 'light' ? 'light' : 'dark';
    }

    function toggle(cls, on) { root.classList[on ? 'add' : 'remove'](cls); }

    function apply() {
        var ct = activeColorTheme();
        var mode = resolvedMode();

        root.setAttribute('data-bs-theme', mode);
        root.setAttribute('data-theme', ct ? 'color' : (state.theme === 'system' ? mode : state.theme));
        root.setAttribute('data-radius', state.radius);
        root.setAttribute('data-header', state.headerStyle);

        toggle('ui-ct', !!ct);
        toggle('ui-dark-sidebar', !ct && mode === 'light' && !!state.darkSidebar);
        toggle('ui-hc', !!state.highContrast);
        toggle('ui-compact', !!state.compact);
        toggle('ui-brand-gradient', !!state.brandGradient);
        toggle('ui-reduce-motion', !!state.reduceMotion);
        toggle('ui-sidebar-collapsed', !!state.sidebarCollapsed);

        if (ct) {
            root.style.setProperty('--ct-gradient', ct.css);
            root.style.setProperty('--ct-base', ct.base);
            root.style.setProperty('--ct-raised', ct.raised);
        } else {
            root.style.removeProperty('--ct-gradient');
            root.style.removeProperty('--ct-base');
            root.style.removeProperty('--ct-raised');
        }

        setBrand(state.accent || (ct ? ct.accent : DEFAULT_ACCENT));
        root.style.setProperty('--app-font', FONTS[state.fontFamily] || FONTS['default']);
        root.style.fontSize = (Math.max(90, Math.min(115, +state.density || 100)) / 100 * 16) + 'px';

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', ct ? ct.base : (mode === 'dark' ? '#0b0f17' : '#ffffff'));

        listeners.forEach(function (fn) { try { fn(state, mode, ct); } catch (e) { /* listener bug */ } });
        try { global.dispatchEvent(new CustomEvent('ui-theme-change', { detail: { state: state, mode: mode } })); } catch (e) { /* old browser */ }
    }

    var listeners = [];

    var api = {
        DEFAULTS: DEFAULTS,
        DEFAULT_ACCENT: DEFAULT_ACCENT,
        FONTS: FONTS,
        get: function () { return merge(state); },
        mode: resolvedMode,
        /** Apply a change instantly and remember it on this device. */
        set: function (patch) {
            state = merge(state, patch);
            state.savedAt = Date.now();
            writeLocal(state);
            apply();
        },
        reset: function () {
            var keepShell = state.sidebarCollapsed;
            state = merge({}, { sidebarCollapsed: keepShell, savedAt: Date.now() });
            writeLocal(state);
            apply();
        },
        /** Live-preview a colour theme without committing it. */
        preview: function (key) {
            try { key ? global.sessionStorage.setItem(PREVIEW_KEY, key) : global.sessionStorage.removeItem(PREVIEW_KEY); } catch (e) { /* ignore */ }
            apply();
        },
        endPreview: function () { api.preview(''); },
        /** "Save to account": persist to users.settings. */
        save: function (extra) {
            var body = merge(state, extra || {});
            var payload = {};
            for (var k in body) payload[k] = body[k];
            if (extra && extra.ui) payload.ui = extra.ui;
            var fd = new FormData();
            fd.append('settings', JSON.stringify(payload));
            return fetch('ajax.php?action=save_ui_settings', {
                method: 'POST', body: fd, credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
        },
        onChange: function (fn) { listeners.push(fn); }
    };

    if (mql) {
        var onScheme = function () { if (state.theme === 'system' && !activeColorTheme()) apply(); };
        mql.addEventListener ? mql.addEventListener('change', onScheme) : mql.addListener(onScheme);
    }

    // Another tab changed the theme: follow it.
    global.addEventListener('storage', function (e) {
        if (e.key === KEY) { state = merge(readLocal()); apply(); }
    });

    apply();
    global.UITheme = api;
})(window);
