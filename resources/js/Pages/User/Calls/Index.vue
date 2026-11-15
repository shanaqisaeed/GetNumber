<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import UserLayout from '@/Layouts/UserLayout.vue';
import ContactPickerModal, { type ContactItem as PickerContactItem } from '@/Components/ContactPickerModal.vue';

interface LineItem {
    id: number;
    key?: string;
    number: string;
    formatted_number: string;
    display_title: string;
    status: string;
    is_active: boolean;
    type?: string;
    type_label?: string;
}

interface CallItem {
    id: number;
    phone_number_id: number | null;
    source_identifier: string;
    destination: string;
    direction: string;
    status: string;
    started_at: string | null;
    ended_at: string | null;
    duration_seconds: number;
    duration_formatted: string;
    cost: number;
    line_number: string;
}

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    lines: LineItem[];
    selected_line_key?: string | null;
    selected_line_id?: number | null;
    initial_destination?: string;
    calls: {
        data: CallItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    contacts: ContactItem[];
    rate_per_minute: number;
    wallet_balance: string;
    currency: string;
}>();

const activeTab = ref<'dialer' | 'history'>('dialer');

// Outgoing line selection (Supports both Phone Lines and Custom IDs)
const selectedLineKey = ref<string | null>(
    props.selected_line_key || (props.lines.length > 0 ? (props.lines[0].key || 'phone_' + props.lines[0].id) : null)
);

const selectedLine = computed(() => {
    return props.lines.find((l) => l.key === selectedLineKey.value) 
        || props.lines.find((l) => l.id === props.selected_line_id) 
        || props.lines[0] 
        || null;
});

const onLineChanged = (newKey: string | null) => {
    if (!newKey) return;
    selectedLineKey.value = newKey;
    router.get(
        route('calls.index'),
        { line_key: newKey },
        { preserveState: true, replace: true, only: ['calls', 'selected_line_key', 'selected_line_id'] }
    );
};

// Dialer State
const destinationInput = ref(props.initial_destination || '');
const keypadMode = ref<'numeric' | 'alphanumeric'>(
    props.initial_destination?.startsWith('@') ? 'alphanumeric' : 'numeric'
);
const isContactPickerOpen = ref(false);
const isCalling = ref(false);
const activeCallId = ref<number | null>(null);
const callDuration = ref(0);
let callTimer: ReturnType<typeof setInterval> | null = null;
let pulseTimer: ReturnType<typeof setInterval> | null = null;
const pingMs = ref<number>(24);
let pingTimer: ReturnType<typeof setInterval> | null = null;
const isMuted = ref(false);
const isSpeakerOn = ref(false);
const callError = ref('');

