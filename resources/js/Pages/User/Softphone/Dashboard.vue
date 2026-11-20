<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';
import ContactPickerModal, { type ContactItem as PickerContactItem } from '@/Components/ContactPickerModal.vue';
import LineSwitcherModal, { LineOption } from '@/Components/LineSwitcherModal.vue';
import { CalmRingtone } from '@/Utils/ringtone';
import { __ } from '@/Composables/useTrans';
import axios from 'axios';

interface LineItem {
    id: number;
    key: string;
    number: string;
    formatted_number: string;
    display_title: string;
    status: string;
    type: 'number' | 'custom_id';
    type_label: string;
    is_organization: boolean;
    organization_name?: string | null;
}

interface RecentCall {
    id: number;
    source: string;
    destination: string;
    direction: 'inbound' | 'outbound';
    status: string;
    started_at: string | null;
    duration_formatted: string;
    cost: number;
}

interface RecentConversation {
    peer: string;
    unread_count: number;
    last_message?: string;
    last_at?: string;
}

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes?: string | null;
    is_shared?: boolean;
    shared_label?: string;
}

const props = defineProps<{
    has_lines: boolean;
    lines: LineItem[];
    active_line: LineItem | null;
    wallet: {
        source: 'personal' | 'organization';
        balance: string;
        currency: string;
        is_organization: boolean;
        organization_name?: string | null;
    };
    presence: 'online' | 'busy' | 'dnd' | 'offline';
    recent_calls: RecentCall[];
    recent_conversations: RecentConversation[];
    contacts: ContactItem[];
    rtc_config?: any;
}>();

// Line Switcher Modal
const isLineSwitcherOpen = ref(false);

// Dialer State
const destinationInput = ref('');
const isContactPickerOpen = ref(false);

// Keypad buttons
const keypadRows = [
    [{ digit: '1', sub: '' }, { digit: '2', sub: 'ABC' }, { digit: '3', sub: 'DEF' }],
    [{ digit: '4', sub: 'GHI' }, { digit: '5', sub: 'JKL' }, { digit: '6', sub: 'MNO' }],
    [{ digit: '7', sub: 'PQRS' }, { digit: '8', sub: 'TUV' }, { digit: '9', sub: 'WXYZ' }],
    [{ digit: '*', sub: '' }, { digit: '0', sub: '+' }, { digit: '#', sub: '' }],
];

function appendDigit(digit: string) {
    destinationInput.value += digit;
}

function removeLastDigit() {
    destinationInput.value = destinationInput.value.slice(0, -1);
}

function clearDestination() {
    destinationInput.value = '';
}

function handleContactSelected(contact: PickerContactItem) {
    destinationInput.value = contact.phone_number;
    isContactPickerOpen.value = false;
    startCall(contact.phone_number);
}

// Start Call: Redirects to /calls with prefilled destination and auto_open modal for noise cancellation review
function startCall(dest?: string) {
    const target = dest || destinationInput.value;
    if (!target) return;

    router.visit(route('calls.index', {
        destination: target.trim(),
        auto_open: 1,
    }));
}
</script>

