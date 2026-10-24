<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const showPassword = ref(false);
const showConfirmPassword = ref(false);

const form = useForm({
    name: '',
    username: '',
    password: '',
    password_confirmation: '',
});

// Password strength calculation
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
    if (score <= 75) return 'خوب';
    return 'بسیار قوی و ایمن';
});

const passwordStrengthColor = computed(() => {
    const score = passwordScore.value;
    if (score <= 25) return 'bg-rose-500';
    if (score <= 50) return 'bg-amber-500';
    if (score <= 75) return 'bg-indigo-500';
    return 'bg-emerald-500';
});

const passwordsMatch = computed(() => {
    if (!form.password_confirmation) return true;
    return form.password === form.password_confirmation;
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout title="ثبت‌نام متقاضی جدید" subtitle="جهت دریافت خطوط سازمانی و دسترسی به خدمات، مشخصات خود را وارد نمایید">
        <Head title="ثبت‌نام در سامانه" />

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Organization / Full Name -->
            <div>
                <label for="name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                    عنوان سازمانی / نام و نام خانوادگی
                </label>
                <div class="relative rounded-2xl group">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <input
                        id="name"
                        type="text"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="name"
                        placeholder="مثال: شرکت فناوری ارتباطات / علی احمدی"
                        class="block w-full pr-11 pl-4 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                    />
                </div>
                <InputError class="mt-2 text-xs" :message="form.errors.name" />
            </div>

            <!-- Username (Unique ID) -->
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="username" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                        شناسه کاربری دلخواه (نام کاربری)
                    </label>
                    <span class="text-[11px] text-slate-400 font-mono">حروف انگلیسی و ارقام</span>
                </div>
                <div class="relative rounded-2xl group">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <input
                        id="username"
                        type="text"
                        v-model="form.username"
                        required
                        autocomplete="username"
                        placeholder="مثال: tehran_agency یا user100"
                        dir="ltr"
                        class="block w-full pr-11 pl-4 py-3 text-sm text-right font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                    />
                </div>
                <InputError class="mt-2 text-xs" :message="form.errors.username" />
            </div>

            <!-- Password -->
            <div>
                <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                    کلمه عبور
                </label>
                <div class="relative rounded-2xl group">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        v-model="form.password"
                        required
                        autocomplete="new-password"
                        placeholder="حداقل ۸ نویسه امن"
                        dir="ltr"
                        class="block w-full pr-11 pl-11 py-3 text-sm font-medium rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                    />
                    <button
                        type="button"
                        @click="showPassword = !showPassword"
                        class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none transition-colors"
                        title="نمایش یا پنهان‌سازی کلمه عبور"
                    >
                        <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-slate-500">سطح امنیت کلمه عبور:</span>
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

                <InputError class="mt-2 text-xs" :message="form.errors.password" />
            </div>

            <!-- Password Confirmation -->
            <div>
                <label for="password_confirmation" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                    تکرار کلمه عبور
                </label>
                <div class="relative rounded-2xl group">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400 dark:text-slate-500 group-focus-within:text-indigo-500 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <input
                        id="password_confirmation"
                        :type="showConfirmPassword ? 'text' : 'password'"
                        v-model="form.password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="تکرار کلمه عبور جهت تطابق"
                        dir="ltr"
                        class="block w-full pr-11 pl-11 py-3 text-sm font-medium rounded-2xl border bg-slate-50/70 dark:bg-slate-950/70 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:focus:ring-indigo-500/20 transition-all outline-none"
                        :class="passwordsMatch ? 'border-slate-200 dark:border-slate-800' : 'border-rose-400 dark:border-rose-600'"
                    />
                    <button
                        type="button"
                        @click="showConfirmPassword = !showConfirmPassword"
                        class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none transition-colors"
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
                <p v-if="!passwordsMatch" class="mt-1 text-[11px] text-rose-500 font-semibold">
                    کلمه عبور و تکرار آن یکسان نیستند.
                </p>
                <InputError class="mt-2 text-xs" :message="form.errors.password_confirmation" />
            </div>

            <!-- Submit Button (2026 Style) -->
            <div class="pt-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full relative py-3.5 px-4 rounded-2xl bg-gradient-to-r from-indigo-600 via-indigo-500 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-600/50 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 group"
                >
                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>ثبت‌نام و افتتاح حساب کاربری</span>
                    <svg v-if="!form.processing" class="w-4 h-4 rotate-180 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </div>

            <!-- Switch to Login -->
            <div class="text-center pt-4 text-xs text-slate-500 dark:text-slate-400 border-t border-slate-100 dark:border-slate-800/80">
                پیش‌تر ثبت‌نام نموده‌اید؟
                <Link :href="route('login')" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline mr-1 inline-flex items-center gap-1 group">
                    <span>ورود به سامانه</span>
                    <svg class="w-3.5 h-3.5 rotate-180 group-hover:-translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
