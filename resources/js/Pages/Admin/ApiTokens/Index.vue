<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserSummary {
    id: number;
    name: string;
    username: string;
}

interface TokenItem {
    id: number;
    user: UserSummary;
    name: string;
    prefix: string;
    preview: string;
    status: 'active' | 'revoked';
    status_label: string;
    status_badge: string;
    is_active: boolean;
    is_expired: boolean;
    abilities: string[];
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
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
    tokens: PaginatedData<TokenItem>;
    filters: {
        search?: string;
        status?: string;
    };
    stats: {
        total: number;
        active: number;
        revoked: number;
    };
}>();

// Filter states
const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');

const handleFilter = () => {
    router.get(
        route('admin.api-tokens.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const resetFilter = () => {
    search.value = '';
    statusFilter.value = '';
    handleFilter();
};

// Revoke Confirmation Modal
const tokenToRevoke = ref<TokenItem | null>(null);
const isRevokeModalOpen = ref(false);
const revokeProcessing = ref(false);

const openRevokeModal = (token: TokenItem) => {
    tokenToRevoke.value = token;
    isRevokeModalOpen.value = true;
};

const closeRevokeModal = () => {
    isRevokeModalOpen.value = false;
    tokenToRevoke.value = null;
};

const confirmRevoke = () => {
    if (!tokenToRevoke.value) return;
    revokeProcessing.value = true;
    router.post(
        route('admin.api-tokens.revoke', { token: tokenToRevoke.value.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                revokeProcessing.value = false;
                closeRevokeModal();
            },
        }
    );
};

// Delete Confirmation Modal
const tokenToDelete = ref<TokenItem | null>(null);
const isDeleteModalOpen = ref(false);
const deleteProcessing = ref(false);

const openDeleteModal = (token: TokenItem) => {
    tokenToDelete.value = token;
    isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
    isDeleteModalOpen.value = false;
    tokenToDelete.value = null;
};

const confirmDelete = () => {
    if (!tokenToDelete.value) return;
    deleteProcessing.value = true;
    router.delete(
        route('admin.api-tokens.destroy', { token: tokenToDelete.value.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                deleteProcessing.value = false;
                closeDeleteModal();
            },
        }
    );
};
</script>

<template>
    <AdminLayout>
        <Head title="مدیریت کلیدهای ارتباطی و وب‌سرویس (API)" />

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm transition-colors">
                <div class="space-y-1">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800/60">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </span>
                        <h1 class="text-xl font-black text-slate-900 dark:text-white">نظارت و مدیریت کلیدهای ارتباطی (API Tokens)</h1>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed pr-10">
                        مشاهده، پایش امنیتی، ردگیری آدرس IP فراخوانی و ابطال کلیدهای وب‌سرویس صادر شده برای کلیه کاربران سامانه.
                    </p>
                </div>

                <div class="flex items-center gap-2 bg-slate-100 dark:bg-slate-800 px-3.5 py-2 rounded-xl text-xs font-mono text-slate-600 dark:text-slate-300">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Rate Limit: 60 req/min</span>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">کل کلیدهای سامانه</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.total }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">کلیدهای فعال</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.active }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center border border-emerald-100 dark:border-emerald-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-semibold text-rose-600 dark:text-rose-400">کلیدهای باطل‌شده</p>
                        <p class="text-2xl font-black text-slate-900 dark:text-white mt-1.5">{{ stats.revoked }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center border border-rose-100 dark:border-rose-900/50">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
                <form @submit.prevent="handleFilter" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <!-- Search Input -->
                    <div class="sm:col-span-6 relative">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="جستجو بر اساس نام کلید، پیشوند توکن، نام یا نام کاربری..."
                            class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        />
                        <div class="absolute left-3 top-2.5 text-slate-400 pointer-events-none">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Status Filter -->
                    <div class="sm:col-span-4">
                        <select
                            v-model="statusFilter"
                            class="w-full px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option value="active">فعال</option>
                            <option value="revoked">باطل‌شده</option>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="sm:col-span-2 flex items-center gap-2">
                        <button
                            type="submit"
                            class="flex-1 py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition shadow-sm"
                        >
                            اعمال فیلتر
                        </button>
                        <button
                            type="button"
                            @click="resetFilter"
                            class="py-2 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-semibold transition"
                            title="بازنشانی"
                        >
                            لغو
                        </button>
                    </div>
                </form>
            </div>

            <!-- Table Card -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h2 class="font-black text-slate-900 dark:text-white text-base">کلیه کلیدهای وب‌سرویس کاربران</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">کلیدهای رمزنگاری‌شده با الگوریتم SHA-256 ذخیره می‌گردند.</p>
                    </div>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                        مجموع: {{ tokens.total }} رکورد
                    </span>
                </div>

                <!-- Empty State -->
                <div v-if="tokens.data.length === 0" class="p-12 text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">هیچ کلید ارتباطی یافت نشد</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                        هیچ کلیدی متناسب با فیلترهای انتخابی در سیستم ثبت نشده است.
                    </p>
                </div>

                <!-- Table Content -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-xs font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="py-3.5 px-4">کاربر مالک کلید</th>
                                <th class="py-3.5 px-4">عنوان سامانه متصل</th>
                                <th class="py-3.5 px-4">پیشوند و شناسه</th>
                                <th class="py-3.5 px-4">وضعیت</th>
                                <th class="py-3.5 px-4">آخرین فراخوانی / IP</th>
                                <th class="py-3.5 px-4">انقضا</th>
                                <th class="py-3.5 px-4">تاریخ صدور</th>
                                <th class="py-3.5 px-4 text-center">عملیات نظارتی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr
                                v-for="token in tokens.data"
                                :key="token.id"
                                class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                            >
                                <td class="py-4 px-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs shrink-0 border border-indigo-100 dark:border-indigo-900/60">
                                            {{ token.user.name.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900 dark:text-white text-xs">{{ token.user.name }}</p>
                                            <p class="text-[11px] text-slate-500 font-mono dir-ltr">@{{ token.user.username }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 px-4 font-bold text-slate-900 dark:text-white text-xs">
                                    {{ token.name }}
                                </td>
                                <td class="py-4 px-4">
                                    <span class="font-mono text-xs text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded dir-ltr inline-block">
                                        {{ token.preview }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold"
                                        :class="token.status_badge"
                                    >
                                        {{ token.status_label }}
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-xs">
                                    <div v-if="token.last_used_at">
                                        <p class="text-slate-700 dark:text-slate-300 font-medium">{{ token.last_used_at }}</p>
                                        <p v-if="token.last_used_ip" class="text-[11px] text-slate-400 font-mono dir-ltr">IP: {{ token.last_used_ip }}</p>
                                    </div>
                                    <span v-else class="text-slate-400 italic">بدون فراخوانی</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400">
                                    <span v-if="token.expires_at">{{ token.expires_at }}</span>
                                    <span v-else class="text-slate-400">دائمی</span>
                                </td>
                                <td class="py-4 px-4 text-xs text-slate-500 dark:text-slate-400 font-mono">
                                    {{ token.created_at }}
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <!-- Revoke -->
                                        <button
                                            v-if="token.is_active"
                                            type="button"
                                            @click="openRevokeModal(token)"
                                            class="p-1.5 text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/60 rounded-lg transition"
                                            title="ابطال اجباری دسترسی"
                                        >
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        </button>

                                        <!-- Delete -->
                                        <button
                                            type="button"
                                            @click="openDeleteModal(token)"
                                            class="p-1.5 text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/60 rounded-lg transition"
                                            title="حذف کلید"
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

                <!-- Pagination Footer -->
                <div v-if="tokens.links && tokens.links.length > 3" class="p-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        نمایش صفحه {{ tokens.current_page }} از {{ tokens.last_page }}
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, index) in tokens.links" :key="index">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg transition"
                                :class="link.active ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-3 py-1.5 text-xs text-slate-400 opacity-50 cursor-not-allowed"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- REVOKE CONFIRMATION MODAL -->
        <div
            v-if="isRevokeModalOpen && tokenToRevoke"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center border border-amber-100 dark:border-amber-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">ابطال دسترسی کلید کاربر</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از ابطال کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToRevoke.name }}»</span> متعلق به کاربر <span class="font-bold text-slate-900 dark:text-white">{{ tokenToRevoke.user.name }}</span> اطمینان دارید؟
                    </p>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="closeRevokeModal"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmRevoke"
                        :disabled="revokeProcessing"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 disabled:opacity-50 transition"
                    >
                        {{ revokeProcessing ? 'در حال ابطال...' : 'تایید ابطال' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- DELETE CONFIRMATION MODAL -->
        <div
            v-if="isDeleteModalOpen && tokenToDelete"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl p-6 space-y-5">
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center border border-rose-100 dark:border-rose-900/60">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">حذف کامل کلید</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 leading-relaxed">
                        آیا از حذف دائم رکورد کلید <span class="font-bold text-slate-900 dark:text-white">«{{ tokenToDelete.name }}»</span> اطمینان دارید؟
                    </p>
                </div>
                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="closeDeleteModal"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        انصراف
                    </button>
                    <button
                        type="button"
                        @click="confirmDelete"
                        :disabled="deleteProcessing"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50 transition"
                    >
                        {{ deleteProcessing ? 'در حال حذف...' : 'حذف قطعی' }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
