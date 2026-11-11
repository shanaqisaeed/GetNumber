<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface GeneralSettings {
    app_name: string;
    app_description: string;
    default_locale?: 'fa' | 'en';
    max_lines_per_user: number;
    event_notify_login_failed: boolean;
    event_notify_user_registered: boolean;
    event_notify_security_alert: boolean;
}

interface AuthSettings {
    auth_username_enabled: boolean;
    auth_email_enabled: boolean;
    auth_ldap_enabled: boolean;
    auth_keycloak_enabled: boolean;
    allow_user_registration: boolean;
}

interface LdapConfig {
    host: string;
    port: number;
    encryption: string;
    base_dn: string;
    bind_dn: string;
    bind_password?: string;
    search_filter: string;
    uid_attribute: string;
    email_attribute: string;
    name_attribute: string;
    auto_create_user: boolean;
}

interface KeycloakConfig {
    base_url: string;
    realm: string;
    client_id: string;
    client_secret?: string;
    auto_create_user: boolean;
}

interface OrgPricing {
    org_price_4_regular: string | number;
    org_price_4_rond: string | number;
    org_price_5_regular: string | number;
    org_price_5_rond: string | number;
    org_price_6_regular: string | number;
    org_price_6_rond: string | number;
    org_price_7_regular: string | number;
    org_price_7_rond: string | number;
    org_price_8_regular: string | number;
    org_price_8_rond: string | number;
}

interface Props {
    initialTab?: 'general' | 'pricing' | 'auth' | 'ldap' | 'keycloak';
    generalSettings: GeneralSettings;
    orgPricing?: OrgPricing;
    authSettings: AuthSettings;
    ldapSettings: {
        config: LdapConfig;
        extensionLoaded: boolean;
        isEnabled: boolean;
    };
    keycloakSettings: {
        config: KeycloakConfig;
        callbackUrl: string;
        isEnabled: boolean;
    };
}

const props = withDefaults(defineProps<Props>(), {
    initialTab: 'general',
});

type TabType = 'general' | 'pricing' | 'auth' | 'ldap' | 'keycloak';
const activeTab = ref<TabType>(props.initialTab);

watch(() => props.initialTab, (newTab) => {
    if (newTab) activeTab.value = newTab;
});

// 1. General Form
const generalForm = useForm({
    app_name: props.generalSettings.app_name,
    app_description: props.generalSettings.app_description,
    default_locale: props.generalSettings.default_locale || 'fa',
    max_lines_per_user: props.generalSettings.max_lines_per_user,
    event_notify_login_failed: props.generalSettings.event_notify_login_failed,
    event_notify_user_registered: props.generalSettings.event_notify_user_registered,
    event_notify_security_alert: props.generalSettings.event_notify_security_alert,
});

const submitGeneral = () => {
    generalForm.post(route('admin.settings.general.update'), {
        preserveScroll: true,
    });
};

// 1.5. Organizational Pricing Matrix Form
const pricingForm = useForm({
    org_price_4_regular: props.orgPricing?.org_price_4_regular ?? '100.0000',
    org_price_4_rond: props.orgPricing?.org_price_4_rond ?? '250.0000',
    org_price_5_regular: props.orgPricing?.org_price_5_regular ?? '50.0000',
    org_price_5_rond: props.orgPricing?.org_price_5_rond ?? '120.0000',
    org_price_6_regular: props.orgPricing?.org_price_6_regular ?? '20.0000',
    org_price_6_rond: props.orgPricing?.org_price_6_rond ?? '50.0000',
    org_price_7_regular: props.orgPricing?.org_price_7_regular ?? '10.0000',
    org_price_7_rond: props.orgPricing?.org_price_7_rond ?? '25.0000',
    org_price_8_regular: props.orgPricing?.org_price_8_regular ?? '5.0000',
    org_price_8_rond: props.orgPricing?.org_price_8_rond ?? '12.0000',
});

const submitPricing = () => {
    pricingForm.post(route('admin.settings.org_pricing.update'), {
        preserveScroll: true,
    });
};

// 2. Auth Providers Form
const authForm = useForm({
    auth_username_enabled: props.authSettings.auth_username_enabled,
    auth_email_enabled: props.authSettings.auth_email_enabled,
    auth_ldap_enabled: props.authSettings.auth_ldap_enabled,
    auth_keycloak_enabled: props.authSettings.auth_keycloak_enabled,
    allow_user_registration: props.authSettings.allow_user_registration,
});

