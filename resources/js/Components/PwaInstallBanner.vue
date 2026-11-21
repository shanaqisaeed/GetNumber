<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { usePwaInstall } from '@/Composables/usePwaInstall';
import { useWebNotifications } from '@/Composables/useWebNotifications';

const { isInstallable, isStandalone, installApp } = usePwaInstall();
const { isPermissionGranted, isPermissionDefault, subscribeToPush } = useWebNotifications();

const isDismissed = ref(false);
const isInstalling = ref(false);

onMounted(() => {
    if (typeof window !== 'undefined') {
        isDismissed.value = localStorage.getItem('getnumber_pwa_dismissed') === 'true';
    }
});

const handleInstall = async () => {
    isInstalling.value = true;
    try {
        const ok = await installApp();
        if (ok) {
            isDismissed.value = true;
        }
    } finally {
        isInstalling.value = false;
    }
};

const handleDismiss = () => {
    isDismissed.value = true;
    if (typeof window !== 'undefined') {
        localStorage.setItem('getnumber_pwa_dismissed', 'true');
    }
};

const handleEnableNotifications = async () => {
    await subscribeToPush();
};
</script>

<template>
    <!-- Show Banner only when installable or notifications not yet granted, and not dismissed, and not standalone -->
    <div
        v-if="!isDismissed && !isStandalone && (isInstallable || isPermissionDefault)"
        class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 border border-indigo-500/30 p-3 sm:p-4 shadow-xl backdrop-blur-md text-white transition-all my-3"
    >
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-600/30 border border-indigo-500/50 flex items-center justify-center shrink-0 shadow-inner">
                    <svg class="w-5 h-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <h4 class="text-sm font-bold flex items-center gap-2 text-white">
                        <span>نصب نسخه پیش‌رونده (PWA) با زنگ بومی موبایل</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-indigo-500/20 text-indigo-300 border border-indigo-400/30">اینترانت ۱۰۰٪</span>
                    </h4>
                    <p class="text-xs text-slate-300 mt-0.5">
                        دریافت تماس و پیامک با زنگ واقعی، حتی هنگام قفل بودن صفحه و بسته بودن مرورگر در اینترانت ملی.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                <!-- Enable Push if default -->
                <button
                    v-if="isPermissionDefault"
                    type="button"
                    @click="handleEnableNotifications"
                    class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 text-xs font-bold transition-all shadow-md flex items-center gap-1.5"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <span>فعال‌سازی زنگ پس‌زمینه</span>
                </button>

                <!-- Install PWA Button -->
                <button
                    v-if="isInstallable"
                    type="button"
                    @click="handleInstall"
                    :disabled="isInstalling"
                    class="px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md flex items-center gap-1.5"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <span>{{ isInstalling ? 'در حال نصب...' : 'نصب نرم‌افزار' }}</span>
                </button>

                <!-- Dismiss -->
                <button
                    type="button"
                    @click="handleDismiss"
                    class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors"
                    title="بستن اعلان"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
