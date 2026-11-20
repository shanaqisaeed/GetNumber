<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

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

interface SmtpSettings {
    mail_mailer: string;
    mail_host: string;
    mail_port: number;
    mail_username: string;
    mail_password?: string;
    mail_encryption: 'tls' | 'ssl' | 'none';
    mail_from_address: string;
    mail_from_name: string;
    require_email_verification: boolean;
}

interface VoiceBridgeSettings {
    host: string;
    api_port: number;
    ws_port: number;
    ws_public_url?: string;
    secret?: string;
    enabled: boolean;
    turn_domain_or_ip?: string;
    turn_port?: number;
    turns_port?: number;
    turns_enabled?: boolean;
    offline_mode?: boolean;
    stun_urls?: string;
    turn_urls?: string;
    turn_username?: string;
    turn_credential?: string;
    turn_secret?: string;
    ice_transport_policy?: 'all' | 'relay' | string;
}

interface Props {
    initialTab?: string;
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
    smtpSettings?: SmtpSettings;
    voiceBridgeSettings?: VoiceBridgeSettings;
}

const props = withDefaults(defineProps<Props>(), {
    initialTab: 'general',
});

const { __ } = useTrans();

// 4 Standard Main Tabs:
// 1. 'general' (General Settings & Email)
// 2. 'security' (Access Limits, Auth Providers, LDAP, Keycloak)
// 3. 'tariffs' (Base Tariffs & Payment Gateways)
// 4. 'bridge' (Go Voice Bridge Server)
type MainTab = 'general' | 'security' | 'tariffs' | 'bridge';

const determineMainTab = (tab?: string): MainTab => {
    if (tab === 'bridge') return 'bridge';
    if (tab === 'auth' || tab === 'ldap' || tab === 'keycloak') return 'security';
    if (tab === 'pricing') return 'tariffs';
    return 'general';
};

const activeTab = ref<MainTab>(determineMainTab(props.initialTab));
const securitySubTab = ref<'auth' | 'ldap' | 'keycloak'>('auth');

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

// 2. Pricing Matrix Form
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

// 3. Auth Providers Form
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

// 4. LDAP Form & Testing
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
        ldapTestResult.value = { success: false, message: __('admin.settings.general.ldap_conn_error') };
    } finally {
        testingLdap.value = false;
    }
};

// 5. Keycloak Form & Testing
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
const keycloakTestResult = ref<{ success: boolean; message: string } | null>(null);

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
        keycloakTestResult.value = { success: false, message: __('admin.settings.general.keycloak_conn_error') };
    } finally {
        testingKeycloak.value = false;
    }
};

// 6. SMTP Form & Testing
const smtpForm = useForm({
    mail_host: props.smtpSettings?.mail_host || '127.0.0.1',
    mail_port: props.smtpSettings?.mail_port || 587,
    mail_encryption: props.smtpSettings?.mail_encryption || 'tls',
    mail_username: props.smtpSettings?.mail_username || '',
    mail_password: '',
    mail_from_address: props.smtpSettings?.mail_from_address || 'noreply@getnumber.local',
    mail_from_name: props.smtpSettings?.mail_from_name || 'GetNumber',
    require_email_verification: props.smtpSettings?.require_email_verification ?? true,
});

const submitSmtp = () => {
    smtpForm.post(route('admin.settings.smtp.update'), {
        preserveScroll: true,
    });
};

const testingSmtp = ref(false);
const testRecipient = ref('');
const smtpTestResult = ref<{ success: boolean; message?: string; error?: string } | null>(null);

const testSmtpConnection = async () => {
    testingSmtp.value = true;
    smtpTestResult.value = null;
    try {
        const res = await fetch(route('admin.settings.smtp.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                recipient: testRecipient.value || undefined,
            }),
        });
        smtpTestResult.value = await res.json();
    } catch {
        smtpTestResult.value = { success: false, error: __('admin.settings.general.smtp_network_error') };
    } finally {
        testingSmtp.value = false;
    }
};

// 7. Voice Bridge Form & Testing
const showBridgeSecret = ref(false);
const showTurnCredential = ref(false);
const bridgeForm = useForm({
    host: props.voiceBridgeSettings?.host || '127.0.0.1',
    api_port: props.voiceBridgeSettings?.api_port || 49000,
    ws_port: props.voiceBridgeSettings?.ws_port || 49001,
    ws_public_url: props.voiceBridgeSettings?.ws_public_url || '',
    secret: props.voiceBridgeSettings?.secret || '',
    enabled: props.voiceBridgeSettings?.enabled ?? true,
    turn_domain_or_ip: props.voiceBridgeSettings?.turn_domain_or_ip || '',
    turn_port: props.voiceBridgeSettings?.turn_port || 3478,
    turns_port: props.voiceBridgeSettings?.turns_port || 5349,
    turns_enabled: props.voiceBridgeSettings?.turns_enabled ?? false,
    offline_mode: props.voiceBridgeSettings?.offline_mode ?? false,
    stun_urls: props.voiceBridgeSettings?.stun_urls ?? '',
    turn_urls: props.voiceBridgeSettings?.turn_urls || '',
    turn_username: props.voiceBridgeSettings?.turn_username || '',
    turn_credential: props.voiceBridgeSettings?.turn_credential || '',
    turn_secret: props.voiceBridgeSettings?.turn_secret || '',
    ice_transport_policy: props.voiceBridgeSettings?.ice_transport_policy || 'all',
});

