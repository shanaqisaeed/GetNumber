<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { __ } from '@/Composables/useTrans';

interface UserIdentityData {
    id: number;
    provider: string;
    provider_label: string;
    provider_id: string;
    provider_email: string | null;
    has_password?: boolean;
    linked_at: string | null;
}

interface Props {
    identities?: UserIdentityData[];
    authSettings?: {
        username_enabled: boolean;
        email_enabled: boolean;
        ldap_enabled: boolean;
        keycloak_enabled: boolean;
    };
    hasPassword?: boolean;
    keycloakLinkUrl?: string;
}

const props = withDefaults(defineProps<Props>(), {
    identities: () => [],
    authSettings: () => ({
        username_enabled: true,
        email_enabled: true,
        ldap_enabled: false,
        keycloak_enabled: false,
    }),
    hasPassword: true,
    keycloakLinkUrl: '',
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

const usernameIdentity = computed(() => props.identities.find(i => i.provider === 'username'));
const emailIdentity = computed(() => props.identities.find(i => i.provider === 'email'));
const ldapIdentity = computed(() => props.identities.find(i => i.provider === 'ldap'));
const keycloakIdentity = computed(() => props.identities.find(i => i.provider === 'keycloak'));

// Visibility based on General Settings & existing connections
const showEmailSection = computed(() => props.authSettings.email_enabled || !!emailIdentity.value || !!user.value?.email);
const showLdapSection = computed(() => props.authSettings.ldap_enabled || !!ldapIdentity.value);
const showKeycloakSection = computed(() => props.authSettings.keycloak_enabled || !!keycloakIdentity.value);

const hasAnyExternalProviderEnabled = computed(() =>
    props.authSettings.email_enabled || props.authSettings.ldap_enabled || props.authSettings.keycloak_enabled
);

// Forms for linking
const emailForm = useForm({
    email: '',
    email_password: '',
    email_password_confirmation: '',
    current_password: '',
});

const isEmailFormOpen = ref(false);

const submitLinkEmail = () => {
    emailForm.post(route('profile.identities.email'), {
        preserveScroll: true,
        onSuccess: () => {
            emailForm.reset();
            isEmailFormOpen.value = false;
        },
    });
};

const ldapForm = useForm({
    ldap_username: '',
    ldap_password: '',
});

const isLdapFormOpen = ref(false);

const submitLinkLdap = () => {
    ldapForm.post(route('profile.identities.ldap'), {
        preserveScroll: true,
        onSuccess: () => {
            ldapForm.reset();
            isLdapFormOpen.value = false;
        },
    });
};

// Dedicated identity password update modal state
const isPasswordModalOpen = ref(false);
const activeProvider = ref<'username' | 'email'>('username');
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const openPasswordModal = (provider: 'username' | 'email') => {
    activeProvider.value = provider;
    passwordForm.reset();
    passwordForm.clearErrors();
    isPasswordModalOpen.value = true;
};

const submitIdentityPassword = () => {
    passwordForm.put(route('profile.identities.password.update', activeProvider.value), {
        preserveScroll: true,
        onSuccess: () => {
            isPasswordModalOpen.value = false;
            passwordForm.reset();
        },
    });
};

// Unlink action
const unlinkingProvider = ref<string | null>(null);

const unlink = (provider: string) => {
    if (!confirm(__('ui.profile.identities.confirm_unlink', { provider }))) {
        return;
    }

    unlinkingProvider.value = provider;
    const form = useForm({});
    form.delete(route('profile.identities.unlink', provider), {
        preserveScroll: true,
        onFinish: () => {
            unlinkingProvider.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-5">
        <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('ui.profile.identities.title') }}</h3>
            <p class="text-xs text-slate-400 mt-0.5">{{ __('ui.profile.identities.subtitle') }}</p>
        </div>

        <!-- Info notice if no external auth is enabled in general settings -->
        <div v-if="!hasAnyExternalProviderEnabled" class="p-3.5 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-500 text-xs flex items-center gap-2">
            <span>{{ __('ui.profile.identities.external_disabled') }}</span>
        </div>

        <div class="space-y-4">
            <!-- 1. Username Identity Card (Always visible) -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('ui.profile.identities.username_title') }}</h4>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">{{ __('ui.profile.identities.connected_primary') }}</span>
                            <span v-if="usernameIdentity?.has_password || hasPassword" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">{{ __('ui.profile.identities.has_active_pass') }}</span>
                        </div>
                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">@{{ user?.username }}</p>
                    </div>
                </div>

                <div>
                    <button
                        type="button"
                        @click="openPasswordModal('username')"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-indigo-600 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/50 dark:hover:bg-indigo-900/50 border border-indigo-200 dark:border-indigo-800 transition-all cursor-pointer"
                    >
                        {{ __('ui.profile.identities.change_pass') }}
                    </button>
                </div>
            </div>

            <!-- 2. Email Identity Card (Only if email_enabled or already connected) -->
            <div v-if="showEmailSection" class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('ui.profile.identities.email_title') }}</h4>
                                <span v-if="emailIdentity || user?.email" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">{{ __('ui.profile.identities.connected') }}</span>
                                <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">{{ __('ui.profile.identities.not_connected') }}</span>

                                <span v-if="emailIdentity?.has_password" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                                    {{ __('ui.profile.identities.dedicated_pass_active') }}
                                </span>

                                <span v-if="!authSettings.email_enabled" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                    {{ __('ui.profile.identities.disabled_in_system') }}
                                </span>
                            </div>
                            <p v-if="user?.email" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">{{ user?.email }}</p>
                            <p v-else class="text-[11px] text-slate-400 mt-0.5">{{ __('ui.profile.identities.email_desc') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            v-if="emailIdentity || user?.email"
                            type="button"
                            @click="openPasswordModal('email')"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/50 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800 transition-all cursor-pointer"
                        >
                            {{ emailIdentity?.has_password ? __('ui.profile.identities.change_email_pass') : __('ui.profile.identities.set_email_pass') }}
                        </button>
                        <button
                            v-if="emailIdentity || user?.email"
                            type="button"
                            @click="unlink('email')"
                            :disabled="unlinkingProvider === 'email'"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                        >{{ __('ui.profile.identities.disconnect') }}</button>
                        <button
                            v-else-if="authSettings.email_enabled"
                            type="button"
                            @click="isEmailFormOpen = !isEmailFormOpen"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/50 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800 transition-all cursor-pointer"
                        >
                            {{ isEmailFormOpen ? __('ui.profile.identities.cancel') : __('ui.profile.identities.connect_email') }}
                        </button>
                    </div>
                </div>

                <!-- Inline Connect Email Form -->
                <form v-if="isEmailFormOpen && !user?.email && authSettings.email_enabled" @submit.prevent="submitLinkEmail" class="p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-3.5">
                    <div class="space-y-1">
                        <label for="link_email" class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.email_label') }}</label>
                        <input
                            id="link_email"
                            type="email"
                            v-model="emailForm.email"
                            placeholder="name@company.com"
                            dir="ltr"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="emailForm.errors.email" />
                    </div>

                    <div v-if="hasPassword" class="space-y-1">
                        <label for="email_current_password" class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.current_pass_label') }}</label>
                        <input
                            id="email_current_password"
                            type="password"
                            v-model="emailForm.current_password"
                            dir="ltr"
                            placeholder="••••••••••••"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="emailForm.errors.current_password" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <div class="space-y-1">
                            <label for="link_email_password" class="block text-[11px] font-bold text-slate-700 dark:text-slate-300">
                                {{ __('ui.profile.identities.email_pass_label') }}
                            </label>
                            <input
                                id="link_email_password"
                                type="password"
                                v-model="emailForm.email_password"
                                :placeholder="__('ui.profile.identities.pass_min_hint')"
                                dir="ltr"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            />
                            <InputError class="mt-1" :message="emailForm.errors.email_password" />
                        </div>
                        <div class="space-y-1">
                            <label for="link_email_password_confirmation" class="block text-[11px] font-bold text-slate-700 dark:text-slate-300">
                                {{ __('ui.profile.identities.email_pass_confirm_label') }}
                            </label>
                            <input
                                id="link_email_password_confirmation"
                                type="password"
                                v-model="emailForm.email_password_confirmation"
                                :placeholder="__('ui.profile.identities.repeat_pass_hint')"
                                dir="ltr"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:text-white"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            @click="isEmailFormOpen = false"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >{{ __('ui.profile.identities.cancel') }}</button>
                        <button
                            type="submit"
                            :disabled="emailForm.processing"
                            class="px-4 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-sm transition-all"
                        >
                            {{ __('ui.profile.identities.submit_email') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- 3. LDAP Directory Identity Card (Only if ldap_enabled or already connected) -->
            <div v-if="showLdapSection" class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h4 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('ui.profile.identities.ldap_title') }}</h4>
                                <span v-if="ldapIdentity" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">{{ __('ui.profile.identities.connected') }}</span>
                                <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">{{ __('ui.profile.identities.not_connected') }}</span>

                                <span v-if="!authSettings.ldap_enabled" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                    {{ __('ui.profile.identities.disabled_in_system') }}
                                </span>
                            </div>
                            <p v-if="ldapIdentity" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">UID: {{ ldapIdentity.provider_id }}</p>
                            <p v-else class="text-[11px] text-slate-400 mt-0.5">{{ __('ui.profile.identities.ldap_desc') }}</p>
                        </div>
                    </div>

                    <div>
                        <button
                            v-if="ldapIdentity"
                            type="button"
                            @click="unlink('ldap')"
                            :disabled="unlinkingProvider === 'ldap'"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                        >{{ __('ui.profile.identities.disconnect') }}</button>
                        <button
                            v-else-if="authSettings.ldap_enabled"
                            type="button"
                            @click="isLdapFormOpen = !isLdapFormOpen"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold text-purple-600 hover:text-purple-700 bg-purple-50 hover:bg-purple-100 dark:bg-purple-950/50 dark:hover:bg-purple-900/50 border border-purple-200 dark:border-purple-800 transition-all cursor-pointer"
                        >
                            {{ isLdapFormOpen ? __('ui.profile.identities.cancel') : __('ui.profile.identities.connect_ldap') }}
                        </button>
                    </div>
                </div>

                <!-- Inline Connect LDAP Form -->
                <form v-if="isLdapFormOpen && !ldapIdentity && authSettings.ldap_enabled" @submit.prevent="submitLinkLdap" class="p-3.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 space-y-3">
                    <div class="space-y-1">
                        <label for="link_ldap_user" class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.ldap_user_label') }}</label>
                        <input
                            id="link_ldap_user"
                            type="text"
                            v-model="ldapForm.ldap_username"
                            :placeholder="__('ui.profile.identities.ldap_user_placeholder')"
                            dir="ltr"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="ldapForm.errors.ldap_username" />
                    </div>

                    <div class="space-y-1">
                        <label for="link_ldap_pass" class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.ldap_pass_label') }}</label>
                        <input
                            id="link_ldap_pass"
                            type="password"
                            v-model="ldapForm.ldap_password"
                            dir="ltr"
                            placeholder="••••••••••••"
                            class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                            required
                        />
                        <InputError class="mt-1" :message="ldapForm.errors.ldap_password" />
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            @click="isLdapFormOpen = false"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >{{ __('ui.profile.identities.cancel') }}</button>
                        <button
                            type="submit"
                            :disabled="ldapForm.processing"
                            class="px-4 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-sm transition-all"
                        >
                            {{ __('ui.profile.identities.submit_ldap') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- 4. Keycloak SSO Identity Card (Only if keycloak_enabled or already connected) -->
            <div v-if="showKeycloakSection" class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('ui.profile.identities.keycloak_title') }}</h4>
                            <span v-if="keycloakIdentity" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">{{ __('ui.profile.identities.connected') }}</span>
                            <span v-else class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">{{ __('ui.profile.identities.not_connected') }}</span>

                            <span v-if="!authSettings.keycloak_enabled" class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                                {{ __('ui.profile.identities.disabled_in_system') }}
                            </span>
                        </div>
                        <p v-if="keycloakIdentity" class="font-mono text-xs text-slate-500 dark:text-slate-400 mt-0.5" dir="ltr">Sub: {{ keycloakIdentity.provider_id.slice(0, 14) }}...</p>
                        <p v-else class="text-[11px] text-slate-400 mt-0.5">{{ __('ui.profile.identities.keycloak_desc') }}</p>
                    </div>
                </div>

                <div>
                    <button
                        v-if="keycloakIdentity"
                        type="button"
                        @click="unlink('keycloak')"
                        :disabled="unlinkingProvider === 'keycloak'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-rose-200 dark:border-rose-900/50 transition-all cursor-pointer"
                    >{{ __('ui.profile.identities.disconnect') }}</button>
                    <a
                        v-else-if="authSettings.keycloak_enabled && keycloakLinkUrl"
                        :href="keycloakLinkUrl"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-300 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/50 dark:hover:bg-amber-900/50 border border-amber-200 dark:border-amber-800 transition-all inline-flex items-center gap-1 cursor-pointer"
                    >
                        <span>{{ __('ui.profile.identities.connect_keycloak') }}</span>
                        <svg class="w-3.5 h-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>

        <!-- Dedicated Identity Password Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="isPasswordModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm" @click.self="isPasswordModalOpen = false">
                    <div class="w-full max-w-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div>
                                <h3 class="text-sm font-black text-slate-900 dark:text-white">
                                    {{ activeProvider === 'email' ? __('ui.profile.identities.modal_email_pass_title') : __('ui.profile.identities.modal_username_pass_title') }}
                                </h3>
                                <p class="text-xs text-slate-400 mt-0.5">
                                    {{ __('ui.profile.identities.modal_pass_subtitle') }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="isPasswordModalOpen = false"
                                class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                            >
                                ✕
                            </button>
                        </div>

                        <form @submit.prevent="submitIdentityPassword" class="space-y-3.5">
                            <div v-if="hasPassword" class="space-y-1">
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.modal_current_pass') }}</label>
                                <input
                                    type="password"
                                    v-model="passwordForm.current_password"
                                    dir="ltr"
                                    placeholder="••••••••••••"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                    required
                                />
                                <InputError class="mt-1" :message="passwordForm.errors.current_password" />
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.modal_new_pass') }}</label>
                                <input
                                    type="password"
                                    v-model="passwordForm.password"
                                    dir="ltr"
                                    :placeholder="__('ui.profile.identities.pass_min_hint')"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                    required
                                />
                                <InputError class="mt-1" :message="passwordForm.errors.password" />
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">{{ __('ui.profile.identities.modal_repeat_pass') }}</label>
                                <input
                                    type="password"
                                    v-model="passwordForm.password_confirmation"
                                    dir="ltr"
                                    :placeholder="__('ui.profile.identities.repeat_pass_hint')"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                    required
                                />
                                <InputError class="mt-1" :message="passwordForm.errors.password_confirmation" />
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    @click="isPasswordModalOpen = false"
                                    class="px-3 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                >{{ __('ui.profile.identities.cancel') }}</button>
                                <button
                                    type="submit"
                                    :disabled="passwordForm.processing"
                                    class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md transition-all disabled:opacity-50 cursor-pointer"
                                >
                                    {{ __('ui.profile.identities.save_pass') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
