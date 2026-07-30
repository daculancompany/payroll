<?php
/**
 * Session bootstrap — include this instead of calling session_start() directly.
 *
 * Every entry point needs the same hardened cookie flags, but the flags can only
 * be set BEFORE the session starts. admin_class.php in particular starts its
 * session before db_connect.php is loaded, so the settings cannot live there.
 * Centralising it here means one place to change, and it is safe to include
 * repeatedly (the session_status guard makes it a no-op after the first call).
 *
 * Flags set:
 *   httponly — JavaScript cannot read the session cookie, so an XSS bug can no
 *              longer lift a live admin session.
 *   samesite — Lax stops the cookie riding along on cross-site POSTs, which
 *              blunts CSRF on every state-changing endpoint.
 *   secure   — HTTPS only. Enabled only when the current request is already
 *              HTTPS; forcing it on plain-HTTP LAN/XAMPP installs would make
 *              the browser withhold the cookie and nobody could sign in.
 */

if (session_status() === PHP_SESSION_NONE) {

    // Behind a reverse proxy / tunnel the connection to PHP may be plain HTTP
    // while the browser is on HTTPS; trust the forwarded header in that case.
    $__https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    // Don't let the session id travel in the URL, where it leaks via Referer
    // headers, shared links and access logs.
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $__https,
        ]);
    } else {
        // PHP < 7.3 has no samesite key; smuggle it through the path argument.
        session_set_cookie_params(0, '/; samesite=Lax', '', $__https, true);
    }

    session_start();
}

// Session-fixation defence is already handled at the two login success points
// in admin_class.php, which both call session_regenerate_id(true) — no helper
// is needed here.

/* ──────────────────────────────────────────────────────────────────────────
 * CSRF token
 *
 * One token per session, minted on first use. The app had no CSRF defence at
 * all: every state-changing action authenticated on the session cookie alone,
 * so any page a signed-in admin visited could silently POST to ajax.php and
 * delete an employee or unlock a payroll run.
 * ────────────────────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    // random_bytes is cryptographically secure; mt_rand/uniqid are not.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csrf_token')) {
    /** The current session's CSRF token. */
    function csrf_token()
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    /** Hidden form field for any form that posts without going through fetch(). */
    function csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * True when the request carries a valid token.
     *
     * Accepts it from the X-CSRF-Token header (how assets2/js/csrf.js sends it)
     * or a csrf_token form field. Safe methods are exempt — they are not
     * supposed to change state, and requiring a token on them would break every
     * plain page load and report link.
     */
    function csrf_verify()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $expected = (string) csrf_token();
        if ($expected === '') {
            return false;
        }

        $presented = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

        return $presented !== '' && hash_equals($expected, $presented);
    }

    /** Reject the request unless it carries a valid token. */
    function csrf_require()
    {
        if (!csrf_verify()) {
            http_response_code(419);   // "Authentication Timeout" — token missing/stale
            header('Content-Type: application/json');
            echo json_encode([
                'result'  => false,
                'message' => 'Your session has expired or this request could not be verified. Please reload the page and try again.',
            ]);
            exit();
        }
    }
}
