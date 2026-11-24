<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import OrgLayout from '@/Layouts/OrgLayout.vue';
import { __ } from '@/Composables/useTrans';

interface RoomItem {
    id: number;
    room_uuid: string;
    title: string;
    is_host: boolean;
    creator_name: string;
    has_pin: boolean;
    admission_policy: string;
    webinar_mode: boolean;
    screen_share_policy: string;
    is_locked?: boolean;
    scheduled_start_at: string | null;
    scheduled_end_at: string | null;
    expires_at: string | null;
    is_expired: boolean;
    is_future: boolean;
    active_count: number;
}

interface LineItem {
    id: number;
    key: string;
    number: string;
    formatted_number: string;
    display_title: string;
    status: string;
}

const props = defineProps<{
    organization?: {
        id: number;
        name: string;
        identifier: string;
        status: string;
    };
    rooms: RoomItem[];
    operableLines: LineItem[];
}>();

const searchQuery = ref('');
const joinCodeInput = ref('');

const filteredRooms = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.rooms;
    return props.rooms.filter((r) => {
        return (
            (r.title && r.title.toLowerCase().includes(q)) ||
            (r.creator_name && r.creator_name.toLowerCase().includes(q)) ||
            (r.room_uuid && r.room_uuid.toLowerCase().includes(q))
        );
    });
});

function joinByCode() {
    const val = joinCodeInput.value.trim();
    if (!val) return;
    const match = val.match(/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i);
    const uuid = match ? match[1] : val;
    router.visit(route('org.conference.lobby', { uuid }));
}

const isCreateModalOpen = ref(false);
const isScheduleEnabled = ref(false);
const copySuccessUuid = ref<string | null>(null);

const form = useForm({
    title: '',
    pin_code: '',
    admission_policy: 'open',
    initial_audio_state: 'open',
    webinar_mode: false,
    screen_share_policy: 'everyone',
    mute_others_on_share: false,
    allow_whiteboard: true,
    watermark_enabled: false,
    scheduled_start_at: '',
    scheduled_end_at: '',
});

function openCreateModal() {
    form.reset();
    isScheduleEnabled.value = false;
    isCreateModalOpen.value = true;
}

function submitCreateRoom() {
    if (!isScheduleEnabled.value) {
        form.scheduled_start_at = '';
        form.scheduled_end_at = '';
    }
    form.post(route('org.conference.store'), {
        onSuccess: () => {
            isCreateModalOpen.value = false;
        },
    });
}

function copyInvitationLink(uuid: string) {
    const url = `${window.location.origin}/org/conference/${uuid}/lobby`;
    navigator.clipboard.writeText(url).then(() => {
        copySuccessUuid.value = uuid;
        setTimeout(() => {
            copySuccessUuid.value = null;
        }, 2500);
    });
}

function deleteRoom(uuid: string) {
    if (confirm(__('conference.room.delete_confirm'))) {
        router.delete(route('org.conference.destroy', { uuid }));
    }
}
</script>