const bridgeSubTab = ref<'connection' | 'relay' | 'guide'>('connection');

const applyGoogleStunPreset = () => {
    bridgeForm.stun_urls = "stun:stun.l.google.com:19302\nstun:stun1.l.google.com:19302\nstun:stun2.l.google.com:19302";
    bridgeForm.turn_urls = '';
    bridgeForm.turn_domain_or_ip = '';
    bridgeForm.turn_secret = '';
    bridgeForm.turn_username = '';
    bridgeForm.turn_credential = '';
    bridgeForm.turns_enabled = false;
    bridgeForm.offline_mode = false;
};

const applyCloudflarePreset = () => {
    bridgeForm.stun_urls = "stun:stun.cloudflare.com:3478\nstun:stun.l.google.com:19302";
    bridgeForm.turn_urls = '';
    bridgeForm.turn_domain_or_ip = '';
    bridgeForm.turn_secret = '';
    bridgeForm.turn_username = '';
    bridgeForm.turn_credential = '';
    bridgeForm.turns_enabled = false;
    bridgeForm.offline_mode = false;
};

const applyPrivatePreset = () => {
    const host = (bridgeForm.host && bridgeForm.host !== '0.0.0.0' && bridgeForm.host !== '127.0.0.1')
        ? bridgeForm.host
        : (typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1');
    bridgeForm.turn_domain_or_ip = host;
    bridgeForm.turn_port = 3478;
    bridgeForm.turns_port = 5349;
    bridgeForm.turns_enabled = true;
    bridgeForm.stun_urls = `stun:${host}:3478\nstun:stun.cloudflare.com:3478\nstun:stun.l.google.com:19302`;
    bridgeForm.turn_urls = `turn:${host}:3478?transport=udp\nturn:${host}:3478?transport=tcp\nturns:${host}:5349?transport=tcp`;
    bridgeForm.offline_mode = false;
    if (!bridgeForm.turn_secret && bridgeForm.secret) {
        bridgeForm.turn_secret = bridgeForm.secret;
    }
};

const copyBridgeSecretToTurn = () => {
    if (bridgeForm.secret) {
        bridgeForm.turn_secret = bridgeForm.secret;
    }
};

const submitVoiceBridge = () => {
    bridgeForm.post(route('admin.settings.voice_bridge.update'), {
        preserveScroll: true,
    });
};

const testingBridge = ref(false);
const bridgeTestResult = ref<{ success: boolean; message: string; data?: any; latency_ms?: number; auth_valid?: boolean; auth_required?: boolean } | null>(null);

const testBridgeConnection = async () => {
    testingBridge.value = true;
    bridgeTestResult.value = null;
    try {
        const res = await fetch(route('admin.settings.voice_bridge.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                host: bridgeForm.host,
                api_port: bridgeForm.api_port,
                secret: bridgeForm.secret,
            }),
        });
        bridgeTestResult.value = await res.json();
    } catch {
        bridgeTestResult.value = { success: false, message: __('admin.settings.general.bridge_network_error') };
    } finally {
        testingBridge.value = false;
    }
};
</script>