const submitAuth = () => {
    authForm.post(route('admin.settings.auth.update'), {
        preserveScroll: true,
    });
};

// 3. LDAP Form & Testing
const ldapForm = useForm({
    host: props.ldapSettings.config.host,
    port: props.ldapSettings.config.port,
    encryption: props.ldapSettings.config.encryption,
    base_dn: props.ldapSettings.config.base_dn,
    bind_dn: props.ldapSettings.config.bind_dn,
    bind_password: '',
    search_filter: props.ldapSettings.config.search_filter,
    uid_attribute: props.ldapSettings.config.uid_attribute,
    email_attribute: props.ldapSettings.config.email_attribute,
    name_attribute: props.ldapSettings.config.name_attribute,
    auto_create_user: props.ldapSettings.config.auto_create_user,
});

const submitLdap = () => {
    ldapForm.post(route('admin.settings.ldap.update'), {
        preserveScroll: true,
    });
};

const testingLdap = ref(false);
const ldapTestResult = ref<{ success: boolean; message: string } | null>(null);

const testLdapConnection = async () => {
    testingLdap.value = true;
    ldapTestResult.value = null;
    try {
        const res = await fetch(route('admin.settings.ldap.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });
        ldapTestResult.value = await res.json();
    } catch {
        ldapTestResult.value = { success: false, message: __('admin.settings.ldap_ajax_error') };
    } finally {
        testingLdap.value = false;
    }
};

// 4. Keycloak Form & Testing
const keycloakForm = useForm({
    base_url: props.keycloakSettings.config.base_url,
    realm: props.keycloakSettings.config.realm,
    client_id: props.keycloakSettings.config.client_id,
    client_secret: '',
    auto_create_user: props.keycloakSettings.config.auto_create_user,
});

const submitKeycloak = () => {
    keycloakForm.post(route('admin.settings.keycloak.update'), {
        preserveScroll: true,
    });
};

const testingKeycloak = ref(false);
const keycloakTestResult = ref<{ success: boolean; message: string; issuer?: string } | null>(null);

const testKeycloakConnection = async () => {
    testingKeycloak.value = true;
    keycloakTestResult.value = null;
    try {
        const res = await fetch(route('admin.settings.keycloak.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
        });
        keycloakTestResult.value = await res.json();
    } catch {
        keycloakTestResult.value = { success: false, message: __('admin.settings.keycloak_ajax_error') };
    } finally {
        testingKeycloak.value = false;
    }
};
</script>

<template>
    <Head :title="__('admin.settings.head_title')" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-500 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-black text-slate-900 dark:text-white">{{ __('admin.settings.main_title') }}</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.main_desc') }}</p>
                </div>
            </div>
        </template>

        <div class="max-w-5xl mx-auto space-y-6 pb-12">
            <!-- Navigation Tabs -->
            <div class="grid grid-cols-2 sm:grid-cols-5 p-1.5 rounded-2xl bg-slate-200/80 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 gap-1.5">
                <button
                    type="button"
                    @click="activeTab = 'general'"
                    class="py-3 px-4 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    :class="activeTab === 'general'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <span>{{ __('admin.settings.tab_general') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'pricing'"
                    class="py-3 px-4 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    :class="activeTab === 'pricing'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ __('admin.settings.tab_tariffs') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'auth'"
                    class="py-3 px-4 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    :class="activeTab === 'auth'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>{{ __('admin.settings.tab_auth') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'ldap'"
                    class="py-3 px-4 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    :class="activeTab === 'ldap'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <span>{{ __('admin.settings.tab_ldap') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'keycloak'"
                    class="py-3 px-4 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    :class="activeTab === 'keycloak'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span>{{ __('admin.settings.tab_keycloak') }}</span>
                </button>
            </div>

            <!-- TAB 1: GENERAL SETTINGS & LINE QUOTA -->
            <div v-show="activeTab === 'general'">
                <form @submit.prevent="submitGeneral" class="space-y-6">
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                        <div class="flex items-center gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <span class="w-3 h-3 rounded-full bg-indigo-500"></span>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.platform_specs_title') }}</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label for="app_name" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    {{ __('admin.settings.app_name_label') }}
                                </label>
                                <input
                                    id="app_name"
                                    type="text"
                                    v-model="generalForm.app_name"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                    required
                                />
                                <InputError class="mt-1" :message="generalForm.errors.app_name" />
                            </div>

                            <div>
                                <label for="app_description" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    {{ __('admin.settings.app_desc_label') }}
                                </label>
                                <textarea
                                    id="app_description"
                                    v-model="generalForm.app_description"
                                    rows="2"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none dark:text-white"
                                ></textarea>
                                <InputError class="mt-1" :message="generalForm.errors.app_description" />
                            </div>

                            <!-- Language & Direction (RTL / LTR) Configuration -->
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-2">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <div>
                                        <label for="default_locale" class="block text-xs font-black text-slate-900 dark:text-white">
                                            {{ __('admin.settings.default_lang_label') }}
                                        </label>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                            {{ __('admin.settings.default_lang_help') }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <select
                                            id="default_locale"
                                            v-model="generalForm.default_locale"
                                            class="px-3.5 py-2 text-xs font-bold rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 outline-none cursor-pointer"
                                        >
                                            <option value="fa">{{ __('admin.settings.lang_fa') }}</option>
                                            <option value="en">{{ __('admin.settings.lang_en') }}</option>
                                        </select>
                                        <span
                                            class="px-2 py-1 rounded-lg text-[10px] font-mono font-black border"
                                            :class="generalForm.default_locale === 'fa' ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20' : 'bg-blue-500/10 text-blue-600 border-blue-500/20'"
                                        >
                                            {{ generalForm.default_locale === 'fa' ? 'RTL' : 'LTR' }}
                                        </span>
                                    </div>
                                </div>
                                <InputError class="mt-1" :message="generalForm.errors.default_locale" />
                            </div>

                            <!-- Maximum Lines Quota Field -->
                            <div class="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/20 border border-indigo-200/80 dark:border-indigo-900/40 space-y-2">
                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                    <div>
                                        <label for="max_lines_per_user" class="block text-xs font-black text-indigo-950 dark:text-indigo-200">
                                            {{ __('admin.settings.max_lines_label') }}
                                        </label>
                                        <p class="text-[11px] text-indigo-700/80 dark:text-indigo-300/80 mt-0.5">
                                            {{ __('admin.settings.max_lines_help') }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input
                                            id="max_lines_per_user"
                                            type="number"
                                            min="1"
                                            max="1000"
                                            v-model.number="generalForm.max_lines_per_user"
                                            class="w-24 px-3 py-2 text-center font-mono font-black text-sm rounded-xl border border-indigo-300 dark:border-indigo-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 outline-none"
                                            required
                                        />
                                        <span class="text-xs font-bold text-indigo-900 dark:text-indigo-300">{{ __('admin.settings.unit_lines') }}</span>
                                    </div>
                                </div>
                                <InputError class="mt-1" :message="generalForm.errors.max_lines_per_user" />
                            </div>
                        </div>
                    </div>

                    <!-- Events & Audit Notifications Card -->
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                        <div class="flex items-center gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.security_events_title') }}</h2>
                        </div>

                        <div class="space-y-4">
                            <label class="flex items-start gap-3 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="generalForm.event_notify_login_failed"
                                    class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white block">{{ __('admin.settings.evt_failed_login') }}</span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ __('admin.settings.evt_failed_login_desc') }}</span>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="generalForm.event_notify_user_registered"
                                    class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white block">{{ __('admin.settings.evt_new_user') }}</span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ __('admin.settings.evt_new_user_desc') }}</span>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="generalForm.event_notify_security_alert"
                                    class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <div>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white block">{{ __('admin.settings.evt_security_changes') }}</span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ __('admin.settings.evt_security_changes_desc') }}</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="generalForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                        >
                            {{ generalForm.processing ? __('admin.settings.saving') : __('admin.settings.save_general') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB: ORGANIZATIONAL 4-8 DIGIT PRICING MATRIX -->
            <div v-show="activeTab === 'pricing'">
                <form @submit.prevent="submitPricing" class="space-y-6">
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.tariffs_table_title') }}</h2>
                            </div>
                            <span class="text-xs text-slate-500">{{ __('admin.settings.tariffs_table_sub') }}</span>
                        </div>

                        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('admin.settings.tariffs_table_desc') }}
                        </p>

                        <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                            <table class="w-full text-right text-xs">
                                <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-700 dark:text-slate-300 font-bold border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="p-3.5">{{ __('admin.settings.th_prefix_length') }}</th>
                                        <th class="p-3.5">{{ __('admin.settings.th_regular_tariff') }}</th>
                                        <th class="p-3.5">{{ __('admin.settings.th_rond_tariff') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <!-- 4 Digits -->
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="p-3.5 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-xs">4</span>
                                            <span>{{ __('admin.settings.prefix_4_desc') }}</span>
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_4_regular" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold" required />
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_4_rond" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold text-amber-600" required />
                                        </td>
                                    </tr>

                                    <!-- 5 Digits -->
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="p-3.5 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-xs">5</span>
                                            <span>{{ __('admin.settings.prefix_5_desc') }}</span>
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_5_regular" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold" required />
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_5_rond" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold text-amber-600" required />
                                        </td>
                                    </tr>

                                    <!-- 6 Digits -->
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="p-3.5 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-xs">6</span>
                                            <span>{{ __('admin.settings.prefix_6_desc') }}</span>
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_6_regular" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold" required />
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_6_rond" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold text-amber-600" required />
                                        </td>
                                    </tr>

                                    <!-- 7 Digits -->
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="p-3.5 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-xs">7</span>
                                            <span>{{ __('admin.settings.prefix_7_desc') }}</span>
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_7_regular" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold" required />
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_7_rond" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold text-amber-600" required />
                                        </td>
                                    </tr>

                                    <!-- 8 Digits -->
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="p-3.5 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-xs">8</span>
                                            <span>{{ __('admin.settings.prefix_8_desc') }}</span>
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_8_regular" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold" required />
                                        </td>
                                        <td class="p-3.5">
                                            <input v-model="pricingForm.org_price_8_rond" type="number" step="0.0001" dir="ltr" class="form-input w-40 font-mono text-center font-bold text-amber-600" required />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="pricingForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                        >
                            {{ pricingForm.processing ? __('admin.settings.saving_tariffs') : __('admin.settings.save_tariffs') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: AUTH PROVIDERS TOGGLES -->
            <div v-show="activeTab === 'auth'">
                <form @submit.prevent="submitAuth" class="space-y-6">
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.auth_title') }}</h2>
                            </div>
                            <span class="text-xs text-slate-500">{{ __('admin.settings.auth_desc') }}</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Username -->
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 flex items-center justify-between gap-4">
                                <div>
                                    <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.login_username_title') }}</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.login_username_desc') }}</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="authForm.auth_username_enabled" class="sr-only peer" />
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- Email -->
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 flex items-center justify-between gap-4">
                                <div>
                                    <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.login_email_title') }}</h3>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.login_email_desc') }}</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="authForm.auth_email_enabled" class="sr-only peer" />
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>

                            <!-- LDAP -->
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 flex items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.auth_ldap_title') }}</h3>
                                        <span v-if="!ldapSettings.config.host" class="text-[10px] text-amber-600 dark:text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded">{{ __('admin.settings.not_configured_badge') }}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.auth_ldap_desc') }}</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="authForm.auth_ldap_enabled" class="sr-only peer" />
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                                </label>
                            </div>

                            <!-- Keycloak -->
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 flex items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.auth_keycloak_title') }}</h3>
                                        <span v-if="!keycloakSettings.config.client_id" class="text-[10px] text-amber-600 dark:text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded">{{ __('admin.settings.not_configured_badge') }}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.auth_keycloak_desc') }}</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" v-model="authForm.auth_keycloak_enabled" class="sr-only peer" />
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600"></div>
                                </label>
                            </div>
                        </div>

                        <!-- Registration Toggle -->
                        <div class="p-4 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white">{{ __('admin.settings.registration_open_title') }}</h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">{{ __('admin.settings.registration_open_desc') }}</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="authForm.allow_user_registration" class="sr-only peer" />
                                <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                            </label>
                        </div>

                        <InputError :message="authForm.errors.auth_username_enabled" />
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="authForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                        >
                            {{ authForm.processing ? __('admin.settings.saving') : __('admin.settings.save_gateways_status') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 3: LDAP CONFIGURATION -->
            <div v-show="activeTab === 'ldap'">
                <div class="space-y-6">
                    <!-- Extension notice if missing -->
                    <div v-if="!ldapSettings.extensionLoaded" class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-xs flex items-center gap-2">
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <span v-html="__('admin.settings.ldap_ext_warning_desc', { ext: 'php_ldap' })"></span>
                    </div>

                    <form @submit.prevent="submitLdap" class="space-y-6">
                        <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                            <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-3">
                                    <span class="w-3 h-3 rounded-full bg-purple-500"></span>
                                    <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.ldap_title') }}</h2>
                                </div>
                                <span class="text-xs px-2.5 py-1 rounded-lg" :class="ldapSettings.isEnabled ? 'bg-emerald-500/10 text-emerald-600 font-bold' : 'bg-slate-200 dark:bg-slate-800 text-slate-500'">
                                    {{ ldapSettings.isEnabled ? __('admin.settings.gateway_active') : __('admin.settings.gateway_inactive') }}
                                </span>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="md:col-span-2">
                                    <label for="ldap_host" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.host_label') }}</label>
                                    <input
                                        id="ldap_host"
                                        type="text"
                                        v-model="ldapForm.host"
                                        placeholder="ldap.company.local"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                    <InputError class="mt-1" :message="ldapForm.errors.host" />
                                </div>

                                <div>
                                    <label for="ldap_port" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.port_label') }}</label>
                                    <input
                                        id="ldap_port"
                                        type="number"
                                        v-model.number="ldapForm.port"
                                        placeholder="389"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                    <InputError class="mt-1" :message="ldapForm.errors.port" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="ldap_encryption" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.encryption_label') }}</label>
                                    <select
                                        id="ldap_encryption"
                                        v-model="ldapForm.encryption"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                    >
                                        <option value="none">{{ __('admin.settings.enc_none') }}</option>
                                        <option value="tls">{{ __('admin.settings.enc_tls') }}</option>
                                        <option value="ssl">{{ __('admin.settings.enc_ssl') }}</option>
                                    </select>
                                    <InputError class="mt-1" :message="ldapForm.errors.encryption" />
                                </div>

                                <div>
                                    <label for="ldap_base_dn" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.base_dn_label') }}</label>
                                    <input
                                        id="ldap_base_dn"
                                        type="text"
                                        v-model="ldapForm.base_dn"
                                        placeholder="dc=company,dc=local"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                    <InputError class="mt-1" :message="ldapForm.errors.base_dn" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="ldap_bind_dn" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.bind_dn_label') }}</label>
                                    <input
                                        id="ldap_bind_dn"
                                        type="text"
                                        v-model="ldapForm.bind_dn"
                                        placeholder="cn=admin,dc=company,dc=local"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                    />
                                    <InputError class="mt-1" :message="ldapForm.errors.bind_dn" />
                                </div>

                                <div>
                                    <label for="ldap_bind_password" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.bind_password_label') }}</label>
                                    <input
                                        id="ldap_bind_password"
                                        type="password"
                                        v-model="ldapForm.bind_password"
                                        :placeholder="__('admin.settings.leave_blank_if_unchanged')"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                    />
                                    <InputError class="mt-1" :message="ldapForm.errors.bind_password" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="ldap_search_filter" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.search_filter_label') }}</label>
                                    <input
                                        id="ldap_search_filter"
                                        type="text"
                                        v-model="ldapForm.search_filter"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                </div>

                                <div>
                                    <label for="ldap_uid_attribute" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.uid_attr') }}</label>
                                    <input
                                        id="ldap_uid_attribute"
                                        type="text"
                                        v-model="ldapForm.uid_attribute"
                                        :placeholder="__('admin.settings.uid_placeholder')"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                </div>

                                <div>
                                    <label for="ldap_email_attribute" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.email_attr') }}</label>
                                    <input
                                        id="ldap_email_attribute"
                                        type="text"
                                        v-model="ldapForm.email_attribute"
                                        placeholder="mail"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-purple-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                </div>
                            </div>

                            <label class="flex items-center gap-3 pt-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="ldapForm.auto_create_user"
                                    class="rounded border-slate-300 text-purple-600 focus:ring-purple-500"
                                />
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                    {{ __('admin.settings.jit_desc') }}
                                </span>
                            </label>
                        </div>

                        <!-- Actions & Test Result -->
                        <div class="flex items-center justify-between gap-4 flex-wrap">
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    @click="testLdapConnection"
                                    :disabled="testingLdap"
                                    class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-xs border border-slate-300 dark:border-slate-700 transition-all cursor-pointer"
                                >
                                    {{ testingLdap ? __('admin.settings.testing_conn') : __('admin.settings.test_connection') }}
                                </button>

                                <span v-if="ldapTestResult" class="text-xs font-bold flex items-center gap-1.5" :class="ldapTestResult.success ? 'text-emerald-600' : 'text-rose-500'">
                                    <span>{{ ldapTestResult.success ? '✓' : '✕' }}</span>
                                    <span>{{ ldapTestResult.message }}</span>
                                </span>
                            </div>

                            <button
                                type="submit"
                                :disabled="ldapForm.processing"
                                class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                            >
                                {{ ldapForm.processing ? __('admin.settings.saving') : __('admin.settings.save_ldap') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 4: KEYCLOAK SSO CONFIGURATION -->
            <div v-show="activeTab === 'keycloak'">
                <form @submit.prevent="submitKeycloak" class="space-y-6">
                    <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                                <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ __('admin.settings.keycloak_title') }}</h2>
                            </div>
                            <span class="text-xs px-2.5 py-1 rounded-lg" :class="keycloakSettings.isEnabled ? 'bg-emerald-500/10 text-emerald-600 font-bold' : 'bg-slate-200 dark:bg-slate-800 text-slate-500'">
                                {{ keycloakSettings.isEnabled ? __('admin.settings.gateway_active') : __('admin.settings.gateway_inactive') }}
                            </span>
                        </div>

                        <!-- Callback URL box -->
                        <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-1">
                            <span class="font-bold text-amber-800 dark:text-amber-300 block">{{ __('admin.settings.redirect_title') }}</span>
                            <p class="text-[11px] text-slate-600 dark:text-slate-400">{{ __('admin.settings.redirect_desc') }}</p>
                            <div class="p-2.5 rounded-xl bg-white dark:bg-slate-950 font-mono text-[11px] text-indigo-600 dark:text-indigo-400 border border-slate-200 dark:border-slate-800 break-all select-all" dir="ltr">
                                {{ keycloakSettings.callbackUrl }}
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label for="keycloak_base_url" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.keycloak_url_label') }}</label>
                                <input
                                    id="keycloak_base_url"
                                    type="url"
                                    v-model="keycloakForm.base_url"
                                    placeholder="https://sso.company.com"
                                    dir="ltr"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                    required
                                />
                                <InputError class="mt-1" :message="keycloakForm.errors.base_url" />
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="keycloak_realm" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.realm_name_label') }}</label>
                                    <input
                                        id="keycloak_realm"
                                        type="text"
                                        v-model="keycloakForm.realm"
                                        :placeholder="__('admin.settings.realm_name_label')"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                    <InputError class="mt-1" :message="keycloakForm.errors.realm" />
                                </div>

                                <div>
                                    <label for="keycloak_client_id" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.client_id_label') }}</label>
                                    <input
                                        id="keycloak_client_id"
                                        type="text"
                                        v-model="keycloakForm.client_id"
                                        placeholder="getnumber-portal"
                                        dir="ltr"
                                        class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                        required
                                    />
                                    <InputError class="mt-1" :message="keycloakForm.errors.client_id" />
                                </div>
                            </div>

                            <div>
                                <label for="keycloak_client_secret" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.settings.client_secret_label') }}</label>
                                <input
                                    id="keycloak_client_secret"
                                    type="password"
                                    v-model="keycloakForm.client_secret"
                                    :placeholder="__('admin.settings.leave_blank_if_unchanged')"
                                    dir="ltr"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:ring-2 focus:ring-amber-500 focus:outline-none dark:text-white"
                                />
                                <InputError class="mt-1" :message="keycloakForm.errors.client_secret" />
                            </div>

                            <label class="flex items-center gap-3 pt-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="keycloakForm.auto_create_user"
                                    class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                />
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                    {{ __('admin.settings.keycloak_jit_desc') }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Actions & Test Result -->
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                @click="testKeycloakConnection"
                                :disabled="testingKeycloak"
                                class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-xs border border-slate-300 dark:border-slate-700 transition-all cursor-pointer"
                            >
                                {{ testingKeycloak ? __('admin.settings.testing_conn') : __('admin.settings.test_connection') }}
                            </button>

                            <span v-if="keycloakTestResult" class="text-xs font-bold flex items-center gap-1.5" :class="keycloakTestResult.success ? 'text-emerald-600' : 'text-rose-500'">
                                <span>{{ keycloakTestResult.success ? '✓' : '✕' }}</span>
                                <span>{{ keycloakTestResult.message }}</span>
                            </span>
                        </div>

                        <button
                            type="submit"
                            :disabled="keycloakForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer"
                        >
                            {{ keycloakForm.processing ? __('admin.settings.saving') : __('admin.settings.save_keycloak') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
