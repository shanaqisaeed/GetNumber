<script setup lang="ts">
import { ref, onMounted, nextTick, watch, computed } from 'vue';
import type { ChatMessage } from '@/Composables/useChatConversation';
import ChatMessageItem from './ChatMessageItem.vue';
import MessageComposer from './MessageComposer.vue';

const props = defineProps<{
    peer: string;
    peerDetails: {
        number: string;
        formatted_number: string;
        title: string;
        type?: string;
        type_label?: string;
        badge_class?: string;
        is_own_number?: boolean;
        is_commercial?: boolean;
        accepts_incoming_sms?: boolean;
        can_reply?: boolean;
        can_reply_reason?: string;
    } | null;
    currentLineNumber: string;
    currentSimIndex: number;
    messages: ChatMessage[];
    isLoading: boolean;
    isLoadingOlder: boolean;
    hasMoreOlder: boolean;
    isSending: boolean;
    tariffRate?: string;
}>();

const emit = defineEmits<{
    (e: 'back'): void;
    (e: 'send', text: string): void;
    (e: 'retry', msg: ChatMessage): void;
    (e: 'load-older'): void;
    (e: 'copy', text: string): void;
}>();

const chatContainer = ref<HTMLElement | null>(null);
const isScrolledUp = ref(false);

