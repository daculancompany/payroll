/* ==========================================================================
   App-wide tooltip engine.  Styles: assets2/css/app-tooltip.css

   One <body>-parented, position:fixed element shared by every tooltip in the
   app. It replaces four separate ::after implementations that each worked only
   where nothing clipped them — the moment a hint lived inside a scrolling panel
   (the DTR record list, the Form 48 sheet, any card) it was cut off at the
   container edge, and raising z-index could not help: an overflow box clips its
   descendants before stacking is considered.

   Two ways in:
     data-tip="…"   explicit, preferred, supports \n for multi-line hints
     title="…"      adopted on first hover — the attribute is MOVED to data-tip
                    so the browser's own tooltip stops competing with this one.
                    That upgrades every existing title= in the app with no edits.
                    Opt out with data-no-tip.

   Delegated off document, so markup rendered later (record cards are rebuilt on
   every fetch, the Form 48 on every day change) is covered without re-binding.
   ========================================================================== */
(function () {
    'use strict';

    var pop = null;
    var current = null;

    function ensure() {
        if (!pop) {
            pop = document.createElement('div');
            pop.id = 'app-tip';
            pop.setAttribute('role', 'tooltip');
            document.body.appendChild(pop);
        }
        return pop;
    }

    // The trigger for an event, adopting a native title on the way if needed.
    // Adoption is deliberately lazy: doing it up front would mean walking the
    // whole document on load and again after every render.
    function trigger(e) {
        var el = (e.target && e.target.closest) ? e.target.closest('[data-tip], [title]') : null;
        if (!el) return null;
        if (el.hasAttribute('data-no-tip')) return null;
        if (!el.hasAttribute('data-tip')) {
            var t = el.getAttribute('title');
            if (!t || !t.trim()) return null;
            el.setAttribute('data-tip', t);
            el.removeAttribute('title');     // stop the native bubble doubling up
        }
        return el;
    }

    function show(el) {
        var tip = el.getAttribute('data-tip');
        if (!tip || !tip.trim()) return;

        var p = ensure();
        current = el;

        // First line is the heading, the rest is muted detail — the convention
        // every caller in the app already writes to ("the fact, then why").
        // Built with textContent nodes rather than innerHTML: tips carry
        // employee names, shift labels and free-text notes, none of which may
        // be parsed as markup.
        var lines = tip.split('\n');
        p.textContent = '';
        var head = document.createElement('b');
        head.textContent = lines[0];
        p.appendChild(head);
        if (lines.length > 1) {
            var rest = document.createElement('span');
            rest.className = 'app-tip-k';
            rest.textContent = '\n' + lines.slice(1).join('\n');
            p.appendChild(rest);
        }

        p.classList.add('show');
        p.classList.remove('flip', 'in');

        var r  = el.getBoundingClientRect();
        var pr = p.getBoundingClientRect();
        var M  = 8;

        // Centred under the trigger, then clamped into the viewport — a button
        // at either edge of the screen would otherwise render half off it.
        var left = r.left + (r.width / 2) - (pr.width / 2);
        left = Math.max(M, Math.min(left, window.innerWidth - pr.width - M));

        // Below by default; above when the bottom of the screen is too close.
        var top = r.bottom + M;
        if (top + pr.height > window.innerHeight - M) {
            top = r.top - pr.height - M;
            p.classList.add('flip');
        }

        p.style.left = Math.round(left) + 'px';
        p.style.top  = Math.round(Math.max(M, top)) + 'px';
        // Arrow follows the trigger even after the clamp shifted the body.
        p.style.setProperty('--arrow',
            Math.round(Math.max(10, Math.min(pr.width - 10, r.left + r.width / 2 - left))) + 'px');

        // Next frame, so the transition has a start state to animate from.
        requestAnimationFrame(function () { p.classList.add('in'); });
    }

    function hide() {
        current = null;
        if (typeof timer !== 'undefined') clearTimeout(timer);
        if (pop) pop.classList.remove('show', 'in', 'flip');
    }

    // A short delay before opening. Sweeping the pointer across a dense grid
    // (the duty roster is ~30 cells wide) or a row of icon buttons would
    // otherwise strobe a tooltip per cell; at this length it is imperceptible
    // when you actually mean to hover something.
    var OPEN_DELAY = 150;
    var timer = null;
    function schedule(el) {
        clearTimeout(timer);
        timer = setTimeout(function () { show(el); }, OPEN_DELAY);
    }

    document.addEventListener('mouseover', function (e) {
        var el = trigger(e);
        if (el && el !== current) schedule(el);
    });
    document.addEventListener('mouseout', function (e) {
        var el = (e.target && e.target.closest) ? e.target.closest('[data-tip]') : null;
        if (el) { clearTimeout(timer); if (el === current) hide(); }
    });
    // Keyboard and touch: a tap moves focus, which is the only "hover" a phone has.
    document.addEventListener('focusin', function (e) {
        var el = trigger(e);
        if (el) show(el);
    });
    document.addEventListener('focusout', hide);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });

    // A fixed tooltip does not travel with its trigger, so any scroll or resize
    // would leave it stranded beside the wrong element. Capture phase catches
    // inner scrollers too, not just the window.
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
    // Clicking usually opens a modal or navigates — a leftover tooltip would
    // hang over the new content.
    document.addEventListener('click', hide, true);
})();
