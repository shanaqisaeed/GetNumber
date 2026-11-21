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
const isNoiseCancellationEnabled = ref(
    typeof window !== 'undefined'
        ? localStorage.getItem('getnumber_noise_cancellation') !== 'false'
        : true
);

let audioContext: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let microphoneStream: MediaStream | null = null;
let animationFrameId: number | null = null;

// --- Speaker Section State ---
let ringtonePlayer: CalmRingtone | null = null;
const isPlayingRingtone = ref(false);
const audioOutputs = ref<{ deviceId: string; label: string; isSpeaker: boolean }[]>([]);
const selectedAudioOutputId = ref<string>('default');

// --- Camera Section State ---
const isTestingCamera = ref(false);
const cameraError = ref<string | null>(null);
const testVideo = ref<HTMLVideoElement | null>(null);
let cameraStream: MediaStream | null = null;
const availableCameras = ref<{ deviceId: string; label: string; facingMode: 'user' | 'environment' }[]>([]);
const selectedCameraId = ref<string>('');
const cameraResolution = ref<string>('');

// Intelligent camera enumeration with IR filter and Front Camera Deduplication
async function enumerateVideoDevices() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoInputs = devices.filter(d => d.kind === 'videoinput');

        let seenFront = false;
        const result: { deviceId: string; label: string; facingMode: 'user' | 'environment' }[] = [];

        for (let i = 0; i < videoInputs.length; i++) {
            const d = videoInputs[i];
            const raw = d.label || '';
            const lower = raw.toLowerCase();

            // 1. Skip non-color IR / Depth / Virtual sensors
            if (lower.includes('ir ') || lower.includes('infra') || lower.includes('depth') || lower.includes('tof')) {
                continue;
            }

            let facing: 'user' | 'environment' = 'user';
            if (lower.includes('back') || lower.includes('rear') || lower.includes('environment')) {
                facing = 'environment';
            } else if (lower.includes('front') || lower.includes('user') || lower.includes('selfie')) {
                facing = 'user';
            } else {
                facing = i === 0 ? 'user' : 'environment';
            }

            // 2. Prevent duplicate front cameras (often exposed by Android Camera2 logical vs physical sensors)
            if (facing === 'user') {
                if (seenFront && !lower.includes('wide') && !lower.includes('ultra')) {
                    continue; // Skip secondary duplicate front sensor
                }
                seenFront = true;
            }

            let label = raw;
            if (!label) {
                label = facing === 'user' ? 'دوربین جلو (سلفی)' : 'دوربین اصلی پشت';
            } else {
                if (lower.includes('back') || lower.includes('rear')) {
                    if (lower.includes('wide') || lower.includes('ultra')) {
                        label = 'دوربین پشت (فوق‌عریض / Ultra Wide)';
                    } else if (lower.includes('tele') || lower.includes('zoom')) {
                        label = 'دوربین پشت (تله‌فوتو / Zoom)';
                    } else if (lower.includes('macro')) {
                        label = 'دوربین پشت (ماکرو / Macro)';
                    } else {
                        label = 'دوربین اصلی پشت';
                    }
                } else if (lower.includes('front') || lower.includes('selfie') || lower.includes('user')) {
                    if (lower.includes('wide') || lower.includes('ultra')) {
                        label = 'دوربین جلو (فوق‌عریض / Ultra Wide)';
                    } else {
                        label = 'دوربین جلو (سلفی)';
                    }
                } else {
                    label = `وب‌کم (${raw.slice(0, 20)})`;
                }
            }

            result.push({
                deviceId: d.deviceId,
                label,
                facingMode: facing,
            });
        }

        availableCameras.value = result;
        if (availableCameras.value.length > 0 && !selectedCameraId.value) {
            selectedCameraId.value = availableCameras.value[0].deviceId;
        }
    } catch {}
}

async function enumerateAudioOutputs() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const outputs = devices.filter(d => d.kind === 'audiooutput');
        audioOutputs.value = outputs.map((d, i) => {
            const raw = d.label || `خروجی ${i + 1}`;
            const lower = raw.toLowerCase();
            const isSpeaker = lower.includes('speaker') || lower.includes('loud') || lower.includes('بلندگو') || !lower.includes('earpiece');
            return {
                deviceId: d.deviceId,
                label: lower.includes('earpiece') ? 'گوشی (Earpiece)' : lower.includes('speaker') ? 'اسپیکر (بلندگو)' : raw,
                isSpeaker,
            };
        });
    } catch {}
}

const toggleNoiseCancellation = () => {
    isNoiseCancellationEnabled.value = !isNoiseCancellationEnabled.value;
    if (typeof window !== 'undefined') {
        localStorage.setItem('getnumber_noise_cancellation', String(isNoiseCancellationEnabled.value));
    }
    if (isTestingMic.value) {
        stopMicTest();
        startMicTest();
    }
};

