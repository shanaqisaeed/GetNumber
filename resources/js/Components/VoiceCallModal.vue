<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue';
import axios from 'axios';

interface UserLine {
    id: number;
    number: string;
    formatted_number: string;
    display_title: string;
}

const props = defineProps<{
    isOpen: boolean;
    availableLines?: UserLine[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'callCompleted', data: { callId: number; duration: number; charged: string }): void;
}>();

const destination = ref('');
const selectedLineId = ref<number | null>(props.availableLines?.[0]?.id || null);
const callState = ref<'idle' | 'initiating' | 'ringing' | 'connected' | 'ended'>('idle');
const activeCallId = ref<number | null>(null);
const callDurationSeconds = ref(0);
const perMinuteRate = ref(5.0);
const isMuted = ref(false);
const endSummary = ref<{ duration: number; charged: string } | null>(null);
const errorMessage = ref('');

let durationTimer: ReturnType<typeof setInterval> | null = null;
let statusPollingTimer: ReturnType<typeof setInterval> | null = null;

const padTime = (n: number) => n.toString().padStart(2, '0');
const formattedDuration = computed(() => {
    const minutes = Math.floor(callDurationSeconds.value / 60);
    const seconds = callDurationSeconds.value % 60;
    return `${padTime(minutes)}:${padTime(seconds)}`;
});

const appendDigit = (digit: string) => {
    if (callState.value === 'idle') {
        destination.value += digit;
    }
};

const backspace = () => {
    if (callState.value === 'idle') {
        destination.value = destination.value.slice(0, -1);
    }
};

const initiateCall = async () => {
    if (!destination.value.trim()) {
        errorMessage.value = 'لطفاً شماره یا شناسه مقصد را وارد کنید.';
        return;
    }

    errorMessage.value = '';
    callState.value = 'initiating';
    endSummary.value = null;

    try {
        const response = await axios.post(route('voice.initiate'), {
            phone_number_id: selectedLineId.value,
            destination_number: destination.value.trim(),
        });

        if (response.data.success) {
            activeCallId.value = response.data.call.id;
            perMinuteRate.value = Number(response.data.call.per_minute_rate) || 5.0;
            callState.value = 'ringing';

            // Simulate pickup after 2 seconds
            setTimeout(() => {
                if (callState.value === 'ringing') {
                    callState.value = 'connected';
                    callDurationSeconds.value = 0;
                    durationTimer = setInterval(() => {
                        callDurationSeconds.value++;
                    }, 1000);
                }
            }, 2000);
        } else {
            errorMessage.value = response.data.message || 'خطا در برقراری تماس';
            callState.value = 'idle';
        }
    } catch (err: any) {
        errorMessage.value = err.response?.data?.message || 'خطا در شروع تماس';
        callState.value = 'idle';
    }
};

const endCall = async () => {
    if (durationTimer) {
        clearInterval(durationTimer);
        durationTimer = null;
    }

    if (!activeCallId.value) {
        callState.value = 'idle';
        return;
    }

    try {
        const response = await axios.post(route('voice.end', activeCallId.value), {
            duration_seconds: callDurationSeconds.value,
        });

        if (response.data.success) {
            endSummary.value = {
                duration: response.data.duration_seconds,
                charged: response.data.charged_amount,
            };
            emit('callCompleted', {
                callId: activeCallId.value,
                duration: response.data.duration_seconds,
                charged: response.data.charged_amount,
            });
        }
    } catch (err) {
        console.error('Failed to report call end:', err);
    } finally {
        callState.value = 'ended';
        activeCallId.value = null;
    }
};

const resetDialer = () => {
    if (durationTimer) clearInterval(durationTimer);
    callState.value = 'idle';
    destination.value = '';
    callDurationSeconds.value = 0;
    endSummary.value = null;
    errorMessage.value = '';
};

const closeModal = () => {
    if (callState.value === 'connected' || callState.value === 'ringing') {
        if (!confirm('تماس در حال انجام است. آیا می‌خواهید تماس قطع و بسته شود؟')) {
            return;
        }
        endCall();
    }
    resetDialer();
    emit('close');
};

onUnmounted(() => {
    if (durationTimer) clearInterval(durationTimer);
    if (statusPollingTimer) clearInterval(statusPollingTimer);
});
</script>

