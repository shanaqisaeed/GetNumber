<script setup lang="ts">
import { ref, onUnmounted, watch, nextTick } from 'vue';
import Modal from '@/Components/Modal.vue';
import { __ } from '@/Composables/useTrans';
import { CalmRingtone } from '@/Utils/ringtone';

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const activeTab = ref<'audio' | 'video'>('audio');

// --- Microphone Section State ---
const isTestingMic = ref(false);
const micVolumeLevel = ref(0);
const micError = ref<string | null>(null);

let audioContext: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let microphoneStream: MediaStream | null = null;
let animationFrameId: number | null = null;

// --- Speaker Section State ---
let ringtonePlayer: CalmRingtone | null = null;
const isPlayingRingtone = ref(false);

// --- Camera Section State ---
const isTestingCamera = ref(false);
const cameraError = ref<string | null>(null);
const testVideo = ref<HTMLVideoElement | null>(null);
let cameraStream: MediaStream | null = null;
const availableCameras = ref<{ deviceId: string; label: string }[]>([]);
const selectedCameraId = ref<string>('');
const cameraResolution = ref<string>('');

async function enumerateVideoDevices() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoInputs = devices.filter(d => d.kind === 'videoinput');
        availableCameras.value = videoInputs
            .filter(d => Boolean(d.deviceId))
            .map((d, i) => ({
                deviceId: d.deviceId,
                label: d.label || `دوربین ${i + 1}`,
            }));
        if (availableCameras.value.length > 0 && !selectedCameraId.value) {
            selectedCameraId.value = availableCameras.value[0].deviceId;
        }
    } catch {}
}

async function startMicTest() {
    micError.value = null;
    try {
        microphoneStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;

        const source = audioContext.createMediaStreamSource(microphoneStream);
        source.connect(analyser);

        isTestingMic.value = true;
        updateMicVolume();
    } catch {
        micError.value = __('softphone.devices.permission_denied');
        stopMicTest();
    }
}

function updateMicVolume() {
    if (!analyser || !isTestingMic.value) return;

    const bufferLength = analyser.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    analyser.getByteFrequencyData(dataArray);

    let sum = 0;
    for (let i = 0; i < bufferLength; i++) {
        sum += dataArray[i];
    }
    const average = sum / bufferLength;
    micVolumeLevel.value = Math.min(100, Math.round((average / 128) * 100));

    animationFrameId = requestAnimationFrame(updateMicVolume);
}

function stopMicTest() {
    isTestingMic.value = false;
    micVolumeLevel.value = 0;

    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
        animationFrameId = null;
    }
    if (microphoneStream) {
        microphoneStream.getTracks().forEach(track => track.stop());
        microphoneStream = null;
    }
    if (audioContext && audioContext.state !== 'closed') {
        audioContext.close();
        audioContext = null;
    }
}

function toggleTestSound() {
    if (isPlayingRingtone.value) {
        ringtonePlayer?.stop();
        isPlayingRingtone.value = false;
    } else {
        if (!ringtonePlayer) {
            ringtonePlayer = new CalmRingtone();
        }
        ringtonePlayer.play();
        isPlayingRingtone.value = true;
        setTimeout(() => {
            if (isPlayingRingtone.value) {
                ringtonePlayer?.stop();
                isPlayingRingtone.value = false;
            }
        }, 4000);
    }
}

async function startCameraTest() {
    cameraError.value = null;
    try {
        const videoConstraints: boolean | MediaTrackConstraints = (selectedCameraId.value && selectedCameraId.value.length > 5)
            ? { deviceId: { ideal: selectedCameraId.value } }
            : true;

        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: videoConstraints,
        });
        isTestingCamera.value = true;

        await nextTick();
        if (testVideo.value) {
            testVideo.value.srcObject = cameraStream;
            testVideo.value.play().catch(() => {});
        }
        await enumerateVideoDevices();

        const videoTrack = cameraStream.getVideoTracks()[0];
        if (videoTrack) {
            const settings = videoTrack.getSettings();
            if (settings.width && settings.height) {
                cameraResolution.value = `${settings.width}×${settings.height}${settings.frameRate ? ` (${Math.round(settings.frameRate)}fps)` : ''}`;
            }
        }
    } catch (err: any) {
        console.error('[CameraTest] Error requesting camera:', err);
        cameraError.value = __('softphone.devices.cam_permission_denied');
        stopCameraTest();
    }
}

