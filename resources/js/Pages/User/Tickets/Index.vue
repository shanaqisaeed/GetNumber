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
        <div class="space-y-4">
            <!-- 1. Header Banner -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <span class="wallet-title">مرکز پشتیبانی و تیکت‌ها</span>
                    <span class="wallet-chip bg-indigo-500/15 border-indigo-500/35 text-indigo-600 dark:text-indigo-400">
                        ۲۴/۷ فعال
                    </span>
                </div>

                <div class="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                    ارتباط مستقیم با کارشناسان فنی سامانه و پیگیری مسائل سرشماره‌ها، وب‌سرویس و وب‌هوک‌ها.
                </div>

                <!-- Stats 3-Column Pill Row -->
                <div class="grid grid-cols-3 gap-2 mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 text-center">
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-amber-600 dark:text-amber-400 block font-bold">در انتظار</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.open }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block font-bold">پاسخ‌داده‌شده</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.answered }}</span>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                        <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-bold">بسته‌شده</span>
                        <span class="text-sm font-black font-mono text-slate-900 dark:text-white">{{ stats.closed }}</span>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-200/80 dark:border-white/10 flex justify-end">
                    <Link
                        :href="route('tickets.create')"
                        class="glass-btn-primary !w-full !py-2.5 !text-xs !rounded-xl flex items-center justify-center gap-1.5"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>ثبت تیکت جدید</span>
                    </Link>
                </div>
            </div>

            <!-- 2. Search & Filter Bar -->
            <div class="glass-card !p-3 space-y-2.5 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                <div class="relative">
                    <input
                        v-model="search"
                        @keydown.enter="applyFilters"
                        type="text"
                        placeholder="جستجو در شناسه یا موضوع تیکت..."
                        class="glass-input !py-2 !pr-8 !pl-3 !text-xs !rounded-xl w-full bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
                    />
                    <svg class="w-3.5 h-3.5 text-slate-400 absolute right-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <select
                        v-model="status"
                        @change="applyFilters"
                        class="form-select !py-2 !text-xs !rounded-xl w-full"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>

                    <select
                        v-model="department"
                        @change="applyFilters"
                        class="form-select !py-2 !text-xs !rounded-xl w-full"
                    >
                        <option value="">همه دپارتمان‌ها</option>
                        <option v-for="d in departments" :key="d.value" :value="d.value">{{ d.label }}</option>
                    </select>
                </div>

                <div v-if="search || status || department" class="flex justify-end pt-1">
                    <button
                        type="button"
                        @click="clearFilters"
                        class="text-[11px] font-bold text-rose-500 hover:underline"
                    >
                        پاکسازی فیلترها
                    </button>
                </div>
            </div>

            <!-- 3. Tickets Cards List -->
            <div v-if="tickets.data.length > 0" class="space-y-3">
                <Link
                    v-for="ticket in tickets.data"
                    :key="ticket.id"
                    :href="route('tickets.show', ticket.id)"
                    class="glass-card !p-4 hover:border-indigo-500/40 block transition-all space-y-2.5 group bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10"
                >
                    <!-- Header: ID, Badges -->
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">
                                #{{ ticket.ticket_number }}
                            </span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shadow-xs" :class="ticket.department_badge">
                                {{ ticket.department_label }}
                            </span>
                        </div>

                        <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full border shadow-xs" :class="ticket.status_badge">
                            {{ ticket.status_label }}
                        </span>
                    </div>

                    <!-- Subject -->
                    <h3 class="text-xs font-black text-slate-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors line-clamp-1">
                        {{ ticket.subject }}
                    </h3>

                    <!-- Associated number if any -->
                    <div v-if="ticket.phone_number" class="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                        <span>خط مرتبط:</span>
                        <span dir="ltr" class="font-mono text-slate-800 dark:text-slate-200 font-bold">{{ ticket.phone_number.formatted_number }}</span>
                    </div>

                    <!-- Footer: Last reply & direct action -->
                    <div class="flex items-center justify-between pt-2 border-t border-slate-200/80 dark:border-white/5 text-[11px] text-slate-500 dark:text-slate-400">
                        <span class="font-mono">{{ ticket.last_reply_human || ticket.created_at }}</span>
                        <div class="flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-bold group-hover:translate-x-[-3px] transition-transform">
                            <span>مشاهده گفتگو</span>
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </div>
                    </div>
                </Link>

                <!-- Pagination -->
                <div v-if="tickets.links && tickets.links.length > 3" class="flex justify-center gap-1 pt-2">
                    <Link
                        v-for="(link, i) in tickets.links"
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
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">تیکتی یافت نشد</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-xs mx-auto">
                        در صورت نیاز به راهنمایی یا گزارش نقص، تیکت پشتیبانی جدیدی ثبت فرمایید.
                    </p>
                </div>
                <Link
                    :href="route('tickets.create')"
                    class="glass-btn-primary !w-auto !py-2 !px-4 !text-xs !rounded-xl inline-flex items-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>ثبت تیکت جدید</span>
                </Link>
            </div>
        </div>
    </UserLayout>
</template>
