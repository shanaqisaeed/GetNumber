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
    let payload = {
        title: 'پیامک جدید دریافت شد',
        body: 'پیام جدیدی دریافت شد.',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        tag: 'sms-notification-' + Date.now(),
        data: { url: '/messages' }
    };

    if (event.data) {
        try {
            const parsed = event.data.json();
            payload = { ...payload, ...parsed };
        } catch (e) {
            const text = event.data.text();
            if (text) {
                payload.body = text;
            }
        }
    }

    const title = payload.title || 'پیامک جدید دریافت شد';
    const tag = payload.tag || (payload.data?.messageId ? `getnumber-sms-${payload.data.messageId}` : ('getnumber-sms-' + Date.now()));
    const options = {
        body: payload.body || 'پیام جدیدی دریافت شد.',
        icon: payload.icon || '/icon-192.png',
        badge: payload.badge || '/icon-192.png',
        tag: tag,
        renotify: false,
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

