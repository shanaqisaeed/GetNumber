<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    status: string;
    created_at: string;
}

defineProps<{
    stats: {
        total_users: number;
        active_users: number;
        suspended_users: number;
        admin_users: number;
    };
    recentUsers: UserItem[];
}>();

function formatRole(role: string): string {
    return role === 'admin' ? 'راهبر ارشد' : 'کاربر سازمانی';
}

function formatStatus(status: string): string {
    if (status === 'active') return 'فعال';
    if (status === 'suspended') return 'تعلیق‌شده';
    return 'غیرفعال';
}
</script>

<template>
    <Head title="پیشخوان مدیریت" />

    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-slate-900 dark:text-white">پیشخوان مدیریت سامانه</h1>
        </template>

        <!-- Metrics Overview Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">کل کاربران سامانه</p>
                    <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.total_users }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
            </div>

            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">کاربران فعال</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.active_users }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">حساب‌های تعلیق‌شده</p>
                    <p class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1">{{ stats.suspended_users }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
            </div>

            <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">راهبران ارشد</p>
                    <p class="text-2xl font-black text-rose-600 dark:text-rose-400 mt-1">{{ stats.admin_users }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Recent Users Table and Management Links -->
        <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-5 border-b border-slate-100 dark:border-slate-800 gap-3">
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">آخرین کاربران ثبت‌شده</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">نمایش فهرست ۵ کاربر اخیر سامانه</p>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.users.create')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>تعریف حساب جدید</span>
                    </Link>

                    <Link
                        :href="route('admin.users.index')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors"
                    >
                        <span>مدیریت کامل کاربران</span>
                        <svg class="w-4 h-4 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto mt-4">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 dark:border-slate-800">
                            <th class="py-3 px-4 font-bold">شناسه کاربری</th>
                            <th class="py-3 px-4 font-bold">عنوان / نام</th>
                            <th class="py-3 px-4 font-bold">نقش</th>
                            <th class="py-3 px-4 font-bold">وضعیت حساب</th>
                            <th class="py-3 px-4 font-bold">تاریخ عضویت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <tr v-for="u in recentUsers" :key="u.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40 transition-colors">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">{{ u.username }}</td>
                            <td class="py-3.5 px-4 font-medium">{{ u.name }}</td>
                            <td class="py-3.5 px-4">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                    :class="u.role === 'admin' ? 'bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                                >
                                    {{ formatRole(u.role) }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                    :class="{
                                        'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400': u.status === 'active',
                                        'bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400': u.status === 'suspended',
                                        'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400': u.status === 'inactive'
                                    }"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full"
                                        :class="{
                                            'bg-emerald-500': u.status === 'active',
                                            'bg-amber-500': u.status === 'suspended',
                                            'bg-slate-400': u.status === 'inactive'
                                        }"
                                    ></span>
                                    <span>{{ formatStatus(u.status) }}</span>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-slate-400 font-mono text-[11px]" dir="ltr">
                                {{ new Date(u.created_at).toLocaleDateString('fa-IR') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
