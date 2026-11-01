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
            'Notification' in window &&
            'serviceWorker' in navigator &&
            'PushManager' in window
        );
    });

    const isPermissionGranted = computed(() => permission.value === 'granted');
    const isPermissionDefault = computed(() => permission.value === 'default');
    const isPermissionDenied = computed(() => permission.value === 'denied');

    const registerServiceWorker = async (): Promise<ServiceWorkerRegistration | null> => {
        if (!isSupported.value) return null;
        try {
            return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        } catch (err) {
            console.warn('[WebPush] Service worker registration failed:', err);
            return null;
        }
    };

    const checkSubscriptionStatus = async () => {
        if (!isSupported.value) return;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            isSubscribed.value = !!subscription;
            if ('Notification' in window) {
                permission.value = Notification.permission;
            }
        } catch (err) {
            console.warn('[WebPush] Error checking subscription:', err);
        }
    };

    const subscribeToPush = async (): Promise<boolean> => {
        if (!isSupported.value) {
            error.value = 'مرورگر شما از اعلان وب پشتیبانی نمی‌کند.';
            return false;
        }

        isSubscribing.value = true;
        error.value = null;

        try {
            // 1. Request user permission
            const perm = await Notification.requestPermission();
            permission.value = perm;

            if (perm !== 'granted') {
                isSubscribing.value = false;
                return false;
            }

            // 2. Register Service Worker and await ready
            const registration = await registerServiceWorker();
            if (!registration) {
                throw new Error('عدم امکان ثبت سرویس‌ورکر مرورگر');
            }
            await navigator.serviceWorker.ready;

            // 3. Fetch VAPID Public Key from backend
            const { data } = await axios.get('/webpush/vapid-public-key');
            if (!data?.publicKey) {
                throw new Error('کلید عمومی سرور دریافت نشد.');
            }

            // 4. Subscribe to PushManager
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(data.publicKey) as any,
                });
            }

            // 5. Send subscription to Laravel backend
            const subData = subscription.toJSON();
            await axios.post('/webpush/subscribe', {
                endpoint: subData.endpoint,
                keys: subData.keys,
            });

            isSubscribed.value = true;
            return true;
        } catch (err: any) {
            console.error('[WebPush] Subscription error:', err);
            error.value = err?.response?.data?.message || err?.message || 'خطا در ثبت اعلان‌های مرورگر';
            return false;
        } finally {
            isSubscribing.value = false;
        }
    };

    const unsubscribeFromPush = async (): Promise<boolean> => {
        if (!isSupported.value) return false;
        isSubscribing.value = true;
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                const endpoint = subscription.endpoint;
                await axios.post('/webpush/unsubscribe', { endpoint });
                await subscription.unsubscribe();
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
     * Show local OS notification when tab is open but not focused
     * Note: Respects privacy - strictly states sender, NOT message body!
     */
    const showLocalNotification = (sender: string) => {
        if (!isSupported.value || permission.value !== 'granted') return;

        try {
            const title = 'پیامک جدید دریافت شد';
            const body = sender ? `پیام جدیدی از شماره ${sender} دریافت شد.` : 'پیامک جدیدی دریافت شد.';

            const notification = new Notification(title, {
                body,
                icon: '/favicon.ico',
                badge: '/favicon.ico',
                tag: 'getnumber-local-' + Date.now(),
            });

            notification.onclick = () => {
                notification.close();
                if (window.focus) {
                    window.focus();
                }
            };
        } catch (err) {
            console.warn('[WebPush] Local notification failed:', err);
        }
    };

    return {
        isSupported,
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
    };
}
