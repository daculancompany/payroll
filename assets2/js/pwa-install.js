/* ──────────────────────────────────────────────────────────────────────────
 * pwa-install.js — the in-app "Install App" invitation.
 *
 * Chrome/Edge only surface installation in a browser menu users never open,
 * and iOS Safari never offers it at all (Share → Add to Home Screen is the
 * only path there). So the app invites the user itself, with a real modal
 * that says what installing gives them — not just an icon to guess at:
 *
 *   • Android / desktop Chromium — captures beforeinstallprompt, then shows an
 *     install modal whose button calls prompt() inside the click gesture.
 *   • iOS / iPadOS              — same modal, but with the Add-to-Home-Screen
 *     steps, since there is no prompt to call.
 *   • Already installed         — nothing is shown.
 *
 * The modal appears on its own a few seconds after load, and "Not now" is
 * remembered for DISMISS_DAYS so it never becomes a nag. Any element marked
 * [data-pwa-install] also opens it on demand, ignoring that suppression.
 *
 * When no prompt arrived and the platform isn't iOS, the modal instead names
 * the failed requirement (insecure origin, no service worker, unreachable
 * manifest, already installed …). That is deliberate: installability has to be
 * debugged on the actual phone / live host, where DevTools isn't available.
 * window.pwaDiag() returns the same report for the console.
 *
 * Loaded plain (not deferred) and early, because beforeinstallprompt fires
 * once and is not replayed for listeners that attach late.
 * ────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    var deferredPrompt = null;   // the captured BeforeInstallPromptEvent
    var promptFired = false;
    var swError = null;
    var manifestPromise = null;
    var modalShown = false;

    var DISMISS_DAYS = 7;        // how long "Not now" silences the auto-modal
    var AUTO_DELAY_MS = 2500;    // let the page settle before interrupting
    var BRAND = '#6642aa';

    // ── platform / state probes ───────────────────────────────────────────
    function isIOS() {
        var ua = navigator.userAgent || '';
        // iPadOS 13+ reports as Macintosh, so fall back to touch-capable Mac.
        return /iPad|iPhone|iPod/.test(ua) ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function isInstalled() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
            (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) ||
            navigator.standalone === true;
    }

    function manifestLink() {
        return document.querySelector('link[rel~="manifest"]');
    }

    function triggers() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]'));
    }

    function showTriggers() {
        triggers().forEach(function (el) {
            el.style.display = el.getAttribute('data-pwa-display') || '';
        });
    }

    function hideTriggers() {
        triggers().forEach(function (el) { el.style.display = 'none'; });
    }

    // ── "Not now" memory ──────────────────────────────────────────────────
    // Keyed by manifest URL so the admin app and the employee portal are
    // dismissed independently. Private-mode localStorage throws; treat any
    // failure as "not dismissed" rather than breaking the invitation.
    function dismissKey() {
        var l = manifestLink();
        return 'pwaInstallDismissed:' + (l ? l.href : location.pathname);
    }

    function isDismissed() {
        try {
            var ts = parseInt(localStorage.getItem(dismissKey()), 10);
            return !!ts && (Date.now() - ts) < DISMISS_DAYS * 86400000;
        } catch (e) { return false; }
    }

    function rememberDismissed() {
        try { localStorage.setItem(dismissKey(), String(Date.now())); } catch (e) { }
    }

    // ── manifest details, so the modal names the right app ────────────────
    function getManifest() {
        if (manifestPromise) return manifestPromise;
        var l = manifestLink();
        if (!l) return (manifestPromise = Promise.resolve({}));
        manifestPromise = fetch(l.href, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (m) {
                var icon = (m.icons || []).filter(function (i) {
                    return (i.purpose || 'any').indexOf('any') !== -1;
                })[0] || (m.icons || [])[0];
                return {
                    name: m.name || m.short_name || 'this app',
                    description: m.description || '',
                    icon: icon ? new URL(icon.src, l.href).href : null
                };
            })
            .catch(function () { return {}; });
        return manifestPromise;
    }

    // ── diagnostics ───────────────────────────────────────────────────────
    // Everything a browser checks before offering an install, gathered so a
    // failure can be read off the screen of the device that's failing.
    function collectDiag() {
        var link = manifestLink();
        return {
            url: location.href,
            protocol: location.protocol,
            secureContext: window.isSecureContext === true,
            installed: isInstalled(),
            ios: isIOS(),
            displayMode: (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                ? 'standalone' : 'browser',
            manifestLink: link ? link.href : null,
            manifestInHead: link ? !!link.closest('head') : false,
            serviceWorkerSupported: 'serviceWorker' in navigator,
            serviceWorkerError: swError,
            beforeInstallPromptFired: promptFired
        };
    }

    // Async extras (SW registration + manifest fetch) collectDiag can't do
    // synchronously. Resolves to the full report.
    function fullDiag() {
        var d = collectDiag();
        var jobs = [];

        if ('serviceWorker' in navigator) {
            jobs.push(navigator.serviceWorker.getRegistrations().then(function (regs) {
                d.serviceWorkers = regs.map(function (r) {
                    return {
                        scope: r.scope,
                        state: r.active ? 'active' : (r.installing ? 'installing' : (r.waiting ? 'waiting' : 'none')),
                        script: r.active && r.active.scriptURL
                    };
                });
                d.serviceWorkerActive = regs.some(function (r) { return !!r.active; });
            }).catch(function (e) { d.serviceWorkers = 'error: ' + e.message; }));
        }

        if (d.manifestLink) {
            jobs.push(fetch(d.manifestLink, { credentials: 'same-origin' }).then(function (r) {
                d.manifestStatus = r.status;
                d.manifestContentType = r.headers.get('content-type');
                return r.text();
            }).then(function (t) {
                try {
                    var m = JSON.parse(t);
                    d.manifestParsed = true;
                    d.manifestName = m.name || m.short_name;
                    d.manifestDisplay = m.display;
                    d.manifestStartUrl = m.start_url;
                    d.manifestIcons = (m.icons || []).map(function (i) { return i.sizes; }).join(' ');
                } catch (e) {
                    d.manifestParsed = false;
                    d.manifestParseError = e.message;
                }
            }).catch(function (e) { d.manifestStatus = 'fetch failed: ' + e.message; }));
        }

        if (navigator.getInstalledRelatedApps) {
            jobs.push(navigator.getInstalledRelatedApps().then(function (apps) {
                d.relatedApps = apps.length;
            }).catch(function () { }));
        }

        return Promise.all(jobs).then(function () { return d; });
    }

    // Turns the report into the single most likely reason install isn't offered.
    function blockingReason(d) {
        if (d.installed) return 'This app is already installed — open it from your home screen or app list.';
        if (!d.secureContext) {
            return 'This page is not on a secure origin (' + d.protocol + '//). Browsers only allow ' +
                'installing over https:// or on localhost, so no install option can appear here. ' +
                'Open the app over https:// instead.';
        }
        if (!d.manifestLink) return 'This page has no <link rel="manifest">, so the browser has no app to install.';
        if (d.manifestStatus && d.manifestStatus !== 200) return 'The manifest could not be loaded (' + d.manifestStatus + ').';
        if (d.manifestParsed === false) return 'The manifest is not valid JSON: ' + d.manifestParseError;
        if (!d.serviceWorkerSupported) return 'This browser has no service worker support.';
        if (d.serviceWorkerError) return 'The service worker failed to register: ' + d.serviceWorkerError;
        if (!d.serviceWorkerActive) {
            return 'The service worker has not activated yet. Reload the page once and try again — ' +
                'the browser needs an active worker before it offers to install.';
        }
        if (d.beforeInstallPromptFired) {
            return 'This app is installable and the browser did offer an install prompt. ' +
                'If you dismissed it, reload the page and tap Install App again.';
        }
        // Everything a browser checks passed, yet no prompt arrived. Overwhelmingly
        // this means the app is already installed: Chrome then swaps the install
        // icon for an "Open in app" pill and never fires beforeinstallprompt again,
        // which reads to users as "install stopped working".
        return 'Everything needed to install is in place, but the browser offered no prompt — ' +
            'which almost always means this app is ALREADY INSTALLED. Look for an ' +
            '"Open in app" button in the address bar, or check chrome://apps. ' +
            'To install it fresh, uninstall the existing copy first (open the installed app → ⋮ → ' +
            'Uninstall, or right-click it in chrome://apps → Remove), then reload this page. ' +
            'Note that Firefox and Chrome on iPhone cannot install apps at all.';
    }

    // ── the modal ─────────────────────────────────────────────────────────
    // Self-contained inline styling: this file is shared by the Bootstrap admin
    // pages and the hand-rolled portal, so it can't depend on either's CSS.
    // opts: {icon, title, body, primary, onPrimary, secondary, onSecondary}
    function modal(opts) {
        var old = document.getElementById('pwa-install-modal');
        if (old) old.remove();

        var wrap = document.createElement('div');
        wrap.id = 'pwa-install-modal';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', 'true');
        wrap.setAttribute('aria-label', 'Install app');
        wrap.style.cssText = 'position:fixed;inset:0;z-index:20000;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(24,20,38,.55);padding:16px;' +
            'font-family:\'Segoe UI\',Arial,sans-serif;-webkit-tap-highlight-color:transparent;' +
            'animation:pwaFade .18s ease-out;';

        var iconHtml = opts.icon
            ? '<img src="' + opts.icon + '" alt="" style="width:58px;height:58px;border-radius:13px;' +
              'flex:0 0 auto;box-shadow:0 2px 10px rgba(0,0,0,.16);">'
            : '';

        var buttons =
            '<button type="button" id="pwa-modal-primary" style="width:100%;border:0;border-radius:10px;' +
            'background:' + BRAND + ';color:#fff;font-size:14px;font-weight:700;padding:12px;' +
            'cursor:pointer;">' + opts.primary + '</button>' +
            (opts.secondary
                ? '<button type="button" id="pwa-modal-secondary" style="width:100%;margin-top:8px;' +
                  'border:1px solid #ded9e8;border-radius:10px;background:#fff;color:#5c5570;' +
                  'font-size:13px;font-weight:600;padding:10px;cursor:pointer;">' + opts.secondary + '</button>'
                : '');

        wrap.innerHTML =
            '<style>@keyframes pwaFade{from{opacity:0}to{opacity:1}}' +
            '@keyframes pwaPop{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}' +
            '#pwa-install-modal .pwa-card::-webkit-scrollbar{width:0}</style>' +
            '<div class="pwa-card" style="background:#fff;width:100%;max-width:420px;border-radius:16px;' +
            'padding:22px;color:#312f38;box-shadow:0 18px 50px rgba(0,0,0,.3);max-height:88vh;' +
            'overflow:auto;animation:pwaPop .22s ease-out;">' +
            '<div style="display:flex;gap:14px;align-items:center;margin-bottom:14px;">' +
            iconHtml +
            '<div><div style="font-size:17px;font-weight:700;line-height:1.25;">' + opts.title + '</div>' +
            (opts.subtitle ? '<div style="font-size:12px;color:#6b6577;margin-top:3px;">' + opts.subtitle + '</div>' : '') +
            '</div></div>' +
            '<div style="font-size:13px;line-height:1.6;">' + opts.body + '</div>' +
            '<div style="margin-top:18px;">' + buttons + '</div>' +
            '</div>';

        document.body.appendChild(wrap);

        function close() { wrap.remove(); }
        wrap.querySelector('#pwa-modal-primary').addEventListener('click', function () {
            if (opts.onPrimary) opts.onPrimary(close); else close();
        });
        var sec = wrap.querySelector('#pwa-modal-secondary');
        if (sec) sec.addEventListener('click', function () {
            if (opts.onSecondary) opts.onSecondary(close); else close();
        });
        // Tapping the backdrop counts as "not now" — same as declining.
        wrap.addEventListener('click', function (e) {
            if (e.target !== wrap) return;
            if (opts.onSecondary) opts.onSecondary(close); else close();
        });
        return close;
    }

    function benefitList(items) {
        return '<ul style="margin:0;padding:0;list-style:none;">' + items.map(function (t) {
            return '<li style="display:flex;gap:9px;margin-bottom:9px;">' +
                '<span style="color:' + BRAND + ';font-weight:700;flex:0 0 auto;">✓</span>' +
                '<span>' + t + '</span></li>';
        }).join('') + '</ul>';
    }

    // The Chromium invitation: a real explanation plus a button that calls
    // prompt() inside the click, which is the only place it's allowed.
    function installModal(m) {
        modal({
            icon: m.icon,
            title: 'Install ' + (m.name || 'this app'),
            subtitle: m.description || '',
            body: '<p style="margin:0 0 12px;">Add it to your device and it runs like a normal app:</p>' +
                benefitList([
                    'Its own icon on your home screen or desktop — no typing the address.',
                    'Opens full screen, without the browser bars.',
                    'Receives notifications for approvals and payslips even when closed.'
                ]),
            primary: 'Install',
            secondary: 'Not now',
            onPrimary: function (close) { doInstall(close); },
            onSecondary: function (close) { rememberDismissed(); close(); }
        });
    }

    // iOS has no prompt to call, so the modal carries the manual steps.
    function iosModal(m) {
        modal({
            icon: m.icon,
            title: 'Add ' + (m.name || 'this app') + ' to your Home Screen',
            subtitle: m.description || '',
            body: '<p style="margin:0 0 10px;">iPhone and iPad can install this app, but only from ' +
                'Safari\'s Share menu — there is no automatic prompt.</p>' +
                '<ol style="margin:0;padding-left:20px;">' +
                '<li style="margin-bottom:7px;">Tap the <strong>Share</strong> button (the square with an ' +
                'arrow pointing up) in Safari\'s toolbar.</li>' +
                '<li style="margin-bottom:7px;">Scroll down and tap <strong>Add to Home Screen</strong>.</li>' +
                '<li>Tap <strong>Add</strong> — the icon appears on your home screen and opens without ' +
                'the browser bars.</li>' +
                '</ol>' +
                '<p style="margin:12px 0 0;color:#6b6577;">Using Chrome on iPhone? Open this page in ' +
                'Safari first — Add to Home Screen is most reliable there.</p>',
            primary: 'Got it',
            secondary: 'Don\'t show again',
            onSecondary: function (close) { rememberDismissed(); close(); }
        });
    }

    // Per-browser manual route, for when there's no prompt to call. Users on the
    // already-installed admin app land here, so it has to be instructions rather
    // than a dead end.
    function manualSteps() {
        var ua = navigator.userAgent || '';
        var android = /Android/.test(ua);
        var edge = /Edg\//.test(ua);
        var chromium = /Chrome\//.test(ua) || edge;
        var firefox = /Firefox\//.test(ua);

        if (firefox) {
            return '<p style="margin:0;">Firefox cannot install web apps. Open this page in ' +
                '<strong>Chrome</strong> or <strong>Edge</strong> and the Install button will work.</p>';
        }
        if (android && chromium) {
            return '<ol style="margin:0;padding-left:20px;">' +
                '<li style="margin-bottom:6px;">Tap the <strong>⋮</strong> menu (top-right of Chrome).</li>' +
                '<li style="margin-bottom:6px;">Tap <strong>Install app</strong>, or ' +
                '<strong>Add to Home screen</strong>.</li>' +
                '<li>Confirm with <strong>Install</strong>.</li></ol>';
        }
        if (edge) {
            return '<ol style="margin:0;padding-left:20px;">' +
                '<li style="margin-bottom:6px;">Click the <strong>⋯</strong> menu (top-right of Edge).</li>' +
                '<li style="margin-bottom:6px;">Choose <strong>Apps</strong> → ' +
                '<strong>Install this site as an app</strong>.</li>' +
                '<li>Confirm with <strong>Install</strong>.</li></ol>';
        }
        if (chromium) {
            return '<ol style="margin:0;padding-left:20px;">' +
                '<li style="margin-bottom:6px;">Look for the <strong>install icon</strong> ' +
                '(a monitor with a down-arrow) at the right of the address bar and click it.</li>' +
                '<li style="margin-bottom:6px;">Or click the <strong>⋮</strong> menu → ' +
                '<strong>Cast, save and share</strong> → <strong>Install page as app…</strong></li>' +
                '<li>Confirm with <strong>Install</strong>.</li></ol>';
        }
        return '<p style="margin:0;">Open this page in <strong>Chrome</strong> or <strong>Edge</strong>, ' +
            'then use the browser menu\'s <strong>Install</strong> option.</p>';
    }

    // Nothing to prompt with and not iOS. Still leads with what installing gives
    // you and how to do it by hand; the requirement check is tucked underneath.
    function manualModal(d, m) {
        var installed = d.installed || (!d.beforeInstallPromptFired && d.secureContext &&
            d.serviceWorkerActive && d.manifestStatus === 200);

        var body = '<p style="margin:0 0 12px;">' + (installed
            ? 'That means it runs like a normal app:'
            : 'Add it to your device and it runs like a normal app:') + '</p>' +
            benefitList([
                'Its own icon on your home screen or desktop — no typing the address.',
                'Opens full screen, without the browser bars.',
                'Receives notifications for approvals and payslips even when closed.'
            ]);

        if (installed) {
            body += '<div style="background:#f1eefa;border:1px solid #ded4f2;border-radius:10px;' +
                'padding:11px 13px;margin:14px 0 0;">' +
                '<div style="font-weight:700;margin-bottom:5px;">It looks like it\'s already installed</div>' +
                'The browser is offering no install prompt, which almost always means this app is ' +
                'installed already. Look for an <strong>“Open in app”</strong> button in the address bar, ' +
                'or find it in <strong>chrome://apps</strong>.' +
                '<div style="margin-top:8px;color:#6b6577;">To install a fresh copy, remove the old one ' +
                'first: open the installed app → <strong>⋮</strong> → <strong>Uninstall</strong> ' +
                '(or right-click it in chrome://apps → <strong>Remove</strong>), then reload this page.</div>' +
                '</div>';
        } else {
            body += '<div style="margin-top:14px;"><div style="font-weight:700;margin-bottom:7px;">' +
                'How to install</div>' + manualSteps() + '</div>';
            if (!d.secureContext) {
                body += '<div style="background:#fdecea;border:1px solid #f5c6c0;border-radius:10px;' +
                    'padding:11px 13px;margin-top:14px;color:#a2321f;">' + blockingReason(d) + '</div>';
            }
        }

        body += '<details style="margin-top:14px;"><summary style="cursor:pointer;color:#6b6577;' +
            'font-size:12px;">Technical details</summary>' + diagTable(d) + '</details>';

        modal({
            icon: m.icon,
            title: installed ? (m.name || 'This app') + ' is already installed'
                : 'Install ' + (m.name || 'this app'),
            subtitle: m.description || '',
            body: body,
            primary: 'Got it'
        });
    }

    function diagTable(d) {
        var rows = '';
        [
            ['Secure origin (https/localhost)', d.secureContext ? 'yes' : 'NO — ' + d.protocol],
            ['Manifest', d.manifestLink ? (d.manifestStatus === 200 ? 'loaded' : 'status ' + d.manifestStatus) : 'MISSING'],
            ['Manifest valid JSON', d.manifestParsed === false ? 'NO' : (d.manifestParsed ? 'yes' : '—')],
            ['Manifest display', d.manifestDisplay || '—'],
            ['Manifest icons', d.manifestIcons || '—'],
            ['Service worker', d.serviceWorkerActive ? 'active' : (d.serviceWorkerError ? 'error' : 'not active')],
            ['Install prompt offered', d.beforeInstallPromptFired ? 'yes' : 'no'],
            // Only detectable from inside the installed window — a browser tab of an
            // already-installed app still reports "no", so don't call it "installed".
            ['Running as installed app', d.installed ? 'yes' : 'no']
        ].forEach(function (r) {
            var bad = /^(NO|MISSING|no$|error|not active|status)/.test(String(r[1]));
            rows += '<tr><td style="padding:4px 8px 4px 0;color:#6b6577;">' + r[0] + '</td>' +
                '<td style="padding:4px 0;font-weight:700;color:' + (bad ? '#c0392b' : '#1e7d5a') + ';">' +
                r[1] + '</td></tr>';
        });
        return '<p style="margin:8px 0;color:#6b6577;font-size:12px;">' + blockingReason(d) + '</p>' +
            '<table style="width:100%;border-collapse:collapse;font-size:12px;">' + rows + '</table>';
    }

    // Gathers both async inputs the manual modal needs.
    function showManualModal() {
        Promise.all([fullDiag(), getManifest()]).then(function (r) {
            manualModal(r[0], r[1]);
        });
    }

    // ── performing the install ────────────────────────────────────────────
    function doInstall(close) {
        if (!deferredPrompt) { close(); showManualModal(); return; }

        var p = deferredPrompt;
        deferredPrompt = null;             // a prompt may only be used once
        var shown;
        try {
            shown = p.prompt();
        } catch (err) {
            // prompt() requires a real user gesture and throws without one.
            close();
            showManualModal();
            return;
        }
        close();
        // Newer Chrome resolves prompt() with the choice; older only exposes
        // userChoice. Prefer whichever this browser gives us.
        Promise.resolve(shown)
            .then(function (choice) { return choice || p.userChoice; })
            .then(function (choice) {
                if (choice && choice.outcome === 'accepted') hideTriggers();
                else rememberDismissed();  // declined at the browser's own dialog
            })
            .catch(function () { showManualModal(); });
    }

    // ── entry points ──────────────────────────────────────────────────────
    // manual = user asked for it, so ignore the "not now" memory.
    function openInstallUI(manual) {
        if (isInstalled()) return;
        if (!manual && (modalShown || isDismissed())) return;
        modalShown = true;

        if (deferredPrompt) { getManifest().then(installModal); return; }
        if (isIOS()) { getManifest().then(iosModal); return; }
        if (manual) showManualModal();
    }

    function onClick(e) {
        e.preventDefault();
        openInstallUI(true);
    }

    // ── wiring ────────────────────────────────────────────────────────────
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();                    // suppress Chrome's own mini-infobar
        deferredPrompt = e;
        promptFired = true;
        if (isInstalled()) return;
        showTriggers();
        setTimeout(function () { openInstallUI(false); }, AUTO_DELAY_MS);
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        hideTriggers();
        var open = document.getElementById('pwa-install-modal');
        if (open) open.remove();
    });

    // Records why registration failed so the diagnostic modal can say so. The
    // pages register the worker themselves; this only listens for the failure.
    window.pwaNoteSwError = function (msg) { swError = String(msg); };

    document.addEventListener('DOMContentLoaded', function () {
        triggers().forEach(function (el) { el.addEventListener('click', onClick); });

        if (isInstalled()) { hideTriggers(); return; }

        // iOS never fires beforeinstallprompt, so both the trigger and the
        // invitation have to be driven from here.
        if (isIOS()) {
            showTriggers();
            setTimeout(function () { openInstallUI(false); }, AUTO_DELAY_MS);
            return;
        }

        // On a non-secure origin nothing can be installed; surfacing the trigger
        // anyway is what lets the user see *why* rather than find nothing.
        if (!window.isSecureContext) { showTriggers(); return; }

        // Chromium fires beforeinstallprompt shortly after load. If it hasn't by
        // then the app is likely already installed or the browser can't install;
        // show the trigger so the diagnostic stays reachable either way.
        setTimeout(function () {
            if (!promptFired && !isInstalled()) showTriggers();
        }, 3000);
    });

    // Console/manual helpers.
    window.pwaInstall = function () { openInstallUI(true); };
    window.pwaInstallReset = function () {
        try { localStorage.removeItem(dismissKey()); } catch (e) { }
        modalShown = false;
        return 'Install invitation re-armed for this app.';
    };
    window.pwaDiag = function () {
        return fullDiag().then(function (d) {
            console.log('[PWA] diagnostics', d);
            console.log('[PWA] verdict:', blockingReason(d));
            return d;
        });
    };
})();
