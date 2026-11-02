<script setup lang="ts">
import { ref, computed } from 'vue';
import { calculateSmsSegments } from '@/Utils/smsCalculator';

const props = defineProps<{
    selectedLineId: number | null;
    tariffRate?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'sent', result: any): void;
    (e: 'toast', message: string): void;
}>();

const recipientInput = ref('');
const recipientChips = ref<string[]>([]);
const messageText = ref('');
const isSending = ref(false);
const sendFeedback = ref<{ success: boolean; message: string; results?: any[] } | null>(null);

// Bulk batch tracking state
const activeBatch = ref<{
    id: string;
    total: number;
    processed: number;
    progress: number;
    isFinished: boolean;
} | null>(null);

const addRecipientChip = (raw: string) => {
    const val = raw.trim();
    if (!val) return;
    if (!recipientChips.value.includes(val)) {
        recipientChips.value.push(val);
    }
    recipientInput.value = '';
};

const removeRecipientChip = (index: number) => {
    recipientChips.value.splice(index, 1);
};

const onRecipientKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
        e.preventDefault();
        addRecipientChip(recipientInput.value);
    } else if (e.key === 'Backspace' && !recipientInput.value && recipientChips.value.length > 0) {
        recipientChips.value.pop();
    }
};

const calculation = computed(() => {
    const totalRecipients = recipientChips.value.length + (recipientInput.value.trim() ? 1 : 0);
    return calculateSmsSegments(messageText.value, props.tariffRate || '0.0500', totalRecipients);
});

// Check progress of queued batch
const pollBatchProgress = async (batchId: string) => {
    try {
        const res = await fetch(route('messages.batch.status', batchId), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (res.ok) {
            const data = await res.json();
            if (activeBatch.value) {
                activeBatch.value.processed = data.processed_count;
                activeBatch.value.progress = data.progress_percentage;
                activeBatch.value.isFinished = data.is_finished;
            }

            if (!data.is_finished) {
                setTimeout(() => pollBatchProgress(batchId), 1500);
            } else {
                emit('toast', `ارسال بسته انبوه با موفقیت انجام شد (${data.success_count} موفق، ${data.failed_count} ناموفق)`);
                emit('sent', { isBatch: true, batchId });
            }
        }
    } catch {
        // Silently tolerate
    }
};

const submitSend = async () => {
    if (recipientInput.value.trim()) {
        addRecipientChip(recipientInput.value);
    }
    if (recipientChips.value.length === 0) {
        emit('toast', 'لطفاً حداقل یک شماره مقصد وارد کنید');
        return;
    }
    if (!messageText.value.trim() || !props.selectedLineId) {
        emit('toast', 'متن پیام نمی‌تواند خالی باشد');
        return;
    }

    isSending.value = true;
    sendFeedback.value = null;

    // If 10+ recipients, automatically use asynchronous bulk queue!
    const isLargeBulk = recipientChips.value.length >= 10;
    const targetUrl = isLargeBulk ? route('messages.send_bulk') : route('messages.send');

    try {
        const res = await fetch(targetUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                from_number_id: props.selectedLineId,
                destinations: recipientChips.value,
                body: messageText.value.trim(),
            }),
        });

        const data = await res.json();

        if (res.ok && data.success) {
            if (isLargeBulk && data.batch_id) {
                activeBatch.value = {
                    id: data.batch_id,
                    total: data.total_count,
                    processed: 0,
                    progress: 0,
                    isFinished: false,
                };
                pollBatchProgress(data.batch_id);
            } else {
                emit('toast', 'پیامک با موفقیت ارسال شد');
                emit('sent', {
                    isBatch: false,
                    destinations: recipientChips.value,
                    result: data,
                });
            }
        } else {
            sendFeedback.value = {
                success: false,
                message: data.message || 'خطا در ارسال پیامک',
            };
            emit('toast', data.message || 'خطا در ارسال');
        }
    } catch {
        sendFeedback.value = {
            success: false,
            message: 'خطا در ارتباط با سرور',
        };
        emit('toast', 'خطای شبکه در ارسال پیام');
    } finally {
        if (!activeBatch.value) {
            isSending.value = false;
        }
    }
};
</script>

