<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { __ } = useTrans();

interface RoleOption {
    value: string;
    label: string;
}

interface StatusOption {
    value: string;
    label: string;
}

interface UserData {
    id: number;
    account_id: string;
    name: string;
    username: string;
    role: string;
    status: string;
    account_tier: string;
    organization_name: string | null;
    enterprise_contract_code: string | null;
    wallet_balance: string;
    created_at: string;
}

interface NumberItem {
    id: number;
    number: string;
    formatted_number: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    display_title: string | null;
    allocated_at: string | null;
}

interface ServiceAllocationItem {
    id: number;
    uuid: string;
    service_key: string;
    service_title: string;
    identifier: string;
    display_title: string;
    status: string;
    allocated_at: string | null;
}

interface AuditLogItem {
    id: number;
    action: string;
    description: string;
    created_at: string;
    actor: string;
}

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes: string | null;
    created_at: string;
}

const props = defineProps<{
    user: UserData;
    phone_numbers: NumberItem[];
    service_allocations: ServiceAllocationItem[];
    contacts?: ContactItem[];
    audit_logs: AuditLogItem[];
    roles: RoleOption[];
    statuses: StatusOption[];
}>();

const activeTab = ref<'profile' | 'wallet' | 'resources' | 'contacts' | 'audit'>('profile');

// User Profile & Account Form
const profileForm = useForm({
    name: props.user.name,
    username: props.user.username,
    role: props.user.role,
    status: props.user.status,
    account_tier: props.user.account_tier || 'individual',
    organization_name: props.user.organization_name || '',
    enterprise_contract_code: props.user.enterprise_contract_code || '',
    password: '',
});

const submitProfile = () => {
    profileForm.patch(route('admin.users.update', props.user.id), {
        preserveScroll: true,
        onSuccess: () => {
            profileForm.password = '';
        },
    });
};

// Wallet Adjustment Form
const walletForm = useForm({
    type: 'deposit' as 'deposit' | 'withdraw',
    amount: '',
    description: '',
});

const submitWalletAdjustment = () => {
    walletForm.post(route('admin.users.wallet.adjust', props.user.id), {
        preserveScroll: true,
        onSuccess: () => {
            walletForm.reset();
        },
    });
};

// Release Line / Resource Actions
const releaseNumber = (num: NumberItem) => {
    if (confirm(__('admin.users.edit_page.confirm_release_number', { number: num.formatted_number }))) {
        router.post(route('admin.numbers.release', num.id), {}, { preserveScroll: true });
    }
};

