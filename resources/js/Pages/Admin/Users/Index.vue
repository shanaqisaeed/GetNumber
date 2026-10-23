<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface UserItem {
    id: number;
    name: string;
    username: string;
    role: string;
    status: string;
    created_at: string;
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
    };
    roles: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const status = ref(props.filters.status || '');

function applyFilters() {
    router.get(
        route('admin.users.index'),
        {
            search: search.value || undefined,
            role: role.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    role.value = '';
    status.value = '';
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
</script>

<template>
    <Head title="مدیریت کاربران و دسترسی‌ها" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-black text-slate-900 dark:text-white">مدیریت کاربران و دسترسی‌ها</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">مشاهده، فیلتر، تعریف حساب جدید و مدیریت وضعیت فعالیت کاربران</p>
                </div>
            </div>
        </template>

        <!-- Flash Messages -->
        <div v-if="$page.props.flash?.success" class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-xs font-bold text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $page.props.flash.success }}</span>
        </div>

        <div v-if="$page.props.flash?.error" class="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/50 border border-rose-200 dark:border-rose-800 text-xs font-bold text-rose-700 dark:text-rose-300 flex items-center gap-2">
            <svg class="w-4 h-4 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $page.props.flash.error }}</span>
        </div>

        <!-- Filter & Actions Bar -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 p-5 shadow-sm mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <!-- Search & Filters -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 flex-1">
                    <div>
                        <input
                            type="text"
                            v-model="search"
                            @keyup.enter="applyFilters"
                            placeholder="جستجو در نام یا شناسه کاربری..."
                            class="w-full text-xs font-medium px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white placeholder:text-slate-400 focus:border-indigo-500 outline-none"
                        />
                    </div>

                    <div>
                        <select
                            v-model="role"
                            @change="applyFilters"
                            class="w-full text-xs font-medium px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                        >
                            <option value="">همه نقش‌ها</option>
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>

                    <div>
                        <select
                            v-model="status"
                            @change="applyFilters"
                            class="w-full text-xs font-medium px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 text-slate-900 dark:text-white focus:border-indigo-500 outline-none"
                        >
                            <option value="">همه وضعیت‌ها</option>
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="applyFilters"
                        class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors"
                    >
                        اعمال فیلتر
                    </button>

                    <button
                        v-if="search || role || status"
                        type="button"
                        @click="clearFilters"
                        class="px-3 py-2.5 rounded-xl text-xs font-medium text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                    >
                        حذف فیلترها
                    </button>

                    <Link
                        :href="route('admin.users.create')"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-sm transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>تعریف حساب کاربری</span>
                    </Link>
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
                            <th class="py-3.5 px-5 font-bold">شناسه کاربری (نام کاربری)</th>
                            <th class="py-3.5 px-5 font-bold">عنوان سازمانی / نام</th>
                            <th class="py-3.5 px-5 font-bold">نقش</th>
                            <th class="py-3.5 px-5 font-bold">وضعیت حساب</th>
                            <th class="py-3.5 px-5 font-bold">تاریخ ایجاد</th>
                            <th class="py-3.5 px-5 font-bold text-left">عملیات مدیریت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="py-4 px-5 text-slate-400 font-mono text-[11px]">{{ user.id }}</td>
                            <td class="py-4 px-5 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">{{ user.username }}</td>
                            <td class="py-4 px-5 font-medium">{{ user.name }}</td>
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
                            <td class="py-4 px-5 text-slate-400 font-mono text-[11px]" dir="ltr">
                                {{ new Date(user.created_at).toLocaleDateString('fa-IR') }}
                            </td>
                            <td class="py-4 px-5 text-left">
                                <div class="inline-flex items-center gap-1.5">
                                    <button
                                        v-if="user.status === 'active'"
                                        type="button"
                                        @click="changeStatus(user, 'suspended')"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 hover:bg-amber-100 transition-colors"
                                        title="تعلیق حساب"
                                    >
                                        تعلیق حساب
                                    </button>
                                    <button
                                        v-if="user.status === 'suspended' || user.status === 'inactive'"
                                        type="button"
                                        @click="changeStatus(user, 'active')"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 transition-colors"
                                        title="فعال‌سازی مجدد"
                                    >
                                        فعال‌سازی
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="users.data.length === 0">
                            <td colspan="7" class="py-12 text-center text-slate-400">
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
    </AdminLayout>
</template>
