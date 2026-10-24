<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';

interface PhoneNumberItem {
    id: number;
    number: string;
    formatted_number: string;
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

const allocateForm = useForm({});

const openAllocateModal = () => {
    allocateForm.reset();
    isAllocateModalOpen.value = true;
};

const closeAllocateModal = () => {
    isAllocateModalOpen.value = false;
};

const submitAllocation = () => {
    allocateForm.post(route('numbers.store'), {
        onSuccess: () => closeAllocateModal(),
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
    <Head title="خطوط سازمانی تخصیص‌یافته" />

    <UserLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">خطوط ارتباطی سازمانی</h1>
        </template>



        <!-- Page Header & Action Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm mb-6">
            <div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                    مدیریت خطوط سازمانی
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    فهرست و وضعیت خطوط ارتباطی سازمانی تخصیص‌یافته به حساب کاربری
                </p>
            </div>

            <!-- Action Button -->
            <button
                @click="openAllocateModal"
                :disabled="quota.active >= quota.max"
                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-500/20 hover:shadow-indigo-500/30 transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer shrink-0"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>دریافت خط سازمانی جدید</span>
            </button>
        </div>

        <!-- Quota Card & Filters -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Quota Status -->
            <div class="lg:col-span-1 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">سقف مجاز خطوط فعال</span>
                    <span dir="ltr" class="text-xs font-mono font-bold text-indigo-600 dark:text-indigo-400">
                        {{ quota.active }} / {{ quota.max }}
                    </span>
                </div>
                <!-- Progress Bar -->
                <div class="w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div
                        class="h-full bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full transition-all duration-500"
                        :style="{ width: `${Math.min(100, (quota.active / quota.max) * 100)}%` }"
                    ></div>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">
                    {{ quota.max - quota.active > 0 ? `امکان تخصیص ${quota.max - quota.active} خط ارتباطی دیگر برای این حساب فراهم است.` : 'سقف مجاز تخصیص خطوط فعال تکمیل گردیده است.' }}
                </p>
            </div>

            <!-- Search & Filters -->
            <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        placeholder="جستجو در شماره‌های تخصیص‌یافته..."
                        class="w-full pr-10 pl-4 py-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 outline-none"
                    />
                    <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <div class="w-full sm:w-48">
                    <select
                        v-model="typeFilter"
                        @change="handleFilter"
                        class="w-full py-2.5 px-3 text-xs rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 outline-none"
                    >
                        <option value="">همه انواع خطوط</option>
                        <option value="user_standard">شماره‌های عادی ۹ رقمی</option>
                        <option value="user_service">سرشماره‌های اختصاصی</option>
                    </select>
                </div>

                <div class="flex gap-2 w-full sm:w-auto">
                    <button
                        @click="handleFilter"
                        class="flex-1 sm:flex-none px-4 py-2.5 rounded-xl bg-slate-900 dark:bg-slate-100 hover:bg-slate-800 dark:hover:bg-white text-white dark:text-slate-900 text-xs font-bold transition-colors cursor-pointer"
                    >
                        فیلتر
                    </button>
                    <button
                        v-if="search || typeFilter"
                        @click="clearFilter"
                        class="px-3 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs transition-colors cursor-pointer"
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
                class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm hover:shadow-md transition-all flex flex-col justify-between"
            >
                <div>
                    <!-- Top row: Badges -->
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border" :class="item.type_badge">
                            {{ item.type_label }}
                        </span>
                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border" :class="item.status_badge">
                            {{ item.status_label }}
                        </span>
                    </div>

                    <!-- Strictly LTR Phone Number Presentation Box -->
                    <div class="bg-slate-50 dark:bg-slate-950/80 border border-slate-200/70 dark:border-slate-800/70 rounded-2xl p-4 mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/80 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <!-- Strictly LTR Number Display -->
                            <div dir="ltr" class="text-left">
                                <span class="font-mono text-lg font-black text-slate-900 dark:text-white tracking-wider block select-all">
                                    {{ item.formatted_number }}
                                </span>
                            </div>
                        </div>

                        <!-- Copy Button -->
                        <button
                            @click="copyNumber(item)"
                            class="p-2 rounded-xl text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 transition-colors cursor-pointer"
                            :title="copiedId === item.id ? 'در حافظه کپی شد' : 'کپی شماره سازمانی'"
                        >
                            <svg v-if="copiedId === item.id" class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <!-- Meta info -->
                    <div class="text-[11px] text-slate-400 space-y-1 mb-3">
                        <div class="flex items-center justify-between">
                            <span>تاریخ تخصیص رسمی:</span>
                            <span dir="ltr" class="font-medium font-mono text-slate-600 dark:text-slate-300">{{ item.allocated_at || '—' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Card Actions -->
                <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-end">
                    <button
                        @click="openReleaseModal(item)"
                        class="inline-flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-xs font-bold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
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
        <div v-else class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-12 text-center shadow-sm">
            <div class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-500 mx-auto flex items-center justify-center mb-4">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 dark:text-white mb-1">در حال حاضر هیچ خط سازمانی به این حساب کاربری تخصیص نیافته است</h3>
            <p class="text-xs text-slate-400 max-w-sm mx-auto mb-6">
                شما می‌توانید جهت بهره‌برداری از خدمات ارتباطی سامانه، با فشردن کلید زیر یک خط سازمانی اختصاصی دریافت فرمایید.
            </p>
            <button
                @click="openAllocateModal"
                class="inline-flex items-center gap-2 px-6 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-lg shadow-indigo-500/20 transition-all cursor-pointer"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>دریافت اولین خط سازمانی</span>
            </button>
        </div>

        <!-- Allocation Modal -->
        <div v-if="isAllocateModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-xl bg-indigo-100 dark:bg-indigo-900/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-sm">
                            +
                        </div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">تخصیص خط ارتباطی جدید</h3>
                    </div>
                    <button @click="closeAllocateModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitAllocation" class="space-y-4">
                    <!--
                        /*
                         * بخش انتخاب شماره دلخواه طبق دستور کاربر موقتاً غیرفعال شده است
                         * و در فازهای آتی مجدداً فعال خواهد شد:
                         *
                         * <div>
                         *   <label class="block text-xs font-bold mb-1.5">شماره ۹ رقمی دلخواه</label>
                         *   <input type="text" dir="ltr" placeholder="000123456" />
                         * </div>
                         */
                    -->

                    <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-300 leading-relaxed space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-slate-900 dark:text-white">
                            <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>تخصیص خط ارتباطی سازمانی</span>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            یک خط ارتباطی ۹ رقمی از استخر شماره‌های آزاد سامانه با پیشوند <span dir="ltr" class="font-mono font-bold text-slate-700 dark:text-slate-300">+85</span> به این حساب کاربری اختصاص می‌یابد.
                        </p>
                    </div>

                    <!-- Non-Affiliation and Terms Notice (Requirement 5) -->
                    <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 text-xs leading-relaxed space-y-2">
                        <div class="flex items-center gap-1.5 font-bold text-amber-800 dark:text-amber-300">
                            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>بیانیه عدم وابستگی و ضوابط بهره‌برداری</span>
                        </div>
                        <p class="text-[11px] leading-relaxed text-slate-700 dark:text-slate-300">
                            این سامانه یک بستر کاملاً مستقل ارائه خدمات ارتباطی بوده و به هیچ حزب، گروه، سازمان‌های تروریستی، نهادهای دولتی یا حاکمیتی وابسته نبوده و هیچ‌گونه خطی بابت فعالیت‌های مذکور اختصاص نمی‌دهد. خطوط تخصیص‌یافته صرفاً در سامانه‌ها و درگاه‌های مورد تأیید و تعیین‌شده توسط این سامانه در دسترس و فعال خواهند بود.
                        </p>
                        <label class="flex items-start gap-2 pt-1 font-semibold cursor-pointer">
                            <input type="checkbox" required class="mt-0.5 rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500" />
                            <span class="text-[11px] text-slate-800 dark:text-slate-200">ضوابط فوق و انحصار دسترسی در سامانه‌های تعیین‌شده را تأیید می‌نمایم.</span>
                        </label>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeAllocateModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="allocateForm.processing"
                            class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            {{ allocateForm.processing ? 'در حال ثبت...' : 'تأیید و دریافت خط' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Release Confirmation Modal -->
        <div v-if="isReleaseModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl">
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4 mx-auto">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>

                <h3 class="text-center font-black text-sm text-slate-900 dark:text-white mb-2">تأیید آزادسازی خط سازمانی</h3>
                <p class="text-center text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                    آیا از آزادسازی خط <span dir="ltr" class="font-mono font-bold text-slate-900 dark:text-white inline-block">{{ numberToRelease?.formatted_number }}</span> اطمینان دارید؟
                    با تأیید، این خط از حساب شما منفک شده و جهت تخصیص مجدد به استخر شماره‌های عمومی بازگردانده می‌شود.
                </p>

                <div class="flex items-center justify-center gap-3">
                    <button
                        @click="closeReleaseModal"
                        class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    >
                        انصراف
                    </button>
                    <button
                        @click="confirmRelease"
                        :disabled="releaseProcessing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/20 transition-all disabled:opacity-50 cursor-pointer"
                    >
                        {{ releaseProcessing ? 'در حال آزادسازی...' : 'تأیید و آزادسازی قطعی' }}
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
