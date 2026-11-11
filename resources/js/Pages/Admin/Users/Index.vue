<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

interface UserPhoneNumber {
    id: number;
    number: string;
    formatted_number: string;
    type: string;
    type_label: string;
    type_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    display_title: string | null;
    allocated_at: string | null;
}

interface UserServiceAllocation {
    id: number;
    service_key: string | null;
    service_title: string;
    resource_type: string;
    identifier: string;
    display_title: string | null;
    status: string;
    quota_total: number;
    quota_used: number;
    allocated_at: string | null;
}

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    account_tier: string;
    organization_name: string | null;
    enterprise_contract_code: string | null;
    status: string;
    created_at: string;
    wallet_balance: string;
    phone_numbers_count: number;
    service_allocations_count: number;
    phone_numbers: UserPhoneNumber[];
    service_allocations: UserServiceAllocation[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    users: {
        data: UserItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        role?: string;
        account_tier?: string;
        status?: string;
        has_numbers?: string;
        sort?: string;
    };
    stats: {
        total: number;
        active: number;
        suspended: number;
        admins: number;
        enterprise?: number;
    };
    roles: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

// Search & filter states
const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const accountTier = ref(props.filters.account_tier || '');
const status = ref(props.filters.status || '');
const hasNumbers = ref(props.filters.has_numbers || '');
const sort = ref(props.filters.sort || 'newest');

function applyFilters() {
    router.get(
        route('admin.users.index'),
        {
            search: search.value || undefined,
            role: role.value || undefined,
            account_tier: accountTier.value || undefined,
            status: status.value || undefined,
            has_numbers: hasNumbers.value || undefined,
            sort: sort.value !== 'newest' ? sort.value : undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    role.value = '';
    accountTier.value = '';
    status.value = '';
    hasNumbers.value = '';
    sort.value = 'newest';
    applyFilters();
}

function changeStatus(user: UserItem, newStatus: string) {
    if (!confirm(__('admin.users.confirm_status_change', { username: user.username, status: formatStatus(newStatus) }))) {
        return;
    }

    router.patch(route('admin.users.status', user.id), {
        status: newStatus,
    }, {
        preserveScroll: true,
    });
}

function formatRole(r: string): string {
    return r === 'admin' ? __('admin.users.role_admin') : __('admin.users.role_user');
}

function formatStatus(s: string): string {
    if (s === 'active') return __('admin.users.status_active');
    if (s === 'suspended') return __('admin.users.status_suspended');
    return __('admin.users.status_inactive');
}

// Edit User Modal State
const isEditModalOpen = ref(false);
const editingUser = ref<UserItem | null>(null);

const editForm = useForm({
    name: '',
    username: '',
    role: 'user',
    account_tier: 'individual',
    organization_name: '',
    enterprise_contract_code: '',
    status: 'active',
    password: '',
});

function openEditModal(user: UserItem) {
    editingUser.value = user;
    editForm.reset();
    editForm.name = user.name;
    editForm.username = user.username;
    editForm.role = user.role;
    editForm.account_tier = user.account_tier || 'individual';
    editForm.organization_name = user.organization_name || '';
    editForm.enterprise_contract_code = user.enterprise_contract_code || '';
    editForm.status = user.status;
    editForm.password = '';
    editForm.clearErrors();
    isEditModalOpen.value = true;
}

function closeEditModal() {
    isEditModalOpen.value = false;
    editingUser.value = null;
    editForm.reset();
}

function submitEdit() {
    if (!editingUser.value) return;

    editForm.patch(route('admin.users.update', editingUser.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error && Object.keys(editForm.errors).length === 0) {
                closeEditModal();
            }
        },
    });
}

// User Numbers Modal State
const isNumbersModalOpen = ref(false);
const selectedUserForNumbers = ref<UserItem | null>(null);

function openNumbersModal(user: UserItem) {
    selectedUserForNumbers.value = user;
    isNumbersModalOpen.value = true;
}

function closeNumbersModal() {
    isNumbersModalOpen.value = false;
    selectedUserForNumbers.value = null;
}

// User Wallet Modal State (Requirement: Manage balance directly from /admin/users)
const isWalletModalOpen = ref(false);
const walletTargetUser = ref<UserItem | null>(null);

const walletForm = useForm({
    action: 'credit', // 'credit' | 'debit' | 'set'
    amount: '',
    reason: __('admin.users.balance_adjust_reason'),
});

function openWalletModal(user: UserItem) {
    walletTargetUser.value = user;
    walletForm.reset();
    walletForm.action = 'credit';
    walletForm.amount = '';
    walletForm.reason = __('admin.users.balance_topup_reason');
    walletForm.clearErrors();
    isWalletModalOpen.value = true;
}

function closeWalletModal() {
    isWalletModalOpen.value = false;
    walletTargetUser.value = null;
    walletForm.reset();
}

function submitWallet() {
    if (!walletTargetUser.value) return;

    walletForm.post(route('admin.users.wallet.adjust', walletTargetUser.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error && Object.keys(walletForm.errors).length === 0) {
                closeWalletModal();
            }
        },
    });
}

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isEditModalOpen.value) closeEditModal();
        if (isNumbersModalOpen.value) closeNumbersModal();
        if (isWalletModalOpen.value) closeWalletModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <Head :title="__('admin.users.head_title')" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">{{ __('admin.users.main_title') }}</h1>
        </template>

        <div class="space-y-6">
            <!-- Header Section with Action Button inside Body -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                        {{ __('admin.users.main_title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('admin.users.subtitle') }}
                    </p>
                </div>

                <Link
                    :href="route('admin.users.create')"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-500/20 transition-all shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ __('admin.users.create_btn') }}</span>
                </Link>
            </div>

            <!-- Stats Metric Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.users.total_users') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-slate-900 dark:text-white block text-right">{{ stats.total }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.users.active_users') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-emerald-600 dark:text-emerald-400 block text-right">{{ stats.active }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.users.enterprise_users') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-purple-600 dark:text-purple-400 block text-right">{{ stats.enterprise ?? 0 }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.users.suspended_users') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400 block text-right">{{ stats.suspended }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">{{ __('admin.users.admin_users') }}</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-rose-600 dark:text-rose-400 block text-right">{{ stats.admins }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Comprehensive Search & Filter Bar -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                    <!-- Search Input (Searches name, username, ID, organization, and service identifier) -->
                    <div class="lg:col-span-2 relative">
                        <input
                            type="text"
                            v-model="search"
                            @keyup.enter="applyFilters"
                            :placeholder="__('admin.users.search_placeholder')"
                            class="w-full pr-10 pl-4 py-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 outline-none"
                        />
                        <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    <!-- Role Filter -->
                    <div>
                        <select
                            v-model="role"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">{{ __('admin.users.all_roles') }}</option>
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>

                    <!-- Account Tier Filter -->
                    <div>
                        <select
                            v-model="accountTier"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">{{ __('admin.users.all_tiers') }}</option>
                            <option value="individual">{{ __('admin.users.tier_individual') }}</option>
                            <option value="enterprise">{{ __('admin.users.tier_enterprise') }}</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <select
                            v-model="status"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">{{ __('admin.users.all_statuses') }}</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>

                    <!-- Line / Service Ownership Filter -->
                    <div>
                        <select
                            v-model="hasNumbers"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">{{ __('admin.users.lines_status') }}</option>
                            <option value="yes">{{ __('admin.users.has_lines') }}</option>
                            <option value="no">{{ __('admin.users.no_lines') }}</option>
                        </select>
                    </div>
                </div>

                <!-- Sort and Filter Action Buttons -->
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-800/80">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400 font-bold">{{ __('admin.users.sort_label') }}</span>
                        <select
                            v-model="sort"
                            @change="applyFilters"
                            class="form-select !py-1.5 !px-3 !text-xs !rounded-xl w-auto"
                        >
                            <option value="newest">{{ __('admin.users.sort_newest') }}</option>
                            <option value="oldest">{{ __('admin.users.sort_oldest') }}</option>
                            <option value="name">{{ __('admin.users.sort_name') }}</option>
                            <option value="username">{{ __('admin.users.sort_username') }}</option>
                            <option value="numbers_count">{{ __('admin.users.sort_numbers_count') }}</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="applyFilters"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-xs font-bold text-white transition-colors cursor-pointer"
                        >{{ __('admin.users.apply_filters') }}</button>

                        <button
                            v-if="search || role || accountTier || status || hasNumbers || sort !== 'newest'"
                            type="button"
                            @click="clearFilters"
                            class="px-3 py-2 rounded-xl text-xs font-bold text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
                        >{{ __('admin.users.clear_filters') }}</button>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/70 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_id') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_name') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_username') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_role') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_tier') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_status') }}</th>
                                <th class="py-3.5 px-5 font-bold text-center">{{ __('admin.users.th_lines') }}</th>
                                <th class="py-3.5 px-5 font-bold text-center">{{ __('admin.users.th_balance') }}</th>
                                <th class="py-3.5 px-5 font-bold">{{ __('admin.users.th_created') }}</th>
                                <th class="py-3.5 px-5 font-bold text-left">{{ __('admin.users.th_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="py-4 px-5 text-slate-400 font-mono text-[11px]">{{ user.id }}</td>
                                <td class="py-4 px-5">
                                    <div class="font-bold text-slate-900 dark:text-white">{{ user.name }}</div>
                                    <div v-if="user.organization_name" class="text-[10px] text-purple-600 dark:text-purple-400 font-semibold mt-0.5">
                                        🏢 {{ user.organization_name }}
                                    </div>
                                </td>
                                <td class="py-4 px-5 font-mono text-slate-700 dark:text-slate-300" dir="ltr">@{{ user.username }}</td>
                                <td class="py-4 px-5">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                        :class="user.role === 'admin' ? 'bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                                    >
                                        {{ formatRole(user.role) }}
                                    </span>
                                </td>
                                <td class="py-4 px-5">
                                    <div class="flex flex-col gap-1 items-start">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                            :class="user.account_tier === 'enterprise'
                                                ? 'bg-purple-100 dark:bg-purple-950/60 text-purple-700 dark:text-purple-400 border border-purple-200 dark:border-purple-800'
                                                : 'bg-slate-100 dark:bg-slate-800 text-slate-500'"
                                        >
                                            {{ user.account_tier === 'enterprise' ? __('admin.users.tier_enterprise') : __('admin.users.tier_individual') }}
                                        </span>
                                        <span v-if="user.enterprise_contract_code" class="text-[10px] font-mono text-slate-400" dir="ltr">
                                            {{ user.enterprise_contract_code }}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-4 px-5">
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                        :class="{
                                            'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400': user.status === 'active',
                                            'bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400': user.status === 'suspended',
                                            'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400': user.status === 'inactive'
                                        }"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :class="{
                                                'bg-emerald-500': user.status === 'active',
                                                'bg-amber-500': user.status === 'suspended',
                                                'bg-slate-400': user.status === 'inactive'
                                            }"
                                        ></span>
                                        <span>{{ formatStatus(user.status) }}</span>
                                    </span>
                                </td>
                                <td class="py-4 px-5 text-center">
                                    <button
                                        type="button"
                                        @click="openNumbersModal(user)"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold transition-all cursor-pointer"
                                        :class="[
                                            (user.service_allocations_count > 0 || user.phone_numbers_count > 0)
                                                ? 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60 hover:bg-indigo-100'
                                                : 'bg-slate-100 dark:bg-slate-800/60 text-slate-400 hover:text-slate-600'
                                        ]"
                                        :title="__('admin.users.view_numbers_tooltip')"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6z" />
                                        </svg>
                                        <span>{{ __('admin.users.services_count', { count: Math.max(user.service_allocations_count || 0, user.phone_numbers_count || 0) }) }}</span>
                                    </button>
                                </td>
                                <td class="py-4 px-5 text-center">
                                    <button
                                        type="button"
                                        @click="openWalletModal(user)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl text-xs font-bold transition-all cursor-pointer bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border border-amber-200/80 dark:border-amber-800/60 hover:bg-amber-100 dark:hover:bg-amber-900/40"
                                        :title="__('admin.users.charge_balance_tooltip')"
                                    >
                                        <span class="font-mono text-xs font-black">{{ user.wallet_balance }}</span>
                                        <span class="text-[10px]">{{ __('admin.users.unit') }}</span>
                                    </button>
                                </td>
                                <td class="py-4 px-5 text-slate-400 font-mono text-[11px]" dir="ltr">
                                    {{ user.created_at }}
                                </td>
                                <td class="py-4 px-5 text-left">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Adjust Wallet Button -->
                                        <button
                                            type="button"
                                            @click="openWalletModal(user)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors cursor-pointer"
                                            :title="__('admin.users.charge_balance_btn')"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                                            </svg>
                                            <span>{{ __('admin.users.balance_action_title') }}</span>
                                        </button>

                                        <!-- Edit User Button (Requirement 2) -->
                                        <button
                                            type="button"
                                            @click="openEditModal(user)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 transition-colors cursor-pointer"
                                            :title="__('admin.users.edit_user_tooltip')"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            <span>{{ __('admin.users.edit') }}</span>
                                        </button>

                                        <!-- Suspend / Activate Button -->
                                        <button
                                            v-if="user.status === 'active'"
                                            type="button"
                                            @click="changeStatus(user, 'suspended')"
                                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 transition-colors cursor-pointer"
                                            :title="__('admin.users.suspend_tooltip')"
                                        >{{ __('admin.users.suspend') }}</button>
                                        <button
                                            v-if="user.status === 'suspended' || user.status === 'inactive'"
                                            type="button"
                                            @click="changeStatus(user, 'active')"
                                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 transition-colors cursor-pointer"
                                            :title="__('admin.users.activate_tooltip')"
                                        >{{ __('admin.users.activate') }}</button>

                                        <!-- User Audit Trail Link -->
                                        <Link
                                            :href="route('admin.audit-logs.index', { user_id: user.id })"
                                            class="p-1 rounded-lg text-slate-400 hover:text-amber-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                            :title="__('admin.users.audit_tooltip')"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="users.data.length === 0">
                                <td colspan="10" class="py-12 text-center text-slate-400">
                                    {{ __('admin.users.no_users_found') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="users.last_page > 1" class="p-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                    <div>
                        {{ __('admin.users.pagination_summary', { current: users.current_page, last: users.last_page, total: users.total }) }}
                    </div>
                    <div class="flex items-center gap-1">
                        <Link
                            v-for="(link, i) in users.links"
                            :key="i"
                            :href="link.url || '#'"
                            v-html="link.label"
                            class="px-3 py-1.5 rounded-lg font-medium transition-colors"
                            :class="[
                                link.active ? 'bg-indigo-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800',
                                !link.url ? 'opacity-30 pointer-events-none' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Full User Edit Modal (Requirement 2: Full management, change username, role, account_tier, organization, status, password) -->
        <div v-if="isEditModalOpen && editingUser" @click.self="closeEditModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-start sm:items-center justify-center p-3 sm:p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-lg rounded-3xl shadow-2xl animate-in fade-in zoom-in-95 duration-150 max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>{{ __('admin.users.modal_edit_title') }}</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            {{ __('admin.users.system_id', { id: editingUser.id }) }}
                        </span>
                    </div>
                    <button @click="closeEditModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitEdit" class="p-6 space-y-4 overflow-y-auto flex-1">
                    <!-- General Error Alert -->
                    <div v-if="$page.props.flash?.error" class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ $page.props.flash.error }}</span>
                    </div>

                    <!-- Name -->
                    <div>
                        <label class="form-label mb-1.5">
                            {{ __('admin.users.name_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="editForm.name"
                            type="text"
                            required
                            class="form-input w-full"
                        />
                        <span v-if="editForm.errors.name" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.name }}
                        </span>
                    </div>

                    <!-- Username (Editable by Admin) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label">
                                {{ __('admin.users.username_label') }} <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-indigo-600 dark:text-indigo-400 font-bold bg-indigo-50 dark:bg-indigo-950/50 px-2 py-0.5 rounded">{{ __('admin.users.username_editable_badge') }}</span>
                        </div>
                        <input
                            v-model="editForm.username"
                            type="text"
                            dir="ltr"
                            required
                            class="form-input w-full font-mono text-left"
                        />
                        <span v-if="editForm.errors.username" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.username }}
                        </span>
                    </div>

                    <!-- Role & Status -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label mb-1.5">
                                {{ __('admin.users.role_label') }} <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-model="editForm.role"
                                class="form-select w-full"
                            >
                                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                            </select>
                            <span v-if="editForm.errors.role" class="text-xs text-rose-500 block mt-1">
                                {{ editForm.errors.role }}
                            </span>
                        </div>

                        <div>
                            <label class="form-label mb-1.5">
                                {{ __('admin.users.status_label') }} <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-model="editForm.status"
                                class="form-select w-full"
                            >
                                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                            <span v-if="editForm.errors.status" class="text-xs text-rose-500 block mt-1">
                                {{ editForm.errors.status }}
                            </span>
                        </div>
                    </div>

                    <!-- Account Tier, Organization & Enterprise Contract Code -->
                    <div class="p-4 rounded-2xl bg-purple-50/40 dark:bg-purple-950/20 border border-purple-200/60 dark:border-purple-800/40 space-y-3">
                        <div>
                            <label class="form-label mb-1.5">
                                {{ __('admin.users.account_tier_label') }}
                            </label>
                            <select v-model="editForm.account_tier" class="form-select w-full">
                                <option value="individual">{{ __('admin.users.tier_individual') }}</option>
                                <option value="enterprise">{{ __('admin.users.tier_enterprise') }}</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label mb-1">
                                    {{ __('admin.users.organization_name_label') }}
                                </label>
                                <input
                                    v-model="editForm.organization_name"
                                    type="text"
                                    :placeholder="__('admin.users.organization_name_ph')"
                                    class="form-input w-full text-xs"
                                />
                            </div>
                            <div>
                                <label class="form-label mb-1">
                                    {{ __('admin.users.contract_code_label') }}
                                </label>
                                <input
                                    v-model="editForm.enterprise_contract_code"
                                    type="text"
                                    dir="ltr"
                                    placeholder="ENT-2026-001"
                                    class="form-input w-full text-xs font-mono text-left"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- New Password (Optional) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label">
                                {{ __('admin.users.change_password_label') }}
                            </label>
                            <span class="text-[11px] text-slate-400">{{ __('admin.users.password_optional') }}</span>
                        </div>
                        <input
                            v-model="editForm.password"
                            type="password"
                            dir="ltr"
                            :placeholder="__('admin.users.password_min_placeholder')"
                            class="form-input w-full font-mono text-left"
                        />
                        <span v-if="editForm.errors.password" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.password }}
                        </span>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                        >{{ __('admin.users.cancel') }}</button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="editForm.processing" class="animate-spin -ml-1 mr-1 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ editForm.processing ? __('admin.users.saving') : __('admin.users.save_changes') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- User Services & Numbers Modal -->
        <div v-if="isNumbersModalOpen && selectedUserForNumbers" @click.self="closeNumbersModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-start sm:items-center justify-center p-3 sm:p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-3xl rounded-3xl shadow-2xl animate-in fade-in zoom-in-95 duration-150 max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>{{ __('admin.users.modal_numbers_title') }}</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            {{ __('admin.users.user_info', { name: selectedUserForNumbers.name, username: selectedUserForNumbers.username }) }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('admin.services.index', { tab: 'allocations', user_id: selectedUserForNumbers.id })"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm transition-colors"
                        >
                            <span>{{ __('admin.users.manage_subscriber_services') }}</span>
                        </Link>
                        <button @click="closeNumbersModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="p-6 overflow-y-auto flex-1 space-y-6">
                    <!-- Multi-Service Resource Allocations -->
                    <div v-if="selectedUserForNumbers.service_allocations && selectedUserForNumbers.service_allocations.length > 0">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-300 mb-3">{{ __('admin.users.allocated_services_heading') }}</h4>
                        <div class="overflow-x-auto rounded-2xl border border-slate-100 dark:border-slate-800">
                            <table class="w-full text-right text-xs">
                                <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                                    <tr>
                                        <th class="py-2.5 px-4">{{ __('admin.services.th_identifier') }}</th>
                                        <th class="py-2.5 px-4">{{ __('admin.services.th_service') }}</th>
                                        <th class="py-2.5 px-4 text-center">{{ __('admin.services.th_quota') }}</th>
                                        <th class="py-2.5 px-4 text-center">{{ __('admin.services.th_status') }}</th>
                                        <th class="py-2.5 px-4">{{ __('admin.users.th_num_allocated') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr v-for="alloc in selectedUserForNumbers.service_allocations" :key="alloc.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="py-2.5 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                            {{ alloc.identifier }}
                                            <div v-if="alloc.display_title" class="text-[10px] font-sans text-slate-400">{{ alloc.display_title }}</div>
                                        </td>
                                        <td class="py-2.5 px-4 font-bold text-indigo-600 dark:text-indigo-400">
                                            {{ alloc.service_title }}
                                        </td>
                                        <td class="py-2.5 px-4 text-center font-mono">
                                            <span v-if="alloc.resource_type === 'web3_domain_suite'" class="px-2 py-0.5 rounded bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 font-bold">
                                                {{ alloc.quota_used }} / {{ alloc.quota_total }}
                                            </span>
                                            <span v-else class="text-slate-400">1 / 1</span>
                                        </td>
                                        <td class="py-2.5 px-4 text-center">
                                            <span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold"
                                                :class="alloc.status === 'active' ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400' : 'bg-slate-100 text-slate-500'"
                                            >
                                                {{ alloc.status }}
                                            </span>
                                        </td>
                                        <td class="py-2.5 px-4 font-mono text-slate-400" dir="ltr">
                                            {{ alloc.allocated_at || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Phone Numbers Table -->
                    <div v-if="selectedUserForNumbers.phone_numbers && selectedUserForNumbers.phone_numbers.length > 0" class="overflow-x-auto">
                        <h4 class="text-xs font-black text-slate-700 dark:text-slate-300 mb-3">{{ __('admin.users.allocated_lines_heading') }}</h4>
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="py-3 px-4">{{ __('admin.users.th_num_number') }}</th>
                                    <th class="py-3 px-4">{{ __('admin.users.th_num_type') }}</th>
                                    <th class="py-3 px-4">{{ __('admin.users.th_num_status') }}</th>
                                    <th class="py-3 px-4">{{ __('admin.users.th_num_service') }}</th>
                                    <th class="py-3 px-4">{{ __('admin.users.th_num_allocated') }}</th>
                                    <th class="py-3 px-4 text-left">{{ __('admin.users.th_num_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="num in selectedUserForNumbers.phone_numbers" :key="num.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                        {{ num.formatted_number || num.number }}
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="num.type_badge">
                                            {{ num.type_label }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="num.status_badge">
                                            {{ num.status_label }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600 dark:text-slate-300">
                                        {{ num.display_title || '—' }}
                                    </td>
                                    <td class="py-3 px-4 font-mono text-slate-400" dir="ltr">
                                        {{ num.allocated_at || '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-left">
                                        <Link
                                            :href="route('admin.numbers.index', { search: num.number })"
                                            class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                                        >
                                            <span>{{ __('admin.users.manage_line') }}</span>
                                            <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                            </svg>
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="(!selectedUserForNumbers.phone_numbers || selectedUserForNumbers.phone_numbers.length === 0) && (!selectedUserForNumbers.service_allocations || selectedUserForNumbers.service_allocations.length === 0)" class="text-center py-10">
                        <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mx-auto mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800 dark:text-white">{{ __('admin.users.no_lines_for_user') }}</h4>
                        <p class="text-xs text-slate-400 mt-1">
                            {{ __('admin.users.no_lines_for_user_desc') }}
                        </p>
                    </div>

                    <div class="pt-4 mt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end">
                        <button
                            type="button"
                            @click="closeNumbersModal"
                            class="px-5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors cursor-pointer"
                        >{{ __('admin.users.close') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Wallet Balance Adjustment Modal (Direct balance control from /admin/users) -->
        <div v-if="isWalletModalOpen && walletTargetUser" @click.self="closeWalletModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-start sm:items-center justify-center p-3 sm:p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-3xl shadow-2xl animate-in fade-in zoom-in-95 duration-150 flex flex-col my-auto">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>{{ __('admin.users.modal_balance_title') }}</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            {{ walletTargetUser.name }} (@{{ walletTargetUser.username }})
                        </span>
                    </div>
                    <button @click="closeWalletModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitWallet" class="p-6 space-y-4">
                    <!-- Current Balance Indicator -->
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/25 flex items-center justify-between">
                        <div>
                            <span class="text-xs text-amber-800 dark:text-amber-300 font-bold block">{{ __('admin.users.current_balance_label') }}</span>
                            <span class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400 mt-0.5 block">
                                {{ walletTargetUser.wallet_balance }}
                                <span class="text-xs font-bold mr-1">{{ __('admin.users.credit_units') }}</span>
                            </span>
                        </div>
                        <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center text-lg">
                            🪙
                        </div>
                    </div>

                    <!-- Action Type Selector -->
                    <div>
                        <label class="form-label mb-1.5 font-bold">{{ __('admin.users.operation_type') }}</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                type="button"
                                @click="walletForm.action = 'credit'"
                                class="py-2 px-2.5 rounded-xl text-xs font-bold border transition-all text-center cursor-pointer"
                                :class="walletForm.action === 'credit'
                                    ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm'
                                    : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700'"
                            >
                                {{ __('admin.users.op_increase') }}
                            </button>
                            <button
                                type="button"
                                @click="walletForm.action = 'debit'"
                                class="py-2 px-2.5 rounded-xl text-xs font-bold border transition-all text-center cursor-pointer"
                                :class="walletForm.action === 'debit'
                                    ? 'bg-rose-600 text-white border-rose-600 shadow-sm'
                                    : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700'"
                            >
                                {{ __('admin.users.op_decrease') }}
                            </button>
                            <button
                                type="button"
                                @click="walletForm.action = 'set'"
                                class="py-2 px-2.5 rounded-xl text-xs font-bold border transition-all text-center cursor-pointer"
                                :class="walletForm.action === 'set'
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                                    : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700'"
                            >
                                {{ __('admin.users.op_set') }}
                            </button>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="form-label mb-1.5 font-bold">
                            {{ walletForm.action === 'set' ? __('admin.users.amount_set_label') : __('admin.users.amount_delta_label') }}
                            <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="walletForm.amount"
                            type="number"
                            step="0.0001"
                            min="0"
                            required
                            :placeholder="__('admin.users.amount_placeholder')"
                            class="form-input w-full font-mono text-left"
                            dir="ltr"
                        />
                        <span v-if="walletForm.errors.amount" class="text-xs text-rose-500 block mt-1">
                            {{ walletForm.errors.amount }}
                        </span>
                    </div>

                    <!-- Reason / Note -->
                    <div>
                        <label class="form-label mb-1.5 font-bold">
                            {{ __('admin.users.reason_label') }} <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="walletForm.reason"
                            type="text"
                            required
                            :placeholder="__('admin.users.reason_placeholder')"
                            class="form-input w-full text-xs"
                        />
                        <span v-if="walletForm.errors.reason" class="text-xs text-rose-500 block mt-1">
                            {{ walletForm.errors.reason }}
                        </span>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2.5">
                        <button
                            type="button"
                            @click="closeWalletModal"
                            class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors cursor-pointer"
                        >{{ __('admin.users.cancel') }}</button>
                        <button
                            type="submit"
                            :disabled="walletForm.processing"
                            class="px-5 py-2 rounded-xl text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white transition-all shadow-md shadow-amber-600/20 disabled:opacity-50 cursor-pointer flex items-center gap-1.5"
                        >
                            <span v-if="walletForm.processing">{{ __('admin.users.applying') }}</span>
                            <span v-else>{{ __('admin.users.apply_balance_changes') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
