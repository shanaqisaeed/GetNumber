<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    status?: string;
}>();

const showPassword = ref(false);

const form = useForm({
    username: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="ورود به سامانه" />

        <!-- Header Badge & Titles matching demo.html -->
        <div class="text-center mb-5">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">ورود به درگاه</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">سامانه هوشمند مدیریت خطوط و پیام‌ها راوی نو</p>
        </div>

        <!-- Glass Card Form Container -->
        <div class="glass-card">
            <!-- Status Alert -->
            <div v-if="status" class="mb-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 p-3 rounded-xl border border-emerald-500/20 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ status }}</span>
            </div>

            <form @submit.prevent="submit">
                <!-- Username Input Wrapper -->
                <div class="glass-input-wrapper">
                    <label for="username" class="glass-label">شناسه کاربری</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <input
                            id="username"
                            type="text"
                            v-model="form.username"
                            class="glass-input"
                            placeholder="شناسه کاربری..."
                            required
                            autofocus
                            dir="ltr"
                            autocomplete="username"
                        />
                    </div>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.username" />
                </div>

                <!-- Password Input Wrapper -->
                <div class="glass-input-wrapper">
                    <label for="password" class="glass-label">کلمه عبور</label>
                    <div class="glass-input-box">
                        <div class="glass-input-icon">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </div>
                        <input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password"
                            class="glass-input"
                            placeholder="••••••••••••"
                            required
                            dir="ltr"
                            autocomplete="current-password"
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="pl-3 pr-1 text-slate-400 hover:text-indigo-400 transition-colors focus:outline-none"
                            title="تغییر وضعیت نمایش رمز"
                        >
                            <svg v-if="!showPassword" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.password" />
                </div>

                <!-- Remember Me -->
                <div class="flex items-center justify-between my-3 px-1">
                    <label class="flex items-center gap-2 cursor-pointer select-none text-xs text-slate-600 dark:text-slate-400">
                        <input
                            type="checkbox"
                            v-model="form.remember"
                            class="w-3.5 h-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                        />
                        <span>مرا به خاطر بسپار</span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="glass-btn-primary mt-2"
                >
                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>ورود به سامانه</span>
                    <svg v-if="!form.processing" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Registration Footer Link -->
            <div class="text-center mt-5 pt-3.5 border-t border-slate-200/50 dark:border-white/10 text-xs text-slate-500 dark:text-slate-400">
                <span>حساب کاربری ندارید؟</span>
                <Link
                    :href="route('register')"
                    class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline mr-1.5"
                >
                    ثبت‌نام متقاضی جدید
                </Link>
            </div>
        </div>
    </AuthLayout>
</template>
