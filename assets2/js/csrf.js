/**
 * CSRF token transport.
 *
 * The app talks to the server two ways — ~79 native fetch() calls and ~31
 * jQuery $.ajax/$.post calls — so BOTH transports are hooked here. Doing it
 * centrally means the ~110 call sites stay untouched, and anything added later
 * is covered automatically.
 *
 * Load this BEFORE any script that might issue a request, and after the
 * <meta name="csrf-token"> tag that carries the value. jQuery is loaded at the
 * bottom of the body, i.e. long after this file, so the jQuery hook installs
 * itself lazily once jQuery actually appears.
 */
(function () {
    'use strict';

    function token() {
        var tag = document.querySelector('meta[name="csrf-token"]');
        return tag ? tag.getAttribute('content') : '';
    }

    // Safe methods must not require a token: every ordinary page load, report
    // link and polling read uses GET, and gating those would break the app.
    var SAFE = /^(GET|HEAD|OPTIONS)$/i;

    /**
     * Only same-origin requests get the token. Sending it to a third party
     * (a CDN, an ngrok tunnel) would leak it to whoever runs that host.
     */
    function isSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return true;   // relative URL that failed to parse — treat as local
        }
    }

    var nativeFetch = window.fetch;
    if (typeof nativeFetch !== 'function') {
        return;
    }

    window.fetch = function (resource, init) {
        init = init || {};

        // fetch() accepts either a URL string or a Request object.
        var url = (resource && resource.url) ? resource.url : String(resource);
        var method = init.method || (resource && resource.method) || 'GET';

        if (!SAFE.test(method) && isSameOrigin(url)) {
            var value = token();
            if (value) {
                // Headers may arrive as a Headers instance, an array of pairs,
                // or a plain object. Normalise to Headers so we can append
                // without clobbering what the caller set.
                var headers = new Headers(
                    init.headers || (resource && resource.headers) || {}
                );
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', value);
                }
                init.headers = headers;
            }
        }

        return nativeFetch.call(this, resource, init);
    };

    /**
     * jQuery transport ($.ajax, $.post — ~31 call sites, several of them the
     * DTR review and payroll-calculation actions).
     *
     * A prefilter runs before every jQuery request, so one registration covers
     * them all, including calls made from HTML partials loaded into modals
     * later. jQuery is not defined yet when this file executes (it loads at the
     * end of the body), so poll briefly for it rather than giving up.
     */
    function installJqueryHook($) {
        if (!$ || !$.ajaxPrefilter || $.__csrfHookInstalled) {
            return false;
        }
        $.__csrfHookInstalled = true;
        $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
            var method = (options.type || options.method || 'GET').toUpperCase();
            if (SAFE.test(method)) {
                return;
            }
            if (options.crossDomain || !isSameOrigin(options.url || '')) {
                return;
            }
            var value = token();
            if (value) {
                jqXHR.setRequestHeader('X-CSRF-Token', value);
            }
        });
        return true;
    }

    if (!installJqueryHook(window.jQuery)) {
        // Every $.ajax call in this app fires on user interaction, so resolving
        // by the time the page is interactive is comfortably early enough.
        var waited = 0;
        var timer = setInterval(function () {
            waited += 50;
            if (installJqueryHook(window.jQuery) || waited >= 10000) {
                clearInterval(timer);
            }
        }, 50);
        document.addEventListener('DOMContentLoaded', function () {
            installJqueryHook(window.jQuery);
        });
    }

    /**
     * Belt and braces for any classic form that posts without JavaScript:
     * inject the hidden field at submit time so it cannot go stale.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (SAFE.test(method)) {
            return;
        }
        if (form.querySelector('input[name="csrf_token"]')) {
            return;
        }
        var value = token();
        if (!value) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = value;
        form.appendChild(input);
    }, true);
})();
