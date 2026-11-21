<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { CalmRingtone } from '@/Utils/ringtone';
import { __ } from '@/Composables/useTrans';
import { useSoftphoneSync } from '@/Composables/useSoftphoneSync';

interface IncomingCallPayload {
    id: number;
    source: string;
    destination?: string;
    status?: string;
}

const page = usePage();
const user = computed(() => page.props.auth?.user);
const incomingCall = ref<IncomingCallPayload | null>(null);
const ringtone = new CalmRingtone();
let pollTimer: ReturnType<typeof setInterval> | null = null;
const { broadcastCallAnswered, broadcastCallEnded } = useSoftphoneSync();

const checkActiveIncomingCall = async () => {
    // When the user is already on the dedicated Calls page, Calls/Index.vue handles calls directly
    if (typeof window !== 'undefined' && window.location.pathname.startsWith('/calls')) {
        if (incomingCall.value) {
            ringtone.stop();
            incomingCall.value = null;
        }
        return;
    }

    if (!user.value?.id) return;

    try {
        const res = await axios.get(route('voice.active_incoming'));
        if (res.data?.has_incoming && res.data.call) {
            const call = res.data.call;
            if (!incomingCall.value || incomingCall.value.id !== call.id) {
                incomingCall.value = {
                    id: call.id,
                    source: call.source || __('user.calls.incoming_call_fallback'),
                    destination: call.destination || '',
                    status: call.status,
                };
                ringtone.play();

                if (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'granted') {
                    try {
                        const notif = new Notification(__('ui.layouts.user.incoming_call_notif_title'), {
                            body: __('ui.layouts.user.incoming_call_notif_body', { caller: incomingCall.value.source, line: incomingCall.value.destination || '' }),
                            icon: '/icon-192.png',
                            badge: '/icon-192.png',
                            tag: 'incoming-call-' + call.id,
                            requireInteraction: true,
                        });
                        notif.onclick = () => {
                            notif.close();
                            window.focus();
                            answerIncoming(false);
                        };
                    } catch {}
                }
            }
        } else {
            if (incomingCall.value) {
                ringtone.stop();
                incomingCall.value = null;
            }
        }
    } catch {}
};

const answerIncoming = (withVideo = false) => {
    if (!incomingCall.value) return;
    const call = incomingCall.value;
    ringtone.stop();
    broadcastCallAnswered(call.id);
    const callId = call.id;
    const caller = call.source || '';
    const line = call.destination || '';
    incomingCall.value = null;

    router.visit(route('calls.index', {
        incoming_call_id: callId,
        active_call_id: callId,
        auto_answer: 1,
        with_video: withVideo ? 1 : 0,
        caller: caller,
        line: line,
    }));
};

const declineIncoming = async () => {
    if (!incomingCall.value) return;
    ringtone.stop();
    const callId = incomingCall.value.id;
    incomingCall.value = null;
    try {
        await axios.post(route('voice.decline', { voiceCall: callId }));
        broadcastCallEnded();
    } catch {}
};

onMounted(() => {
    checkActiveIncomingCall();
    pollTimer = setInterval(checkActiveIncomingCall, 3500);

    if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            const data = event.data;
            if (data?.type === 'incoming_call_push') {
                checkActiveIncomingCall();
            } else if (data?.type === 'call_declined_from_notification') {
                ringtone.stop();
                incomingCall.value = null;
            } else if (data?.type === 'answer_incoming_call') {
                if (data.callId) {
                    answerIncoming(false);
                }
            }
        });
    }
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    ringtone.stop();
});
</script>

<template>
    <div
        v-if="incomingCall"
        class="fixed inset-0 z-[150] overflow-y-auto flex items-center justify-center p-3 sm:p-4 bg-slate-950/85 backdrop-blur-lg animate-in fade-in duration-300"
    >
        <div class="glass-card w-full max-w-sm sm:max-w-md p-6 sm:p-8 bg-white/95 dark:bg-slate-900/95 border border-slate-200 dark:border-white/10 rounded-3xl space-y-6 shadow-2xl text-center relative overflow-hidden">
            <!-- Glowing Ambient Background Blob -->
            <div class="absolute -top-24 -left-24 w-48 h-48 bg-emerald-500/20 rounded-full blur-3xl pointer-events-none animate-pulse"></div>
            <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none animate-pulse"></div>

            <!-- Animated Avatar with Multi-layer Ripples -->
            <div class="relative w-28 h-28 mx-auto flex items-center justify-center">
                <span class="absolute inset-0 rounded-full bg-emerald-500/20 animate-ping"></span>
                <span class="absolute -inset-3 rounded-full bg-emerald-500/15 animate-pulse duration-1000"></span>
                <span class="absolute -inset-6 rounded-full bg-emerald-500/10 animate-pulse duration-1500"></span>
                <div class="w-20 h-20 rounded-full bg-gradient-to-tr from-emerald-600 to-teal-500 text-white flex items-center justify-center shadow-xl shadow-emerald-500/40 relative z-10 ring-4 ring-white dark:ring-slate-800">
                    <svg class="w-10 h-10 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                </div>
            </div>

            <!-- Caller Info & Destination Line -->
            <div class="space-y-2 relative z-10">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs font-bold font-mono">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ __('user.calls.incoming_call_title') }}</span>
                </div>
                <div class="text-2xl sm:text-3xl font-black font-mono tracking-wider text-slate-900 dark:text-white" dir="ltr">
                    {{ incomingCall.source }}
                </div>
                <div v-if="incomingCall.destination" class="text-xs text-slate-400 font-medium">
                    {{ __('user.calls.to_destination', { destination: incomingCall.destination }) }}
                </div>
            </div>

            <!-- Action Buttons: Decline, Voice Answer, Video Answer -->
            <div class="grid grid-cols-3 gap-2 sm:gap-3 pt-2 relative z-10">
                <!-- Decline button -->
                <button
                    type="button"
                    @click="declineIncoming"
                    class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-rose-600/30 transition-all active:scale-95 group cursor-pointer"
                >
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <span>{{ __('user.calls.decline_btn') }}</span>
                </button>

                <!-- Answer Voice button -->
                <button
                    type="button"
                    @click="answerIncoming(false)"
                    class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-emerald-600/30 transition-all active:scale-95 group cursor-pointer"
                >
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <span>پاسخ صوتی</span>
                </button>

                <!-- Answer Video button -->
                <button
                    type="button"
                    @click="answerIncoming(true)"
                    class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-indigo-500 to-indigo-700 hover:from-indigo-600 hover:to-indigo-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-indigo-600/30 transition-all active:scale-95 group cursor-pointer"
                >
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span>پاسخ تصویری</span>
                </button>
            </div>
        </div>
    </div>
</template>
