/* ════════════════════════════════════════════════════════════════════════
   COMC Employee Portal — native-app interactions (mobile)
   Loaded last so it can wrap the portal's global functions (switchTab,
   empRenderNotif). Adds:
     • per-screen header titles
     • instant (motionless) screen switching from the fixed bottom nav
     • swipe left/right to move between the primary bottom-nav screens
     • drag-to-dismiss on the More bottom sheet
     • swipe-to-dismiss on notifications (marks them read)
   Desktop behaviour is untouched: everything checks the mobile media query
   or touch capability first.
   ════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    /* Same condition as the CSS app layer: phones/small tablets in portrait,
       plus any touch device in short landscape (rotated phone). */
    var MOBILE_MQ = window.matchMedia('(max-width:767.98px), (pointer:coarse) and (max-height:500px)');
    var IS_TOUCH  = 'ontouchstart' in window;

    var TAB_TITLES = {
        overview: 'Home',            payslips: 'Payslips',
        attendance: 'Attendance',    leave: 'Leave',
        mydtr: 'My DTR',             'att-requests': 'Requests',
        compare: 'Compare',          loans: 'Loans',
        contrib: 'Contributions',    info: 'My Info',
        help: 'Help & FAQ'
    };
    /* Screens that live in the bottom nav, in visual order — swipe moves between these */
    var PRIMARY_TABS = ['overview', 'payslips', 'attendance', 'leave'];

    function currentTabId() {
        var p = document.querySelector('.tab-panel.active');
        return p ? p.id.replace(/^tab-/, '') : 'overview';
    }
    function setScreenTitle(id) {
        var el = document.getElementById('ptop-screen-title');
        if (!el) return;
        el.textContent = TAB_TITLES[id] || 'My Portal';
    }
    function buzz(ms) {
        if (navigator.vibrate) { try { navigator.vibrate(ms); } catch (e) { /* noop */ } }
    }

    /* ── switchTab wrapper: per-screen title + scroll-to-top only ─────────
       Tab switching is deliberately motionless: no slide, no fade, no haptic. */
    var _origSwitchTab = window.switchTab;
    if (typeof _origSwitchTab === 'function') {
        window.switchTab = function (id, btn) {
            var changed = currentTabId() !== id;
            _origSwitchTab(id, btn);
            setScreenTitle(id);
            if (MOBILE_MQ.matches && changed) {
                window.scrollTo(0, 0);           /* each screen opens at its top */
            }
        };
    }
    setScreenTitle(currentTabId());

    /* ── Swipe left/right anywhere on a primary screen → adjacent screen ── */
    (function () {
        if (!IS_TOUCH) return;
        /* Never hijack horizontal gestures that belong to scrollers / widgets */
        var IGNORE = '.table-responsive,.apexcharts-canvas,.daterangepicker,' +
                     '.bootstrap-datetimepicker-widget,.modal,.more-sheet,.emp-notif-panel,' +
                     'input,textarea,select,.tab-strip,.dropdown-menu,.emp-notif-item';
        var sx = 0, sy = 0, t0 = 0, tracking = false;

        document.addEventListener('touchstart', function (e) {
            tracking = false;
            if (!MOBILE_MQ.matches || e.touches.length !== 1) return;
            if (e.target.closest && e.target.closest(IGNORE)) return;
            if (document.querySelector('.modal.show, .more-sheet.open, .emp-notif-panel.open')) return;
            sx = e.touches[0].clientX;
            sy = e.touches[0].clientY;
            t0 = Date.now();
            tracking = true;
        }, { passive: true });

        document.addEventListener('touchend', function (e) {
            if (!tracking) return;
            tracking = false;
            var dx = e.changedTouches[0].clientX - sx;
            var dy = e.changedTouches[0].clientY - sy;
            var dt = Date.now() - t0;
            /* a deliberate, mostly-horizontal flick */
            if (dt > 600 || Math.abs(dx) < 70 || Math.abs(dy) > 70 ||
                Math.abs(dx) < Math.abs(dy) * 1.6) return;
            var cur = PRIMARY_TABS.indexOf(currentTabId());
            if (cur < 0) return;                       /* secondary screens: no swipe-nav */
            var next = cur + (dx < 0 ? 1 : -1);
            if (next < 0 || next >= PRIMARY_TABS.length) return;
            window.switchTab(PRIMARY_TABS[next], null);
        }, { passive: true });
    })();

    /* ── More bottom sheet: drag down to dismiss ─────────────────────── */
    (function () {
        if (!IS_TOUCH) return;
        var sheet = document.getElementById('more-sheet');
        if (!sheet) return;
        var startY = null, delta = 0, dragging = false;

        sheet.addEventListener('touchstart', function (e) {
            if (!sheet.classList.contains('open')) return;
            startY = e.touches[0].clientY;
            delta = 0;
            dragging = true;
            sheet.classList.add('dragging');
        }, { passive: true });

        sheet.addEventListener('touchmove', function (e) {
            if (!dragging || startY === null) return;
            delta = Math.max(0, e.touches[0].clientY - startY);
            sheet.style.transform = 'translateY(' + delta + 'px)';
        }, { passive: true });

        sheet.addEventListener('touchend', function () {
            if (!dragging) return;
            dragging = false;
            sheet.classList.remove('dragging');
            sheet.style.transform = '';
            if (delta > 90 && typeof window.closeMore === 'function') {
                buzz(8);
                window.closeMore();
            }
            startY = null;
        });
    })();

    /* ── Notifications: swipe an item sideways to dismiss (marks read) ── */
    (function () {
        if (!IS_TOUCH) return;
        var dismissed = {};   /* keep swiped-away ids out of subsequent re-renders */

        var _origRender = window.empRenderNotif;
        if (typeof _origRender === 'function') {
            window.empRenderNotif = function (data) {
                if (data && data.items) {
                    data.items = data.items.filter(function (n) { return !dismissed[n.id]; });
                }
                _origRender(data);
            };
        }

        var list = document.getElementById('emp-notif-list');
        if (!list) return;
        var item = null, sx = 0, sy = 0, dx = 0, swiping = false;

        list.addEventListener('touchstart', function (e) {
            item = e.target.closest ? e.target.closest('.emp-notif-item') : null;
            if (!item) return;
            sx = e.touches[0].clientX;
            sy = e.touches[0].clientY;
            dx = 0;
            swiping = false;
            item.style.transition = 'none';
        }, { passive: true });

        list.addEventListener('touchmove', function (e) {
            if (!item) return;
            dx = e.touches[0].clientX - sx;
            var dyAbs = Math.abs(e.touches[0].clientY - sy);
            if (!swiping && Math.abs(dx) > 12 && Math.abs(dx) > dyAbs) swiping = true;
            if (swiping) {
                item.style.transform = 'translateX(' + dx + 'px)';
                item.style.opacity = String(Math.max(.25, 1 - Math.abs(dx) / 220));
            }
        }, { passive: true });

        list.addEventListener('touchend', function () {
            if (!item) return;
            var el = item;
            item = null;
            el.style.transition = 'transform .22s ease, opacity .22s ease, max-height .25s ease .18s, padding .25s ease .18s, border-width .25s ease .18s';
            if (swiping && Math.abs(dx) > 90) {
                var id = el.dataset.id;
                dismissed[id] = 1;
                buzz(10);
                el.style.maxHeight = el.offsetHeight + 'px';
                el.style.overflow = 'hidden';
                el.style.transform = 'translateX(' + (dx > 0 ? '110%' : '-110%') + ')';
                el.style.opacity = '0';
                requestAnimationFrame(function () {
                    el.style.maxHeight = '0';
                    el.style.paddingTop = '0';
                    el.style.paddingBottom = '0';
                    el.style.borderWidth = '0';
                });
                setTimeout(function () {
                    el.remove();
                    var listEl = document.getElementById('emp-notif-list');
                    if (listEl && !listEl.querySelector('.emp-notif-item')) {
                        listEl.innerHTML = '<div class="emp-notif-empty">No notifications yet.</div>';
                    }
                }, 480);
                fetch('emp-portal-ajax.php?action=emp_mark_read', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + encodeURIComponent(id)
                }).catch(function () { /* offline: dismissal is still visual */ });
            } else {
                el.style.transform = '';
                el.style.opacity = '';
            }
            swiping = false;
        });
    })();

    /* ── Portrait lock ────────────────────────────────────────────────
       manifest.webmanifest declares "orientation": "portrait", but an app
       installed before that field was added keeps its old manifest until
       Chrome rebuilds the WebAPK, so ask the Screen Orientation API too.
       It only works from an installed (standalone/fullscreen) window and
       rejects everywhere else — including plain browser tabs, where taking
       over rotation would be hostile anyway. If it's refused, the CSS
       rotate guard in portal-mobile.css catches the landscape case. */
    (function () {
        var so = window.screen && window.screen.orientation;
        if (!so || typeof so.lock !== 'function') return;

        var installed = window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.navigator.standalone === true;
        if (!installed) return;

        try {
            var res = so.lock('portrait');
            /* Rejects on iOS and when the OS forbids it — swallow it, the
               guard overlay is the fallback. An unhandled rejection here
               would surface as a console error on every launch. */
            if (res && typeof res.catch === 'function') { res.catch(function () {}); }
        } catch (e) { /* unsupported */ }
    })();
})();
