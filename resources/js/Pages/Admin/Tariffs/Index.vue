<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface PrefixTariffItem {
    id: number;
    prefix: string;
    clean_prefix: string;
    name: string;
    country_name: string | null;
    flag: string | null;
    is_default: boolean;
    is_active: boolean;
    sms_intra_prefix_rate: string;
    sms_inter_prefix_rate: string;
    voice_intra_prefix_rate: string;
    voice_inter_prefix_rate: string;
}

interface GlobalTariffs {
    id_sms_rate: string;
    id_voice_rate_per_minute: string;
    sms_intra_prefix_rate: string;
    sms_inter_prefix_rate: string;
    voice_intra_prefix_rate: string;
    voice_inter_prefix_rate: string;
}

const props = defineProps<{
    prefixes: PrefixTariffItem[];
    global_settings: GlobalTariffs;
}>();

const activeTab = ref<'prefixes' | 'global'>('prefixes');

// Global Tariffs Form
const globalForm = useForm({
    id_sms_rate: props.global_settings.id_sms_rate,
    id_voice_rate_per_minute: props.global_settings.id_voice_rate_per_minute,
    sms_intra_prefix_rate: props.global_settings.sms_intra_prefix_rate,
    sms_inter_prefix_rate: props.global_settings.sms_inter_prefix_rate,
    voice_intra_prefix_rate: props.global_settings.voice_intra_prefix_rate,
    voice_inter_prefix_rate: props.global_settings.voice_inter_prefix_rate,
});

const submitGlobal = () => {
    globalForm.post(route('admin.tariffs.global.update'), {
        preserveScroll: true,
    });
};

// Editing Prefix Tariff Modal
const editingPrefix = ref<PrefixTariffItem | null>(null);
const prefixForm = useForm({
    sms_intra_prefix_rate: '',
    sms_inter_prefix_rate: '',
    voice_intra_prefix_rate: '',
    voice_inter_prefix_rate: '',
});

const openEditPrefix = (prefix: PrefixTariffItem) => {
    editingPrefix.value = prefix;
    prefixForm.sms_intra_prefix_rate = prefix.sms_intra_prefix_rate;
    prefixForm.sms_inter_prefix_rate = prefix.sms_inter_prefix_rate;
    prefixForm.voice_intra_prefix_rate = prefix.voice_intra_prefix_rate;
    prefixForm.voice_inter_prefix_rate = prefix.voice_inter_prefix_rate;
};

const closeEditPrefix = () => {
    editingPrefix.value = null;
    prefixForm.reset();
};

const submitPrefixTariff = () => {
    if (!editingPrefix.value) return;
    prefixForm.patch(route('admin.tariffs.prefixes.update', editingPrefix.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditPrefix();
        },
    });
};
</script>

