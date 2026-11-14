<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface SampleLine {
    id: number;
    number: string;
    formatted_number: string;
    type_label: string;
    status_label: string;
    status_badge: string;
    display_title: string;
    user_name: string | null;
    edit_url: string;
}

interface PhoneLinesData {
    definition_id: number;
    is_purchasable: boolean;
    is_active: boolean;
    prefixes_url: string;
    sample_lines: SampleLine[];
    total_lines: number;
}

interface CustomIdsData {
    definition_id: number;
    is_purchasable: boolean;
    requires_approval: boolean;
    pricing: {
        regular: number;
        semi_rond: number;
        golden_rond: number;
    };
    total_allocations: number;
}

interface TldItem {
    id: number;
    tld: string;
    base_price: string;
    multiplier_rond: string;
    multiplier_semi_rond: string;
    is_active: boolean;
}

interface Web3DomainsData {
    definition_id: number;
    is_purchasable: boolean;
    tlds: TldItem[];
}

const props = defineProps<{
    phone_lines: PhoneLinesData;
    custom_ids: CustomIdsData;
    web3_domains: Web3DomainsData;
}>();

const activeTab = ref<'phone_lines' | 'custom_ids' | 'web3_domains'>('phone_lines');

// 1. Phone Lines Actions
const togglePurchasable = (defId: number) => {
    router.post(route('admin.services.toggle_purchasable', defId), {}, {
        preserveScroll: true,
    });
};

// 2. Custom IDs Actions
const toggleIdApproval = () => {
    router.post(
        route('admin.services.approval_mode', props.custom_ids.definition_id),
        { requires_approval: !props.custom_ids.requires_approval },
        { preserveScroll: true }
    );
};

const idPricingForm = useForm({
    regular: props.custom_ids.pricing.regular,
    semi_rond: props.custom_ids.pricing.semi_rond,
    golden_rond: props.custom_ids.pricing.golden_rond,
});

const submitIdPricing = () => {
    idPricingForm.post(route('admin.services.id_pricing'), {
        preserveScroll: true,
    });
};

// 3. Web3 Domains TLD Management
const isAddTldModalOpen = ref(false);
const editingTld = ref<TldItem | null>(null);

const tldForm = useForm({
    tld: '',
    base_price: '50.0000',
    multiplier_rond: '2.5',
    multiplier_semi_rond: '1.5',
});

const editTldForm = useForm({
    base_price: '',
    multiplier_rond: '',
    multiplier_semi_rond: '',
    is_active: true,
});

const openAddTldModal = () => {
    tldForm.reset();
    tldForm.clearErrors();
    isAddTldModalOpen.value = true;
};

const submitAddTld = () => {
    tldForm.post(route('admin.services.tld.store'), {
        preserveScroll: true,
        onSuccess: () => {
            isAddTldModalOpen.value = false;
        },
    });
};

const openEditTldModal = (tld: TldItem) => {
    editingTld.value = tld;
    editTldForm.base_price = tld.base_price;
    editTldForm.multiplier_rond = tld.multiplier_rond;
    editTldForm.multiplier_semi_rond = tld.multiplier_semi_rond;
    editTldForm.is_active = tld.is_active;
};

const submitEditTld = () => {
    if (!editingTld.value) return;
    editTldForm.put(route('admin.services.tld.update', editingTld.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingTld.value = null;
        },
    });
};

const deleteTld = (tld: TldItem) => {
    if (confirm(`آیا از حذف پسوند .${tld.tld} اطمینان دارید؟`)) {
        router.delete(route('admin.services.tld.destroy', tld.id), {
            preserveScroll: true,
        });
    }
};

const formatCurrency = (val: string | number) => {
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(Number(val) || 0);
};
</script>

