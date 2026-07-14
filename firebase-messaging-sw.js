/* Firebase Cloud Messaging service worker.
 * Receives pushes while the site is closed / in the background and shows
 * a system notification. The server sends DATA-ONLY messages so this worker
 * is the single place notifications are rendered (no double-display). */

importScripts('https://www.gstatic.com/firebasejs/12.16.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/12.16.0/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: "AIzaSyC9P9TRB30NkwSR_8rxrdKy3ELbrFP9h_c",
    authDomain: "payroll-comc.firebaseapp.com",
    projectId: "payroll-comc",
    storageBucket: "payroll-comc.firebasestorage.app",
    messagingSenderId: "691341817439",
    appId: "1:691341817439:web:36e19671fb8a4e91734dea"
});

const messaging = firebase.messaging();

// A (no-op) fetch handler is what makes the site pass Chrome/Android's
// "installable" check so the browser offers Add to Home Screen. We don't
// cache — requests pass straight through to the network.
self.addEventListener('fetch', function () { /* pass-through */ });

messaging.onBackgroundMessage(function (payload) {
    const d = payload.data || {};
    return self.registration.showNotification(d.title || 'COMC Payroll', {
        body: d.body || '',
        icon: 'assets2/images/pwa/icon-192.png',
        badge: 'assets2/images/pwa/icon-192.png',
        tag: d.tag || 'comc-payroll',
        renotify: true,
        data: { link: d.link || 'index.php' }
    });
});

// Clicking the notification focuses an open payroll tab (and navigates it),
// or opens a new one.
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const link = (event.notification.data && event.notification.data.link) || 'index.php';
    const target = new URL(link, self.registration.scope).href;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (const c of list) {
                if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
                    c.navigate(target);
                    return c.focus();
                }
            }
            return clients.openWindow(target);
        })
    );
});
