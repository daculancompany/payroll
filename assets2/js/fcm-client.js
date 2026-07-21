// Firebase Cloud Messaging — admin browser registration.
// Registers the service worker, asks for notification permission, obtains the
// FCM token and stores it server-side (fcm_tokens table) so attendance events
// can be pushed to this browser even when the site is closed.
//
// Permission is requested from a CLICK on a small banner when still undecided —
// Chrome's quieter UI and Safari both ignore requests made without a user gesture.
import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.16.0/firebase-app.js';
import { getMessaging, getToken, deleteToken, onMessage, isSupported } from 'https://www.gstatic.com/firebasejs/12.16.0/firebase-messaging.js';

const firebaseConfig = {
    apiKey: "AIzaSyC9P9TRB30NkwSR_8rxrdKy3ELbrFP9h_c",
    authDomain: "payroll-comc.firebaseapp.com",
    projectId: "payroll-comc",
    storageBucket: "payroll-comc.firebasestorage.app",
    messagingSenderId: "691341817439",
    appId: "1:691341817439:web:36e19671fb8a4e91734dea",
    measurementId: "G-MLGX1B04PR"
};

// Firebase Console → Project settings → Cloud Messaging → Web Push certificates
const VAPID_KEY = 'BCflMElogMVA3TtKqWKMy1A2ZA_ea-m38fetLatC7XwSAO3HkgJmFQ70XuGrHIl66nZeQOMPKroMDqlLnHLZY80';

const BANNER_DISMISS_KEY = 'fcm_banner_dismissed_on';

// Where to POST the token. Admin shell uses the default; the employee portal
// sets window.FCM_SAVE_URL = 'emp-portal-ajax.php?action=save_fcm_token' first.
const SAVE_URL = window.FCM_SAVE_URL || 'ajax.php?action=save_fcm_token';

// ── In-app notification toast (foreground pushes) ───────────────────────────
// Slide-in card, mobile-friendly (full width on small screens), auto-dismisses,
// clicking it opens the link. Plays /notification.mp3.

const NOTIF_SOUND = new Audio('notification.mp3');
NOTIF_SOUND.preload = 'auto';

function playNotifSound() {
    try {
        NOTIF_SOUND.currentTime = 0;
        const p = NOTIF_SOUND.play();
        if (p) p.catch(function (e) { console.warn('[FCM] sound blocked (no user interaction yet):', e.name); });
    } catch (e) { /* ignore */ }
}

function ensureToastStyles() {
    if (document.getElementById('fcm-toast-css')) return;
    const css = document.createElement('style');
    css.id = 'fcm-toast-css';
    css.textContent =
        '#fcm-toast-stack{position:fixed;top:14px;right:14px;z-index:100000;display:flex;flex-direction:column;gap:10px;width:340px;max-width:calc(100vw - 28px);}' +
        '.fcm-toast{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #cfe9d6;border-left:4px solid #107c41;border-radius:12px;' +
            'box-shadow:0 8px 28px rgba(0,0,0,.22);padding:12px 14px;cursor:pointer;animation:fcmSlideIn .3s ease;overflow:hidden;}' +
        '.fcm-toast.hide{transition:all .25s ease;opacity:0;transform:translateX(30px);}' +
        '.fcm-toast img{width:36px;height:36px;border-radius:8px;flex:none;}' +
        '.fcm-toast .t{font-weight:700;font-size:13px;color:#0e6b37;margin-bottom:2px;}' +
        '.fcm-toast .b{font-size:12px;color:#444;line-height:1.4;word-break:break-word;}' +
        '.fcm-toast .x{flex:none;background:none;border:none;color:#999;font-size:16px;cursor:pointer;padding:0 2px;line-height:1;}' +
        '@keyframes fcmSlideIn{from{opacity:0;transform:translateX(40px);}to{opacity:1;transform:translateX(0);}}' +
        '@media (max-width: 576px){#fcm-toast-stack{top:10px;right:10px;left:10px;width:auto;}}';
    document.head.appendChild(css);
}

