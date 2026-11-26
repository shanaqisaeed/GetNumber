<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import OrgLayout from '@/Layouts/OrgLayout.vue';
import { __ } from '@/Composables/useTrans';

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
    room: {
        uuid: string;
        title: string;
        is_host: boolean;
        has_pin: boolean;
        scheduled_start_at: string | null;
        scheduled_end_at: string | null;
        is_future: boolean;
        admission_policy: string;
        webinar_mode: boolean;
        is_locked?: boolean;
    };
    operableLines: LineItem[];
    existingStatus?: string | null;
}>();

const selectedLine = ref<string>(props.operableLines.length > 0 ? props.operableLines[0].number : '');
const pinCode = ref('');
const localStream = ref<MediaStream | null>(null);
const videoPreviewRef = ref<HTMLVideoElement | null>(null);
const isCameraEnabled = ref(false);
const isMicEnabled = ref(false);
const isMediaInitializing = ref(true);
const audioLevel = ref(0);
const isSubmitting = ref(false);
const errorMessage = ref<string | null>(null);
const waitingApproval = ref(props.existingStatus === 'waiting_approval');

const countdown = ref({
    days: 0,
    hours: 0,
    minutes: 0,
    seconds: 0,
    isReady: false,
});

let timerInterval: any = null;
let pollInterval: any = null;
let audioContext: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let micAnimFrame: number | null = null;

function updateCountdown() {
    if (!props.room.scheduled_start_at) {
        countdown.value.isReady = true;
        return;
    }

    const target = new Date(props.room.scheduled_start_at).getTime();
    const now = new Date().getTime();
    const diff = target - now;

    if (diff <= 0) {
        countdown.value.isReady = true;
        return;
    }

    countdown.value.isReady = false;
    countdown.value.days = Math.floor(diff / (1000 * 60 * 60 * 24));
    countdown.value.hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    countdown.value.minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    countdown.value.seconds = Math.floor((diff % (1000 * 60)) / 1000);
}

function attachAudioMeter(stream: MediaStream) {
    if (stream.getAudioTracks().length === 0) return;

    try {
        if (micAnimFrame) cancelAnimationFrame(micAnimFrame);
        if (!audioContext || audioContext.state === 'closed') {
            audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume().catch(() => {});
        }

        analyser = audioContext.createAnalyser();
        analyser.fftSize = 64;
        const source = audioContext.createMediaStreamSource(stream);
        source.connect(analyser);

        const dataArray = new Uint8Array(analyser.frequencyBinCount);
        const checkAudio = () => {
            if (!analyser) return;
            if (!isMicEnabled.value) {
                audioLevel.value = 0;
            } else {
                analyser.getByteFrequencyData(dataArray);
                let sum = 0;
                for (let i = 0; i < dataArray.length; i++) {
                    sum += dataArray[i];
                }
                const average = sum / dataArray.length;
                audioLevel.value = Math.min(100, Math.round((average / 128) * 100));
            }
            micAnimFrame = requestAnimationFrame(checkAudio);
        };
        checkAudio();
    } catch {
        // Ignore AudioContext restriction until user interaction
    }
}

async function attachStreamToVideo(stream: MediaStream) {
    await nextTick();
    if (videoPreviewRef.value) {
        videoPreviewRef.value.srcObject = stream;
        try {
            await videoPreviewRef.value.play();
        } catch {
            // Autoplay fallback handled by muted + playsinline
        }
    }
}

async function startMediaPreview() {
    isMediaInitializing.value = true;
    stopPreview();

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        isCameraEnabled.value = false;
        isMicEnabled.value = false;
        isMediaInitializing.value = false;
        return;
    }

    let combinedStream: MediaStream | null = null;

    // Only request video if user explicitly enabled camera
    if (isCameraEnabled.value) {
        try {
            combinedStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 640 }, height: { ideal: 360 } },
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
        } catch {
            try {
                combinedStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                });
            } catch {}
        }
    } else {
        // Only request audio preview by default, camera hardware stays completely off
        try {
            combinedStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
        } catch {}
    }

    if (combinedStream) {
        localStream.value = combinedStream;
        const vTrack = combinedStream.getVideoTracks()[0];
        const aTrack = combinedStream.getAudioTracks()[0];

        if (vTrack) {
            vTrack.enabled = true;
            isCameraEnabled.value = true;
            await attachStreamToVideo(combinedStream);
        } else {
            isCameraEnabled.value = false;
        }

        if (aTrack) {
            aTrack.enabled = true;
            isMicEnabled.value = true;
            attachAudioMeter(combinedStream);
        } else {
            isMicEnabled.value = false;
        }
    } else {
        isCameraEnabled.value = false;
        isMicEnabled.value = false;
    }

    isMediaInitializing.value = false;
}

