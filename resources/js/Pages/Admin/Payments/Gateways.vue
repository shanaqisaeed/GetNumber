<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface GatewayModel {
    id: number;
    name: string;
    driver: string;
    is_active: boolean;
    is_default: boolean;
    supported_currencies: string[];
    supported_networks: string[];
    config: Record<string, any> | null;
}

const { __ } = useTrans();

const props = defineProps<{
    gateways: GatewayModel[];
}>();

// Editing gateway modal
const isEditModalOpen = ref(false);
const editingGateway = ref<GatewayModel | null>(null);
const syncingId = ref<number | null>(null);

const syncGatewayWallet = (g: GatewayModel) => {
    syncingId.value = g.id;
    useForm({}).post(route('admin.payments.gateways.sync', g.id), {
        preserveScroll: true,
        onFinish: () => {
            syncingId.value = null;
        },
    });
};

const form = useForm({
    is_active: true,
    is_default: false,
    config: {
        api_key: '',
        secret_key: '',
        base_url: '',
        wallet_id: '',
        deposit_address: '',
        deposit_address_override: '',
        min_deposit: '1.0000',
    },
});

const openEditModal = (g: GatewayModel) => {
    editingGateway.value = g;
    form.is_active = g.is_active;
    form.is_default = g.is_default;

    let baseUrl = g.config?.base_url || '';
    if (g.driver === 'nobitex' && (!baseUrl || baseUrl.includes('api.nobitex.ir'))) {
        baseUrl = 'https://apiv2.nobitex.ir';
    }

    form.config = {
        api_key: g.config?.api_key || '',
        secret_key: g.config?.secret_key || '',
        base_url: baseUrl,
        wallet_id: g.config?.wallet_id || '',
        deposit_address: g.config?.deposit_address || '',
        deposit_address_override: g.config?.deposit_address_override || '',
        min_deposit: g.config?.min_deposit || '1.0000',
    };
    isEditModalOpen.value = true;
};

const closeEditModal = () => {
    isEditModalOpen.value = false;
    editingGateway.value = null;
    form.reset();
};

const submitGatewayUpdate = () => {
    if (!editingGateway.value) return;

    form.patch(route('admin.payments.gateways.update', editingGateway.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditModal();
        },
    });
};
</script>

