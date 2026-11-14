<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface UserItem {
    id: number;
    name: string;
    username: string;
}

interface CallLogItem {
    id: number;
    user: UserItem | null;
    source_identifier: string;
    destination: string;
    direction: string;
    status: string;
    started_at: string | null;
    ended_at: string | null;
    duration_seconds: number;
    duration_formatted: string;
    cost: string;
    rate_per_minute: string;
    ping_ms: number;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    calls: {
        data: CallLogItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        status?: string;
        direction?: string;
    };
    stats: {
        total_calls: number;
        completed_calls: number;
        total_duration_minutes: number;
        total_cost: string;
    };
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const direction = ref(props.filters.direction || '');

const handleFilter = () => {
    router.get(
        route('admin.calls.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            direction: direction.value || undefined,
        },
        { preserveState: true, replace: true }
    );
};

const clearFilter = () => {
    search.value = '';
    status.value = '';
    direction.value = '';
    router.get(route('admin.calls.index'));
};
</script>

<template>
    <Head title="گزارش و مانیتورینگ مکالمات صوتی" />

    <AdminLayout>
        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        گزارش و لاگ جامع تماس‌های صوتی
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        نظارت زنده، بررسی مدت مکالمه، پینگ سرور ارتباطی و صورت‌حساب کسرشده از کاربران
                    </p>
                </div>
            </div>

            <!-- KPI Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                    <div class="text-xs text-slate-400 font-semibold">مجموع تماس‌ها</div>
                    <div class="text-2xl font-black font-mono text-slate-900 dark:text-white mt-1">{{ stats.total_calls }}</div>
                </div>
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                    <div class="text-xs text-slate-400 font-semibold">تماس‌های برقرارشده</div>
                    <div class="text-2xl font-black font-mono text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.completed_calls }}</div>
                </div>
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                    <div class="text-xs text-slate-400 font-semibold">مجموع دقایق مکالمه</div>
                    <div class="text-2xl font-black font-mono text-indigo-600 dark:text-indigo-400 mt-1">{{ stats.total_duration_minutes }} دقیقه</div>
                </div>
                <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm">
                    <div class="text-xs text-slate-400 font-semibold">کل هزینه کسرشده</div>
                    <div class="text-2xl font-black font-mono text-amber-600 dark:text-amber-400 mt-1" dir="ltr">{{ Number(stats.total_cost).toFixed(2) }} TRX</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-sm flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px]">
                    <input
                        type="text"
                        v-model="search"
                        @keyup.enter="handleFilter"
                        placeholder="جستجو در خط مبدا، مقصد یا نام کاربر..."
                        class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5 pr-8"
                    />
                    <span class="absolute right-2.5 top-2.5 text-slate-400">🔍</span>
                </div>

                <select
                    v-model="status"
                    @change="handleFilter"
                    class="text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                >
                    <option value="">همه وضعیت‌ها</option>
                    <option value="completed">موفق (Completed)</option>
                    <option value="in_progress">در حال مکالمه</option>
                    <option value="missed">بی‌پاسخ (Missed)</option>
                    <option value="failed">ناموفق (Failed)</option>
                </select>

                <select
                    v-model="direction"
                    @change="handleFilter"
                    class="text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-2.5"
                >
                    <option value="">همه جهت‌ها</option>
                    <option value="outbound">خروجی</option>
                    <option value="inbound">ورودی</option>
                </select>

                <button
                    type="button"
                    @click="handleFilter"
                    class="px-4 py-2 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition-colors"
                >
                    اعمال فیلتر
                </button>

                <button
                    v-if="search || status || direction"
                    type="button"
                    @click="clearFilter"
                    class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold text-xs"
                >
                    پاک‌کردن
                </button>
            </div>

            <!-- Calls Table -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800/80 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/50 text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                <th class="py-3 px-4 font-bold">شناسه</th>
                                <th class="py-3 px-4 font-bold">کاربر</th>
                                <th class="py-3 px-4 font-bold">مبدا</th>
                                <th class="py-3 px-4 font-bold">مقصد</th>
                                <th class="py-3 px-4 font-bold">جهت</th>
                                <th class="py-3 px-4 font-bold">وضعیت</th>
                                <th class="py-3 px-4 font-bold">مدت مکالمه</th>
                                <th class="py-3 px-4 font-bold">هزینه</th>
                                <th class="py-3 px-4 font-bold">پینگ (Ping)</th>
                                <th class="py-3 px-4 font-bold">زمان شروع</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            <tr v-for="call in calls.data" :key="call.id" class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-500">#{{ call.id }}</td>
                                <td class="py-3.5 px-4 font-bold">
                                    <Link
                                        v-if="call.user"
                                        :href="route('admin.users.edit', call.user.id)"
                                        class="text-indigo-600 dark:text-indigo-400 hover:underline"
                                    >
                                        {{ call.user.name }}
                                    </Link>
                                    <span v-else class="text-slate-400">ناشناس</span>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold" dir="ltr">{{ call.source_identifier }}</td>
                                <td class="py-3.5 px-4 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">{{ call.destination }}</td>
                                <td class="py-3.5 px-4">
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                        :class="call.direction === 'outbound' ? 'bg-emerald-500/15 text-emerald-600 border border-emerald-500/30' : 'bg-blue-500/15 text-blue-600 border border-blue-500/30'"
                                    >
                                        {{ call.direction === 'outbound' ? 'خروجی ↗' : 'ورودی ↙' }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span
                                        class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                        :class="call.status === 'completed' ? 'bg-emerald-500/15 text-emerald-600' : (call.status === 'in_progress' ? 'bg-amber-500/15 text-amber-600 animate-pulse' : 'bg-slate-500/15 text-slate-600')"
                                    >
                                        {{ call.status }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold" dir="ltr">{{ call.duration_formatted }}</td>
                                <td class="py-3.5 px-4 font-mono font-black text-amber-600 dark:text-amber-400" dir="ltr">{{ call.cost }} TRX</td>
                                <td class="py-3.5 px-4 font-mono text-[11px]" dir="ltr">
                                    <span class="px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 font-bold">
                                        {{ call.ping_ms }} ms
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-[11px] text-slate-400" dir="ltr">{{ call.started_at || '-' }}</td>
                            </tr>
                            <tr v-if="calls.data.length === 0">
                                <td colspan="10" class="py-10 text-center text-slate-400">
                                    هیچ تماسی با مشخصات واردشده یافت نشد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="calls.links && calls.links.length > 3" class="p-4 border-t border-slate-100 dark:border-slate-800 flex justify-center gap-1">
                    <Link
                        v-for="(link, i) in calls.links"
                        :key="i"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                        :class="[
                            link.active ? 'bg-indigo-600 text-white shadow-md' : 'bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
                            !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