const releaseAllocation = (alloc: ServiceAllocationItem) => {
    if (confirm(__('admin.users.edit_page.confirm_release_alloc', { identifier: alloc.identifier }))) {
        router.delete(route('admin.services.allocations.destroy', alloc.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="__('admin.users.edit_page.head_title', { name: user.name })" />

    <AdminLayout>
        <div class="space-y-6 max-w-6xl mx-auto">
            <!-- Header Command Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl shadow-sm border border-slate-200/80 dark:border-slate-800/80">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('admin.users.index')"
                        class="p-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                        :title="__('admin.users.edit_page.back_to_list')"
                    >
                        <svg class="w-5 h-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h1 class="text-2xl font-black text-slate-900 dark:text-white">
                                {{ user.name }}
                            </h1>
                            <span class="font-mono text-xs text-indigo-600 dark:text-indigo-400 font-bold" dir="ltr">@{{ user.username }}</span>
                            <span
                                class="text-[10px] font-bold px-2.5 py-0.5 rounded-full"
                                :class="user.role === 'admin' ? 'bg-rose-500/15 text-rose-600 border border-rose-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                            >
                                {{ user.role }}
                            </span>
                            <span
                                class="text-[10px] font-bold px-2.5 py-0.5 rounded-full"
                                :class="user.status === 'active' ? 'bg-emerald-500/15 text-emerald-600 border border-emerald-500/30' : 'bg-amber-500/15 text-amber-600 border border-amber-500/30'"
                            >
                                {{ user.status }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3 mt-1 text-xs text-slate-400">
                            <span>{{ __('admin.users.edit_page.system_id_label') }} <strong class="font-mono text-slate-600 dark:text-slate-300" dir="ltr">[{{ user.account_id }}]</strong></span>
                            <span>•</span>
                            <span>{{ __('admin.users.edit_page.member_since', { date: user.created_at }) }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="px-4 py-2 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-left">
                        <div class="text-[10px] font-semibold text-slate-400">{{ __('admin.users.edit_page.wallet_balance_label') }}</div>
                        <div class="text-base font-black font-mono text-emerald-600 dark:text-emerald-400" dir="ltr">
                            {{ Number(user.wallet_balance).toFixed(2) }} TRX
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200/80 dark:border-slate-800/80 pb-3">
                <button
                    type="button"
                    @click="activeTab = 'profile'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'profile'
                        ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    {{ __('admin.users.edit_page.tab_profile') }}
                </button>
                <button
                    type="button"
                    @click="activeTab = 'wallet'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'wallet'
                        ? 'bg-amber-600 text-white shadow-md shadow-amber-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    {{ __('admin.users.edit_page.tab_wallet') }}
                </button>
                <button
                    type="button"
                    @click="activeTab = 'resources'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'resources'
                        ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    {{ __('admin.users.edit_page.tab_resources', { count: phone_numbers.length + service_allocations.length }) }}
                </button>
                <button
                    type="button"
                    @click="activeTab = 'contacts'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'contacts'
                        ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    {{ __('admin.users.edit_page.tab_contacts', { count: contacts?.length || 0 }) }}
                </button>
                <button
                    type="button"
                    @click="activeTab = 'audit'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'audit'
                        ? 'bg-rose-600 text-white shadow-md shadow-rose-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    {{ __('admin.users.edit_page.tab_audit') }}
                </button>
            </div>

            <!-- TAB 1: Profile & Access Form -->
            <div v-if="activeTab === 'profile'" class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-6">
                <form @submit.prevent="submitProfile" class="space-y-6">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white pb-3 border-b border-slate-100 dark:border-slate-800">
                        {{ __('admin.users.edit_page.profile_section_title') }}
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Full Name -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.name_label') }}</label>
                            <input
                                v-model="profileForm.name"
                                type="text"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                            <div v-if="profileForm.errors.name" class="text-xs text-rose-500 mt-1">{{ profileForm.errors.name }}</div>
                        </div>

                        <!-- Username -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.username_label') }}</label>
                            <input
                                v-model="profileForm.username"
                                type="text"
                                dir="ltr"
                                class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                            <div v-if="profileForm.errors.username" class="text-xs text-rose-500 mt-1">{{ profileForm.errors.username }}</div>
                        </div>

                        <!-- Role -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.role_label') }}</label>
                            <select
                                v-model="profileForm.role"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            >
                                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.status_label') }}</label>
                            <select
                                v-model="profileForm.status"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            >
                                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </div>

                        <!-- Account Tier -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.tier_label') }}</label>
                            <select
                                v-model="profileForm.account_tier"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            >
                                <option value="individual">{{ __('admin.users.edit_page.tier_individual') }}</option>
                                <option value="enterprise">{{ __('admin.users.edit_page.tier_enterprise') }}</option>
                            </select>
                        </div>

                        <!-- Password Reset -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.password_label') }}</label>
                            <input
                                v-model="profileForm.password"
                                type="password"
                                dir="ltr"
                                :placeholder="__('admin.users.edit_page.password_placeholder')"
                                class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                            <div v-if="profileForm.errors.password" class="text-xs text-rose-500 mt-1">{{ profileForm.errors.password }}</div>
                        </div>

                        <!-- Organization Name -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.org_name_label') }}</label>
                            <input
                                v-model="profileForm.organization_name"
                                type="text"
                                :placeholder="__('admin.users.edit_page.org_name_placeholder')"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                        </div>

                        <!-- Enterprise Contract Code -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.contract_code_label') }}</label>
                            <input
                                v-model="profileForm.enterprise_contract_code"
                                type="text"
                                dir="ltr"
                                :placeholder="__('admin.users.edit_page.contract_code_placeholder')"
                                class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-4 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="submit"
                            :disabled="profileForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-600/20 disabled:opacity-50 transition-colors"
                        >
                            {{ profileForm.processing ? __('admin.users.edit_page.saving_profile') : __('admin.users.edit_page.save_profile_btn') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Wallet Management Form -->
            <div v-else-if="activeTab === 'wallet'" class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">{{ __('admin.users.edit_page.wallet_title') }}</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('admin.users.edit_page.wallet_subtitle') }}</p>
                    </div>
                    <div class="text-right">
                        <span class="text-xs text-slate-400">{{ __('admin.users.edit_page.current_balance_label') }}</span>
                        <div class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400" dir="ltr">
                            {{ Number(user.wallet_balance).toFixed(2) }} TRX
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitWalletAdjustment" class="max-w-xl space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.wallet_op_label') }}</label>
                            <select
                                v-model="walletForm.type"
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            >
                                <option value="deposit">{{ __('admin.users.edit_page.wallet_op_deposit') }}</option>
                                <option value="withdraw">{{ __('admin.users.edit_page.wallet_op_withdraw') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.wallet_amount_label') }}</label>
                            <input
                                v-model="walletForm.amount"
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                :placeholder="__('admin.users.edit_page.wallet_amount_placeholder')"
                                dir="ltr"
                                class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                            />
                            <div v-if="walletForm.errors.amount" class="text-xs text-rose-500 mt-1">{{ walletForm.errors.amount }}</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">{{ __('admin.users.edit_page.wallet_desc_label') }}</label>
                        <input
                            v-model="walletForm.description"
                            type="text"
                            :placeholder="__('admin.users.edit_page.wallet_desc_placeholder')"
                            class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-3"
                        />
                        <div v-if="walletForm.errors.description" class="text-xs text-rose-500 mt-1">{{ walletForm.errors.description }}</div>
                    </div>

                    <div class="pt-2">
                        <button
                            type="submit"
                            :disabled="walletForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs shadow-md shadow-amber-600/20 disabled:opacity-50 transition-colors"
                        >
                            {{ walletForm.processing ? __('admin.users.edit_page.wallet_submitting') : __('admin.users.edit_page.wallet_submit_btn') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 3: Allocated Lines & Custom IDs -->
            <div v-else-if="activeTab === 'resources'" class="space-y-6">
                <!-- Phone Numbers -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-4">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <span>{{ __('admin.users.edit_page.lines_heading') }}</span>
                        <span class="text-xs font-normal text-slate-400">{{ __('admin.users.edit_page.lines_count', { count: phone_numbers.length }) }}</span>
                    </h3>

                    <div v-if="phone_numbers.length > 0" class="overflow-x-auto">
                        <table class="w-full text-right text-xs">
                            <thead>
                                <tr class="text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_number') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_type') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_display_title') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_status') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_allocated_at') }}</th>
                                    <th class="py-2.5 px-3 text-left">{{ __('admin.users.edit_page.th_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                                <tr v-for="num in phone_numbers" :key="num.id">
                                    <td class="py-3 px-3 font-mono font-bold" dir="ltr">{{ num.formatted_number }}</td>
                                    <td class="py-3 px-3">{{ num.type_label }}</td>
                                    <td class="py-3 px-3">{{ num.display_title || '-' }}</td>
                                    <td class="py-3 px-3">{{ num.status_label }}</td>
                                    <td class="py-3 px-3 font-mono text-[11px]" dir="ltr">{{ num.allocated_at || '-' }}</td>
                                    <td class="py-3 px-3 text-left">
                                        <div class="inline-flex items-center gap-2">
                                            <Link
                                                :href="route('admin.numbers.edit', num.id)"
                                                class="px-2.5 py-1 rounded-lg text-xs font-bold text-indigo-600 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100"
                                            >
                                                {{ __('admin.users.edit_page.edit_line_btn') }}
                                            </Link>
                                            <button
                                                type="button"
                                                @click="releaseNumber(num)"
                                                class="px-2.5 py-1 rounded-lg text-xs font-bold text-rose-600 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100"
                                            >
                                                {{ __('admin.users.edit_page.release_line_btn') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="text-center py-6 text-xs text-slate-400">
                        {{ __('admin.users.edit_page.no_lines') }}
                    </div>
                </div>

                <!-- Custom IDs & Allocations -->
                <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-4">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <span>{{ __('admin.users.edit_page.allocs_heading') }}</span>
                        <span class="text-xs font-normal text-slate-400">{{ __('admin.users.edit_page.allocs_count', { count: service_allocations.length }) }}</span>
                    </h3>

                    <div v-if="service_allocations.length > 0" class="overflow-x-auto">
                        <table class="w-full text-right text-xs">
                            <thead>
                                <tr class="text-slate-400 border-b border-slate-100 dark:border-slate-800 pb-2">
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_service') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_identifier') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_display_title') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_status') }}</th>
                                    <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_registered_at') }}</th>
                                    <th class="py-2.5 px-3 text-left">{{ __('admin.users.edit_page.th_actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                                <tr v-for="alloc in service_allocations" :key="alloc.id">
                                    <td class="py-3 px-3">{{ alloc.service_title }}</td>
                                    <td class="py-3 px-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">{{ alloc.identifier }}</td>
                                    <td class="py-3 px-3">{{ alloc.display_title || '-' }}</td>
                                    <td class="py-3 px-3">{{ alloc.status }}</td>
                                    <td class="py-3 px-3 font-mono text-[11px]" dir="ltr">{{ alloc.allocated_at || '-' }}</td>
                                    <td class="py-3 px-3 text-left">
                                        <button
                                            type="button"
                                            @click="releaseAllocation(alloc)"
                                            class="px-2.5 py-1 rounded-lg text-xs font-bold text-rose-600 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100"
                                        >
                                            {{ __('admin.users.edit_page.release_alloc_btn') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="text-center py-6 text-xs text-slate-400">
                        {{ __('admin.users.edit_page.no_allocs') }}
                    </div>
                </div>
            </div>

            <!-- TAB 4: Contacts List -->
            <div v-else-if="activeTab === 'contacts'" class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">{{ __('admin.users.edit_page.contacts_heading') }}</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('admin.users.edit_page.contacts_subtitle') }}</p>
                    </div>
                    <span class="text-xs font-mono font-bold px-3 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                        {{ __('admin.users.edit_page.contacts_total', { count: contacts?.length || 0 }) }}
                    </span>
                </div>

                <div v-if="contacts && contacts.length > 0" class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_contact_name') }}</th>
                                <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_contact_number') }}</th>
                                <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_contact_notes') }}</th>
                                <th class="py-2.5 px-3">{{ __('admin.users.edit_page.th_registered_at') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            <tr v-for="contact in contacts" :key="contact.id">
                                <td class="py-3 px-3 font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 font-bold flex items-center justify-center text-xs">
                                        {{ contact.name.charAt(0) }}
                                    </div>
                                    <span>{{ contact.name }}</span>
                                </td>
                                <td class="py-3 px-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">
                                    {{ contact.phone_number }}
                                </td>
                                <td class="py-3 px-3 text-slate-500 dark:text-slate-400">{{ contact.notes || '-' }}</td>
                                <td class="py-3 px-3 font-mono text-[11px]" dir="ltr">{{ contact.created_at || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-center py-8 text-xs text-slate-400">
                    {{ __('admin.users.edit_page.no_contacts') }}
                </div>
            </div>

            <!-- TAB 5: Audit Logs -->
            <div v-else-if="activeTab === 'audit'" class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">{{ __('admin.users.edit_page.audit_heading') }}</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('admin.users.edit_page.audit_subtitle') }}</p>
                    </div>
                    <Link
                        :href="route('admin.audit-logs.index', { user_id: user.id })"
                        class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        {{ __('admin.users.edit_page.view_all_audit_link') }}
                    </Link>
                </div>

                <div v-if="audit_logs.length > 0" class="space-y-2.5">
                    <div
                        v-for="log in audit_logs"
                        :key="log.id"
                        class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 flex items-center justify-between gap-3 text-xs"
                    >
                        <div>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ log.action }}</span>
                            <p class="text-slate-500 dark:text-slate-400 mt-0.5">{{ log.description }}</p>
                        </div>
                        <div class="text-left font-mono text-[11px] text-slate-400 shrink-0" dir="ltr">
                            {{ log.created_at }}
                        </div>
                    </div>
                </div>
                <div v-else class="text-center py-6 text-xs text-slate-400">
                    {{ __('admin.users.edit_page.no_audit_logs') }}
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