<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"
    >
        <div class="bg-white dark:bg-gray-900 rounded-3xl max-w-sm w-full shadow-2xl border border-gray-100 dark:border-gray-800 overflow-hidden text-right">
            <!-- Modal Header -->
            <div class="p-5 bg-gradient-to-r from-indigo-900 to-indigo-800 text-white flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center backdrop-blur-sm">
                        <svg class="w-5 h-5 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold">شماره‌گیر ارتباط صوتی (Voice Call)</h3>
                        <p class="text-[11px] text-indigo-200">زیرساخت ارتباط زنده بر بستر شبکه اختصاصی</p>
                    </div>
                </div>
                <button
                    type="button"
                    @click="closeModal"
                    class="w-7 h-7 rounded-lg bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-colors"
                >
                    &times;
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-5 space-y-4">
                <!-- Tariff Warning Notice -->
                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900/50 flex items-center gap-2 text-xs text-amber-800 dark:text-amber-300">
                    <svg class="w-4 h-4 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>نرخ مصوب تماس: ۵.۰۰۰۰ کوین در دقیقه (کسر آنی از کیف‌پول).</span>
                </div>

                <!-- Error Alert -->
                <div v-if="errorMessage" class="p-3 rounded-xl bg-rose-50 text-rose-800 text-xs font-semibold">
                    {{ errorMessage }}
                </div>

                <!-- Active Line Selector (when idle) -->
                <div v-if="callState === 'idle'">
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                        تماس از طریق خط:
                    </label>
                    <select
                        v-model="selectedLineId"
                        class="w-full text-xs rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >
                        <option :value="null">-- خط پیش‌فرض سامانه --</option>
                        <option v-for="l in availableLines" :key="l.id" :value="l.id">
                            {{ l.formatted_number }} ({{ l.display_title || 'خط فعال' }})
                        </option>
                    </select>
                </div>

                <!-- IN CALL VIEW -->
                <div v-if="callState === 'ringing' || callState === 'initiating' || callState === 'connected'" class="py-6 text-center space-y-4">
                    <div class="relative inline-block">
                        <div
                            class="w-20 h-20 rounded-full mx-auto flex items-center justify-center text-white text-2xl shadow-lg"
                            :class="callState === 'connected' ? 'bg-emerald-600 animate-pulse' : 'bg-indigo-600 animate-ping'"
                        >
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>

                    <div>
                        <div class="text-xl font-mono font-bold text-gray-900 dark:text-white">
                            {{ destination }}
                        </div>
                        <div class="text-xs font-semibold mt-1" :class="callState === 'connected' ? 'text-emerald-500' : 'text-indigo-500'">
                            <span v-if="callState === 'initiating'">در حال اتصال به سرور مخابراتی...</span>
                            <span v-else-if="callState === 'ringing'">در حال زنگ خوردن...</span>
                            <span v-else-if="callState === 'connected'">مکالمه برقرار است — {{ formattedDuration }}</span>
                        </div>
                    </div>

                    <!-- Call Controls -->
                    <div class="flex items-center justify-center gap-4 pt-4">
                        <button
                            type="button"
                            @click="isMuted = !isMuted"
                            class="w-11 h-11 rounded-full flex items-center justify-center transition-colors border"
                            :class="isMuted ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 border-gray-200'"
                            title="قطع/وصل میکروفون"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            @click="endCall"
                            class="w-14 h-14 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-lg shadow-rose-600/30 transition-transform active:scale-95"
                            title="قطع تماس"
                        >
                            <svg class="w-7 h-7 transform rotate-135" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M16 8l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M5 3a2 2 0 00-2 2v1c0 8.284 6.716 15 15 15h1a2 2 0 002-2v-3.28a1 1 0 00-.684-.948l-4.493-1.498a1 1 0 00-1.21.502l-1.13 2.257a11.042 11.042 0 01-5.516-5.517l2.257-1.128a1 1 0 00.502-1.21L9.228 3.683A1 1 0 008.279 3H5z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- ENDED SUMMARY VIEW -->
                <div v-else-if="callState === 'ended'" class="py-6 text-center space-y-4">
                    <div class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-gray-800 text-gray-500 mx-auto flex items-center justify-center">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div>
                        <h4 class="text-base font-bold text-gray-900 dark:text-white">تماس پایان یافت</h4>
                        <div v-if="endSummary" class="mt-2 text-xs text-gray-500 space-y-1">
                            <div>مدت مکالمه: <span class="font-mono font-bold text-gray-900 dark:text-white">{{ endSummary.duration }} ثانیه</span></div>
                            <div>هزینه کسر شده: <span class="font-mono font-bold text-emerald-600">{{ endSummary.charged }} کوین</span></div>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="resetDialer"
                        class="px-5 py-2 text-xs font-bold rounded-xl text-white bg-indigo-600 hover:bg-indigo-700"
                    >
                        برقراری تماس جدید
                    </button>
                </div>

                <!-- DIALPAD & INPUT (Idle View) -->
                <div v-else class="space-y-4">
                    <!-- Display Input -->
                    <div class="relative">
                        <input
                            v-model="destination"
                            type="text"
                            placeholder="شماره یا آیدی مقصد..."
                            class="w-full text-center text-lg font-mono font-bold tracking-wider rounded-2xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white py-3 pl-10 pr-4"
                        />
                        <button
                            v-if="destination"
                            type="button"
                            @click="backspace"
                            class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 hover:text-gray-600"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414a2 2 0 001.414.586H19a2 2 0 002-2V7a2 2 0 00-2-2h-8.172a2 2 0 00-1.414.586L3 12z" />
                            </svg>
                        </button>
                    </div>

                    <!-- 3x4 Dialpad Matrix -->
                    <div class="grid grid-cols-3 gap-2.5">
                        <button
                            v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#']"
                            :key="digit"
                            type="button"
                            @click="appendDigit(digit)"
                            class="h-12 rounded-2xl bg-gray-50 dark:bg-gray-800 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 text-gray-900 dark:text-white font-mono text-lg font-bold border border-gray-100 dark:border-gray-700/50 shadow-sm transition-all active:scale-95 flex items-center justify-center"
                        >
                            {{ digit }}
                        </button>
                    </div>

                    <!-- Call Button -->
                    <div class="pt-2">
                        <button
                            type="button"
                            @click="initiateCall"
                            :disabled="!destination.trim()"
                            class="w-full py-3.5 rounded-2xl text-white font-bold bg-emerald-600 hover:bg-emerald-700 shadow-lg shadow-emerald-600/30 disabled:opacity-40 transition-all flex items-center justify-center gap-2"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>آغاز تماس صوتی</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

