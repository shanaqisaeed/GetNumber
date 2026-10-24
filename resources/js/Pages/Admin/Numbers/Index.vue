<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

interface UserSummary {
    id: number;
    name: string;
    username: string;
    role?: string;
    status?: string;
}

interface PhoneNumberItem {
    id: number;
    number: string;
    formatted_number: string;
    phone_prefix_id?: number | null;
    prefix?: {
        id: number;
        prefix: string;
        name: string;
    } | null;
    effective_title: string;
    display_title: string | null;
    type: string;
    type_label: string;
    type_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    accepts_incoming_sms?: boolean;
    allocated_at: string | null;
    created_at: string;
    user: UserSummary | null;
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
        status?: string;
        user_id?: string | number;
        length?: string | number;
        sort?: string;
    };
    users: Array<{ id: number; name: string; username: string }>;
    prefixes?: Array<{ id: number; prefix: string; name: string; is_default: boolean }>;
    stats: {
        total: number;
        allocated: number;
        available: number;
        suspended: number;
    };
}>();

// Search & filter
const search = ref(props.filters.search || '');
const typeFilter = ref(props.filters.type || '');
const statusFilter = ref(props.filters.status || '');
const userIdFilter = ref(props.filters.user_id ? String(props.filters.user_id) : '');
const lengthFilter = ref(props.filters.length ? String(props.filters.length) : '');
const sortFilter = ref(props.filters.sort || 'newest');

const handleFilter = () => {
    router.get(
        route('admin.numbers.index'),
        {
            search: search.value || undefined,
            type: typeFilter.value || undefined,
            status: statusFilter.value || undefined,
            user_id: userIdFilter.value || undefined,
            length: lengthFilter.value || undefined,
            sort: sortFilter.value !== 'newest' ? sortFilter.value : undefined,
        },
        { preserveState: true, replace: true }
    );
};

const clearFilter = () => {
    search.value = '';
    typeFilter.value = '';
    statusFilter.value = '';
    userIdFilter.value = '';
    lengthFilter.value = '';
    sortFilter.value = 'newest';
    handleFilter();
};

// Batch Generation Modal State
const isBatchModalOpen = ref(false);
const batchForm = useForm({
    mode: 'batch',
    count: 10,
    digits: 9,
    type: 'user_standard',
});

const openBatchModal = () => {
    batchForm.reset();
    isBatchModalOpen.value = true;
};

const closeBatchModal = () => {
    isBatchModalOpen.value = false;
    batchForm.reset();
};

const submitBatch = () => {
    batchForm.post(route('admin.numbers.store'), {
        onSuccess: () => closeBatchModal(),
    });
};

// Single Creation Modal State
const isSingleModalOpen = ref(false);
const singleForm = useForm({
    mode: 'single',
    number: '',
    phone_prefix_id: '',
    type: 'user_standard',
    display_title: '',
    user_id: '',
    accepts_incoming_sms: true,
});

const openSingleModal = () => {
    singleForm.reset();
    isSingleModalOpen.value = true;
};

const closeSingleModal = () => {
    isSingleModalOpen.value = false;
    singleForm.reset();
};

const submitSingle = () => {
    singleForm.post(route('admin.numbers.store'), {
        onSuccess: () => closeSingleModal(),
    });
};

// Searchable User Picker for Assign Modal
const isAssignModalOpen = ref(false);
const numberToAssign = ref<PhoneNumberItem | null>(null);
const userSearchQuery = ref('');
const userSearchResults = ref<UserSummary[]>([]);
const isSearchingUsers = ref(false);
const selectedUser = ref<UserSummary | null>(null);

const assignForm = useForm({
    user_id: '',
    display_title: '',
});

let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const searchUsers = async (query: string) => {
    if (!query.trim()) {
        userSearchResults.value = [];
        return;
    }

    isSearchingUsers.value = true;
    try {
        const response = await fetch(route('admin.users.search', { q: query }));
        if (response.ok) {
            userSearchResults.value = await response.json();
        }
    } catch {
        userSearchResults.value = [];
    } finally {
        isSearchingUsers.value = false;
    }
};

watch(userSearchQuery, (newVal) => {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        searchUsers(newVal);
    }, 250);
});

const chooseUser = (user: UserSummary) => {
    selectedUser.value = user;
    assignForm.user_id = String(user.id);
    userSearchResults.value = [];
    userSearchQuery.value = '';
};

const clearSelectedUser = () => {
    selectedUser.value = null;
    assignForm.user_id = '';
};

