<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
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

const props = defineProps<{
    gateways: GatewayModel[];
}>();

// Editing gateway modal
const isEditModalOpen = ref(false);
const editingGateway = ref<GatewayModel | null>(null);

const form = useForm({
    is_active: true,
    is_default: false,
    config: {
        api_key: '',
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
    form.config = {
        api_key: g.config?.api_key || '',
        base_url: g.config?.base_url || '',
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
    <Head title="پیکربندی درگاه‌های پرداخت کریپتو" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h1 class="text-base sm:text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                        پیکربندی درگاه‌های پرداخت و صرافی‌ها
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        مدیریت اتصال صرافی نوبیتکس، شبکه ترون گرید و درگاه‌های شبیه‌ساز تست
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
                        <span>واریزی‌های کریپتو</span>
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
                                {{ g.is_active ? 'فعال' : 'غیرفعال' }}
                            </span>
                            <span
                                v-if="g.is_default"
                                class="px-2 py-0.5 rounded-lg bg-indigo-500/15 text-indigo-400 text-[10px] font-bold border border-indigo-500/30"
                            >
                                درگاه پیش‌فرض
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                            <div>
                                شناسه درایور: <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ g.driver }}</span>
                            </div>
                            <span>•</span>
                            <div>
                                ارزهای پشتیبانی‌شده:
                                <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ (g.supported_currencies || []).join(', ') }}</span>
                            </div>
                            <span>•</span>
                            <div>
                                شبکه‌ها:
                                <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ (g.supported_networks || []).join(', ') }}</span>
                            </div>
                        </div>

                        <div v-if="g.driver === 'nobitex'" class="text-[11px] text-slate-400 pt-1">
                            آدرس پایه: <span class="font-mono">{{ g.config?.base_url || 'https://api.nobitex.ir' }}</span> |
                            وضعیت کلید API: <span :class="g.config?.api_key ? 'text-emerald-500 font-bold' : 'text-amber-500 font-bold'">{{ g.config?.api_key ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}</span>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="openEditModal(g)"
                        class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all shadow-md shadow-indigo-600/20 shrink-0"
                    >
                        ویرایش تنظیمات درگاه
                    </button>
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
                            ویرایش درگاه «{{ editingGateway?.name }}»
                        </h3>
                        <button type="button" @click="closeEditModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
                    </div>

                    <form @submit.prevent="submitGatewayUpdate" class="space-y-4 mt-4 text-xs">
                        <!-- Toggles -->
                        <div class="grid grid-cols-2 gap-3">
                            <label class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.is_active" class="rounded text-indigo-600" />
                                <span class="font-bold text-slate-900 dark:text-white">درگاه فعال باشد</span>
                            </label>

                            <label class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="form.is_default" class="rounded text-indigo-600" />
                                <span class="font-bold text-slate-900 dark:text-white">درگاه پیش‌فرض سیستم</span>
                            </label>
                        </div>

                        <!-- Nobitex specific fields -->
                        <template v-if="editingGateway?.driver === 'nobitex'">
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">کلید وب‌سرویس نوبیتکس (API Key / Token)</label>
                                <input
                                    v-model="form.config.api_key"
                                    type="password"
                                    placeholder="توکن دریافت شده از پنل کاربری نوبیتکس..."
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">آدرس پایه API (Base URL)</label>
                                <input
                                    v-model="form.config.base_url"
                                    type="text"
                                    placeholder="https://api.nobitex.ir"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">شناسه کیف پول ترون نوبیتکس (اختیاری)</label>
                                <input
                                    v-model="form.config.wallet_id"
                                    type="number"
                                    placeholder="در صورت خالی بودن، به صورت خودکار از API دریافت می‌شود"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">آدرس واریز ثابت اختصاصی (اختیاری / جهت Override)</label>
                                <input
                                    v-model="form.config.deposit_address_override"
                                    type="text"
                                    placeholder="آدرس کیف پول ثابت در صورت عدم تولید داینامیک..."
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <!-- TronGrid specific fields -->
                        <template v-else-if="editingGateway?.driver === 'tron_grid'">
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">آدرس دریافت ترون (TRON Deposit Address)</label>
                                <input
                                    v-model="form.config.deposit_address"
                                    type="text"
                                    placeholder="T..."
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>

                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">کلید TronGrid API Key (اختیاری)</label>
                                <input
                                    v-model="form.config.api_key"
                                    type="password"
                                    placeholder="کلید اختیاری از سایت trongrid.io جهت حذف محدودیت ریت‌لیمیت..."
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <!-- Mock specific fields -->
                        <template v-else>
                            <div>
                                <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">آدرس شبیه‌ساز تست</label>
                                <input
                                    v-model="form.config.deposit_address"
                                    type="text"
                                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 font-mono text-xs focus:border-indigo-500 outline-none text-slate-900 dark:text-white"
                                />
                            </div>
                        </template>

                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">حداقل مبلغ واریز (TRX)</label>
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
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-500 shadow-md shadow-indigo-600/20 disabled:opacity-50 transition-all"
                            >
                                <span v-if="form.processing">در حال ذخیره...</span>
                                <span v-else>ذخیره تنظیمات درگاه</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </teleport>
    </AdminLayout>
</template>

