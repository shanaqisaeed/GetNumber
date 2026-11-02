<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';

const isRecovery = ref(false);
const codeInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggleRecovery = () => {
    isRecovery.value = !isRecovery.value;
    form.clearErrors();
    form.code = '';
    form.recovery_code = '';
    nextTick(() => {
        codeInput.value?.focus();
    });
};

const submit = () => {
    form.post(route('two-factor.challenge'), {
        onFinish: () => {
            form.reset('code', 'recovery_code');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="احراز هویت دومرحله‌ای" />

        <!-- Header -->
        <div class="text-center mb-5">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-pink-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">احراز هویت دومرحله‌ای</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                {{ isRecovery ? 'لطفاً یکی از کدهای بازیابی اضطراری خود را وارد کنید' : 'کد ۶ رقمی نمایش داده شده در برنامه Authenticator را وارد کنید' }}
            </p>
        </div>

        <!-- Glass Card Form -->
        <div class="glass-card">
            <form @submit.prevent="submit" class="space-y-4">
                <!-- 1. Normal TOTP 6-digit mode -->
                <div v-if="!isRecovery" class="glass-input-wrapper">
                    <label for="code" class="glass-label">کد ۶ رقمی یکبار مصرف</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input
                            id="code"
                            ref="codeInput"
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            placeholder="123456"
                            class="glass-input text-center font-mono text-lg font-black tracking-widest"
                            dir="ltr"
                            autofocus
                        />
                    </div>
                    <InputError :message="form.errors.code" />
                </div>

                <!-- 2. Recovery Code Mode -->
                <div v-else class="glass-input-wrapper">
                    <label for="recovery_code" class="glass-label">کد بازیابی اضطراری</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </div>
                        <input
                            id="recovery_code"
                            ref="codeInput"
                            v-model="form.recovery_code"
                            type="text"
                            placeholder="XXXXXX-XXXXXX"
                            class="glass-input text-center font-mono text-sm font-black tracking-wider uppercase"
                            dir="ltr"
                            autofocus
                        />
                    </div>
                    <InputError :message="form.errors.code || form.errors.recovery_code" />
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="glass-btn-primary !w-full !py-3 flex items-center justify-center gap-2"
                >
                    <svg v-if="form.processing" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>تایید و ورود به حساب</span>
                </button>

                <!-- Toggle Mode Button -->
                <div class="pt-2 text-center">
                    <button
                        type="button"
                        @click="toggleRecovery"
                        class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ isRecovery ? 'استفاده از کد ۶ رقمی اپلیکیشن Authenticator' : 'استفاده از کد بازیابی اضطراری (Backup Code)' }}
                    </button>
                </div>

                <!-- Return to Login -->
                <div class="pt-2 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <Link
                        :href="route('login')"
                        class="text-xs text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
                    >
                        بازگشت به صفحه ورود
                    </Link>
                </div>
            </form>
        </div>
    </AuthLayout>
</template>