async function toggleCamera() {
    if (isCameraEnabled.value) {
        // Turn OFF camera and physically release hardware device
        isCameraEnabled.value = false;
        if (localStream.value) {
            localStream.value.getVideoTracks().forEach((t) => {
                t.stop();
                localStream.value?.removeTrack(t);
            });
        }
        if (videoPreviewRef.value) {
            videoPreviewRef.value.srcObject = null;
        }
        return;
    }

    // Turn ON camera
    try {
        const vStream = await navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 640 }, height: { ideal: 360 } },
        });
        const vTrack = vStream.getVideoTracks()[0];
        if (vTrack) {
            if (!localStream.value) {
                localStream.value = new MediaStream([vTrack]);
            } else {
                localStream.value.getVideoTracks().forEach((old) => {
                    old.stop();
                    localStream.value?.removeTrack(old);
                });
                localStream.value.addTrack(vTrack);
            }
            vTrack.enabled = true;
            isCameraEnabled.value = true;
            await attachStreamToVideo(localStream.value);
        }
    } catch {
        isCameraEnabled.value = false;
        errorMessage.value = __('conference.controls.media_permission_error');
    }
}

async function toggleMic() {
    if (localStream.value) {
        const track = localStream.value.getAudioTracks()[0];
        if (track && track.readyState === 'live') {
            track.enabled = !track.enabled;
            isMicEnabled.value = track.enabled;
            if (audioContext && audioContext.state === 'suspended') {
                audioContext.resume().catch(() => {});
            }
            return;
        }
    }

    // No live audio track yet — attempt to acquire microphone now
    try {
        const aStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        const aTrack = aStream.getAudioTracks()[0];
        if (aTrack) {
            if (!localStream.value) {
                localStream.value = new MediaStream([aTrack]);
            } else {
                localStream.value.getAudioTracks().forEach((old) => {
                    old.stop();
                    localStream.value?.removeTrack(old);
                });
                localStream.value.addTrack(aTrack);
            }
            aTrack.enabled = true;
            isMicEnabled.value = true;
            attachAudioMeter(localStream.value);
        }
    } catch {
        isMicEnabled.value = false;
        errorMessage.value = __('conference.controls.media_permission_error');
    }
}

async function requestJoin() {
    errorMessage.value = null;

    if (!selectedLine.value) {
        errorMessage.value = __('conference.auth.phone_required');
        return;
    }

    isSubmitting.value = true;

    try {
        const resp = await axios.post(route('org.conference.join', { uuid: props.room.uuid }), {
            phone_number: selectedLine.value,
            pin_code: pinCode.value,
            is_camera_on: isCameraEnabled.value,
            is_muted: !isMicEnabled.value,
        });

        if (resp.data.success) {
            if (resp.data.status === 'admitted' && resp.data.redirect) {
                stopPreview();
                window.location.href = resp.data.redirect;
            } else if (resp.data.status === 'waiting_approval') {
                waitingApproval.value = true;
                startApprovalPolling();
            }
        }
    } catch (err: any) {
        errorMessage.value = err.response?.data?.message || __('conference.lobby.join_error');
    } finally {
        isSubmitting.value = false;
    }
}

function startApprovalPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(async () => {
        try {
            const resp = await axios.get(route('org.conference.state', { uuid: props.room.uuid }));
            if (resp.data.selfStatus === 'admitted') {
                clearInterval(pollInterval);
                stopPreview();
                window.location.href = route('org.conference.room', { uuid: props.room.uuid });
            } else if (resp.data.selfStatus === 'rejected') {
                clearInterval(pollInterval);
                waitingApproval.value = false;
                errorMessage.value = __('conference.lobby.rejected_notice');
            }
        } catch {
            // Keep polling
        }
    }, 2200);
}