const openAssignModal = (item: PhoneNumberItem) => {
    numberToAssign.value = item;
    assignForm.reset();
    assignForm.display_title = item.display_title || '';
    selectedUser.value = null;
    userSearchQuery.value = '';
    userSearchResults.value = [];
    isAssignModalOpen.value = true;
};

const closeAssignModal = () => {
    isAssignModalOpen.value = false;
    numberToAssign.value = null;
    selectedUser.value = null;
    assignForm.reset();
};

const submitAssign = () => {
    if (!numberToAssign.value) return;

    assignForm.post(route('admin.numbers.assign', numberToAssign.value.id), {
        onSuccess: () => closeAssignModal(),
    });
};

// Edit Modal State
const isEditModalOpen = ref(false);
const numberToEdit = ref<PhoneNumberItem | null>(null);
const editUserSearchQuery = ref('');
const editUserSearchResults = ref<UserSummary[]>([]);
const isSearchingEditUsers = ref(false);
const selectedEditUser = ref<UserSummary | null>(null);

const editForm = useForm({
    display_title: '',
    type: 'user_standard',
    phone_prefix_id: '',
    user_id: '',
    accepts_incoming_sms: true,
});

let editSearchDebounceTimer: ReturnType<typeof setTimeout> | null = null;
const searchEditUsers = async (query: string) => {
    if (!query.trim()) {
        editUserSearchResults.value = [];
        return;
    }
    isSearchingEditUsers.value = true;
    try {
        const response = await fetch(route('admin.users.search', { q: query }));
        if (response.ok) {
            editUserSearchResults.value = await response.json();
        }
    } catch {
        editUserSearchResults.value = [];
    } finally {
        isSearchingEditUsers.value = false;
    }
};

watch(editUserSearchQuery, (newVal) => {
    if (editSearchDebounceTimer) clearTimeout(editSearchDebounceTimer);
    editSearchDebounceTimer = setTimeout(() => {
        searchEditUsers(newVal);
    }, 250);
});

const chooseEditUser = (user: UserSummary) => {
    selectedEditUser.value = user;
    editForm.user_id = String(user.id);
    editUserSearchResults.value = [];
    editUserSearchQuery.value = '';
};

const clearSelectedEditUser = () => {
    selectedEditUser.value = null;
    editForm.user_id = '';
};

const openEditModal = (item: PhoneNumberItem) => {
    numberToEdit.value = item;
    editForm.clearErrors();
    editForm.display_title = item.display_title || '';
    editForm.type = item.type;
    editForm.phone_prefix_id = item.phone_prefix_id ? String(item.phone_prefix_id) : '';
    editForm.user_id = item.user ? String(item.user.id) : '';
    selectedEditUser.value = item.user ? { id: item.user.id, name: item.user.name, username: item.user.username } : null;
    editForm.accepts_incoming_sms = item.accepts_incoming_sms ?? true;
    editUserSearchQuery.value = '';
    editUserSearchResults.value = [];
    isEditModalOpen.value = true;
};

const closeEditModal = () => {
    isEditModalOpen.value = false;
    numberToEdit.value = null;
    selectedEditUser.value = null;
    editForm.reset();
};

const submitEdit = () => {
    if (!numberToEdit.value) return;
    editForm.patch(route('admin.numbers.update', numberToEdit.value.id), {
        onSuccess: () => closeEditModal(),
    });
};

// Delete Action
const handleDelete = (item: PhoneNumberItem) => {
    if (confirm(`آیا از حذف دائم خط سازمانی ${item.formatted_number} اطمینان دارید؟ این اقدام غیرقابل بازگشت است.`)) {
        router.delete(route('admin.numbers.destroy', item.id));
    }
};

// Release Action
const handleRelease = (item: PhoneNumberItem) => {
    if (confirm(`آیا از آزادسازی خط سازمانی ${item.formatted_number} و بازگردانی آن به استخر شماره‌ها اطمینان دارید؟`)) {
        router.post(route('admin.numbers.release', item.id));
    }
};

// Status Change Modal State
const isStatusModalOpen = ref(false);
const numberForStatus = ref<PhoneNumberItem | null>(null);
const statusForm = useForm({
    status: '',
    reason: '',
});

const openStatusModal = (item: PhoneNumberItem) => {
    numberForStatus.value = item;
    statusForm.status = item.status;
    statusForm.reason = '';
    isStatusModalOpen.value = true;
};

const closeStatusModal = () => {
    isStatusModalOpen.value = false;
    numberForStatus.value = null;
    statusForm.reset();
};

