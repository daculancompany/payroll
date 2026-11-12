/* v2 router: SPA-style navigation over the existing PHP page fragments.
 *
 *   click on a link to a routed page  → fetch app/<page> with X-App-Nav
 *   server returns {html, scripts}     → tear down old page, swap #main-content,
 *                                        run its scripts, push history
 *
 * The page fragments and scripts were written for full page loads, so the
 * router gives every page a lifecycle they don't know about:
 *
 *  - Scripts are executed by the router, not the parser. Top-level `let` /
 *    `const` / `class` are rewritten to `var` so a page can be visited twice
 *    without "Identifier already declared". Functions stay global, so inline
 *    onclick="…" handlers keep working.
 *  - DOMContentLoaded / window load listeners added by a page are queued and
 *    fired after its scripts have run, as on a real load.
 *  - Listeners a page adds to document / window / body (jQuery or native) and
 *    its setInterval timers are recorded and removed on teardown, so handlers
 *    don't stack up across visits.
 *  - DataTables, open modals, backdrops and body-level popups are cleaned up.
 *
 * Pages not yet checked for this (outside V2.spaSafe) get a normal full load
 * inside the v2 shell. Authorization is entirely server-side: the partial
 * endpoint runs the same page_allowed() gate as a full load.
 */
(function () {
    'use strict';

    var V2 = window.V2 || {};
    var BASE = new URL(V2.base || '/', location.origin);
    var main = document.getElementById('main-content');
    var progress = document.getElementById('v2-progress');
    var ROUTES = V2.routes || [];
    var SAFE = V2.spaSafe || [];
    var navToken = 0;

    /* ── page phase tracking ────────────────────────────────────────── */
    var phase = { active: false, listeners: [], jq: [], intervals: [], domReady: [], load: [] };
    var GLOBAL_TARGETS = [document, window, document.body, document.documentElement];

    function isGlobal(t) { return GLOBAL_TARGETS.indexOf(t) !== -1; }
    function isJqHandle(target, fn) {
        if (!window.jQuery || !jQuery._data) return false;
        var d = jQuery._data(target);
        return !!(d && d.handle === fn);
    }

    var nativeAdd = EventTarget.prototype.addEventListener;
    EventTarget.prototype.addEventListener = function (type, fn, opts) {
        if (phase.active && isGlobal(this) && typeof fn === 'function') {
            // A page's ready/load hooks: run them after its scripts, like a real load.
            if (phase.collecting && type === 'DOMContentLoaded' && this === document) { phase.domReady.push(fn); return; }
            if (phase.collecting && type === 'load' && this === window) { phase.load.push(fn); return; }
            // jQuery binds one native handle per element+type; it is shared
            // with the shell's own jQuery handlers, so never remove it.
            if (!isJqHandle(this, fn)) phase.listeners.push([this, type, fn, opts]);
        }
        return nativeAdd.call(this, type, fn, opts);
    };

    var nativeSetInterval = window.setInterval;
    window.setInterval = function () {
        var id = nativeSetInterval.apply(window, arguments);
        if (phase.active) phase.intervals.push(id);
        return id;
    };

    function patchJquery() {
        if (!window.jQuery || jQuery.fn.__v2patched) return;
        ['on', 'one'].forEach(function (m) {
            var orig = jQuery.fn[m];
            jQuery.fn[m] = function () {
                if (phase.active) {
                    for (var i = 0; i < this.length; i++) {
                        if (isGlobal(this[i])) { phase.jq.push([this[i], Array.prototype.slice.call(arguments)]); break; }
                    }
                }
                return orig.apply(this, arguments);
            };
        });
        jQuery.fn.__v2patched = true;
    }
    patchJquery();

    function teardown() {
        try { if (window.AppPage && typeof window.AppPage.destroy === 'function') window.AppPage.destroy(); } catch (e) { console.warn('[v2] AppPage.destroy', e); }
        window.AppPage = null;

        if (window.jQuery) {
            var $ = jQuery;
            try { if ($.fn.dataTable) $.fn.dataTable.tables({ api: true }).destroy(); } catch (e) { /* table already gone */ }
            phase.jq.forEach(function (rec) {
                var args = rec[1], sel = typeof args[1] === 'string' ? args[1] : undefined;
                var fn = null;
                for (var i = args.length - 1; i >= 0; i--) if (typeof args[i] === 'function') { fn = args[i]; break; }
                try {
                    if (typeof args[0] === 'object') $(rec[0]).off(args[0], sel);
                    else if (fn) $(rec[0]).off(args[0], sel, fn);
                } catch (e) { /* ignore */ }
            });
        }
        phase.listeners.forEach(function (l) { try { l[0].removeEventListener(l[1], l[2], l[3]); } catch (e) { /* ignore */ } });
        phase.intervals.forEach(function (id) { clearInterval(id); });
        phase.listeners = []; phase.jq = []; phase.intervals = [];

        // Modals and popups that live on <body>.
        if (window.bootstrap) {
            main.querySelectorAll('.modal.show').forEach(function (m) {
                var inst = bootstrap.Modal.getInstance(m); if (inst) inst.hide();
            });
        }
        document.querySelectorAll('.modal-backdrop, .daterangepicker, .bootstrap-datetimepicker-widget, body > .tooltip, body > .popover, body > .dropdown-menu.bs-select')
            .forEach(function (n) { n.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        if (window.Swal && Swal.isVisible && Swal.isVisible()) Swal.close();
    }

    /* ── script execution ───────────────────────────────────────────── */
    var srcCache = {};

    /** Rewrite top-level let/const/class to var so the script can re-run. */
    function reDeclarable(code) {
        var lines = code.split('\n');
        var indent = Infinity;
        lines.forEach(function (l) {
            if (!l.trim() || /^\s*(\/\/|\*|\/\*)/.test(l)) return;
            var m = l.match(/^[ \t]*/)[0].length;
            if (m < indent) indent = m;
        });
        if (!isFinite(indent)) return code;
        var re = new RegExp('^([ \\t]{' + indent + '})(?:let|const)(\\s)');
        var reClass = new RegExp('^([ \\t]{' + indent + '})class\\s+([A-Za-z_$][\\w$]*)');
        return lines.map(function (l) {
            return l.replace(re, '$1var$2').replace(reClass, '$1var $2 = class $2');
        }).join('\n');
    }

    function inject(code, label) {
        var s = document.createElement('script');
        s.textContent = reDeclarable(code) + '\n//# sourceURL=' + label;
        document.body.appendChild(s);
        s.remove();
    }

    function sameOrigin(url) {
        try { return new URL(url, BASE).origin === location.origin; } catch (e) { return false; }
    }

    function loadExternal(url) {
        if (window.jQuery && /\/jquery(?:-\d[\d.]*)?(?:\.min)?\.js(?:[?#]|$)/i.test(url)) return Promise.resolve();   // see runScript
        return new Promise(function (resolve) {
            var s = document.createElement('script');
            s.src = url;
            s.onload = s.onerror = function () { resolve(); };
            document.body.appendChild(s);
        });
    }

    function runScript(item) {
        if (item.inline != null) {
            try { inject(item.inline, 'v2-inline/' + (V2.page || 'page') + '.js'); } catch (e) { console.error('[v2] inline script', e); }
            return Promise.resolve();
        }
        var url = new URL(item.src, BASE).href;
        if (!sameOrigin(url)) return loadExternal(url);
        // A second jQuery would replace the one the shell's plugins (DataTables,
        // bootstrap-select, the select2 shim) are attached to. Some fragments
        // include their own copy because they used to run before the footer.
        if (window.jQuery && /\/jquery(?:-\d[\d.]*)?(?:\.min)?\.js(?:[?#]|$)/i.test(url)) return Promise.resolve();
        var p = srcCache[url] || fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) { console.warn('[v2] script ' + url + ' → HTTP ' + r.status + ', skipped'); return null; }
            return r.text();
        });
        srcCache[url] = p;
        return p.then(function (code) {
            if (code == null) return;
            try { inject(code, url); } catch (e) { console.error('[v2] script ' + url, e); }
        });
    }

    function runAll(items) {
        return items.reduce(function (p, it) { return p.then(function () { return runScript(it); }); }, Promise.resolve());
    }

    /** Server marks fragment scripts type="text/v2-deferred"; run them in order. */
    function deferredIn(container) {
        return Array.prototype.map.call(container.querySelectorAll('script[type="text/v2-deferred"]'), function (s) {
            var src = s.getAttribute('src');
            var item = src ? { src: src } : { inline: s.textContent };
            s.remove();
            return item;
        });
    }

    /** Start a page's lifecycle: run fragment scripts, page scripts, ready hooks. */
    function mount(scripts) {
        patchJquery();
        phase.active = true;
        phase.collecting = true;
        phase.domReady = []; phase.load = [];
        var items = deferredIn(main).concat((scripts || []).map(function (s) { return { src: s }; }));
        return runAll(items).then(function () {
            phase.collecting = false;
            var evt = new Event('DOMContentLoaded');
            phase.domReady.forEach(function (fn) { try { fn.call(document, evt); } catch (e) { console.error(e); } });
            var le = new Event('load');
            phase.load.forEach(function (fn) { try { fn.call(window, le); } catch (e) { console.error(e); } });
            if (window.AppPage && typeof window.AppPage.init === 'function') {
                try { window.AppPage.init(main); } catch (e) { console.error('[v2] AppPage.init', e); }
            }
            V2.navs = (V2.navs || 0) + 1;   // completed page mounts (pollable; listeners added by a page are removed on teardown)
            window.dispatchEvent(new CustomEvent('v2:navigated', { detail: { page: V2.page } }));
        });
    }

    /* ── URL mapping ────────────────────────────────────────────────── */
    /** Map any link that points at a routed page (classic or v2 style) to app/<page>. */
    function toAppUrl(href) {
        var u;
        // Relative paths resolve like links do: against <base href>.
        try { u = new URL(href, document.baseURI); } catch (e) { return null; }
        if (u.origin !== location.origin) return null;
        var rel = u.pathname.indexOf(BASE.pathname) === 0 ? u.pathname.slice(BASE.pathname.length) : null;
        if (rel === null) return null;
        var page = null, params = new URLSearchParams(u.search);

        if (rel === 'index.php' || rel === '') {
            page = params.get('page') || (rel === 'index.php' ? 'home' : null);
            params.delete('page');
        } else if (rel === 'app' || rel === 'app/') {
            page = V2.landing || 'home';
        } else {
            var m = rel.match(/^(?:app\/)?([A-Za-z0-9_-]+)\/?$/);
            if (m) page = m[1];
        }
        if (page === 'payroll-list') page = 'payroll';
        if (!page || ROUTES.indexOf(page) === -1) return null;
        var qs = params.toString();
        return { page: page, url: BASE.pathname + 'app/' + page + (qs ? '?' + qs : '') + u.hash };
    }

    /* ── navigation ─────────────────────────────────────────────────── */
    function setProgress(on) {
        if (!progress) return;
        progress.classList.toggle('is-active', on);
        if (!on) { progress.classList.add('is-done'); setTimeout(function () { progress.classList.remove('is-done'); }, 300); }
    }

    function markActive(page) {
        document.querySelectorAll('.v2-nav__item[data-match]').forEach(function (a) {
            a.classList.toggle('is-active', a.dataset.match.split(' ').indexOf(page) !== -1);
        });
    }

    function navigate(target, opts) {
        opts = opts || {};
        if (SAFE.indexOf(target.page) === -1) { location.href = target.url; return; }

        var token = ++navToken;
        setProgress(true);
        fetch(target.url, { headers: { 'X-App-Nav': '1', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) { location.href = BASE.pathname + 'login.php'; throw new Error('auth'); }
                return r.json();
            })
            .then(function (data) {
                if (token !== navToken) return;              // a newer click won
                if (data.full) { location.href = BASE.pathname + data.full; return; }

                teardown();
                if (!opts.replay) history.pushState({ v2: true, url: target.url }, '', target.url);
                main.innerHTML = data.html;
                main.dataset.page = data.page;
                V2.page = data.page;
                document.title = data.title + ' · ' + (V2.brand || '');
                var crumb = document.getElementById('v2-crumb');
                if (crumb) {
                    crumb.textContent = '';
                    if (data.group) {
                        var g = document.createElement('small');
                        g.className = 'd-none d-md-inline';
                        g.textContent = data.group;
                        crumb.appendChild(g);
                    }
                    crumb.appendChild(document.createTextNode(data.title));
                }
                markActive(data.page);
                window.scrollTo(0, 0);
                main.focus({ preventScroll: true });
                return mount(data.scripts);
            })
            .catch(function (e) {
                if (e && e.message === 'auth') return;
                console.error('[v2] navigation failed, doing a full load', e);
                location.href = target.url;
            })
            .then(function () { if (token === navToken) setProgress(false); });
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest('a[href]');
        if (!a) return;
        var raw = a.getAttribute('href');
        if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(raw)) return;
        if (a.target && a.target !== '_self') return;
        if (a.hasAttribute('download') || a.hasAttribute('data-bs-toggle') || a.hasAttribute('data-no-nav')) return;
        var t = toAppUrl(a.href);
        if (!t) return;
        e.preventDefault();
        navigate(t);
    });

    window.addEventListener('popstate', function () {
        var t = toAppUrl(location.href);
        if (t) navigate(t, { replay: true });
    });

    // Scripts that do window.location = 'index.php?page=…' still land in the
    // classic shell; give pages a v2-aware helper instead.
    window.v2Go = function (href) {
        var t = toAppUrl(href);
        t ? navigate(t) : (location.href = href);
    };

    // Normalise the address bar on first load (app.php?page=x → app/x).
    var first = toAppUrl(location.href);
    if (first && location.pathname + location.search + location.hash !== first.url) {
        history.replaceState({ v2: true, url: first.url }, '', first.url);
    }

    // First page: run its scripts through the same lifecycle as navigations.
    mount(V2.initialScripts || []);
})();