<template>
    <Head :title="__('admin.settings.general.head_title')" />

    <AdminLayout>
        <div class="space-y-6 max-w-5xl mx-auto">
            <!-- Header -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ __('admin.settings.general.page_title') }}
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    {{ __('admin.settings.general.page_subtitle') }}
                </p>
            </div>

            <!-- 4 Standard Tabs Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 p-1.5 rounded-2xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 gap-1.5">
                <button
                    type="button"
                    @click="activeTab = 'general'"
                    class="py-3 px-4 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="activeTab === 'general' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <span>{{ __('admin.settings.general.tab_general') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="py-3 px-4 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="activeTab === 'security' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>{{ __('admin.settings.general.tab_security') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'tariffs'"
                    class="py-3 px-4 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="activeTab === 'tariffs' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ __('admin.settings.general.tab_tariffs') }}</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'bridge'"
                    class="py-3 px-4 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="activeTab === 'bridge' ? 'bg-white dark:bg-gray-700 text-emerald-600 dark:text-emerald-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span>{{ __('admin.settings.general.tab_bridge') }}</span>
                </button>
            </div>

            <!-- TAB 1: General Settings & SMTP -->
            <div v-show="activeTab === 'general'" class="space-y-6">
                <!-- General Specs Form -->
                <form @submit.prevent="submitGeneral" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-100 dark:border-gray-700">
                        {{ __('admin.settings.general.general_specs_title') }}
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.app_name_label') }}</label>
                            <input
                                v-model="generalForm.app_name"
                                type="text"
                                required
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.app_desc_label') }}</label>
                            <input
                                v-model="generalForm.app_description"
                                type="text"
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                    </div>

                    <!-- Event Notifications -->
                    <div class="pt-4 border-t border-gray-100 dark:border-gray-700 space-y-3">
                        <span class="text-xs font-bold text-gray-700 dark:text-gray-300 block">{{ __('admin.settings.general.system_notifications_title') }}</span>
                        <div class="flex items-center gap-3">
                            <input
                                id="ev_login_failed"
                                v-model="generalForm.event_notify_login_failed"
                                type="checkbox"
                                class="w-4 h-4 text-indigo-600 rounded"
                            />
                            <label for="ev_login_failed" class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                {{ __('admin.settings.general.notify_login_failed') }}
                            </label>
                        </div>
                        <div class="flex items-center gap-3">
                            <input
                                id="ev_security_alert"
                                v-model="generalForm.event_notify_security_alert"
                                type="checkbox"
                                class="w-4 h-4 text-indigo-600 rounded"
                            />
                            <label for="ev_security_alert" class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                {{ __('admin.settings.general.notify_security_alert') }}
                            </label>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button
                            type="submit"
                            :disabled="generalForm.processing"
                            class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50"
                        >
                            {{ generalForm.processing ? __('admin.settings.general.saving') : __('admin.settings.general.save_general_btn') }}
                        </button>
                    </div>
                </form>

                <!-- SMTP Mailer Card -->
                <form @submit.prevent="submitSmtp" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-100 dark:border-gray-700">
                        {{ __('admin.settings.general.smtp_title') }}
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_host_label') }}</label>
                            <input
                                v-model="smtpForm.mail_host"
                                type="text"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_port_label') }}</label>
                            <input
                                v-model="smtpForm.mail_port"
                                type="number"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_username_label') }}</label>
                            <input
                                v-model="smtpForm.mail_username"
                                type="text"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_password_label') }}</label>
                            <input
                                v-model="smtpForm.mail_password"
                                type="password"
                                :placeholder="__('admin.settings.general.leave_blank_unchanged')"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_encryption_label') }}</label>
                            <select
                                v-model="smtpForm.mail_encryption"
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">{{ __('admin.settings.general.enc_none') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_from_address_label') }}</label>
                            <input
                                v-model="smtpForm.mail_from_address"
                                type="email"
                                required
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.smtp_from_name_label') }}</label>
                            <input
                                v-model="smtpForm.mail_from_name"
                                type="text"
                                required
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                    </div>

                    <div class="pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3">
                        <input
                            id="require_email_verification"
                            v-model="smtpForm.require_email_verification"
                            type="checkbox"
                            class="w-4 h-4 text-indigo-600 rounded"
                        />
                        <label for="require_email_verification" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">
                            {{ __('admin.settings.general.require_email_verification') }}
                        </label>
                    </div>

                    <div class="pt-2 flex items-center justify-between">
                        <button
                            type="button"
                            @click="testSmtpConnection"
                            :disabled="testingSmtp"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100"
                        >
                            {{ testingSmtp ? __('admin.settings.general.testing_conn') : __('admin.settings.general.test_smtp_btn') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="smtpForm.processing"
                            class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {{ smtpForm.processing ? __('admin.settings.general.saving') : __('admin.settings.general.save_smtp_btn') }}
                        </button>
                    </div>

                    <div v-if="smtpTestResult" class="p-3 rounded-xl text-xs" :class="smtpTestResult.success ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                        {{ smtpTestResult.message || smtpTestResult.error }}
                    </div>
                </form>
            </div>

            <!-- TAB 2: Access Limits, Auth Providers, LDAP, Keycloak -->
            <div v-show="activeTab === 'security'" class="space-y-6">
                <!-- Sub Tab Navigation -->
                <div class="flex items-center gap-2 border-b border-gray-200 dark:border-gray-700 pb-2">
                    <button
                        type="button"
                        @click="securitySubTab = 'auth'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'auth' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        {{ __('admin.settings.general.subtab_auth') }}
                    </button>
                    <button
                        type="button"
                        @click="securitySubTab = 'ldap'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'ldap' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        {{ __('admin.settings.general.subtab_ldap') }}
                    </button>
                    <button
                        type="button"
                        @click="securitySubTab = 'keycloak'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'keycloak' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        {{ __('admin.settings.general.subtab_keycloak') }}
                    </button>
                </div>

                <!-- Sub-tab 1: Access Limits & Auth Providers -->
                <div v-if="securitySubTab === 'auth'" class="space-y-6">
                    <form @submit.prevent="submitGeneral" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('admin.settings.general.quota_title') }}</h3>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                {{ __('admin.settings.general.max_lines_label') }}
                            </label>
                            <input
                                v-model="generalForm.max_lines_per_user"
                                type="number"
                                min="1"
                                max="1000"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white max-w-xs"
                            />
                            <p class="text-xs text-gray-400 mt-1">{{ __('admin.settings.general.max_lines_help') }}</p>
                        </div>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="generalForm.processing"
                                class="px-4 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700"
                            >
                                {{ __('admin.settings.general.update_quota_btn') }}
                            </button>
                        </div>
                    </form>

                    <form @submit.prevent="submitAuth" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('admin.settings.general.auth_methods_title') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('admin.settings.general.auth_methods_desc') }}</p>

                        <div class="space-y-3">
                            <div class="flex items-center gap-3">
                                <input id="auth_username" v-model="authForm.auth_username_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_username" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">{{ __('admin.settings.general.auth_username_label') }}</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_email" v-model="authForm.auth_email_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_email" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">{{ __('admin.settings.general.auth_email_label') }}</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_ldap" v-model="authForm.auth_ldap_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_ldap" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">{{ __('admin.settings.general.auth_ldap_label') }}</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_keycloak" v-model="authForm.auth_keycloak_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_keycloak" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">{{ __('admin.settings.general.auth_keycloak_label') }}</label>
                            </div>
                            <div class="flex items-center gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                                <input id="allow_reg" v-model="authForm.allow_user_registration" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="allow_reg" class="text-xs font-bold text-gray-900 dark:text-white cursor-pointer">{{ __('admin.settings.general.allow_registration_label') }}</label>
                            </div>
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button type="submit" :disabled="authForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                {{ __('admin.settings.general.save_auth_btn') }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Sub-tab 2: LDAP Settings -->
                <div v-if="securitySubTab === 'ldap'" class="space-y-6">
                    <form @submit.prevent="submitLdap" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('admin.settings.general.ldap_title') }}</h3>
                            <span class="text-xs px-2.5 py-1 rounded-full" :class="ldapSettings.extensionLoaded ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                {{ ldapSettings.extensionLoaded ? __('admin.settings.general.ldap_ext_enabled') : __('admin.settings.general.ldap_ext_disabled') }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_host_label') }}</label>
                                <input v-model="ldapForm.host" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_port_label') }}</label>
                                <input v-model="ldapForm.port" type="number" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_base_dn_label') }}</label>
                                <input v-model="ldapForm.base_dn" type="text" :placeholder="__('admin.settings.general.ldap_base_dn_ph')" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_encryption_label') }}</label>
                                <select v-model="ldapForm.encryption" class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <option value="none">{{ __('admin.settings.general.enc_none') }}</option>
                                    <option value="tls">StartTLS</option>
                                    <option value="ssl">SSL / LDAPS</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_bind_dn_label') }}</label>
                                <input v-model="ldapForm.bind_dn" type="text" placeholder="cn=admin,dc=example,dc=com" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_bind_password_label') }}</label>
                                <input v-model="ldapForm.bind_password" type="password" :placeholder="__('admin.settings.general.leave_blank_unchanged')" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_search_filter_label') }}</label>
                                <input v-model="ldapForm.search_filter" type="text" placeholder="(&(objectClass=person)(|(uid={login})(mail={login})))" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_uid_attribute_label') }}</label>
                                <input v-model="ldapForm.uid_attribute" type="text" placeholder="uid / sAMAccountName" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.ldap_email_attribute_label') }}</label>
                                <input v-model="ldapForm.email_attribute" type="text" placeholder="mail" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3">
                            <input id="ldap_auto_create" v-model="ldapForm.auto_create_user" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <label for="ldap_auto_create" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">
                                {{ __('admin.settings.general.ldap_auto_create_user') }}
                            </label>
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <button type="button" @click="testLdapConnection" :disabled="testingLdap" class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30">
                                {{ testingLdap ? __('admin.settings.general.testing_conn') : __('admin.settings.general.test_ldap_btn') }}
                            </button>
                            <button type="submit" :disabled="ldapForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                {{ __('admin.settings.general.save_ldap_btn') }}
                            </button>
                        </div>

                        <div v-if="ldapTestResult" class="p-3 rounded-xl text-xs" :class="ldapTestResult.success ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                            {{ ldapTestResult.message }}
                        </div>
                    </form>
                </div>

                <!-- Sub-tab 3: Keycloak Settings -->
                <div v-if="securitySubTab === 'keycloak'" class="space-y-6">
                    <form @submit.prevent="submitKeycloak" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('admin.settings.general.keycloak_title') }}</h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_base_url_label') }}</label>
                                <input v-model="keycloakForm.base_url" type="text" :placeholder="__('admin.settings.general.keycloak_base_url_ph')" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_realm_label') }}</label>
                                <input v-model="keycloakForm.realm" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_client_id_label') }}</label>
                                <input v-model="keycloakForm.client_id" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_client_secret_label') }}</label>
                                <input v-model="keycloakForm.client_secret" type="password" :placeholder="__('admin.settings.general.leave_blank_unchanged')" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_redirect_uri_label') }}</label>
                                <input :value="keycloakSettings.callbackUrl" type="text" readonly class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-500 cursor-not-allowed" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.settings.general.keycloak_scopes_label') }}</label>
                                <input value="openid profile email" type="text" readonly class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-500 cursor-not-allowed" />
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3">
                            <input id="keycloak_auto_create" v-model="keycloakForm.auto_create_user" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <label for="keycloak_auto_create" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">
                                {{ __('admin.settings.general.keycloak_auto_create_user') }}
                            </label>
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <button type="button" @click="testKeycloakConnection" :disabled="testingKeycloak" class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30">
                                {{ testingKeycloak ? __('admin.settings.general.testing_keycloak') : __('admin.settings.general.test_keycloak_btn') }}
                            </button>
                            <button type="submit" :disabled="keycloakForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                {{ __('admin.settings.general.save_keycloak_btn') }}
                            </button>
                        </div>

                        <div v-if="keycloakTestResult" class="p-3 rounded-xl text-xs" :class="keycloakTestResult.success ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                            {{ keycloakTestResult.message }}
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 3: Base Tariffs Matrix & Gateways Link -->
            <div v-show="activeTab === 'tariffs'" class="space-y-6">
                <!-- Direct Gateway Management Banner -->
                <div class="p-6 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-bold text-indigo-950 dark:text-indigo-200">{{ __('admin.settings.general.gateways_banner_title') }}</h3>
                        <p class="text-xs text-indigo-700 dark:text-indigo-300 mt-1">
                            {{ __('admin.settings.general.gateways_banner_desc') }}
                        </p>
                    </div>
                    <Link
                        :href="route('admin.payments.gateways.index')"
                        class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm shrink-0"
                    >
                        {{ __('admin.settings.general.gateways_banner_btn') }}
                    </Link>
                </div>

                <!-- Short Numbers Base Pricing Matrix -->
                <form @submit.prevent="submitPricing" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">{{ __('admin.settings.general.org_pricing_title') }}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.settings.general.org_pricing_desc') }}</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="len in [4, 5, 6, 7, 8]"
                            :key="len"
                            class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/30"
                        >
                            <div class="text-sm font-bold text-gray-900 dark:text-white">
                                {{ __('admin.settings.general.prefix_len_label', { len }) }}
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-0.5">{{ __('admin.settings.general.regular_price_label') }}</label>
                                <input
                                    v-model="pricingForm[`org_price_${len}_regular` as keyof typeof pricingForm]"
                                    type="number"
                                    step="0.0001"
                                    class="w-full text-xs font-mono rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-amber-600 mb-0.5">{{ __('admin.settings.general.rond_price_label') }}</label>
                                <input
                                    v-model="pricingForm[`org_price_${len}_rond` as keyof typeof pricingForm]"
                                    type="number"
                                    step="0.0001"
                                    class="w-full text-xs font-mono rounded-lg border-amber-300 dark:border-amber-700 dark:bg-gray-700 dark:text-white"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button
                            type="submit"
                            :disabled="pricingForm.processing"
                            class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50"
                        >
                            {{ pricingForm.processing ? __('admin.settings.general.saving') : __('admin.settings.general.save_pricing_btn') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 4: Go Voice Bridge Daemon -->
            <div v-show="activeTab === 'bridge'" class="space-y-6">
                <!-- Info & Health Banner -->
                <div class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>{{ __('admin.settings.general.bridge_title') }}</span>
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ __('admin.settings.general.bridge_desc') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-xs font-mono font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                {{ __('admin.settings.general.bridge_ports_badge') }}
                            </span>
                        </div>
                    </div>

                    <!-- Voice Bridge Sub-Tabs Navigation (3 Tabs) -->
                    <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 dark:border-gray-700 pb-3">
                        <button
                            type="button"
                            @click="bridgeSubTab = 'connection'"
                            class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                            :class="bridgeSubTab === 'connection'
                                ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/20'
                                : 'bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-800'"
                        >
                            {{ __('admin.settings.general.bridge_subtab_connection') }}
                        </button>
                        <button
                            type="button"
                            @click="bridgeSubTab = 'relay'"
                            class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                            :class="bridgeSubTab === 'relay'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                                : 'bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-800'"
                        >
                            {{ __('admin.settings.general.bridge_subtab_relay') }}
                        </button>
                        <button
                            type="button"
                            @click="bridgeSubTab = 'guide'"
                            class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                            :class="bridgeSubTab === 'guide'
                                ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20'
                                : 'bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-800'"
                        >
                            {{ __('admin.settings.general.bridge_subtab_guide') }}
                        </button>
                    </div>

                    <!-- Voice Bridge Settings Form -->
                    <form @submit.prevent="submitVoiceBridge" class="space-y-5 pt-1">
                        <!-- SUB-TAB 1: Live Voice Connection & Go Daemon -->
                        <div v-show="bridgeSubTab === 'connection'" class="space-y-4">
                            <!-- Daemon Enable Toggle -->
                            <div class="flex items-center gap-3 p-4 rounded-2xl bg-emerald-500/10 dark:bg-emerald-950/20 border border-emerald-300/60 dark:border-emerald-800/50">
                                <input
                                    id="voice_bridge_enabled_toggle"
                                    v-model="bridgeForm.enabled"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer"
                                />
                                <div class="flex-1">
                                    <label for="voice_bridge_enabled_toggle" class="text-xs font-bold text-gray-800 dark:text-gray-200 cursor-pointer select-none">
                                        {{ __('admin.settings.general.bridge_enabled_label') }}
                                    </label>
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 block sm:inline sm:mr-2">
                                        {{ __('admin.settings.general.bridge_enabled_hint') }}
                                    </span>
                                </div>
                            </div>

                            <!-- Live Test Connection Action -->
                            <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="space-y-0.5">
                                        <div class="text-xs font-bold text-gray-800 dark:text-gray-200">{{ __('admin.settings.general.bridge_health_title') }}</div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ __('admin.settings.general.bridge_health_desc') }}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="testBridgeConnection"
                                        :disabled="testingBridge"
                                        class="px-4 py-2 text-xs font-bold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 shadow-sm transition-all flex items-center gap-1.5 disabled:opacity-50"
                                    >
                                        <span v-if="testingBridge" class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                        <span>{{ testingBridge ? __('admin.settings.general.bridge_testing') : __('admin.settings.general.bridge_test_btn') }}</span>
                                    </button>
                                </div>

                                <!-- Feedback result -->
                                <div
                                    v-if="bridgeTestResult"
                                    class="p-3 rounded-xl border text-xs space-y-1.5"
                                    :class="bridgeTestResult.success ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-800 dark:text-emerald-200' : 'bg-rose-500/10 border-rose-500/30 text-rose-800 dark:text-rose-200'"
                                >
                                    <div class="font-bold flex items-center gap-1.5">
                                        <span>{{ bridgeTestResult.success ? '✓' : '✗' }}</span>
                                        <span>{{ bridgeTestResult.message }}</span>
                                    </div>
                                    <div v-if="bridgeTestResult.data" class="font-mono text-[11px] space-y-0.5 pt-1 border-t border-emerald-500/20" dir="ltr">
                                        <div>Service: {{ bridgeTestResult.data.service }} (v{{ bridgeTestResult.data.version }})</div>
                                        <div>Uptime: {{ bridgeTestResult.data.uptime_seconds }}s | Active Calls: {{ bridgeTestResult.data.active_sessions }}</div>
                                        <div>Endpoints: http://{{ (bridgeTestResult.data.bridge_host && bridgeTestResult.data.bridge_host !== '0.0.0.0') ? bridgeTestResult.data.bridge_host : (bridgeForm.host && bridgeForm.host !== '0.0.0.0' ? bridgeForm.host : '127.0.0.1') }}:{{ bridgeTestResult.data.api_port }} & ws://{{ (bridgeTestResult.data.bridge_host && bridgeTestResult.data.bridge_host !== '0.0.0.0') ? bridgeTestResult.data.bridge_host : (bridgeForm.host && bridgeForm.host !== '0.0.0.0' ? bridgeForm.host : '127.0.0.1') }}:{{ bridgeTestResult.data.ws_port }}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Host & Ports -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                        {{ __('admin.settings.general.bridge_host_label') }}
                                    </label>
                                    <input
                                        v-model="bridgeForm.host"
                                        type="text"
                                        required
                                        :placeholder="__('admin.settings.general.bridge_host_placeholder')"
                                        class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        dir="ltr"
                                    />
                                    <InputError :message="bridgeForm.errors.host" class="mt-1" />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                        {{ __('admin.settings.general.bridge_api_port_label') }}
                                    </label>
                                    <input
                                        v-model="bridgeForm.api_port"
                                        type="number"
                                        required
                                        min="1"
                                        max="65535"
                                        :placeholder="__('admin.settings.general.bridge_api_port_placeholder')"
                                        class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        dir="ltr"
                                    />
                                    <InputError :message="bridgeForm.errors.api_port" class="mt-1" />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                        {{ __('admin.settings.general.bridge_ws_port_label') }}
                                    </label>
                                    <input
                                        v-model="bridgeForm.ws_port"
                                        type="number"
                                        required
                                        min="1"
                                        max="65535"
                                        :placeholder="__('admin.settings.general.bridge_ws_port_placeholder')"
                                        class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        dir="ltr"
                                    />
                                    <InputError :message="bridgeForm.errors.ws_port" class="mt-1" />
                                </div>
                            </div>

                            <!-- Bridge Secret Token Field -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300">
                                        {{ __('admin.settings.general.bridge_secret_label') }}
                                    </label>
                                    <button
                                        type="button"
                                        @click="showBridgeSecret = !showBridgeSecret"
                                        class="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1 font-medium"
                                    >
                                        <span>{{ showBridgeSecret ? __('admin.settings.general.hide_token') : __('admin.settings.general.show_token') }}</span>
                                    </button>
                                </div>
                                <div class="relative">
                                    <input
                                        v-model="bridgeForm.secret"
                                        :type="showBridgeSecret ? 'text' : 'password'"
                                        :placeholder="__('admin.settings.general.bridge_secret_placeholder')"
                                        class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white pl-10 pr-3"
                                        dir="ltr"
                                    />
                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                        </svg>
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1" v-html="__('admin.settings.general.bridge_secret_help_html')"></p>
                                <InputError :message="bridgeForm.errors.secret" class="mt-1" />
                            </div>

                            <!-- Public WSS URL for HTTPS Browsers -->
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                    {{ __('admin.settings.general.bridge_wss_label') }}
                                </label>
                                <input
                                    v-model="bridgeForm.ws_public_url"
                                    type="text"
                                    :placeholder="__('admin.settings.general.bridge_wss_placeholder')"
                                    class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                    dir="ltr"
                                />
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1" v-html="__('admin.settings.general.bridge_wss_help_html')"></p>
                                <InputError :message="bridgeForm.errors.ws_public_url" class="mt-1" />
                            </div>
                        </div>

                        <!-- SUB-TAB 2: STUN / TURN & WebRTC Media Relay -->
                        <div v-show="bridgeSubTab === 'relay'" class="space-y-4">
                            <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-br from-indigo-50/70 via-indigo-50/40 to-slate-50 dark:from-indigo-950/30 dark:via-indigo-950/20 dark:to-slate-900/40 border border-indigo-200/80 dark:border-indigo-800/50 space-y-4 shadow-sm">
                                <div>
                                    <h4 class="text-xs sm:text-sm font-extrabold text-indigo-950 dark:text-indigo-200 flex items-center gap-2">
                                        <span>🛡️</span>
                                        <span>{{ __('admin.settings.general.webrtc_relay_title') }}</span>
                                    </h4>
                                    <p class="text-[11px] text-indigo-800/80 dark:text-indigo-300/80 mt-1 leading-relaxed" v-html="__('admin.settings.general.webrtc_relay_desc_html')"></p>
                                </div>

                                <!-- Quick Presets (Google STUN, Cloudflare, Private STUN/TURN) -->
                                <div class="p-3.5 rounded-xl bg-white/80 dark:bg-gray-800/80 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
                                    <div class="text-[11px] font-bold text-gray-700 dark:text-gray-300 flex items-center gap-1">
                                        <span>⚡</span>
                                        <span>{{ __('admin.settings.general.quick_presets_label') }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            @click="applyGoogleStunPreset"
                                            class="px-3 py-1.5 text-xs font-bold rounded-xl bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/40 dark:hover:bg-blue-900/60 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800/60 transition shadow-xs flex items-center gap-1"
                                        >
                                            <span>🔍</span>
                                            <span>{{ __('admin.settings.general.preset_google_stun') }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="applyCloudflarePreset"
                                            class="px-3 py-1.5 text-xs font-bold rounded-xl bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60 transition shadow-xs flex items-center gap-1"
                                        >
                                            <span>☁️</span>
                                            <span>{{ __('admin.settings.general.preset_cloudflare') }}</span>
                                        </button>
                                        <button
                                            type="button"
                                            @click="applyPrivatePreset"
                                            class="px-3 py-1.5 text-xs font-bold rounded-xl bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:hover:bg-emerald-900/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60 transition shadow-xs flex items-center gap-1"
                                        >
                                            <span>🔒</span>
                                            <span>{{ __('admin.settings.general.preset_private') }}</span>
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-indigo-700 dark:text-indigo-300/80 pt-1">
                                        {{ __('admin.settings.general.preset_fallback_hint') }}
                                    </p>

                                    <!-- Public vs Private STUN/TURN Clarification Notice -->
                                    <div class="mt-2 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-300 text-[11px] leading-relaxed flex items-start gap-2">
                                        <span class="shrink-0 text-sm">💡</span>
                                        <span>
                                            <strong>توجه درباره سرورهای گوگل و کلادفلر:</strong> این سرورها صرفاً خدمات رایگان STUN (کشف IP عمومی و برقراری مستقیم P2P) ارائه می‌دهند و فاقد رله TURN یا کلید HMAC هستند؛ لذا با انتخاب آنها، فیلدهای TURN و کلید اعتبارسنجی خودکار خالی می‌شوند. در صورت نیاز به رله تضمینی از پشت فایروال‌های بسته شرکتی، از پریست «سرور شخصی Coturn» استفاده نمایید.
                                        </span>
                                    </div>
                                </div>

                                <!-- Dedicated STUN/TURN Host & Ports -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div class="sm:col-span-1">
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.turn_host_label') }}
                                        </label>
                                        <input
                                            v-model="bridgeForm.turn_domain_or_ip"
                                            type="text"
                                            :placeholder="__('admin.settings.general.turn_host_placeholder')"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            {{ __('admin.settings.general.turn_host_help') }}
                                        </p>
                                        <InputError :message="bridgeForm.errors.turn_domain_or_ip" class="mt-1" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.turn_port_label') }}
                                        </label>
                                        <input
                                            v-model="bridgeForm.turn_port"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            placeholder="3478"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            {{ __('admin.settings.general.turn_port_help') }}
                                        </p>
                                        <InputError :message="bridgeForm.errors.turn_port" class="mt-1" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.turns_port_label') }}
                                        </label>
                                        <input
                                            v-model="bridgeForm.turns_port"
                                            type="number"
                                            min="1"
                                            max="65535"
                                            placeholder="5349"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                        <div class="flex items-center gap-2 mt-1.5">
                                            <input
                                                id="voice_turns_toggle"
                                                v-model="bridgeForm.turns_enabled"
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 w-3.5 h-3.5"
                                            />
                                            <label for="voice_turns_toggle" class="text-[11px] text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                                                {{ __('admin.settings.general.turns_enable_label') }}
                                            </label>
                                        </div>
                                        <InputError :message="bridgeForm.errors.turns_port" class="mt-1" />
                                    </div>
                                </div>

                                <!-- STUN & TURN Manual URL Lists -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.stun_urls_label') }}
                                        </label>
                                        <textarea
                                            v-model="bridgeForm.stun_urls"
                                            rows="3"
                                            :placeholder="__('admin.settings.general.stun_urls_placeholder')"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        ></textarea>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            {{ __('admin.settings.general.stun_urls_help') }}
                                        </p>
                                        <InputError :message="bridgeForm.errors.stun_urls" class="mt-1" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.turn_urls_label') }}
                                        </label>
                                        <textarea
                                            v-model="bridgeForm.turn_urls"
                                            rows="3"
                                            placeholder="turn:YOUR_SERVER_IP:3478?transport=udp&#10;turn:YOUR_SERVER_IP:3478?transport=tcp&#10;turns:YOUR_DOMAIN:5349?transport=tcp"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        ></textarea>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            {{ __('admin.settings.general.turn_urls_help') }}
                                        </p>
                                        <InputError :message="bridgeForm.errors.turn_urls" class="mt-1" />
                                    </div>
                                </div>

                                <!-- Credentials & HMAC Secret -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300">
                                                {{ __('admin.settings.general.turn_secret_label') }}
                                            </label>
                                            <button
                                                type="button"
                                                @click="copyBridgeSecretToTurn"
                                                class="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                {{ __('admin.settings.general.sync_bridge_secret_btn') }}
                                            </button>
                                        </div>
                                        <input
                                            v-model="bridgeForm.turn_secret"
                                            :type="showTurnCredential ? 'text' : 'password'"
                                            :placeholder="__('admin.settings.general.turn_secret_placeholder')"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">
                                            {{ __('admin.settings.general.turn_secret_help') }}
                                        </p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                            {{ __('admin.settings.general.turn_username_label') }}
                                        </label>
                                        <input
                                            v-model="bridgeForm.turn_username"
                                            type="text"
                                            :placeholder="__('admin.settings.general.turn_username_placeholder')"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                    </div>

                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300">
                                                {{ __('admin.settings.general.turn_credential_label') }}
                                            </label>
                                            <button
                                                type="button"
                                                @click="showTurnCredential = !showTurnCredential"
                                                class="text-[10px] text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >
                                                {{ showTurnCredential ? __('admin.settings.general.hide_short') : __('admin.settings.general.show_short') }}
                                            </button>
                                        </div>
                                        <input
                                            v-model="bridgeForm.turn_credential"
                                            :type="showTurnCredential ? 'text' : 'password'"
                                            :placeholder="__('admin.settings.general.turn_credential_placeholder')"
                                            class="w-full text-xs font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                            dir="ltr"
                                        />
                                    </div>
                                </div>

                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
                                    <div class="flex items-center gap-2">
                                        <label class="text-xs font-bold text-gray-700 dark:text-gray-300">
                                            {{ __('admin.settings.general.ice_policy_label') }}
                                        </label>
                                        <select
                                            v-model="bridgeForm.ice_transport_policy"
                                            class="text-xs rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white py-1.5 px-3"
                                        >
                                            <option value="all">{{ __('admin.settings.general.ice_policy_all') }}</option>
                                            <option value="relay">{{ __('admin.settings.general.ice_policy_relay') }}</option>
                                        </select>
                                    </div>
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400" v-html="__('admin.settings.general.auto_ports_info_html')"></span>
                                </div>
                            </div>
                        </div>

                        <!-- SUB-TAB 3: Installation & Deployment Guide -->
                        <div v-show="bridgeSubTab === 'guide'" class="space-y-4">
                            <!-- Voice Bridge Deployment Guide Info Box -->
                            <div class="p-5 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-xs space-y-4">
                                <div class="font-bold text-sm text-slate-800 dark:text-slate-200 flex items-center gap-2">
                                    <span class="text-xl">🐧</span>
                                    <span>{{ __('admin.settings.general.linux_setup_title') }}</span>
                                </div>
                                <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed" v-html="__('admin.settings.general.linux_setup_desc_html')"></p>
                                
                                <div class="space-y-2">
                                    <div class="font-bold text-slate-700 dark:text-slate-300">۱. اجرای اسکریپت نصب خودکار لینوکس:</div>
                                    <div class="p-3 rounded-xl bg-gray-900 text-emerald-400 font-mono text-xs select-all flex items-center justify-between" dir="ltr">
                                        <code>cd /var/www/html/services/voice-bridge && sudo bash install.sh</code>
                                        <span class="text-[10px] text-gray-400">{{ __('admin.settings.general.linux_setup_path_badge') }}</span>
                                    </div>
                                </div>

                                <div class="space-y-2 pt-2">
                                    <div class="font-bold text-slate-700 dark:text-slate-300">۲. بازگشایی پورت‌های فایروال سرور (UFW / iptables):</div>
                                    <div class="p-3 rounded-xl bg-gray-900 text-emerald-400 font-mono text-xs select-all" dir="ltr">
                                        <div>sudo ufw allow 587/tcp comment "Voice Bridge REST API"</div>
                                        <div>sudo ufw allow 993/tcp comment "Voice Bridge WebSocket"</div>
                                        <div>sudo ufw allow 3478/tcp comment "STUN / TURN TCP"</div>
                                        <div>sudo ufw allow 3478/udp comment "STUN / TURN UDP"</div>
                                        <div>sudo ufw allow 5349/tcp comment "TURNS TLS TCP"</div>
                                        <div>sudo ufw allow 49152:49250/udp comment "WebRTC Media UDP Ports"</div>
                                    </div>
                                </div>

                                <div class="space-y-2 pt-2">
                                    <div class="font-bold text-slate-700 dark:text-slate-300">۳. نمونه پیکربندی Nginx برای WebSocket ایمن (wss://):</div>
                                    <pre class="p-3 rounded-xl bg-gray-900 text-slate-300 font-mono text-[11px] overflow-x-auto select-all" dir="ltr">
location /ws/signaling {
    proxy_pass http://127.0.0.1:993;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400s;
}</pre>
                                </div>

                                <p class="text-[11px] text-slate-500 dark:text-slate-400 pt-2 border-t border-slate-200 dark:border-slate-800" v-html="__('admin.settings.general.linux_setup_tip_html')"></p>
                            </div>
                        </div>

                        <!-- Save Settings Button (Available across tabs) -->
                        <div class="pt-2 flex justify-end">
                            <button
                                type="submit"
                                :disabled="bridgeForm.processing"
                                class="px-6 py-2.5 text-xs font-bold rounded-xl text-white bg-emerald-600 hover:bg-emerald-700 shadow-md shadow-emerald-600/20 disabled:opacity-50 transition-colors"
                            >
                                {{ bridgeForm.processing ? __('admin.settings.general.saving') : __('admin.settings.general.save_bridge_btn') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
