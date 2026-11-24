<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTrans } from '@/Composables/useTrans';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const { __ } = useTrans();

interface CreatorItem {
    id: number;
    name: string;
    account_id: string;
}

interface OrgItem {
    id: number;
    name: string;
}

interface ConferenceItem {
    id: number;
    room_uuid: string;
    title: string;
    organization: OrgItem | null;
    creator: CreatorItem | null;
    has_pin: boolean;
    admission_policy: string;
    initial_audio_state: string;
    webinar_mode: boolean;
    screen_share_policy: string;
    allow_whiteboard: boolean;
    watermark_enabled: boolean;
    is_locked: boolean;
    is_active: boolean;
    is_expired: boolean;
    active_count: number;
    created_at: string;
    expires_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    rooms: {
        data: ConferenceItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        filter?: string;
    };
    stats: {
        active_rooms: number;
        active_participants: number;
    };
}>();

const search = ref(props.filters.search || '');
const currentFilter = ref(props.filters.filter || 'all');

function applyFilters() {
    router.get(
        route('admin.conferences.index'),
        {
            search: search.value || undefined,
            filter: currentFilter.value !== 'all' ? currentFilter.value : undefined,
        },
        { preserveState: true, replace: true }
    );
}

function setFilter(filterType: string) {
    currentFilter.value = filterType;
    applyFilters();
}

function deleteConference(uuid: string) {
    if (confirm(__('admin.conferences.end_room_confirm'))) {
        router.delete(route('admin.conferences.destroy', { uuid }), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <AdminLayout>
        <Head :title="__('admin.conferences.title')" />

        <div class="space-y-6">
            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ __('admin.conferences.title') }}
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('admin.conferences.subtitle') }}
                    </p>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold">
                            {{ __('admin.conferences.stat_active_rooms') }}
                        </div>
                        <div class="text-2xl font-black text-slate-900 dark:text-white mt-0.5">
                            {{ stats.active_rooms }}
                        </div>
                    </div>
                </div>

                <div class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 dark:text-slate-400 font-semibold">
                            {{ __('admin.conferences.stat_active_participants') }}
                        </div>
                        <div class="text-2xl font-black text-slate-900 dark:text-white mt-0.5">
                            {{ stats.active_participants }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters & Search Toolbar -->
            <div class="p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="w-full md:w-80 relative">
                    <input
                        v-model="search"
                        @keyup.enter="applyFilters"
                        type="text"
                        :placeholder="__('admin.conferences.search_placeholder')"
                        class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-indigo-500"
                    />
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <div class="flex items-center gap-1.5 w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                    <button
                        @click="setFilter('all')"
                        :class="currentFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200'"
                        class="px-3 py-2 rounded-xl text-xs font-bold transition shrink-0 cursor-pointer"
                    >
                        {{ __('admin.conferences.filter_all') }}
                    </button>
                    <button
                        @click="setFilter('active')"
                        :class="currentFilter === 'active' ? 'bg-emerald-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200'"
                        class="px-3 py-2 rounded-xl text-xs font-bold transition shrink-0 cursor-pointer"
                    >
                        {{ __('admin.conferences.filter_active') }}
                    </button>
                    <button
                        @click="setFilter('expired')"
                        :class="currentFilter === 'expired' ? 'bg-slate-700 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200'"
                        class="px-3 py-2 rounded-xl text-xs font-bold transition shrink-0 cursor-pointer"
                    >
                        {{ __('admin.conferences.filter_expired') }}
                    </button>
                </div>
            </div>

            <!-- Conferences Table -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="p-4">{{ __('admin.conferences.th_room') }}</th>
                                <th class="p-4">{{ __('admin.conferences.th_creator') }}</th>
                                <th class="p-4">{{ __('admin.conferences.th_status') }}</th>
                                <th class="p-4">{{ __('admin.conferences.th_policies') }}</th>
                                <th class="p-4">{{ __('admin.conferences.th_created_at') }}</th>
                                <th class="p-4 text-center">{{ __('admin.conferences.th_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <tr
                                v-for="room in rooms.data"
                                :key="room.id"
                                class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition"
                            >
                                <td class="p-4">
                                    <div class="font-bold text-slate-900 dark:text-white">{{ room.title }}</div>
                                    <div class="text-[11px] font-mono text-slate-400 mt-0.5">{{ room.room_uuid }}</div>
                                </td>

                                <td class="p-4">
                                    <div class="font-semibold text-slate-800 dark:text-slate-200">
                                        {{ room.creator?.name || '-' }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ room.organization?.name || room.creator?.account_id }}
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold"
                                            :class="room.is_active ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-slate-500/10 text-slate-500 border border-slate-500/20'"
                                        >
                                            {{ room.is_active ? __('admin.conferences.status_active') : __('admin.conferences.status_expired') }}
                                        </span>
                                        <span
                                            v-if="room.is_active"
                                            class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20"
                                        >
                                            {{ __('admin.conferences.active_participants_count', { count: room.active_count }) }}
                                        </span>
                                    </div>
                                </td>

                                <td class="p-4">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-if="room.watermark_enabled" class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 text-[10px] font-medium border border-amber-500/20">
                                            واترمارک
                                        </span>
                                        <span v-if="room.webinar_mode" class="px-1.5 py-0.5 rounded bg-purple-500/10 text-purple-600 dark:text-purple-400 text-[10px] font-medium border border-purple-500/20">
                                            وبینار
                                        </span>
                                        <span v-if="room.is_locked" class="px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-600 dark:text-rose-400 text-[10px] font-medium border border-rose-500/20">
                                            قفل
                                        </span>
                                        <span v-if="room.has_pin" class="px-1.5 py-0.5 rounded bg-slate-500/10 text-slate-600 dark:text-slate-400 text-[10px] font-medium border border-slate-500/20">
                                            رمز
                                        </span>
                                    </div>
                                </td>

                                <td class="p-4 text-slate-500 dark:text-slate-400 font-mono text-[11px]">
                                    {{ room.created_at }}
                                </td>

                                <td class="p-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <Link
                                            v-if="room.is_active"
                                            :href="route('org.conference.lobby', { uuid: room.room_uuid })"
                                            target="_blank"
                                            class="px-2.5 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 font-bold text-[11px] transition"
                                            :title="__('admin.conferences.enter_as_admin')"
                                        >
                                            {{ __('admin.conferences.enter_as_admin') }}
                                        </Link>

                                        <button
                                            @click="deleteConference(room.room_uuid)"
                                            class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 hover:bg-rose-100 font-bold text-[11px] transition cursor-pointer"
                                            :title="__('admin.conferences.end_room_btn')"
                                        >
                                            {{ __('admin.conferences.end_room_btn') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="rooms.data.length === 0">
                                <td colspan="6" class="p-10 text-center text-slate-400">
                                    <div class="font-bold">{{ __('admin.conferences.empty_title') }}</div>
                                    <div class="text-[11px] mt-1">{{ __('admin.conferences.empty_desc') }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Links -->
                <div v-if="rooms.links && rooms.links.length > 3" class="p-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-center gap-1">
                    <Link
                        v-for="(link, i) in rooms.links"
                        :key="i"
                        :href="link.url || '#'"
                        :class="[
                            'px-3 py-1.5 rounded-xl text-xs font-bold transition',
                            link.active ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200',
                            !link.url ? 'opacity-40 pointer-events-none' : ''
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