<template>
    <div class="space-y-3.5">
        <!-- Composer Header with Back Button -->
        <div class="glass-card !p-3 flex items-center justify-between gap-2.5 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
            <div class="flex items-center gap-2.5">
                <button
                    type="button"
                    @click="emit('close')"
                    class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white flex items-center justify-center shrink-0 transition-colors"
                    title="بازگشت"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <span class="text-xs font-black text-slate-900 dark:text-white">ارسال پیامک جدید و انبوه</span>
            </div>
        </div>

        <!-- Recipient Field ("To:") -->
        <div class="glass-card !p-3.5 space-y-2 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
            <div class="flex items-center justify-between">
                <label class="text-[11px] font-bold text-slate-600 dark:text-slate-300">گیرنده (شماره مقصد):</label>
                <span v-if="recipientChips.length > 0" class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 font-bold">
                    {{ recipientChips.length }} گیرنده انتخابی
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-1.5 p-2 rounded-2xl bg-slate-100/90 dark:bg-white/5 border border-slate-200 dark:border-white/10 focus-within:border-indigo-500 transition-colors">
                <!-- Chips -->
                <span
                    v-for="(chip, idx) in recipientChips"
                    :key="idx"
                    class="inline-flex items-center gap-1 text-[11px] font-mono font-bold bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 border border-indigo-500/30 px-2 py-0.5 rounded-xl"
                >
                    <span>{{ chip }}</span>
                    <button type="button" @click="removeRecipientChip(idx)" class="hover:text-rose-500">✕</button>
                </span>

                <input
                    v-model="recipientInput"
                    @keydown="onRecipientKeyDown"
                    @blur="addRecipientChip(recipientInput)"
                    type="text"
                    placeholder="شماره مقصد را وارد و اینتر یا ویرگول بزنید..."
                    class="flex-1 min-w-[150px] bg-transparent border-0 p-1 text-xs text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:ring-0"
                    dir="ltr"
                />
            </div>
        </div>

        <!-- Message Body Textarea with Integrated Segment & Cost Calculator -->
        <div class="glass-card !p-3.5 space-y-2.5 bg-white/95 dark:bg-slate-900/90 border-slate-200/90 dark:border-white/10">
            <div class="flex items-center justify-between">
                <label class="text-[11px] font-bold text-slate-600 dark:text-slate-300">متن پیامک:</label>

                <!-- Calculation Pill -->
                <div class="flex items-center gap-2 text-[10px] font-mono">
                    <span
                        class="px-1.5 py-0.2 rounded font-bold"
                        :class="calculation.encoding === 'GSM-7' ? 'bg-sky-500/15 text-sky-600 dark:text-sky-400' : 'bg-purple-500/15 text-purple-600 dark:text-purple-400'"
                    >
                        {{ calculation.encoding }}
                    </span>
                    <span class="text-slate-500 dark:text-slate-400">
                        {{ calculation.charCount }} کاراکتر ({{ calculation.segmentCount }} پارت)
                    </span>
                </div>
            </div>

            <textarea
                v-model="messageText"
                rows="5"
                placeholder="متن پیام خود را بنویسید..."
                class="w-full glass-input !p-3 !text-xs !rounded-2xl resize-none bg-slate-100/90 dark:bg-slate-900/60 border-slate-200 dark:border-white/10 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500"
            ></textarea>

            <!-- Detailed Cost Breakdown Bar -->
            <div
                v-if="messageText.length > 0"
                class="p-2.5 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/70 dark:border-indigo-500/20 flex flex-wrap items-center justify-between gap-2 text-xs"
            >
                <div class="flex items-center gap-3 text-[11px] text-slate-600 dark:text-slate-300 font-medium">
                    <span>تعداد پارت: <b class="font-mono text-indigo-600 dark:text-indigo-400">{{ calculation.segmentCount }}</b></span>
                    <span>تعداد گیرندگان: <b class="font-mono text-indigo-600 dark:text-indigo-400">{{ calculation.recipientCount }}</b></span>
                    <span>تعرفه هر پارت: <b class="font-mono text-slate-500 dark:text-slate-400">{{ calculation.ratePerSegment }} COIN</b></span>
                </div>

                <div class="font-bold text-xs text-indigo-700 dark:text-indigo-300 flex items-center gap-1.5">
                    <span>مجموع تخمین هزینه:</span>
                    <span class="font-mono text-sm font-black">{{ calculation.totalCost }} {{ calculation.currency }}</span>
                </div>
            </div>

            <!-- Batch Progress Indicator -->
            <div v-if="activeBatch" class="p-3 rounded-2xl bg-indigo-50/80 dark:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-800/80 space-y-2">
                <div class="flex items-center justify-between text-xs font-bold">
                    <span class="text-indigo-700 dark:text-indigo-300">در حال پردازش در صف پس‌زمینه...</span>
                    <span class="font-mono text-indigo-600 dark:text-indigo-400">{{ activeBatch.processed }} / {{ activeBatch.total }} ({{ activeBatch.progress }}%)</span>
                </div>
                <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                    <div
                        class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                        :style="{ width: `${activeBatch.progress}%` }"
                    ></div>
                </div>
            </div>

            <button
                type="button"
                @click="submitSend"
                :disabled="isSending || !messageText.trim()"
                class="glass-btn-primary !w-full !py-3 !text-xs !rounded-2xl mt-2 flex items-center justify-center gap-2"
            >
                <svg v-if="isSending" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-4 h-4 -scale-x-100 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
                <span>{{ isSending ? 'در حال ارسال...' : (recipientChips.length >= 10 ? 'ثبت و ارسال به صف انبوه' : 'ارسال پیامک') }}</span>
            </button>

            <p class="text-[10px] text-slate-500 dark:text-slate-400 text-center pt-1">
                پیام‌ها در محیط پلتفرم ثبت شده و پیامک مخابراتی ارسال نمی‌شود.
            </p>
        </div>
    </div>
</template>

