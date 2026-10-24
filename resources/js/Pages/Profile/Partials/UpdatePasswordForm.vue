<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

// Real-time password strength calculation
const passwordScore = computed(() => {
    const val = form.password;
    if (!val) return 0;
    let score = 0;
    if (val.length >= 8) score += 25;
    if (val.length >= 12) score += 25;
    if (/[0-9]/.test(val)) score += 25;
    if (/[^A-Za-z0-9]/.test(val) || /[A-Z]/.test(val)) score += 25;
    return score;
});

const passwordStrengthText = computed(() => {
    const score = passwordScore.value;
    if (score === 0) return '';
    if (score <= 25) return 'ضعیف';
    if (score <= 50) return 'متوسط';
    if (score <= 75) return 'خوب و مطمئن';
    return 'بسیار قوی و ایمن';
});

const passwordStrengthColor = computed(() => {
    const score = passwordScore.value;
    if (score <= 25) return 'bg-rose-500';
    if (score <= 50) return 'bg-amber-500';
    if (score <= 75) return 'bg-indigo-500';
    return 'bg-emerald-500';
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section class="w-full space-y-4">
        <header class="flex items-center gap-2.5 pb-3 border-b border-slate-200/80 dark:border-white/10">
            <div class="w-9 h-9 rounded-2xl bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/25 flex items-center justify-center text-base shrink-0 shadow-sm">
                🔐
            </div>
            <div>
                <h3 class="font-black text-sm text-slate-900 dark:text-white">
                    امنیت و کلمه عبور
                </h3>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    بروزرسانی گذرواژه جهت محافظت از دسترسی‌های سازمانی
                </p>
            </div>
        </header>

        <form @submit.prevent="updatePassword" class="space-y-4 w-full">
            <!-- Current Password -->
            <div class="space-y-1.5">
                <label for="current_password" class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                    کلمه عبور فعلی
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <input
                        id="current_password"
                        ref="currentPasswordInput"
                        v-model="form.current_password"
                        :type="showCurrentPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="کلمه عبور فعال فعلی"
                        dir="ltr"
                        class="w-full pr-10 pl-10 py-2.5 text-xs font-medium rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-800/80 text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-all"
                    />
                    <button
                        type="button"
                        @click="showCurrentPassword = !showCurrentPassword"
                        class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                        title="نمایش/پنهان گذرواژه"
                    >
                        <svg v-if="!showCurrentPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                        </svg>
                    </button>
                </div>
                <InputError :message="form.errors.current_password" class="mt-1 text-xs" />
            </div>

            <!-- New Password -->
            <div class="space-y-1.5">
                <label for="password" class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                    کلمه عبور جدید
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        :type="showNewPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="حداقل ۸ نویسه امن"
                        dir="ltr"
                        class="w-full pr-10 pl-10 py-2.5 text-xs font-medium rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-800/80 text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-all"
                    />
                    <button
                        type="button"
                        @click="showNewPassword = !showNewPassword"
                        class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                        title="نمایش/پنهان گذرواژه"
                    >
                        <svg v-if="!showNewPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                        </svg>
                    </button>
                </div>

                <!-- Password Strength Visual Indicator -->
                <div v-if="form.password" class="mt-2 space-y-1">
                    <div class="flex items-center justify-between text-[10px]">
                        <span class="text-slate-500">کیفیت امنیتی گذرواژه:</span>
                        <span class="font-bold text-slate-700 dark:text-slate-300">{{ passwordStrengthText }}</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                        <div
                            class="h-full transition-all duration-300 rounded-full"
                            :class="passwordStrengthColor"
                            :style="{ width: `${passwordScore}%` }"
                        ></div>
                    </div>
                </div>

                <InputError :message="form.errors.password" class="mt-1 text-xs" />
            </div>

            <!-- Password Confirmation -->
            <div class="space-y-1.5">
                <label for="password_confirmation" class="block text-xs font-bold text-slate-800 dark:text-slate-200">
                    تکرار کلمه عبور جدید
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showConfirmPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="تکرار کلمه عبور جدید"
                        dir="ltr"
                        class="w-full pr-10 pl-10 py-2.5 text-xs font-medium rounded-2xl border border-slate-200 dark:border-white/10 bg-slate-50 dark:bg-slate-800/80 text-slate-900 dark:text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-purple-500/50 transition-all"
                    />
                    <button
                        type="button"
                        @click="showConfirmPassword = !showConfirmPassword"
                        class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
                        title="نمایش/پنهان گذرواژه"
                    >
                        <svg v-if="!showConfirmPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                        </svg>
                    </button>
                </div>
                <InputError :message="form.errors.password_confirmation" class="mt-1 text-xs" />
            </div>

            <!-- Action Button & Feedback -->
            <div class="pt-2 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200/80 dark:border-white/10">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl inline-flex items-center justify-center gap-2 shadow-md shadow-purple-600/25 disabled:opacity-40"
                >
                    <svg v-if="form.processing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>بروزرسانی گذرواژه</span>
                </button>

                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-1"
                    leave-active-class="transition ease-in duration-150"
                    leave-to-class="opacity-0 translate-y-1"
                >
                    <div
                        v-if="form.recentlySuccessful"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 text-xs font-bold"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>گذرواژه با موفقیت تغییر یافت.</span>
                    </div>
                </Transition>
            </div>
        </form>
    </section>
</template>
