<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface PrefixItem {
    id: number;
    prefix: string;
    clean_prefix: string;
    name: string;
    country_name?: string;
    country_code?: string;
    flag?: string;
    sub_prefixes?: string[];
    price_regular: string | number;
    price_semi_rond: string | number;
    price_golden_rond: string | number;
    sms_price_per_segment: string | number;
    inbound_sms_price: string | number;
    is_default: boolean;
    is_active: boolean;
    numbers_count: number;
    created_at: string;
}

const props = defineProps<{
    prefixes: PrefixItem[];
    stats: {
        total: number;
        active: number;
        default: string;
    };
}>();

// Toast
const toastMessage = ref<string | null>(null);
const { __ } = useTrans();
const showToast = (msg: string) => {
    toastMessage.value = msg;
    setTimeout(() => {
        if (toastMessage.value === msg) toastMessage.value = null;
    }, 3000);
};

// Create Modal
const isCreateModalOpen = ref(false);
const createForm = useForm({
    prefix: '',
    name: '',
    country_name: '',
    country_code: 'IR',
    flag: '🇮🇷',
    sub_prefixes: '912, 910, 918, 935',
    price_regular: '1.0000',
    price_semi_rond: '5.0000',
    price_golden_rond: '20.0000',
    sms_price_per_segment: '0.0500',
    inbound_sms_price: '0.0000',
    is_default: false,
    is_active: true,
});

const openCreateModal = () => {
    createForm.reset();
    createForm.clearErrors();
    createForm.country_code = 'IR';
    createForm.flag = '🇮🇷';
    createForm.sub_prefixes = '912, 910, 918, 935';
    createForm.price_regular = '1.0000';
    createForm.price_semi_rond = '5.0000';
    createForm.price_golden_rond = '20.0000';
    createForm.sms_price_per_segment = '0.0500';
    createForm.inbound_sms_price = '0.0000';
    isCreateModalOpen.value = true;
};

const submitCreate = () => {
    if (!createForm.name && createForm.country_name) {
        createForm.name = createForm.country_name;
    }
    createForm.post(route('admin.prefixes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isCreateModalOpen.value = false;
            showToast(__('admin.prefixes.toast_created'));
        },
    });
};

// Edit Modal
const isEditModalOpen = ref(false);
const editingPrefix = ref<PrefixItem | null>(null);
const editForm = useForm({
    name: '',
    country_name: '',
    country_code: '',
    flag: '',
    sub_prefixes: '',
    price_regular: '1.0000',
    price_semi_rond: '5.0000',
    price_golden_rond: '20.0000',
    sms_price_per_segment: '0.0500',
    inbound_sms_price: '0.0000',
    is_default: false,
    is_active: true,
});

const openEditModal = (prefix: PrefixItem) => {
    editingPrefix.value = prefix;
    editForm.reset();
    editForm.clearErrors();
    editForm.name = prefix.name;
    editForm.country_name = prefix.country_name || prefix.name;
    editForm.country_code = prefix.country_code || 'INTL';
    editForm.flag = prefix.flag || '🌐';
    editForm.sub_prefixes = Array.isArray(prefix.sub_prefixes) ? prefix.sub_prefixes.join(', ') : '';
    editForm.price_regular = String(prefix.price_regular || '1.0000');
    editForm.price_semi_rond = String(prefix.price_semi_rond || '5.0000');
    editForm.price_golden_rond = String(prefix.price_golden_rond || '20.0000');
    editForm.sms_price_per_segment = String(prefix.sms_price_per_segment || '0.0500');
    editForm.inbound_sms_price = String(prefix.inbound_sms_price || '0.0000');
    editForm.is_default = prefix.is_default;
    editForm.is_active = prefix.is_active;
    isEditModalOpen.value = true;
};

const submitEdit = () => {
    if (!editingPrefix.value) return;
    if (!editForm.name && editForm.country_name) {
        editForm.name = editForm.country_name;
    }
    editForm.patch(route('admin.prefixes.update', editingPrefix.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            isEditModalOpen.value = false;
            showToast(__('admin.prefixes.toast_updated'));
        },
    });
};

// Quick Toggle Status
const toggleStatus = (prefix: PrefixItem) => {
    router.patch(route('admin.prefixes.update', prefix.id), {
        name: prefix.name,
        country_name: prefix.country_name,
        country_code: prefix.country_code,
        flag: prefix.flag,
        price_regular: prefix.price_regular,
        price_semi_rond: prefix.price_semi_rond,
        price_golden_rond: prefix.price_golden_rond,
        sms_price_per_segment: prefix.sms_price_per_segment,
        inbound_sms_price: prefix.inbound_sms_price,
        is_default: prefix.is_default,
        is_active: !prefix.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => showToast(__('admin.prefixes.toast_status_changed', { prefix: prefix.prefix })),
    });
};

