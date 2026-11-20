<script setup lang="ts">
import { ref, computed } from 'vue';
import ContactPickerModal, { type ContactItem } from '@/Components/ContactPickerModal.vue';
import { __ } from '@/Composables/useTrans';
import axios from 'axios';

const props = withDefaults(defineProps<{
    selectedLineId: number | null;
    currentLine?: any | null;
    lines?: any[];
    contacts?: ContactItem[];
    initialRecipient?: string;
}>(), {
    lines: () => [],
    contacts: () => [],
});

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'sent', result: any): void;
    (e: 'toast', message: string): void;
    (e: 'switch-line', lineId: number, lineKey?: string): void;
}>();

const recipient = ref(props.initialRecipient || '');
const messageText = ref('');
const isSending = ref(false);
const errorMessage = ref<string | null>(null);
const isContactPickerOpen = ref(false);

const activeLineId = ref<number | null>(
    props.selectedLineId || props.currentLine?.id || (props.lines.length > 0 ? props.lines[0].id : null)
);

const activeLine = computed(() => {
    return props.lines.find(l => l.id === activeLineId.value) || props.currentLine || props.lines[0] || null;
});

function handleContactSelected(c: ContactItem) {
    recipient.value = c.phone_number;
    isContactPickerOpen.value = false;
}

function onLineSelectChange(event: Event) {
    const val = Number((event.target as HTMLSelectElement).value);
    activeLineId.value = val;
    const found = props.lines.find(l => l.id === val);
    if (found) {
        emit('switch-line', found.id, found.key);
    }
}

async function sendDirectMessage() {
    const targetLineId = activeLineId.value || props.selectedLineId;
    if (!recipient.value.trim() || !messageText.value.trim() || !targetLineId) {
        if (!targetLineId) {
            errorMessage.value = __('softphone.sms.no_active_line_error');
        }
        return;
    }

    isSending.value = true;
    errorMessage.value = null;

    try {
        const response = await axios.post(route('messages.send'), {
            from_number_id: targetLineId,
            destinations: recipient.value.trim(),
            body: messageText.value.trim(),
        });

        if (response.data?.success) {
            emit('sent', {
                peer: recipient.value.trim(),
                message: response.data.created_message || response.data.message,
            });
            emit('close');
        } else {
            errorMessage.value = response.data?.message || __('softphone.sms.delivery_failed');
        }
    } catch (err: any) {
        errorMessage.value = err.response?.data?.message || __('softphone.sms.delivery_failed');
    } finally {
        isSending.value = false;
    }
}
</script>

<template>
    <div class="glass-card p-6 rounded-3xl border border-slate-200 dark:border-white/10 space-y-5 bg-white/95 dark:bg-slate-900/90 shadow-xl">
        <!-- Contact Picker Modal -->
        <ContactPickerModal
            :show="isContactPickerOpen"
            :contacts="contacts"
            @close="isContactPickerOpen = false"
            @select="handleContactSelected"
        />

        <!-- Modal Header -->
        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
            <h3 class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                <span>💬</span>
                <span>{{ __('softphone.sms.new_conversation') }}</span>
            </h3>
            <button type="button" @click="emit('close')" class="p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-white/10 text-slate-400 hover:text-slate-800 dark:hover:text-white text-xs transition-colors">
                ✕
            </button>
        </div>

        <div v-if="errorMessage" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold">
            {{ errorMessage }}
        </div>

        <!-- Sender Line Display & Selector -->
        <div class="p-3.5 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-500/20 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-indigo-700 dark:text-indigo-300">
                    {{ __('softphone.sms.sender_line') }}
                </span>
                <span v-if="activeLine" class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-md bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/30">
                    {{ activeLine.type_label || __('softphone.sms.active_sim_badge') }}
                </span>
            </div>

            <!-- Single Line Display or Multi-Line Selector -->
            <div v-if="lines.length > 1" class="pt-1">
                <select
                    :value="activeLineId"
                    @change="onLineSelectChange"
                    class="w-full py-2 px-3 rounded-xl bg-white dark:bg-slate-900 border border-indigo-300 dark:border-indigo-500/40 text-xs font-bold font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500"
                >
                    <option v-for="l in lines" :key="l.id" :value="l.id">
                        {{ l.formatted_number || l.number }} ({{ l.effective_title || l.display_title }})
                    </option>
                </select>
            </div>
            <div v-else-if="activeLine" class="flex items-center justify-between pt-0.5">
                <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                    {{ activeLine.effective_title || activeLine.display_title }}
                </span>
                <span class="font-mono font-black text-sm text-indigo-700 dark:text-indigo-300" dir="ltr">
                    {{ activeLine.formatted_number || activeLine.number }}
                </span>
            </div>
            <div v-else class="text-xs text-amber-500 font-bold">
                {{ __('softphone.sms.no_active_line_error') }}
            </div>
        </div>

        <!-- Recipient Input with Contact Picker -->
        <div class="space-y-1.5">
            <div class="flex items-center justify-between">
                <label class="text-xs font-bold text-slate-500 dark:text-slate-400">
                    {{ __('softphone.sms.recipient') }}
                </label>
                <button
                    v-if="contacts.length > 0"
                    type="button"
                    @click="isContactPickerOpen = true"
                    class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1"
                >
                    <span>👥</span>
                    <span>{{ __('softphone.sms.pick_from_contacts') }}</span>
                </button>
            </div>

            <div class="relative flex items-center">
                <input
                    v-model="recipient"
                    type="text"
                    dir="ltr"
                    :placeholder="__('softphone.sms.recipient')"
                    class="w-full py-3 px-4 pr-11 rounded-2xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 font-mono text-sm text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500"
                />
                <button
                    type="button"
                    @click="isContactPickerOpen = true"
                    class="absolute right-3 p-1 text-slate-400 hover:text-indigo-500 transition-colors"
                    :title="__('softphone.sms.pick_from_contacts')"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Message Body -->
        <div class="space-y-1.5">
            <label class="text-xs font-bold text-slate-500 dark:text-slate-400">
                {{ __('softphone.sms.type_message') }}
            </label>
            <textarea
                v-model="messageText"
                rows="4"
                :placeholder="__('softphone.sms.type_message')"
                class="w-full p-4 rounded-2xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 text-sm text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 resize-none"
            ></textarea>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3 pt-2">
            <button
                type="button"
                @click="emit('close')"
                class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-600 dark:text-slate-300 text-xs font-bold transition-all"
            >
                {{ __('softphone.dialer.decline') }}
            </button>
            <button
                type="button"
                @click="sendDirectMessage"
                :disabled="!recipient.trim() || !messageText.trim() || isSending || !activeLineId"
                class="px-6 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 text-white text-xs font-black shadow-lg shadow-indigo-600/30 flex items-center gap-2 transition-all active:scale-95"
            >
                <svg v-if="!isSending" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
                <span>{{ isSending ? __('softphone.sms.sending') : __('softphone.sms.send') }}</span>
            </button>
        </div>
    </div>
</template>