// Scroll helper
const scrollToBottom = (smooth = false, force = false) => {
    if (isScrolledUp.value && !force) return;

    const doScroll = () => {
        if (chatContainer.value) {
            chatContainer.value.scrollTo({
                top: chatContainer.value.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        }
    };

    nextTick(() => {
        doScroll();
        setTimeout(doScroll, 50);
    });
};

const onScroll = () => {
    if (!chatContainer.value) return;
    const { scrollTop, scrollHeight, clientHeight } = chatContainer.value;
    const distanceFromBottom = scrollHeight - scrollTop - clientHeight;
    isScrolledUp.value = distanceFromBottom > 120;

    // Auto-fetch older messages when near top
    if (scrollTop < 30 && !props.isLoadingOlder && props.hasMoreOlder && props.messages.length >= 20) {
        emit('load-older');
    }
};

// Preserve scroll position when older messages are loaded
const preserveScrollAfterPrepend = (prevScrollHeight: number, prevScrollTop: number) => {
    nextTick(() => {
        if (chatContainer.value) {
            const newScrollHeight = chatContainer.value.scrollHeight;
            chatContainer.value.scrollTop = prevScrollTop + (newScrollHeight - prevScrollHeight);
        }
    });
};

defineExpose({
    scrollToBottom,
    preserveScrollAfterPrepend,
    chatContainer,
});

// Auto-scroll when new messages arrive (unless user scrolled up)
watch(() => props.messages.length, (newLen, oldLen) => {
    if (newLen > oldLen) {
        // If the newest message is outbound or user is at bottom, auto scroll
        const last = props.messages[props.messages.length - 1];
        if (last?.direction === 'outbound' || !isScrolledUp.value) {
            scrollToBottom(true);
        }
    }
});

onMounted(() => {
    scrollToBottom(false, true);
    setTimeout(() => scrollToBottom(false, true), 120);
});

const copyHeader = () => {
    const textToCopy = props.peerDetails?.is_commercial
        ? (props.peerDetails?.title || '')
        : (props.peerDetails?.formatted_number || props.peer);
    emit('copy', textToCopy);
};
</script>

<template>
    <div class="space-y-3">
        <!-- Chat Navigation Header -->
        <div class="glass-card !p-3 flex items-center justify-between gap-2.5 bg-white/95 dark:bg-slate-900/80 border-slate-200/90 dark:border-white/10">
            <div class="flex items-center gap-2.5 min-w-0">
                <!-- Back Button (RTL points right) -->
                <button
                    type="button"
                    @click="emit('back')"
                    class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20 text-slate-800 dark:text-white flex items-center justify-center shrink-0 transition-colors"
                    :title="__('user.messages.back_to_conversations')"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                <!-- Contact Details -->
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs font-black text-slate-900 dark:text-white truncate">
                            {{ peerDetails?.title || peer }}
                        </span>
                        <span v-if="peerDetails?.type_label" class="text-[9px] px-1.5 py-0.2 rounded-md font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                            {{ peerDetails?.type_label }}
                        </span>
                    </div>
                    <div v-if="!peerDetails?.is_commercial" dir="ltr" class="text-[10px] font-mono text-slate-500 dark:text-slate-400 text-right">
                        {{ peerDetails?.formatted_number || peer }}
                    </div>
                    <div v-else class="text-[10px] text-violet-600 dark:text-violet-400 font-bold flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span>
                        <span>{{ __('user.messages.platform_commercial_prefix') }}</span>
                    </div>
                    <div v-if="currentLineNumber" class="text-[9px] text-indigo-600 dark:text-indigo-400 font-bold truncate">
                        {{ __('user.messages.send_from', { sim: currentSimIndex, line: currentLineNumber }) }}
                    </div>
                </div>
            </div>

            <!-- Header Action: Copy Number / Title -->
            <div class="flex items-center gap-1.5 shrink-0">
                <button
                    type="button"
                    @click="copyHeader"
                    class="w-8 h-8 rounded-xl glass-pill !p-0 flex items-center justify-center text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                    :title="peerDetails?.is_commercial ? __('user.messages.copy_prefix_name') : __('user.messages.copy_number')"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Scrollable Messages Container -->
        <div class="relative">
            <div
                ref="chatContainer"
                @scroll="onScroll"
                class="glass-card chat-messages-scroll-area !p-3.5 space-y-3 h-[60vh] max-h-[620px] min-h-[380px] bg-white/70 dark:bg-slate-950/50 border-slate-200/80 dark:border-white/10 overflow-y-auto"
            >
                <!-- Load earlier messages button -->
                <div v-if="hasMoreOlder && messages.length >= 20" class="text-center py-1">
                    <button
                        type="button"
                        @click="emit('load-older')"
                        :disabled="isLoadingOlder"
                        class="text-[11px] font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/70 border border-indigo-200 dark:border-indigo-800/80 px-3 py-1 rounded-xl transition-all inline-flex items-center gap-1.5 shadow-sm disabled:opacity-50"
                    >
                        <svg v-if="isLoadingOlder" class="animate-spin w-3.5 h-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                        </svg>
                        <span>{{ isLoadingOlder ? __('user.messages.loading_older') : __('user.messages.load_older') }}</span>
                    </button>
                </div>

                <!-- Initial Loading indicator -->
                <div v-if="isLoading" class="py-12 text-center text-xs text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2">
                    <svg class="animate-spin w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ __('user.messages.loading_history') }}</span>
                </div>

                <!-- Message Bubbles Stream -->
                <div v-else-if="messages.length > 0" class="space-y-2.5">
                    <ChatMessageItem
                        v-for="msg in messages"
                        :key="msg.client_message_id || msg.id"
                        :message="msg"
                        @retry="emit('retry', msg)"
                        @copy="emit('copy', $event)"
                    />
                </div>

                <!-- Empty thread placeholder -->
                <div v-else class="py-16 text-center text-xs text-slate-500 dark:text-slate-400 space-y-2">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 mx-auto flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                    </div>
                    <p>{{ __('user.messages.no_messages') }}</p>
                </div>
            </div>

            <!-- Floating Scroll-to-Bottom Anchor Button -->
            <transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 translate-y-2 scale-90" enter-to-class="opacity-100 translate-y-0 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 translate-y-0 scale-100" leave-to-class="opacity-0 translate-y-2 scale-90">
                <button
                    v-if="isScrolledUp"
                    type="button"
                    @click="scrollToBottom(true, true)"
                    class="absolute bottom-4 left-4 z-20 w-9 h-9 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-600/30 flex items-center justify-center transition-all active:scale-95 border border-white/20"
                    :title="__('user.messages.scroll_to_bottom')"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                    </svg>
                </button>
            </transition>
        </div>

        <!-- Chat Composer Bar -->
        <MessageComposer
            :disabled="isSending"
            :tariff-rate="tariffRate"
            :can-reply="peerDetails?.can_reply"
            :can-reply-reason="peerDetails?.can_reply_reason"
            @send="emit('send', $event)"
        />
    </div>
</template>