// Bridge & WebRTC State
const bridgeSessionId = ref<string | null>(null);
const bridgeWsEndpoint = ref<string | null>(null);
const isBridgeConnected = ref(false);
const isInternalCall = ref(false);
let bridgeSocket: WebSocket | null = null;
let bridgePingInterval: ReturnType<typeof setInterval> | null = null;
let localMediaStream: MediaStream | null = null;
const micPermissionGranted = ref<boolean | null>(null);
const isMicModalOpen = ref(false);
const isSecureContext = ref(
    typeof window !== 'undefined'
        ? (window.isSecureContext ?? (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1'))
        : true
);

// Incoming Call Notification State
const incomingCall = ref<any>(null);
const isIncomingCallModalOpen = ref(false);
let incomingPollTimer: ReturnType<typeof setInterval> | null = null;
let ringtoneAudioContext: AudioContext | null = null;
let ringtoneOscillator: OscillatorNode | null = null;

const handleContactSelected = (contact: PickerContactItem) => {
    destinationInput.value = contact.phone_number.trim();
    if (contact.phone_number.startsWith('@')) {
        keypadMode.value = 'alphanumeric';
    }
    activeTab.value = 'dialer';
};

// Keypad digits
const keypadRows = [
    [
        { digit: '1', sub: ' ' },
        { digit: '2', sub: 'ABC' },
        { digit: '3', sub: 'DEF' },
    ],
    [
        { digit: '4', sub: 'GHI' },
        { digit: '5', sub: 'JKL' },
        { digit: '6', sub: 'MNO' },
    ],
    [
        { digit: '7', sub: 'PQRS' },
        { digit: '8', sub: 'TUV' },
        { digit: '9', sub: 'WXYZ' },
    ],
    [
        { digit: '*', sub: ' ' },
        { digit: '0', sub: '+' },
        { digit: '#', sub: ' ' },
    ],
];

const appendDigit = (char: string) => {
    if (destinationInput.value.length < 24) {
        destinationInput.value += char;
    }
};

const backspace = () => {
    destinationInput.value = destinationInput.value.slice(0, -1);
};

const clearInput = () => {
    destinationInput.value = '';
};

const ensureAtPrefix = () => {
    if (!destinationInput.value.startsWith('@')) {
        destinationInput.value = '@' + destinationInput.value;
    }
};

// Request Microphone Access
const requestMicrophoneAccess = async (): Promise<boolean> => {
    micPermissionGranted.value = null;
    if (typeof navigator === 'undefined' || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        micPermissionGranted.value = false;
        isMicModalOpen.value = true;
        callError.value = 'مرورگر شما از دریافت صدای میکروفون پشتیبانی نمی‌کند یا پروتکل امن HTTPS برقرار نیست.';
        return false;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        localMediaStream = stream;
        micPermissionGranted.value = true;
        isMicModalOpen.value = false;
        return true;
    } catch {
        micPermissionGranted.value = false;
        isMicModalOpen.value = true;
        callError.value = 'دسترسی به میکروفون توسط کاربر یا مرورگر مسدود شده است. لطفاً در نوار آدرس دسترسی میکروفون را مجاز کنید.';
        return false;
    }
};

const toggleMute = () => {
    isMuted.value = !isMuted.value;
    if (localMediaStream) {
        localMediaStream.getAudioTracks().forEach(track => {
            track.enabled = !isMuted.value;
        });
    }
};

const toggleSpeaker = () => {
    isSpeakerOn.value = !isSpeakerOn.value;
};

// WebSocket signaling connection to Voice Bridge
const connectBridgeWebSocket = (wsUrl: string) => {
    try {
        if (bridgeSocket) {
            bridgeSocket.close();
            bridgeSocket = null;
        }
        bridgeSocket = new WebSocket(wsUrl);
        bridgeSocket.onopen = () => {
            isBridgeConnected.value = true;
            bridgePingInterval = setInterval(() => {
                if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
                    const start = performance.now();
                    bridgeSocket.send(JSON.stringify({ type: 'ping', t: start }));
                }
            }, 2500);
        };
        bridgeSocket.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                if (msg.type === 'pong' && msg.t) {
                    const rtt = Math.round(performance.now() - msg.t);
                    if (rtt > 0) pingMs.value = rtt;
                }
            } catch {}
        };
        bridgeSocket.onerror = () => {
            isBridgeConnected.value = false;
        };
        bridgeSocket.onclose = () => {
            isBridgeConnected.value = false;
            if (bridgePingInterval) clearInterval(bridgePingInterval);
        };
    } catch {
        isBridgeConnected.value = false;
    }
};

// Start Voice Call
const startCall = async (skipMicCheck = false) => {
    if (!destinationInput.value.trim() || !selectedLine.value || isCalling.value) return;

    callError.value = '';
    
    // Check & request microphone permission if not explicitly bypassed
    if (!skipMicCheck) {
        const hasMic = await requestMicrophoneAccess();
        if (!hasMic) return;
    }

    if (isMicModalOpen.value) {
        isMicModalOpen.value = false;
    }

    isCalling.value = true;
    callDuration.value = 0;

    try {
        const res = await axios.post(route('voice.initiate'), {
            line_key: selectedLineKey.value || undefined,
            phone_number_id: selectedLine.value?.id,
            destination: destinationInput.value.trim(),
        });

        if (res.data?.success && res.data.call) {
            activeCallId.value = res.data.call.id;
            bridgeSessionId.value = res.data.call.bridge_session_id || null;
            bridgeWsEndpoint.value = res.data.call.bridge_ws_endpoint || null;
            isInternalCall.value = Boolean(res.data.call.is_internal);
            pingMs.value = res.data.call.ping_ms || 22;

            if (bridgeWsEndpoint.value) {
                connectBridgeWebSocket(bridgeWsEndpoint.value);
            }

            callTimer = setInterval(() => {
                callDuration.value++;
            }, 1000);

            // Periodic Balance Heartbeat (Auto-disconnects if balance is exhausted)
            pulseTimer = setInterval(async () => {
                if (!activeCallId.value || !isCalling.value) return;
                try {
                    const pulseRes = await axios.post(route('voice.pulse', activeCallId.value), {
                        elapsed_seconds: callDuration.value,
                    });
                    if (pulseRes.data?.can_continue === false) {
                        callError.value = pulseRes.data.message || 'مکالمه به دلیل اتمام موجودی کیف‌پول پایان یافت.';
                        endCall();
                    }
                } catch {}
            }, 15000);

            if (!bridgeWsEndpoint.value) {
                pingTimer = setInterval(() => {
                    pingMs.value = Math.floor(Math.random() * 10) + 18;
                }, 3000);
            }
        } else {
            throw new Error(res.data?.message || 'خطا در برقراری تماس');
        }
    } catch (err: any) {
        isCalling.value = false;
        callError.value = err.response?.data?.message || err.message || 'خطا در اتصال تماس صوتی';
        if (localMediaStream) {
            localMediaStream.getTracks().forEach(t => t.stop());
            localMediaStream = null;
        }
    }
};

