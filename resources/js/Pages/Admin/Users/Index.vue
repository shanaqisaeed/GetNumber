<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

interface UserPhoneNumber {
    id: number;
    number: string;
    formatted_number: string;
    type: string;
    type_label: string;
    type_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    display_title: string | null;
    allocated_at: string | null;
}

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    status: string;
    created_at: string;
    phone_numbers_count: number;
    phone_numbers: UserPhoneNumber[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    users: {
        data: UserItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        role?: string;
        status?: string;
        has_numbers?: string;
        sort?: string;
    };
    stats: {
        total: number;
        active: number;
        suspended: number;
        admins: number;
    };
    roles: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

// Search & filter states
const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const status = ref(props.filters.status || '');
const hasNumbers = ref(props.filters.has_numbers || '');
const sort = ref(props.filters.sort || 'newest');

function applyFilters() {
    router.get(
        route('admin.users.index'),
        {
            search: search.value || undefined,
            role: role.value || undefined,
            status: status.value || undefined,
            has_numbers: hasNumbers.value || undefined,
            sort: sort.value !== 'newest' ? sort.value : undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    role.value = '';
    status.value = '';
    hasNumbers.value = '';
    sort.value = 'newest';
    applyFilters();
}

function changeStatus(user: UserItem, newStatus: string) {
    if (!confirm(`آیا از تغییر وضعیت حساب کاربری «${user.username}» به حالت «${formatStatus(newStatus)}» اطمینان دارید؟`)) {
        return;
    }

    router.patch(route('admin.users.status', user.id), {
        status: newStatus,
    }, {
        preserveScroll: true,
    });
}

function formatRole(r: string): string {
    return r === 'admin' ? 'راهبر ارشد' : 'کاربر سازمانی';
}

function formatStatus(s: string): string {
    if (s === 'active') return 'فعال';
    if (s === 'suspended') return 'تعلیق‌شده';
    return 'غیرفعال';
}

// Edit User Modal State
const isEditModalOpen = ref(false);
const editingUser = ref<UserItem | null>(null);

const editForm = useForm({
    name: '',
    username: '',
    role: 'user',
    status: 'active',
    password: '',
});

function openEditModal(user: UserItem) {
    editingUser.value = user;
    editForm.reset();
    editForm.name = user.name;
    editForm.username = user.username;
    editForm.role = user.role;
    editForm.status = user.status;
    editForm.password = '';
    editForm.clearErrors();
    isEditModalOpen.value = true;
}

function closeEditModal() {
    isEditModalOpen.value = false;
    editingUser.value = null;
    editForm.reset();
}

function submitEdit() {
    if (!editingUser.value) return;

    editForm.patch(route('admin.users.update', editingUser.value.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            if (!page.props.flash?.error && Object.keys(editForm.errors).length === 0) {
                closeEditModal();
            }
        },
    });
}

// User Numbers Modal State
const isNumbersModalOpen = ref(false);
const selectedUserForNumbers = ref<UserItem | null>(null);

function openNumbersModal(user: UserItem) {
    selectedUserForNumbers.value = user;
    isNumbersModalOpen.value = true;
}

function closeNumbersModal() {
    isNumbersModalOpen.value = false;
    selectedUserForNumbers.value = null;
}

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isEditModalOpen.value) closeEditModal();
        if (isNumbersModalOpen.value) closeNumbersModal();
    }
};

onMounted(() => window.addEventListener('keydown', handleEscape));
onUnmounted(() => window.removeEventListener('keydown', handleEscape));
</script>