function stopCameraTest() {
    isTestingCamera.value = false;
    cameraResolution.value = '';
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    if (testVideo.value) {
        testVideo.value.srcObject = null;
    }
}

async function onCameraChanged() {
    if (isTestingCamera.value) {
        stopCameraTest();
        await startCameraTest();
    }
}

function closeModal() {
    stopMicTest();
    stopCameraTest();
    if (isPlayingRingtone.value) {
        ringtonePlayer?.stop();
        isPlayingRingtone.value = false;
    }
    emit('close');
}

// Automatically trigger test when switching tabs
watch(activeTab, async (newTab) => {
    if (newTab === 'video') {
        stopMicTest();
        await nextTick();
        if (!isTestingCamera.value) {
            await startCameraTest();
        }
    } else {
        stopCameraTest();
        if (!isTestingMic.value) {
            await startMicTest();
        }
    }
});

watch(() => props.show, async (newVal) => {
    if (newVal) {
        activeTab.value = 'audio';
        await enumerateVideoDevices();
        await nextTick();
        await startMicTest();
    } else {
        stopMicTest();
        stopCameraTest();
    }
});

onUnmounted(() => {
    stopMicTest();
    stopCameraTest();
    ringtonePlayer?.stop();
});
</script>

<template>
    <Modal :show="show" max-width="md" @close="closeModal">
        <div class="p-6 space-y-4">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-cyan-500/15 text-cyan-500 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-800 dark:text-white">
                            {{ __('softphone.devices.title') }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{ __('softphone.devices.quality_good') }}
                        </p>
                    </div>
                </div>
                <button type="button" @click="closeModal" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white text-xs">
                    ✕
                </button>
            </div>

            <!-- Tab Switcher: Microphone/Sound vs Camera/Video -->
            <div class="flex items-center p-1 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-white/10 text-xs">
                <button
                    type="button"
                    @click="activeTab = 'audio'"
                    class="flex-1 py-2 px-3 rounded-xl font-bold transition-all flex items-center justify-center gap-1.5"
                    :class="activeTab === 'audio'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                >
                    <span>🎙️</span>
                    <span>{{ __('softphone.devices.tab_audio') }}</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'video'"
                    class="flex-1 py-2 px-3 rounded-xl font-bold transition-all flex items-center justify-center gap-1.5"
                    :class="activeTab === 'video'
                        ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                        : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                >
                    <span>📹</span>
                    <span>{{ __('softphone.devices.tab_video') }}</span>
                </button>
            </div>

            <!-- TAB 1: AUDIO & MICROPHONE -->
            <div v-if="activeTab === 'audio'" class="space-y-4 animate-in fade-in duration-150">
                <!-- Error -->
                <div v-if="micError" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold">
                    {{ micError }}
                </div>

                <!-- Microphone Section -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800 dark:text-white">
                            {{ __('softphone.devices.microphone') }}
                        </span>
                        <button
                            type="button"
                            @click="isTestingMic ? stopMicTest() : startMicTest()"
                            class="px-3 py-1.5 rounded-xl text-xs font-black transition-all"
                            :class="isTestingMic ? 'bg-rose-600 hover:bg-rose-500 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white'"
                        >
                            {{ isTestingMic ? __('softphone.devices.stop_test') : __('softphone.devices.test_mic') }}
                        </button>
                    </div>

                    <!-- Signal bar -->
                    <div class="space-y-1.5">
                        <div class="h-3 w-full rounded-full bg-slate-200 dark:bg-white/10 overflow-hidden">
                            <div
                                class="h-full bg-gradient-to-r from-emerald-500 via-amber-500 to-rose-500 transition-all duration-75"
                                :style="{ width: micVolumeLevel + '%' }"
                            ></div>
                        </div>
                        <p class="text-[11px] text-slate-400 text-center">
                            {{ isTestingMic ? __('softphone.devices.speak_prompt') : __('softphone.devices.mic_level') }}
                        </p>
                    </div>
                </div>

                <!-- Speaker / Sound Section -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-800 dark:text-white block">
                            {{ __('softphone.devices.speaker') }}
                        </span>
                        <span class="text-[11px] text-slate-400">
                            {{ __('softphone.devices.test_sound') }}
                        </span>
                    </div>
                    <button
                        type="button"
                        @click="toggleTestSound"
                        class="px-3.5 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-black shadow-sm transition-all"
                    >
                        {{ isPlayingRingtone ? '■ ' + __('softphone.devices.stop_test') : '▶ ' + __('softphone.devices.test_sound') }}
                    </button>
                </div>
            </div>

            <!-- TAB 2: CAMERA & VIDEO -->
            <div v-else-if="activeTab === 'video'" class="space-y-4 animate-in fade-in duration-150">
                <!-- Camera Error -->
                <div v-if="cameraError" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold">
                    {{ cameraError }}
                </div>

                <!-- Camera Selection Row -->
                <div v-if="availableCameras.length > 1" class="flex items-center justify-between gap-2 text-xs">
                    <span class="text-slate-500 dark:text-slate-400 font-bold shrink-0">{{ __('softphone.devices.switch_camera') }}</span>
                    <select
                        v-model="selectedCameraId"
                        @change="onCameraChanged"
                        class="flex-1 py-1 px-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-xs text-slate-800 dark:text-white outline-none"
                    >
                        <option v-for="cam in availableCameras" :key="cam.deviceId" :value="cam.deviceId">
                            {{ cam.label }}
                        </option>
                    </select>
                </div>

                <!-- Video Preview Viewport -->
                <div class="relative w-full aspect-video rounded-2xl overflow-hidden bg-slate-950 border border-slate-800 shadow-inner flex items-center justify-center">
                    <video
                        ref="testVideo"
                        autoplay
                        playsinline
                        muted
                        class="w-full h-full object-cover"
                        :class="isTestingCamera ? 'opacity-100' : 'opacity-0'"
                    ></video>

                    <!-- Overlay when camera is off -->
                    <div v-if="!isTestingCamera" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-800 text-slate-400 flex items-center justify-center text-xl">
                            📷
                        </div>
                        <p class="text-xs text-slate-400 max-w-xs leading-relaxed">
                            {{ __('softphone.devices.cam_prompt') }}
                        </p>
                    </div>

                    <!-- Active Status Badge -->
                    <div v-if="isTestingCamera" class="absolute top-2.5 right-2.5 z-10 flex items-center gap-1.5 bg-black/60 backdrop-blur-md px-2.5 py-1 rounded-full text-white text-[10px] font-mono">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>{{ __('softphone.devices.cam_active_badge') }}</span>
                        <span v-if="cameraResolution" class="text-slate-300 mr-1 border-r border-white/20 pr-1">
                            {{ cameraResolution }}
                        </span>
                    </div>
                </div>

                <!-- Camera Action Controls -->
                <div class="flex items-center justify-between pt-1">
                    <span class="text-xs text-slate-400">
                        {{ isTestingCamera ? 'تصویر با موفقیت در حال پخش است' : __('softphone.devices.camera') }}
                    </span>
                    <button
                        type="button"
                        @click="isTestingCamera ? stopCameraTest() : startCameraTest()"
                        class="px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-1.5 shadow-md"
                        :class="isTestingCamera
                            ? 'bg-rose-600 hover:bg-rose-500 text-white shadow-rose-600/30'
                            : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/30'"
                    >
                        <span>{{ isTestingCamera ? '⏹️' : '▶️' }}</span>
                        <span>{{ isTestingCamera ? __('softphone.devices.stop_cam_test') : __('softphone.devices.test_cam') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>