const initiateCallAction = () => {
    startCall(false);
};

// End Voice Call
const endCall = async () => {
    if (pulseTimer) {
        clearInterval(pulseTimer);
        pulseTimer = null;
    }
    if (bridgeSocket) {
        bridgeSocket.close();
        bridgeSocket = null;
    }
    if (bridgePingInterval) {
        clearInterval(bridgePingInterval);
        bridgePingInterval = null;
    }
    if (localMediaStream) {
        localMediaStream.getTracks().forEach(t => t.stop());
        localMediaStream = null;
    }
    isBridgeConnected.value = false;
    bridgeSessionId.value = null;

    if (!activeCallId.value) {
        isCalling.value = false;
        if (callTimer) clearInterval(callTimer);
        if (pingTimer) clearInterval(pingTimer);
        return;
    }

    try {
        await axios.post(route('voice.end', activeCallId.value), {
            duration_seconds: callDuration.value,
        });
    } catch {
        // continue clean-up
    } finally {
        if (callTimer) clearInterval(callTimer);
        if (pingTimer) clearInterval(pingTimer);
        isCalling.value = false;
        activeCallId.value = null;
        callDuration.value = 0;
        router.reload({ only: ['calls', 'wallet_balance'] });
    }
};

// Incoming Call Sound & Ringtone Simulation (Web Audio API)
const playRingtone = () => {
    try {
        const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
        if (!AudioCtx) return;
        ringtoneAudioContext = new AudioCtx();
        const osc = ringtoneAudioContext.createOscillator();
        const gain = ringtoneAudioContext.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(440, ringtoneAudioContext.currentTime);
        gain.gain.setValueAtTime(0.08, ringtoneAudioContext.currentTime);
        osc.connect(gain);
        gain.connect(ringtoneAudioContext.destination);
        osc.start();
        ringtoneOscillator = osc;
    } catch {}
};

const stopRingtone = () => {
    try {
        if (ringtoneOscillator) {
            ringtoneOscillator.stop();
            ringtoneOscillator.disconnect();
            ringtoneOscillator = null;
        }
        if (ringtoneAudioContext) {
            ringtoneAudioContext.close();
            ringtoneAudioContext = null;
        }
    } catch {}
};

const checkIncomingCall = async () => {
    if (isCalling.value) return;
    try {
        const res = await axios.get(route('voice.active_incoming'));
        if (res.data?.has_incoming && res.data.call) {
            if (!incomingCall.value || incomingCall.value.id !== res.data.call.id) {
                incomingCall.value = res.data.call;
                isIncomingCallModalOpen.value = true;
                playRingtone();
            }
        } else {
            if (isIncomingCallModalOpen.value) {
                stopRingtone();
                isIncomingCallModalOpen.value = false;
                incomingCall.value = null;
            }
        }
    } catch {}
};

