<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';

interface LineItem {
    id: number;
    number: string;
    formatted_number: string;
    effective_title: string;
    type: string;
    type_label: string;
    badge_class: string;
}

interface ConversationItem {
    peer_number: string;
    peer_formatted: string;
    peer_title: string;
    peer_type?: string;
    peer_type_label?: string;
    peer_badge_class?: string;
    is_own_number?: boolean;
    latest_message: {
        id: number;
        body: string;
        direction: 'inbound' | 'outbound';
        server_received_at: string;
        server_received_at_formatted: string;
    };
    messages_count: number;
    unread_count: number;
}

interface ChatMessage {
    id: number;
    phone_number_id: number;
    destination_number: string;
    sender: string;
    peer_number: string;
    direction: 'inbound' | 'outbound';
    body: string;
    server_received_at: string;
    server_received_at_formatted: string;
    processing_status: string;
}

const props = defineProps<{
    allocatedNumbers: LineItem[];
    currentNumber: LineItem | null;
    conversations: ConversationItem[];
    activePeer: string | null;
    activePeerDetails: {
        number: string;
        formatted_number: string;
        title: string;
        type?: string;
        type_label?: string;
        badge_class?: string;
        is_own_number?: boolean;
    } | null;
    activeMessages: ChatMessage[];
    stats: {
        total_messages: number;
        today_messages: number;
        allocated_lines_count: number;
    };
    search?: string;
}>();

// Selected line ID (SIM / sender number switcher)
const selectedLineId = ref<number | null>(props.currentNumber?.id || null);

// Conversations list & active chat state
const conversationList = ref<ConversationItem[]>([...props.conversations]);
const currentActivePeer = ref<string | null>(props.activePeer);
const chatMessages = ref<ChatMessage[]>([...props.activeMessages]);
const currentPeerInfo = ref(props.activePeerDetails);

// Search query in conversations list
const searchQuery = ref(props.search || '');

// Quick reply text in active conversation
const replyText = ref('');
const isSendingReply = ref(false);

// New Message Screen state (True phone "To:" box experience - no popup modals)
const isComposingNew = ref<boolean>(!props.activePeer && props.conversations.length === 0);
const recipientInput = ref('');
const recipientChips = ref<string[]>([]);
const composerText = ref('');
const isSendingComposer = ref(false);
const composeFeedback = ref<{ success: boolean; message: string; results?: any[] } | null>(null);

// Chat stream container for auto-scroll
const chatContainer = ref<HTMLElement | null>(null);

// Silent 4-second Polling in background
let pollingInterval: any = null;
const isPollingBusy = ref(false);

const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
};

const getHighestMessageId = () => {
    if (chatMessages.value.length === 0) return 0;
    return Math.max(...chatMessages.value.map(m => m.id));
};