<template>
    <Head title="مدیریت تعرفه‌های ارتباطی، پیامک و مکالمه" />

    <AdminLayout>
        <div class="space-y-6 max-w-6xl mx-auto">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        سامانه تعرفه‌گذاری اختصاصی ارتباطات
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        پیکربندی نرخ پیامک‌های درون‌شبکه‌ای و بین‌المللی، تعرفه تماس صوتی در دقیقه و ارتباطات شناسه به شناسه (ID-to-ID)
                    </p>
                </div>
            </div>

            <!-- Quick Overview Card for Custom ID Communication Tariffs -->
            <div class="p-4 rounded-3xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-800/60 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-mono font-black text-base shrink-0">@</span>
                    <div>
                        <h3 class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>تعرفه ارتباطات آیدی اختصاصی (ID-to-ID)</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold">فعال و در حال اجرا</span>
                        </h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            هزینه ارتباط مستقیم بین کاربران از طریق شناسه‌های @handle (بدون نیاز به خط تلفن).
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-4 text-xs font-mono">
                    <div class="bg-white dark:bg-slate-900 px-3 py-1.5 rounded-xl border border-indigo-100 dark:border-indigo-900/40">
                        <span class="text-slate-500 text-[11px] ml-1">پیامک آیدی:</span>
                        <strong class="text-indigo-600 dark:text-indigo-400 font-black">{{ global_settings.id_sms_rate }} TRX</strong>
                    </div>
                    <div class="bg-white dark:bg-slate-900 px-3 py-1.5 rounded-xl border border-indigo-100 dark:border-indigo-900/40">
                        <span class="text-slate-500 text-[11px] ml-1">تماس آیدی:</span>
                        <strong class="text-emerald-600 dark:text-emerald-400 font-black">{{ global_settings.id_voice_rate_per_minute }} TRX / دقیقه</strong>
                    </div>
                    <button
                        type="button"
                        @click="activeTab = 'global'"
                        class="px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition-colors shrink-0"
                    >
                        ویرایش تعرفه آیدی ⚙️
                    </button>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200/80 dark:border-slate-800/80 pb-3">
                <button
                    type="button"
                    @click="activeTab = 'prefixes'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'prefixes'
                        ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    📞 تعرفه تفکیکی سرشماره‌ها ({{ prefixes.length }})
                </button>
                <button
                    type="button"
                    @click="activeTab = 'global'"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all"
                    :class="activeTab === 'global'
                        ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/20'
                        : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50'"
                >
                    🌐 تعرفه‌های سراسری و آیدی به آیدی (ID-to-ID)
                </button>
            </div>

            <!-- TAB 1: Prefixes Tariffs Table -->
            <div v-if="activeTab === 'prefixes'" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">
                            جدول تعرفه پیامک و مکالمه بر حسب سرشماره
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            تمامی ارقام به ازای هر پارت پیامک و هر دقیقه تماس محاسبه می‌گردند.
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/70 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                <th class="py-3.5 px-4 font-bold">پیش‌شماره</th>
                                <th class="py-3.5 px-4 font-bold">کشور / عنوان</th>
                                <th class="py-3.5 px-4 font-bold">پیامک هم‌پیش‌شماره</th>
                                <th class="py-3.5 px-4 font-bold">پیامک بین‌پیش‌شماره</th>
                                <th class="py-3.5 px-4 font-bold">تماس صوتی هم‌پیش‌شماره</th>
                                <th class="py-3.5 px-4 font-bold">تماس صوتی بین‌پیش‌شماره</th>
                                <th class="py-3.5 px-4 font-bold text-left">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            <tr v-for="prefix in prefixes" :key="prefix.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="py-3.5 px-4 font-mono font-black text-indigo-600 dark:text-indigo-400" dir="ltr">
                                    {{ prefix.flag }} {{ prefix.prefix }}
                                </td>
                                <td class="py-3.5 px-4 font-bold">
                                    {{ prefix.country_name || prefix.name }}
                                    <span v-if="prefix.is_default" class="text-[10px] px-1.5 py-0.2 rounded-md bg-emerald-500/15 text-emerald-600 mr-1.5">پیش‌فرض</span>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold" dir="ltr">
                                    {{ Number(prefix.sms_intra_prefix_rate).toFixed(4) }} TRX
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-500" dir="ltr">
                                    {{ Number(prefix.sms_inter_prefix_rate).toFixed(4) }} TRX
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-emerald-600 dark:text-emerald-400" dir="ltr">
                                    {{ Number(prefix.voice_intra_prefix_rate).toFixed(4) }} TRX / دقیقه
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-amber-600 dark:text-amber-400" dir="ltr">
                                    {{ Number(prefix.voice_inter_prefix_rate).toFixed(4) }} TRX / دقیقه
                                </td>
                                <td class="py-3.5 px-4 text-left">
                                    <button
                                        type="button"
                                        @click="openEditPrefix(prefix)"
                                        class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 font-bold transition-colors"
                                    >
                                        ویرایش تعرفه
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: Global & ID Tariffs Form -->
            <div v-else-if="activeTab === 'global'" class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800/80 shadow-sm space-y-6">
                <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">تعرفه‌های سراسری و ارتباطات آیدی به آیدی (ID-to-ID)</h2>
                    <p class="text-xs text-slate-400 mt-0.5">تنظیم هزینه پیام و تماس بین شناسه‌های @handle و مقادیر پیش‌فرض سامانه</p>
                </div>

                <form @submit.prevent="submitGlobal" class="space-y-6 max-w-2xl">
                    <!-- ID-to-ID Rates -->
                    <div class="p-5 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 space-y-4">
                        <h3 class="text-xs font-black text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
                            <span>🆔</span>
                            <span>تعرفه ارتباط مستقیم آیدی به آیدی (@handle to @handle)</span>
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    هزینه پیام آیدی به آیدی (هر پیام)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.id_sms_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    هزینه تماس صوتی آیدی به آیدی (هر دقیقه)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.id_voice_rate_per_minute"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Global Fallback Rates -->
                    <div class="p-5 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 space-y-4">
                        <h3 class="text-xs font-black text-slate-900 dark:text-white">
                            نرخ‌های پیش‌فرض سراسری خطوط تلفن (در صورت عدم تعیین روی سرشماره)
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    پیامک هم‌پیش‌شماره (Intra-Prefix)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.sms_intra_prefix_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    پیامک بین‌پیش‌شماره / خارجی (Inter-Prefix)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.sms_inter_prefix_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    تماس صوتی هم‌پیش‌شماره (هر دقیقه)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.voice_intra_prefix_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    تماس صوتی بین‌پیش‌شماره / خارجی (هر دقیقه)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="globalForm.voice_inter_prefix_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        dir="ltr"
                                        class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 pl-14"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-slate-400 font-mono">TRX</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="submit"
                            :disabled="globalForm.processing"
                            class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-600/20 disabled:opacity-50 transition-colors"
                        >
                            {{ globalForm.processing ? 'در حال ثبت...' : 'ذخیره تعرفه‌های سراسری' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Edit Prefix Tariff Modal -->
            <div
                v-if="editingPrefix"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md"
                @click.self="closeEditPrefix"
            >
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-lg shadow-2xl space-y-5">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>ویرایش تعرفه‌های سرشماره:</span>
                            <span class="font-mono text-indigo-600 dark:text-indigo-400" dir="ltr">{{ editingPrefix.prefix }}</span>
                        </h3>
                        <button type="button" @click="closeEditPrefix" class="text-slate-400 hover:text-slate-700 dark:hover:text-white">✕</button>
                    </div>

                    <form @submit.prevent="submitPrefixTariff" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                    پیامک هم‌پیش‌شماره (Intra)
                                </label>
                                <input
                                    v-model="prefixForm.sms_intra_prefix_rate"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    required
                                    dir="ltr"
                                    class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                    پیامک بین‌پیش‌شماره (Inter)
                                </label>
                                <input
                                    v-model="prefixForm.sms_inter_prefix_rate"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    required
                                    dir="ltr"
                                    class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                    تماس صوتی هم‌پیش‌شماره (دقیقه)
                                </label>
                                <input
                                    v-model="prefixForm.voice_intra_prefix_rate"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    required
                                    dir="ltr"
                                    class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                    تماس صوتی بین‌پیش‌شماره (دقیقه)
                                </label>
                                <input
                                    v-model="prefixForm.voice_inter_prefix_rate"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    required
                                    dir="ltr"
                                    class="w-full text-xs font-mono rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                                />
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
                            <button type="button" @click="closeEditPrefix" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                                انصراف
                            </button>
                            <button
                                type="submit"
                                :disabled="prefixForm.processing"
                                class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-600/20 disabled:opacity-50"
                            >
                                {{ prefixForm.processing ? 'در حال ذخیره...' : 'ذخیره تعرفه‌های سرشماره' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

