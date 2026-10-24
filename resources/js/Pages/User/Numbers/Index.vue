<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';
import AllocateNumberModal from '@/Components/AllocateNumberModal.vue';

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

const openAllocateModal = () => {
    isAllocateModalOpen.value = true;
};

const closeAllocateModal = () => {
    isAllocateModalOpen.value = false;
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

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

// Copy phone number
const copyNumber = (item: PhoneNumberItem) => {
    navigator.clipboard.writeText(item.formatted_number);
    showToast(`شماره ${item.formatted_number} کپی شد`);
};

// Global escape key handler for modals
const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isEditTitleModalOpen.value) closeEditTitleModal();
        if (isReleaseModalOpen.value) closeReleaseModal();
        if (isAllocateModalOpen.value) closeAllocateModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <Head title="مدیریت خطوط سازمانی" />

    <UserLayout>
        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-4 scale-95" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-200 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 -translate-y-2 scale-95">
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4">
            <!-- 1. Quota Banner Card -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span class="wallet-title">سهمیه خطوط سازمانی شما</span>
                    </div>
                    <span
                        class="wallet-chip"
                        :class="[
                            quota.max - quota.active > 0
                                ? 'bg-emerald-500/15 border-emerald-500/35 text-emerald-600 dark:text-emerald-400'
                                : 'bg-rose-500/15 border-rose-500/35 text-rose-600 dark:text-rose-400'
                        ]"
                    >
                        {{ quota.max - quota.active > 0 ? `${quota.max - quota.active} خط آزاد` : 'تکمیل ظرفیت' }}
                    </span>
                </div>

                <div class="wallet-amount">
                    <span>{{ quota.active }}</span>
                    <span class="wallet-currency text-sm font-bold text-slate-500 dark:text-slate-400">/ {{ quota.max }} خط فعال</span>
                </div>

                <!-- Progress Bar -->
                <div class="mt-3">
                    <div class="h-2 w-full rounded-full bg-slate-200/80 dark:bg-slate-800/60 overflow-hidden p-0.5 border border-slate-300/60 dark:border-white/10">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 transition-all duration-700 shadow-sm"
                            :style="{ width: `${Math.min(100, (quota.active / quota.max) * 100)}%` }"
                        ></div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 pt-3 border-t border-slate-200/80 dark:border-white/10">
                    <span class="text-[11px] text-slate-600 dark:text-slate-400">
                        پیش‌شماره سامانه: <strong class="font-mono text-indigo-600 dark:text-indigo-400 font-black" dir="ltr">{{ default_prefix || '+85' }}</strong>
                    </span>

                    <button
                        type="button"
                        @click="openAllocateModal"
                        :disabled="quota.active >= quota.max"
                        class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>تخصیص خط جدید</span>
                    </button>
                </div>
            </div>

            <!-- 2. Search & Filter Bar -->
            <div class="glass-card !p-3 flex items-center gap-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="relative flex-1">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        placeholder="جستجو در خطوط یا عناوین..."
                        class="glass-input !py-2 !pr-8 !pl-3 !text-xs !rounded-xl w-full bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                    />
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <select
                    v-model="typeFilter"
                    @change="handleFilter"
                    class="form-select !py-2 !text-xs !rounded-xl w-auto"
                >
                    <option value="">همه انواع</option>
                    <option value="standard">خط اعتباری</option>
                    <option value="service">خط تجاری</option>
                </select>

                <button
                    v-if="search || typeFilter"
                    type="button"
                    @click="clearFilter"
                    class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-rose-500 hover:bg-rose-500/10"
                    title="پاکسازی فیلتر"
                >
                    ✕
                </button>
            </div>

            <!-- 3. Numbers List Cards -->
            <div v-if="numbers.data.length > 0" class="space-y-3">
                <div
                    v-for="item in numbers.data"
                    :key="item.id"
                    class="glass-card !p-4 hover:border-indigo-500/40 transition-all space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <!-- Header Row: Type Badge, Active Status, Title -->
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border shadow-xs" :class="item.type_badge">
                                {{ item.type_label }}
                            </span>
                            <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                                {{ item.display_title || 'خط بدون عنوان' }}
                            </span>
                        </div>

                        <button
                            type="button"
                            @click="openEditTitleModal(item)"
                            class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline px-2 py-0.5 rounded-md hover:bg-indigo-50 dark:hover:bg-indigo-950/40 shrink-0"
                        >
                            ویرایش نام
                        </button>
                    </div>

                    <!-- Monospace Number Presentation Box -->
                    <div class="p-3 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-xl bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0 border border-indigo-500/25">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div dir="ltr" class="text-left font-mono font-black text-base text-slate-900 dark:text-white tracking-wider truncate select-all">
                                {{ item.formatted_number }}
                            </div>
                        </div>

                        <!-- Copy Button -->
                        <button
                            type="button"
                            @click="copyNumber(item)"
                            class="p-2 rounded-xl glass-pill hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                            title="کپی شماره"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>

                    <!-- Actions Row -->
                    <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-xs">
                        <Link
                            :href="route('messages.index', { phone_number_id: item.id })"
                            class="inline-flex items-center gap-1.5 font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <span>مشاهده پیامک‌ها</span>
                        </Link>

                        <button
                            type="button"
                            @click="openReleaseModal(item)"
                            class="text-[11px] font-bold text-rose-500 dark:text-rose-400 hover:text-rose-600 dark:hover:text-rose-300 hover:underline transition-colors"
                        >
                            آزادسازی خط
                        </button>
                    </div>
                </div>

                <!-- Pagination if multi-page -->
                <div v-if="numbers.links && numbers.links.length > 3" class="flex justify-center gap-1 pt-2">
                    <Link
                        v-for="(link, i) in numbers.links"
                        :key="i"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                        :class="[
                            link.active ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                            !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="glass-card !p-8 text-center space-y-3 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="w-14 h-14 rounded-3xl bg-indigo-500/15 border border-indigo-500/30 text-indigo-600 dark:text-indigo-400 mx-auto flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <rect x="5" y="2" width="14" height="20" rx="2" stroke-width="2"></rect>
                        <line x1="12" y1="18" x2="12.01" y2="18" stroke-width="2"></line>
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">خطی یافت نشد</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        شما در حال حاضر خط فعالی با فیلترهای انتخابی ندارید.
                    </p>
                </div>
                <button
                    v-if="quota.active < quota.max"
                    type="button"
                    @click="openAllocateModal"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>دریافت خط جدید</span>
                </button>
            </div>
        </div>

        <!-- 4. Allocate Modal -->
        <AllocateNumberModal
            :show="isAllocateModalOpen"
            :default-prefix="default_prefix"
            :remaining-quota="Math.max(0, quota.max - quota.active)"
            :max-quota="quota.max"
            @close="closeAllocateModal"
        />

        <!-- 5. Edit Title Modal -->
        <div v-if="isEditTitleModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeEditTitleModal">
            <div class="glass-card !p-5 w-full max-w-md shadow-2xl relative space-y-4 bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200 max-h-[calc(100dvh-2.5rem)] overflow-y-auto">
                <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">
                        ویرایش عنوان خط {{ selectedNumberForTitle?.formatted_number }}
                    </h3>
                    <button type="button" @click="closeEditTitleModal" class="w-8 h-8 rounded-xl flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">✕</button>
                </div>

                <form @submit.prevent="submitTitleUpdate" class="space-y-4">
                    <div>
                        <label class="form-label">عنوان نمایشی:</label>
                        <input
                            type="text"
                            v-model="titleForm.display_title"
                            placeholder="مثال: بخش پشتیبانی یا فروش"
                            maxlength="100"
                            class="form-input !py-2.5 !text-xs"
                            :disabled="titleForm.processing"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-white/10">
                        <button type="button" @click="closeEditTitleModal" :disabled="titleForm.processing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="titleForm.processing"
                            class="glass-btn-primary !w-auto !py-2 !px-5 !text-xs !rounded-xl inline-flex items-center gap-1.5"
                        >
                            <svg v-if="titleForm.processing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ titleForm.processing ? 'در حال ذخیره...' : 'ذخیره عنوان' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 6. Release Confirmation Modal -->
        <div v-if="isReleaseModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 dark:bg-black/70 backdrop-blur-md" @click.self="closeReleaseModal">
            <div class="glass-card !p-5 w-full max-w-md shadow-2xl relative space-y-4 text-center bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-white/10 text-slate-900 dark:text-white rounded-3xl animate-in zoom-in-95 duration-200 max-h-[calc(100dvh-2.5rem)] overflow-y-auto">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 mx-auto flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>

                <div class="space-y-1">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تأیید آزادسازی خط</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        آیا از آزادسازی خط <span dir="ltr" class="font-mono font-bold text-slate-900 dark:text-white">{{ numberToRelease?.formatted_number }}</span> اطمینان دارید؟
                    </p>
                </div>

                <div class="p-3 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-500/20 text-rose-700 dark:text-rose-300 text-[11px] leading-relaxed text-right">
                    <b>ضمانت حریم خصوصی:</b> کلیه پیام‌های پیشین این خط پس از آزادسازی به‌صورت دائمی حذف خواهند شد تا در اختیار مالک بعدی قرار نگیرد.
                </div>

                <div class="flex items-center justify-center gap-2 pt-2">
                    <button type="button" @click="closeReleaseModal" :disabled="releaseProcessing" class="glass-pill !py-2 !px-4 !text-xs text-slate-700 dark:text-slate-300">
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRelease"
                        :disabled="releaseProcessing"
                        class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-lg shadow-rose-600/30 transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                    >
                        <svg v-if="releaseProcessing" class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ releaseProcessing ? 'در حال آزادسازی...' : 'آزادسازی قطعی' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