// Quick Set As Default
const setAsDefault = (prefix: PrefixItem) => {
    router.patch(route('admin.prefixes.update', prefix.id), {
        name: prefix.name,
        country_name: prefix.country_name,
        country_code: prefix.country_code,
        flag: prefix.flag,
        price_regular: prefix.price_regular,
        price_semi_rond: prefix.price_semi_rond,
        price_golden_rond: prefix.price_golden_rond,
        sms_price_per_segment: prefix.sms_price_per_segment,
        inbound_sms_price: prefix.inbound_sms_price,
        is_default: true,
        is_active: true,
    }, {
        preserveScroll: true,
        onSuccess: () => showToast(__('admin.prefixes.toast_default_set', { prefix: prefix.prefix })),
    });
};

// Delete Prefix
const deletePrefix = (prefix: PrefixItem) => {
    if (confirm(__('admin.prefixes.confirm_delete', { prefix: prefix.prefix }))) {
        router.delete(route('admin.prefixes.destroy', prefix.id), {
            preserveScroll: true,
            onSuccess: () => showToast(__('admin.prefixes.toast_deleted', { prefix: prefix.prefix })),
        });
    }
};
</script>

<template>
    <Head :title="__('admin.prefixes.head_title')" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Toast Notification -->
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="toastMessage" class="fixed top-5 left-1/2 -translate-x-1/2 z-50 bg-emerald-600 text-white px-4 py-2.5 rounded-xl shadow-xl text-xs font-bold flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>

            <!-- Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-slate-800 pb-5">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                        <span>{{ __('admin.prefixes.page_title') }}</span>
                    </h1>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                        {{ __('admin.prefixes.page_desc') }}
                    </p>
                </div>

                <button
                    type="button"
                    @click="openCreateModal"
                    class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/20 transition-all flex items-center justify-center gap-2 shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>{{ __('admin.prefixes.add_prefix') }}</span>
                </button>
            </div>

            <!-- Stats Row -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                        #
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">{{ __('admin.prefixes.total_prefixes') }}</span>
                        <span class="text-lg font-black font-mono text-slate-900 dark:text-white">{{ stats.total }}</span>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold">
                        ✓
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">{{ __('admin.prefixes.active_prefixes') }}</span>
                        <span class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400">{{ stats.active }}</span>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold font-mono">
                        {{ stats.default }}
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 dark:text-slate-400 block font-bold">{{ __('admin.prefixes.default_prefix') }}</span>
                        <span class="text-lg font-black font-mono text-amber-600 dark:text-amber-400">{{ stats.default }}</span>
                    </div>
                </div>
            </div>

            <!-- Prefixes Table Card -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-600 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-3.5">{{ __('admin.prefixes.th_country') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_prefix') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_precodes') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_tariffs') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_sms_tariff') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_lines') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_status') }}</th>
                                <th class="p-3.5">{{ __('admin.prefixes.th_default') }}</th>
                                <th class="p-3.5 text-center">{{ __('admin.prefixes.th_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800/70">
                            <tr v-for="prefix in prefixes" :key="prefix.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                                <td class="p-3.5 font-medium text-slate-900 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xl">{{ prefix.flag || '🌐' }}</span>
                                        <div>
                                            <span class="font-black block">{{ prefix.country_name || prefix.name }}</span>
                                            <span class="text-[10px] text-slate-400 font-mono">{{ prefix.country_code }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3.5 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                    <span class="text-rose-600 dark:text-rose-400 text-sm font-black">{{ prefix.prefix }}</span>
                                </td>
                                <td class="p-3.5">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        <span
                                            v-for="sub in (prefix.sub_prefixes || [])"
                                            :key="sub"
                                            class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[10px] font-mono text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-white/10"
                                        >
                                            {{ sub }}
                                        </span>
                                    </div>
                                </td>
                                <td class="p-3.5 font-mono">
                                    <div class="flex items-center gap-1.5 text-[11px]">
                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400" :title="__('admin.prefixes.tier_regular')">
                                            {{ prefix.price_regular }}
                                        </span>
                                        <span>/</span>
                                        <span class="px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400 font-bold" :title="__('admin.prefixes.tier_semi_rond')">
                                            {{ prefix.price_semi_rond }}
                                        </span>
                                        <span>/</span>
                                        <span class="px-1.5 py-0.5 rounded bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400 font-black" :title="__('admin.prefixes.tier_golden')">
                                            {{ prefix.price_golden_rond }}
                                        </span>
                                    </div>
                                </td>
                                <td class="p-3.5 font-mono" dir="ltr">
                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200/80 dark:border-emerald-800/60 text-[11px] font-black text-emerald-700 dark:text-emerald-400">
                                        <span>{{ prefix.sms_price_per_segment }}</span>
                                        <span class="text-[9px] font-bold opacity-80">TRX / SMS</span>
                                    </div>
                                </td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded-full font-mono text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        {{ __('admin.prefixes.lines_count', { count: prefix.numbers_count }) }}
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <button
                                        type="button"
                                        @click="toggleStatus(prefix)"
                                        class="px-2.5 py-1 rounded-full text-[10px] font-bold transition-all border"
                                        :class="prefix.is_active
                                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800'
                                            : 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'"
                                    >
                                        {{ prefix.is_active ? __('admin.prefixes.status_active') : __('admin.prefixes.status_inactive') }}
                                    </button>
                                </td>
                                <td class="p-3.5">
                                    <span v-if="prefix.is_default" class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800 inline-flex items-center gap-1">
                                        <span>★</span>
                                        <span>{{ __('admin.prefixes.default_badge') }}</span>
                                    </span>
                                    <button
                                        v-else
                                        type="button"
                                        @click="setAsDefault(prefix)"
                                        class="text-[11px] font-bold text-slate-400 hover:text-amber-600 dark:hover:text-amber-400 transition-colors"
                                    >{{ __('admin.prefixes.set_default') }}</button>
                                </td>
                                <td class="p-3.5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button
                                            type="button"
                                            @click="openEditModal(prefix)"
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                            :title="__('admin.prefixes.edit_tooltip')"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="deletePrefix(prefix)"
                                            :disabled="prefix.is_default || prefix.numbers_count > 0"
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors"
                                            :title="__('admin.prefixes.delete_tooltip')"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Create Modal -->
            <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm" @click.self="isCreateModalOpen = false">
                <div class="w-full max-w-lg rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800 shrink-0 mb-4">
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('admin.prefixes.modal_add_title') }}</h3>
                        <button type="button" @click="isCreateModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">✕</button>
                    </div>

                    <form @submit.prevent="submitCreate" class="space-y-3.5 text-xs overflow-y-auto flex-1">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.prefix_label') }}</label>
                                <input
                                    v-model="createForm.prefix"
                                    type="text"
                                    dir="ltr"
                                    placeholder="+98"
                                    class="form-input w-full font-mono text-left"
                                    required
                                />
                                <p v-if="createForm.errors.prefix" class="text-rose-500 text-[11px] mt-1">{{ createForm.errors.prefix }}</p>
                            </div>
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.country_label') }}</label>
                                <input
                                    v-model="createForm.country_name"
                                    type="text"
                                    :placeholder="__('admin.prefixes.country_placeholder')"
                                    class="form-input w-full"
                                    required
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.iso_label') }}</label>
                                <input
                                    v-model="createForm.country_code"
                                    type="text"
                                    dir="ltr"
                                    maxlength="4"
                                    placeholder="IR"
                                    class="form-input w-full font-mono uppercase text-left"
                                />
                            </div>
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.flag_label') }}</label>
                                <input
                                    v-model="createForm.flag"
                                    type="text"
                                    placeholder="🇮🇷"
                                    class="form-input w-full text-center text-base"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="form-label mb-1">{{ __('admin.prefixes.precodes_label') }}</label>
                            <input
                                v-model="createForm.sub_prefixes"
                                type="text"
                                dir="ltr"
                                placeholder="912, 910, 918, 935"
                                class="form-input w-full font-mono text-left"
                            />
                            <p class="text-[11px] text-slate-500 mt-1">{{ __('admin.prefixes.precodes_help') }}</p>
                        </div>

                        <!-- 3-Tier Rond Pricing for 9-digit lines -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-2">
                            <span class="font-bold text-slate-800 dark:text-slate-200 block text-xs">{{ __('admin.prefixes.pricing_label') }}</span>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="text-[11px] text-slate-500 block mb-1">{{ __('admin.prefixes.tier_regular') }}</label>
                                    <input
                                        v-model="createForm.price_regular"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-blue-600 dark:text-blue-400 block mb-1 font-bold">{{ __('admin.prefixes.tier_semi_rond') }}</label>
                                    <input
                                        v-model="createForm.price_semi_rond"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-amber-600 dark:text-amber-400 block mb-1 font-bold">{{ __('admin.prefixes.tier_golden') }}</label>
                                    <input
                                        v-model="createForm.price_golden_rond"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Per-Prefix SMS Tariff -->
                        <div class="p-3.5 rounded-2xl bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-200/80 dark:border-emerald-800/50 space-y-2">
                            <span class="font-bold text-emerald-900 dark:text-emerald-300 block text-xs">{{ __('admin.prefixes.sms_pricing_label') }}</span>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div>
                                    <label class="text-[11px] text-emerald-700 dark:text-emerald-400 block mb-1 font-bold">{{ __('admin.prefixes.sms_outbound_rate') }}</label>
                                    <input
                                        v-model="createForm.sms_price_per_segment"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-slate-500 block mb-1">{{ __('admin.prefixes.sms_inbound_rate') }}</label>
                                    <input
                                        v-model="createForm.inbound_sms_price"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="createForm.is_active" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('admin.prefixes.is_active_label') }}</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="createForm.is_default" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('admin.prefixes.is_default_label') }}</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                            <button
                                type="button"
                                @click="isCreateModalOpen = false"
                                class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                            >{{ __('admin.prefixes.cancel') }}</button>
                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 cursor-pointer"
                            >
                                <span>{{ createForm.processing ? __('admin.prefixes.saving') : __('admin.prefixes.save_prefix') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div v-if="isEditModalOpen" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm" @click.self="isEditModalOpen = false">
                <div class="w-full max-w-lg rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800 shrink-0 mb-4">
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">{{ __('admin.prefixes.modal_edit_title', { prefix: editingPrefix?.prefix }) }}</h3>
                        <button type="button" @click="isEditModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">✕</button>
                    </div>

                    <form @submit.prevent="submitEdit" class="space-y-3.5 text-xs overflow-y-auto flex-1">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.country_label') }}</label>
                                <input
                                    v-model="editForm.country_name"
                                    type="text"
                                    class="form-input w-full"
                                    required
                                />
                            </div>
                            <div>
                                <label class="form-label mb-1">{{ __('admin.prefixes.iso_label') }}</label>
                                <input
                                    v-model="editForm.country_code"
                                    type="text"
                                    dir="ltr"
                                    maxlength="4"
                                    class="form-input w-full font-mono uppercase text-left"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="form-label mb-1">{{ __('admin.prefixes.flag_label') }}</label>
                            <input
                                v-model="editForm.flag"
                                type="text"
                                class="form-input w-full text-center text-base"
                            />
                        </div>

                        <div>
                            <label class="form-label mb-1">{{ __('admin.prefixes.precodes_label') }}</label>
                            <input
                                v-model="editForm.sub_prefixes"
                                type="text"
                                dir="ltr"
                                class="form-input w-full font-mono text-left"
                            />
                        </div>

                        <!-- 3-Tier Rond Pricing for 9-digit lines -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-2">
                            <span class="font-bold text-slate-800 dark:text-slate-200 block text-xs">{{ __('admin.prefixes.pricing_label_edit') }}</span>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="text-[11px] text-slate-500 block mb-1">{{ __('admin.prefixes.tier_regular') }}</label>
                                    <input
                                        v-model="editForm.price_regular"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-blue-600 dark:text-blue-400 block mb-1 font-bold">{{ __('admin.prefixes.tier_semi_rond') }}</label>
                                    <input
                                        v-model="editForm.price_semi_rond"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-amber-600 dark:text-amber-400 block mb-1 font-bold">{{ __('admin.prefixes.tier_golden') }}</label>
                                    <input
                                        v-model="editForm.price_golden_rond"
                                        type="number"
                                        step="0.0001"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Per-Prefix SMS Tariff -->
                        <div class="p-3.5 rounded-2xl bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-200/80 dark:border-emerald-800/50 space-y-2">
                            <span class="font-bold text-emerald-900 dark:text-emerald-300 block text-xs">{{ __('admin.prefixes.sms_pricing_label') }}</span>
                            <div class="grid grid-cols-2 gap-2.5">
                                <div>
                                    <label class="text-[11px] text-emerald-700 dark:text-emerald-400 block mb-1 font-bold">{{ __('admin.prefixes.sms_outbound_rate') }}</label>
                                    <input
                                        v-model="editForm.sms_price_per_segment"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                                <div>
                                    <label class="text-[11px] text-slate-500 block mb-1">{{ __('admin.prefixes.sms_inbound_rate') }}</label>
                                    <input
                                        v-model="editForm.inbound_sms_price"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        dir="ltr"
                                        class="form-input w-full font-mono text-center"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="editForm.is_active" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('admin.prefixes.is_active_label') }}</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="editForm.is_default" type="checkbox" class="rounded text-rose-600 focus:ring-rose-500" />
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ __('admin.prefixes.is_default_label') }}</span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                            <button
                                type="button"
                                @click="isEditModalOpen = false"
                                class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                            >{{ __('admin.prefixes.cancel') }}</button>
                            <button
                                type="submit"
                                :disabled="editForm.processing"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 text-white font-bold hover:bg-rose-700 shadow-sm disabled:opacity-50 cursor-pointer"
                            >
                                <span>{{ editForm.processing ? __('admin.prefixes.saving') : __('admin.prefixes.update_settings') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
