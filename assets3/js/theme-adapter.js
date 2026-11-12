/* v2 theme adapter: makes page components follow the theme, generically.
 *
 * The page fragments were designed for one light, purple look, with colours
 * hardcoded in their own CSS and in HTML their JS builds (cards, tiles,
 * chips, toolbars). Rewriting all of them by hand doesn't scale, so this
 * reads what each element actually renders and remaps it:
 *
 *   always            old brand purple → current accent (bg, text, border)
 *   dark or colour    white / grey surfaces → theme surfaces
 *   theme             pastel status tints → translucent tint of the same hue
 *                     light neutral borders → theme border
 *   dark              dark / grey text → theme text; dark coloured text is
 *                     lightened so it stays readable
 *
 * Overrides are inline !important and the original inline value is kept,
 * so a theme switch restores the page and re-adapts it. Runs after each
 * navigation, on theme change, and on DOM additions (JS-rendered cards),
 * in idle-time chunks. Mark an element data-v2-keep to opt out.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var SCOPE = '#main-content, .modal, .offcanvas, #empQuickView';
    var SKIP_TAGS = { IMG: 1, VIDEO: 1, CANVAS: 1, SVG: 1, PATH: 1, SCRIPT: 1, STYLE: 1, OPTION: 1, BR: 1, HR: 1, INPUT: 0 };
    var touched = new Set();
    var saved = new WeakMap();          // el → { prop: [value, priority] }

    function parse(c) {
        var m = c && c.match(/rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?/);
        return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
    }
    function lum(c) { return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
    function sat(c) { return Math.max(c[0], c[1], c[2]) - Math.min(c[0], c[1], c[2]); }
    function hue(c) {
        var r = c[0] / 255, g = c[1] / 255, b = c[2] / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min, h = 0;
        if (!d) return 0;
        if (max === r) h = ((g - b) / d) % 6; else if (max === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
        h *= 60; return h < 0 ? h + 360 : h;
    }
    function isPurple(c) { var h = hue(c); return sat(c) >= 12 && h >= 248 && h <= 290; }
    function isBrandPurple(c) { return isPurple(c) && sat(c) > 60 && lum(c) < 175; }
    /** Pastel → the same hue at full strength, for a translucent tint. */
    function vivid(c) {
        var mn = Math.min(c[0], c[1], c[2]), mx = Math.max(c[0], c[1], c[2]), k = 255 / ((mx - mn) || 1);
        return [0, 1, 2].map(function (i) { return Math.round((c[i] - mn) * k * 0.85); });
    }
    function lighten(c, a) { return 'rgb(' + c.slice(0, 3).map(function (v) { return Math.round(v + (255 - v) * a); }).join(',') + ')'; }

    /* Writes are buffered during a batch: reading computed styles and
       writing inline styles in alternation forces a style recalc per element
       (hundreds of ms on a big page). Read everything, then write once. */
    var batch = null;
    function set(el, prop, value) {
        if (batch) { batch.push(el, prop, value); return; }
        write(el, prop, value);
    }
    function flush() {
        var b = batch; batch = null;
        for (var i = 0; i < b.length; i += 3) write(b[i], b[i + 1], b[i + 2]);
    }
    function write(el, prop, value) {
        var rec = saved.get(el);
        if (!rec) { rec = {}; saved.set(el, rec); }
        if (!(prop in rec)) rec[prop] = [el.style.getPropertyValue(prop), el.style.getPropertyPriority(prop)];
        el.style.setProperty(prop, value, 'important');
        touched.add(el);
    }
    function restoreAll() {
        touched.forEach(function (el) {
            var rec = saved.get(el); if (!rec) return;
            for (var p in rec) {
                if (rec[p][0]) el.style.setProperty(p, rec[p][0], rec[p][1]); else el.style.removeProperty(p);
            }
            saved.delete(el);
        });
        touched.clear();
    }

    function mode() {
        return {
            dark: root.getAttribute('data-bs-theme') === 'dark',
            themed: root.getAttribute('data-bs-theme') === 'dark' || root.classList.contains('ui-ct')
        };
    }

    function bgFor(c, m) {
        if (isBrandPurple(c)) return 'var(--brand-600)';
        if (isPurple(c) && lum(c) > 195) return 'var(--app-tint)';
        if (!m.themed || lum(c) <= 195) return null;
        if (sat(c) < 14) return lum(c) > 246 ? 'var(--v2-surface)' : 'var(--v2-surface-2)';
        var v = vivid(c);
        return 'rgba(' + v.join(',') + ',' + (m.dark ? .16 : .2) + ')';
    }

    /* Each element's original colours, read once with no override applied.
       A theme switch recomputes from this cache with writes only, so the
       page restyles once instead of being re-read element by element. */
    var orig = new WeakMap();
    var known = [];
    var SIDES = ['top', 'right', 'bottom', 'left'];

    function read(el) {
        var o = orig.get(el);
        if (o) return o;
        if (SKIP_TAGS[el.tagName] || el.closest('[data-v2-keep], .apexcharts-canvas, .v2-topbar, .v2-sidebar')) o = { skip: true };
        else {
            var cs = getComputedStyle(el);
            if (cs.display === 'none') return null;          // read again once shown
            var bi = cs.backgroundImage;
            o = {
                bg: parse(cs.backgroundColor),
                stops: bi && bi.indexOf('gradient') !== -1 ? (bi.match(/rgba?\([^)]+\)/g) || []).map(parse).filter(Boolean) : null,
                fg: parse(cs.color),
                borders: SIDES.map(function (side) {
                    return parseFloat(cs['border-' + side + '-width']) > 0 ? parse(cs['border-' + side + '-color']) : null;
                })
            };
        }
        orig.set(el, o);
        known.push(el);
        return o;
    }

    function decide(el, o, m) {
        if (!o || o.skip) return;
        var bg = o.bg;
        if (bg && bg[3] > 0.5) {
            var nb = bgFor(bg, m);
            if (nb) set(el, 'background-color', nb);
        }
        var stops = o.stops;
        if (stops && stops.length) {
            if (stops.every(isBrandPurple)) { set(el, 'background-image', 'none'); set(el, 'background-color', 'var(--brand-600)'); }
            else if (stops.some(isBrandPurple) && stops.every(function (c) { return sat(c) > 40; })) {
                set(el, 'background-image', 'linear-gradient(90deg, var(--brand-500), var(--brand-700))');
            }
            else if (m.themed && stops.every(function (c) { return lum(c) > 195; })) {
                set(el, 'background-image', 'none');
                set(el, 'background-color', bgFor(stops[0], m) || 'var(--v2-surface-2)');
            }
        }
        var fg = o.fg;
        if (fg) {
            if (isBrandPurple(fg)) set(el, 'color', m.dark ? 'var(--brand-300)' : 'var(--brand-600)');
            else if (m.dark) {
                var l = lum(fg), s = sat(fg);
                if (l < 100 && s < 40) set(el, 'color', 'var(--v2-text)');
                else if (l < 175 && s < 40) set(el, 'color', 'var(--v2-text-2)');
                else if (l < 150 && s >= 40) set(el, 'color', lighten(fg, l < 110 ? .5 : .35));
            }
        }
        o.borders.forEach(function (bc, i) {
            if (!bc || bc[3] === 0) return;
            var prop = 'border-' + SIDES[i] + '-color';
            if (isBrandPurple(bc)) set(el, prop, 'var(--brand-500)');
            else if (isPurple(bc) && lum(bc) > 180) set(el, prop, 'var(--app-tint-border)');
            else if (m.themed && lum(bc) > 185 && sat(bc) < 30) set(el, prop, 'var(--v2-border)');
            else if (m.themed && lum(bc) > 185) set(el, prop, 'rgba(' + vivid(bc).join(',') + ',.35)');
        });
    }

    function adapt(el, m) { decide(el, read(el), m); }

    /** Theme switch: recompute every known element from its cached colours. */
    function retheme() {
        var m = mode();
        root.classList.add('v2-adapting');
        known = known.filter(function (el) { return el.isConnected; });
        batch = [];
        restoreAll();                     // writes only
        known.forEach(function (el) { decide(el, orig.get(el), m); });
        flush();                          // writes only: one restyle for the lot
        requestAnimationFrame(function () { root.classList.remove('v2-adapting'); });
    }

    /* ── scheduling ─────────────────────────────────────────────────────
       Synchronous on purpose: the first pass runs before the page is first
       painted (the script sits right after #main-content), and nodes added
       later are adapted inside the MutationObserver callback, which runs
       before the browser paints them. So nothing flashes in the old colours.
       Only a very large batch spills over into idle time. */
    var SYNC_BUDGET = 6000;
    var queue = [], scheduled = false;
    var idle = window.requestIdleCallback || function (fn) { return setTimeout(function () { fn({ timeRemaining: function () { return 12; } }); }, 16); };

    function collect(rootEl, out) {
        if (!rootEl || rootEl.nodeType !== 1) return;
        out.push(rootEl);
        if (rootEl.querySelectorAll) Array.prototype.push.apply(out, rootEl.querySelectorAll('*'));
    }
    function process(list) {
        var m = mode(), i = 0;
        batch = [];
        for (; i < list.length && i < SYNC_BUDGET; i++) if (list[i].isConnected) adapt(list[i], m);
        flush();
        if (i < list.length) {
            Array.prototype.push.apply(queue, list.slice(i));
            if (!scheduled) { scheduled = true; idle(run); }
        }
    }
    function run(deadline) {
        var m = mode(), n = 0;
        batch = [];
        while (queue.length && (n++ < 60 || deadline.timeRemaining() > 2)) {
            var el = queue.shift();
            if (el.isConnected) adapt(el, m);
        }
        flush();
        if (queue.length) idle(run); else scheduled = false;
    }

    function full() {
        // Transitions off while reading: otherwise getComputedStyle returns the
        // animating value right after an override is removed.
        root.classList.add('v2-adapting');
        void root.offsetHeight;
        restoreAll();
        queue = [];
        var list = [];
        document.querySelectorAll(SCOPE).forEach(function (r) { collect(r, list); });
        process(list);
        requestAnimationFrame(function () { root.classList.remove('v2-adapting'); });
        root.classList.remove('v2-pending');
    }

    // Theme switch: restore everything and adapt for the new theme, same tick.
    window.addEventListener('ui-theme-change', retheme);
    // Navigation: the new fragment was already adapted by the observer; just
    // drop references to the old page's elements.
    window.addEventListener('v2:navigated', function () {
        touched.forEach(function (el) { if (!el.isConnected) { touched.delete(el); saved.delete(el); } });
        known = known.filter(function (el) { return el.isConnected; });
    });

    // JS-rendered components (cards, rows, modals filled over ajax).
    new MutationObserver(function (muts) {
        var list = [];
        muts.forEach(function (mu) {
            mu.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.closest && node.closest(SCOPE)) collect(node, list);
            });
            // Class change (e.g. a toggle gaining .active): re-read once its
            // colour transition has settled, never mid-animation.
            if (mu.type === 'attributes' && mu.target.closest && mu.target.closest(SCOPE)) later(mu.target);
        });
        if (list.length) process(list);
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

    function readapt(el) {
        var rec = saved.get(el);
        if (rec) {
            for (var p in rec) { if (rec[p][0]) el.style.setProperty(p, rec[p][0], rec[p][1]); else el.style.removeProperty(p); }
            saved.delete(el); touched.delete(el);
        }
        orig.delete(el);
        adapt(el, mode());
    }
    var pending = new Set(), pendingT = null;
    function later(el) {
        pending.add(el);
        clearTimeout(pendingT);
        pendingT = setTimeout(function () { pending.forEach(function (e) { if (e.isConnected) readapt(e); }); pending.clear(); }, 260);
    }
    document.addEventListener('transitionend', function (e) {
        // Elements already adapted are skipped: their transitions come from our
        // own override, and re-adapting them would loop. Class changes still
        // re-adapt them through the MutationObserver.
        if (touched.has(e.target)) return;
        if (/color|background/.test(e.propertyName) && e.target.closest && e.target.closest(SCOPE)) later(e.target);
    }, true);

    window.V2ThemeAdapter = { refresh: full };
    full();
})();
