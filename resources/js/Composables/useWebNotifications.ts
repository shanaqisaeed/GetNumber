import { ref, computed } from 'vue';
import axios from 'axios';

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
            return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        } catch (err) {
            console.warn('[WebPush] Service worker registration error:', err);
            return null;
        }
    };

    const checkSubscriptionStatus = async () => {
        if (typeof window === 'undefined') return;

        if ('Notification' in window) {
            permission.value = Notification.permission;
        }

        if (isPushApiSupported.value) {
            try {
                // Must use getRegistration() instead of ready, which hangs indefinitely if SW is not yet active
                const registration = await navigator.serviceWorker.getRegistration();
                if (registration) {
                    const subscription = await registration.pushManager.getSubscription();
                    isSubscribed.value = !!subscription;

                    // Automatically ensure backend has the latest subscription registered for this user session
                    if (subscription && Notification.permission === 'granted') {
                        const subData = subscription.toJSON();
                        axios.post('/webpush/subscribe', {
                            endpoint: subData.endpoint,
                            keys: subData.keys,
                        }).catch(() => {});
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
            error.value = 'مرورگر شما از قابلیت اعلان وب پشتیبانی نمی‌کند.';
            return {
                success: false,
                mode: 'unsupported',
                message: error.value,
            };
        }

        isSubscribing.value = true;
        error.value = null;

        try {
            // Step 1: Request user permission directly from browser
            const perm = await Notification.requestPermission();
            permission.value = perm;

            if (perm !== 'granted') {
                isSubscribing.value = false;
                return {
                    success: false,
                    mode: 'denied',
                    message: 'مجوز ارسال اعلان توسط شما در مرورگر رد شد.',
                };
            }

            // At this point, permission IS GRANTED. User is guaranteed to receive notifications!
            // Step 2: Attempt PushManager subscription for offline background delivery
            let pushSubscribedSuccessfully = false;

            if (isPushApiSupported.value) {
                try {
                    const registration = await registerServiceWorker();
                    if (registration) {
                        // Fetch VAPID Public Key from backend
                        const { data } = await axios.get('/webpush/vapid-public-key');
                        if (data?.publicKey) {
                            let subscription = await registration.pushManager.getSubscription();
                            let appServerKey: any;
                            try {
                                appServerKey = urlBase64ToUint8Array(data.publicKey);
                            } catch {
                                appServerKey = data.publicKey;
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
                                pushSubscribedSuccessfully = true;
                            }
                        }
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
                    message: 'اعلان‌های پیشرفته مرورگر (حتی در صورت بسته بودن صفحه) فعال شدند.',
                };
            }

            return {
                success: true,
                mode: 'local',
                message: 'اعلان‌های مرورگر با موفقیت فعال شدند.',
            };
        } catch (err: any) {
            console.error('[WebPush] Critical subscription error:', err);
            isSubscribing.value = false;
            error.value = err?.message || 'خطا در فعال‌سازی اعلان‌ها';
            return {
                success: false,
                mode: 'denied',
                message: error.value || 'خطا در فعال‌سازی اعلان‌ها',
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
    const showLocalNotification = async (sender: string) => {
        if (!isSupported.value || permission.value !== 'granted') return;

        const title = 'پیامک جدید دریافت شد';
        const body = sender ? `پیام جدیدی از شماره ${sender} دریافت شد.` : 'پیامک جدیدی دریافت شد.';
        const options: NotificationOptions = {
            body,
            icon: '/icon-192.png',
            badge: '/icon-192.png',
            tag: 'getnumber-sms-' + Date.now(),
            // @ts-ignore
            vibrate: [200, 100, 200],
        };

        // 1. Try service worker showNotification first (mandatory on Android Chrome, works universally)
        if ('serviceWorker' in navigator) {
            try {
                let reg = await navigator.serviceWorker.getRegistration();
                if (!reg) {
                    reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
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
