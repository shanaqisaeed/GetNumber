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
}

const props = withDefaults(defineProps<Props>(), {
    initialTab: 'general',
});

const { __ } = useTrans();

// 3 Standard Main Tabs:
// 1. 'general' (تنظیمات عمومی و ایمیل)
// 2. 'security' (سقف‌های دسترسی و احراز هویت / LDAP / Keycloak)
// 3. 'tariffs' (نرخ‌های پایه و درگاه‌های پرداخت)
type MainTab = 'general' | 'security' | 'tariffs';

const determineMainTab = (tab?: string): MainTab => {
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
        ldapTestResult.value = { success: false, message: 'خطا در ارتباط با سرور' };
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
        keycloakTestResult.value = { success: false, message: 'خطا در ارتباط با سرور Keycloak' };
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
        smtpTestResult.value = { success: false, error: 'خطای شبکه در تست SMTP' };
    } finally {
        testingSmtp.value = false;
    }
};
</script>

<template>
    <Head title="تنظیمات جامع سامانه" />

    <AdminLayout>
        <div class="space-y-6 max-w-5xl mx-auto">
            <!-- Header -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    تنظیمات یکپارچه و پیکربندی مرکزی سامانه
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    مدیریت پارامترهای عمومی، روش‌های احراز هویت سازمانی و نرخ‌گذاری پایه سرشماره‌ها
                </p>
            </div>

            <!-- 3 Standard Tabs Bar -->
            <div class="grid grid-cols-1 sm:grid-cols-3 p-1.5 rounded-2xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 gap-1.5">
                <button
                    type="button"
                    @click="activeTab = 'general'"
                    class="py-3 px-4 rounded-xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="activeTab === 'general' ? 'bg-white dark:bg-gray-700 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                    </svg>
                    <span>۱. تنظیمات عمومی (General)</span>
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
                    <span>۲. سقف‌های دسترسی و امنیت (Access & Auth)</span>
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
                    <span>۳. نرخ‌های پایه و درگاه‌ها (Tariffs & Gateways)</span>
                </button>
            </div>

            <!-- TAB 1: تنظیمات عمومی (General Settings & SMTP) -->
            <div v-show="activeTab === 'general'" class="space-y-6">
                <!-- General Specs Form -->
                <form @submit.prevent="submitGeneral" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-100 dark:border-gray-700">
                        مشخصات عمومی پلتفرم
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نام سامانه</label>
                            <input
                                v-model="generalForm.app_name"
                                type="text"
                                required
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">زبان پیش‌فرض</label>
                            <select
                                v-model="generalForm.default_locale"
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="fa">فارسی (FA)</option>
                                <option value="en">English (EN)</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">توضیحات سامانه</label>
                            <input
                                v-model="generalForm.app_description"
                                type="text"
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                    </div>

                    <!-- Event Notifications -->
                    <div class="pt-4 border-t border-gray-100 dark:border-gray-700 space-y-3">
                        <span class="text-xs font-bold text-gray-700 dark:text-gray-300 block">اعلان‌های سیستمی</span>
                        <div class="flex items-center gap-3">
                            <input
                                id="ev_login_failed"
                                v-model="generalForm.event_notify_login_failed"
                                type="checkbox"
                                class="w-4 h-4 text-indigo-600 rounded"
                            />
                            <label for="ev_login_failed" class="text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                ثبت لاگ و اعلان ورودهای ناموفق
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
                                ارسال هشدار امنیتی هنگام شناسایی فعالیت مشکوک
                            </label>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button
                            type="submit"
                            :disabled="generalForm.processing"
                            class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50"
                        >
                            {{ generalForm.processing ? 'در حال ذخیره...' : 'ذخیره تنظیمات عمومی' }}
                        </button>
                    </div>
                </form>

                <!-- SMTP Mailer Card -->
                <form @submit.prevent="submitSmtp" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <h2 class="text-base font-bold text-gray-900 dark:text-white pb-3 border-b border-gray-100 dark:border-gray-700">
                        تنظیمات سرور ایمیل (SMTP)
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">هاست SMTP</label>
                            <input
                                v-model="smtpForm.mail_host"
                                type="text"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">پورت</label>
                            <input
                                v-model="smtpForm.mail_port"
                                type="number"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نام کاربری ایمیل</label>
                            <input
                                v-model="smtpForm.mail_username"
                                type="text"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">رمز عبور (جدید)</label>
                            <input
                                v-model="smtpForm.mail_password"
                                type="password"
                                placeholder="در صورت عدم تغییر خالی بگذارید"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نوع رمزنگاری</label>
                            <select
                                v-model="smtpForm.mail_encryption"
                                class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">بدون رمزنگاری</option>
                            </select>
                        </div>
                    </div>

                    <div class="pt-2 flex items-center justify-between">
                        <button
                            type="button"
                            @click="testSmtpConnection"
                            :disabled="testingSmtp"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100"
                        >
                            {{ testingSmtp ? 'در حال آزمون ارتباط...' : 'آزمون ارتباط SMTP' }}
                        </button>
                        <button
                            type="submit"
                            :disabled="smtpForm.processing"
                            class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {{ smtpForm.processing ? 'در حال ذخیره...' : 'ذخیره پیکربندی SMTP' }}
                        </button>
                    </div>

                    <div v-if="smtpTestResult" class="p-3 rounded-xl text-xs" :class="smtpTestResult.success ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                        {{ smtpTestResult.message || smtpTestResult.error }}
                    </div>
                </form>
            </div>

            <!-- TAB 2: سقف‌های دسترسی و امنیت (Access Limits, Auth Providers, LDAP, Keycloak) -->
            <div v-show="activeTab === 'security'" class="space-y-6">
                <!-- Sub Tab Navigation -->
                <div class="flex items-center gap-2 border-b border-gray-200 dark:border-gray-700 pb-2">
                    <button
                        type="button"
                        @click="securitySubTab = 'auth'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'auth' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        سقف دسترسی و فعال‌سازی روش‌های ورود
                    </button>
                    <button
                        type="button"
                        @click="securitySubTab = 'ldap'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'ldap' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        پیکربندی سرور LDAP
                    </button>
                    <button
                        type="button"
                        @click="securitySubTab = 'keycloak'"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="securitySubTab === 'keycloak' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'"
                    >
                        پیکربندی Keycloak SSO
                    </button>
                </div>

                <!-- Sub-tab 1: Access Limits & Auth Providers -->
                <div v-if="securitySubTab === 'auth'" class="space-y-6">
                    <form @submit.prevent="submitGeneral" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">سقف سهمیه خطوط کاربران</h3>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                حداکثر تعداد خطوط فعال مجاز برای هر کاربر
                            </label>
                            <input
                                v-model="generalForm.max_lines_per_user"
                                type="number"
                                min="1"
                                max="1000"
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white max-w-xs"
                            />
                            <p class="text-xs text-gray-400 mt-1">کاربران پس از رسیدن به این سقف، امکان رزرو یا خرید خط جدید را ندارند.</p>
                        </div>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="generalForm.processing"
                                class="px-4 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700"
                            >
                                به‌روزرسانی سقف دسترسی
                            </button>
                        </div>
                    </form>

                    <form @submit.prevent="submitAuth" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">روش‌های فعال احراز هویت</h3>
                        <p class="text-xs text-gray-500">حداقل یکی از روش‌های نام‌کاربری یا ایمیل باید برای ورود فعال باشد.</p>

                        <div class="space-y-3">
                            <div class="flex items-center gap-3">
                                <input id="auth_username" v-model="authForm.auth_username_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_username" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">ورود با نام کاربری (Username)</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_email" v-model="authForm.auth_email_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_email" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">ورود با آدرس ایمیل (Email OTP / Password)</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_ldap" v-model="authForm.auth_ldap_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_ldap" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">احراز هویت دایرکتوری LDAP / Active Directory</label>
                            </div>
                            <div class="flex items-center gap-3">
                                <input id="auth_keycloak" v-model="authForm.auth_keycloak_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="auth_keycloak" class="text-xs font-bold text-gray-700 dark:text-gray-300 cursor-pointer">ورود یکپارچه Keycloak OpenID Connect</label>
                            </div>
                            <div class="flex items-center gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                                <input id="allow_reg" v-model="authForm.allow_user_registration" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                                <label for="allow_reg" class="text-xs font-bold text-gray-900 dark:text-white cursor-pointer">امکان ثبت‌نام مستقیم کاربران جدید فعال باشد</label>
                            </div>
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button type="submit" :disabled="authForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                ذخیره روش‌های ورود
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Sub-tab 2: LDAP Settings -->
                <div v-if="securitySubTab === 'ldap'" class="space-y-6">
                    <form @submit.prevent="submitLdap" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">پیکربندی سرور LDAP / AD</h3>
                            <span class="text-xs px-2.5 py-1 rounded-full" :class="ldapSettings.extensionLoaded ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                {{ ldapSettings.extensionLoaded ? 'اکستنشن PHP LDAP فعال است' : 'اکستنشن PHP LDAP غیرفعال است' }}
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">هاست سرور LDAP</label>
                                <input v-model="ldapForm.host" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">پورت LDAP</label>
                                <input v-model="ldapForm.port" type="number" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Base DN</label>
                                <input v-model="ldapForm.base_dn" type="text" placeholder="dc=example,dc=com" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نوع رمزنگاری</label>
                                <select v-model="ldapForm.encryption" class="w-full text-sm rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    <option value="none">بدون رمزنگاری</option>
                                    <option value="tls">StartTLS</option>
                                    <option value="ssl">SSL / LDAPS</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <button type="button" @click="testLdapConnection" :disabled="testingLdap" class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30">
                                {{ testingLdap ? 'در حال آزمون ارتباط...' : 'تست اتصال به LDAP' }}
                            </button>
                            <button type="submit" :disabled="ldapForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                ذخیره تنظیمات LDAP
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
                        <h3 class="text-base font-bold text-gray-900 dark:text-white">پیکربندی یکپارچه Keycloak SSO</h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">آدرس پایه سرور Keycloak</label>
                                <input v-model="keycloakForm.base_url" type="text" placeholder="https://auth.example.com" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نام قلمرو (Realm)</label>
                                <input v-model="keycloakForm.realm" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Client ID</label>
                                <input v-model="keycloakForm.client_id" type="text" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Client Secret</label>
                                <input v-model="keycloakForm.client_secret" type="password" placeholder="در صورت عدم تغییر خالی بگذارید" class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-between">
                            <button type="button" @click="testKeycloakConnection" :disabled="testingKeycloak" class="px-4 py-2 text-xs font-semibold rounded-xl text-indigo-700 bg-indigo-50 dark:bg-indigo-900/30">
                                {{ testingKeycloak ? 'در حال بررسی Realm...' : 'بررسی اتصال Keycloak' }}
                            </button>
                            <button type="submit" :disabled="keycloakForm.processing" class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700">
                                ذخیره تنظیمات Keycloak
                            </button>
                        </div>

                        <div v-if="keycloakTestResult" class="p-3 rounded-xl text-xs" :class="keycloakTestResult.success ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'">
                            {{ keycloakTestResult.message }}
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 3: نرخ‌های پایه و درگاه‌ها (Base Tariffs Matrix & Gateways Link) -->
            <div v-show="activeTab === 'tariffs'" class="space-y-6">
                <!-- Direct Gateway Management Banner -->
                <div class="p-6 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-bold text-indigo-950 dark:text-indigo-200">مدیریت کلیدهای API و درگاه‌های پرداخت</h3>
                        <p class="text-xs text-indigo-700 dark:text-indigo-300 mt-1">
                            پیکربندی درگاه‌های زرین‌پال، نوبیتکس، درگاه پشتیبان و مدیریت کیف‌پول ریالی/ارزی
                        </p>
                    </div>
                    <Link
                        :href="route('admin.payments.gateways.index')"
                        class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm shrink-0"
                    >
                        پیکربندی کلیدهای API درگاه‌ها &larr;
                    </Link>
                </div>

                <!-- Short Numbers Base Pricing Matrix -->
                <form @submit.prevent="submitPricing" class="p-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700/60 shadow-sm space-y-5">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">ماتریس تعرفه پایه سرشماره‌های کوتاه (۴ الی ۸ رقمی)</h3>
                            <p class="text-xs text-gray-500 mt-0.5">قیمت‌ها برحسب کوین محاسبه می‌شوند و برای همه کاربران یکسان اعمال می‌گردد.</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="len in [4, 5, 6, 7, 8]"
                            :key="len"
                            class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center p-3 rounded-xl bg-gray-50 dark:bg-gray-700/30"
                        >
                            <div class="text-sm font-bold text-gray-900 dark:text-white">
                                سرشماره‌های {{ len }} رقمی
                            </div>
                            <div>
                                <label class="block text-[11px] text-gray-500 mb-0.5">قیمت عادی</label>
                                <input
                                    v-model="pricingForm[`org_price_${len}_regular` as keyof typeof pricingForm]"
                                    type="number"
                                    step="0.0001"
                                    class="w-full text-xs font-mono rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                            </div>
                            <div>
                                <label class="block text-[11px] text-amber-600 mb-0.5">قیمت رند (Rond / VIP)</label>
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
                            {{ pricingForm.processing ? 'در حال ذخیره...' : 'ذخیره ماتریس تعرفه‌ها' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
