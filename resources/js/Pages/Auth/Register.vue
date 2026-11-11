<script setup lang="ts">
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Props {
    allowRegistration?: boolean;
    enabledProviders?: {
        username: boolean;
        email: boolean;
        ldap: boolean;
        keycloak: boolean;
    };
    keycloakLoginUrl?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    allowRegistration: true,
    enabledProviders: () => ({
        username: true,
        email: true,
        ldap: false,
        keycloak: false,
    }),
    keycloakLoginUrl: null,
});

type RegistrationType = 'username' | 'email';
const selectedMethod = ref<RegistrationType>(props.enabledProviders.username ? 'username' : 'email');
const showPassword = ref(false);

const form = useForm({
    registration_type: selectedMethod.value,
    name: '',
    username: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const selectMethod = (method: RegistrationType) => {
    selectedMethod.value = method;
    form.registration_type = method;
    form.clearErrors();
};

const submit = () => {
    form.registration_type = selectedMethod.value;
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head :title="__('ui.auth.register.head_title')" />

        <!-- Header Brand & Title -->
        <div class="text-center mb-6">
            <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                    <line x1="20" y1="8" x2="20" y2="14"></line>
                    <line x1="23" y1="11" x2="17" y2="11"></line>
                </svg>
            </div>
            <h1 class="text-2xl font-black text-slate-900 dark:text-white tracking-tight">{{ __('ui.auth.register.title') }}</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ __('ui.auth.register.subtitle') }}</p>
        </div>

        <!-- Registration Method Selector -->
        <div class="mb-5">
            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                {{ __('ui.auth.register.method_prompt') }}
            </label>
            <div class="grid grid-cols-2 gap-2 p-1.5 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 text-xs font-bold">
                <button
                    v-if="enabledProviders.username"
                    type="button"
                    @click="selectMethod('username')"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-2 cursor-pointer text-center"
                    :class="selectedMethod === 'username'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>{{ __('ui.auth.register.method_username') }}</span>
                </button>

                <button
                    v-if="enabledProviders.email"
                    type="button"
                    @click="selectMethod('email')"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-2 cursor-pointer text-center"
                    :class="selectedMethod === 'email'
                        ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm border border-slate-200/60 dark:border-white/10'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>{{ __('ui.auth.register.method_email') }}</span>
                </button>
            </div>
        </div>

        <!-- Enterprise SSO/LDAP Hint Box -->
        <div v-if="enabledProviders.ldap || enabledProviders.keycloak" class="mb-5 p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-800 dark:text-amber-300 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ __('ui.auth.register.org_notice') }}</span>
            </div>
            <Link :href="route('login')" class="font-bold underline shrink-0 hover:text-amber-600">
                {{ __('ui.auth.register.org_login') }}
            </Link>
        </div>

        <!-- Registration Form -->
        <form @submit.prevent="submit" class="space-y-4">
            <!-- Full Name (Mandatory) -->
            <div class="space-y-1.5">
                <label for="name" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    {{ __('ui.auth.register.name_label') }}
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <input
                        id="name"
                        type="text"
                        v-model="form.name"
                        class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                        :placeholder="__('ui.auth.register.name_placeholder')"
                        required
                        autofocus
                        autocomplete="name"
                    />
                </div>
                <InputError class="mt-1" :message="form.errors.name" />
            </div>

            <!-- Option A: Username (Mandatory if chosen) -->
            <div v-if="selectedMethod === 'username'" class="space-y-1.5">
                <label for="username" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    {{ __('ui.auth.register.username_label') }}
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                    </div>
                    <input
                        id="username"
                        type="text"
                        v-model="form.username"
                        class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white font-mono"
                        placeholder="e.g. morteza_sh"
                        required
                        dir="ltr"
                        autocomplete="username"
                    />
                </div>
                <span class="text-[10px] text-slate-400 block">{{ __('ui.auth.register.username_hint') }}</span>
                <InputError class="mt-1" :message="form.errors.username" />
            </div>

            <!-- Option B: Email (Mandatory if chosen) -->
            <div v-if="selectedMethod === 'email'" class="space-y-1.5">
                <label for="email" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                    {{ __('ui.auth.register.email_label') }}
                </label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <input
                        id="email"
                        type="email"
                        v-model="form.email"
                        class="w-full pr-10 pl-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white font-mono"
                        placeholder="you@company.com"
                        required
                        dir="ltr"
                        autocomplete="email"
                    />
                </div>
                <InputError class="mt-1" :message="form.errors.email" />
            </div>

            <!-- Password and Confirmation (Mandatory) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label for="password" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                        {{ __('ui.auth.register.password_label') }}
                    </label>
                    <div class="relative">
                        <input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                            :placeholder="__('ui.auth.register.password_placeholder')"
                            required
                            dir="ltr"
                            autocomplete="new-password"
                        />
                    </div>
                    <InputError class="mt-1" :message="form.errors.password" />
                </div>

                <div class="space-y-1.5">
                    <label for="password_confirmation" class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                        {{ __('ui.auth.register.password_confirmation_label') }}
                    </label>
                    <div class="relative">
                        <input
                            id="password_confirmation"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password_confirmation"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/80 text-sm font-medium focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                            :placeholder="__('ui.auth.register.password_confirmation_placeholder')"
                            required
                            dir="ltr"
                            autocomplete="new-password"
                        />
                    </div>
                    <InputError class="mt-1" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <!-- Submit Button -->
            <div class="pt-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-3 rounded-2xl bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white font-black text-sm shadow-md shadow-indigo-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50"
                >
                    <span v-if="form.processing">{{ __('ui.auth.register.processing') }}</span>
                    <template v-else>
                        <span>{{ __('ui.auth.register.submit_button') }}</span>
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </template>
                </button>
            </div>
        </form>

        <!-- Footer Link: Already registered -->
        <div class="mt-6 pt-5 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between text-xs">
            <span class="text-slate-500 dark:text-slate-400">{{ __('ui.auth.register.has_account') }}</span>
            <Link :href="route('login')" class="font-bold text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('ui.auth.register.login_link') }}
            </Link>
        </div>
    </AuthLayout>
</template>
