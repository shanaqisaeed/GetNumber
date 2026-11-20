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

// Presence State
const currentPresence = ref<'online' | 'busy' | 'dnd' | 'offline'>(props.presence || 'online');
async function setPresence(val: 'online' | 'busy' | 'dnd' | 'offline') {
    currentPresence.value = val;
    try {
        await axios.post(route('softphone.presence'), { presence: val });
    } catch {}
}

// Dialer State
const destinationInput = ref('');
const isContactPickerOpen = ref(false);
const callState = ref<'idle' | 'calling' | 'connected' | 'ended'>('idle');
const activeCallId = ref<number | null>(null);
const callDurationSeconds = ref(0);
let callTimer: ReturnType<typeof setInterval> | null = null;
let pulseTimer: ReturnType<typeof setInterval> | null = null;

// Low Balance 1-minute warning state
const lowBalanceWarning = ref(false);
const lowBalanceSeconds = ref(60);

// Keypad buttons
const keypadRows = [
    [{ digit: '1', sub: '' }, { digit: '2', sub: 'ABC' }, { digit: '3', sub: 'DEF' }],
    [{ digit: '4', sub: 'GHI' }, { digit: '5', sub: 'JKL' }, { digit: '6', sub: 'MNO' }],
    [{ digit: '7', sub: 'PQRS' }, { digit: '8', sub: 'TUV' }, { digit: '9', sub: 'WXYZ' }],
    [{ digit: '*', sub: '' }, { digit: '0', sub: '+' }, { digit: '#', sub: '' }],
];

function appendDigit(digit: string) {
    if (callState.value === 'idle') {
        destinationInput.value += digit;
    }
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
}

// Start Call
async function startCall(dest?: string) {
    const target = dest || destinationInput.value;
    if (!target || !props.active_line) return;

    destinationInput.value = target;
    callState.value = 'calling';
    lowBalanceWarning.value = false;
    callDurationSeconds.value = 0;

    try {
        const payload: any = {
            destination_number: target,
        };
        if (props.active_line.type === 'number') {
            payload.phone_number_id = props.active_line.id;
        } else {
            payload.custom_id = props.active_line.number;
        }

        const res = await axios.post(route('voice.initiate'), payload);
        if (res.data?.success && res.data.call) {
            activeCallId.value = res.data.call.id;
            callState.value = 'connected';
            startTimers();
        } else {
            endCall();
        }
    } catch (err: any) {
        alert(err.response?.data?.message || __('softphone.balance.insufficient_funds_to_start'));
        endCall();
    }
}

function startTimers() {
    callTimer = setInterval(() => {
        callDurationSeconds.value += 1;
    }, 1000);

    pulseTimer = setInterval(async () => {
        if (!activeCallId.value) return;
        try {
            const res = await axios.post(route('voice.pulse', { voiceCall: activeCallId.value }), {
                elapsed_seconds: callDurationSeconds.value,
            });

            if (res.data?.low_balance_warning) {
                lowBalanceWarning.value = true;
                lowBalanceSeconds.value = res.data.seconds_remaining || 60;
            }

            if (res.data?.auto_terminated || !res.data?.can_continue) {
                endCall();
            }
        } catch {
            endCall();
        }
    }, 5000);
}

async function endCall() {
    if (activeCallId.value) {
        try {
            await axios.post(route('voice.end', { voiceCall: activeCallId.value }), {
                duration_seconds: callDurationSeconds.value,
            });
        } catch {}
    }

    if (callTimer) clearInterval(callTimer);
    if (pulseTimer) clearInterval(pulseTimer);
    callState.value = 'idle';
    activeCallId.value = null;
    callDurationSeconds.value = 0;
    lowBalanceWarning.value = false;
    router.reload();
}

const formattedDuration = computed(() => {
    const mins = Math.floor(callDurationSeconds.value / 60);
    const secs = callDurationSeconds.value % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
});

