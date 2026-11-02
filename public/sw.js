/**
 * GetNumber Web Push Service Worker
 * Handles background push notifications and notification click events
 */

self.addEventListener('install', (event) => {
    // Activate worker immediately
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    // Become available to all open pages
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload = {};
    try {
        payload = event.data.json();
    } catch (e) {
        payload = {
            title: 'پیامک جدید دریافت شد',
            body: 'پیامک جدیدی دریافت شد.',
        };
    }

    const title = payload.title || 'پیامک جدید دریافت شد';
    const options = {
        body: payload.body || 'پیام جدیدی دریافت شد.',
        icon: payload.icon || '/icon-192.png',
        badge: payload.badge || '/icon-192.png',
        tag: payload.tag || ('sms-notification-' + (payload.data?.messageId || Date.now())),
        renotify: true,
        vibrate: [200, 100, 200],
        data: payload.data || { url: '/messages' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data?.url || '/messages';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // If any window of our app is already open, focus it
            for (const client of clientList) {
                if (client.url && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise, open a new window pointing to the target URL
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
            return null;
        })
    );
});

