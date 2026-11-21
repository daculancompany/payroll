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
    // Printable documents (and their thumbnails): kept light paper.
    var PAPER = '[data-v2-paper]' + (window.V2_PAPER_SEL ? ', ' + window.V2_PAPER_SEL : '');
    // Elements styled entirely by CSS: the adapter leaves them alone.
    // .dtrf48: the DTR (Civil Service Form 48) always stays a white printed form.
    var KEEP = '[data-v2-keep], .apexcharts-canvas, .v2-topbar, .v2-sidebar, .cs-select, .cs-menu, .pd-view-btn, .att-empty-btn, .dtrf48' + (window.V2_KEEP_SEL ? ', ' + window.V2_KEEP_SEL : '');
    var SCOPE = (window.V2_ADAPT_SCOPE || '#main-content, .modal, .offcanvas') + ', .swal2-container';   // SweetAlert dialogs (tables in previews)
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
        if (isBrandPurple(c)) return m.paper ? 'var(--brand-700)' : 'var(--brand-600)';
        if (isPurple(c) && lum(c) > 195) return 'var(--app-tint)';
        if (!m.themed || lum(c) <= 195) return null;
        if (sat(c) < 14) return lum(c) > 246 ? 'var(--v2-surface)' : 'var(--v2-surface-2)';
        // Pastel status fills become a faint wash of the same hue: colour stays
        // in the text and icons, not in glowing pills and boxes.
        var v = vivid(c);
        return 'rgba(' + v.join(',') + ',' + (m.dark ? .07 : .1) + ')';
    }

    /* Each element's original colours, read once with no override applied.
       A theme switch recomputes from this cache with writes only, so the
       page restyles once instead of being re-read element by element. */
    var orig = new WeakMap();
    var known = [];
    var hidden = new Set();             // skipped while display:none, see unhide()
    var SIDES = ['top', 'right', 'bottom', 'left'];

    function read(el) {
        var o = orig.get(el);
        if (o) return o;
        if (SKIP_TAGS[el.tagName] || el.closest(KEEP)) o = { skip: true };
        else {
            var cs = getComputedStyle(el);
            if (cs.display === 'none') { hidden.add(el); return null; }   // read again once shown (unhide)
            var bi = cs.backgroundImage;
            o = {
                // Inside a printable document: accent swap only, never darkened.
                paper: !!el.closest(PAPER),
                // inside a button: app.css owns the look; only legacy purple is swapped
                btn: !!el.closest('.btn'),
                // popovers / dropdowns float over content: translucent theme
                // surfaces would let the page show through, so use the opaque one
                floating: (cs.position === 'absolute' || cs.position === 'fixed') && (cs.boxShadow !== 'none' || parseInt(cs.zIndex, 10) > 0),
                // Must be solid on a colour theme: a dialog's own panel (the
                // page showed through the payroll Table View) and sticky
                // headers / frozen columns that rows scroll underneath.
                solid: el.matches('.modal-content, .offcanvas, .dropdown-menu, .popover') || cs.position === 'sticky',
                th: el.tagName === 'TH',
                bg: parse(cs.backgroundColor),
                stops: bi && bi.indexOf('gradient') !== -1 ? (bi.match(/rgba?\([^)]+\)/g) || []).map(parse).filter(Boolean) : null,
                fg: parse(cs.color),
                borders: SIDES.map(function (side) {
                    return parseFloat(cs['border-' + side + '-width']) > 0 ? parse(cs['border-' + side + '-color']) : null;
                }),
                widths: SIDES.map(function (side) { return parseFloat(cs['border-' + side + '-width']) || 0; })
            };
        }
        orig.set(el, o);
        hidden.delete(el);
        known.push(el);
        return o;
    }

    var LIGHT = { dark: false, themed: false, paper: true };   // deeper accent shades on white paper

    /* Buttons: app.css styles every Bootstrap variant, so the adapter only
       swaps hardcoded legacy purple (inline styles, page rules) for the
       accent, using the same tokens as app.css so text contrast is right. */
    function decideBtn(el, o, m) {
        var isBtn = el.classList.contains('btn');
        var purpleFill = (o.bg && o.bg[3] > 0.5 && isBrandPurple(o.bg)) || (o.stops && o.stops.length && o.stops.some(isBrandPurple));
        if (purpleFill) {
            if (o.stops && o.stops.length) set(el, 'background-image', 'none');
            set(el, 'background-color', 'var(--acc-bg)');
            if (isBtn) set(el, 'color', 'var(--acc-fg)');
        } else if (o.fg && isBrandPurple(o.fg)) {
            set(el, 'color', m.dark ? 'var(--brand-300)' : 'var(--brand-600)');
        }
        o.borders.forEach(function (bc, i) {
            if (bc && bc[3] > 0 && isBrandPurple(bc)) set(el, 'border-' + SIDES[i] + '-color', 'var(--acc-bg)');
        });
    }

    function decide(el, o, m) {
        if (!o || o.skip) return;
        if (o.btn) { decideBtn(el, o, m); return; }
        if (o.paper) m = LIGHT;   // documents stay light paper (tinted, or white with the setting)
        var bg = o.bg;
        if (bg && bg[3] > 0.5) {
            var nb = bgFor(bg, m);
            if (o.floating && m.themed && nb && nb.indexOf('--v2-surface') !== -1) nb = 'var(--v2-raised)';
            if (nb && o.solid && m.themed && !(o.stops && o.stops.length)) {
                // Same look, on an opaque base: neutral surfaces → the raised
                // surface; a tint (rgba wash) is layered over it, not see-through.
                var base = o.th ? 'var(--v2-table-head)' : 'var(--v2-raised)';
                if (nb.indexOf('--v2-surface') !== -1) nb = base;
                else if (nb.indexOf('rgba(') === 0) { set(el, 'background-image', 'linear-gradient(' + nb + ', ' + nb + ')'); nb = base; }
            }
            if (nb) set(el, 'background-color', nb);
        }
        var stops = o.stops;
        if (stops && stops.length) {
            if (stops.every(isBrandPurple)) { set(el, 'background-image', 'none'); set(el, 'background-color', 'var(--brand-600)'); }
            else if (stops.some(isBrandPurple) && stops.every(function (c) { return sat(c) > 40; })) {
                set(el, 'background-image', 'linear-gradient(90deg, var(--brand-500), var(--brand-700))');
            }
            else if (stops.every(function (c) { return isPurple(c) && lum(c) > 195; })) {
                set(el, 'background-image', 'none');           // old light-purple wash → accent tint
                set(el, 'background-color', 'var(--app-tint)');
            }
            else if (m.themed && stops.every(function (c) { return lum(c) > 195; })) {
                set(el, 'background-image', 'none');
                set(el, 'background-color', bgFor(stops[0], m) || 'var(--v2-surface-2)');
            }
        }
        var fg = o.fg;
        if (fg) {
            if (isBrandPurple(fg)) set(el, 'color', m.dark ? 'var(--brand-300)' : m.paper ? 'var(--brand-800)' : 'var(--brand-600)');
            else if (m.dark) {
                var l = lum(fg), s = sat(fg);
                if (l < 100 && s < 40) set(el, 'color', 'var(--v2-text)');
                else if (l < 175 && s < 40) set(el, 'color', 'var(--v2-text-2)');
                else if (l < 150 && s >= 40) set(el, 'color', lighten(fg, l < 110 ? .5 : .35));
            }
        }
        // Clean look: a left or top border thicker than the card's other sides
        // is a status/accent stripe. Level it with the other sides, neutral.
        // (Bottom is left alone: that's where active-tab underlines live.)
        var stripe = {};
        if (!m.paper) [0, 3].forEach(function (i) {
            var w = o.widths[i], base = Math.min(o.widths[1], o.widths[2]);
            if (w >= 2 && w - base >= 1.5 && o.borders[i] && o.borders[i][3] > 0) {
                set(el, 'border-' + SIDES[i] + '-width', base + 'px');
                if (base > 0) set(el, 'border-' + SIDES[i] + '-color', 'var(--v2-border)');
                stripe[i] = true;
            }
        });
        o.borders.forEach(function (bc, i) {
            if (!bc || bc[3] === 0 || stripe[i]) return;
            var prop = 'border-' + SIDES[i] + '-color';
            if (isBrandPurple(bc)) set(el, prop, m.paper ? 'var(--brand-700)' : 'var(--brand-500)');
            else if (isPurple(bc) && lum(bc) > 180) set(el, prop, 'var(--app-tint-border)');
            else if (m.themed && lum(bc) > 185 && sat(bc) < 30) set(el, prop, 'var(--v2-border)');
            else if (m.themed && lum(bc) > 185) set(el, prop, 'var(--v2-border)');   // coloured hairlines go neutral
        });
    }

    /* Icon-only buttons get .v2-btn-icon (square). CSS can't tell: an <i>
       next to a text label is still the only *element* child. Toggled only
       when the state changes, so the class mutation can't loop. */
    function markIconBtn(el) {
        var only = !el.textContent.trim() && !!el.querySelector('i, svg, img');
        if (only !== el.classList.contains('v2-btn-icon')) el.classList.toggle('v2-btn-icon', only);
    }
    function adapt(el, m) {
        if (el.classList && el.classList.contains('btn')) markIconBtn(el);
        decide(el, read(el), m);
    }

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
        var list = [], changed = [], shown = new Set();
        muts.forEach(function (mu) {
            mu.addedNodes.forEach(function (node) {
                if (node.nodeType === 1 && node.closest && node.closest(SCOPE)) collect(node, list);
            });
            if (mu.type === 'childList' && mu.target.classList && mu.target.classList.contains('btn')) markIconBtn(mu.target);
            if (mu.type !== 'attributes') return;
            if (mu.attributeName === 'class') {
                if (mu.target.closest && mu.target.closest(SCOPE) && changed.indexOf(mu.target) === -1) changed.push(mu.target);
            } else if (hidden.size) {
                // style / hidden: something may have just been un-hidden
                // (the payroll Table View swaps its panes with style.display).
                // Our own writes land here too; a Set keeps that cheap.
                shown.add(mu.target);
            }
        });
        if (list.length) process(list);
        if (changed.length) reclass(changed);
        if (shown.size) unhide(shown);
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });

    /* Elements skipped while display:none (read() returns null for them) are
       remembered here and adapted the moment they, or an ancestor, show. */
    function unhide(targets) {
        var list = [];
        hidden.forEach(function (el) {
            if (!el.isConnected) { hidden.delete(el); return; }
            // the changed element is el itself or one of its ancestors
            var a = el;
            while (a && !targets.has(a)) a = a.parentElement;
            if (!a) return;
            if (getComputedStyle(el).display === 'none') return;
            hidden.delete(el);
            list.push(el);
            var kids = el.querySelectorAll('*');
            for (var i = 0; i < kids.length; i++) if (!orig.has(kids[i])) list.push(kids[i]);
        });
        if (list.length) process(list);
    }

    /* Class change (a toggle gaining .active, a popover opening): re-adapt
       right away, inside the observer callback, so it never paints in the old
       colours. Transitions are switched off on the changed elements while
       reading, so computed values are final rather than mid-animation.
       Descendants not adapted yet (they were display:none until now) are
       picked up too. */
    function reclass(els) {
        var m = mode(), list = [];
        els.forEach(function (el) {
            el.style.setProperty('transition', 'none', 'important');
            var rec = saved.get(el);
            if (rec) {
                for (var p in rec) { if (rec[p][0]) el.style.setProperty(p, rec[p][0], rec[p][1]); else el.style.removeProperty(p); }
                saved.delete(el); touched.delete(el);
            }
            orig.delete(el);
            list.push(el);
            var kids = el.querySelectorAll('*');
            for (var i = 0; i < kids.length; i++) if (!orig.has(kids[i])) list.push(kids[i]);
        });
        process(list);   // a big table past the sync budget spills to idle time instead of being dropped
        requestAnimationFrame(function () { els.forEach(function (el) { el.style.removeProperty('transition'); }); });
    }

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

    /* Hover states: page CSS has its own light :hover colours, which a
       one-off read at rest never sees. On pointer-enter, read the element's
       hover colours (transition off, so they're final) and override them for
       the theme; put things back on leave. Only the hovered element and a
       few ancestors are read, so this stays cheap. */
    function hoverAdapt(el, m) {
        if (el.__v2h || el.closest(KEEP)) return;
        var inBtn = !!el.closest('.btn');
        if (el.closest(PAPER)) return;
        var prevT = [el.style.getPropertyValue('transition'), el.style.getPropertyPriority('transition')];
        el.style.setProperty('transition', 'none', 'important');
        // Only a real :hover colour gets a wash. Hovering any child bubbles up
        // to its card / dialog panel; reading our own override (or the plain
        // translucent surface) as a "hover colour" turned cards and modals
        // see-through under the pointer on the light colour themes.
        var o = orig.get(el), rec = saved.get(el);
        var ours = rec && ('background-color' in rec) ? [el.style.getPropertyValue('background-color'), el.style.getPropertyPriority('background-color')] : null;
        if (ours) el.style.removeProperty('background-color');   // read the page's own hover colour
        var cs = getComputedStyle(el), done = [];
        var bg = parse(cs.backgroundColor);
        if (ours) el.style.setProperty('background-color', ours[0], ours[1]);
        var ownHover = !(o && o.solid)                                          // solid panels never wash
            && !(o && o.bg && bg && o.bg.join() === bg.join());                 // same as at rest = no :hover rule
        if (ownHover && bg && bg[3] > 0.5) {
            var nb = inBtn ? (isBrandPurple(bg) ? 'var(--acc-bg-hover)' : null) : bgFor(bg, m);
            if (nb) {
                if (nb.indexOf('--v2-surface') !== -1) nb = 'var(--v2-active)';   // neutral hover wash
                done.push(['background-color', el.style.getPropertyValue('background-color'), el.style.getPropertyPriority('background-color')]);
                el.style.setProperty('background-color', nb, 'important');
            }
        }
        var fg = parse(cs.color);
        if (fg && (isBrandPurple(fg) || (!inBtn && m.dark && lum(fg) < 100 && sat(fg) < 40))) {
            done.push(['color', el.style.getPropertyValue('color'), el.style.getPropertyPriority('color')]);
            el.style.setProperty('color', isBrandPurple(fg) ? (m.dark ? 'var(--brand-300)' : 'var(--brand-600)') : 'var(--v2-text)', 'important');
        }
        function putBackTransition() {
            if (prevT[0]) el.style.setProperty('transition', prevT[0], prevT[1]); else el.style.removeProperty('transition');
        }
        if (!done.length) { putBackTransition(); return; }
        el.__v2h = true;
        el.addEventListener('mouseleave', function off() {
            el.removeEventListener('mouseleave', off);
            done.forEach(function (d) { if (d[1]) el.style.setProperty(d[0], d[1], d[2]); else el.style.removeProperty(d[0]); });
            putBackTransition();
            el.__v2h = false;
        });
    }
    document.addEventListener('mouseover', function (e) {
        var m = mode();
        if (!m.themed || !e.target.closest) return;
        var el = e.target, n = 0;
        while (el && el.nodeType === 1 && n++ < 4 && el.closest(SCOPE)) { hoverAdapt(el, m); el = el.parentElement; }
    }, true);

    window.V2ThemeAdapter = { refresh: full };
    full();
})();