// Background silent polling
const performSilentPoll = async () => {
    if (isPollingBusy.value || !selectedLineId.value) return;
    if (document.hidden) return;

    isPollingBusy.value = true;
    try {
        const params = new URLSearchParams();
        params.append('phone_number_id', String(selectedLineId.value));
        if (currentActivePeer.value && !isComposingNew.value) {
            params.append('peer', currentActivePeer.value);
            params.append('after_id', String(getHighestMessageId()));
        }
        if (searchQuery.value) {
            params.append('search', searchQuery.value);
        }

        const res = await fetch(`${route('messages.poll')}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (res.ok) {
            const data = await res.json();
            if (data.success) {
                if (Array.isArray(data.conversations)) {
                    conversationList.value = data.conversations;
                }

                if (Array.isArray(data.new_messages) && data.new_messages.length > 0 && !isComposingNew.value) {
                    const existingIds = new Set(chatMessages.value.map(m => m.id));
                    const fresh = data.new_messages.filter((m: ChatMessage) => !existingIds.has(m.id));
                    if (fresh.length > 0) {
                        chatMessages.value = [...chatMessages.value, ...fresh];
                        scrollToBottom();
                    }
                }
            }
        }
    } catch {
        // Silently tolerate
    } finally {
        isPollingBusy.value = false;
    }
};

onMounted(() => {
    scrollToBottom();
    pollingInterval = setInterval(performSilentPoll, 4000);
});

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval);
});

// Switching sending line (like switching SIM card on mobile)
const onLineChange = () => {
    if (!selectedLineId.value) return;
    router.get(
        route('messages.index'),
        {
            phone_number_id: selectedLineId.value,
            search: searchQuery.value || undefined,
        },
        { preserveState: false }
    );
};

// Start composing a new message (opens mobile "To:" recipient box)
const startNewMessage = () => {
    isComposingNew.value = true;
    currentActivePeer.value = null;
    currentPeerInfo.value = null;
    recipientChips.value = [];
    recipientInput.value = '';
    composerText.value = '';
    composeFeedback.value = null;
    chatMessages.value = [];
};

// Add a recipient chip into the "To:" box
const addRecipientChip = (raw: string) => {
    const val = raw.trim();
    if (!val) return;

    if (!recipientChips.value.includes(val)) {
        recipientChips.value.push(val);
    }
    recipientInput.value = '';

    // If exactly 1 recipient, check if there is an existing conversation to preview history
    if (recipientChips.value.length === 1) {
        checkAndLoadExistingHistory(recipientChips.value[0]);
    } else {
        chatMessages.value = [];
    }
};

const removeRecipientChip = (index: number) => {
    recipientChips.value.splice(index, 1);
    if (recipientChips.value.length === 1) {
        checkAndLoadExistingHistory(recipientChips.value[0]);
    } else {
        chatMessages.value = [];
    }
};

const onRecipientKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
        e.preventDefault();
        addRecipientChip(recipientInput.value);
    } else if (e.key === 'Backspace' && !recipientInput.value && recipientChips.value.length > 0) {
        recipientChips.value.pop();
    }
};

// Quick load history if recipient already has conversation
const checkAndLoadExistingHistory = async (peer: string) => {
    if (!selectedLineId.value) return;
    try {
        const res = await fetch(
            `${route('messages.conversation')}?phone_number_id=${selectedLineId.value}&peer=${encodeURIComponent(peer)}`,
            {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }
        );
        if (res.ok) {
            const data = await res.json();
            if (data.success && Array.isArray(data.messages)) {
                chatMessages.value = data.messages;
                scrollToBottom();
            }
        }
    } catch {
        // Fallback
    }
};

// Select an existing conversation from list
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
    };

    try {
        const res = await fetch(
            `${route('messages.conversation')}?phone_number_id=${selectedLineId.value}&peer=${encodeURIComponent(conv.peer_number)}`,
            {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }
        );
        if (res.ok) {
            const data = await res.json();
            if (data.success && Array.isArray(data.messages)) {
                chatMessages.value = data.messages;
                scrollToBottom();
            }
        }
    } catch {
        // Fallback
    }
};

// Filter conversations in real time (matches number, title, or message bodies)
const filteredConversations = computed(() => {
    if (!searchQuery.value.trim()) return conversationList.value;
    const q = searchQuery.value.trim().toLowerCase();
    return conversationList.value.filter(c =>
        c.peer_number.toLowerCase().includes(q) ||
        c.peer_title.toLowerCase().includes(q) ||
        c.latest_message.body.toLowerCase().includes(q)
    );
});

// Other allocated lines of the user (for quick sending between own lines)
const otherOwnLines = computed(() => {
    return props.allocatedNumbers.filter(l => l.id !== selectedLineId.value);
});

// Send message from the new message composer
const sendFromComposer = async () => {
    if (recipientInput.value.trim()) {
        addRecipientChip(recipientInput.value);
    }

    if (recipientChips.value.length === 0) {
        alert('لطفاً حداقل یک شماره مقصد در کادر «گیرنده» وارد نمایید.');
        return;
    }

    if (!composerText.value.trim() || !selectedLineId.value) {
        alert('لطفاً متن پیامک را وارد نمایید.');
        return;
    }

    isSendingComposer.value = true;
    composeFeedback.value = null;

    try {
        const res = await fetch(route('messages.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                from_number_id: selectedLineId.value,
                destinations: recipientChips.value,
                body: composerText.value.trim(),
            }),
        });

        const data = await res.json();
        composeFeedback.value = {
            success: data.success,
            message: data.message,
            results: data.results,
        };

        if (data.success) {
            // If single recipient, immediately transition into normal chat view
            if (recipientChips.value.length === 1) {
                const targetPeer = recipientChips.value[0].replace(/^\+?85/, '').trim();
                composerText.value = '';
                router.get(
                    route('messages.index'),
                    { phone_number_id: selectedLineId.value, peer: targetPeer },
                    { preserveState: false }
                );
            } else {
                composerText.value = '';
                recipientChips.value = [];
                performSilentPoll();
            }
        }
    } catch {
        composeFeedback.value = {
            success: false,
            message: 'خطا در ارتباط با سرور.',
        };
    } finally {
        isSendingComposer.value = false;
    }
};

// Send reply in active conversation
const sendReply = async () => {
    if (!replyText.value.trim() || !selectedLineId.value || !currentActivePeer.value) return;

    isSendingReply.value = true;
    const textToSend = replyText.value.trim();
    replyText.value = '';

    try {
        const res = await fetch(route('messages.send'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                from_number_id: selectedLineId.value,
                destinations: [currentActivePeer.value],
                body: textToSend,
            }),
        });

        const data = await res.json();
        if (data.success) {
            chatMessages.value.push({
                id: data.results[0]?.message_id || Date.now(),
                phone_number_id: selectedLineId.value,
                destination_number: currentActivePeer.value,
                sender: props.currentNumber?.number || '',
                peer_number: currentActivePeer.value,
                direction: 'outbound',
                body: textToSend,
                server_received_at: new Date().toISOString(),
                server_received_at_formatted: 'هم‌اکنون',
                processing_status: 'processed',
            });
            scrollToBottom();
            performSilentPoll();
        } else {
            alert(data.message || 'خطا در ارسال پیامک.');
            replyText.value = textToSend;
        }
    } catch {
        alert('خطای ارتباط با سرور.');
        replyText.value = textToSend;
    } finally {
        isSendingReply.value = false;
    }
};

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
</script>

<template>
    <Head title="پیامک و گفتگوها" />

    <UserLayout>
        <div class="h-[calc(100vh-8.5rem)] flex flex-col space-y-4">
            <!-- Mobile Style Top Header: SIM / Line Switcher -->
            <div class="bg-white dark:bg-slate-900 px-5 py-3 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex items-center justify-between shadow-xs shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <span class="text-[11px] block text-slate-400 font-medium">سیم‌کارت / خط فعال:</span>
                        <div v-if="allocatedNumbers.length > 0" class="flex items-center gap-2">
                            <select
                                v-model="selectedLineId"
                                @change="onLineChange"
                                class="text-xs font-mono font-bold rounded-xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-blue-600 dark:text-blue-400 px-3 py-1.5 focus:border-blue-500 focus:ring-blue-500"
                                dir="ltr"
                            >
                                <option
                                    v-for="line in allocatedNumbers"
                                    :key="line.id"
                                    :value="line.id"
                                >
                                    {{ line.formatted_number }} ({{ line.type_label }})
                                </option>
                            </select>
                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                {{ currentNumber?.effective_title }}
                            </span>
                        </div>
                        <div v-else class="text-xs text-rose-500 font-medium">
                            شما هیچ خط تخصیص‌یافته فعالی ندارید.
                        </div>
                    </div>
                </div>

                <!-- Quick info -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-500 dark:text-slate-400 hidden sm:inline">
                        مجموع پیام‌ها: <strong class="text-slate-900 dark:text-white font-mono">{{ stats.total_messages.toLocaleString('fa-IR') }}</strong>
                    </span>
                </div>
            </div>

            <!-- Mobile Messenger Split Layout -->
            <div class="flex-1 bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 overflow-hidden flex flex-col md:flex-row shadow-sm min-h-0">
                <!-- Right Column: Conversations List (مانند پیام‌های گوشی) -->
                <div class="w-full md:w-80 lg:w-96 border-b md:border-b-0 md:border-l border-slate-200/80 dark:border-slate-800 flex flex-col h-full bg-slate-50/50 dark:bg-slate-900/40 shrink-0">
                    <!-- Conversations Header with New Message Button & Search -->
                    <div class="p-3.5 border-b border-slate-200/80 dark:border-slate-800 space-y-3 shrink-0">
                        <div class="flex items-center justify-between">
                            <h2 class="font-bold text-sm text-slate-900 dark:text-white">گفتگوها</h2>
                            <button
                                type="button"
                                @click="startNewMessage"
                                :disabled="!currentNumber"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-medium text-xs shadow-sm shadow-blue-500/20 disabled:opacity-50 transition-colors"
                                title="ایجاد پیام جدید"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                <span>پیام جدید</span>
                            </button>
                        </div>

                        <!-- Real-time Search Box -->
                        <div class="relative">
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="جستجو در گفتگوها و متن پیام‌ها..."
                                class="w-full text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white pr-9 pl-3 py-2 focus:border-blue-500 focus:ring-blue-500 placeholder:text-slate-400"
                            />
                            <div class="absolute right-3 top-2.5 text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Conversation items list -->
                    <div class="flex-1 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800/60">
                        <div v-if="filteredConversations.length === 0" class="p-8 text-center text-slate-400 text-xs">
                            <div class="w-12 h-12 mx-auto mb-2 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                            </div>
                            <span>گفتگویی یافت نشد.</span>
                        </div>

                        <div
                            v-for="conv in filteredConversations"
                            :key="conv.peer_number"
                            @click="selectConversation(conv)"
                            :class="[
                                'p-3.5 flex items-start gap-3 cursor-pointer transition-colors',
                                !isComposingNew && currentActivePeer === conv.peer_number
                                    ? 'bg-blue-50/90 dark:bg-blue-950/40 border-r-4 border-blue-600'
                                    : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/50'
                            ]"
                        >
                            <div class="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold text-xs shrink-0 font-mono">
                                {{ conv.peer_number.slice(-3) }}
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1 mb-1">
                                    <div class="flex items-center gap-1.5 truncate">
                                        <span class="font-bold text-xs text-slate-900 dark:text-white truncate">
                                            {{ conv.peer_title }}
                                        </span>
                                        <span
                                            v-if="conv.is_own_number"
                                            class="px-1.5 py-0.2 rounded-md text-[9px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0"
                                        >
                                            خط خودتان
                                        </span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 shrink-0 font-mono" dir="ltr">
                                        {{ conv.latest_message.server_received_at_formatted }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate max-w-[180px]">
                                        <span v-if="conv.latest_message.direction === 'outbound'" class="text-blue-500 font-bold ml-1">شما:</span>
                                        {{ conv.latest_message.body }}
                                    </p>

                                    <span
                                        v-if="conv.unread_count > 0"
                                        class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-600 text-white shrink-0"
                                    >
                                        {{ conv.unread_count }}
                                    </span>
                                </div>

                                <div class="mt-1 flex items-center gap-1.5 text-[10px] font-mono text-slate-400" dir="ltr">
                                    <span>{{ conv.peer_formatted }}</span>
                                    <span
                                        v-if="conv.peer_type_label"
                                        class="px-1 rounded text-[9px] bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-300"
                                    >
                                        {{ conv.peer_type_label }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Left Column: Active Chat Area OR New Message "To:" Composer -->
                <div class="flex-1 flex flex-col h-full bg-white dark:bg-slate-900 min-w-0">
                    <!-- MODE A: Composing New Message (True Phone "To:" Input Box) -->
                    <template v-if="isComposingNew">
                        <!-- Compose Header -->
                        <div class="p-3.5 border-b border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/60 flex items-center justify-between shrink-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-sm text-slate-900 dark:text-white">ارسال پیامک جدید</h3>
                                <span class="text-xs text-slate-400 font-mono" dir="ltr">
                                    (From: {{ props.currentNumber?.formatted_number }})
                                </span>
                            </div>

                            <button
                                v-if="conversations.length > 0"
                                type="button"
                                @click="isComposingNew = false; selectConversation(conversations[0])"
                                class="text-xs text-slate-500 hover:text-slate-800 dark:hover:text-white"
                            >
                                انصراف
                            </button>
                        </div>

                        <!-- Integrated Phone "To:" Recipient Box (باکس هوشمند گیرنده) -->
                        <div class="p-4 border-b border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 space-y-2.5 shrink-0">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="font-bold text-slate-700 dark:text-slate-300 shrink-0">به (گیرنده):</span>
                                <div class="flex-1 flex flex-wrap items-center gap-1.5 p-2 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-500">
                                    <!-- Recipient Tag Chips -->
                                    <span
                                        v-for="(chip, idx) in recipientChips"
                                        :key="idx"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-blue-100 dark:bg-blue-950/70 text-blue-800 dark:text-blue-300 font-mono font-bold text-xs"
                                        dir="ltr"
                                    >
                                        <span>{{ chip }}</span>
                                        <button
                                            type="button"
                                            @click="removeRecipientChip(idx)"
                                            class="text-blue-500 hover:text-blue-700 dark:hover:text-blue-200 font-sans"
                                        >
                                            ✕
                                        </button>
                                    </span>

                                    <!-- Typing Input -->
                                    <input
                                        v-model="recipientInput"
                                        @keydown="onRecipientKeyDown"
                                        type="text"
                                        placeholder="شماره مقصد را وارد و Enter بزنید (یک یا چند شماره)..."
                                        class="flex-1 min-w-[200px] border-0 bg-transparent p-1 text-xs text-slate-900 dark:text-white focus:ring-0 font-mono text-left"
                                        dir="ltr"
                                    />

                                    <button
                                        v-if="recipientInput.trim()"
                                        type="button"
                                        @click="addRecipientChip(recipientInput)"
                                        class="px-2 py-0.5 rounded-lg bg-blue-600 text-white text-[11px] font-medium font-sans"
                                    >
                                        افزودن +
                                    </button>
                                </div>
                            </div>

                            <!-- Quick Chips for User's Other Lines (ارسال به خطوط خود کاربر) -->
                            <div v-if="otherOwnLines.length > 0" class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                                <span class="text-slate-400 text-[11px]">ارسال به خطوط دیگر خودتان:</span>
                                <button
                                    v-for="own in otherOwnLines"
                                    :key="own.id"
                                    type="button"
                                    @click="addRecipientChip(own.number)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-dashed border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-[11px] font-mono transition-colors"
                                >
                                    <span>+ {{ own.formatted_number }}</span>
                                    <span class="text-[9px] font-sans">({{ own.effective_title }})</span>
                                </button>
                            </div>

                            <!-- Notice for group message -->
                            <div v-if="recipientChips.length > 1" class="text-[11px] text-indigo-600 dark:text-indigo-400 flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>ارسال گروهی به {{ recipientChips.length.toLocaleString('fa-IR') }} شماره مقصد انتخاب‌شده</span>
                            </div>

                            <!-- Feedback Alert -->
                            <div
                                v-if="composeFeedback"
                                :class="[
                                    'p-3 rounded-xl text-xs',
                                    composeFeedback.success
                                        ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300'
                                        : 'bg-rose-50 text-rose-800 border border-rose-200 dark:bg-rose-950/50 dark:text-rose-300'
                                ]"
                            >
                                <p class="font-bold">{{ composeFeedback.message }}</p>
                                <ul v-if="composeFeedback.results" class="mt-1 space-y-0.5 text-[11px]">
                                    <li v-for="(res, i) in composeFeedback.results" :key="i">
                                        <span class="font-mono" dir="ltr">{{ res.destination }}:</span>
                                        {{ res.status === 'success' ? 'ارسال شد' : res.reason }}
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Chat preview area if 1 recipient has history, or blank placeholder -->
                        <div
                            ref="chatContainer"
                            class="flex-1 overflow-y-auto p-4 md:p-6 space-y-3.5 bg-slate-50/40 dark:bg-slate-950/40"
                        >
                            <template v-if="recipientChips.length === 1 && chatMessages.length > 0">
                                <div class="text-center text-[11px] text-slate-400 mb-2">
                                    سوابق پیام‌های قبلی با این شماره:
                                </div>
                                <div
                                    v-for="msg in chatMessages"
                                    :key="msg.id"
                                    :class="msg.direction === 'outbound' ? 'flex justify-start' : 'flex justify-end'"
                                >
                                    <div
                                        :class="[
                                            'max-w-md sm:max-w-lg rounded-3xl p-4 shadow-xs space-y-1',
                                            msg.direction === 'outbound'
                                                ? 'bg-blue-600 text-white rounded-tr-xs'
                                                : 'bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700 rounded-tl-xs'
                                        ]"
                                    >
                                        <p class="text-xs leading-relaxed whitespace-pre-wrap select-all font-sans">
                                            {{ msg.body }}
                                        </p>
                                        <div
                                            :class="[
                                                'flex items-center gap-1.5 text-[10px] font-mono',
                                                msg.direction === 'outbound' ? 'justify-end text-blue-200' : 'justify-start text-slate-400'
                                            ]"
                                            dir="ltr"
                                        >
                                            <span>{{ formatTime(msg.server_received_at) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div v-else class="h-full flex flex-col items-center justify-center text-center text-slate-400 text-xs">
                                <span>شماره مقصد را در کادر بالا وارد و متن پیامک را در پایین بنویسید.</span>
                            </div>
                        </div>

                        <!-- Compose Bottom Message Box -->
                        <div class="p-3 border-t border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 shrink-0">
                            <form @submit.prevent="sendFromComposer" class="flex items-end gap-2">
                                <div class="flex-1 relative">
                                    <textarea
                                        v-model="composerText"
                                        @keydown.enter.exact.prevent="sendFromComposer"
                                        rows="2"
                                        placeholder="متن پیامک خود را بنویسید (Enter برای ارسال)..."
                                        class="w-full text-xs rounded-2xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white p-3 focus:border-blue-500 focus:ring-blue-500 resize-none leading-relaxed placeholder:text-slate-400"
                                    ></textarea>
                                    <div class="absolute left-3 bottom-2 text-[10px] text-slate-400 font-mono" dir="ltr">
                                        {{ composerText.length }} / 2000
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="!composerText.trim() || isSendingComposer"
                                    class="p-3.5 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-500/20 disabled:opacity-50 transition-colors shrink-0"
                                    title="ارسال پیامک"
                                >
                                    <span v-if="isSendingComposer" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full block"></span>
                                    <svg v-else class="w-5 h-5 -rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </template>

                    <!-- MODE B: Viewing an Active Conversation Thread -->
                    <template v-else-if="currentActivePeer">
                        <!-- Chat Header -->
                        <div class="p-3.5 border-b border-slate-200/80 dark:border-slate-800 flex items-center justify-between shrink-0 bg-slate-50/30 dark:bg-slate-900">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-sm">
                                    #
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h2 class="font-bold text-sm text-slate-900 dark:text-white">
                                            {{ currentPeerInfo?.title }}
                                        </h2>
                                        <span
                                            v-if="currentPeerInfo?.is_own_number"
                                            class="text-[10px] px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 font-medium"
                                        >
                                            خط دیگر خودتان
                                        </span>
                                        <span
                                            v-else-if="currentPeerInfo?.type_label"
                                            class="text-[10px] px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 font-medium"
                                        >
                                            {{ currentPeerInfo.type_label }}
                                        </span>
                                    </div>
                                    <div class="text-xs font-mono text-slate-400 text-left" dir="ltr">
                                        {{ currentPeerInfo?.formatted_number }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Messages Stream -->
                        <div
                            ref="chatContainer"
                            class="flex-1 overflow-y-auto p-4 md:p-6 space-y-3.5 bg-slate-50/40 dark:bg-slate-950/40"
                        >
                            <div v-if="chatMessages.length === 0" class="h-full flex items-center justify-center text-xs text-slate-400">
                                پیامی در این گفتگو ثبت نشده است. اولین پیامک را ارسال نمایید!
                            </div>

                            <template v-for="msg in chatMessages" :key="msg.id">
                                <!-- Outbound (User's Sent SMS) -->
                                <div
                                    v-if="msg.direction === 'outbound'"
                                    class="flex justify-start"
                                >
                                    <div class="max-w-md sm:max-w-lg bg-blue-600 text-white rounded-3xl rounded-tr-xs p-4 shadow-sm space-y-1.5">
                                        <p class="text-xs leading-relaxed whitespace-pre-wrap select-all font-sans">
                                            {{ msg.body }}
                                        </p>
                                        <div class="flex items-center justify-end gap-1.5 text-[10px] text-blue-200 font-mono" dir="ltr">
                                            <span>{{ formatTime(msg.server_received_at) }}</span>
                                            <svg class="w-3.5 h-3.5 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Inbound (Received SMS from Contact) -->
                                <div
                                    v-else
                                    class="flex justify-end"
                                >
                                    <div class="max-w-md sm:max-w-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700/80 rounded-3xl rounded-tl-xs p-4 shadow-xs space-y-1.5">
                                        <p class="text-xs leading-relaxed whitespace-pre-wrap select-all font-sans text-right">
                                            {{ msg.body }}
                                        </p>
                                        <div class="flex items-center justify-start gap-1.5 text-[10px] text-slate-400 font-mono" dir="ltr">
                                            <span>{{ formatTime(msg.server_received_at) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Chat Bottom Compose Input -->
                        <div class="p-3 border-t border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 shrink-0">
                            <form @submit.prevent="sendReply" class="flex items-end gap-2">
                                <div class="flex-1 relative">
                                    <textarea
                                        v-model="replyText"
                                        @keydown.enter.exact.prevent="sendReply"
                                        rows="2"
                                        placeholder="متن پیامک خود را بنویسید (Enter برای ارسال)..."
                                        class="w-full text-xs rounded-2xl border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white p-3 focus:border-blue-500 focus:ring-blue-500 resize-none leading-relaxed placeholder:text-slate-400"
                                    ></textarea>
                                    <div class="absolute left-3 bottom-2 text-[10px] text-slate-400 font-mono" dir="ltr">
                                        {{ replyText.length }} / 2000
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="!replyText.trim() || isSendingReply"
                                    class="p-3.5 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-500/20 disabled:opacity-50 transition-colors shrink-0"
                                    title="ارسال پیامک"
                                >
                                    <span v-if="isSendingReply" class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full block"></span>
                                    <svg v-else class="w-5 h-5 -rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </template>

                    <!-- MODE C: Blank state when no conversations exist yet -->
                    <div v-else class="h-full flex flex-col items-center justify-center p-8 text-center text-slate-400">
                        <div class="w-20 h-20 mb-4 rounded-3xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-700 dark:text-slate-300">یک گفتگو را انتخاب نمایید</h3>
                        <p class="text-xs text-slate-500 max-w-sm mt-1">
                            برای شروع چت، از ستون راست یک گفتگو را انتخاب کنید یا بر روی «پیام جدید» کلیک فرمایید.
                        </p>
                        <button
                            type="button"
                            @click="startNewMessage"
                            class="mt-4 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium shadow-md shadow-blue-500/20"
                        >
                            پیام جدید
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </UserLayout>
</template>
