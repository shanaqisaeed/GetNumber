/**
 * Ravyno High-Performance Service Worker & Native Calling Engine
 * 100% Intranet-Safe & Self-Hosted:
 * - Native Incoming Call Notifications with [Answer / Decline] Actions
 * - Background Sync & WakeLock Keep-Alive Support
 * - Offline Shell & Resilient Asset Caching
 */

const CACHE_NAME = 'ravyno-shell-v4';
const STATIC_ASSETS = [
    '/manifest.json',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-192.png',
    '/icon-maskable-512.png',
    '/apple-touch-icon.png',
];

// Install: Cache essential PWA shell assets safely and activate immediately without failing
self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            for (const asset of STATIC_ASSETS) {
                try {
                    await cache.add(asset);
                } catch (e) {
                    // Non-fatal skip so SW activation is never blocked
                }
            }
        })
    );
});

// Activate: Purge stale caches and claim all clients immediately
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((k) => {
                    if (k !== CACHE_NAME) {
                        return caches.delete(k);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Push Event: Handle incoming voice call rings and SMS notifications
self.addEventListener('push', (event) => {
    let payload = {
        title: 'Ravyno',
        body: 'رویداد جدید دریافت شد.',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        tag: 'ravyno-notice-' + Date.now(),
        data: { url: '/calls' }
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

    const isCall = (payload.tag && (payload.tag.startsWith('ravyno-call-') || payload.tag.startsWith('getnumber-call-'))) || payload.data?.type === 'voice_call' || payload.data?.callId;
    const title = payload.title || (isCall ? '📞 تماس صوتی ورودی' : 'پیامک جدید دریافت شد');
    const tag = payload.tag || (isCall ? `ravyno-call-${payload.data?.callId || Date.now()}` : `ravyno-sms-${payload.data?.messageId || Date.now()}`);

    const options = {
        body: payload.body || (isCall ? 'شما یک تماس ورودی جدید دارید.' : 'پیام جدیدی دریافت شد.'),
        icon: payload.icon || '/icon-192.png',
        badge: payload.badge || '/icon-192.png',
        tag: tag,
        renotify: true,
        requireInteraction: isCall ? true : (payload.requireInteraction ?? false),
        vibrate: isCall
            ? [500, 250, 500, 250, 500, 250, 500, 250, 500, 250, 500] // Persistent ringing vibration
            : (payload.vibrate || [200, 100, 200]),
        actions: isCall
            ? [
                {
                    action: 'answer',
                    title: '📞 پاسخ به تماس',
                    icon: '/icon-192.png'
                },
                {
                    action: 'decline',
                    title: '❌ رد تماس',
                    icon: '/icon-192.png'
                }
            ]
            : (payload.actions || [
                {
                    action: 'view',
                    title: 'مشاهده پیام',
                    icon: '/icon-192.png'
                }
            ]),
        data: payload.data || { url: isCall ? '/calls' : '/messages' },
    };

    // Broadcast incoming call signal directly to all open browser tabs & display OS lock-screen notification
    // With iOS Safari / WebKit resilient fallback if extended options (actions/vibrate) are rejected
    const showNotifPromise = self.registration.showNotification(title, options).catch(() => {
        return self.registration.showNotification(title, {
            body: options.body,
            icon: options.icon,
            badge: options.badge,
            tag: options.tag,
            data: options.data,
        });
    });

    event.waitUntil(
        Promise.all([
            showNotifPromise,
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                for (const client of clients) {
                    client.postMessage({
                        type: isCall ? 'incoming_call_push' : 'incoming_sms_push',
                        payload: payload
                    });
                }
            })
        ])
    );
});

// Notification Click: Handle Native Answer / Decline Actions & Seamless Window Focusing
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const callId = event.notification.data?.callId;
    const hasVideo = Boolean(event.notification.data?.hasVideo);
    const caller = event.notification.data?.caller || '';
    const isCall = !!callId || (event.notification.tag && (event.notification.tag.startsWith('ravyno-call-') || event.notification.tag.startsWith('getnumber-call-')));
    const targetUrl = event.notification.data?.url || (isCall ? '/calls' : '/messages');

    // 1. User tapped "❌ رد تماس" (Decline) directly from notification / lockscreen
    if (event.action === 'decline' && callId) {
        event.waitUntil(
            fetch(`/voice/${callId}/decline`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            }).then(() => {
                // Notify open tabs to stop ringtone immediately
                return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                    for (const client of clients) {
                        client.postMessage({
                            type: 'call_declined_from_notification',
                            callId: callId
                        });
                    }
                });
            }).catch(() => {})
        );
        return;
    }

    // 2. User tapped "📞 پاسخ به تماس" (Answer) or body of call notification
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Check if any tab is currently on /calls
            for (const client of clientList) {
                if (client.url && client.url.includes('/calls') && 'focus' in client) {
                    client.postMessage({
                        type: 'answer_incoming_call',
                        callId: callId,
                        hasVideo: hasVideo,
                        caller: caller,
                        autoAnswer: event.action === 'answer' || targetUrl.includes('auto_answer=1')
                    });
                    return client.focus();
                }
            }

            // If another page of our origin is open, message GlobalIncomingCallModal to navigate via Inertia (no double reload!)
            for (const client of clientList) {
                if (client.url && 'focus' in client) {
                    client.postMessage({
                        type: 'answer_incoming_call',
                        callId: callId,
                        hasVideo: hasVideo,
                        caller: caller,
                        autoAnswer: event.action === 'answer' || targetUrl.includes('auto_answer=1')
                    });
                    return client.focus();
                }
            }

            // Otherwise, open a fresh standalone window pointing directly to the call
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
            return null;
        })
    );
});

// Background Sync: Ensures offline call state synchronization
self.addEventListener('sync', (event) => {
    if (event.tag === 'ravyno-call-sync' || event.tag === 'getnumber-call-sync') {
        event.waitUntil(
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                for (const client of clients) {
                    client.postMessage({ type: 'sync_call_state' });
                }
            })
        );
    }
});

// Fetch Event: Intranet-Safe & Offline Resilient Caching (Never blocks API, WebSockets or Auth)
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Bypass non-GET, API, WebRTC voice bridge, WebSocket, and telemetry requests
    if (
        req.method !== 'GET' ||
        url.pathname.startsWith('/api') ||
        url.pathname.startsWith('/voice') ||
        url.pathname.startsWith('/webpush') ||
        url.pathname.startsWith('/telemetry') ||
        url.pathname.includes('/bridge')
    ) {
        return;
    }

    // Static Assets & Fonts (Cache-First with Network Fallback)
    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.endsWith('.woff2') ||
        url.pathname.endsWith('.woff') ||
        url.pathname.endsWith('.png') ||
        url.pathname.endsWith('.jpg') ||
        url.pathname.endsWith('.ico') ||
        url.pathname.endsWith('.svg') ||
        url.pathname === '/manifest.json'
    ) {
        event.respondWith(
            caches.match(req).then((cached) => {
                if (cached) return cached;
                return fetch(req).then((res) => {
                    if (res && res.status === 200) {
                        const cloned = res.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, cloned));
                    }
                    return res;
                }).catch(() => cached);
            })
        );
        return;
    }

    // HTML Navigation requests (Network-First with fallback to cached page for offline/intranet safety)
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => {
                return caches.match('/calls').then((cachedCalls) => {
                    if (cachedCalls) return cachedCalls;
                    return caches.match('/manifest.json');
                });
            })
        );
    }
});
