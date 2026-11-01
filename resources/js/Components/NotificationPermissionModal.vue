<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import { useWebNotifications, SubscribeResult } from '@/Composables/useWebNotifications';
import { onMounted, ref } from 'vue';

const emit = defineEmits<{
    (e: 'activated', result: SubscribeResult): void;
    (e: 'dismissed'): void;
}>();

const {
    isSupported,
    isPermissionDefault,
    isPermissionGranted,
    isSubscribing,
    checkSubscriptionStatus,
    subscribeToPush,
} = useWebNotifications();

const isOpen = ref(false);

onMounted(async () => {
    await checkSubscriptionStatus();

    // If notifications aren't supported or permission is already determined, don't show
    if (!isSupported.value || isPermissionGranted.value || !isPermissionDefault.value) {
        return;
    }

    // Check if dismissed in localStorage in the last 12 hours
    const dismissedAt = localStorage.getItem('notification_prompt_dismissed');
    if (dismissedAt && Date.now() - Number(dismissedAt) < 12 * 60 * 60 * 1000) {
        return;
    }

    // Smooth delay before modal pops up after page load
    setTimeout(() => {
        if (isPermissionDefault.value) {
            isOpen.value = true;
        }
    }, 600);
});

const handleConfirm = async () => {
    const result = await subscribeToPush();
    isOpen.value = false;
    emit('activated', result);
};

const handleDismiss = () => {
    isOpen.value = false;
    localStorage.setItem('notification_prompt_dismissed', Date.now().toString());
    emit('dismissed');
};
</script>

<template>
    <Modal :show="isOpen" @close="handleDismiss" maxWidth="md">
        <div class="p-6 text-right bg-white dark:bg-slate-900 rounded-3xl relative overflow-hidden border border-slate-200/80 dark:border-slate-800 shadow-2xl">
            <!-- Decorative Background Glow -->
            <div class="absolute -top-12 -right-12 w-32 h-32 bg-indigo-500/10 dark:bg-indigo-500/20 rounded-full blur-2xl pointer-events-none"></div>

            <!-- Top Icon -->
            <div class="flex items-center justify-between mb-4">
                <div class="w-14 h-14 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-100 dark:border-indigo-800/50 flex items-center justify-center text-indigo-600 dark:text-indigo-400 shadow-inner">
                    <svg class="w-7 h-7 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                </div>
                <button
                    type="button"
                    @click="handleDismiss"
                    class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 flex items-center justify-center transition-colors cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="mb-6">
                <h3 class="text-base md:text-lg font-black text-slate-900 dark:text-white mb-2 flex items-center gap-2">
                    <span>دریافت اعلان پیامک‌های جدید</span>
                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">پیشنهادی</span>
                </h3>
                <p class="text-xs md:text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    آیا مایلید هنگام رسیدن پیامک جدید (حتی در صورت بسته بودن مرورگر یا این صفحه) روی سیستم یا تلفن همراه شما اعلان ارسال شود؟
                </p>
                <div class="mt-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>حفظ حریم خصوصی: محتوای پیامک نمایش داده نمی‌شود و صرفاً نام یا شماره فرستنده اعلان خواهد شد.</span>
                </div>
            </div>

            <!-- Modal Action Buttons -->
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
    </Modal>
</template>
