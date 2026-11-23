import { ref, computed } from 'vue';
import axios from 'axios';
import { __ } from './useTrans';

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

export interface SubscribeResult {
    success: boolean;
    mode: 'push' | 'local' | 'denied' | 'unsupported';
    message: string;
}

const permission = ref<NotificationPermission>(
    typeof window !== 'undefined' && 'Notification' in window
        ? Notification.permission
        : 'default'
);
const isSubscribed = ref<boolean>(false);
const isSubscribing = ref<boolean>(false);
const error = ref<string | null>(null);

export function useWebNotifications() {
    const isSupported = computed(() => {
        return (
            typeof window !== 'undefined' &&
            'Notification' in window
        );
    });

    const isPushApiSupported = computed(() => {
        return (
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window
        );
    });

    const isPermissionGranted = computed(() => permission.value === 'granted');
    const isPermissionDefault = computed(() => permission.value === 'default');
    const isPermissionDenied = computed(() => permission.value === 'denied');

    const registerServiceWorker = async (): Promise<ServiceWorkerRegistration | null> => {
        if (!isPushApiSupported.value) return null;
        try {
            await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            return await navigator.serviceWorker.ready;
        } catch (err) {
            console.warn('[WebPush] Service worker registration error:', err);
            return null;
        }
    };

    const ensurePushSubscriptionSynced = async (registration: ServiceWorkerRegistration): Promise<boolean> => {
        try {
            const { data } = await axios.get('/webpush/vapid-public-key');
            if (!data?.publicKey) return false;

            let appServerKey: any;
            try {
                appServerKey = urlBase64ToUint8Array(data.publicKey);
            } catch {
                appServerKey = data.publicKey;
            }

            let subscription = await registration.pushManager.getSubscription();

            // Verify existing browser subscription matches current server VAPID public key
            if (subscription && subscription.options?.applicationServerKey && appServerKey instanceof Uint8Array) {
                const existingKey = new Uint8Array(subscription.options.applicationServerKey);
                if (existingKey.length !== appServerKey.length || !existingKey.every((v, idx) => v === appServerKey[idx])) {
                    await subscription.unsubscribe().catch(() => {});
                    subscription = null;
                }
            }

            if (!subscription) {
                try {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: appServerKey,
                    });
                } catch (subErr) {
                    console.warn('[WebPush] Initial subscribe failed, resetting existing subscription:', subErr);
                    const oldSub = await registration.pushManager.getSubscription();
                    if (oldSub) {
                        await oldSub.unsubscribe().catch(() => {});
                    }
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: appServerKey,
                    });
                }
            }

            if (subscription) {
                const subData = subscription.toJSON();
                await axios.post('/webpush/subscribe', {
                    endpoint: subData.endpoint,
                    keys: subData.keys,
                });
                isSubscribed.value = true;
                return true;
            }
        } catch (err) {
            console.warn('[WebPush] Push subscription sync warning:', err);
        }
        return false;
    };

    const checkSubscriptionStatus = async () => {
        if (typeof window === 'undefined') return;

        if ('Notification' in window) {
            permission.value = Notification.permission;
        }

        if (isPushApiSupported.value) {
            try {
                let registration: ServiceWorkerRegistration | undefined | null = await navigator.serviceWorker.getRegistration();
                if (!registration) {
                    registration = await registerServiceWorker();
                }
                if (registration) {
                    const subscription = await registration.pushManager.getSubscription();
                    isSubscribed.value = !!subscription;

                    // Automatically ensure backend has the latest subscription registered when permission is already granted
                    if (Notification.permission === 'granted') {
                        await ensurePushSubscriptionSynced(registration);
                    }
                } else {
                    isSubscribed.value = false;
                }
            } catch (err) {
                console.warn('[WebPush] Error checking subscription:', err);
                isSubscribed.value = false;
            }
        }
    };

    /**
     * Request browser notification permission and optionally subscribe to push service
     */
    const subscribeToPush = async (): Promise<SubscribeResult> => {
        if (!isSupported.value) {
            error.value = __('ui.composables.web_notifications.unsupported');
            return {
                success: false,
                mode: 'unsupported',
                message: error.value,
            };
        }

        isSubscribing.value = true;
        error.value = null;

        try {
            // Step 1: Request user permission if not already granted (avoids re-calling requestPermission outside user gesture)
            const perm = Notification.permission === 'granted'
                ? 'granted'
                : await Notification.requestPermission();
            permission.value = perm;

            if (perm !== 'granted') {
                isSubscribing.value = false;
                return {
                    success: false,
                    mode: 'denied',
                    message: __('ui.composables.web_notifications.permission_denied'),
                };
            }

            // Step 2: Subscribe via Service Worker PushManager for lock-screen & background delivery
            let pushSubscribedSuccessfully = false;

            if (isPushApiSupported.value) {
                try {
                    const registration = await registerServiceWorker();
                    if (registration) {
                        pushSubscribedSuccessfully = await ensurePushSubscriptionSynced(registration);
                    }
                } catch (pushErr: any) {
                    console.error('[WebPush] Background PushManager registration error:', pushErr);
                }
            }

            isSubscribing.value = false;

            if (pushSubscribedSuccessfully) {
                return {
                    success: true,
                    mode: 'push',
                    message: __('ui.composables.web_notifications.push_enabled'),
                };
            }

            return {
                success: true,
                mode: 'local',
                message: __('ui.composables.web_notifications.notifications_enabled'),
            };
        } catch (err: any) {
            console.error('[WebPush] Critical subscription error:', err);
            isSubscribing.value = false;
            error.value = err?.message || __('ui.composables.web_notifications.enable_error');
            return {
                success: false,
                mode: 'denied',
                message: error.value || __('ui.composables.web_notifications.enable_error'),
            };
        }
    };

    const unsubscribeFromPush = async (): Promise<boolean> => {
        if (!isPushApiSupported.value) return false;
        isSubscribing.value = true;
        try {
            const registration = await navigator.serviceWorker.getRegistration();
            if (registration) {
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    const endpoint = subscription.endpoint;
                    await axios.post('/webpush/unsubscribe', { endpoint });
                    await subscription.unsubscribe();
                }
            }
            isSubscribed.value = false;
            return true;
        } catch (err: any) {
            console.error('[WebPush] Unsubscribe error:', err);
            return false;
        } finally {
            isSubscribing.value = false;
        }
    };

    /**
     * Show local OS notification (universal for Desktop and Mobile/Android)
     * Note: Strictly respects privacy - announces only the sender, NEVER the message body!
     */
    const showLocalNotification = async (sender: string, messageId?: number | string) => {
        if (!isSupported.value || permission.value !== 'granted') return;

        const title = __('ui.composables.web_notifications.new_sms_title');
        const body = sender
            ? __('ui.composables.web_notifications.new_sms_from_sender', { sender })
            : __('ui.composables.web_notifications.new_sms_generic');
        const tag = messageId ? `ravy-no-sms-${messageId}` : `ravy-no-sms-${Date.now()}`;
        const options: NotificationOptions = {
            body,
            icon: '/icon-192.png',
            badge: '/icon-192.png',
            tag,
            // @ts-ignore
            renotify: false,
            vibrate: [200, 100, 200],
            data: {
                url: '/messages',
                messageId,
                sender,
            },
        };

        // 1. Try service worker showNotification first (mandatory on Android Chrome, works universally)
        if ('serviceWorker' in navigator) {
            try {
                let reg = await navigator.serviceWorker.getRegistration();
                if (!reg || !reg.active) {
                    await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                    reg = await navigator.serviceWorker.ready;
                }
                if (reg && 'showNotification' in reg) {
                    await reg.showNotification(title, options);
                    return;
                }
            } catch (swErr) {
                console.warn('[WebPush] SW showNotification fallback:', swErr);
            }
        }

        // 2. Fallback to standard window Notification constructor
        try {
            const notification = new Notification(title, options);
            notification.onclick = () => {
                notification.close();
                if (window.focus) {
                    window.focus();
                }
            };
        } catch (err) {
            console.warn('[WebPush] Window Notification failed:', err);
        }
    };

    /**
     * Trigger a real Web Push test from the server (useful for verifying closed browser behavior)
     */
    const sendTestPush = async (): Promise<{ success: boolean; message: string }> => {
        try {
            const { data } = await axios.post('/webpush/test');
            return data;
        } catch (err: any) {
            return {
                success: false,
                message: err?.response?.data?.message || err.message,
            };
        }
    };

    return {
        isSupported,
        isPushApiSupported,
        isPermissionGranted,
        isPermissionDefault,
        isPermissionDenied,
        isSubscribed,
        isSubscribing,
        error,
        permission,
        checkSubscriptionStatus,
        subscribeToPush,
        unsubscribeFromPush,
        showLocalNotification,
        sendTestPush,
    };
}