onUnmounted(() => {
    if (callTimer) clearInterval(callTimer);
    if (pulseTimer) clearInterval(pulseTimer);
});
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
            <!-- Top Control Bar (Active Line Chip, Presence Selector, Wallet Balance) -->
            <div class="glass-card p-4 rounded-3xl border border-slate-200 dark:border-white/10 flex flex-wrap items-center justify-between gap-4">
                <!-- Line Info & Switcher Button -->
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-indigo-500/15 text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-slate-400 font-bold">{{ __('softphone.line.active_line') }}:</span>
                            <span class="font-mono font-black text-sm text-slate-800 dark:text-white" dir="ltr">
                                {{ active_line.formatted_number || active_line.number }}
                            </span>
                            <span
                                v-if="active_line.is_organization"
                                class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 font-bold border border-emerald-500/30"
                            >
                                {{ active_line.organization_name || __('organization.dashboard.title') }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="isLineSwitcherOpen = true"
                            class="text-[11px] text-indigo-500 dark:text-indigo-400 font-bold hover:underline inline-flex items-center gap-1 mt-0.5"
                        >
                            <span>{{ __('softphone.line.switch_line') }}</span>
                            <span>⇄</span>
                        </button>
                    </div>
                </div>

                <!-- Presence & Wallet Controls -->
                <div class="flex items-center gap-4">
                    <!-- Presence Dropdown -->
                    <div class="flex items-center gap-1.5 bg-slate-100 dark:bg-white/5 p-1 rounded-2xl border border-slate-200 dark:border-white/10 text-xs font-bold">
                        <button
                            type="button"
                            @click="setPresence('online')"
                            class="px-2.5 py-1 rounded-xl transition-all"
                            :class="currentPresence === 'online' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
                        >
                            ● {{ __('softphone.presence.online') }}
                        </button>
                        <button
                            type="button"
                            @click="setPresence('dnd')"
                            class="px-2.5 py-1 rounded-xl transition-all"
                            :class="currentPresence === 'dnd' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
                        >
                            ⛔ {{ __('softphone.presence.dnd') }}
                        </button>
                    </div>

                    <!-- Wallet Balance Chip -->
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-2xl bg-amber-500/10 border border-amber-500/25">
                        <span class="text-[11px] text-amber-500 font-bold">
                            {{ wallet.is_organization ? __('organization.wallet.title') : __('softphone.nav.quick_wallet') }}:
                        </span>
                        <span class="font-mono font-black text-xs text-amber-500">
                            {{ wallet.balance }} {{ wallet.currency }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Mid-Call 1-Minute Grace Alert Banner -->
            <transition enter-active-class="transition duration-300 ease-out" enter-from-class="opacity-0 -translate-y-2">
                <div
                    v-if="lowBalanceWarning"
                    class="p-4 rounded-3xl bg-rose-500/20 border-2 border-rose-500/50 text-white flex items-center justify-between gap-4 animate-pulse shadow-xl"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div>
                            <p class="text-xs font-black">{{ __('softphone.balance.low_balance_warning_title') }}</p>
                            <p class="text-[11px] text-rose-200">{{ __('softphone.balance.low_balance_warning_desc') }}</p>
                        </div>
                    </div>
                    <Link
                        :href="route('wallet.index')"
                        class="px-3.5 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shrink-0 shadow"
                    >
                        {{ __('softphone.balance.recharge_wallet') }}
                    </Link>
                </div>
            </transition>

            <!-- 3-Column Softphone Layout Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- COLUMN 1 (4 Cols): SMART DIALER & ACTIVE CALL -->
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
                            :disabled="callState !== 'idle'"
                            class="w-full py-3.5 px-4 pr-12 rounded-2xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 font-mono text-base font-black text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 transition-all text-center"
                        />
                        <button
                            v-if="destinationInput && callState === 'idle'"
                            type="button"
                            @click="removeLastDigit"
                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-slate-400 hover:text-rose-500 transition-colors"
                        >
                            ⌫
                        </button>
                    </div>

                    <!-- In-Call Active State Card -->
                    <div v-if="callState !== 'idle'" class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-center space-y-2">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-black animate-pulse">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            <span>{{ callState === 'calling' ? __('softphone.dialer.calling') : __('softphone.dialer.in_call') }}</span>
                        </div>
                        <div class="text-2xl font-mono font-black text-emerald-500">
                            {{ formattedDuration }}
                        </div>
                        <div class="pt-2">
                            <button
                                type="button"
                                @click="endCall"
                                class="w-full py-3 rounded-2xl bg-rose-600 hover:bg-rose-500 text-white font-black text-xs shadow-lg shadow-rose-600/30 transition-all active:scale-95"
                            >
                                {{ __('softphone.dialer.ending') }}
                            </button>
                        </div>
                    </div>

                    <!-- Numeric Keypad Grid -->
                    <div v-else class="space-y-4">
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

                        <!-- Initiate Call Button -->
                        <button
                            type="button"
                            @click="startCall()"
                            :disabled="!destinationInput"
                            class="w-full py-4 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-40 text-white font-black text-sm shadow-xl shadow-emerald-600/30 flex items-center justify-center gap-2.5 transition-all active:scale-95"
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
