<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head title="تأیید کلمه عبور" />

        <div class="text-center mb-5">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-amber-500 to-rose-600 flex items-center justify-center text-white shadow-lg shadow-amber-500/30">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">تأیید هویت امنیتی</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">جهت دسترسی به این بخش حساس، لطفاً کلمه عبور خود را تأیید فرمایید.</p>
        </div>

        <div class="glass-card">
            <form @submit.prevent="submit">
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
                            type="password"
                            v-model="form.password"
                            class="glass-input"
                            placeholder="••••••••••••"
                            required
                            autofocus
                            dir="ltr"
                            autocomplete="current-password"
                        />
                    </div>
                    <InputError class="mt-1.5 text-xs text-rose-500" :message="form.errors.password" />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="glass-btn-primary mt-3"
                >
                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>تأیید و ادامه</span>
                </button>
            </form>
        </div>
    </AuthLayout>
</template>