<template>
    <Head title="مدیریت کاربران و دسترسی‌ها" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">مدیریت کاربران و دسترسی‌ها</h1>
        </template>

        <div class="space-y-6">
            <!-- Header Section with Action Button inside Body -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                        مدیریت کاربران و سطوح دسترسی
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        نظارت، ویرایش مشخصات هویتی، تغییر شناسه، بررسی خطوط و مدیریت وضعیت حساب‌های کاربری
                    </p>
                </div>

                <Link
                    :href="route('admin.users.create')"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md shadow-indigo-500/20 transition-all shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>تعریف حساب کاربری جدید</span>
                </Link>
            </div>

            <!-- Stats Metric Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">کل کاربران سامانه</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-slate-900 dark:text-white block text-right">{{ stats.total }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">کاربران فعال</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-emerald-600 dark:text-emerald-400 block text-right">{{ stats.active }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">حساب‌های تعلیق‌شده</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400 block text-right">{{ stats.suspended }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400 font-bold block mb-1">راهبران سامانه</span>
                        <span dir="ltr" class="text-2xl font-black font-mono text-rose-600 dark:text-rose-400 block text-right">{{ stats.admins }}</span>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Comprehensive Search & Filter Bar -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <!-- Search Input (Searches name, username, ID, and phone number) -->
                    <div class="lg:col-span-2 relative">
                        <input
                            type="text"
                            v-model="search"
                            @keyup.enter="applyFilters"
                            placeholder="جستجو در شناسه، نام، نام کاربری یا شماره تلفن تخصیص‌یافته..."
                            class="w-full pr-10 pl-4 py-2.5 text-xs rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 outline-none"
                        />
                        <svg class="w-4 h-4 text-slate-400 absolute right-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    <!-- Role Filter -->
                    <div>
                        <select
                            v-model="role"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">همه نقش‌ها</option>
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <select
                            v-model="status"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>

                    <!-- Line Ownership Filter -->
                    <div>
                        <select
                            v-model="hasNumbers"
                            @change="applyFilters"
                            class="form-select !text-xs !py-2.5 !rounded-xl w-full"
                        >
                            <option value="">وضعیت خطوط</option>
                            <option value="yes">دارای خط سازمانی</option>
                            <option value="no">فاقد خط سازمانی</option>
                        </select>
                    </div>
                </div>

                <!-- Sort and Filter Action Buttons -->
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-slate-800/80">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-400 font-bold">مرتب‌سازی:</span>
                        <select
                            v-model="sort"
                            @change="applyFilters"
                            class="form-select !py-1.5 !px-3 !text-xs !rounded-xl w-auto"
                        >
                            <option value="newest">جدیدترین کاربران</option>
                            <option value="oldest">قدیمی‌ترین کاربران</option>
                            <option value="name">بر اساس نام (الفبا)</option>
                            <option value="username">بر اساس شناسه کاربری</option>
                            <option value="numbers_count">بیشترین تعداد خطوط</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="applyFilters"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-xs font-bold text-white transition-colors cursor-pointer"
                        >
                            اعمال فیلترها
                        </button>

                        <button
                            v-if="search || role || status || hasNumbers || sort !== 'newest'"
                            type="button"
                            @click="clearFilters"
                            class="px-3 py-2 rounded-xl text-xs font-bold text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors cursor-pointer"
                        >
                            حذف فیلترها
                        </button>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/70 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                <th class="py-3.5 px-5 font-bold">شناسه</th>
                                <th class="py-3.5 px-5 font-bold">نام کاربر / سازمان</th>
                                <th class="py-3.5 px-5 font-bold">شناسه کاربری</th>
                                <th class="py-3.5 px-5 font-bold">نقش</th>
                                <th class="py-3.5 px-5 font-bold">وضعیت حساب</th>
                                <th class="py-3.5 px-5 font-bold text-center">خطوط سازمانی</th>
                                <th class="py-3.5 px-5 font-bold">تاریخ ایجاد</th>
                                <th class="py-3.5 px-5 font-bold text-left">عملیات مدیریت</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="py-4 px-5 text-slate-400 font-mono text-[11px]">{{ user.id }}</td>
                                <td class="py-4 px-5 font-bold text-slate-900 dark:text-white">{{ user.name }}</td>
                                <td class="py-4 px-5 font-mono text-slate-700 dark:text-slate-300" dir="ltr">@{{ user.username }}</td>
                                <td class="py-4 px-5">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                        :class="user.role === 'admin' ? 'bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                                    >
                                        {{ formatRole(user.role) }}
                                    </span>
                                </td>
                                <td class="py-4 px-5">
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                        :class="{
                                            'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400': user.status === 'active',
                                            'bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400': user.status === 'suspended',
                                            'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400': user.status === 'inactive'
                                        }"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :class="{
                                                'bg-emerald-500': user.status === 'active',
                                                'bg-amber-500': user.status === 'suspended',
                                                'bg-slate-400': user.status === 'inactive'
                                            }"
                                        ></span>
                                        <span>{{ formatStatus(user.status) }}</span>
                                    </span>
                                </td>
                                <td class="py-4 px-5 text-center">
                                    <button
                                        type="button"
                                        @click="openNumbersModal(user)"
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold transition-all cursor-pointer"
                                        :class="[
                                            user.phone_numbers_count > 0
                                                ? 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60 hover:bg-indigo-100'
                                                : 'bg-slate-100 dark:bg-slate-800/60 text-slate-400 hover:text-slate-600'
                                        ]"
                                        title="مشاهده فهرست شماره‌ها"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                        <span>{{ user.phone_numbers_count }} خط</span>
                                    </button>
                                </td>
                                <td class="py-4 px-5 text-slate-400 font-mono text-[11px]" dir="ltr">
                                    {{ user.created_at }}
                                </td>
                                <td class="py-4 px-5 text-left">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Edit User Button (Requirement 2) -->
                                        <button
                                            type="button"
                                            @click="openEditModal(user)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 transition-colors cursor-pointer"
                                            title="ویرایش کامل مشخصات و شناسه"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            <span>ویرایش</span>
                                        </button>

                                        <!-- Suspend / Activate Button -->
                                        <button
                                            v-if="user.status === 'active'"
                                            type="button"
                                            @click="changeStatus(user, 'suspended')"
                                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 transition-colors cursor-pointer"
                                            title="تعلیق حساب"
                                        >
                                            تعلیق
                                        </button>
                                        <button
                                            v-if="user.status === 'suspended' || user.status === 'inactive'"
                                            type="button"
                                            @click="changeStatus(user, 'active')"
                                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 transition-colors cursor-pointer"
                                            title="فعال‌سازی مجدد"
                                        >
                                            فعال‌سازی
                                        </button>

                                        <!-- User Audit Trail Link -->
                                        <Link
                                            :href="route('admin.audit-logs.index', { user_id: user.id })"
                                            class="p-1 rounded-lg text-slate-400 hover:text-amber-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                            title="مشاهده گزارش ممیزی و امنیت این کاربر"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="users.data.length === 0">
                                <td colspan="8" class="py-12 text-center text-slate-400">
                                    هیچ حساب کاربری مطابق با فیلترهای جستجو یافت نشد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div v-if="users.last_page > 1" class="p-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                    <div>
                        نمایش صفحه {{ users.current_page }} از {{ users.last_page }} (مجموع {{ users.total }} کاربر)
                    </div>
                    <div class="flex items-center gap-1">
                        <Link
                            v-for="(link, i) in users.links"
                            :key="i"
                            :href="link.url || '#'"
                            v-html="link.label"
                            class="px-3 py-1.5 rounded-lg font-medium transition-colors"
                            :class="[
                                link.active ? 'bg-indigo-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800',
                                !link.url ? 'opacity-30 pointer-events-none' : ''
                            ]"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Full User Edit Modal (Requirement 2: Full management, change username, role, status, password) -->
        <div v-if="isEditModalOpen && editingUser" @click.self="closeEditModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150 max-h-[calc(100dvh-2.5rem)] flex flex-col">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>مدیریت و ویرایش مشخصات حساب کاربری</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            شناسه سیستمی: #{{ editingUser.id }}
                        </span>
                    </div>
                    <button @click="closeEditModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitEdit" class="p-6 space-y-4 overflow-y-auto flex-1">
                    <!-- General Error Alert -->
                    <div v-if="$page.props.flash?.error" class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ $page.props.flash.error }}</span>
                    </div>

                    <!-- Name -->
                    <div>
                        <label class="form-label mb-1.5">
                            نام کاربر / عنوان سازمانی <span class="text-rose-500">*</span>
                        </label>
                        <input
                            v-model="editForm.name"
                            type="text"
                            required
                            class="form-input w-full"
                        />
                        <span v-if="editForm.errors.name" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.name }}
                        </span>
                    </div>

                    <!-- Username (Editable by Admin) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label">
                                شناسه کاربری (نام کاربری) <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[10px] text-indigo-600 dark:text-indigo-400 font-bold bg-indigo-50 dark:bg-indigo-950/50 px-2 py-0.5 rounded">قابل ویرایش توسط مدیریت</span>
                        </div>
                        <input
                            v-model="editForm.username"
                            type="text"
                            dir="ltr"
                            required
                            class="form-input w-full font-mono text-left"
                        />
                        <span v-if="editForm.errors.username" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.username }}
                        </span>
                    </div>

                    <!-- Role & Status -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label mb-1.5">
                                نقش کاربری <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-model="editForm.role"
                                class="form-select w-full"
                            >
                                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                            </select>
                            <span v-if="editForm.errors.role" class="text-xs text-rose-500 block mt-1">
                                {{ editForm.errors.role }}
                            </span>
                        </div>

                        <div>
                            <label class="form-label mb-1.5">
                                وضعیت حساب <span class="text-rose-500">*</span>
                            </label>
                            <select
                                v-model="editForm.status"
                                class="form-select w-full"
                            >
                                <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                            <span v-if="editForm.errors.status" class="text-xs text-rose-500 block mt-1">
                                {{ editForm.errors.status }}
                            </span>
                        </div>
                    </div>

                    <!-- New Password (Optional) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="form-label">
                                تغییر کلمه عبور
                            </label>
                            <span class="text-[11px] text-slate-400">اختیاری - در صورت عدم نیاز خالی بگذارید</span>
                        </div>
                        <input
                            v-model="editForm.password"
                            type="password"
                            dir="ltr"
                            placeholder="حداقل ۶ کاراکتر"
                            class="form-input w-full font-mono text-left"
                        />
                        <span v-if="editForm.errors.password" class="text-xs text-rose-500 block mt-1">
                            {{ editForm.errors.password }}
                        </span>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100 dark:border-slate-800">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                        >
                            انصراف
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-md shadow-indigo-600/20 disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="editForm.processing" class="animate-spin -ml-1 mr-1 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ editForm.processing ? 'در حال ثبت...' : 'ذخیره تغییرات حساب' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- User Numbers Modal (Requirement 2: View numbers belonging to user) -->
        <div v-if="isNumbersModalOpen && selectedUserForNumbers" @click.self="closeNumbersModal" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150 max-h-[calc(100dvh-2.5rem)] flex flex-col">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>فهرست خطوط سازمانی تخصیص‌یافته</span>
                        </h3>
                        <span class="text-xs text-slate-400 block mt-0.5">
                            کاربر: {{ selectedUserForNumbers.name }} (@{{ selectedUserForNumbers.username }})
                        </span>
                    </div>
                    <button @click="closeNumbersModal" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto flex-1">
                    <div v-if="selectedUserForNumbers.phone_numbers && selectedUserForNumbers.phone_numbers.length > 0" class="overflow-x-auto">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 font-bold border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="py-3 px-4">شماره خط</th>
                                    <th class="py-3 px-4">نوع</th>
                                    <th class="py-3 px-4">وضعیت</th>
                                    <th class="py-3 px-4">عنوان سرویس</th>
                                    <th class="py-3 px-4">تاریخ تخصیص</th>
                                    <th class="py-3 px-4 text-left">عملیات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <tr v-for="num in selectedUserForNumbers.phone_numbers" :key="num.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                        {{ num.formatted_number || num.number }}
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="num.type_badge">
                                            {{ num.type_label }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="num.status_badge">
                                            {{ num.status_label }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600 dark:text-slate-300">
                                        {{ num.display_title || '—' }}
                                    </td>
                                    <td class="py-3 px-4 font-mono text-slate-400" dir="ltr">
                                        {{ num.allocated_at || '—' }}
                                    </td>
                                    <td class="py-3 px-4 text-left">
                                        <Link
                                            :href="route('admin.numbers.index', { search: num.number })"
                                            class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline"
                                        >
                                            <span>مدیریت خط</span>
                                            <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                            </svg>
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="text-center py-10">
                        <div class="w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mx-auto mb-3">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800 dark:text-white">هیچ خطی به این کاربر تخصیص نیافته است</h4>
                        <p class="text-xs text-slate-400 mt-1">
                            می‌توانید از طریق بخش مدیریت خطوط سازمانی، شماره دلخواه را مستقیماً به این حساب تخصیص دهید.
                        </p>
                    </div>

                    <div class="pt-4 mt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end">
                        <button
                            type="button"
                            @click="closeNumbersModal"
                            class="px-5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors cursor-pointer"
                        >
                            بستن
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
