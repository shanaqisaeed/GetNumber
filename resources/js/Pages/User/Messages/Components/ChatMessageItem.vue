<script setup lang="ts">
import { computed } from 'vue';
import type { ChatMessage } from '@/Composables/useChatConversation';

const props = defineProps<{
    message: ChatMessage;
}>();

const emit = defineEmits<{
    (e: 'retry', msg: ChatMessage): void;
    (e: 'copy', text: string): void;
}>();

const formatTime = (isoString: string) => {
    try {
        const d = new Date(isoString);
        return new Intl.DateTimeFormat('fa-IR', {
            hour: '2-digit',
            minute: '2-digit',
        }).format(d);
    } catch {
        return '';
    }
};

const displayTime = computed(() => {
    return formatTime(props.message.server_received_at) || props.message.server_received_at_formatted;
});

const isOutbound = computed(() => props.message.direction === 'outbound');
</script>

<template>
    <div
        class="flex flex-col group relative"
        :class="isOutbound ? 'items-end' : 'items-start'"
    >
        <!-- Message Bubble -->
        <div
            class="max-w-[85%] rounded-2xl p-3 text-xs leading-relaxed shadow-md select-text relative transition-all"
            :class="[
                isOutbound
                    ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-bl-sm'
                    : 'bg-white dark:bg-slate-800 border border-slate-200/90 dark:border-slate-700/80 text-slate-900 dark:text-slate-100 rounded-br-sm',
                message.processing_status === 'failed' ? 'border-2 border-rose-500/80' : ''
            ]"
        >
            <p class="whitespace-pre-wrap break-words font-medium">{{ message.body }}</p>

            <!-- Time & Status Row -->
            <div
                class="flex items-center gap-1.5 mt-1.5 text-[9px] font-mono"
                :class="isOutbound ? 'text-indigo-200 justify-end' : 'text-slate-500 dark:text-slate-400 justify-start'"
            >
                <span>{{ displayTime }}</span>

                <template v-if="isOutbound">
                    <!-- 1. Optimistic / Pending: Animated Pulse Indicator -->
                    <span
                        v-if="message.processing_status === 'pending'"
                        class="text-indigo-200 inline-flex items-center gap-1 cursor-help"
                        title="در حال ارسال به سرور..."
                    >
                        <svg class="animate-spin w-3 h-3 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-[8px] font-sans">ارسال...</span>
                    </span>

                    <!-- 2. Failed with Retry button -->
                    <div
                        v-else-if="message.processing_status === 'failed'"
                        class="inline-flex items-center gap-1 text-rose-300"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <button
                            type="button"
                            @click="emit('retry', message)"
                            class="underline font-bold text-[9px] hover:text-white bg-rose-950/60 px-1.5 py-0.5 rounded transition-colors"
                        >
                            تلاش مجدد
                        </button>
                    </div>

                    <!-- 3. Read: Two Emerald Ticks -->
                    <span
                        v-else-if="message.processing_status === 'processed'"
                        class="text-emerald-300 inline-flex items-center -space-x-1.5 rtl:space-x-reverse"
                        title="خوانده شده توسط مخاطب"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>

                    <!-- 4. Delivered: Two Subtle Ticks -->
                    <span
                        v-else-if="message.processing_status === 'received'"
                        class="text-indigo-200/90 inline-flex items-center -space-x-1.5 rtl:space-x-reverse"
                        title="تحویل داده شده"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>

                    <!-- 5. Processing / Sent: Single Tick -->
                    <span
                        v-else
                        class="text-indigo-200/70 inline-flex items-center"
                        title="ارسال شده"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>
                </template>
            </div>
        </div>
    </div>
</template>