const submitStatus = () => {
    if (!numberForStatus.value) return;

    statusForm.patch(route('admin.numbers.status', numberForStatus.value.id), {
        onSuccess: () => closeStatusModal(),
    });
};

// Copy state
const copiedId = ref<number | null>(null);
const copyNumber = (item: PhoneNumberItem) => {
    navigator.clipboard.writeText(item.formatted_number);
    copiedId.value = item.id;
    setTimeout(() => {
        copiedId.value = null;
    }, 2000);
};

const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isBatchModalOpen.value) closeBatchModal();
        if (isSingleModalOpen.value) closeSingleModal();
        if (isAssignModalOpen.value) closeAssignModal();
        if (isEditModalOpen.value) closeEditModal();
        if (isStatusModalOpen.value) closeStatusModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Head title="مدیریت خطوط سازمانی" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">مدیریت خطوط سازمانی</h1>
        </template>

        <!-- Page Header & Action Bar (Buttons relocated from header to page body) -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm mb-6">
            <div>
                <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                    مدیریت خطوط سازمانی و استخر شماره‌ها
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    نظارت بر خطوط ارتباطی، تخصیص شماره‌ها به کاربران و تنظیم وضعیت
                </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button
                    @click="openBatchModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold text-xs hover:bg-slate-800 dark:hover:bg-slate-100 transition-all cursor-pointer shadow-sm"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <span>تولید دسته‌ای شماره آزاد</span>
                </button>

                <button
                    @click="openSingleModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-500/20 transition-all cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ثبت شماره جدید</span>
                </button>
            </div>
        </div>



        <!-- System Stats Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                <span class="text-xs text-slate-400 font-bold block mb-1">مجموع کل خطوط سامانه</span>
                <span dir="ltr" class="text-2xl font-black font-mono text-slate-900 dark:text-white block text-right">{{ stats.total }}</span>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                <span class="text-xs text-slate-400 font-bold block mb-1">خطوط تخصیص‌یافته به کاربران</span>
                <span dir="ltr" class="text-2xl font-black font-mono text-indigo-600 dark:text-indigo-400 block text-right">{{ stats.allocated }}</span>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                <span class="text-xs text-slate-400 font-bold block mb-1">خطوط آزاد و آماده تخصیص</span>
                <span dir="ltr" class="text-2xl font-black font-mono text-emerald-600 dark:text-emerald-400 block text-right">{{ stats.available }}</span>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm">
                <span class="text-xs text-slate-400 font-bold block mb-1">خطوط تعلیق‌شده</span>
                <span dir="ltr" class="text-2xl font-black font-mono text-rose-600 dark:text-rose-400 block text-right">{{ stats.suspended }}</span>
            </div>
        </div>

        <!-- Filter & Search Bar (Comprehensive & Future-Proof) -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm mb-6 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                <!-- Search Input -->
                <div class="lg:col-span-2 relative">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        placeholder="جستجو در شماره، شناسه، عنوان یا نام کاربر..."
                        class="w-full pr-10 pl-4 py-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 placeholder:text-slate-400"
                    />
                    <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <!-- Type Filter -->
                <div>
                    <select
                        v-model="typeFilter"
                        @change="handleFilter"
                        class="form-select w-full py-2.5 px-3 text-xs rounded-xl"
                    >
                        <option value="">همه انواع خطوط</option>
                        <option value="user_standard">اعتباری (۹ رقمی)</option>
                        <option value="user_service">تجاری (۴ تا ۸ رقمی)</option>
                        <option value="platform_service">تجاری سامانه</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <select
                        v-model="statusFilter"
                        @change="handleFilter"
                        class="form-select w-full py-2.5 px-3 text-xs rounded-xl"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option value="available">آزاد در استخر</option>
                        <option value="allocated">تخصیص‌یافته به کاربر</option>
                        <option value="reserved">رزروشده</option>
                        <option value="suspended">تعلیق‌شده</option>
                        <option value="inactive">غیرفعال</option>
                    </select>
                </div>

                <!-- Length Filter -->
                <div>
                    <select
                        v-model="lengthFilter"
                        @change="handleFilter"
                        class="form-select w-full py-2.5 px-3 text-xs rounded-xl"
                    >
                        <option value="">همه ارقام (طول)</option>
                        <option value="4">۴ رقمی</option>
                        <option value="5">۵ رقمی</option>
                        <option value="6">۶ رقمی</option>
                        <option value="7">۷ رقمی</option>
                        <option value="8">۸ رقمی</option>
                        <option value="9">۹ رقمی</option>
                    </select>
                </div>

                <!-- User Filter -->
                <div>
                    <select
                        v-model="userIdFilter"
                        @change="handleFilter"
                        class="form-select w-full py-2.5 px-3 text-xs rounded-xl"
                    >
                        <option value="">همه کاربران</option>
                        <option v-for="u in users" :key="u.id" :value="String(u.id)">
                            {{ u.name }} (@{{ u.username }})
                        </option>
                    </select>
                </div>
            </div>

            <!-- Sort and Action Row -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-800/80">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400 font-bold">مرتب‌سازی:</span>
                    <select
                        v-model="sortFilter"
                        @change="handleFilter"
                        class="form-select py-1.5 px-3 text-xs rounded-xl"
                    >
                        <option value="newest">جدیدترین شماره‌ها</option>
                        <option value="oldest">قدیمی‌ترین شماره‌ها</option>
                        <option value="number_asc">ترتیب ارقام (صعودی)</option>
                        <option value="number_desc">ترتیب ارقام (نزولی)</option>
                        <option value="allocated_at">تاریخ آخرین تخصیص</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        @click="handleFilter"
                        class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition-colors cursor-pointer"
                    >
                        اعمال فیلترها
                    </button>
                    <button
                        v-if="search || typeFilter || statusFilter || userIdFilter || lengthFilter || sortFilter !== 'newest'"
                        @click="clearFilter"
                        class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-rose-950/40 text-slate-600 dark:text-slate-300 hover:text-rose-600 text-xs font-bold transition-colors cursor-pointer"
                    >
                        حذف فیلترها
                    </button>
                </div>
            </div>
        </div>

        <!-- Numbers Data Table -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50/80 dark:bg-slate-800/50 border-b border-slate-200/80 dark:border-slate-800/80 text-slate-500 dark:text-slate-400 font-bold">
                        <tr>
                            <th class="py-3.5 px-5">شماره سازمانی</th>
                            <th class="py-3.5 px-5">عنوان نمایشی خط</th>
                            <th class="py-3.5 px-5">نوع خط</th>
                            <th class="py-3.5 px-5">کاربر تخصیص‌یافته</th>
                            <th class="py-3.5 px-5">دریافت پیامک</th>
                            <th class="py-3.5 px-5">وضعیت</th>
                            <th class="py-3.5 px-5">تاریخ تخصیص</th>
                            <th class="py-3.5 px-5 text-left">عملیات مدیریت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr
                            v-for="item in numbers.data"
                            :key="item.id"
                            class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                        >
                            <!-- Strictly LTR Phone Number in Admin Table -->
                            <td class="py-3.5 px-5">
                                <div class="flex items-center gap-2">
                                    <span dir="ltr" class="font-mono font-bold text-sm text-slate-900 dark:text-white inline-block text-left select-all">
                                        {{ item.formatted_number }}
                                    </span>
                                    <button
                                        @click="copyNumber(item)"
                                        class="text-slate-400 hover:text-indigo-600 p-1 transition-colors cursor-pointer"
                                        :title="copiedId === item.id ? 'در حافظه کپی شد' : 'کپی شماره'"
                                    >
                                        <svg v-if="copiedId === item.id" class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                    </button>
                                </div>
                            </td>

                            <!-- Line Display Title -->
                            <td class="py-3.5 px-5">
                                <div v-if="item.display_title" class="font-bold text-slate-900 dark:text-white max-w-xs truncate" :title="item.display_title">
                                    {{ item.display_title }}
                                </div>
                                <span v-else class="text-slate-400 dark:text-slate-500 italic text-[11px]">بدون عنوان</span>
                            </td>

                            <td class="py-3.5 px-5">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border" :class="item.type_badge">
                                    {{ item.type_label }}
                                </span>
                            </td>

                            <td class="py-3.5 px-5">
                                <div v-if="item.user" class="flex items-center gap-1.5">
                                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-500 font-bold">#{{ item.user.id }}</span>
                                    <span class="font-bold text-slate-900 dark:text-white">{{ item.user.name }}</span>
                                    <span dir="ltr" class="text-[10px] font-mono text-slate-400">@{{ item.user.username }}</span>
                                </div>
                                <span v-else class="text-slate-400 italic">بدون انتساب (آزاد)</span>
                            </td>

                            <!-- Incoming SMS Reception Capability -->
                            <td class="py-3.5 px-5">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border"
                                    :class="item.accepts_incoming_sms
                                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800'
                                        : 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800'"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full" :class="item.accepts_incoming_sms ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                    {{ item.accepts_incoming_sms ? 'دریافت فعال (دوطرفه)' : 'فقط ارسال (یک‌طرفه)' }}
                                </span>
                            </td>

                            <td class="py-3.5 px-5">
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full border" :class="item.status_badge">
                                    {{ item.status_label }}
                                </span>
                            </td>

                            <td class="py-3.5 px-5 text-slate-500 font-mono text-[11px]">
                                <span dir="ltr">{{ item.allocated_at || '—' }}</span>
                            </td>

                            <td class="py-3.5 px-5 text-left">
                                <div class="inline-flex items-center gap-1">
                                    <!-- Edit line button -->
                                    <button
                                        @click="openEditModal(item)"
                                        class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 text-[11px] font-bold hover:bg-indigo-100 transition-colors cursor-pointer"
                                        title="ویرایش مشخصات خط"
                                    >
                                        ویرایش
                                    </button>

                                    <!-- Assign button (if available) -->
                                    <button
                                        v-if="item.status === 'available'"
                                        @click="openAssignModal(item)"
                                        class="px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold hover:bg-emerald-100 transition-colors cursor-pointer"
                                    >
                                        تخصیص
                                    </button>

                                    <!-- Release button (if allocated) -->
                                    <button
                                        v-if="item.status === 'allocated'"
                                        @click="handleRelease(item)"
                                        class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-[11px] font-bold hover:bg-rose-50 hover:text-rose-600 transition-colors cursor-pointer"
                                    >
                                        آزادسازی
                                    </button>

                                    <!-- Status button -->
                                    <button
                                        @click="openStatusModal(item)"
                                        class="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 text-[11px] font-bold hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                                    >
                                        وضعیت
                                    </button>

                                    <!-- Delete button -->
                                    <button
                                        @click="handleDelete(item)"
                                        class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/50 transition-colors cursor-pointer"
                                        title="حذف کامل خط"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="numbers.data.length === 0">
                            <td colspan="8" class="py-10 text-center text-slate-400">
                                هیچ شماره‌ای با معیارهای انتخابی یافت نگردید.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Batch Generation Modal (With manual digits length input) -->
        <div v-if="isBatchModalOpen" @click.self="closeBatchModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5 shrink-0">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تولید دسته‌ای شماره‌های آزاد</h3>
                    <button @click="closeBatchModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitBatch" class="space-y-4 overflow-y-auto flex-1">
                    <!-- Count Input -->
                    <div>
                        <label class="form-label mb-1.5">
                            تعداد خطوط جهت تولید (حداکثر ۱۰۰ شماره در هر نوبت)
                        </label>
                        <input
                            type="number"
                            v-model="batchForm.count"
                            min="1"
                            max="100"
                            class="form-input w-full font-mono"
                            required
                        />
                        <p v-if="batchForm.errors.count" class="text-rose-500 text-[11px] mt-1">{{ batchForm.errors.count }}</p>
                    </div>

                    <!-- Digits Length Input (As requested: length entered manually) -->
                    <div>
                        <label class="form-label mb-1.5">
                            طول ارقام شماره (تعداد ارقام عددی، مثلاً ۹ یا ۶ یا ۴)
                        </label>
                        <input
                            type="number"
                            v-model="batchForm.digits"
                            min="4"
                            max="11"
                            placeholder="مثال: ۹"
                            class="form-input w-full font-mono"
                            required
                        />
                        <p class="text-[11px] text-slate-400 mt-1">
                            شماره‌های تولیدشده دقیقاً با این تعداد رقم و با حفظ قطعی صفرهای ابتدایی ایجاد می‌گردند.
                        </p>
                        <p v-if="batchForm.errors.digits" class="text-rose-500 text-[11px] mt-1">{{ batchForm.errors.digits }}</p>
                    </div>

                    <!-- Type Selection -->
                    <div>
                        <label class="form-label mb-1.5">
                            نوع خطوط سازمانی
                        </label>
                        <select
                            v-model="batchForm.type"
                            class="form-select w-full"
                        >
                            <option value="user_standard">شماره‌های اعتباری</option>
                            <option value="user_service">خطوط تجاری</option>
                            <option value="platform_service">خطوط تجاری سامانه</option>
                        </select>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeBatchModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="batchForm.processing"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="batchForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ batchForm.processing ? 'در حال تولید...' : 'تولید و ثبت در استخر' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Single Number Creation Modal -->
        <div v-if="isSingleModalOpen" @click.self="closeSingleModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5 shrink-0">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">ثبت دستی شماره جدید</h3>
                    <button @click="closeSingleModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitSingle" class="space-y-4 overflow-y-auto flex-1">
                    <div>
                        <label class="form-label mb-1.5">
                            شماره (۴ تا ۱۱ رقم عددی)
                        </label>
                        <input
                            type="text"
                            v-model="singleForm.number"
                            placeholder="مثال: 000123456 یا 1000"
                            dir="ltr"
                            class="form-input w-full font-mono text-left"
                            required
                        />
                        <p v-if="singleForm.errors.number" class="text-rose-500 text-[11px] mt-1">{{ singleForm.errors.number }}</p>
                    </div>

                    <div>
                        <label class="form-label mb-1.5">
                            نوع خط
                        </label>
                        <select
                            v-model="singleForm.type"
                            class="form-select w-full"
                        >
                            <option value="user_standard">شماره اعتباری</option>
                            <option value="user_service">خط تجاری</option>
                            <option value="platform_service">خط تجاری سامانه</option>
                        </select>
                    </div>

                    <div v-if="prefixes && prefixes.length > 0">
                        <label class="form-label mb-1.5">
                            پیش‌شماره متصل
                        </label>
                        <select
                            v-model="singleForm.phone_prefix_id"
                            class="form-select w-full"
                        >
                            <option value="">پیش‌فرض سامانه</option>
                            <option v-for="p in prefixes" :key="p.id" :value="String(p.id)">
                                {{ p.prefix }} ({{ p.name }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label mb-1.5">
                            عنوان نمایشی سرویس (اختیاری)
                        </label>
                        <input
                            type="text"
                            v-model="singleForm.display_title"
                            placeholder="مثال: پشتیبانی سامانه، مرکز اعلانات، بانکداری"
                            class="form-input w-full"
                        />
                        <p v-if="singleForm.errors.display_title" class="text-rose-500 text-[11px] mt-1">{{ singleForm.errors.display_title }}</p>
                    </div>

                    <div class="flex items-center gap-3 p-3 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800/60">
                        <input
                            id="accepts_incoming_sms"
                            type="checkbox"
                            v-model="singleForm.accepts_incoming_sms"
                            class="w-4 h-4 rounded-md text-indigo-600 focus:ring-indigo-500 border-slate-300 dark:border-slate-700"
                        />
                        <label for="accepts_incoming_sms" class="text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                            امکان دریافت پیامک ورودی فعال باشد (در صورت غیرفعال بودن، خط صرفاً یک‌طرفه/اطلاع‌رسانی است)
                        </label>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeSingleModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="singleForm.processing"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="singleForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ singleForm.processing ? 'در حال ثبت...' : 'ثبت خط در سامانه' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Searchable Assign Modal (Scalable for 1000+ users) -->
        <div v-if="isAssignModalOpen" @click.self="closeAssignModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5 shrink-0">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تخصیص خط سازمانی به کاربر</h3>
                    <button @click="closeAssignModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitAssign" class="space-y-4 overflow-y-auto flex-1">
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800/60 text-xs flex items-center justify-between">
                        <span>شماره خط انتخابی:</span>
                        <span dir="ltr" class="font-mono font-bold text-slate-900 dark:text-white">{{ numberToAssign?.formatted_number }}</span>
                    </div>

                    <div>
                        <label class="form-label mb-1.5">
                            عنوان یا نام نمایشی خط (اختیاری)
                        </label>
                        <input
                            type="text"
                            v-model="assignForm.display_title"
                            placeholder="مثال: واحد فروش، پشتیبانی، امور مالی..."
                            class="form-input w-full"
                        />
                        <p class="text-[11px] text-slate-400 mt-1">
                            در صورت خالی گذاشتن، عنوان قبلی خط حفظ خواهد شد.
                        </p>
                    </div>

                    <!-- Searchable User Selection -->
                    <div>
                        <label class="form-label mb-1.5">
                            جستجو و انتخاب کاربر مقصد (شناسه ID، نام یا نام کاربری)
                        </label>

                        <!-- Selected User Display Card -->
                        <div v-if="selectedUser" class="p-3.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-800 flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2.5">
                                <span class="px-2 py-0.5 rounded-md bg-indigo-600 text-white font-mono text-xs font-bold">
                                    #{{ selectedUser.id }}
                                </span>
                                <div>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white block">{{ selectedUser.name }}</span>
                                    <span dir="ltr" class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 block text-right">@{{ selectedUser.username }}</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="clearSelectedUser"
                                class="text-xs text-rose-500 hover:text-rose-700 font-bold px-2 py-1 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                            >
                                تغییر کاربر
                            </button>
                        </div>

                        <!-- Search Input -->
                        <div v-else class="relative">
                            <input
                                type="text"
                                v-model="userSearchQuery"
                                placeholder="شناسه (مثال: 5) یا نام کاربری..."
                                class="form-input w-full pr-10 pl-4 py-2.5"
                            />
                            <div class="absolute right-3 top-3 text-slate-400">
                                <svg v-if="!isSearchingUsers" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <svg v-else class="w-4 h-4 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>

                            <!-- Search Results Dropdown -->
                            <div
                                v-if="userSearchResults.length > 0"
                                class="absolute z-20 top-full mt-1.5 w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-xl max-h-56 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <div
                                    v-for="u in userSearchResults"
                                    :key="u.id"
                                    @click="chooseUser(u)"
                                    class="p-3 hover:bg-indigo-50/60 dark:hover:bg-indigo-950/40 cursor-pointer transition-colors flex items-center justify-between"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[10px] font-mono font-bold text-slate-600 dark:text-slate-300">
                                            #{{ u.id }}
                                        </span>
                                        <span class="text-xs font-bold text-slate-900 dark:text-white">{{ u.name }}</span>
                                    </div>
                                    <span dir="ltr" class="text-[11px] font-mono text-slate-400">@{{ u.username }}</span>
                                </div>
                            </div>

                            <p v-if="userSearchQuery && !isSearchingUsers && userSearchResults.length === 0" class="text-[11px] text-slate-400 mt-1">
                                کاربری با مشخصات فوق یافت نشد.
                            </p>
                        </div>

                        <p v-if="assignForm.errors.user_id" class="text-rose-500 text-[11px] mt-1">{{ assignForm.errors.user_id }}</p>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeAssignModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="assignForm.processing || !assignForm.user_id"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="assignForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ assignForm.processing ? 'در حال ثبت تخصیص...' : 'تأیید و تخصیص خط' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Status Change Modal -->
        <div v-if="isStatusModalOpen" @click.self="closeStatusModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5 shrink-0">
                    <h3 class="font-black text-sm text-slate-900 dark:text-white">تغییر وضعیت خط سازمانی</h3>
                    <button @click="closeStatusModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitStatus" class="space-y-4 overflow-y-auto flex-1">
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800/60 text-xs flex items-center justify-between">
                        <span>شماره خط:</span>
                        <span dir="ltr" class="font-mono font-bold text-slate-900 dark:text-white">{{ numberForStatus?.formatted_number }}</span>
                    </div>

                    <div>
                        <label class="form-label mb-1.5">
                            وضعیت جدید
                        </label>
                        <select
                            v-model="statusForm.status"
                            class="form-select w-full"
                        >
                            <option value="available">آزاد برای تخصیص</option>
                            <option value="allocated">تخصیص‌یافته</option>
                            <option value="reserved">رزروشده</option>
                            <option value="suspended">تعلیق‌شده</option>
                            <option value="inactive">غیرفعال</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label mb-1.5">
                            علت تغییر وضعیت (ثبت در گزارش‌های نظارتی)
                        </label>
                        <input
                            type="text"
                            v-model="statusForm.reason"
                            placeholder="مثال: دستور اداری، تعلیق موقت، یا بررسی فنی"
                            class="form-input w-full"
                        />
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeStatusModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="statusForm.processing"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="statusForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ statusForm.processing ? 'در حال ثبت...' : 'ذخیره تغییرات وضعیت' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Line Modal -->
        <div v-if="isEditModalOpen" @click.self="closeEditModal" class="fixed inset-0 z-50 overflow-y-auto flex items-start sm:items-center justify-center p-3 sm:p-4 bg-slate-950/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 w-full max-w-md shadow-2xl max-h-[calc(100dvh-2.5rem)] flex flex-col my-auto responsive-modal-scroll-card">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800 mb-5 shrink-0">
                    <div>
                        <h3 class="font-black text-sm text-slate-900 dark:text-white">ویرایش مشخصات خط سازمانی</h3>
                        <p dir="ltr" class="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400 mt-0.5 text-right">{{ numberToEdit?.formatted_number }}</p>
                    </div>
                    <button @click="closeEditModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-sm cursor-pointer">✕</button>
                </div>

                <form @submit.prevent="submitEdit" class="space-y-4 overflow-y-auto flex-1">
                    <!-- Display Title -->
                    <div>
                        <label class="form-label mb-1.5">
                            عنوان یا نام نمایشی خط
                        </label>
                        <input
                            type="text"
                            v-model="editForm.display_title"
                            placeholder="مثال: پشتیبانی فنی، درگاه مالی، واحد فروش"
                            class="form-input w-full"
                        />
                        <p v-if="editForm.errors.display_title" class="text-rose-500 text-[11px] mt-1">{{ editForm.errors.display_title }}</p>
                    </div>

                    <!-- Line Type -->
                    <div>
                        <label class="form-label mb-1.5">
                            نوع خط سازمانی
                        </label>
                        <select
                            v-model="editForm.type"
                            class="form-select w-full"
                        >
                            <option value="user_standard">شماره اعتباری</option>
                            <option value="user_service">خط تجاری</option>
                            <option value="platform_service">خط تجاری سامانه</option>
                        </select>
                        <p v-if="editForm.errors.type" class="text-rose-500 text-[11px] mt-1">{{ editForm.errors.type }}</p>
                    </div>

                    <!-- Prefix Selection -->
                    <div v-if="prefixes && prefixes.length > 0">
                        <label class="form-label mb-1.5">
                            پیش‌شماره متصل
                        </label>
                        <select
                            v-model="editForm.phone_prefix_id"
                            class="form-select w-full"
                        >
                            <option value="">پیش‌فرض سامانه</option>
                            <option v-for="p in prefixes" :key="p.id" :value="String(p.id)">
                                {{ p.prefix }} ({{ p.name }})
                            </option>
                        </select>
                    </div>

                    <!-- User Ownership Assignment -->
                    <div>
                        <label class="form-label mb-1.5">
                            کاربر مالک خط (در صورت عدم انتساب، خط آزاد خواهد شد)
                        </label>
                        <div v-if="selectedEditUser" class="p-3.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-800 flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2.5">
                                <span class="px-2 py-0.5 rounded-md bg-indigo-600 text-white font-mono text-xs font-bold">
                                    #{{ selectedEditUser.id }}
                                </span>
                                <div>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white block">{{ selectedEditUser.name }}</span>
                                    <span dir="ltr" class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 block text-right">@{{ selectedEditUser.username }}</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="clearSelectedEditUser"
                                class="text-xs text-rose-500 hover:text-rose-700 font-bold px-2 py-1 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                            >
                                حذف مالک (آزادسازی)
                            </button>
                        </div>

                        <div v-else class="relative">
                            <input
                                type="text"
                                v-model="editUserSearchQuery"
                                placeholder="جستجو کاربر با نام یا نام کاربری جهت انتساب..."
                                class="form-input w-full pr-10 pl-4 py-2"
                            />
                            <div class="absolute right-3 top-2.5 text-slate-400">
                                <svg v-if="!isSearchingEditUsers" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <svg v-else class="w-4 h-4 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>

                            <div
                                v-if="editUserSearchResults.length > 0"
                                class="absolute z-20 top-full mt-1.5 w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-xl max-h-48 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800"
                            >
                                <div
                                    v-for="u in editUserSearchResults"
                                    :key="u.id"
                                    @click="chooseEditUser(u)"
                                    class="p-2.5 hover:bg-indigo-50/60 dark:hover:bg-indigo-950/40 cursor-pointer transition-colors flex items-center justify-between"
                                >
                                    <div class="flex items-center gap-2">
                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[10px] font-mono font-bold text-slate-600 dark:text-slate-300">
                                            #{{ u.id }}
                                        </span>
                                        <span class="text-xs font-bold text-slate-900 dark:text-white">{{ u.name }}</span>
                                    </div>
                                    <span dir="ltr" class="text-[11px] font-mono text-slate-400">@{{ u.username }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Accepts incoming SMS Toggle -->
                    <div class="flex items-center gap-3 p-3 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/60 dark:border-slate-800/60">
                        <input
                            id="edit_accepts_incoming_sms"
                            type="checkbox"
                            v-model="editForm.accepts_incoming_sms"
                            class="w-4 h-4 rounded-md text-indigo-600 focus:ring-indigo-500 border-slate-300 dark:border-slate-700"
                        />
                        <label for="edit_accepts_incoming_sms" class="text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                            امکان دریافت پیامک ورودی فعال باشد (دو طرفه)
                        </label>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-500/20 transition-all disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="editForm.processing" class="animate-spin -ml-1 mr-1 h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ editForm.processing ? 'در حال ذخیره...' : 'ذخیره تغییرات' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