function stopPreview() {
    if (micAnimFrame) {
        cancelAnimationFrame(micAnimFrame);
        micAnimFrame = null;
    }
    if (audioContext) {
        try {
            audioContext.close();
        } catch {}
        audioContext = null;
    }
    if (localStream.value) {
        localStream.value.getTracks().forEach((track) => track.stop());
        localStream.value = null;
    }
}

onMounted(() => {
    updateCountdown();
    timerInterval = setInterval(updateCountdown, 1000);
    startMediaPreview();

    if (props.existingStatus === 'waiting_approval') {
        startApprovalPolling();
    }
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (pollInterval) clearInterval(pollInterval);
    stopPreview();
});
</script>

<template>
    <Head :title="room.title + ' - ' + __('conference.lobby.waiting_title')" />

    <OrgLayout>
        <div class="max-w-5xl mx-auto space-y-6">
            <!-- Room Info Header -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 text-xs font-bold border border-purple-200 dark:border-purple-800">
                            {{ __('conference.title') }}
                        </span>
                    </div>
                    <h1 class="text-2xl font-black text-slate-900 dark:text-white">
                        {{ room.title }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                        ID: {{ room.uuid }}
                    </p>
                </div>

                <div v-if="room.is_host">
                    <span class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 text-xs font-bold border border-emerald-200 dark:border-emerald-800 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>{{ __('conference.common.host') }}</span>
                    </span>
                </div>
            </div>

            <!-- Scheduled Countdown Card -->
            <div
                v-if="!countdown.isReady && !room.is_host"
                class="bg-gradient-to-l from-purple-900 via-slate-900 to-indigo-950 border border-purple-500/30 rounded-3xl p-8 text-white text-center space-y-6 shadow-xl animate-fade-in"
            >
                <div class="space-y-1">
                    <h2 class="text-xl font-black text-purple-200">
                        {{ __('conference.lobby.scheduled_countdown_title') }}
                    </h2>
                    <p class="text-sm text-slate-300">
                        {{ __('conference.lobby.scheduled_countdown_desc') }}
                    </p>
                </div>

                <div class="flex items-center justify-center gap-3 sm:gap-4 font-mono">
                    <div class="bg-black/40 border border-white/10 rounded-2xl p-3 sm:p-4 min-w-[70px] sm:min-w-[85px]">
                        <span class="text-2xl sm:text-4xl font-black text-white block">{{ countdown.days }}</span>
                        <span class="text-[10px] text-slate-400 font-sans">{{ __('conference.lobby.days') }}</span>
                    </div>
                    <span class="text-xl font-bold text-purple-400">:</span>
                    <div class="bg-black/40 border border-white/10 rounded-2xl p-3 sm:p-4 min-w-[70px] sm:min-w-[85px]">
                        <span class="text-2xl sm:text-4xl font-black text-white block">{{ String(countdown.hours).padStart(2, '0') }}</span>
                        <span class="text-[10px] text-slate-400 font-sans">{{ __('conference.lobby.hours') }}</span>
                    </div>
                    <span class="text-xl font-bold text-purple-400">:</span>
                    <div class="bg-black/40 border border-white/10 rounded-2xl p-3 sm:p-4 min-w-[70px] sm:min-w-[85px]">
                        <span class="text-2xl sm:text-4xl font-black text-white block">{{ String(countdown.minutes).padStart(2, '0') }}</span>
                        <span class="text-[10px] text-slate-400 font-sans">{{ __('conference.lobby.minutes') }}</span>
                    </div>
                    <span class="text-xl font-bold text-purple-400">:</span>
                    <div class="bg-black/40 border border-white/10 rounded-2xl p-3 sm:p-4 min-w-[70px] sm:min-w-[85px]">
                        <span class="text-2xl sm:text-4xl font-black text-emerald-400 block">{{ String(countdown.seconds).padStart(2, '0') }}</span>
                        <span class="text-[10px] text-slate-400 font-sans">{{ __('conference.lobby.seconds') }}</span>
                    </div>
                </div>
            </div>

            <!-- Waiting Room & Media Testing Arena -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="md:col-span-7 bg-slate-950 rounded-3xl overflow-hidden border border-slate-800 shadow-xl relative aspect-video flex items-center justify-center">
                    <!-- Keep video mounted without display:none so browser decoder never pauses -->
                    <video
                        ref="videoPreviewRef"
                        autoplay
                        muted
                        playsinline
                        class="w-full h-full object-cover transform -scale-x-100 transition-opacity duration-200"
                        :class="isCameraEnabled ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                    ></video>

                    <div v-if="!isCameraEnabled" class="absolute inset-0 flex flex-col items-center justify-center text-center space-y-2 text-slate-400 bg-slate-950/90 p-4">
                        <div class="w-16 h-16 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center mx-auto text-rose-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </div>
                        <p class="text-xs font-bold text-slate-300">
                            {{ isMediaInitializing ? __('conference.common.loading') : __('conference.lobby.camera_status_off') }}
                        </p>
                        <button
                            v-if="!isMediaInitializing"
                            type="button"
                            @click="startMediaPreview"
                            class="px-3.5 py-1.5 rounded-xl bg-purple-600/30 hover:bg-purple-600/50 border border-purple-500/40 text-purple-200 text-[11px] font-bold transition"
                        >
                            {{ __('conference.lobby.retry_camera_mic') }}
                        </button>
                    </div>

                    <!-- Top Status Badges on Video Preview -->
                    <div class="absolute top-3 inset-x-3 flex flex-wrap items-center justify-between gap-2 z-10">
                        <!-- Live Camera & Mic Status Pills -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="px-2.5 py-1 rounded-full text-[11px] font-bold backdrop-blur-md border flex items-center gap-1.5"
                                :class="isCameraEnabled ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-rose-500/20 text-rose-300 border-rose-500/40'"
                            >
                                <span class="w-2 h-2 rounded-full" :class="isCameraEnabled ? 'bg-emerald-400 animate-pulse' : 'bg-rose-500'"></span>
                                <span>{{ isCameraEnabled ? __('conference.lobby.camera_status_on') : __('conference.lobby.camera_status_off') }}</span>
                            </span>

                            <span
                                class="px-2.5 py-1 rounded-full text-[11px] font-bold backdrop-blur-md border flex items-center gap-1.5"
                                :class="isMicEnabled ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' : 'bg-rose-500/20 text-rose-300 border-rose-500/40'"
                            >
                                <span class="w-2 h-2 rounded-full" :class="isMicEnabled ? 'bg-emerald-400 animate-pulse' : 'bg-rose-500'"></span>
                                <span>{{ isMicEnabled ? __('conference.lobby.mic_status_on') : __('conference.lobby.mic_status_off') }}</span>
                            </span>
                        </div>

                        <!-- Live Audio Level Meter -->
                        <div class="flex items-center gap-2 bg-black/70 backdrop-blur-md px-3 py-1.5 rounded-full border border-white/10">
                            <span class="w-2 h-2 rounded-full" :class="isMicEnabled && audioLevel > 5 ? 'bg-emerald-500' : 'bg-slate-500'"></span>
                            <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 transition-all duration-75" :style="{ width: (isMicEnabled ? audioLevel : 0) + '%' }"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Interactive Toggle Buttons with Clear Labels -->
                    <div class="absolute bottom-4 inset-x-0 flex items-center justify-center gap-3 z-10 px-3">
                        <button
                            type="button"
                            @click="toggleMic"
                            :class="isMicEnabled ? 'bg-emerald-600/90 hover:bg-emerald-500 text-white border-emerald-400/50' : 'bg-rose-600 hover:bg-rose-500 text-white border-rose-500'"
                            class="px-4 py-2.5 rounded-2xl border backdrop-blur-md transition shadow-lg flex items-center gap-2 text-xs font-bold cursor-pointer"
                            :title="isMicEnabled ? __('conference.controls.mic_off') : __('conference.controls.mic_on')"
                        >
                            <svg v-if="isMicEnabled" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                            </svg>
                            <svg v-else class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
                            </svg>
                            <span>{{ isMicEnabled ? __('conference.controls.mic_active_label') : __('conference.controls.mic_muted_label') }}</span>
                        </button>

                        <button
                            type="button"
                            @click="toggleCamera"
                            :class="isCameraEnabled ? 'bg-emerald-600/90 hover:bg-emerald-500 text-white border-emerald-400/50' : 'bg-rose-600 hover:bg-rose-500 text-white border-rose-500'"
                            class="px-4 py-2.5 rounded-2xl border backdrop-blur-md transition shadow-lg flex items-center gap-2 text-xs font-bold cursor-pointer"
                            :title="isCameraEnabled ? __('conference.controls.camera_off') : __('conference.controls.camera_on')"
                        >
                            <svg v-if="isCameraEnabled" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg v-else class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                            <span>{{ isCameraEnabled ? __('conference.controls.camera_active_label') : __('conference.controls.camera_off_label') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Join Verification Card -->
                <div class="md:col-span-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-7 shadow-sm flex flex-col justify-between space-y-6">
                    <div class="space-y-4">
                        <div class="space-y-1">
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">
                                {{ __('conference.lobby.ready_to_join') }}
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('conference.auth.phone_required') }}
                            </p>
                        </div>

                        <!-- Clear Device Readiness Summary Box -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/70 space-y-2">
                            <div class="text-[11px] font-bold text-slate-500 dark:text-slate-400">
                                {{ __('conference.lobby.device_status_title') }}
                            </div>
                            <div class="grid grid-cols-1 gap-2">
                                <div class="flex items-center justify-between text-xs font-bold px-2.5 py-1.5 rounded-xl" :class="isCameraEnabled ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'">
                                    <span class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full" :class="isCameraEnabled ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        <span>{{ isCameraEnabled ? __('conference.lobby.camera_status_on') : __('conference.lobby.camera_status_off') }}</span>
                                    </span>
                                    <button type="button" @click="toggleCamera" class="text-[11px] underline">
                                        {{ isCameraEnabled ? __('conference.controls.camera_off') : __('conference.controls.camera_on') }}
                                    </button>
                                </div>

                                <div class="flex items-center justify-between text-xs font-bold px-2.5 py-1.5 rounded-xl" :class="isMicEnabled ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'">
                                    <span class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full" :class="isMicEnabled ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        <span>{{ isMicEnabled ? __('conference.lobby.mic_status_on') : __('conference.lobby.mic_status_off') }}</span>
                                    </span>
                                    <button type="button" @click="toggleMic" class="text-[11px] underline">
                                        {{ isMicEnabled ? __('conference.controls.mic_off') : __('conference.controls.mic_on') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="p-3.5 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-600 dark:text-rose-400 text-xs font-semibold animate-fade-in flex items-center gap-2">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ errorMessage }}</span>
                        </div>

                        <div v-if="waitingApproval" class="p-5 rounded-2xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800 text-center space-y-3 animate-fade-in">
                            <div class="w-10 h-10 rounded-full border-2 border-purple-600 border-t-transparent animate-spin mx-auto"></div>
                            <div class="space-y-1">
                                <h3 class="text-xs font-bold text-purple-900 dark:text-purple-200">{{ __('conference.lobby.waiting_title') }}</h3>
                                <p class="text-[11px] text-purple-700 dark:text-purple-400 leading-relaxed">{{ __('conference.lobby.waiting_desc') }}</p>
                            </div>
                        </div>

                        <div v-if="!waitingApproval">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.auth.select_line') }} *
                            </label>
                            <select
                                v-model="selectedLine"
                                required
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-xs font-mono font-semibold"
                            >
                                <option v-for="line in operableLines" :key="line.id" :value="line.number">
                                    {{ line.formatted_number || line.number }} ({{ line.display_title || __('conference.lobby.default_line_title') }})
                                </option>
                            </select>
                        </div>

                        <div v-if="!waitingApproval && room.has_pin && !room.is_host">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                {{ __('conference.room.pin_code') }} *
                            </label>
                            <input
                                v-model="pinCode"
                                type="password"
                                required
                                :placeholder="__('conference.room.pin_placeholder')"
                                class="w-full px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm font-mono"
                            />
                        </div>
                    </div>

                    <div v-if="!waitingApproval">
                        <button
                            type="button"
                            @click="requestJoin"
                            :disabled="isSubmitting || (!countdown.isReady && !room.is_host)"
                            class="w-full py-3.5 rounded-2xl bg-purple-600 hover:bg-purple-500 text-white font-bold text-sm shadow-lg shadow-purple-600/30 transition-all flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                        >
                            <svg v-if="isSubmitting" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>{{ room.is_host ? __('conference.lobby.enter_as_host') : __('conference.lobby.request_join_btn') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </OrgLayout>
</template>
