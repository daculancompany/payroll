/* ════════════════════════════════════════════════════════════════════════
   Portal MD — Material touch ripple (pairs with assets2/css/portal-md.css).
   Reusable on any page: one delegated pointerdown listener injects an
   expanding .md-ripple span into whichever tappable was pressed. The CSS
   file gives every host position:relative + overflow:hidden, so nothing
   here mutates styles or layout. Mobile-scoped like the CSS; desktop and
   reduced-motion users never see a ripple.
   ════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var MOBILE_MQ  = window.matchMedia('(max-width:767.98px), (pointer:coarse) and (max-height:500px)');
    var NO_MOTION  = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* Must stay in sync with the ripple-host list in portal-md.css */
    var HOSTS = '.btn:not(.btn-close), .qa-btn, .mydtr-btn, .lv-dur-btn, ' +
                '.more-item, .na-item, .tab-btn, .faq-q, .cs-trigger, .cs-opt, ' +
                '.ptop-icbtn, .ptop-logout, .att-range-picker, .swal2-styled, ' +
                '.holm-card, .psm-card, .attm-card, .areq-card, .mydtr-card, ' +
                '.help-card, .ins-box, .ytd-box, .loan-c';

    document.addEventListener('pointerdown', function (e) {
        if (!MOBILE_MQ.matches || NO_MOTION.matches) return;
        if (e.button !== 0 && e.pointerType === 'mouse') return;   /* primary press only */
        var host = e.target.closest ? e.target.closest(HOSTS) : null;
        if (!host || host.disabled) return;

        var r = host.getBoundingClientRect();
        /* diameter covers the far corner from the touch point */
        var d = Math.max(r.width, r.height) * 2.1;
        var span = document.createElement('span');
        span.className = 'md-ripple';
        span.style.width = span.style.height = d + 'px';
        span.style.left = (e.clientX - r.left - d / 2) + 'px';
        span.style.top  = (e.clientY - r.top  - d / 2) + 'px';
        host.appendChild(span);
        span.addEventListener('animationend', function () { span.remove(); });
        /* safety net if the animation never fires (display:none mid-press) */
        setTimeout(function () { if (span.parentNode) span.remove(); }, 900);
    }, { passive: true });

    /* ── More sheet: let the tab's ripple play before the sheet slides up ──
       openMore() is defined earlier in the page (portal-md loads last), and
       opening the sheet instantly hid the press feedback under the backdrop. */
    var _openMore = window.openMore;
    if (typeof _openMore === 'function') {
        window.openMore = function () {
            if (!MOBILE_MQ.matches || NO_MOTION.matches) return _openMore();
            setTimeout(_openMore, 180);
        };
    }
})();