function showInAppNotification(title, body, link) {
    ensureToastStyles();
    let stack = document.getElementById('fcm-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'fcm-toast-stack';
        document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = 'fcm-toast';
    toast.innerHTML =
        '<img src="assets2/images/logo.jpeg" alt="">' +
        '<div style="flex:1;min-width:0;"><div class="t"></div><div class="b"></div></div>' +
        '<button class="x" title="Dismiss">&times;</button>';
    toast.querySelector('.t').textContent = title;
    toast.querySelector('.b').textContent = body;

    const remove = function () {
        toast.classList.add('hide');
        setTimeout(function () { toast.remove(); }, 260);
    };
    toast.querySelector('.x').addEventListener('click', function (e) { e.stopPropagation(); remove(); });
    toast.addEventListener('click', function () { if (link) location.href = link; });

    stack.prepend(toast);
    while (stack.children.length > 4) stack.lastElementChild.remove();   // cap the pile
    playNotifSound();
    setTimeout(remove, 8000);
}

// register() resolves while the worker is still installing; getToken() needs an
// ACTIVE worker or PushManager.subscribe aborts. Wait for activation (max ~10s).
function waitForActiveWorker(reg) {
    if (reg.active) return Promise.resolve();
    const sw = reg.installing || reg.waiting;
    if (!sw) return Promise.resolve();
    return new Promise(function (resolve, reject) {
        const timer = setTimeout(function () { reject(new Error('service worker did not activate within 10s')); }, 10000);
        sw.addEventListener('statechange', function () {
            if (sw.state === 'activated') { clearTimeout(timer); resolve(); }
            if (sw.state === 'redundant') { clearTimeout(timer); reject(new Error('service worker install failed (redundant) — check firebase-messaging-sw.js for errors')); }
        });
        if (sw.state === 'activated') { clearTimeout(timer); resolve(); }
    });
}

// Registers SW, fetches the FCM token and syncs it to the server.
// Only call once Notification.permission === 'granted'.
async function registerAndSync() {
    const app = initializeApp(firebaseConfig);
    const messaging = getMessaging(app);

    let reg;
    try {
        reg = await navigator.serviceWorker.register('firebase-messaging-sw.js');
        await waitForActiveWorker(reg);
        console.log('[FCM] ✅ service worker registered & active:', reg.scope);
    } catch (e) {
        console.error('[FCM] ❌ service worker registration failed:', e);
        throw e;
    }

    let token;
    try {
        token = await getToken(messaging, { vapidKey: VAPID_KEY, serviceWorkerRegistration: reg });
    } catch (e) {
        // "AbortError: … could not retrieve the public key" (and similar) almost
        // always means this browser holds a PushManager subscription created with a
        // DIFFERENT applicationServerKey (e.g. the VAPID key changed, or an old
        // Firebase config). The push service then refuses a token until the stale
        // subscription is dropped. Clear it + Firebase's cached token, then retry once.
        console.warn('[FCM] getToken failed (' + e.name + ') — clearing stale push subscription and retrying:', e.message);
        try {
            const sub = await reg.pushManager.getSubscription();
            if (sub) await sub.unsubscribe();
            try { await deleteToken(messaging); } catch (_) { /* no cached token — fine */ }
            token = await getToken(messaging, { vapidKey: VAPID_KEY, serviceWorkerRegistration: reg });
            console.log('[FCM] ✅ recovered after clearing stale subscription');
        } catch (e2) {
            console.error('[FCM] ❌ getToken still failing after clearing subscription. ' +
                'Verify VAPID_KEY matches Firebase Console → Project settings → Cloud Messaging → ' +
                'Web Push certificates (Key pair):', e2);
            throw e2;
        }
    }
    if (!token) {
        console.error('[FCM] ❌ no token returned — permission granted but FCM gave nothing back');
        return;
    }
    console.log('[FCM] ✅ token obtained:', token);

    // Save on EVERY page load — the server dedupes by token (unique key) and just
    // bumps last_seen/user_id. Never skip via localStorage: if the server row was
    // cleaned up (token rotation) it must be re-created on the next visit.
    try {
        const fd = new FormData();
        fd.append('token', token);
        const res = await fetch(SAVE_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
        const body = await res.text();
        if (res.ok && body.trim() === '1') {
            console.log('[FCM] ✅ token saved to server (fcm_tokens)');
        } else {
            console.error('[FCM] ❌ server rejected token save — HTTP', res.status, 'response:', body.slice(0, 200));
        }
    } catch (e) {
        console.error('[FCM] ❌ token save request failed:', e);
    }

    // Foreground pushes: page is open — show a custom in-app toast with sound
    // instead of a system notification (which macOS often mutes anyway).
    onMessage(messaging, function (payload) {
        console.log('[FCM] 📩 push received (foreground):', payload);
        const d = payload.data || {};
        showInAppNotification(d.title || 'COMC Payroll', d.body || '', d.link || 'index.php');
        // Let the host page react (e.g. the employee portal pull-to-refreshes to
        // pull in the new content). No-op on pages without a listener.
        try { window.dispatchEvent(new CustomEvent('fcm:foreground-message', { detail: payload })); } catch (e) { /* noop */ }
    });
}

// Small bottom-right banner whose click supplies the user gesture browsers
// require before they honor Notification.requestPermission().
function showEnableBanner() {
    // Don't nag: show at most once per day after a dismiss.
    if (localStorage.getItem(BANNER_DISMISS_KEY) === new Date().toDateString()) return;
    if (document.getElementById('fcm-enable-banner')) return;

    const div = document.createElement('div');
    div.id = 'fcm-enable-banner';
    div.style.cssText = 'position:fixed;bottom:18px;right:18px;z-index:99999;background:#fff;border:1px solid #cfe9d6;' +
        'border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.18);padding:14px 16px;max-width:300px;font-size:13px;color:#333;';
    div.innerHTML =
        '<div style="font-weight:700;color:#107c41;margin-bottom:4px;"><i class="ri-notification-3-line"></i> Attendance alerts</div>' +
        '<div style="margin-bottom:10px;">Get a desktop notification for every new attendance scan — even when this site is closed.</div>' +
        '<button id="fcm-enable-btn" style="background:#107c41;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-weight:600;cursor:pointer;">Enable</button> ' +
        '<button id="fcm-later-btn" style="background:none;border:none;color:#888;cursor:pointer;padding:6px 10px;">Later</button>';
    document.body.appendChild(div);

    document.getElementById('fcm-enable-btn').addEventListener('click', async function () {
        const permission = await Notification.requestPermission();   // valid: called from a click
        div.remove();
        console.log('[FCM] permission choice:', permission);
        if (permission === 'granted') {
            try { await registerAndSync(); } catch (e) { console.error('[FCM] ❌ registration failed:', e); }
        } else {
            console.error('[FCM] ❌ permission not granted — no push possible');
        }
    });
    document.getElementById('fcm-later-btn').addEventListener('click', function () {
        localStorage.setItem(BANNER_DISMISS_KEY, new Date().toDateString());
        div.remove();
    });
}

// Notifications were denied earlier — the browser will never re-prompt on its
// own, so show a visible alert walking the user through re-allowing them.
function showBlockedAlert() {
    if (localStorage.getItem(BANNER_DISMISS_KEY) === new Date().toDateString()) return;
    if (typeof Swal === 'undefined') { alert('Notifications are blocked for this site.\n\nClick the lock icon beside the address bar → Notifications → Allow, then reload the page.'); return; }
    Swal.fire({
        icon: 'warning',
        title: 'Allow notifications',
        html: 'Attendance alerts are <b>blocked</b> for this site.<br><br>' +
              '1. Click the <b>lock icon</b> 🔒 beside the address bar<br>' +
              '2. Set <b>Notifications</b> to <b>Allow</b><br>' +
              '3. Reload this page',
        showCancelButton: true,
        confirmButtonText: 'I allowed it — reload',
        cancelButtonText: 'Later',
        confirmButtonColor: '#107c41'
    }).then(function (r) {
        if (r.isConfirmed) {
            location.reload();
        } else {
            localStorage.setItem(BANNER_DISMISS_KEY, new Date().toDateString());
        }
    });
}

async function initFcm() {
    try {
        if (VAPID_KEY.startsWith('PASTE_')) {
            console.warn('[FCM] VAPID key not set — web push disabled. See assets2/js/fcm-client.js');
            return;
        }
        if (!('serviceWorker' in navigator) || !('Notification' in window) || !(await isSupported())) {
            console.error('[FCM] ❌ push not supported in this browser/context (needs HTTPS or localhost)');
            return;
        }
        console.log('[FCM] supported ✔ — permission is:', Notification.permission);

        if (Notification.permission === 'granted') {
            await registerAndSync();
        } else if (Notification.permission === 'default') {
            console.log('[FCM] showing enable banner (waiting for user click)');
            showEnableBanner();
        } else {
            console.error('[FCM] ❌ notifications are BLOCKED for this site — lock icon in address bar → Notifications → Allow, then reload');
            showBlockedAlert();
        }
    } catch (err) {
        console.error('[FCM] ❌ init failed:', err);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFcm);
} else {
    initFcm();
}
