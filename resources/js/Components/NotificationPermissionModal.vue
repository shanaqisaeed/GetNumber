<script setup lang="ts">
import { useWebNotifications, SubscribeResult } from '@/Composables/useWebNotifications';
import { onMounted, onUnmounted, ref } from 'vue';
import { __ } from '@/Composables/useTrans';

const emit = defineEmits<{
    (e: 'activated', result: SubscribeResult): void;
    (e: 'dismissed'): void;
}>();

const {
    isSupported,
    isPermissionDenied,
    isPermissionGranted,
    isSubscribing,
    checkSubscriptionStatus,
    subscribeToPush,
    showLocalNotification,
} = useWebNotifications();

const isOpen = ref(false);
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;

const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 4000);
};

const handleOpenModalEvent = () => {
    isOpen.value = true;
};

onMounted(async () => {
    if (typeof window === 'undefined') return;

    // Listen for manual triggers from header / settings
    window.addEventListener('open-notification-modal', handleOpenModalEvent);

    // 1. If notifications are not supported in browser, do not show modal
    if (!('Notification' in window) || !isSupported.value) {
        return;
    }

    // 2. Initial state check
    await checkSubscriptionStatus();

    // 3. CRITICAL: If permission is ALREADY granted, NEVER show the modal!
    if (Notification.permission === 'granted') {
        isOpen.value = false;
        try {
            localStorage.setItem('notification_permission_handled', 'granted');
        } catch {}

        // Silently ensure Service Worker push subscription is synchronized with backend
        subscribeToPush().catch(() => {});
        return;
    }

    // 4. If dismissed during this session, do not show again
    try {
        if (sessionStorage.getItem('notification_prompt_dismissed') === '1') {
            return;
        }
        if (Notification.permission === 'denied' && localStorage.getItem('notification_denied_dismissed') === '1') {
            return;
        }
    } catch {}

    // 5. Only for first-time unhandled state ('default'), open modal smoothly after 600ms
    setTimeout(() => {
        if (Notification.permission !== 'granted' && sessionStorage.getItem('notification_prompt_dismissed') !== '1') {
            isOpen.value = true;
        }
    }, 600);
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('open-notification-modal', handleOpenModalEvent);
    }
});

const handleConfirm = async () => {
    isOpen.value = false;
    const result = await subscribeToPush();

    if (result.success || (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'granted')) {
        try {
            localStorage.setItem('notification_permission_handled', 'granted');
            sessionStorage.removeItem('notification_prompt_dismissed');
        } catch {}
        showLocalNotification(__('ui.components.notifications_modal.test_sender'), 'welcome');
    }

    showToast(result.message);
    emit('activated', result);
};

const handleDismiss = () => {
    isOpen.value = false;
    try {
        sessionStorage.setItem('notification_prompt_dismissed', '1');
        if (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'denied') {
            localStorage.setItem('notification_denied_dismissed', '1');
        }
    } catch {}
    emit('dismissed');
};

const handleRecheck = async () => {
    await checkSubscriptionStatus();
    if (isPermissionGranted.value) {
        await handleConfirm();
    } else {
        showToast(__('ui.components.notifications_modal.not_allowed_warning'));
    }
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
                                        {{ __('ui.components.notifications_modal.title') }}
                                    </h3>
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                        {{ __('ui.components.notifications_modal.recommended') }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ __('ui.components.notifications_modal.subtitle') }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="handleDismiss"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors cursor-pointer"
                            :aria-label="__('ui.components.notifications_modal.close')"
                        >
                            ✕
                        </button>
                    </div>

                    <!-- Body: If Permission is Blocked/Denied in Browser Settings -->
                    <div v-if="isPermissionDenied" class="space-y-3">
                        <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs leading-relaxed space-y-2">
                            <div class="flex items-center gap-2 font-black text-sm text-amber-600 dark:text-amber-400">
                                <span>⚠️</span>
                                <span>{{ __('ui.components.notifications_modal.blocked_title') }}</span>
                            </div>
                            <p v-html="__('ui.components.notifications_modal.blocked_description')"></p>
                        </div>
                    </div>

                    <!-- Body: Normal Prompt -->
                    <div v-else class="space-y-3">
                        <p class="text-xs md:text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                            {{ __('ui.components.notifications_modal.prompt_text') }}
                        </p>
                        <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-800/40 text-[11px] text-slate-600 dark:text-slate-300 flex items-start gap-2.5">
                            <span class="text-indigo-500 dark:text-indigo-400 text-sm shrink-0">🔒</span>
                            <span class="leading-relaxed">
                                <strong>{{ __('ui.components.notifications_modal.privacy_badge') }}</strong>
                                {{ __('ui.components.notifications_modal.privacy_text') }}
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
                            {{ __('ui.components.notifications_modal.later_button') }}
                        </button>

                        <button
                            v-if="isPermissionDenied"
                            type="button"
                            @click="handleRecheck"
                            class="px-5 py-2.5 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 dark:bg-amber-500 dark:hover:bg-amber-600 rounded-xl shadow-lg shadow-amber-500/25 transition-all flex items-center gap-2 cursor-pointer"
                        >
                            <span>🔄</span>
                            <span>{{ __('ui.components.notifications_modal.recheck_button') }}</span>
                        </button>

                        <button
                            v-else
                            type="button"
                            :disabled="isSubscribing"
                            @click="handleConfirm"
                            class="px-5 py-2.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 rounded-xl shadow-lg shadow-indigo-500/25 transition-all flex items-center gap-2 disabled:opacity-60 cursor-pointer"
                        >
                            <span v-if="isSubscribing" class="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ __('ui.components.notifications_modal.enable_button') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </transition>
    </teleport>
</template>
