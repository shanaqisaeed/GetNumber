<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status?: string;
    email?: string;
}>();

const form = useForm({});

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout>
        <Head :title="__('ui.auth.verify_email.head_title')" />

        <div class="text-center mb-6">
            <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-pink-600 flex items-center justify-center text-white shadow-xl shadow-indigo-500/25">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                    <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
            </div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white tracking-tight">
                {{ __('ui.auth.verify_email.title') }}
            </h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto leading-relaxed">
                {{ __('ui.auth.verify_email.desc') }}
            </p>

            <div v-if="email" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                </svg>
                <span>{{ email }}</span>
            </div>
        </div>

        <div class="glass-card space-y-4">
            <div
                v-if="verificationLinkSent"
                class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-700 dark:text-emerald-400 text-xs font-bold flex items-center gap-2"
            >
                <svg class="w-4 h-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ __('ui.auth.verify_email.link_sent') }}</span>
            </div>

            <form @submit.prevent="submit" class="space-y-3">
                <button
                    type="submit"
                    class="glass-btn-primary w-full !py-3 !rounded-xl !text-xs font-bold"
                    :disabled="form.processing"
                >
                    <svg v-if="form.processing" class="w-4 h-4 animate-spin inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span>{{ __('ui.auth.verify_email.resend_btn') }}</span>
                </button>
            </form>

            <div class="pt-2 border-t border-slate-200/80 dark:border-white/10 flex items-center justify-between text-xs">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-rose-600 dark:text-rose-400 font-bold hover:underline"
                >
                    {{ __('ui.auth.verify_email.logout_btn') }}
                </Link>

                <Link
                    :href="route('welcome')"
                    class="text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 font-medium"
                >
                    {{ __('ui.auth.verify_email.back_to_home') }}
                </Link>
            </div>
        </div>
    </AuthLayout>
</template>

