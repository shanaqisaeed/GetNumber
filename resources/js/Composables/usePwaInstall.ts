import { ref, computed, onMounted } from 'vue';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
}

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const isInstallable = ref(false);
const isInstalled = ref(false);
const isWakeLockActive = ref(false);
let wakeLockSentinel: any = null;

export function usePwaInstall() {
    const isStandalone = computed(() => {
        if (typeof window === 'undefined') return false;
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            (window.navigator as any).standalone === true ||
            document.referrer.includes('android-app://')
        );
    });

    onMounted(() => {
        if (typeof window === 'undefined') return;

        // Check if already running standalone
        if (isStandalone.value) {
            isInstalled.value = true;
            isInstallable.value = false;
        }

        // Listen for beforeinstallprompt
        window.addEventListener('beforeinstallprompt', (e: Event) => {
            e.preventDefault();
            deferredPrompt.value = e as BeforeInstallPromptEvent;
            isInstallable.value = true;
            console.log('[PWA] 📱 beforeinstallprompt fired, ready for 1-click install');
        });

        // Listen for appinstalled
        window.addEventListener('appinstalled', () => {
            isInstalled.value = true;
            isInstallable.value = false;
            deferredPrompt.value = null;
            console.log('[PWA] 🎉 PWA installed successfully');
        });
    });

    /**
     * Trigger native PWA install prompt dialog
     */
    const installApp = async (): Promise<boolean> => {
        if (!deferredPrompt.value) return false;
        try {
            await deferredPrompt.value.prompt();
            const choice = await deferredPrompt.value.userChoice;
            deferredPrompt.value = null;
            isInstallable.value = false;
            if (choice.outcome === 'accepted') {
                isInstalled.value = true;
                return true;
            }
            return false;
        } catch (err) {
            console.warn('[PWA] Install error:', err);
            return false;
        }
    };

    /**
     * Acquire Screen WakeLock during call or active session
     */
    const acquireWakeLock = async () => {
        if (typeof navigator !== 'undefined' && 'wakeLock' in navigator) {
            try {
                wakeLockSentinel = await (navigator as any).wakeLock.request('screen');
                isWakeLockActive.value = true;
                wakeLockSentinel.addEventListener('release', () => {
                    isWakeLockActive.value = false;
                    wakeLockSentinel = null;
                });
                console.log('[PWA] 💡 Screen WakeLock acquired (keeps display on during call)');
            } catch (err) {
                console.warn('[PWA] WakeLock request warning:', err);
            }
        }
    };

    /**
     * Release Screen WakeLock
     */
    const releaseWakeLock = () => {
        if (wakeLockSentinel) {
            try {
                wakeLockSentinel.release();
                wakeLockSentinel = null;
                isWakeLockActive.value = false;
                console.log('[PWA] 💤 Screen WakeLock released');
            } catch {}
        }
    };

    return {
        isInstallable,
        isInstalled,
        isStandalone,
        isWakeLockActive,
        installApp,
        acquireWakeLock,
        releaseWakeLock,
    };
}