<template>
    <Head :title="__('conference.title')" />

    <OrgLayout>
        <div class="max-w-7xl mx-auto space-y-6">
            <!-- Header Section -->
            <div class="bg-gradient-to-l from-slate-900 via-purple-950 to-slate-900 border border-purple-500/20 rounded-3xl p-6 sm:p-8 text-white shadow-xl flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-bold border border-emerald-500/30">
                            {{ __('conference.room.hybrid_badge') }}
                        </span>
                        <span class="px-2.5 py-0.5 rounded-full bg-purple-500/20 text-purple-300 text-xs font-bold border border-purple-500/30">
                            {{ __('conference.room.expiry_badge') }}
                        </span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white">
                        {{ __('conference.title') }}
                    </h1>
                    <p class="text-slate-300 text-sm max-w-2xl leading-relaxed">
                        {{ __('conference.subtitle') }}
                    </p>
                </div>
                <div class="shrink-0">
                    <button
                        @click="openCreateModal"
                        class="w-full sm:w-auto px-6 py-3.5 rounded-2xl bg-purple-600 hover:bg-purple-500 text-white font-bold text-sm shadow-lg shadow-purple-600/30 transition-all flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>{{ __('conference.room.create_title') }}</span>
                    </button>
                </div>
            </div>

            <!-- Search & Quick Join Bar -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm flex flex-col md:flex-row items-center gap-4">
                <!-- Live Search Input -->
                <div class="relative flex-1 w-full">
                    <div class="absolute inset-y-0 start-0 flex items-center ps-3.5 pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="__('conference.room.search_placeholder')"
                        class="w-full ps-10 pe-9 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''"
                        class="absolute inset-y-0 end-0 flex items-center pe-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Quick Direct Join by Code or URL -->
                <form @submit.prevent="joinByCode" class="flex items-center gap-2 w-full md:w-auto">
                    <div class="relative flex-1 md:w-72">
                        <input
                            v-model="joinCodeInput"
                            type="text"
                            :placeholder="__('conference.room.join_code_placeholder')"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-900 dark:text-white text-xs font-mono focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="!joinCodeInput.trim()"
                        class="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-xs font-bold transition flex items-center gap-1.5 shrink-0 shadow-sm cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        <span>{{ __('conference.room.join_by_code_btn') }}</span>
                    </button>
                </form>
            </div>

            <!-- Active & Scheduled Rooms Grid -->
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>{{ __('conference.room.active_rooms_title') }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-mono">
                            {{ filteredRooms.length }}
                        </span>
                    </h2>
                </div>

                <div v-if="rooms.length === 0" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-12 text-center space-y-4">
                    <div class="w-16 h-16 rounded-2xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center mx-auto">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="space-y-1">
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-200">{{ __('conference.room.empty_title') }}</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('conference.room.empty_desc') }}</p>
                    </div>
                    <div>
                        <button
                            @click="openCreateModal"
                            class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition"
                        >
                            {{ __('conference.room.create_title') }}
                        </button>
                    </div>
                </div>

                <div v-else-if="filteredRooms.length === 0" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-12 text-center space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('conference.room.no_search_results') }}</p>
                    <button
                        @click="searchQuery = ''"
                        class="text-xs font-bold text-purple-600 hover:text-purple-500 transition"
                    >
                        پاک کردن جستجو
                    </button>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <div
                        v-for="room in filteredRooms"
                        :key="room.id"
                        class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all flex flex-col justify-between space-y-4"
                    >
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-2">
                                <span
                                    v-if="room.is_host"
                                    class="px-2 py-0.5 rounded-md bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400 text-[11px] font-bold border border-purple-200 dark:border-purple-800"
                                >
                                    {{ __('conference.common.host') }}
                                </span>
                                <span
                                    v-else
                                    class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 text-[11px] font-medium"
                                >
                                    {{ __('conference.common.participant') }}
                                </span>

                                <div class="flex items-center gap-1.5">
                                    <span v-if="room.webinar_mode" class="px-2 py-0.5 rounded-md bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 text-[10px] font-semibold border border-amber-200 dark:border-amber-800/50">
                                        {{ __('conference.room.webinar_badge') }}
                                    </span>
                                    <span v-if="room.has_pin" class="p-1 rounded bg-slate-100 dark:bg-slate-800 text-slate-500" :title="__('conference.room.has_pin_tooltip')">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                    </span>
                                    <button
                                        v-if="room.is_host"
                                        @click="deleteRoom(room.room_uuid)"
                                        class="p-1 rounded hover:bg-rose-50 dark:hover:bg-rose-950/50 text-slate-400 hover:text-rose-500 transition"
                                        :title="__('conference.common.delete')"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <h3 class="text-base font-bold text-slate-900 dark:text-white line-clamp-1">
                                {{ room.title }}
                            </h3>

                            <div class="text-xs text-slate-500 dark:text-slate-400 space-y-1">
                                <p class="flex items-center justify-between">
                                    <span>
                                        <span class="text-slate-400">{{ __('conference.room.host_label') }}</span>
                                        <span class="font-medium text-slate-700 dark:text-slate-300 ms-1">{{ room.creator_name }}</span>
                                    </span>
                                    <span class="font-mono text-[11px] px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                        {{ __('conference.controls.participants') }}: {{ room.active_count }}
                                    </span>
                                </p>
                                <p v-if="room.is_future && room.scheduled_start_at" class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400 font-medium">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>{{ __('conference.room.scheduled_badge') }}</span>
                                </p>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2">
                            <button
                                @click="copyInvitationLink(room.room_uuid)"
                                class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition flex items-center gap-1"
                                :title="__('conference.room.copy_link')"
                            >
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span>{{ copySuccessUuid === room.room_uuid ? __('conference.room.link_copied') : __('conference.room.copy_link') }}</span>
                            </button>

                            <Link
                                :href="route('org.conference.lobby', { uuid: room.room_uuid })"
                                class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition flex items-center gap-1 shadow-sm"
                            >
                                <span>{{ __('conference.room.enter_room_btn') }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Room Modal -->
        <div v-if="isCreateModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fade-in">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl w-full max-w-xl max-h-[90vh] overflow-y-auto shadow-2xl p-6 sm:p-7 space-y-6">
                <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">
                        {{ __('conference.room.create_title') }}
                    </h3>
                    <button @click="isCreateModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitCreateRoom" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            {{ __('conference.room.room_name') }} <span class="text-slate-400 font-normal">({{ __('conference.common.optional') }})</span>
                        </label>
                        <input
                            v-model="form.title"
                            type="text"
                            :placeholder="__('conference.room.room_name_placeholder')"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            {{ __('conference.room.pin_code') }}
                        </label>
                        <input
                            v-model="form.pin_code"
                            type="text"
                            maxlength="8"
                            :placeholder="__('conference.room.pin_placeholder')"
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm font-mono focus:ring-2 focus:ring-purple-500"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.room.admission_policy') }}
                            </label>
                            <select
                                v-model="form.admission_policy"
                                class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-medium"
                            >
                                <option value="open">{{ __('conference.room.admission_open') }}</option>
                                <option value="approval">{{ __('conference.room.admission_approval') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.room.initial_audio') }}
                            </label>
                            <select
                                v-model="form.initial_audio_state"
                                class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-medium"
                            >
                                <option value="open">{{ __('conference.room.initial_audio_open') }}</option>
                                <option value="muted">{{ __('conference.room.initial_audio_muted') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                            {{ __('conference.room.screen_share_policy') }}
                        </label>
                        <select
                            v-model="form.screen_share_policy"
                            class="w-full px-3 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-medium"
                        >
                            <option value="everyone">{{ __('conference.room.screen_share_everyone') }}</option>
                            <option value="by_permission">{{ __('conference.room.screen_share_by_permission') }}</option>
                            <option value="host_only">{{ __('conference.room.screen_share_host_only') }}</option>
                        </select>
                    </div>

                    <div class="space-y-3 pt-2">
                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 cursor-pointer">
                            <input v-model="form.watermark_enabled" type="checkbox" class="w-4 h-4 text-purple-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ __('conference.room.watermark_enabled_label') }}</span>
                                <span class="text-slate-500 text-[11px]">{{ __('conference.room.watermark_enabled_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 cursor-pointer">
                            <input v-model="form.mute_others_on_share" type="checkbox" class="w-4 h-4 text-purple-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ __('conference.room.mute_others_on_share') }}</span>
                                <span class="text-slate-500 text-[11px]">{{ __('conference.room.mute_others_on_share_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 cursor-pointer">
                            <input v-model="form.webinar_mode" type="checkbox" class="w-4 h-4 text-purple-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ __('conference.room.webinar_mode') }}</span>
                                <span class="text-slate-500 text-[11px]">{{ __('conference.room.webinar_mode_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 cursor-pointer">
                            <input v-model="form.allow_whiteboard" type="checkbox" class="w-4 h-4 text-purple-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ __('conference.room.allow_whiteboard') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 cursor-pointer">
                            <input v-model="isScheduleEnabled" type="checkbox" class="w-4 h-4 text-purple-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-800 dark:text-slate-200 block">{{ __('conference.room.schedule_meeting') }}</span>
                                <span class="text-slate-500 text-[11px]">{{ __('conference.room.schedule_meeting_desc') }}</span>
                            </div>
                        </label>
                    </div>

                    <div v-if="isScheduleEnabled" class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 rounded-xl bg-purple-50/50 dark:bg-purple-950/20 border border-purple-200 dark:border-purple-800/40 animate-fade-in">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.room.scheduled_start') }}
                            </label>
                            <input
                                v-model="form.scheduled_start_at"
                                type="datetime-local"
                                required
                                class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.room.scheduled_end') }}
                            </label>
                            <input
                                v-model="form.scheduled_end_at"
                                type="datetime-local"
                                class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs"
                            />
                        </div>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="isCreateModalOpen = false"
                            class="px-5 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold"
                        >
                            {{ __('conference.common.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition shadow-md disabled:opacity-50"
                        >
                            {{ form.processing ? __('conference.common.loading') : __('conference.room.create_btn') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </OrgLayout>
</template>