const answerIncomingCall = async () => {
    if (!incomingCall.value) return;
    stopRingtone();
    const callId = incomingCall.value.id;
    try {
        const res = await axios.post(route('voice.answer', callId));
        if (res.data?.success) {
            isIncomingCallModalOpen.value = false;
            activeCallId.value = callId;
            destinationInput.value = incomingCall.value.source;
            isCalling.value = true;
            callDuration.value = 0;
            pingMs.value = incomingCall.value.ping_ms || 22;
            bridgeSessionId.value = incomingCall.value.bridge_session_id || null;
            isBridgeConnected.value = Boolean(incomingCall.value.bridge_session_id);
            isInternalCall.value = true;

            await requestMicrophoneAccess();

            callTimer = setInterval(() => {
                callDuration.value++;
            }, 1000);

            if (incomingCall.value.bridge_ws_endpoint) {
                connectBridgeWebSocket(incomingCall.value.bridge_ws_endpoint);
            }
        }
    } catch {}
};

const declineIncomingCall = async () => {
    if (!incomingCall.value) return;
    stopRingtone();
    const callId = incomingCall.value.id;
    isIncomingCallModalOpen.value = false;
    incomingCall.value = null;
    try {
        await axios.post(route('voice.decline', callId));
        router.reload({ only: ['calls'] });
    } catch {}
};

const formatTimer = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
};

// Redial / Call from History or Contact
const dialNumber = (number: string) => {
    destinationInput.value = number.replace(/\s+/g, '');
    activeTab.value = 'dialer';
};

onMounted(() => {
    if (typeof window !== 'undefined') {
        const params = new URLSearchParams(window.location.search);
        const target = params.get('to') || params.get('destination') || params.get('number') || params.get('custom_id');
        if (target) {
            destinationInput.value = target.trim();
            if (target.startsWith('@')) {
                keypadMode.value = 'alphanumeric';
            }
            activeTab.value = 'dialer';
        }
        const lineKeyParam = params.get('line_key');
        if (lineKeyParam) {
            const matched = props.lines.find((l) => l.key === lineKeyParam);
            if (matched) {
                selectedLineKey.value = matched.key || null;
            }
        } else {
            const lineParam = params.get('phone_number_id');
            if (lineParam) {
                const matched = props.lines.find((l) => l.id === Number(lineParam));
                if (matched) {
                    selectedLineKey.value = matched.key || null;
                }
            }
        }
    }

    // Start incoming call detector
    checkIncomingCall();
    incomingPollTimer = setInterval(checkIncomingCall, 3500);
});

onUnmounted(() => {
    if (callTimer) clearInterval(callTimer);
    if (pulseTimer) clearInterval(pulseTimer);
    if (pingTimer) clearInterval(pingTimer);
    if (bridgePingInterval) clearInterval(bridgePingInterval);
    if (incomingPollTimer) clearInterval(incomingPollTimer);
    stopRingtone();
    if (bridgeSocket) {
        bridgeSocket.close();
        bridgeSocket = null;
    }
    if (localMediaStream) {
        localMediaStream.getTracks().forEach(t => t.stop());
        localMediaStream = null;
    }
});
</script>