<template>
    <Head title="مدیریت معماری سرویس‌ها" />

    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        مدیریت تخصصی سرویس‌های پلتفرم
                    </h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        تنظیمات جامع خطوط، آیدی‌ها و دامنه‌های Web3، سوییچ‌های فروش و ماتریس قیمت‌گذاری
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        :href="route('admin.orders.index')"
                        class="px-4 py-2 text-sm font-medium rounded-xl text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 transition-colors"
                    >
                        کنسول بررسی سفارشات &larr;
                    </Link>
                </div>
            </div>

            <!-- Tab Navigation (3 Clean Tabs) -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="flex gap-4" aria-label="Tabs">
                    <button
                        type="button"
                        @click="activeTab = 'phone_lines'"
                        class="py-3 px-5 text-sm font-bold border-b-2 transition-colors flex items-center gap-2"
                        :class="activeTab === 'phone_lines' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        <span>۱. خطوط (Phone Lines)</span>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                            {{ phone_lines.total_lines }}
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'custom_ids'"
                        class="py-3 px-5 text-sm font-bold border-b-2 transition-colors flex items-center gap-2"
                        :class="activeTab === 'custom_ids' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span>۲. آیدی‌ها (Handles / IDs)</span>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                            {{ custom_ids.total_allocations }}
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'web3_domains'"
                        class="py-3 px-5 text-sm font-bold border-b-2 transition-colors flex items-center gap-2"
                        :class="activeTab === 'web3_domains' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                        <span>۳. دامنه‌های Web3 و پسوندها (TLDs)</span>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                            {{ web3_domains.tlds.length }} TLD
                        </span>
                    </button>
                </nav>
            </div>

            <!-- Tab 1: خطوط (Phone Lines) -->
            <div v-if="activeTab === 'phone_lines'" class="space-y-6">
                <!-- Global Controls Card -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            سوییچ کلی خرید خطوط توسط کاربران
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            در صورت غیرفعال بودن این گزینه، ثبت سفارش خط جدید در پنل کاربری موقتاً مسدود می‌شود.
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        <Link
                            :href="phone_lines.prefixes_url"
                            class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold rounded-xl text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 border border-indigo-200 dark:border-indigo-800/50 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            مدیریت تخصصی تعرفه سرشماره‌ها
                        </Link>

                        <button
                            type="button"
                            @click="togglePurchasable(phone_lines.definition_id)"
                            class="px-5 py-2.5 text-sm font-bold rounded-xl transition-colors shadow-sm"
                            :class="phone_lines.is_purchasable ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-300 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        >
                            {{ phone_lines.is_purchasable ? 'خرید خطوط فعال است (غیرفعال‌سازی)' : 'خرید غیرفعال است (فعال‌سازی)' }}
                        </button>
                    </div>
                </div>

                <!-- Lines Table with Dedicated Full Edit Page Links -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">
                                لیست نمونه خطوط و پیوند مستقیم به ویرایش تخصصی
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                برای تنظیم کامل مسیریابی، وضعیت دوطرفه/یک‌طرفه و مانیتورینگ مصرف خط، وارد صفحه ویرایش اختصاصی شوید.
                            </p>
                        </div>
                        <Link
                            :href="route('admin.numbers.index')"
                            class="text-xs font-bold text-indigo-600 hover:underline"
                        >
                            مشاهده تمام خطوط در سامانه ({{ phone_lines.total_lines }}) &larr;
                        </Link>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-gray-50/75 dark:bg-gray-700/50 text-xs font-semibold text-gray-500 uppercase border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="p-4">شماره خط</th>
                                    <th class="p-4">نوع</th>
                                    <th class="p-4">عنوان نمایشی</th>
                                    <th class="p-4">مالک فعلی</th>
                                    <th class="p-4">وضعیت</th>
                                    <th class="p-4 text-center">ویرایش اختصاصی</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr
                                    v-for="line in phone_lines.sample_lines"
                                    :key="line.id"
                                    class="hover:bg-gray-50/50 dark:hover:bg-gray-700/40"
                                >
                                    <td class="p-4 font-mono font-bold text-gray-900 dark:text-white">
                                        {{ line.formatted_number }}
                                    </td>
                                    <td class="p-4 text-xs text-gray-600 dark:text-gray-300">
                                        {{ line.type_label }}
                                    </td>
                                    <td class="p-4 text-sm text-gray-700 dark:text-gray-200">
                                        {{ line.display_title }}
                                    </td>
                                    <td class="p-4 text-xs font-medium text-gray-900 dark:text-white">
                                        {{ line.user_name || 'بدون مالک' }}
                                    </td>
                                    <td class="p-4">
                                        <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="line.status_badge">
                                            {{ line.status_label }}
                                        </span>
                                    </td>
                                    <td class="p-4 text-center">
                                        <Link
                                            :href="line.edit_url"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 transition-colors"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            صفحه ویرایش مجزا
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab 2: آیدی‌ها (Handles / Custom IDs) -->
            <div v-if="activeTab === 'custom_ids'" class="space-y-6">
                <!-- Controls Card -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h2 class="text-base font-bold text-gray-900 dark:text-white">
                                وضعیت فروش و مکانیزم تایید آیدی‌ها
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                                فعال‌سازی کلی خرید و نحوه تایید سفارشات (تایید خودکار آنی یا بازبینی دستی توسط مدیر)
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- Toggle Purchasable -->
                            <button
                                type="button"
                                @click="togglePurchasable(custom_ids.definition_id)"
                                class="px-4 py-2 text-xs font-bold rounded-xl transition-colors"
                                :class="custom_ids.is_purchasable ? 'bg-emerald-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                            >
                                {{ custom_ids.is_purchasable ? 'خرید آیدی: فعال' : 'خرید آیدی: غیرفعال' }}
                            </button>

                            <!-- Toggle Approval Mode -->
                            <button
                                type="button"
                                @click="toggleIdApproval"
                                class="px-4 py-2 text-xs font-bold rounded-xl transition-colors border"
                                :class="custom_ids.requires_approval ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' : 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300'"
                            >
                                مکانیزم: {{ custom_ids.requires_approval ? 'نیازمند تایید مدیر' : 'تایید خودکار آنی (Auto)' }}
                            </button>
                        </div>
                    </div>

                    <!-- ID Rarity Pricing Table -->
                    <div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white mb-1">
                            جدول قیمت‌گذاری هوشمند آیدی‌ها بر اساس الگوریتم رند بودن
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            مبالغ پایه محاسبه شده در موتور اعتبارسنجی زنده (IdRarityEngine) برای انواع رده‌های رندی
                        </p>

                        <form @submit.prevent="submitIdPricing" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30">
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                    آیدی‌های عادی (Regular)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="idPricingForm.regular"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white pr-3 pl-12"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-gray-400">کوین</span>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">آیدی‌های استاندارد بدون الگوی تکرار</p>
                            </div>

                            <div class="p-4 rounded-xl border border-amber-200 dark:border-amber-800/50 bg-amber-50/40 dark:bg-amber-900/10">
                                <label class="block text-xs font-bold text-amber-800 dark:text-amber-300 mb-1">
                                    آیدی‌های نیمه‌رند (Semi-Rond)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="idPricingForm.semi_rond"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        class="w-full text-sm font-mono rounded-xl border-amber-300 dark:border-amber-700 dark:bg-gray-700 dark:text-white pr-3 pl-12"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-gray-400">کوین</span>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">طول ۴ کاراکتر یا الگوهای تکرار دوتایی</p>
                            </div>

                            <div class="p-4 rounded-xl border border-purple-200 dark:border-purple-800/50 bg-purple-50/40 dark:bg-purple-900/10">
                                <label class="block text-xs font-bold text-purple-800 dark:text-purple-300 mb-1">
                                    آیدی‌های طلایی و VIP (Golden Rond)
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="idPricingForm.golden_rond"
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        required
                                        class="w-full text-sm font-mono rounded-xl border-purple-300 dark:border-purple-700 dark:bg-gray-700 dark:text-white pr-3 pl-12"
                                    />
                                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-xs text-gray-400">کوین</span>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">۳ کاراکتری، کلمات کلیدی VIP یا آینه‌ای</p>
                            </div>

                            <div class="sm:col-span-3 flex justify-end pt-2">
                                <button
                                    type="submit"
                                    :disabled="idPricingForm.processing"
                                    class="px-5 py-2.5 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50"
                                >
                                    {{ idPricingForm.processing ? 'در حال ثبت...' : 'ذخیره جدول تعرفه آیدی‌ها' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tab 3: دامنه‌های Web3 و پسوندها (TLDs) -->
            <div v-if="activeTab === 'web3_domains'" class="space-y-6">
                <!-- Header Card -->
                <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-white">
                            مدیریت پسوندهای مجاز (TLDs) و نرخ‌گذاری دامنه‌ها
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            تعریف پسوندهای جدید، نرخ پایه هر پسوند و ضریب تصاعدی دامنه‌های رند
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            @click="togglePurchasable(web3_domains.definition_id)"
                            class="px-4 py-2 text-xs font-bold rounded-xl transition-colors"
                            :class="web3_domains.is_purchasable ? 'bg-emerald-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        >
                            {{ web3_domains.is_purchasable ? 'خرید دامنه‌ها: فعال' : 'خرید دامنه‌ها: غیرفعال' }}
                        </button>
                        <button
                            type="button"
                            @click="openAddTldModal"
                            class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700 transition-colors shadow-sm"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            افزودن پسوند TLD جدید
                        </button>
                    </div>
                </div>

                <!-- TLD List Table -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/60 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-gray-50/75 dark:bg-gray-700/50 text-xs font-semibold text-gray-500 uppercase border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="p-4">پسوند دامنه (TLD)</th>
                                    <th class="p-4">قیمت پایه (کوین)</th>
                                    <th class="p-4">ضریب رند طلایی</th>
                                    <th class="p-4">ضریب نیمه‌رند</th>
                                    <th class="p-4">وضعیت</th>
                                    <th class="p-4 text-center">عملیات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-if="web3_domains.tlds.length === 0">
                                    <td colspan="6" class="p-8 text-center text-gray-400">
                                        هیچ پسوند دامنه‌ای تعریف نشده است.
                                    </td>
                                </tr>
                                <tr
                                    v-for="tld in web3_domains.tlds"
                                    :key="tld.id"
                                    class="hover:bg-gray-50/50 dark:hover:bg-gray-700/40"
                                >
                                    <td class="p-4">
                                        <span class="font-mono text-base font-black text-indigo-600 dark:text-indigo-400">
                                            .{{ tld.tld }}
                                        </span>
                                    </td>
                                    <td class="p-4 font-mono font-bold text-gray-900 dark:text-white">
                                        {{ formatCurrency(tld.base_price) }}
                                    </td>
                                    <td class="p-4 font-mono text-xs text-purple-600 dark:text-purple-400">
                                        &times;{{ tld.multiplier_rond }}
                                    </td>
                                    <td class="p-4 font-mono text-xs text-amber-600 dark:text-amber-400">
                                        &times;{{ tld.multiplier_semi_rond }}
                                    </td>
                                    <td class="p-4">
                                        <span
                                            class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold"
                                            :class="tld.is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-gray-100 text-gray-600'"
                                        >
                                            {{ tld.is_active ? 'فعال' : 'غیرفعال' }}
                                        </span>
                                    </td>
                                    <td class="p-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button
                                                type="button"
                                                @click="openEditTldModal(tld)"
                                                class="px-3 py-1 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 transition-colors"
                                            >
                                                ویرایش تعرفه
                                            </button>
                                            <button
                                                type="button"
                                                @click="deleteTld(tld)"
                                                class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-rose-50 hover:bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 transition-colors"
                                            >
                                                حذف
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add TLD Modal -->
        <div v-if="isAddTldModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">تعریف پسوند TLD جدید</h3>
                <form @submit.prevent="submitAddTld" class="mt-4 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">نام پسوند (بدون نقطه)</label>
                        <input
                            v-model="tldForm.tld"
                            type="text"
                            required
                            placeholder="مثال: eth یا id یا org"
                            class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                        <div v-if="tldForm.errors.tld" class="text-xs text-rose-500 mt-1">{{ tldForm.errors.tld }}</div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">قیمت پایه (کوین)</label>
                        <input
                            v-model="tldForm.base_price"
                            type="number"
                            step="0.0001"
                            min="0"
                            required
                            class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">ضریب نیمه‌رند</label>
                            <input
                                v-model="tldForm.multiplier_semi_rond"
                                type="number"
                                step="0.1"
                                min="1"
                                required
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">ضریب رند طلایی</label>
                            <input
                                v-model="tldForm.multiplier_rond"
                                type="number"
                                step="0.1"
                                min="1"
                                required
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="isAddTldModalOpen = false"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-gray-600 bg-gray-100 dark:bg-gray-700"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="tldForm.processing"
                            class="px-4 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700"
                        >
                            افزودن پسوند
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit TLD Modal -->
        <div v-if="editingTld" class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-gray-100 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">ویرایش تعرفه پسوند .{{ editingTld.tld }}</h3>
                <form @submit.prevent="submitEditTld" class="mt-4 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">قیمت پایه (کوین)</label>
                        <input
                            v-model="editTldForm.base_price"
                            type="number"
                            step="0.0001"
                            min="0"
                            required
                            class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">ضریب نیمه‌رند</label>
                            <input
                                v-model="editTldForm.multiplier_semi_rond"
                                type="number"
                                step="0.1"
                                min="1"
                                required
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">ضریب رند طلایی</label>
                            <input
                                v-model="editTldForm.multiplier_rond"
                                type="number"
                                step="0.1"
                                min="1"
                                required
                                class="w-full text-sm font-mono rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            id="edit_tld_active"
                            v-model="editTldForm.is_active"
                            type="checkbox"
                            class="w-4 h-4 text-indigo-600 rounded"
                        />
                        <label for="edit_tld_active" class="text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">
                            پسوند در فرم‌های خرید فعال و قابل سفارش باشد
                        </label>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="editingTld = null"
                            class="px-4 py-2 text-xs font-semibold rounded-xl text-gray-600 bg-gray-100 dark:bg-gray-700"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="editTldForm.processing"
                            class="px-4 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700"
                        >
                            ذخیره تغییرات پسوند
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
