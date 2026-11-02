<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import UserLayout from '@/Layouts/UserLayout.vue';

// Subcomponents
import LineSelector, { LineItem } from './Components/LineSelector.vue';
import ConversationList, { ConversationItem } from './Components/ConversationList.vue';
import ChatWindow from './Components/ChatWindow.vue';
import BulkSendModal from './Components/BulkSendModal.vue';

// Composables & Utilities
import { useRealtimeMessages, RealtimeMessagePayload, RealtimeStatusPayload } from '@/Composables/useRealtimeMessages';
import { useChatConversation, ChatMessage } from '@/Composables/useChatConversation';

const props = defineProps<{
    allocatedNumbers: LineItem[];
    currentNumber: LineItem | null;
    conversations: ConversationItem[];
    activePeer: string | null;
    activePeerDetails: any | null;
    activeMessages: ChatMessage[];
    stats: {
        total_messages: number;
        today_messages: number;
        allocated_lines_count: number;
    };
    search?: string;
    smsTariff?: {
        rate_per_segment: string;
        currency: string;
    };
}>();

// Line / SIM selection
const selectedLineId = ref<number | null>(props.currentNumber?.id || null);
const allocatedNumbersList = ref<LineItem[]>(JSON.parse(JSON.stringify(props.allocatedNumbers || [])));

// Current active line item
const currentLine = computed(() => {
    return allocatedNumbersList.value.find(l => l.id === selectedLineId.value) || null;
});

const currentSimIndex = computed(() => {
    const idx = allocatedNumbersList.value.findIndex(l => l.id === selectedLineId.value);
    return idx >= 0 ? idx + 1 : 1;
});

// Conversations list & Active chat state
const conversationList = ref<ConversationItem[]>([...props.conversations]);
const currentActivePeer = ref<string | null>(props.activePeer || null);
const currentPeerInfo = ref<any>(props.activePeerDetails || null);
const isComposingNew = ref<boolean>(false);
const searchQuery = ref(props.search || '');
const isSendingMessage = ref<boolean>(false);

// Chat Conversation State Manager
const {
    messages,
    isLoading: isLoadingChat,
    isLoadingOlder,
    hasMoreOlder,
    loadConversation,
    loadOlderMessages,
    sendOptimisticMessage,
    retryMessage,
    handleIncomingMessage,
    handleStatusUpdate,
    getHighestMessageId,
} = useChatConversation();

// Reference to ChatWindow component for scroll manipulation
const chatWindowRef = ref<any>(null);

// Initialize chat messages if peer was loaded from server URL
if (props.activePeer && props.activeMessages) {
    loadConversation(props.currentNumber?.id || 0, props.activePeer, props.activeMessages);
}

// Toast notification state
const toastMessage = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | null = null;
const showToast = (msg: string) => {
    toastMessage.value = msg;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = null;
    }, 2800);
};

// Copy helper
const copyToClipboard = async (text: string, label = 'شماره کپی شد') => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }
        showToast(label);
    } catch {
        showToast(label);
    }
};

const page = usePage();