<template>
    <Head :title="__('admin.payments.head_title_gateways')" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        {{ __('admin.payments.page_title_gateways') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {{ __('admin.payments.page_desc_gateways') }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.payments.index')"
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all flex items-center gap-1.5"
                    >
                        <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>{{ __('admin.payments.back_to_deposits_btn') }}</span>
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6 max-w-4xl mx-auto">
            <div class="grid grid-cols-1 gap-4">
                <div
                    v-for="g in gateways"
                    :key="g.id"
                    class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
                >
                    <div class="space-y-2">
                        <div class="flex items-center gap-2.5">
                            <h2 class="text-sm font-black text-slate-900 dark:text-white">{{ g.name }}</h2>
                            <span
                                class="px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                :class="g.is_active ? 'bg-emerald-500/15 text-emerald-500' : 'bg-rose-500/15 text-rose-500'"
                            >
                                {{ g.is_active ? __('admin.payments.status_active') : __('admin.payments.status_inactive') }}
                            </span>
                            <span
                                v-if="g.is_default"
                                class="px-2 py-0.5 rounded-lg bg-indigo-500/15 text-indigo-400 text-[10px] font-bold border border-indigo-500/30"
                            >
                                {{ __('admin.payments.default_gateway') }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                            <div>
                                {{ __('admin.payments.driver_id') }} <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ g.driver }}</span>
                            </div>
                            <span>•</span>
                            <div>
                                {{ __('admin.payments.supported_currencies') }}
                                <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ (g.supported_currencies || []).join(', ') }}</span>
                            </div>
                            <span>•</span>
                            <div>
                                {{ __('admin.payments.networks') }}
                                <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ (g.supported_networks || []).join(', ') }}</span>
                            </div>
                        </div>

                        <div v-if="g.driver === 'nobitex'" class="text-[11px] text-slate-400 pt-1 flex flex-wrap items-center gap-2">
                            <span>{{ __('admin.payments.base_url') }} <span class="font-mono">{{ g.config?.base_url || 'https://apiv2.nobitex.ir' }}</span></span>
                            <span>•</span>
                            <span>
                                {{ __('admin.payments.connection_status') }}
                                <span v-if="g.config?.secret_key && g.config?.api_key" class="text-emerald-500 font-bold">API Key (Ed25519)</span>
                                <span v-else-if="g.config?.api_key" class="text-sky-500 font-bold">{{ __('admin.payments.token_legacy') }}</span>
                                <span v-else class="text-amber-500 font-bold">{{ __('admin.payments.not_configured') }}</span>
                            </span>
                            <span v-if="g.config?.deposit_address">•</span>
                            <span v-if="g.config?.deposit_address" class="text-slate-500 dark:text-slate-400">
                                {{ __('admin.payments.trx_address') }} <span class="font-mono text-slate-700 dark:text-slate-300">{{ g.config.deposit_address }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            v-if="g.driver === 'nobitex' || g.driver === 'wallex'"
                            type="button"
                            @click="syncGatewayWallet(g)"
                            :disabled="syncingId === g.id || !g.config?.api_key"
                            class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <span v-if="syncingId === g.id">{{ __('admin.payments.syncing') }}</span>
                            <span v-else>{{ __('admin.payments.sync_trx_wallet') }}</span>
                        </button>

                        <button
                            type="button"
                            @click="openEditModal(g)"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20 shrink-0 cursor-pointer"
                        >{{ __('admin.payments.edit_gateway_btn') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDIT GATEWAY MODAL -->
        <teleport to="body">
            <div v-if="isEditModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm" @click="closeEditModal"></div>

                <div class="relative w-full max-w-lg rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl p-6 overflow-hidden z-10 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                            {{ __('admin.payments.modal_edit_gateway_title', { name: editingGateway?.name }) }}
                        </h3>
                        <button type="button" @click="closeEditModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <form @submit.prevent="submitGatewayUpdate" class="space-y-4 mt-4 text-xs">
                        <!-- Toggles -->
                        <div class="grid grid-cols-2 gap-3">
                            <label class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.is_active" class="rounded text-indigo-600" />
                                <span class="font-bold text-slate-900 dark:text-white">{{ __('admin.payments.is_active_label') }}</span>
                            </label>

                            <label class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.is_default" class="rounded text-indigo-600" />
                                <span class="font-bold text-slate-900 dark:text-white">{{ __('admin.payments.is_default_label') }}</span>
                            </label>
                        </div>

                        <!-- Nobitex specific fields -->
                        <template v-if="editingGateway?.driver === 'nobitex'">
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.nobitex_public_key') }}</label>
                                <input
                                    v-model="form.config.api_key"
                                    type="text"
                                    :placeholder="__('admin.payments.nobitex_public_key_placeholder')"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.nobitex_secret_key') }}</label>
                                <input
                                    v-model="form.config.secret_key"
                                    type="text"
                                    :placeholder="__('admin.payments.nobitex_secret_key_placeholder')"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                                <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1 leading-relaxed">
                                    <span v-html="__('admin.payments.nobitex_help_html')"></span>
                                </p>
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.base_url_label') }}</label>
                                <input
                                    v-model="form.config.base_url"
                                    type="text"
                                    placeholder="https://apiv2.nobitex.ir"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.wallet_id_label') }}</label>
                                <input
                                    v-model="form.config.wallet_id"
                                    type="number"
                                    :placeholder="__('admin.payments.wallet_id_placeholder')"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.static_deposit_address') }}</label>
                                <input
                                    v-model="form.config.deposit_address_override"
                                    type="text"
                                    :placeholder="__('admin.payments.static_deposit_placeholder')"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <!-- TronGrid specific fields -->
                        <template v-else-if="editingGateway?.driver === 'tron_grid'">
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.trongrid_deposit_address') }}</label>
                                <input
                                    v-model="form.config.deposit_address"
                                    type="text"
                                    placeholder="T..."
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.trongrid_key') }}</label>
                                <input
                                    v-model="form.config.api_key"
                                    type="password"
                                    :placeholder="__('admin.payments.trongrid_key_placeholder')"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <!-- Mock specific fields -->
                        <template v-else>
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.simulator_address') }}</label>
                                <input
                                    v-model="form.config.deposit_address"
                                    type="text"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">{{ __('admin.payments.min_deposit_trx') }}</label>
                            <input
                                v-model="form.config.min_deposit"
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                            />
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <button
                                type="button"
                                @click="closeEditModal"
                                class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >{{ __('admin.payments.cancel') }}</button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-500 shadow-md shadow-indigo-600/20 disabled:opacity-50 transition-all"
                            >
                                <span v-if="form.processing">{{ __('admin.payments.saving') }}</span>
                                <span v-else>{{ __('admin.payments.save_gateway_settings') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

