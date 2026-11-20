<script setup lang="ts">
import { ref, onMounted } from 'vue';
import ContactPickerModal, { type ContactItem } from '@/Components/ContactPickerModal.vue';
import { __ } from '@/Composables/useTrans';
import axios from 'axios';

const props = defineProps<{
    selectedLineId: number | null;
    initialRecipient?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'sent', result: any): void;
    (e: 'toast', message: string): void;
}>();

const recipient = ref(props.initialRecipient || '');
const messageText = ref('');
const isSending = ref(false);
const errorMessage = ref<string | null>(null);
const isContactPickerOpen = ref(false);

function handleContactSelected(c: ContactItem) {
    recipient.value = c.phone_number;
    isContactPickerOpen.value = false;
}

async function sendDirectMessage() {
    if (!recipient.value || !messageText.value || !props.selectedLineId) return;

    isSending.value = true;
    errorMessage.value = null;

    try {
        const response = await axios.post(route('messages.send'), {
            phone_number_id: props.selectedLineId,
            destination: recipient.value,
            body: messageText.value,
        });

        if (response.data?.success) {
            emit('sent', {
                peer: recipient.value,
                message: response.data.message,
            });
            emit('close');
        }
    } catch (err: any) {
        errorMessage.value = err.response?.data?.message || __('softphone.sms.delivery_failed');
    } finally {
        isSending.value = false;
    }
}
</script>

<template>
    <div class="glass-card p-6 rounded-3xl border border-slate-200 dark:border-white/10 space-y-5">
        <ContactPickerModal
            :show="isContactPickerOpen"
            :contacts="[]"
            @close="isContactPickerOpen = false"
            @select="handleContactSelected"
        />

        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-white/10">
            <h3 class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                <span>💬</span>
                <span>{{ __('softphone.sms.new_conversation') }}</span>
            </h3>
            <button type="button" @click="emit('close')" class="p-1 text-slate-400 hover:text-white text-xs">
                ✕
            </button>
        </div>

        <div v-if="errorMessage" class="p-3 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-500 text-xs font-bold">
            {{ errorMessage }}
        </div>

        <!-- Recipient Input -->
        <div class="space-y-1.5">
            <label class="text-xs font-bold text-slate-400">
                {{ __('softphone.sms.recipient') }}
            </label>
            <div class="flex items-center gap-2">
                <input
                    v-model="recipient"
                    type="text"
                    dir="ltr"
                    :placeholder="__('softphone.sms.recipient')"
                    class="flex-1 py-3 px-4 rounded-2xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 font-mono text-sm text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500"
                />
            </div>
        </div>

        <!-- Message Body -->
        <div class="space-y-1.5">
            <label class="text-xs font-bold text-slate-400">
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
                class="px-4 py-2.5 rounded-2xl bg-slate-200/50 dark:bg-white/5 text-slate-600 dark:text-slate-300 text-xs font-bold hover:bg-slate-200 transition-all"
            >
                {{ __('softphone.dialer.decline') }}
            </button>
            <button
                type="button"
                @click="sendDirectMessage"
                :disabled="!recipient || !messageText || isSending"
                class="px-6 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-black shadow-lg shadow-indigo-600/30 transition-all"
            >
                {{ isSending ? __('softphone.sms.sending') : __('softphone.sms.send') }}
            </button>
        </div>
    </div>
</template>
