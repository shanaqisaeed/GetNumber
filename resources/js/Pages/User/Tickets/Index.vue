<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TicketItem {
    id: number;
    ticket_number: string;
    subject: string;
    department: string;
    department_label: string;
    department_badge: string;
    priority: string;
    priority_label: string;
    priority_badge: string;
    status: string;
    status_label: string;
    status_badge: string;
    phone_number: {
        id: number;
        number: string;
        formatted_number: string;
        display_title: string | null;
    } | null;
    assigned_agent: {
        name: string;
    } | null;
    last_reply_at: string | null;
    last_reply_human: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    tickets: {
        data: TicketItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        status?: string;
        department?: string;
        search?: string;
    };
    stats: {
        total: number;
        open: number;
        answered: number;
        in_progress: number;
        closed: number;
    };
    departments: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const department = ref(props.filters.department || '');

function applyFilters() {
    router.get(
        route('tickets.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            department: department.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    status.value = '';
    department.value = '';
    applyFilters();
}
</script>

<template>
    <Head title="پشتیبانی و تیکت‌ها" />

    <UserLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h1 class="text-base font-bold text-slate-900 dark:text-white">پشتیبانی و تیکت‌های سازمانی</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">ارتباط مستقیم با کارشناسان فنی و پیگیری مسائل سرشماره‌ها و وب‌سرویس</p>
                </div>
                <Link
                    :href="route('tickets.create')"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-md shadow-indigo-600/20 transition-all cursor-pointer"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ثبت تیکت جدید</span>
                </Link>
            </div>
        </template>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">کل تیکت‌ها</p>
                    <p class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ stats.total }}</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">در انتظار کارشناس</p>
                    <p class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1">{{ stats.open }}</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">پاسخ داده‌شده</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.answered }}</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">بسته‌شده</p>
                    <p class="text-2xl font-black text-slate-500 dark:text-slate-400 mt-1">{{ stats.closed }}</p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input
                    v-model="search"
                    @keydown.enter="applyFilters"
                    type="text"
                    placeholder="جستجو در شناسه یا موضوع تیکت..."
                    class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white py-2 px-3"
                />

                <select
                    v-model="status"
                    @change="applyFilters"
                    class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white py-2"
                >
                    <option value="">همه وضعیت‌ها</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>

                <div class="flex gap-2">
                    <select
                        v-model="department"
                        @change="applyFilters"
                        class="w-full text-xs rounded-xl bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white py-2"
                    >
                        <option value="">همه دپارتمان‌ها</option>
                        <option v-for="d in departments" :key="d.value" :value="d.value">{{ d.label }}</option>
                    </select>

                    <button
                        v-if="search || status || department"
                        type="button"
                        @click="clearFilters"
                        class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-rose-500 text-xs font-bold shrink-0"
                    >
                        پاکسازی
                    </button>
                </div>
            </div>
        </div>

        <!-- Tickets List -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                            <th class="py-3 px-4 font-bold">شناسه</th>
                            <th class="py-3 px-4 font-bold">موضوع تیکت</th>
                            <th class="py-3 px-4 font-bold">دپارتمان</th>
                            <th class="py-3 px-4 font-bold">اولویت</th>
                            <th class="py-3 px-4 font-bold">وضعیت</th>
                            <th class="py-3 px-4 font-bold">آخرین به‌روزرسانی</th>
                            <th class="py-3 px-4 font-bold text-center">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                        <tr
                            v-for="ticket in tickets.data"
                            :key="ticket.id"
                            class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition-colors"
                        >
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-900 dark:text-white" dir="ltr">
                                {{ ticket.ticket_number }}
                            </td>
                            <td class="py-3.5 px-4">
                                <Link
                                    :href="route('tickets.show', ticket.id)"
                                    class="font-bold text-slate-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                                >
                                    {{ ticket.subject }}
                                </Link>
                                <div v-if="ticket.phone_number" class="text-[11px] text-slate-400 flex items-center gap-1.5 mt-0.5">
                                    <span>خط مرتبط:</span>
                                    <span class="font-mono text-slate-600 dark:text-slate-300" dir="ltr">{{ ticket.phone_number.formatted_number }}</span>
                                    <span v-if="ticket.phone_number.display_title">({{ ticket.phone_number.display_title }})</span>
                                </div>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="ticket.department_badge">
                                    {{ ticket.department_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="ticket.priority_badge">
                                    {{ ticket.priority_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold" :class="ticket.status_badge">
                                    {{ ticket.status_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-slate-400">
                                <span class="block font-mono text-[11px]" dir="ltr">{{ ticket.last_reply_at }}</span>
                                <span class="text-[10px]">{{ ticket.last_reply_human }}</span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <Link
                                    :href="route('tickets.show', ticket.id)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 font-bold text-xs transition-colors"
                                >
                                    <span>مشاهده و گفتگو</span>
                                    <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                </Link>
                            </td>
                        </tr>

                        <tr v-if="tickets.data.length === 0">
                            <td colspan="7" class="py-12 text-center text-slate-400">
                                <svg class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-700 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                                <p class="text-sm font-bold text-slate-700 dark:text-slate-300">هیچ تیکت پشتیبانی یافت نشد.</p>
                                <p class="text-xs text-slate-400 mt-1">در صورت نیاز به راهنمایی یا گزارش اختلال، یک تیکت جدید ثبت فرمایید.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="tickets.links && tickets.links.length > 3"
                class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between"
            >
                <div class="text-xs text-slate-400">
                    نمایش صفحه {{ tickets.current_page }} از {{ tickets.last_page }}
                </div>
                <div class="flex items-center gap-1">
                    <Link
                        v-for="(link, idx) in tickets.links"
                        :key="idx"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition-colors"
                        :class="[
                            link.active
                                ? 'bg-indigo-600 text-white shadow-sm'
                                : link.url
                                ? 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                                : 'text-slate-300 dark:text-slate-600 cursor-not-allowed'
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </UserLayout>
</template>
