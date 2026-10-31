<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface TicketItem {
    id: number;
    ticket_number: string;
    subject: string;
    user: {
        id: number;
        name: string;
        username: string;
    };
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
        id: number;
        name: string;
        username: string;
    } | null;
    last_reply_at: string | null;
    last_reply_human: string | null;
    created_at: string;
}

interface StaffUser {
    id: number;
    name: string;
    username: string;
    role_label: string;
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
        priority?: string;
        assigned_to?: string;
        search?: string;
    };
    stats: {
        total: number;
        open: number;
        in_progress: number;
        answered: number;
        closed: number;
        unassigned: number;
    };
    staffMembers: StaffUser[];
    departments: Array<{ value: string; label: string }>;
    priorities: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const department = ref(props.filters.department || '');
const priority = ref(props.filters.priority || '');
const assignedTo = ref(props.filters.assigned_to || '');

function applyFilters() {
    router.get(
        route('admin.tickets.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            department: department.value || undefined,
            priority: priority.value || undefined,
            assigned_to: assignedTo.value || undefined,
        },
        { preserveState: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    status.value = '';
    department.value = '';
    priority.value = '';
    assignedTo.value = '';
    applyFilters();
}
</script>

<template>
    <Head title="میز کار و کارتابل تیکت‌های پشتیبانی" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-base font-bold text-slate-900 dark:text-white">میز خدمت و مدیریت تیکت‌های پشتیبانی</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">رسیدگی به تیکت‌های مشترکین، واگذاری به کارشناسان و پاسخ‌گویی امن</p>
                </div>
            </div>
        </template>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5 mb-6">
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">کل تیکت‌ها</p>
                <p class="text-xl font-black text-slate-900 dark:text-white mt-1">{{ stats.total }}</p>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">در انتظار کارشناس</p>
                <p class="text-xl font-black text-amber-600 dark:text-amber-400 mt-1">{{ stats.open }}</p>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">در حال بررسی</p>
                <p class="text-xl font-black text-sky-600 dark:text-sky-400 mt-1">{{ stats.in_progress }}</p>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">پاسخ داده‌شده</p>
                <p class="text-xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.answered }}</p>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">بدون کارشناس</p>
                <p class="text-xl font-black text-rose-600 dark:text-rose-400 mt-1">{{ stats.unassigned }}</p>
            </div>

            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">بسته‌شده</p>
                <p class="text-xl font-black text-slate-500 dark:text-slate-400 mt-1">{{ stats.closed }}</p>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <!-- Search -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">جستجو</label>
                    <input
                        v-model="search"
                        @keydown.enter="applyFilters"
                        type="text"
                        placeholder="شماره تیکت، موضوع یا نام کاربر..."
                        class="form-input w-full text-xs py-2"
                    />
                </div>

                <!-- Department -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">دپارتمان</label>
                    <select
                        v-model="department"
                        @change="applyFilters"
                        class="form-select w-full text-xs py-2"
                    >
                        <option value="">همه دپارتمان‌ها</option>
                        <option v-for="d in departments" :key="d.value" :value="d.value">{{ d.label }}</option>
                    </select>
                </div>

                <!-- Status -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">وضعیت</label>
                    <select
                        v-model="status"
                        @change="applyFilters"
                        class="form-select w-full text-xs py-2"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>

                <!-- Priority -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">اولویت</label>
                    <select
                        v-model="priority"
                        @change="applyFilters"
                        class="form-select w-full text-xs py-2"
                    >
                        <option value="">همه اولویت‌ها</option>
                        <option v-for="p in priorities" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </div>

                <!-- Assigned To & Clear -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">کارشناس مسئول</label>
                    <div class="flex gap-2">
                        <select
                            v-model="assignedTo"
                            @change="applyFilters"
                            class="form-select w-full text-xs py-2"
                        >
                            <option value="">همه کارشناسان</option>
                            <option value="unassigned">بدون کارشناس</option>
                            <option v-for="staff in staffMembers" :key="staff.id" :value="staff.id">
                                {{ staff.name }} (@{{ staff.username }})
                            </option>
                        </select>
                        <button
                            v-if="search || status || department || priority || assignedTo"
                            type="button"
                            @click="clearFilters"
                            class="px-2.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-rose-500 text-xs font-bold shrink-0 cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-700 transition"
                            title="پاکسازی فیلترها"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tickets Table -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/40 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                            <th class="py-3 px-4 font-bold">شناسه</th>
                            <th class="py-3 px-4 font-bold">موضوع تیکت</th>
                            <th class="py-3 px-4 font-bold">کاربر متقاضی</th>
                            <th class="py-3 px-4 font-bold">دپارتمان</th>
                            <th class="py-3 px-4 font-bold">اولویت</th>
                            <th class="py-3 px-4 font-bold">وضعیت</th>
                            <th class="py-3 px-4 font-bold">کارشناس</th>
                            <th class="py-3 px-4 font-bold">آخرین اقدام</th>
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
                                    :href="route('admin.tickets.show', ticket.id)"
                                    class="font-bold text-slate-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors block max-w-xs truncate"
                                >
                                    {{ ticket.subject }}
                                </Link>
                                <span v-if="ticket.phone_number" class="text-[10px] text-slate-400 font-mono" dir="ltr">
                                    {{ ticket.phone_number.formatted_number }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-bold text-slate-900 dark:text-white block">{{ ticket.user.name }}</span>
                                <span class="font-mono text-[10px] text-slate-400" dir="ltr">@{{ ticket.user.username }}</span>
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
                            <td class="py-3.5 px-4">
                                <span v-if="ticket.assigned_agent" class="inline-flex items-center gap-1 font-medium text-slate-800 dark:text-slate-200">
                                    <span>{{ ticket.assigned_agent.name }}</span>
                                </span>
                                <span v-else class="text-rose-500 font-medium text-[11px]">بدون کارشناس</span>
                            </td>
                            <td class="py-3.5 px-4 text-slate-400">
                                <span class="block font-mono text-[10px]" dir="ltr">{{ ticket.last_reply_at }}</span>
                                <span class="text-[10px]">{{ ticket.last_reply_human }}</span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <Link
                                    :href="route('admin.tickets.show', ticket.id)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 font-bold text-xs transition-colors"
                                >
                                    <span>پاسخ و مدیریت</span>
                                    <svg class="w-3.5 h-3.5 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                </Link>
                            </td>
                        </tr>

                        <tr v-if="tickets.data.length === 0">
                            <td colspan="9" class="py-12 text-center text-slate-400">
                                <p class="text-sm font-bold text-slate-700 dark:text-slate-300">هیچ تیکتی با این مشخصات یافت نشد.</p>
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
    </AdminLayout>
</template>