// Real-Time WebSockets Engine with Intelligent Polling Fallback
const { connectionLabel, connectionStatusDotClass, isWebSocketActive, performPoll } = useRealtimeMessages({
    userId: (page.props.auth as any)?.user?.id,
    lineId: selectedLineId.value,
    isAdmin: !!(page.props.auth as any)?.user?.is_admin,
    pollUrl: route('messages.poll'),
    pollParams: () => {
        const params: Record<string, any> = {
            phone_number_id: selectedLineId.value,
        };
        if (currentActivePeer.value && !isComposingNew.value) {
            params.peer = currentActivePeer.value;
            params.after_id = getHighestMessageId();
        }
        if (searchQuery.value) {
            params.search = searchQuery.value;
        }
        return params;
    },
    onPollResponse: (data) => {
        if (Array.isArray(data.conversations)) {
            conversationList.value = data.conversations;
        }

        if (data.unread_counts_by_line && typeof data.unread_counts_by_line === 'object') {
            allocatedNumbersList.value.forEach(line => {
                line.unread_count = Number(data.unread_counts_by_line[line.id] || 0);
            });
        }

        if (data.statuses && typeof data.statuses === 'object') {
            Object.keys(data.statuses).forEach(idStr => {
                handleStatusUpdate({ id: Number(idStr), processing_status: data.statuses[idStr] });
            });
        }

        if (Array.isArray(data.new_messages) && data.new_messages.length > 0 && currentActivePeer.value && !isComposingNew.value) {
            data.new_messages.forEach((m: any) => {
                handleIncomingMessage(m);
            });
        }
    },
    onMessage: (msg: RealtimeMessagePayload) => {
        if (selectedLineId.value && msg.phone_number_id === selectedLineId.value) {
            const isMatchActiveChat = currentActivePeer.value &&
                (msg.peer_number === currentActivePeer.value || msg.sender === currentActivePeer.value);

            if (isMatchActiveChat && !isComposingNew.value) {
                handleIncomingMessage(msg);
            }

            // Update conversation snippet dynamically
            const convIndex = conversationList.value.findIndex(c => c.peer_number === msg.peer_number);
            if (convIndex !== -1) {
                const conv = conversationList.value[convIndex];
                conv.latest_message = {
                    id: msg.id,
                    body: msg.body,
                    direction: msg.direction,
                    server_received_at: msg.server_received_at,
                    server_received_at_formatted: msg.server_received_at_formatted,
                };
                conv.messages_count += 1;
                if (!isMatchActiveChat && msg.direction === 'inbound') {
                    conv.unread_count = (conv.unread_count || 0) + 1;
                }
                conversationList.value.splice(convIndex, 1);
                conversationList.value.unshift(conv);
            } else {
                performPoll();
            }

            const currentLineItem = allocatedNumbersList.value.find(l => l.id === selectedLineId.value);
            if (currentLineItem && msg.direction === 'inbound' && !isMatchActiveChat) {
                currentLineItem.unread_count = (currentLineItem.unread_count || 0) + 1;
            }
        } else if (msg.direction === 'inbound') {
            const otherLine = allocatedNumbersList.value.find(l => l.id === msg.phone_number_id);
            if (otherLine) {
                otherLine.unread_count = (otherLine.unread_count || 0) + 1;
            }
        }
    },
    onStatusUpdate: (status: RealtimeStatusPayload) => {
        handleStatusUpdate(status);
    },
    onUnreadUpdate: (totalUnread, byLine) => {
        if (byLine) {
            allocatedNumbersList.value.forEach(line => {
                if (typeof byLine[line.id] === 'number') {
                    line.unread_count = byLine[line.id];
                }
            });
        }
    },
});

// Switching sending line
const switchLine = (lineId: number) => {
    if (selectedLineId.value === lineId) return;
    selectedLineId.value = lineId;
    router.get(
        route('messages.index'),
        {
            phone_number_id: lineId,
            search: searchQuery.value || undefined,
        },
        { preserveState: false }
    );
};

// Select a conversation from list
const selectConversation = async (conv: ConversationItem) => {
    isComposingNew.value = false;
    currentActivePeer.value = conv.peer_number;
    currentPeerInfo.value = {
        number: conv.peer_number,
        formatted_number: conv.peer_formatted,
        title: conv.peer_title,
        type: conv.peer_type,
        type_label: conv.peer_type_label,
        badge_class: conv.peer_badge_class,
        is_own_number: conv.is_own_number,
        is_commercial: conv.is_commercial,
        accepts_incoming_sms: conv.accepts_incoming_sms,
        can_reply: conv.can_reply,
        can_reply_reason: conv.can_reply_reason,
    };

    // Mark as read immediately in local state
    const foundIndex = conversationList.value.findIndex(c => c.peer_number === conv.peer_number);
    if (foundIndex !== -1) {
        const clearedCount = conversationList.value[foundIndex].unread_count || 0;
        conversationList.value[foundIndex].unread_count = 0;
        const currentLineItem = allocatedNumbersList.value.find(l => l.id === selectedLineId.value);
        if (currentLineItem && clearedCount > 0) {
            currentLineItem.unread_count = Math.max(0, (currentLineItem.unread_count || 0) - clearedCount);
        }
    }

    if (selectedLineId.value) {
        await loadConversation(selectedLineId.value, conv.peer_number);
    }
};

// Return to conversations list (Mobile Back Button)
const closeChatOrComposer = () => {
    currentActivePeer.value = null;
    currentPeerInfo.value = null;
    isComposingNew.value = false;
    performPoll();
};

// Start composing a new message
const startNewMessage = () => {
    isComposingNew.value = true;
    currentActivePeer.value = null;
    currentPeerInfo.value = null;
};

// Handle optimistic message send from ChatWindow
const handleSendMessage = async (text: string) => {
    if (!selectedLineId.value || !currentActivePeer.value) return;

    isSendingMessage.value = true;
    await sendOptimisticMessage(
        selectedLineId.value,
        currentActivePeer.value,
        text,
        currentLine.value?.number || '',
        (sentMsg) => {
            // Update conversation list latest message
            const convIdx = conversationList.value.findIndex(c => c.peer_number === currentActivePeer.value);
            if (convIdx !== -1) {
                const conv = conversationList.value[convIdx];
                conv.latest_message = {
                    id: sentMsg.id,
                    body: sentMsg.body,
                    direction: 'outbound',
                    server_received_at: sentMsg.server_received_at,
                    server_received_at_formatted: 'هم‌اکنون',
                };
                conv.messages_count += 1;
                conversationList.value.splice(convIdx, 1);
                conversationList.value.unshift(conv);
            }
        },
        (errMsg) => {
            showToast(errMsg);
        }
    );
    isSendingMessage.value = false;
};

