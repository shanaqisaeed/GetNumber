<script setup lang="ts">
import OrgLayout from '@/Layouts/OrgLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import { __ } from '@/Composables/useTrans';

interface SenderLine {
    id: number;
    number: string;
    formatted_number: string;
    display_title: string;
    sms_rate: number;
}

interface MessageBatchItem {
    batch_id: string;
    total_count: number;
    processed_count: number;
    success_count: number;
    failed_count: number;
    status: string;
    created_at: string;
}

const props = defineProps<{
    sender_lines: SenderLine[];
    recent_batches: MessageBatchItem[];
    org_balance: string;
    currency: string;
}>();

const form = useForm({
    from_number_id: props.sender_lines[0]?.id || null,
    recipients_text: '',
    body: '',
});

const fileInputRef = ref<HTMLInputElement | null>(null);
const fileFeedback = ref<string | null>(null);

const isEstimating = ref(false);
const estimateResult = ref<{
    recipients_count: number;
    segments: number;
    per_segment_rate: number;
    total_cost: number;
    has_sufficient_balance: boolean;
    estimated_cost?: number;
    total_segments?: number;
    intra_recipients?: number;
    inter_recipients?: number;
    id_recipients?: number;
    intra_rate?: number;
    inter_rate?: number;
    id_rate?: number;
} | null>(null);

// Batch details modal state
const isBatchModalOpen = ref(false);
const selectedBatch = ref<MessageBatchItem | null>(null);

function viewBatchDetails(batch: MessageBatchItem) {
    selectedBatch.value = batch;
    isBatchModalOpen.value = true;
}

const rawRecipientsList = computed(() => {
    return form.recipients_text
        .split(/[\n,;]+/)
        .map(s => s.trim())
        .filter(s => s.length >= 3);
});

async function runCostEstimation() {
    if (!form.from_number_id || rawRecipientsList.value.length === 0 || !form.body.trim()) {
        return;
    }

    isEstimating.value = true;
    try {
        const response = await axios.post(route('org.bulk_sms.estimate'), {
            from_number_id: form.from_number_id,
            destinations_count: rawRecipientsList.value.length,
            recipients_text: form.recipients_text,
            body: form.body,
        });

        estimateResult.value = response.data;
    } catch {} finally {
        isEstimating.value = false;
    }
}

function triggerFileInput() {
    fileInputRef.value?.click();
}

