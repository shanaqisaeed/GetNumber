<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import TwoFactorAuthenticationForm from './Partials/TwoFactorAuthenticationForm.vue';
import ActiveSessionsForm from './Partials/ActiveSessionsForm.vue';
import ConnectedIdentitiesForm from './Partials/ConnectedIdentitiesForm.vue';
import WalletSection from './Partials/WalletSection.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface SessionItem {
    id: string;
    ip_address: string;
    is_current_device: boolean;
    platform: string;
    browser: string;
    device_type: 'desktop' | 'mobile' | 'tablet';
    last_active: string;
    last_activity_date: string;
    last_activity_timestamp: number;
}

interface TransactionItem {
    id: number;
    type: string;
    type_label: string;
    is_credit: boolean;
    amount: string;
    balance_after: string;
    reference: string | null;
    created_at: string;
    created_ago: string;
}

interface GatewayItem {
    id: number;
    name: string;
    driver: string;
    supported_currencies: string[];
    supported_networks: string[];
}

interface PendingDeposit {
    tracking_code: string;
    currency: string;
    network: string;
    requested_amount: string;
    coin_amount: string;
    deposit_address: string;
    deposit_tag: string | null;
    status: string;
    status_label: string;
    status_badge: string;
    tx_hash: string | null;
    can_submit_tx: boolean;
    is_completed: boolean;
    is_expired: boolean;
    created_at: string;
}

interface Props {
    status?: string;
    initialTab?: string;
    initialView?: 'charge' | 'transactions';
    wallet?: { balance: string };
    stats?: {
        active_lines: number;
        max_lines: number;
        active_sessions: number;
        connected_identities: number;
    };
    sessions?: SessionItem[];
    transactions?: TransactionItem[];
    activeGateways?: GatewayItem[];
    pendingDeposit?: PendingDeposit | null;
    identities?: Array<{
        id: number;
        provider: string;
        provider_label: string;
        provider_id: string;
        provider_email: string | null;
        linked_at: string | null;
    }>;
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
    initialTab: 'info',
    initialView: 'charge',
    wallet: () => ({ balance: '0' }),
    stats: () => ({
        active_lines: 0,
        max_lines: 10,
        active_sessions: 1,
        connected_identities: 1,
    }),
    sessions: () => [],
    transactions: () => [],
    activeGateways: () => [],
    pendingDeposit: null,
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

type TabType = 'info' | 'security' | 'identities' | 'sessions' | 'wallet' | 'danger';

const resolveTab = (tab?: string): TabType => {
    if (tab === 'security' || tab === 'password' || tab === 'two_factor') return 'security';
    if (tab === 'identities' || tab === 'connections') return 'identities';
    if (tab === 'sessions' || tab === 'devices') return 'sessions';
    if (tab === 'wallet' || tab === 'charge' || tab === 'transactions') return 'wallet';
    if (tab === 'danger' || tab === 'delete') return 'danger';
    return 'info';
};

const activeTab = ref<TabType>(resolveTab(props.initialTab));

watch(
    () => props.initialTab,
    (val) => {
        activeTab.value = resolveTab(val);
    }
);

const creditBalance = computed(() => {
    const raw = Number(props.wallet?.balance || 0);
    return Math.floor(raw);
});
</script>

<template>
    <Head title="تنظیمات حساب کاربری و پروفایل" />

    <UserLayout>
        <div class="max-w-6xl mx-auto space-y-6 pb-16">
            <!-- 1. Header Profile Identity Banner -->
            <div class="p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-white/10 shadow-sm relative overflow-hidden">
                <!-- Background decorative elements -->
                <div class="absolute -top-24 -right-24 w-64 h-64 bg-indigo-500/10 dark:bg-indigo-500/20 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-purple-500/10 dark:bg-purple-500/20 rounded-full blur-3xl pointer-events-none"></div>

                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <!-- Avatar -->
                        <div class="relative shrink-0">
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl sm:rounded-3xl p-1 bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 shadow-lg shadow-indigo-500/25">
                                <div class="w-full h-full rounded-[14px] sm:rounded-[20px] bg-slate-100 dark:bg-slate-900 flex items-center justify-center font-black text-2xl sm:text-3xl text-slate-800 dark:text-white">
                                    {{ user?.name ? user.name.charAt(0) : 'U' }}
                                </div>
                            </div>
                            <span class="status-dot-pulse absolute -bottom-0.5 -right-0.5 border-2 border-white dark:border-slate-900" title="وضعیت آنلاین"></span>
                        </div>