<template>
    <UserLayout>
        <Head :title="__('softphone.nav.dashboard')" />

        <!-- Line Switcher Modal -->
        <LineSwitcherModal
            :show="isLineSwitcherOpen"
            :lines="lines"
            :active-line-key="active_line?.key"
            @close="isLineSwitcherOpen = false"
        />

        <!-- Contact Picker Modal -->
        <ContactPickerModal
            :show="isContactPickerOpen"
            :contacts="contacts"
            @close="isContactPickerOpen = false"
            @select="handleContactSelected"
        />

        <!-- SCENARIO 1: ZERO-LINE STATE -->
        <div v-if="!has_lines || !active_line" class="w-full max-w-xl mx-auto py-12 px-4 text-center">
            <div class="glass-card p-8 rounded-3xl border border-indigo-500/20 shadow-2xl space-y-6">
                <div class="w-20 h-20 mx-auto rounded-3xl bg-indigo-500/15 text-indigo-400 flex items-center justify-center shadow-inner">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>

                <div class="space-y-2">
                    <h2 class="text-xl font-black text-slate-800 dark:text-white">
                        {{ __('softphone.line.no_active_line') }}
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto leading-relaxed">
                        {{ __('softphone.line.no_line_desc') }}
                    </p>
                </div>

                <div class="pt-4">
                    <Link
                        :href="route('services.index')"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 py-3.5 px-8 rounded-2xl bg-gradient-to-r from-indigo-600 via-indigo-500 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-black text-sm shadow-xl shadow-indigo-600/30 transition-all active:scale-95"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>{{ __('softphone.line.buy_service_btn') }}</span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- SCENARIO 2: ACTIVE SOFTPHONE DESKTOP 3-COLUMN WORKSTATION -->
        <div v-else class="space-y-6">
            <!-- 3-Column Softphone Layout Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- COLUMN 1 (4 Cols): SMART DIALER -->
                <div class="lg:col-span-4 glass-card p-5 rounded-3xl border border-slate-200 dark:border-white/10 space-y-5">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                        <h3 class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <span>📞</span>
                            <span>{{ __('softphone.dialer.title') }}</span>
                        </h3>
                        <button
                            type="button"
                            @click="isContactPickerOpen = true"
                            class="text-xs font-bold text-indigo-500 hover:underline"
                        >
                            {{ __('softphone.nav.contacts') }}
                        </button>
                    </div>

                    <!-- Destination Input Bar -->
                    <div class="relative">
                        <input
                            v-model="destinationInput"
                            type="text"
                            dir="ltr"
                            :placeholder="__('softphone.dialer.enter_number_or_id')"
                            class="w-full py-3.5 px-4 pr-12 rounded-2xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 font-mono text-base font-black text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 transition-all text-center"
                            @keyup.enter="startCall()"
                        />
                        <button
                            v-if="destinationInput"
                            type="button"
                            @click="removeLastDigit"
                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-rose-500 transition-colors"
                        >
                            ⌫
                        </button>
                    </div>

                    <!-- Numeric Keypad Grid -->
                    <div class="space-y-4">
                        <div class="grid grid-cols-3 gap-2.5">
                            <template v-for="row in keypadRows" :key="row[0].digit">
                                <button
                                    v-for="btn in row"
                                    :key="btn.digit"
                                    type="button"
                                    @click="appendDigit(btn.digit)"
                                    class="py-3.5 rounded-2xl bg-slate-100 dark:bg-white/5 hover:bg-indigo-500/15 border border-slate-200 dark:border-white/10 text-slate-800 dark:text-white hover:text-indigo-500 hover:border-indigo-500/30 font-mono font-bold flex flex-col items-center justify-center transition-all active:scale-90"
                                >
                                    <span class="text-lg leading-tight">{{ btn.digit }}</span>
                                    <span v-if="btn.sub" class="text-[9px] text-slate-400 font-sans tracking-widest">{{ btn.sub }}</span>
                                </button>
                            </template>
                        </div>

                        <!-- Initiate Call Button: Redirects to /calls with prefilled number for noise cancelling review -->
                        <button
                            type="button"
                            @click="startCall()"
                            :disabled="!destinationInput"
                            class="w-full py-4 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-40 text-white font-black text-sm shadow-xl shadow-emerald-600/30 flex items-center justify-center gap-2.5 transition-all active:scale-95 cursor-pointer"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>{{ __('softphone.dialer.call') }}</span>
                        </button>
                    </div>
                </div>

                <!-- COLUMN 2 (4 Cols): RECENT CALLS LOG -->
                <div class="lg:col-span-4 glass-card p-5 rounded-3xl border border-slate-200 dark:border-white/10 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                        <h3 class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <span>🕒</span>
                            <span>{{ __('softphone.dialer.recent_calls') }}</span>
                        </h3>
                        <Link :href="route('calls.index')" class="text-xs font-bold text-indigo-500 hover:underline">
                            {{ __('ui.layouts.user.view_action') }}
                        </Link>
                    </div>

                    <div v-if="recent_calls.length === 0" class="py-12 text-center text-xs text-slate-400">
                        {{ __('softphone.sms.empty_conversations') }}
                    </div>

                    <div v-else class="space-y-2 max-h-[460px] overflow-y-auto pr-1">
                        <div
                            v-for="call in recent_calls"
                            :key="call.id"
                            class="p-3 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-3 hover:border-indigo-500/30 transition-all"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                                    :class="call.direction === 'outbound' ? 'bg-indigo-500/15 text-indigo-400' : 'bg-emerald-500/15 text-emerald-400'"
                                >
                                    <svg v-if="call.direction === 'outbound'" class="w-4 h-4 rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                    </svg>
                                    <svg v-else class="w-4 h-4 -rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                    </svg>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-mono text-xs font-black text-slate-800 dark:text-white truncate" dir="ltr">
                                        {{ call.direction === 'outbound' ? call.destination : call.source }}
                                    </div>
                                    <div class="text-[10px] text-slate-400 flex items-center gap-2">
                                        <span>{{ call.started_at }}</span>
                                        <span>•</span>
                                        <span>{{ call.duration_formatted }}</span>
                                    </div>
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="startCall(call.direction === 'outbound' ? call.destination : call.source)"
                                class="p-2 rounded-xl bg-emerald-500/15 hover:bg-emerald-500 text-emerald-400 hover:text-white transition-all shrink-0"
                                :title="__('softphone.dialer.call')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- COLUMN 3 (4 Cols): SMS & CONVERSATIONS FOR ACTIVE LINE -->
                <div class="lg:col-span-4 glass-card p-5 rounded-3xl border border-slate-200 dark:border-white/10 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                        <h3 class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                            <span>💬</span>
                            <span>{{ __('softphone.sms.conversations_list') }}</span>
                        </h3>
                        <Link :href="route('messages.index')" class="text-xs font-bold text-indigo-500 hover:underline">
                            {{ __('softphone.sms.new_conversation') }}
                        </Link>
                    </div>

                    <div v-if="recent_conversations.length === 0" class="py-12 text-center text-xs text-slate-400">
                        {{ __('softphone.sms.empty_conversations') }}
                    </div>

                    <div v-else class="space-y-2 max-h-[460px] overflow-y-auto pr-1">
                        <Link
                            v-for="conv in recent_conversations"
                            :key="conv.peer"
                            :href="route('messages.index', { peer: conv.peer, phone_number_id: active_line.id })"
                            class="p-3 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-3 hover:border-indigo-500/30 transition-all block"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-xs font-black text-slate-800 dark:text-white truncate" dir="ltr">
                                        {{ conv.peer }}
                                    </span>
                                    <span v-if="conv.unread_count > 0" class="px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-black">
                                        {{ conv.unread_count }}
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-400 truncate mt-0.5">
                                    {{ conv.last_message || __('softphone.sms.start_conversation') }}
                                </p>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