function handleFileUpload(event: Event) {
    const input = event.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) return;

    const file = input.files[0];
    const reader = new FileReader();

    reader.onload = (e) => {
        const content = e.target?.result as string;
        if (!content) return;

        const numbers = content
            .split(/[\r\n,;]+/)
            .map(s => s.trim().replace(/['"]/g, ''))
            .filter(s => s.length >= 7 && /^(\+?[0-9]{7,16})$/.test(s));

        if (numbers.length > 0) {
            const existing = form.recipients_text.trim();
            const combined = existing ? `${existing}\n${numbers.join('\n')}` : numbers.join('\n');
            form.recipients_text = combined;
            fileFeedback.value = __('organization.bulk_sms.file_numbers_extracted', { count: numbers.length });
            runCostEstimation();
        }
    };

    reader.readAsText(file);
    input.value = '';
}

function submitCampaign() {
    form.post(route('org.bulk_sms.send'), {
        onSuccess: () => {
            form.reset('recipients_text', 'body');
            estimateResult.value = null;
            fileFeedback.value = null;
        },
    });
}
</script>

<template>
    <OrgLayout>
        <Head :title="__('organization.bulk_sms.title')" />

        <div class="space-y-6 max-w-7xl mx-auto">
            <!-- Hidden File Input -->
            <input
                ref="fileInputRef"
                type="file"
                accept=".csv,.txt"
                class="hidden"
                @change="handleFileUpload"
            />

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                        {{ __('organization.bulk_sms.title') }}
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        {{ __('organization.bulk_sms.cost_source_notice') }}
                    </p>
                </div>

                <div class="px-4 py-2 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                    <span>💳 {{ __('organization.wallet.balance') }}:</span>
                    <span class="text-emerald-500 font-mono font-black" dir="ltr">{{ org_balance }} {{ currency }}</span>
                </div>
            </div>

            <!-- Grid: Campaign Composer & Batch History -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Composer Form (2 Columns) -->
                <div class="lg:col-span-2 p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-5">
                    <form @submit.prevent="submitCampaign" class="space-y-4">
                        <!-- Sender Line Selection -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('organization.bulk_sms.sender_line') }}
                            </label>
                            <select
                                v-model="form.from_number_id"
                                required
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none"
                                dir="ltr"
                                @change="runCostEstimation"
                            >
                                <option
                                    v-for="line in sender_lines"
                                    :key="line.id"
                                    :value="line.id"
                                >
                                    {{ line.formatted_number }} ({{ line.display_title }})
                                </option>
                            </select>
                        </div>

                        <!-- Recipients Input & File Import Button -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-xs font-bold text-slate-700 dark:text-slate-300">
                                    {{ __('organization.bulk_sms.recipients') }}
                                </label>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="triggerFileInput"
                                        class="px-2.5 py-1 rounded-lg bg-purple-500/10 hover:bg-purple-500/20 text-purple-600 dark:text-purple-400 font-bold text-[11px] transition-all flex items-center gap-1"
                                    >
                                        <span>📁</span>
                                        <span>{{ __('organization.bulk_sms.upload_file_button') }}</span>
                                    </button>
                                    <span class="text-[11px] font-mono font-bold text-purple-600 dark:text-purple-400">
                                        {{ rawRecipientsList.length }} {{ __('organization.bulk_sms.estimated_recipients') }}
                                    </span>
                                </div>
                            </div>
                            <textarea
                                v-model="form.recipients_text"
                                rows="5"
                                required
                                dir="ltr"
                                :placeholder="__('organization.bulk_sms.recipients_placeholder')"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none resize-none"
                                @blur="runCostEstimation"
                            ></textarea>
                            <p v-if="fileFeedback" class="text-[11px] text-emerald-500 font-bold mt-1">
                                ✓ {{ fileFeedback }}
                            </p>
                        </div>

                        <!-- Message Body -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                {{ __('organization.bulk_sms.message_body') }}
                            </label>
                            <textarea
                                v-model="form.body"
                                rows="4"
                                required
                                :placeholder="__('organization.bulk_sms.message_placeholder')"
                                class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs focus:ring-2 focus:ring-purple-500 outline-none resize-none"
                                @blur="runCostEstimation"
                            ></textarea>
                        </div>

                        <!-- Cost Estimation Card -->
                        <div v-if="estimateResult" class="p-4 rounded-2xl bg-purple-500/10 border border-purple-500/20 space-y-2.5">
                            <div class="flex items-center justify-between text-xs font-bold">
                                <span class="text-slate-600 dark:text-slate-300">{{ __('organization.bulk_sms.estimated_cost') }}:</span>
                                <span class="text-purple-600 dark:text-purple-400 font-mono font-black" dir="ltr">
                                    {{ (estimateResult.total_cost ?? estimateResult.estimated_cost ?? 0).toLocaleString() }} {{ currency }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-[11px] text-slate-400">
                                <span>{{ __('organization.bulk_sms.estimated_recipients') }}: {{ estimateResult.recipients_count ?? rawRecipientsList.length }}</span>
                                <span>{{ __('organization.bulk_sms.total_segments') }}: {{ estimateResult.total_segments ?? estimateResult.segments ?? 1 }}</span>
                            </div>

                            <!-- Intra vs Inter Prefix Tariff Breakdown -->
                            <div v-if="estimateResult.intra_recipients || estimateResult.inter_recipients" class="pt-2 border-t border-purple-500/15 grid grid-cols-2 gap-2 text-[11px]">
                                <div class="p-2 rounded-xl bg-purple-500/5 border border-purple-500/10 space-y-0.5">
                                    <div class="text-slate-500 dark:text-slate-400 font-bold">{{ __('organization.bulk_sms.intra_recipients') }}</div>
                                    <div class="text-purple-600 dark:text-purple-400 font-mono font-bold" dir="ltr">
                                        {{ estimateResult.intra_recipients || 0 }} × {{ estimateResult.intra_rate ?? estimateResult.per_segment_rate }} {{ currency }}
                                    </div>
                                </div>
                                <div class="p-2 rounded-xl bg-purple-500/5 border border-purple-500/10 space-y-0.5">
                                    <div class="text-slate-500 dark:text-slate-400 font-bold">{{ __('organization.bulk_sms.inter_recipients') }}</div>
                                    <div class="text-purple-600 dark:text-purple-400 font-mono font-bold" dir="ltr">
                                        {{ estimateResult.inter_recipients || 0 }} × {{ estimateResult.inter_rate ?? estimateResult.per_segment_rate }} {{ currency }}
                                    </div>
                                </div>
                            </div>

                            <div v-if="!estimateResult.has_sufficient_balance" class="text-rose-500 text-xs font-bold pt-1">
                                ⚠️ {{ __('organization.bulk_sms.insufficient_org_balance') }}
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-between pt-2">
                            <button
                                type="button"
                                @click="runCostEstimation"
                                :disabled="isEstimating"
                                class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition-all disabled:opacity-50"
                            >
                                {{ __('organization.bulk_sms.estimate_cost') }}
                            </button>

                            <button
                                type="submit"
                                :disabled="form.processing || (estimateResult !== null && !estimateResult.has_sufficient_balance)"
                                class="px-6 py-2.5 rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white text-xs font-black shadow-lg shadow-purple-600/30 transition-all disabled:opacity-50 active:scale-95"
                            >
                                {{ __('organization.bulk_sms.send_campaign') }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Recent Batches Card (1 Column) -->
                <div class="p-6 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm space-y-4">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.bulk_sms.recent_batches') }}
                    </h3>

                    <div v-if="recent_batches.length === 0" class="py-8 text-center text-xs text-slate-400">
                        {{ __('organization.bulk_sms.no_batches') }}
                    </div>

                    <div v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                        <div
                            v-for="batch in recent_batches"
                            :key="batch.batch_id"
                            class="py-3 space-y-1.5 text-xs cursor-pointer hover:bg-slate-50/50 dark:hover:bg-slate-800/40 p-2 rounded-xl transition-colors"
                            @click="viewBatchDetails(batch)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                                    {{ batch.batch_id.substring(0, 10) }}...
                                </span>
                                <span
                                    class="px-2 py-0.5 rounded-md text-[10px] font-bold"
                                    :class="batch.status === 'completed' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-amber-500/10 text-amber-500'"
                                >
                                    {{ batch.status }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-[11px] text-slate-400">
                                <span>{{ batch.created_at }}</span>
                                <span class="font-mono">
                                    <span class="text-emerald-500 font-bold">✓ {{ batch.success_count }}</span> /
                                    <span class="text-slate-400">{{ batch.total_count }}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch Details Modal -->
        <Modal :show="isBatchModalOpen" max-width="md" @close="isBatchModalOpen = false">
            <div class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">
                        {{ __('organization.bulk_sms.batch_details_modal_title') }}
                    </h3>
                    <button type="button" @click="isBatchModalOpen = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>

                <div v-if="selectedBatch" class="space-y-4">
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/60 dark:border-slate-700/60 space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">{{ __('organization.bulk_sms.batch_id') }}:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" dir="ltr">{{ selectedBatch.batch_id }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">{{ __('organization.team.status') }}:</span>
                            <span class="font-bold text-purple-600 dark:text-purple-400">{{ selectedBatch.status }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-400">{{ __('organization.bulk_sms.created_at') }}:</span>
                            <span class="text-slate-500">{{ selectedBatch.created_at }}</span>
                        </div>
                    </div>

                    <!-- Progress Stats -->
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200/50 dark:border-slate-700/50">
                            <span class="text-[10px] text-slate-400 block">{{ __('organization.bulk_sms.total_segments') }}</span>
                            <span class="text-sm font-black font-mono text-slate-800 dark:text-slate-200">{{ selectedBatch.total_count }}</span>
                        </div>
                        <div class="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                            <span class="text-[10px] text-emerald-500 block">{{ __('organization.bulk_sms.sent_count') }}</span>
                            <span class="text-sm font-black font-mono text-emerald-500">{{ selectedBatch.success_count }}</span>
                        </div>
                        <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20">
                            <span class="text-[10px] text-rose-500 block">{{ __('organization.bulk_sms.failed_count') }}</span>
                            <span class="text-sm font-black font-mono text-rose-500">{{ selectedBatch.failed_count }}</span>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="button"
                            @click="isBatchModalOpen = false"
                            class="px-5 py-2 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-xs font-bold text-slate-800 dark:text-white"
                        >
                            {{ __('organization.bulk_sms.close') }}
                        </button>
                    </div>
                </div>
            </div>
        </Modal>
    </OrgLayout>
</template>