// Retry failed message
const handleRetryMessage = async (msg: ChatMessage) => {
    if (!selectedLineId.value) return;
    await retryMessage(
        msg,
        selectedLineId.value,
        () => {
            showToast('پیامک با موفقیت ارسال شد');
        },
        (errMsg) => {
            showToast(errMsg);
        }
    );
};

// Load older messages with scroll restoration
const handleLoadOlder = async () => {
    if (!selectedLineId.value || !currentActivePeer.value || !chatWindowRef.value?.chatContainer) return;

    const container = chatWindowRef.value.chatContainer as HTMLElement;
    const prevScrollHeight = container.scrollHeight;
    const prevScrollTop = container.scrollTop;

    await loadOlderMessages(selectedLineId.value, currentActivePeer.value, () => {
        chatWindowRef.value?.preserveScrollAfterPrepend(prevScrollHeight, prevScrollTop);
    });
};

// Handle bulk send completion
const handleBulkSent = (payload: any) => {
    if (!payload.isBatch && payload.destinations?.length === 1) {
        const targetPeer = payload.destinations[0].replace(/^\+?85/, '').trim();
        router.get(
            route('messages.index'),
            { phone_number_id: selectedLineId.value, peer: targetPeer },
            { preserveState: false }
        );
    } else {
        closeChatOrComposer();
    }
};

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        if (isComposingNew.value || currentActivePeer.value) {
            closeChatOrComposer();
        }
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleEscape);
});
</script>

<template>
    <Head title="هاب پیام‌رسانی و گفتگو" />

    <UserLayout>
        <!-- Floating Toast Notification -->
        <teleport to="body">
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-4 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 -translate-y-2 scale-95"
            >
                <div
                    v-if="toastMessage"
                    class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-900/95 text-white border border-indigo-500/50 shadow-2xl backdrop-blur-xl flex items-center gap-2.5 text-xs font-bold pointer-events-none"
                >
                    <div class="w-5 h-5 rounded-lg bg-emerald-500/25 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <polyline points="20 6 9 17 4 12" stroke-width="2.5"></polyline>
                        </svg>
                    </div>
                    <span>{{ toastMessage }}</span>
                </div>
            </transition>
        </teleport>

        <div class="space-y-4">
            <!-- ========================================================== -->
            <!-- VIEW A: CONVERSATIONS LIST (When no chat is active)        -->
            <!-- ========================================================== -->
            <div v-if="!currentActivePeer && !isComposingNew" class="space-y-3.5">
                <!-- 1. High-Tech Multi-SIM Switcher Card -->
                <LineSelector
                    :lines="allocatedNumbersList"
                    :selected-line-id="selectedLineId"
                    :is-web-socket-active="isWebSocketActive"
                    :connection-label="connectionLabel"
                    :connection-status-dot-class="connectionStatusDotClass"
                    @switch-line="switchLine"
                />

                <!-- 2. Conversations List with Virtual Scrolling -->
                <ConversationList
                    :conversations="conversationList"
                    :has-current-number="!!currentLine"
                    :initial-search="searchQuery"
                    @select="selectConversation"
                    @start-new="startNewMessage"
                    @search-change="searchQuery = $event"
                />
            </div>

            <!-- ========================================================== -->
            <!-- VIEW B: ACTIVE CHAT SCREEN (Mobile Chat Experience)        -->
            <!-- ========================================================== -->
            <div v-else-if="currentActivePeer">
                <ChatWindow
                    ref="chatWindowRef"
                    :peer="currentActivePeer"
                    :peer-details="currentPeerInfo"
                    :current-line-number="currentLine?.formatted_number || ''"
                    :current-sim-index="currentSimIndex"
                    :messages="messages"
                    :is-loading="isLoadingChat"
                    :is-loading-older="isLoadingOlder"
                    :has-more-older="hasMoreOlder"
                    :is-sending="isSendingMessage"
                    :tariff-rate="smsTariff?.rate_per_segment || '0.0500'"
                    @back="closeChatOrComposer"
                    @send="handleSendMessage"
                    @retry="handleRetryMessage"
                    @load-older="handleLoadOlder"
                    @copy="copyToClipboard($event)"
                />
            </div>

            <!-- ========================================================== -->
            <!-- VIEW C: NEW MESSAGE / BULK SEND SCREEN                     -->
            <!-- ========================================================== -->
            <div v-else-if="isComposingNew">
                <BulkSendModal
                    :selected-line-id="selectedLineId"
                    :tariff-rate="smsTariff?.rate_per_segment || '0.0500'"
                    @close="closeChatOrComposer"
                    @sent="handleBulkSent"
                    @toast="showToast"
                />
            </div>
        </div>
    </UserLayout>
</template>
