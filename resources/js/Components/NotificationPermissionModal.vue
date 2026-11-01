<script setup lang="ts">
import { useWebNotifications, SubscribeResult } from '@/Composables/useWebNotifications';
import { onMounted, ref } from 'vue';

const emit = defineEmits<{
    (e: 'activated', result: SubscribeResult): void;
    (e: 'dismissed'): void;
}>();

const {
    isSupported,
    isPermissionDenied,
    isPermissionGranted,
    isSubscribed,
    isSubscribing,
    checkSubscriptionStatus,
    subscribeToPush,
} = useWebNotifications();

const isOpen = ref(false);
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 3500);
};

onMounted(async () => {
    // Clear any previous stale test flags from localStorage
    try {
        localStorage.removeItem('notification_prompt_dismissed');
        localStorage.removeItem('webpush_prompt_dismissed');
    } catch {}

    // Check current browser support and subscription status without hanging
    await checkSubscriptionStatus();

    // 1. If notifications are not supported in browser, cannot show modal
    if (!isSupported.value) {
        return;
    }

    // 2. If user already explicitly blocked/denied in browser settings, cannot prompt
    if (isPermissionDenied.value) {
        return;
    }

    // 3. If already fully granted AND subscribed, don't show
    if (isSubscribed.value && isPermissionGranted.value) {
        return;
    }

    // 4. Check if dismissed in this current browser session
    try {
        if (sessionStorage.getItem('notification_prompt_dismissed')) {
            return;
        }
    } catch {}

    // Open the modal after a smooth delay
    setTimeout(() => {
        if (!isSubscribed.value || !isPermissionGranted.value) {
            isOpen.value = true;
        }
    }, 400);
});

const handleConfirm = async () => {
    const result = await subscribeToPush();
    isOpen.value = false;

    showToast(result.message);
    emit('activated', result);
};

const handleDismiss = () => {
    isOpen.value = false;
    try {
        sessionStorage.setItem('notification_prompt_dismissed', '1');
    } catch {}
    emit('dismissed');
};
</script>

<template>
    <!-- Floating Toast Notification on Activation -->
    <teleport to="body">
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 -translate-y-4 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 -translate-y-2 scale-95"
        >
            <div
                v-if="toastMessage"
                class="fixed top-6 left-1/2 -translate-x-1/2 z-[110] px-4 py-2.5 rounded-2xl bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
            >
                <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                    </svg>
                </div>
                <span>{{ toastMessage }}</span>
            </div>
        </transition>
    </teleport>

    <!-- Modal Prompt Teleported to Body -->
    <teleport to="body">
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm overflow-y-auto"
                @click.self="handleDismiss"
            >
                <div
                    class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 rounded-3xl p-6 relative shadow-2xl text-right animate-in zoom-in-95 duration-200 space-y-4 overflow-hidden"
                >
                    <!-- Decorative Background Glow -->
                    <div class="absolute -top-12 -right-12 w-32 h-32 bg-indigo-500/15 dark:bg-indigo-500/20 rounded-full blur-2xl pointer-events-none"></div>

                    <!-- Header -->
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-100 dark:border-indigo-800/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 shadow-inner">
                                <svg class="w-6 h-6 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm md:text-base font-black text-slate-900 dark:text-white">
                                        دریافت اعلان پیامک‌های جدید
                                    </h3>
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">پیشنهادی</span>
                                </div>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    اطلاع‌رسانی فوری بر روی مرورگر
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="handleDismiss"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                            aria-label="بستن"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="space-y-3">
                        <p class="text-xs md:text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                            آیا مایلید هنگام رسیدن پیامک جدید (حتی در صورت بسته بودن این صفحه یا مرورگر) اعلان سیستم بر روی دستگاه خود دریافت کنید؟
                        </p>
                        <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-800/40 text-[11px] text-slate-600 dark:text-slate-300 flex items-start gap-2.5">
                            <span class="text-indigo-500 dark:text-indigo-400 text-sm shrink-0">🔒</span>
                            <span class="leading-relaxed">
                                <strong>حفظ حریم خصوصی:</strong> متن پیامک‌ها به هیچ‌وجه در اعلان نمایش داده نمی‌شود و صرفاً نام یا شماره فرستنده اطلاع داده خواهد شد.
                            </span>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="handleDismiss"
                            class="px-4 py-2.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-all cursor-pointer"
                        >
                            خیر، بعداً
                        </button>
                        <button
                            type="button"
                            :disabled="isSubscribing"
                            @click="handleConfirm"
                            class="px-5 py-2.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 rounded-xl shadow-lg shadow-indigo-500/25 transition-all flex items-center gap-2 disabled:opacity-60 cursor-pointer"
                        >
                            <span v-if="isSubscribing" class="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>بله، فعال‌سازی اعلان</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
