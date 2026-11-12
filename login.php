<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/session_bootstrap.php';
$brand = getenv('APP_BRAND') ?: 'Payroll';
// Sign-in photo. Swap in your own (or a licensed) image here or via APP_LOGIN_BG.
$login_bg = getenv('APP_LOGIN_BG') ?: 'login-bg.jpg';
// Absolute path: a relative url() inside a CSS variable would resolve
// against assets3/css/, not this page.
if (!preg_match('#^(https?:)?/#', $login_bg)) {
    $login_bg = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/' . $login_bg;
}
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sign in · <?= e($brand) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <?php include __DIR__ . '/includes/favicon.php'; ?>

    <!-- PWA: the admin app can be installed from the sign-in screen too -->
    <link rel="manifest" href="manifest-admin.webmanifest">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="assets2/images/pwa/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e($brand) ?>">
    <script src="assets2/js/pwa-install.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('firebase-messaging-sw.js').catch(function (e) {
                    console.warn('[PWA] SW registration failed:', e);
                    if (window.pwaNoteSwError) window.pwaNoteSwError(e.message || e);
                });
            });
        }
    </script>

    <!-- Same appearance the user picked on this device (theme engine). -->
    <script>window.__UI_SERVER = {};</script>
    <script src="<?= av('assets3/js/color-themes.js') ?>"></script>
    <script src="<?= av('assets3/js/theme-engine.js') ?>"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css">
    <link rel="stylesheet" href="<?= av('assets3/css/app.css') ?>">
    <link rel="stylesheet" href="<?= av('assets3/css/auth.css') ?>">
</head>
<body class="v2 auth">
<div class="v2-bg" aria-hidden="true"></div>

<main class="auth-shell">
    <section class="auth-hero" aria-hidden="true" style="--auth-bg: url('<?= e($login_bg) ?>');">
        <div class="auth-hero__brand">
            <span class="v2-brand__mark"><img src="assets/images/favicon-192.png?v=2" alt=""></span>
            <strong><?= e($brand) ?></strong>
        </div>
        <div class="auth-hero__copy">
            <h1>Payroll, timekeeping and leave in one place.</h1>
            <p>Run payroll from biometric DTR, approve leave through your area chain, and hand every employee a payslip they can read.</p>
            <dl class="auth-hero__facts">
                <div><dt>DTR</dt><dd>Biometric &amp; face-verified</dd></div>
                <div><dt>Payroll</dt><dd>Locked, auditable payslips</dd></div>
                <div><dt>Leave</dt><dd>Approvals by area chain</dd></div>
            </dl>
        </div>
        <div class="auth-hero__foot">v<?= e(APP_VERSION) ?></div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-card__mobile-brand">
                <span class="v2-brand__mark"><img src="assets/images/favicon-192.png?v=2" alt=""></span>
                <strong><?= e($brand) ?></strong>
            </div>

            <h2>Welcome back</h2>
            <p class="auth-sub">Sign in to continue.</p>
            <?php if (app_is_local()): ?>
                <div class="auth-badge"><i class="ri-shield-keyhole-line"></i> Payroll workstation · administrator access only</div>
            <?php endif; ?>

            <form id="form-login" method="post" novalidate>
                <div id="message-show" role="alert" aria-live="polite"></div>

                <label class="form-label" for="username">Email or employee no.</label>
                <div class="auth-input">
                    <i class="ri-user-3-line"></i>
                    <!-- Posted as `username`: the server matches users and employee accounts. -->
                    <input type="text" class="form-control" name="username" id="username" placeholder="you@company.com" autocomplete="username" required autofocus>
                </div>

                <label class="form-label" for="password-input">Password</label>
                <div class="auth-input">
                    <i class="ri-lock-2-line"></i>
                    <input type="password" class="form-control" name="password" id="password-input" placeholder="Your password" autocomplete="current-password" required>
                    <button type="button" class="auth-input__toggle" id="password-addon" aria-label="Show password" aria-pressed="false"><i class="ri-eye-line" aria-hidden="true"></i></button>
                </div>

                <button class="btn btn-primary btn-lg w-100 mt-3" type="submit" id="btn-login">
                    <span class="auth-btn-label">Sign in</span>
                    <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                </button>

                <div class="mt-3" data-pwa-install data-pwa-display="block" style="display:none;">
                    <button class="btn btn-light w-100" type="button"><i class="ri-download-2-line me-1"></i> Install app</button>
                </div>
            </form>

            <div class="auth-foot">
                <button type="button" class="v2-icon-btn" id="auth-mode" aria-label="Toggle light or dark mode">
                    <i class="ri-moon-clear-line v2-when-light"></i><i class="ri-sun-line v2-when-dark"></i>
                </button>
                <span>&copy; <?= date('Y') ?> <?= e($brand) ?> · v<?= e(APP_VERSION) ?></span>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    var form = document.getElementById('form-login');
    var msg = document.getElementById('message-show');
    var btn = document.getElementById('btn-login');
    var pw = document.getElementById('password-input');
    var eye = document.getElementById('password-addon');

    function showError(text) {
        msg.innerHTML = '';
        var d = document.createElement('div');
        d.className = 'alert alert-danger d-flex gap-2 align-items-start';
        d.innerHTML = '<i class="ri-error-warning-line"></i><span></span>';
        d.lastChild.textContent = text;      // server text is never parsed as HTML
        msg.appendChild(d);
    }
    function busy(on) {
        btn.disabled = on;
        btn.querySelector('.spinner-border').classList.toggle('d-none', !on);
        btn.querySelector('.auth-btn-label').textContent = on ? 'Signing in…' : 'Sign in';
    }

    eye.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.setAttribute('aria-pressed', show);
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        eye.firstElementChild.className = show ? 'ri-eye-off-line' : 'ri-eye-line';
    });

    document.getElementById('auth-mode').addEventListener('click', function () {
        UITheme.set({ theme: UITheme.mode() === 'dark' ? 'light' : 'dark', colorTheme: '' });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msg.innerHTML = '';
        if (!form.username.value.trim()) { showError('Enter your email or employee number.'); form.username.focus(); return; }
        if (!pw.value) { showError('Enter your password.'); pw.focus(); return; }
        busy(true);
        fetch('ajax.php?action=login', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.result) { location.href = res.redirect || 'index.php?page=home'; return; }
                busy(false);
                showError((res && res.message) || 'Sign-in failed.');
            })
            .catch(function () { busy(false); showError('Could not reach the server. Check your connection and try again.'); });
    });
})();
</script>
</body>
</html>