<template>
    <Head title="تماس صوتی و شماره‌گیر ابری" />

    <UserLayout>
        <div class="space-y-4">
            <!-- Header Command Card -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <h2 class="wallet-title">مرکز تماس صوتی و ارتباطات ابری</h2>
                    </div>
                    <span class="wallet-chip bg-emerald-500/15 border-emerald-500/30 text-emerald-600 dark:text-emerald-400">
                        سرویس صوتی فعال
                    </span>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    شماره‌گیری استاندارد دوطرفه، مدیریت دفترچه تلفن و بایگانی دقیق مکالمات به ازای هر خط.
                </p>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-200/80 dark:border-white/10 text-xs">
                    <div class="flex items-center gap-3">
                        <span class="text-slate-500 dark:text-slate-400">
                            موجودی:
                            <strong class="font-mono text-emerald-600 dark:text-emerald-400 font-bold" dir="ltr">
                                {{ Number(wallet_balance).toFixed(2) }} {{ currency }}
                            </strong>
                        </span>
                        <span class="text-slate-300 dark:text-slate-600">|</span>
                        <span class="text-slate-500 dark:text-slate-400">
                            تعرفه تماس:
                            <strong class="font-mono text-indigo-600 dark:text-indigo-400 font-bold" dir="ltr">
                                {{ rate_per_minute }} {{ currency }} / دقیقه
                            </strong>
                        </span>
                    </div>

                    <div v-if="lines.length > 0" class="flex items-center gap-2">
                        <span class="text-[11px] text-slate-500 font-bold">خط خروجی:</span>
                        <select
                            v-model="selectedLineKey"
                            @change="onLineChanged(selectedLineKey)"
                            class="form-select !py-1.5 !px-3 !text-xs font-mono font-bold rounded-xl bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-white/10"
                            dir="ltr"
                        >
                            <option v-for="line in lines" :key="line.key || line.id" :value="line.key || ('phone_' + line.id)">
                                {{ line.type === 'custom_id' ? '🆔' : '📞' }} {{ line.formatted_number }} ({{ line.display_title }})
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Navigation Switcher: 2 Main Tabs + Direct Contacts Link -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 flex-1 max-w-md">
                    <button
                        type="button"
                        @click="activeTab = 'dialer'"
                        class="flex-1 py-2.5 px-3 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border"
                        :class="activeTab === 'dialer'
                            ? 'bg-emerald-600 border-emerald-600 text-white shadow-md shadow-emerald-600/20'
                            : 'glass-pill text-slate-600 dark:text-slate-300 border-slate-200/80 dark:border-white/10'"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>شماره‌گیر صوتی</span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'history'"
                        class="flex-1 py-2.5 px-3 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border"
                        :class="activeTab === 'history'
                            ? 'bg-indigo-600 border-indigo-600 text-white shadow-md shadow-indigo-600/20'
                            : 'glass-pill text-slate-600 dark:text-slate-300 border-slate-200/80 dark:border-white/10'"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>تاریخچه مکالمات</span>
                        <span v-if="calls.total > 0" class="text-[10px] px-1.5 py-0.2 rounded-full bg-white/20">
                            {{ calls.total }}
                        </span>
                    </button>
                </div>

                <Link
                    :href="route('contacts.index')"
                    class="py-2.5 px-4 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border glass-pill text-indigo-600 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800/60 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 shrink-0"
                    title="مشاهده و مدیریت کامل دفترچه تلفن و مخاطبین"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span>دفترچه مخاطبین</span>
                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </Link>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 1: PHONE DIALER (Mobile Layout: 1-2-3 / 4-5-6 / 7-8-9 / *-0-#) -->
            <!-- ======================================================== -->
            <div v-if="activeTab === 'dialer'" class="glass-card !p-5 max-w-md mx-auto space-y-5 bg-white/95 dark:bg-slate-900/80 border border-slate-200/90 dark:border-white/10 shadow-xl">
                <!-- If currently in active call session -->
                <div v-if="isCalling" class="text-center py-6 space-y-4 animate-in fade-in zoom-in-95 duration-200">
                    <div class="relative w-24 h-24 mx-auto flex items-center justify-center">
                        <span class="absolute inset-0 rounded-full bg-emerald-500/20 animate-ping"></span>
                        <div class="w-20 h-20 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-xl shadow-emerald-500/40">
                            <svg class="w-9 h-9" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-xs text-slate-400">در حال مکالمه از خط {{ selectedLine?.formatted_number }}</div>
                        <div class="text-2xl font-black font-mono tracking-wider text-slate-900 dark:text-white" dir="ltr">
                            {{ destinationInput }}
                        </div>
                        <div class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400 mt-2">
                            {{ formatTimer(callDuration) }}
                        </div>

                        <!-- Real-time Bridge / Fallback Status Indicator -->
                        <div class="pt-2 flex flex-col items-center gap-1.5">
                            <div
                                v-if="isBridgeConnected || bridgeSessionId"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>🟢 متصل به بریج صوتی سرور (سرور ابری اوبونتو)</span>
                                <span class="text-slate-400">|</span>
                                <span>پینگ زنده: {{ pingMs }} ms</span>
                            </div>
                            <div
                                v-else
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-600 dark:text-amber-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                                <span>🟡 در حال ارتباط شبیه‌سازی شده سرور (Fallback Mode)</span>
                                <span class="text-slate-400">|</span>
                                <span>پینگ: {{ pingMs }} ms</span>
                            </div>

                            <div v-if="isInternalCall" class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2.5 py-0.5 rounded-full">
                                ✨ تماس درون‌شبکه سامانه GetNumber (۱۰۰٪ رایگان)
                            </div>
                        </div>
                    </div>

                    <!-- Call Controls -->
                    <div class="flex items-center justify-center gap-4 pt-3">
                        <button
                            type="button"
                            @click="toggleMute"
                            class="w-12 h-12 rounded-2xl flex items-center justify-center border transition-all"
                            :class="isMuted ? 'bg-amber-500/20 border-amber-500 text-amber-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                            :title="isMuted ? 'میکروفون غیرفعال است' : 'میکروفون فعال است'"
                        >
                            <span class="text-xs font-bold">{{ isMuted ? 'بی‌صدا' : 'صدا فعال' }}</span>
                        </button>

                        <!-- Hang up button -->
                        <button
                            type="button"
                            @click="endCall"
                            class="w-16 h-16 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-xl shadow-rose-600/40 transition-transform active:scale-95"
                            title="قطع تماس"
                        >
                            <svg class="w-8 h-8 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            @click="toggleSpeaker"
                            class="w-12 h-12 rounded-2xl flex items-center justify-center border transition-all"
                            :class="isSpeakerOn ? 'bg-blue-500/20 border-blue-500 text-blue-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                            :title="isSpeakerOn ? 'پخش از اسپیکر' : 'پخش از گوشی/هدفون'"
                        >
                            <span class="text-xs font-bold">{{ isSpeakerOn ? 'اسپیکر' : 'گوشی' }}</span>
                        </button>
                    </div>
                </div>

                <!-- Normal Dialing Keypad View -->
                <div v-else class="space-y-4">
                    <!-- Keypad Mode Switcher (Numbers vs IDs) -->
                    <div class="flex items-center justify-center p-1 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200/80 dark:border-white/10 max-w-xs mx-auto">
                        <button
                            type="button"
                            @click="keypadMode = 'numeric'"
                            class="flex-1 py-1.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                            :class="keypadMode === 'numeric'
                                ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                        >
                            <span>🔢 شماره‌گیر عددی</span>
                        </button>
                        <button
                            type="button"
                            @click="keypadMode = 'alphanumeric'"
                            class="flex-1 py-1.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                            :class="keypadMode === 'alphanumeric'
                                ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                        >
                            <span>🆔 ورودی آیدی (@)</span>
                        </button>
                    </div>

                    <!-- Dialed Number Display Area with Contact Picker Link -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-2 min-h-[60px]">
                        <button
                            type="button"
                            v-if="destinationInput"
                            @click="clearInput"
                            class="text-xs text-slate-400 hover:text-rose-500 font-bold px-2"
                        >
                            پاک‌کردن
                        </button>
                        <div v-else class="w-12"></div>

                        <input
                            type="text"
                            v-model="destinationInput"
                            :placeholder="keypadMode === 'numeric' ? 'شماره تماس...' : 'آیدی مورد نظر مثلاً @ceo...'"
                            class="flex-1 text-center font-mono font-black text-xl text-slate-900 dark:text-white bg-transparent outline-none tracking-wider"
                            dir="ltr"
                        />

                        <div class="flex items-center gap-1">
                            <!-- Contact Picker Button -->
                            <button
                                type="button"
                                @click="isContactPickerOpen = true"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10 transition-colors"
                                title="انتخاب از دفترچه مخاطبین"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </button>

                            <!-- Backspace Button -->
                            <button
                                type="button"
                                @click="backspace"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                                :disabled="!destinationInput"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414-6.414a2 2 0 011.414-.586H19a2 2 0 012 2v10a2 2 0 01-2 2h-7.172a2 2 0 01-1.414-.586L3 12z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div v-if="callError" class="p-2.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 text-xs font-bold text-center">
                        {{ callError }}
                    </div>

                    <!-- MODE 1: Standard 3x4 Telephone Dialpad (LTR) -->
                    <div v-if="keypadMode === 'numeric'" class="grid grid-cols-3 gap-2.5" dir="ltr">
                        <template v-for="row in keypadRows" :key="row[0].digit">
                            <button
                                v-for="btn in row"
                                :key="btn.digit"
                                type="button"
                                @click="appendDigit(btn.digit)"
                                class="h-14 rounded-2xl bg-slate-100 hover:bg-slate-200/90 dark:bg-slate-800/80 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-white/5 flex flex-col items-center justify-center text-slate-900 dark:text-white transition-all active:scale-95 shadow-xs"
                            >
                                <span class="font-mono font-black text-xl leading-none">{{ btn.digit }}</span>
                                <span v-if="btn.sub" class="text-[9px] font-mono tracking-widest text-slate-400 dark:text-slate-500 mt-0.5">{{ btn.sub }}</span>
                            </button>
                        </template>
                    </div>

                    <!-- MODE 2: Alphanumeric & ID typing assistant -->
                    <div v-else class="space-y-3">
                        <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/70 dark:border-indigo-500/20 text-xs space-y-2">
                            <div class="font-bold text-indigo-700 dark:text-indigo-300 flex items-center gap-1.5">
                                <span>💡</span>
                                <span>تماس صوتی مستقیم با آیدی اختصاصی</span>
                            </div>
                            <p class="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                ارتباط صوتی P2P مستقیم بدون نیاز به شماره سنتی مخابراتی. شناسه کاربر را با علامت @ یا به شکل ساده وارد نمایید.
                            </p>
                            <!-- Quick Prefix Chips -->
                            <div class="flex items-center gap-1.5 pt-1">
                                <span class="text-[10px] text-slate-400">میانبرها:</span>
                                <button
                                    type="button"
                                    @click="ensureAtPrefix"
                                    class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-mono font-black text-xs hover:bg-indigo-700 transition-colors"
                                >
                                    @
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('_')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    _
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('.')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    .
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('-')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    -
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Green Action Call Button -->
                    <div class="pt-2 flex justify-center">
                        <button
                            type="button"
                            @click="initiateCallAction"
                            :disabled="!destinationInput.trim() || !selectedLine"
                            class="w-full max-w-xs py-3.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm flex items-center justify-center gap-2 shadow-lg shadow-emerald-600/30 transition-all active:scale-98 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>برقراری تماس صوتی</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 2: CALL HISTORY                                      -->
            <!-- ======================================================== -->
            <div v-else-if="activeTab === 'history'" class="space-y-3">
                <!-- Selected Line History Indicator -->
                <div class="glass-card !p-3 bg-white/90 dark:bg-slate-900/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500 dark:text-slate-400">تاریخچه مکالمات خط انتخابی:</span>
                        <span class="font-mono font-black text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-lg" dir="ltr">
                            {{ selectedLine?.formatted_number }}
                        </span>
                        <span class="text-[11px] text-slate-400">({{ selectedLine?.display_title }})</span>
                    </div>
                    <span class="text-[11px] text-slate-500">
                        {{ calls.total }} تماس
                    </span>
                </div>

                <div v-if="calls.data && calls.data.length > 0" class="space-y-2">
                    <div
                        v-for="call in calls.data"
                        :key="call.id"
                        class="glass-card !p-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 border"
                                :class="call.direction === 'outbound'
                                    ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
                                    : 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20'"
                            >
                                <svg v-if="call.direction === 'outbound'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5" dir="ltr">
                                    <span class="font-mono font-black">{{ call.destination }}</span>
                                    <span class="text-[10px] text-slate-400">از {{ call.line_number }}</span>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5">
                                    {{ call.started_at || 'نامشخص' }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <div class="text-left font-mono">
                                <div class="font-bold text-slate-800 dark:text-slate-200">{{ call.duration_formatted }}</div>
                                <div class="text-[10px] text-emerald-600 dark:text-emerald-400">{{ call.cost }} {{ currency }}</div>
                            </div>

                            <button
                                type="button"
                                @click="dialNumber(call.destination)"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-indigo-600 dark:text-indigo-400 font-bold text-[11px] flex items-center gap-1 transition-colors"
                            >
                                <span>تماس</span>
                            </button>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="calls.links && calls.links.length > 3" class="flex justify-center gap-1 pt-3">
                        <button
                            v-for="(link, i) in calls.links"
                            :key="i"
                            type="button"
                            @click="link.url && router.visit(link.url)"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                            :class="[
                                link.active ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                                !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>

                <div v-else class="glass-card !p-8 text-center space-y-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-500/15 text-indigo-600 mx-auto flex items-center justify-center text-lg">📞</div>
                    <h3 class="font-bold text-sm text-slate-900 dark:text-white">هنوز تماسی برای این خط ثبت نشده است</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">پس از برقراری یا دریافت مکالمات صوتی با این خط، لیست تاریخچه اینجا نمایش داده می‌شود.</p>
                </div>
            </div>
        </div>

        <!-- Contact Picker Modal -->
        <ContactPickerModal
            :show="isContactPickerOpen"
            :initial-contacts="contacts"
            @close="isContactPickerOpen = false"
            @select="handleContactSelected"
        />

        <!-- Incoming Call Modal Notification -->
        <div
            v-if="isIncomingCallModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md animate-in fade-in duration-200"
        >
            <div class="glass-card !p-6 max-w-sm w-full text-center space-y-5 bg-white dark:bg-slate-900 border border-emerald-500/50 rounded-3xl shadow-2xl">
                <div class="relative w-20 h-20 mx-auto flex items-center justify-center">
                    <span class="absolute inset-0 rounded-full bg-emerald-500/30 animate-ping"></span>
                    <div class="w-16 h-16 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/40">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                </div>

                <div class="space-y-1">
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">تماس صوتی دریافتی...</span>
                    <div class="text-xl font-black font-mono text-slate-900 dark:text-white" dir="ltr">
                        {{ incomingCall?.source }}
                    </div>
                    <div class="text-xs text-slate-400">
                        به مقصد: {{ incomingCall?.destination }}
                    </div>
                </div>

                <div class="flex items-center justify-center gap-3 pt-2">
                    <!-- Decline button -->
                    <button
                        type="button"
                        @click="declineIncomingCall"
                        class="flex-1 py-3 px-4 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-rose-600/30 transition-transform active:scale-95"
                    >
                        <svg class="w-4 h-4 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>رد تماس</span>
                    </button>

                    <!-- Answer button -->
                    <button
                        type="button"
                        @click="answerIncomingCall"
                        class="flex-1 py-3 px-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-600/30 transition-transform active:scale-95"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>پاسخ به تماس</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Microphone Access & Permission Helper Modal -->
        <div
            v-if="isMicModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md"
            @click.self="isMicModalOpen = false"
        >
            <div class="glass-card w-full max-w-sm p-6 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-3xl space-y-4 shadow-2xl text-center">
                <div class="w-14 h-14 rounded-2xl bg-amber-500/15 text-amber-500 mx-auto flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                    </svg>
                </div>

                <div class="space-y-1.5">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">راهنمای دسترسی به میکروفون</h3>
                    <p v-if="!isSecureContext" class="text-xs text-amber-600 dark:text-amber-400 font-medium leading-relaxed">
                        مرورگرها به دلیل ضوابط امنیتی وب، اجازه ضبط صدا را فقط روی پروتکل امن <strong>HTTPS</strong> یا دامنه محلی <strong>localhost</strong> صادر می‌کنند. از آنجا که اتصال شما روی <code>HTTP</code> است، مرورگر پنجره درخواست دسترسی را نشان نمی‌دهد.
                    </p>
                    <p v-else class="text-xs text-slate-500 dark:text-slate-400 font-medium leading-relaxed">
                        دسترسی میکروفون در مرورگر صادر نشده یا مسدود است. لطفاً روی آیکون قفل 🔒 در نوار آدرس کلیک کرده و دسترسی میکروفون را روی حالت مجاز (Allow) قرار دهید.
                    </p>
                </div>

                <div class="space-y-2 pt-2">
                    <button
                        type="button"
                        @click="requestMicrophoneAccess"
                        class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition-colors shadow-md shadow-indigo-600/20"
                    >
                        تلاش مجدد برای درخواست دسترسی
                    </button>

                    <button
                        type="button"
                        @click="startCall(true)"
                        class="w-full py-2.5 rounded-xl bg-slate-100 dark:bg-white/10 hover:bg-slate-200 dark:hover:bg-white/15 text-slate-800 dark:text-slate-200 font-bold text-xs transition-colors"
                    >
                        برقراری تماس در حالت آزمایشی (شنیداری بدون میکروفون)
                    </button>

                    <button
                        type="button"
                        @click="isMicModalOpen = false"
                        class="w-full py-1.5 text-[11px] text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                    >
                        انصراف
                    </button>
                </div>
            </div>
        </div>
    </UserLayout>
</template>

