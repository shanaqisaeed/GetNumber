<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import TwoFactorAuthenticationForm from './Partials/TwoFactorAuthenticationForm.vue';
import ActiveSessionsForm from './Partials/ActiveSessionsForm.vue';
import ConnectedIdentitiesForm from './Partials/ConnectedIdentitiesForm.vue';
import WalletRechargeTab from './Partials/WalletRechargeTab.vue';
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

interface PendingDeposit {
    tracking_code: string;
    requested_amount: string;
    status_label: string;
    created_at: string;
}

interface Props {
    status?: string;
    initialTab?: string;
    wallet?: { balance: string };
    stats?: {
        active_lines: number;
        max_lines: number;
        active_sessions: number;
        connected_identities: number;
    };
    sessions?: SessionItem[];
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
    wallet: () => ({ balance: '0' }),
    stats: () => ({
        active_lines: 0,
        max_lines: 10,
        active_sessions: 1,
        connected_identities: 1,
    }),
    sessions: () => [],
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
    if (tab === 'wallet' || tab === 'charge' || tab === 'deposit') return 'wallet';
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
    return Math.floor(Number(props.wallet?.balance || 0));
});
</script>

<template>
    <Head title="تنظیمات پروفایل و کاربری" />

    <UserLayout>
        <div class="max-w-4xl mx-auto space-y-5 pb-16">
            <!-- 1. Clean Top Header Profile Card (No vertical text, no cramped pills) -->
            <div class="p-5 sm:p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 shadow-sm flex items-center justify-between gap-4 flex-wrap">
                <!-- User Avatar & Identity Details -->
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="relative shrink-0">
                        <div class="w-14 h-14 rounded-2xl p-0.5 bg-gradient-to-tr from-indigo-500 to-purple-600 shadow-md shadow-indigo-500/20">
                            <div class="w-full h-full rounded-[14px] bg-slate-100 dark:bg-slate-900 flex items-center justify-center font-black text-xl text-slate-800 dark:text-white">
                                {{ user?.name ? user.name.charAt(0) : 'U' }}
                            </div>
                        </div>
                        <span class="status-dot-pulse absolute -bottom-0.5 -right-0.5 border-2 border-white dark:border-slate-900" title="وضعیت آنلاین"></span>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-base font-black text-slate-900 dark:text-white truncate">
                                {{ user?.name || 'کاربر گرامی' }}
                            </h1>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 whitespace-nowrap">
                                {{ user?.role === 'admin' ? 'راهبر ارشد سامانه' : 'کاربر تایید شده' }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 font-mono mt-0.5" dir="ltr">@{{ user?.username }}</p>
                    </div>
                </div>

                <!-- Right / Left Horizontal Badges (Spacious, No line wrap) -->
                <div class="flex items-center gap-2.5 flex-wrap">
                    <button
                        type="button"
                        @click="activeTab = 'wallet'"
                        class="px-3.5 py-2 rounded-2xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/25 text-xs font-black flex items-center gap-1.5 transition-colors cursor-pointer whitespace-nowrap"
                    >
                        <span>🪙</span>
                        <span>اعتبار: {{ creditBalance }} TRX</span>
                    </button>

                    <div class="px-3.5 py-2 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 text-xs font-bold whitespace-nowrap">
                        <span>خطوط: {{ stats.active_lines }} از {{ stats.max_lines }}</span>
                    </div>
                </div>
            </div>

            <!-- 2. Top Segmented Navigation Tabs (Concise titles, NO verbose subtitles) -->
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-1.5 p-1.5 rounded-2xl bg-slate-200/80 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-xs font-bold">
                <!-- Tab 1: Info -->
                <button
                    type="button"
                    @click="activeTab = 'info'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'info'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm font-black'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>اطلاعات حساب</span>
                </button>

                <!-- Tab 2: Security -->
                <button
                    type="button"
                    @click="activeTab = 'security'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'security'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm font-black'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>امنیت و رمز</span>
                </button>

                <!-- Tab 3: Connected Identities -->
                <button
                    type="button"
                    @click="activeTab = 'identities'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'identities'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm font-black'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    <span>روش‌های ورود</span>
                </button>

                <!-- Tab 4: Active Sessions -->
                <button
                    type="button"
                    @click="activeTab = 'sessions'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'sessions'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm font-black'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>نشست‌ها</span>
                </button>

                <!-- Tab 5: Wallet Recharge -->
                <button
                    type="button"
                    @click="activeTab = 'wallet'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'wallet'
                        ? 'bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm font-black'
                        : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    <span>شارژ حساب</span>
                </button>

                <!-- Tab 6: Danger (Delete Account) -->
                <button
                    type="button"
                    @click="activeTab = 'danger'"
                    class="py-2.5 px-3 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer text-center"
                    :class="activeTab === 'danger'
                        ? 'bg-rose-600 text-white shadow-sm font-black'
                        : 'text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40'"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    <span>حذف حساب</span>
                </button>
            </div>

            <!-- 3. Active Tab Content Pane (Full width, plenty of breathing room) -->
            <div class="p-6 sm:p-7 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 shadow-sm min-h-[380px]">
                <!-- Tab 1: Profile Information -->
                <div v-show="activeTab === 'info'">
                    <UpdateProfileInformationForm :status="status" />
                </div>

                <!-- Tab 2: Security & Password -->
                <div v-show="activeTab === 'security'" class="space-y-8">
                    <UpdatePasswordForm />
                    <div class="border-t border-slate-100 dark:border-slate-800"></div>
                    <TwoFactorAuthenticationForm />
                </div>

                <!-- Tab 3: Connected Identities -->
                <div v-show="activeTab === 'identities'">
                    <ConnectedIdentitiesForm
                        :identities="identities"
                        :authSettings="authSettings"
                        :hasPassword="hasPassword"
                        :keycloakLinkUrl="keycloakLinkUrl"
                    />
                </div>

                <!-- Tab 4: Active Sessions -->
                <div v-show="activeTab === 'sessions'">
                    <ActiveSessionsForm
                        :sessions="sessions"
                        :hasPassword="hasPassword"
                    />
                </div>

                <!-- Tab 5: Wallet Recharge (Super clean: Amount -> TRX -> Pay, NO gateways) -->
                <div v-show="activeTab === 'wallet'">
                    <WalletRechargeTab
                        :wallet="wallet"
                        :pendingDeposit="pendingDeposit"
                    />
                </div>

                <!-- Tab 6: Delete Account -->
                <div v-show="activeTab === 'danger'">
                    <DeleteUserForm />
                </div>
            </div>
        </div>
    </UserLayout>
</template>
