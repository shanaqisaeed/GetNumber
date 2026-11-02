<script setup lang="ts">
import { ref, computed } from 'vue';
import { calculateSmsSegments } from '@/Utils/smsCalculator';

const props = defineProps<{
    disabled?: boolean;
    tariffRate?: string;
    canReply?: boolean;
    canReplyReason?: string | null;
}>();

const emit = defineEmits<{
    (e: 'send', text: string): void;
}>();

const text = ref('');

const calculation = computed(() => {
    return calculateSmsSegments(text.value, props.tariffRate || '0.0500', 1);
});

const handleSend = () => {
    const trimmed = text.value.trim();
    if (!trimmed || props.disabled || props.canReply === false) return;
    emit('send', trimmed);
    text.value = '';
};
</script>

<template>
    <div class="space-y-1.5">
        <!-- Interactive Segment & Cost Indicator Bar -->
        <div
            v-if="text.length > 0 && canReply !== false"
            class="flex items-center justify-between px-3 py-1 rounded-xl bg-slate-100/90 dark:bg-slate-900/80 border border-slate-200/80 dark:border-white/10 text-[10px] font-mono animate-in fade-in duration-150"
        >
            <div class="flex items-center gap-2">
                <!-- Encoding Pill -->
                <span
                    class="px-1.5 py-0.2 rounded font-bold"
                    :class="calculation.encoding === 'GSM-7' ? 'bg-sky-500/15 text-sky-600 dark:text-sky-400' : 'bg-purple-500/15 text-purple-600 dark:text-purple-400'"
                >
                    {{ calculation.encoding }}
                </span>
                <!-- Segment Count -->
                <span class="text-slate-600 dark:text-slate-300">
                    {{ calculation.charCount }} کاراکتر ({{ calculation.segmentCount }} پارت)
                </span>
                <!-- Remaining Chars -->
                <span class="text-slate-400 dark:text-slate-500 hidden sm:inline">
                    {{ calculation.charsRemainingInSegment }} تا پارت بعد
                </span>
            </div>

            <!-- Cost Estimate -->
            <div class="flex items-center gap-1 font-bold text-indigo-600 dark:text-indigo-400">
                <span class="font-sans text-[9px] text-slate-500 dark:text-slate-400">تخمین هزینه:</span>
                <span>{{ calculation.estimatedCost }} {{ calculation.currency }}</span>
            </div>
        </div>

        <!-- Input Bar with Send Button -->
        <div v-if="canReply !== false" class="glass-card !p-2 flex items-center gap-2 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
            <input
                v-model="text"
                @keydown.enter.prevent="handleSend"
                type="text"
                placeholder="پیام خود را بنویسید..."
                :disabled="disabled"
                class="flex-1 bg-slate-100/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-3.5 py-2 text-xs text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:outline-none focus:border-indigo-500"
            />

            <!-- Telegram / Material Paper Plane Send Button -->
            <button
                type="button"
                @click="handleSend"
                :disabled="!text.trim() || disabled"
                class="w-10 h-10 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white flex items-center justify-center shadow-md shadow-indigo-500/30 disabled:opacity-40 disabled:cursor-not-allowed transition-all active:scale-95 shrink-0"
                title="ارسال پیام"
            >
                <svg v-if="disabled" class="animate-spin w-5 h-5 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-5 h-5 -scale-x-100 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </div>

        <!-- Read-only Line Notice -->
        <div v-else class="glass-card !p-3.5 text-center bg-amber-50/80 dark:bg-amber-950/40 border-amber-200/80 dark:border-amber-500/30 text-xs text-amber-800 dark:text-amber-200 font-bold flex items-center justify-center gap-2 rounded-2xl shadow-sm">
            <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{{ canReplyReason || 'این سرشماره تجاری صرفاً یک‌طرفه بوده و امکان دریافت پیامک یا پاسخ را ندارد.' }}</span>
        </div>
    </div>
</template>

