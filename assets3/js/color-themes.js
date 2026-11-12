/* Colour themes for the v2 shell.
 *
 * A colour theme paints one gradient behind the whole app and turns every
 * surface (sidebar, topbar, cards, tables) into a translucent tint over it.
 * Each theme also decides light vs dark text (`mode`), its own accent, and an
 * opaque `base` for floating UI (modals, dropdowns) that sits outside the
 * layered page.
 *
 * Vanilla port of the GradeTrackr registry (same keys and gradients), so a
 * user who knows one app finds the same themes in the other.
 */
(function (global) {
    'use strict';

    var RAW = [
        // Light: pastel gradients, dark text
        { key: 'mint', label: 'Mint', mode: 'light', css: 'linear-gradient(135deg,#a8e6cf,#dcedc1)' },
        { key: 'peach', label: 'Peach', mode: 'light', css: 'linear-gradient(135deg,#ffd194,#ffafbd)' },
        { key: 'lavender', label: 'Lavender', mode: 'light', css: 'linear-gradient(135deg,#c2c8f0,#b6c6e6)' },
        { key: 'lemon', label: 'Lemon Mint', mode: 'light', css: 'linear-gradient(135deg,#f7f7b6,#c8f7c5)' },
        { key: 'rosegold', label: 'Rose Gold', mode: 'light', css: 'linear-gradient(135deg,#f4c4c4,#d6c7a1)' },
        { key: 'blossom', label: 'Blossom', mode: 'light', css: 'linear-gradient(135deg,#fcdce8,#e4d6f5)' },
        { key: 'aqua', label: 'Aqua', mode: 'light', css: 'linear-gradient(135deg,#d6eefc,#d4f5e9)' },
        { key: 'cream', label: 'Cream', mode: 'light', css: 'linear-gradient(135deg,#f5f0d6,#efe9c8)' },
        { key: 'sky', label: 'Sky', mode: 'light', css: 'linear-gradient(135deg,#cfe7ff,#e8dcff)' },
        { key: 'sage', label: 'Sage', mode: 'light', css: 'linear-gradient(135deg,#dfe9d8,#c5dcc9)' },
        { key: 'coral', label: 'Coral Reef', mode: 'light', css: 'linear-gradient(135deg,#ffd6c9,#ffeab0)' },
        { key: 'hanami', label: 'Hanami', mode: 'light', css: 'linear-gradient(135deg,#f8d3dd,#e2ecc6)' },
        { key: 'lilac', label: 'Lilac Mist', mode: 'light', css: 'linear-gradient(135deg,#e5d4f7,#cfe1f7,#d6f2ee)' },
        { key: 'sand', label: 'Desert Sand', mode: 'light', css: 'linear-gradient(135deg,#efe1c6,#e3cdb0)' },
        // Dark: deep gradients, light text
        { key: 'sunset', label: 'Sunset', mode: 'dark', css: 'linear-gradient(135deg,#5b2c83,#e07a5f)' },
        { key: 'spectrum', label: 'Spectrum', mode: 'dark', css: 'linear-gradient(135deg,#3a1c71,#d76d77,#21d4fd)' },
        { key: 'forest', label: 'Forest', mode: 'dark', css: 'linear-gradient(135deg,#234d20,#77a86b)' },
        { key: 'ember', label: 'Ember', mode: 'dark', css: 'linear-gradient(135deg,#420516,#7a0c12)' },
        { key: 'midnight', label: 'Midnight', mode: 'dark', css: 'linear-gradient(135deg,#0b1437,#3b2a78)' },
        { key: 'rust', label: 'Rust', mode: 'dark', css: 'linear-gradient(135deg,#5c1a1a,#7a3b2e)' },
        { key: 'slate', label: 'Slate', mode: 'dark', css: 'linear-gradient(135deg,#6b7280,#9aa6b2)' },
        { key: 'pine', label: 'Pine', mode: 'dark', css: 'linear-gradient(135deg,#2f4f4f,#3c6e5a)' },
        { key: 'ocean', label: 'Ocean', mode: 'dark', css: 'linear-gradient(135deg,#136a8a,#267871)' },
        { key: 'orchid', label: 'Orchid', mode: 'dark', css: 'linear-gradient(135deg,#7b2a8a,#c850c0,#4158d0)' },
        { key: 'marigold', label: 'Marigold', mode: 'dark', css: 'linear-gradient(135deg,#aa2e57,#e0852a)' },
        { key: 'aurora', label: 'Aurora', mode: 'dark', css: 'linear-gradient(135deg,#0f4c81,#1cb5a3)' },
        { key: 'moss', label: 'Moss', mode: 'dark', css: 'linear-gradient(135deg,#3a3a1a,#5a4a1a)' },
        { key: 'cobalt', label: 'Cobalt', mode: 'dark', css: 'linear-gradient(135deg,#1a2a8a,#3a5ad0)' },
        { key: 'dusk', label: 'Dusk', mode: 'dark', css: 'linear-gradient(135deg,#2c2552,#8a4f7d,#d98c6b)' },
        { key: 'nebula', label: 'Nebula', mode: 'dark', css: 'linear-gradient(135deg,#1b1240,#5b2a86,#1f6fb2)' },
        { key: 'neon', label: 'Neon Nights', mode: 'dark', css: 'linear-gradient(135deg,#0f2a4a,#5a2b8c,#0e8a8a)' },
        { key: 'crimson', label: 'Crimson Moon', mode: 'dark', css: 'linear-gradient(135deg,#2a0a10,#8e1b2c)' },
        { key: 'deepsea', label: 'Under the Sea', mode: 'dark', css: 'linear-gradient(135deg,#0b2a33,#1f5f5b,#5a8f6a)' },
        { key: 'storm', label: 'Retro Storm', mode: 'dark', css: 'linear-gradient(135deg,#2b3a55,#5b6b8c)' },
        { key: 'plum', label: 'Plum', mode: 'dark', css: 'linear-gradient(135deg,#2d1433,#6b2d5c)' },
        { key: 'espresso', label: 'Espresso', mode: 'dark', css: 'linear-gradient(135deg,#24170f,#5a3a24)' },
        { key: 'emerald', label: 'Emerald', mode: 'dark', css: 'linear-gradient(135deg,#06302a,#0f7a5c)' },
        { key: 'graphite', label: 'Graphite', mode: 'dark', css: 'linear-gradient(135deg,#17191c,#3a3f46)' },
        { key: 'default', label: 'Default Blue', mode: 'dark', css: 'linear-gradient(135deg,#0f172a,#1e3a5f,#2196f3)' }
    ];

    function hexStops(css) {
        return (css.match(/#[0-9a-fA-F]{6}\b/g) || []).map(function (h) {
            var n = parseInt(h.slice(1), 16);
            return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
        });
    }
    function luminance(c) { return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
    function mix(c, target, amount) {
        return c.map(function (v) { return Math.round(v + (target - v) * amount); });
    }
    function toHex(c) {
        return '#' + c.map(function (v) { return ('0' + v.toString(16)).slice(-2); }).join('');
    }
    function saturation(c) { return Math.max.apply(null, c) - Math.min.apply(null, c); }

    /* Opaque surface for floating UI: darkest stop pushed toward black (dark
       themes) or lightest stop pushed toward white (light themes). */
    function deriveBase(stops, mode) {
        var sorted = stops.slice().sort(function (a, b) { return luminance(a) - luminance(b); });
        if (mode === 'dark') {
            return { base: toHex(mix(sorted[0], 0, 0.45)), raised: toHex(mix(sorted[0], 0, 0.25)) };
        }
        var lightest = sorted[sorted.length - 1];
        return { base: toHex(mix(lightest, 255, 0.82)), raised: toHex(mix(lightest, 255, 0.6)) };
    }

    /* Accent: the most saturated stop, nudged until it reads as text/icon on
       the theme's own surfaces. */
    function deriveAccent(stops, mode) {
        var c = stops.slice().sort(function (a, b) { return saturation(b) - saturation(a); })[0];
        var i;
        if (mode === 'light') {
            c = mix(c, 0, 0.35);
            for (i = 0; i < 12 && luminance(c) > 105; i++) c = mix(c, 0, 0.1);
        } else {
            c = mix(c, 255, 0.1);
            for (i = 0; i < 12 && luminance(c) < 150; i++) c = mix(c, 255, 0.12);
        }
        return toHex(c);
    }

    var THEMES = RAW.map(function (t) {
        var stops = hexStops(t.css);
        var b = deriveBase(stops, t.mode);
        return {
            key: t.key, label: t.label, mode: t.mode, css: t.css,
            base: b.base, raised: b.raised, accent: deriveAccent(stops, t.mode)
        };
    });

    global.ColorThemes = {
        list: THEMES,
        get: function (key) {
            if (!key) return null;
            for (var i = 0; i < THEMES.length; i++) if (THEMES[i].key === key) return THEMES[i];
            return null;
        },
        util: { hexStops: hexStops, mix: mix, toHex: toHex, luminance: luminance }
    };
})(window);
