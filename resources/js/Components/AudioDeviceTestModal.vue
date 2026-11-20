<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import Modal from '@/Components/Modal.vue';
import { __ } from '@/Composables/useTrans';
import { CalmRingtone } from '@/Utils/ringtone';

defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const isTestingMic = ref(false);
const micVolumeLevel = ref(0);
const micError = ref<string | null>(null);

let audioContext: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let microphoneStream: MediaStream | null = null;
let animationFrameId: number | null = null;
let ringtonePlayer: CalmRingtone | null = null;
const isPlayingRingtone = ref(false);

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
    } catch (err: any) {
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

function closeModal() {
    stopMicTest();
    if (isPlayingRingtone.value) {
        ringtonePlayer?.stop();
        isPlayingRingtone.value = false;
    }
    emit('close');
}

onUnmounted(() => {
    stopMicTest();
    ringtonePlayer?.stop();
});
</script>

<template>
    <Modal :show="show" max-width="md" @close="closeModal">
        <div class="p-6">
            <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-cyan-500/15 text-cyan-400 flex items-center justify-center">
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
                <button type="button" @click="closeModal" class="p-1 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white">
                    ✕
                </button>
            </div>

            <!-- Error -->
            <div v-if="micError" class="mt-4 p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold">
                {{ micError }}
            </div>

            <!-- Microphone Section -->
            <div class="mt-5 p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 space-y-3">
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
            <div class="mt-4 p-4 rounded-2xl bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 flex items-center justify-between">
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
    </Modal>
</template>