                        <!-- User Info -->
                        <div class="space-y-1">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <h1 class="text-lg sm:text-xl font-black text-slate-900 dark:text-white">
                                    {{ user?.name || 'کاربر گرامی' }}
                                </h1>
                                <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-black bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                                    {{ user?.role === 'admin' ? 'راهبر ارشد' : 'کاربر تایید شده' }}
                                </span>
                            </div>

                            <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400 font-mono flex-wrap" dir="ltr">
                                <span>@{{ user?.username }}</span>
                                <span v-if="user?.email">• {{ user?.email }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Metrics Strip -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-4 md:pt-0 border-t md:border-t-0 border-slate-100 dark:border-slate-800">
                        <!-- Credit Metric -->
                        <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 text-right">
                            <span class="text-[10px] text-slate-400 font-medium block">اعتبار کیف پول</span>
                            <span class="text-sm font-black text-amber-600 dark:text-amber-400 font-mono mt-0.5 block">
                                {{ creditBalance }} واحد
                            </span>
                        </div>

                        <!-- Lines Metric -->
                        <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 text-right">
                            <span class="text-[10px] text-slate-400 font-medium block">خطوط فعال</span>
                            <span class="text-sm font-black text-slate-900 dark:text-white font-mono mt-0.5 block">
                                {{ stats.active_lines }} / {{ stats.max_lines }}
                            </span>
                        </div>

                        <!-- Sessions Metric -->
                        <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 text-right">
                            <span class="text-[10px] text-slate-400 font-medium block">نشست‌های فعال</span>
                            <span class="text-sm font-black text-cyan-600 dark:text-cyan-400 font-mono mt-0.5 block">
                                {{ stats.active_sessions }} دستگاه
                            </span>
                        </div>

                        <!-- Identities Metric -->
                        <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/60 text-right">
                            <span class="text-[10px] text-slate-400 font-medium block">روش‌های متصل</span>
                            <span class="text-sm font-black text-emerald-600 dark:text-emerald-400 font-mono mt-0.5 block">
                                {{ stats.connected_identities }} هویت
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Main Two-Column Navigation & Content Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- Sidebar Tabs Navigation (RTL Right side: 4 cols on desktop) -->
                <aside class="lg:col-span-4 space-y-2">
                    <div class="p-2 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-white/10 shadow-sm space-y-1">
                        <!-- 1. Profile Info -->
                        <button
                            type="button"
                            @click="activeTab = 'info'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'info'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25 font-black'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'info' ? 'bg-white/20 text-white' : 'bg-indigo-500/10 text-indigo-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">اطلاعات کاربری</span>
                                    <span class="text-[10px] opacity-75 font-normal block">مشخصات هویتی و نام نمایشی</span>
                                </div>
                            </div>
                            <svg class="w-4 h-4 rtl:rotate-180 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- 2. Security & Password -->
                        <button
                            type="button"
                            @click="activeTab = 'security'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'security'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25 font-black'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'security' ? 'bg-white/20 text-white' : 'bg-emerald-500/10 text-emerald-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">امنیت و رمز عبور</span>
                                    <span class="text-[10px] opacity-75 font-normal block">تغییر گذرواژه و احراز دومرحله‌ای</span>
                                </div>
                            </div>
                            <svg class="w-4 h-4 rtl:rotate-180 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- 3. Connected Identities -->
                        <button
                            type="button"
                            @click="activeTab = 'identities'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'identities'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25 font-black'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'identities' ? 'bg-white/20 text-white' : 'bg-blue-500/10 text-blue-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">اتصال حساب‌ها و هویت‌ها</span>
                                    <span class="text-[10px] opacity-75 font-normal block">ایمیل، سازمانی LDAP و Keycloak</span>
                                </div>
                            </div>
                            <svg class="w-4 h-4 rtl:rotate-180 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- 4. Active Sessions -->
                        <button
                            type="button"
                            @click="activeTab = 'sessions'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'sessions'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25 font-black'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'sessions' ? 'bg-white/20 text-white' : 'bg-cyan-500/10 text-cyan-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">دستگاه‌ها و نشست‌های فعال</span>
                                    <span class="text-[10px] opacity-75 font-normal block">مدیریت دستگاه‌ها و خروج همگانی</span>
                                </div>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-bold font-mono" :class="activeTab === 'sessions' ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-300'">
                                {{ stats.active_sessions }}
                            </span>
                        </button>

                        <!-- 5. Wallet & Recharge -->
                        <button
                            type="button"
                            @click="activeTab = 'wallet'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'wallet'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25 font-black'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'wallet' ? 'bg-white/20 text-white' : 'bg-amber-500/10 text-amber-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">کیف پول و شارژ حساب</span>
                                    <span class="text-[10px] opacity-75 font-normal block">افزایش موجودی و تراکنش‌ها</span>
                                </div>
                            </div>
                            <svg class="w-4 h-4 rtl:rotate-180 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- 6. Danger Zone -->
                        <button
                            type="button"
                            @click="activeTab = 'danger'"
                            class="w-full p-3 rounded-2xl text-right flex items-center justify-between gap-3 transition-all cursor-pointer"
                            :class="activeTab === 'danger'
                                ? 'bg-rose-600 text-white shadow-md shadow-rose-600/25 font-black'
                                : 'text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 font-bold'"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" :class="activeTab === 'danger' ? 'bg-white/20 text-white' : 'bg-rose-500/10 text-rose-500'">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </div>
                                <div>
                                    <span class="text-xs block">حذف حساب کاربری</span>
                                    <span class="text-[10px] opacity-75 font-normal block">بستن دائمی و آزادسازی خطوط</span>
                                </div>
                            </div>
                            <svg class="w-4 h-4 rtl:rotate-180 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </aside>

                <!-- Main Content Pane (8 cols on desktop) -->
                <main class="lg:col-span-8">
                    <div class="p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-white/10 shadow-sm min-h-[420px]">
                        <!-- TAB 1: Profile Information -->
                        <div v-show="activeTab === 'info'">
                            <UpdateProfileInformationForm :status="status" />
                        </div>

                        <!-- TAB 2: Security & Password -->
                        <div v-show="activeTab === 'security'" class="space-y-10">
                            <UpdatePasswordForm />
                            <div class="border-t border-slate-200/70 dark:border-slate-800"></div>
                            <TwoFactorAuthenticationForm />
                        </div>

                        <!-- TAB 3: Connected Identities -->
                        <div v-show="activeTab === 'identities'">
                            <ConnectedIdentitiesForm
                                :identities="identities"
                                :authSettings="authSettings"
                                :hasPassword="hasPassword"
                                :keycloakLinkUrl="keycloakLinkUrl"
                            />
                        </div>

                        <!-- TAB 4: Active Sessions -->
                        <div v-show="activeTab === 'sessions'">
                            <ActiveSessionsForm
                                :sessions="sessions"
                                :hasPassword="hasPassword"
                            />
                        </div>

                        <!-- TAB 5: Wallet & Recharge -->
                        <div v-show="activeTab === 'wallet'">
                            <WalletSection
                                :wallet="wallet"
                                :transactions="transactions"
                                :activeGateways="activeGateways"
                                :pendingDeposit="pendingDeposit"
                                :initialView="initialView"
                            />
                        </div>

                        <!-- TAB 6: Danger Zone (Delete Account) -->
                        <div v-show="activeTab === 'danger'">
                            <DeleteUserForm />
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </UserLayout>
</template>
