<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';

interface PhoneNumberItem {
    id: number;
    number: string;
    formatted_number: string;
    effective_title: string;
    display_title: string | null;
    type: string;
    type_label: string;
    type_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    allocated_at: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedData<T> {
    data: T[];
    links: PaginationLink[];
    total: number;
    current_page: number;
    last_page: number;
}

interface PrefixItem {
    id: number;
    prefix: string;
    name: string;
    is_default: boolean;
}

const props = defineProps<{
    numbers: PaginatedData<PhoneNumberItem>;
    filters: {
        search?: string;
        type?: string;
    };
    quota: {
        active: number;
        max: number;
    };
    default_prefix?: string;
    active_prefixes?: PrefixItem[];
}>();

// Search & Filter state
const search = ref(props.filters.search || '');
const typeFilter = ref(props.filters.type || '');

const handleFilter = () => {
    router.get(
        route('numbers.index'),
        {
            search: search.value || undefined,
            type: typeFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const clearFilter = () => {
    search.value = '';
    typeFilter.value = '';
    handleFilter();
};

// Allocation Modal State
const isAllocateModalOpen = ref(false);
const termsAccepted = ref(false);
const allocateForm = useForm({});

const openAllocateModal = () => {
    termsAccepted.value = false;
    allocateForm.reset();
    isAllocateModalOpen.value = true;
};

const closeAllocateModal = () => {
    isAllocateModalOpen.value = false;
};

const submitAllocation = () => {
    if (!termsAccepted.value) return;
    allocateForm.post(route('numbers.store'), {
        onSuccess: () => closeAllocateModal(),
    });
};

// Edit Title Modal State
const isEditTitleModalOpen = ref(false);
const selectedNumberForTitle = ref<PhoneNumberItem | null>(null);
const titleForm = useForm({
    display_title: '',
});

const openEditTitleModal = (item: PhoneNumberItem) => {
    selectedNumberForTitle.value = item;
    titleForm.display_title = item.display_title || '';
    isEditTitleModalOpen.value = true;
};

const closeEditTitleModal = () => {
    isEditTitleModalOpen.value = false;
    selectedNumberForTitle.value = null;
    titleForm.reset();
};

const submitTitleUpdate = () => {
    if (!selectedNumberForTitle.value) return;
    titleForm.put(route('numbers.update', selectedNumberForTitle.value.id), {
        onSuccess: () => closeEditTitleModal(),
    });
};

// Release Modal State
const isReleaseModalOpen = ref(false);
const numberToRelease = ref<PhoneNumberItem | null>(null);
const releaseProcessing = ref(false);

const openReleaseModal = (item: PhoneNumberItem) => {
    numberToRelease.value = item;
    isReleaseModalOpen.value = true;
};

const closeReleaseModal = () => {
    isReleaseModalOpen.value = false;
    numberToRelease.value = null;
};

const confirmRelease = () => {
    if (!numberToRelease.value) return;

    releaseProcessing.value = true;
    router.delete(route('numbers.destroy', numberToRelease.value.id), {
        onFinish: () => {
            releaseProcessing.value = false;
            closeReleaseModal();
        },
    });
};

// Copy notification state
const copiedId = ref<number | null>(null);
const copyNumber = (item: PhoneNumberItem) => {
    navigator.clipboard.writeText(item.formatted_number);
    copiedId.value = item.id;
    setTimeout(() => {
        copiedId.value = null;
    }, 2000);
};
</script>

<template>
    <Head title="مدیریت خطوط سازمانی" />

    <UserLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                <h1 class="text-base font-bold text-slate-900 dark:text-white">خطوط ارتباطی سازمانی</h1>
            </div>
        </template>

        <!-- Background Ambient Mesh -->
        <div class="relative">
            <!-- Header Card & Quick Action -->
            <div class="glass-card !p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            <span class="glass-pill text-[11px] text-indigo-600 dark:text-indigo-400 font-bold">
                                استخر شماره‌های اختصاصی
                            </span>
                            <span class="glass-pill text-[11px] text-emerald-600 dark:text-emerald-400 font-mono font-bold" dir="ltr">
                                {{ default_prefix || '+85' }}
                            </span>
                        </div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                            مدیریت و پایش خطوط ارتباطی
                        </h1>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-xl">
                            فهرست سرشماره‌ها و خطوط فعال اختصاص‌یافته به حساب کاربری سازمانی، با قابلیت کپی آنی، تنظیم عنوان و مدیریت چرخه واگذاری.
                        </p>
                    </div>

                    <!-- Action Button -->
                    <button
                        type="button"
                        @click="openAllocateModal"
                        :disabled="quota.active >= quota.max"
                        class="glass-btn-primary !w-auto !py-2.5 !px-5 !text-xs !rounded-2xl shrink-0 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>دریافت خط سازمانی جدید</span>
                    </button>
                </div>
            </div>

            <!-- Quota Status & Search Filter Bar -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Quota Card -->
                <div class="glass-card !p-5 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold text-slate-600 dark:text-slate-300">سقف مجاز خطوط فعال</span>
                            <span dir="ltr" class="text-xs font-mono font-black text-indigo-600 dark:text-indigo-400">
                                {{ quota.active }} / {{ quota.max }}
                            </span>
                        </div>
                        <!-- Gradient Progress Bar -->
                        <div class="w-full h-2.5 bg-slate-200/60 dark:bg-slate-800/80 rounded-full overflow-hidden">
                            <div
                                class="h-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-full transition-all duration-500 shadow-sm shadow-indigo-500/50"
                                :style="{ width: `${Math.min(100, (quota.active / quota.max) * 100)}%` }"
                            ></div>
                        </div>
                    </div>

                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-3 pt-2 border-t border-slate-200/50 dark:border-white/5 flex items-center justify-between">
                        <span>{{ quota.max - quota.active > 0 ? `ظرفیت آزاد: ${quota.max - quota.active} خط دیگر` : 'سقف مجاز تکمیل گردیده است.' }}</span>
                        <span class="status-dot-pulse"></span>
                    </p>
                </div>

                <!-- Search & Filters -->
                <div class="lg:col-span-2 glass-card !p-5 flex flex-col sm:flex-row items-center gap-3">
                    <div class="relative flex-1 w-full">
                        <input
                            type="text"
                            v-model="search"
                            @keyup.enter="handleFilter"
                            placeholder="جستجو در شماره‌ها یا عناوین خطوط..."
                            class="glass-input !py-2.5 !pr-10 !text-xs"
                        />
                        <svg class="w-4 h-4 text-slate-400 absolute right-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    <div class="w-full sm:w-48">
                        <select
                            v-model="typeFilter"
                            @change="handleFilter"
                            class="glass-input !py-2.5 !px-3 !text-xs cursor-pointer"
                        >
                            <option value="">همه انواع خطوط</option>
                            <option value="user_standard">شماره‌های عادی ۹ رقمی</option>
                            <option value="user_service">سرشماره‌های اختصاصی (کوتاه)</option>
                        </select>
                    </div>

                    <div class="flex gap-2 w-full sm:w-auto shrink-0">
                        <button
                            type="button"
                            @click="handleFilter"
                            class="glass-btn-primary !w-auto !py-2.5 !px-4 !text-xs !rounded-xl cursor-pointer"
                        >
                            اعمال فیلتر
                        </button>
                        <button
                            type="button"
                            v-if="search || typeFilter"
                            @click="clearFilter"
                            class="glass-pill !py-2.5 !px-3 !text-xs hover:text-rose-500 transition-colors cursor-pointer"
                            title="پاکسازی فیلتر"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </div>

            <!-- Numbers Grid -->
            <div v-if="numbers.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                <div
                    v-for="item in numbers.data"
                    :key="item.id"
                    class="glass-card group flex flex-col justify-between hover:border-indigo-500/40 transition-all duration-300"
                >
                    <div>
                        <!-- Top Row: Type & Status Badges -->
                        <div class="flex items-center justify-between gap-2 mb-4">
                            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border shadow-xs" :class="item.type_badge">
                                {{ item.type_label }}
                            </span>
                            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border flex items-center gap-1.5 shadow-xs" :class="item.status_badge">
                                <span class="w-1.5 h-1.5 rounded-full" :class="item.status === 'allocated' ? 'bg-blue-500' : 'bg-emerald-500'"></span>
                                {{ item.status_label }}
                            </span>
                        </div>

                        <!-- Strictly LTR Monospace Phone Presentation Box -->
                        <div class="p-4 rounded-2xl bg-slate-100/70 dark:bg-slate-950/70 border border-slate-200/80 dark:border-white/10 mb-4 flex items-center justify-between group-hover:border-indigo-500/30 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-purple-600 text-white flex items-center justify-center font-bold text-xs shadow-md shadow-indigo-500/20 shrink-0">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                    </svg>
                                </div>
                                <div dir="ltr" class="text-left">
                                    <span class="font-mono text-lg font-black text-slate-900 dark:text-white tracking-wider block select-all">
                                        {{ item.formatted_number }}
                                    </span>
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-mono">
                                        شناسه خط: #{{ item.id }}
                                    </span>
                                </div>
                            </div>

                            <!-- One-Click Copy Button -->
                            <button
                                type="button"
                                @click="copyNumber(item)"
                                class="p-2.5 rounded-xl glass-pill hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors cursor-pointer"
                                :title="copiedId === item.id ? 'شماره در حافظه کپی شد' : 'کپی سرشماره'"
                            >
                                <svg v-if="copiedId === item.id" class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </button>
                        </div>

                        <!-- Title & Custom Name Area -->
                        <div class="mb-4 p-3 rounded-xl bg-slate-50/50 dark:bg-slate-900/40 border border-slate-200/40 dark:border-white/5 flex items-center justify-between">
                            <div class="truncate">
                                <span class="text-[10px] text-slate-400 block">عنوان خط:</span>
                                <span class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate block">
                                    {{ item.display_title || 'بدون عنوان (نمایش شماره)' }}
                                </span>
                            </div>
                            <button
                                type="button"
                                @click="openEditTitleModal(item)"
                                class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline shrink-0 px-2 py-1 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-950/40 cursor-pointer"
                            >
                                ویرایش
                            </button>
                        </div>

                        <!-- Metadata Info -->
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 space-y-1.5 mb-4">
                            <div class="flex items-center justify-between">
                                <span>تاریخ تخصیص:</span>
                                <span dir="ltr" class="font-mono font-medium text-slate-700 dark:text-slate-300">
                                    {{ item.allocated_at || item.created_at }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>پروتکل پیامک:</span>
                                <span class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    وب‌سرویس و وب‌هوک داخلی
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Bottom Actions -->
                    <div class="pt-3 border-t border-slate-200/60 dark:border-white/10 flex items-center justify-between gap-2">
                        <!-- Direct Link to Messages of this Line -->
                        <Link
                            :href="route('messages.index', { phone_number_id: item.id })"
                            class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                            </svg>
                            <span>مشاهده مکالمات</span>
                        </Link>

                        <!-- Release Button -->
                        <button
                            type="button"
                            @click="openReleaseModal(item)"
                            class="inline-flex items-center gap-1 py-1.5 px-3 rounded-xl text-xs font-bold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            <span>آزادسازی خط</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 text-indigo-500 mx-auto flex items-center justify-center mb-4">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1.5">
                    در حال حاضر هیچ خط سازمانی به این حساب تخصیص نیافته است
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto mb-6 leading-relaxed">
                    شما می‌توانید جهت شروع تبادل پیام و دریافت وب‌هوک‌های داخلی سامانه، از طریق دکمه زیر اولین خط سازمانی اختصاصی خود را دریافت فرمایید.
                </p>
                <button
                    type="button"
                    @click="openAllocateModal"
                    class="glass-btn-primary !w-auto !py-2.5 !px-6 !text-xs !rounded-2xl cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>دریافت اولین خط سازمانی</span>
                </button>
            </div>
        </div>

        <!-- Allocation Modal with Dynamic Prefix Display & Privacy Clarification -->
        <div v-if="isAllocateModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md">
            <div class="glass-card !p-6 w-full max-w-lg shadow-2xl relative animate-in fade-in zoom-in-95 duration-200">
                <div class="flex items-center justify-between pb-4 border-b border-slate-200/60 dark:border-white/10 mb-5">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-purple-600 text-white flex items-center justify-center font-bold text-sm shadow-md shadow-indigo-500/25">
                            +
                        </div>
                        <div>
                            <h3 class="font-black text-sm text-slate-900 dark:text-white">دریافت خط ارتباطی سازمانی جدید</h3>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">تخصیص اتمیک از استخر شماره‌های آزاد</span>
                        </div>
                    </div>
                    <button type="button" @click="closeAllocateModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitAllocation" class="space-y-4">
                    <!-- Dynamic Prefix Info Box -->
                    <div class="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-500/20 text-xs leading-relaxed space-y-2">
                        <div class="flex items-center justify-between font-bold text-indigo-900 dark:text-indigo-300">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                پیش‌شماره فعال سامانه:
                            </span>
                            <span dir="ltr" class="font-mono text-sm font-black text-indigo-600 dark:text-indigo-400">
                                {{ default_prefix || '+85' }}
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-600 dark:text-slate-300">
                            یک خط ۹ رقمی استاندارد یکتا به‌صورت کاملاً اتمیک از استخر شماره‌های آزاد تخصیص خواهد یافت. پیام‌های ورودی به این خط از طریق پیشخوان و وب‌سرویس در دسترس شما خواهند بود.
                        </p>
                    </div>

                    <!-- Terms & Non-Affiliation Declaration -->
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/25 text-amber-900 dark:text-amber-200 text-xs leading-relaxed space-y-2.5">
                        <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-300">
                            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>بیانیه عدم وابستگی و ضوابط بهره‌برداری سازمانی</span>
                        </div>
                        <p class="text-[11px] text-slate-700 dark:text-slate-300 leading-relaxed">
                            این سامانه یک بستر کاملاً مستقل ارائه خدمات ارتباطی سازمانی بوده و به هیچ حزب، گروه، سازمان‌های سیاسی، نهادهای دولتی یا حاکمیتی وابسته نبوده و هیچ‌گونه خطی بابت فعالیت‌های مذکور اختصاص نمی‌دهد. خطوط تخصیص‌یافته صرفاً در درگاه‌های مجاز و معین در دسترس خواهند بود.
                        </p>
                        <label class="flex items-start gap-2 pt-1 font-semibold cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="termsAccepted"
                                required
                                class="mt-0.5 rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                            />
                            <span class="text-[11px] text-slate-800 dark:text-slate-200">
                                ضوابط فوق و مقررات بهره‌برداری داخلی را به طور کامل مطالعه نموده و تأیید می‌نمایم.
                            </span>
                        </label>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-4 border-t border-slate-200/60 dark:border-white/10 flex items-center justify-end gap-2.5">
                        <button
                            type="button"
                            @click="closeAllocateModal"
                            class="glass-pill !py-2 !px-4 !text-xs cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="!termsAccepted || allocateForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl cursor-pointer disabled:opacity-40"
                        >
                            {{ allocateForm.processing ? 'در حال ثبت و تخصیص...' : 'تأیید و دریافت خط' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Title Modal -->
        <div v-if="isEditTitleModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md">
            <div class="glass-card !p-6 w-full max-w-md shadow-2xl relative animate-in fade-in zoom-in-95 duration-200">
                <div class="flex items-center justify-between pb-4 border-b border-slate-200/60 dark:border-white/10 mb-4">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">
                        ویرایش عنوان خط {{ selectedNumberForTitle?.formatted_number }}
                    </h3>
                    <button type="button" @click="closeEditTitleModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitTitleUpdate" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                            عنوان نمایشی خط
                        </label>
                        <input
                            type="text"
                            v-model="titleForm.display_title"
                            placeholder="مثال: واحد پشتیبانی یا خدمات مالی"
                            maxlength="100"
                            class="glass-input !py-2.5 !text-xs"
                        />
                        <span class="text-[10px] text-slate-400 mt-1 block">
                            در صورت خالی گذاشتن، شماره به‌صورت پیش‌فرض نمایش داده می‌شود.
                        </span>
                    </div>

                    <div class="pt-4 border-t border-slate-200/60 dark:border-white/10 flex items-center justify-end gap-2.5">
                        <button type="button" @click="closeEditTitleModal" class="glass-pill !py-2 !px-4 !text-xs cursor-pointer">
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="titleForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl cursor-pointer"
                        >
                            {{ titleForm.processing ? 'در حال ذخیره...' : 'ذخیره عنوان' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Release Confirmation Modal (With Explicit Privacy Guarantee) -->
        <div v-if="isReleaseModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md">
            <div class="glass-card !p-6 w-full max-w-md shadow-2xl text-center relative animate-in fade-in zoom-in-95 duration-200">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4 mx-auto">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>

                <h3 class="font-black text-sm text-slate-900 dark:text-white mb-2">
                    تأیید آزادسازی و بازگردانی خط
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-3 leading-relaxed">
                    آیا از آزادسازی خط <span dir="ltr" class="font-mono font-bold text-slate-900 dark:text-white inline-block">{{ numberToRelease?.formatted_number }}</span> اطمینان دارید؟
                </p>

                <!-- Privacy Guarantee Alert -->
                <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-700 dark:text-rose-300 text-[11px] leading-relaxed mb-5 text-right">
                    <b>ضمانت حریم خصوصی و امنیت داده‌ها:</b>
                    جهت جلوگیری از هرگونه نشت اطلاعات به مالکین آتی، به محض آزادسازی، کلیه پیام‌های دریافتی پیشین این خط به‌صورت قطعی از پایگاه‌داده حذف خواهند شد.
                </div>

                <div class="flex items-center justify-center gap-3">
                    <button
                        type="button"
                        @click="closeReleaseModal"
                        class="glass-pill !py-2.5 !px-5 !text-xs cursor-pointer"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRelease"
                        :disabled="releaseProcessing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/25 transition-all disabled:opacity-50 cursor-pointer"
                    >
                        {{ releaseProcessing ? 'در حال آزادسازی...' : 'تأیید و آزادسازی قطعی' }}
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
