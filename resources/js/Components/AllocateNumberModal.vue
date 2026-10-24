<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = withDefaults(
    defineProps<{
        show: boolean;
        defaultPrefix?: string;
        remainingQuota?: number;
        maxQuota?: number;
    }>(),
    {
        defaultPrefix: '+85',
        remainingQuota: undefined,
        maxQuota: undefined,
    }
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'success', message?: string): void;
}>();

const termsAccepted = ref(false);

const form = useForm({
    display_title: '',
    preferred_number: '',
});

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show) {
        handleClose();
    }
};

// Reset state when opening/closing
watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            termsAccepted.value = false;
            form.reset();
            form.clearErrors();
            window.addEventListener('keydown', handleKeydown);
        } else {
            window.removeEventListener('keydown', handleKeydown);
        }
    }
);

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

const handleClose = () => {
    if (!form.processing) {
        emit('close');
    }
};

const submitAllocation = () => {
    if (!termsAccepted.value || form.processing) return;

    form.post(route('numbers.store'), {
        preserveScroll: true,
        onSuccess: () => {
            termsAccepted.value = false;
            form.reset();
            emit('success', 'خط سازمانی جدید با موفقیت تخصیص یافت.');
            emit('close');
        },
    });
};
</script>

<template>
    <teleport to="body">
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md"
                @click.self="handleClose"
            >
                <div
                    class="glass-card !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200 max-h-[calc(100dvh-2.5rem)] overflow-y-auto"
                >
                    <!-- Header -->
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-white/10">
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                                📱
                            </div>
                            <div>
                                <h3 class="font-black text-sm text-slate-900 dark:text-white">دریافت خط سازمانی جدید</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    سرشماره استاندارد با پیش‌شماره {{ defaultPrefix }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="handleClose"
                            class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors"
                            aria-label="بستن پنجره"
                        >
                            ✕
                        </button>
                    </div>

                    <form @submit.prevent="submitAllocation" class="space-y-4">
                        <!-- Active Prefix & Capacity Badge Box -->
                        <div class="p-3.5 rounded-2xl bg-indigo-50/90 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-500/20 text-xs space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-400 font-semibold">پیش‌شماره فعال:</span>
                                <span dir="ltr" class="font-mono font-black text-indigo-600 dark:text-indigo-400">
                                    {{ defaultPrefix }}
                                </span>
                            </div>

                            <div v-if="remainingQuota !== undefined" class="flex items-center justify-between pt-1 border-t border-indigo-100 dark:border-indigo-500/15">
                                <span class="text-slate-600 dark:text-slate-400 font-semibold">ظرفیت سهمیه باقی‌مانده:</span>
                                <span
                                    class="font-black"
                                    :class="remainingQuota > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                                >
                                    {{ remainingQuota }} شماره
                                    <span v-if="maxQuota" class="text-slate-400 dark:text-slate-500 text-[10px] font-normal">
                                        (از سقف {{ maxQuota }})
                                    </span>
                                </span>
                            </div>

                            <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed pt-0.5">
                                یک شماره ۹ رقمی استاندارد یکتا به‌صورت آنی از استخر شماره‌ها به حساب شما تخصیص می‌یابد.
                            </p>
                        </div>

                        <!-- Optional Display Title -->
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                                عنوان یا نام نمایشی خط
                                <span class="text-slate-400 dark:text-slate-500 text-[10px] font-normal">(اختیاری)</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                    </svg>
                                </div>
                                <input
                                    v-model="form.display_title"
                                    type="text"
                                    class="w-full pr-9 pl-3 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-white/10 text-xs text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition-all"
                                    placeholder="مثال: واحد فروش، پشتیبانی، امور مشتریان..."
                                    maxlength="100"
                                    :disabled="form.processing"
                                />
                            </div>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400">
                                در صورت تمایل می‌توانید بعداً نیز عنوان خط را در پنل ویرایش نمایید.
                            </p>
                        </div>

                        <!-- Legal Disclaimer & Terms Agreement Box -->
                        <div class="p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200/90 dark:border-amber-500/20 text-xs space-y-2.5">
                            <div class="flex items-start gap-2 text-amber-800 dark:text-amber-200/90">
                                <svg class="w-4 h-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <p class="text-[11px] leading-relaxed">
                                    این سامانه یک بستر مستقل ارائه خدمات ارتباطی سازمانی بوده و به هیچ حزب، گروه یا سازمان سیاسی یا دولتی وابسته نیست.
                                </p>
                            </div>

                            <label class="flex items-start gap-2.5 cursor-pointer pt-1 border-t border-amber-200/60 dark:border-amber-500/15">
                                <input
                                    type="checkbox"
                                    v-model="termsAccepted"
                                    :disabled="form.processing"
                                    class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-white/20 text-indigo-600 focus:ring-indigo-500/40 cursor-pointer"
                                />
                                <span class="text-[11px] text-slate-800 dark:text-slate-200 font-bold select-none">
                                    ضوابط و مقررات بهره‌برداری را مطالعه نموده و می‌پذیرم.
                                </span>
                            </label>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200/80 dark:border-white/10">
                            <button
                                type="button"
                                @click="handleClose"
                                :disabled="form.processing"
                                class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-xs font-bold text-slate-700 dark:text-slate-300 transition-all"
                            >
                                انصراف
                            </button>

                            <button
                                type="submit"
                                :disabled="!termsAccepted || form.processing || (remainingQuota !== undefined && remainingQuota <= 0)"
                                class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed shadow-md shadow-indigo-500/20"
                            >
                                <svg
                                    v-if="form.processing"
                                    class="animate-spin h-3.5 w-3.5 text-white"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>
                                    {{ form.processing ? 'در حال تخصیص شماره...' : 'تأیید و دریافت خط' }}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </teleport>
</template>