async function startMicTest() {
    micError.value = null;
    try {
        microphoneStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: isNoiseCancellationEnabled.value,
                autoGainControl: true,
            },
        });
        const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
        audioContext = new AudioContextClass();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 128;

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
        audioContext.close().catch(() => {});
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
        }, 5000);
    }
}

async function startCameraTest() {
    cameraError.value = null;
    try {
        let constraints: MediaStreamConstraints = {
            video: selectedCameraId.value && selectedCameraId.value.length > 5
                ? { deviceId: { exact: selectedCameraId.value } }
                : { facingMode: { ideal: 'user' } }
        };

        try {
            cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: true });
        }

        isTestingCamera.value = true;

        await nextTick();
        if (testVideo.value) {
            testVideo.value.srcObject = cameraStream;
            testVideo.value.play().catch(() => {});
        }
        await enumerateVideoDevices();

        const videoTrack = cameraStream?.getVideoTracks()[0];
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
        await enumerateAudioOutputs();
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
    <Modal :show="show" max-width="lg" @close="closeModal">
        <div class="p-5 sm:p-6 space-y-4 text-right">
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-500/15 text-indigo-500 flex items-center justify-center font-bold text-lg">
                        🎙️
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-800 dark:text-white">
                            {{ __('softphone.devices.title') }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            بررسی سلامت، وضوح تصویر دوربین و تنظیم صدای ورودی و خروجی
                        </p>
                    </div>
                </div>
                <button type="button" @click="closeModal" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white text-xs transition-colors">
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
                <div v-if="micError" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold text-center">
                    {{ micError }}
                </div>

                <!-- Microphone Section -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                            <span>🎙️</span>
                            <span>{{ __('softphone.devices.microphone') }}</span>
                        </span>
                        <button
                            type="button"
                            @click="isTestingMic ? stopMicTest() : startMicTest()"
                            class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all active:scale-95"
                            :class="isTestingMic ? 'bg-rose-600 hover:bg-rose-500 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white'"
                        >
                            {{ isTestingMic ? __('softphone.devices.stop_test') : __('softphone.devices.test_mic') }}
                        </button>
                    </div>

                    <!-- VU Meter Signal bar -->
                    <div class="space-y-1.5">
                        <div class="h-3 w-full rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden p-0.5 border border-slate-300/40 dark:border-slate-700">
                            <div
                                class="h-full rounded-full transition-all duration-75"
                                :class="micVolumeLevel > 65 ? 'bg-amber-500' : 'bg-emerald-500'"
                                :style="{ width: micVolumeLevel + '%' }"
                            ></div>
                        </div>
                        <p class="text-[11px] text-slate-400 text-center">
                            {{ isTestingMic ? __('softphone.devices.speak_prompt') : __('softphone.devices.mic_level') }}
                        </p>
                    </div>
                </div>

                <!-- Noise Cancellation Toggle -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold text-slate-800 dark:text-white block">حذف نویز محیطی هوشمند (Noise Cancellation)</span>
                        <span class="text-[11px] text-slate-400">کاهش صدای تایپ، باد و همهمه محیط در مکالمات</span>
                    </div>
                    <button
                        type="button"
                        @click="toggleNoiseCancellation"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 active:scale-95 border"
                        :class="isNoiseCancellationEnabled
                            ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-600 dark:text-emerald-400'
                            : 'bg-slate-200 dark:bg-slate-700 text-slate-500 border-transparent'"
                    >
                        <span>🎧</span>
                        <span>{{ isNoiseCancellationEnabled ? 'روشن' : 'خاموش' }}</span>
                    </button>
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
                        class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-black shadow-md shadow-purple-600/20 transition-all active:scale-95"
                    >
                        {{ isPlayingRingtone ? '■ ' + __('softphone.devices.stop_test') : '▶ ' + __('softphone.devices.test_sound') }}
                    </button>
                </div>
            </div>

            <!-- TAB 2: CAMERA & VIDEO -->
            <div v-else-if="activeTab === 'video'" class="space-y-4 animate-in fade-in duration-150">
                <!-- Camera Error -->
                <div v-if="cameraError" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold text-center">
                    {{ cameraError }}
                </div>

                <!-- Camera Selection Row -->
                <div v-if="availableCameras.length > 1" class="flex items-center justify-between gap-2 text-xs">
                    <span class="text-slate-500 dark:text-slate-400 font-bold shrink-0">{{ __('softphone.devices.switch_camera') }}</span>
                    <select
                        v-model="selectedCameraId"
                        @change="onCameraChanged"
                        class="flex-1 py-1.5 px-3 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-xs text-slate-800 dark:text-white outline-none font-bold"
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
                        {{ isTestingCamera ? 'تصویر زنده با موفقیت فعال است' : __('softphone.devices.camera') }}
                    </span>
                    <button
                        type="button"
                        @click="isTestingCamera ? stopCameraTest() : startCameraTest()"
                        class="px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-1.5 shadow-md active:scale-95"
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
