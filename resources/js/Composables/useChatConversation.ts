import { ref } from 'vue';
import { __ } from './useTrans';

export interface ChatMessage {
    id: number;
    client_message_id?: string;
    phone_number_id: number;
    destination_number: string;
    sender: string;
    peer_number: string;
    direction: 'inbound' | 'outbound';
    body: string;
    server_received_at: string;
    server_received_at_formatted: string;
    processing_status: 'pending' | 'processing' | 'sent' | 'received' | 'processed' | 'failed';
    is_optimistic?: boolean;
    error_message?: string;
}

export function useChatConversation() {
    const messages = ref<ChatMessage[]>([]);
    const isLoading = ref<boolean>(false);
    const isLoadingOlder = ref<boolean>(false);
    const hasMoreOlder = ref<boolean>(true);

    const getLowestMessageId = (): number => {
        const realMsgs = messages.value.filter(m => m.id > 0);
        if (realMsgs.length === 0) return 0;
        return Math.min(...realMsgs.map(m => m.id));
    };

    const getHighestMessageId = (): number => {
        const realMsgs = messages.value.filter(m => m.id > 0);
        if (realMsgs.length === 0) return 0;
        return Math.max(...realMsgs.map(m => m.id));
    };

    /**
     * Load initial conversation messages.
     */
    const loadConversation = async (lineId: number, peerNumber: string, initialMessages: ChatMessage[] = []) => {
        if (initialMessages && initialMessages.length > 0) {
            messages.value = [...initialMessages];
            hasMoreOlder.value = initialMessages.length >= 20;
            return;
        }

        isLoading.value = true;
        messages.value = [];
        hasMoreOlder.value = true;

        try {
            const res = await fetch(
                `${route('messages.conversation')}?phone_number_id=${lineId}&peer=${encodeURIComponent(peerNumber)}`,
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
                    messages.value = data.messages;
                    hasMoreOlder.value = data.messages.length >= 20;

                    if (typeof data.unread_total === 'number' && typeof window !== 'undefined') {
                        window.dispatchEvent(new CustomEvent('unread-count-updated', {
                            detail: {
                                total: data.unread_total,
                                byLine: data.unread_by_line,
                            }
                        }));
                    }
                }
            }
        } catch (err) {
            console.error('Error loading conversation messages:', err);
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Prepend older messages when scrolling to top.
     */
    const loadOlderMessages = async (
        lineId: number,
        peerNumber: string,
        onPrepend?: (prevScrollHeight: number) => void
    ) => {
        if (isLoadingOlder.value || !hasMoreOlder.value) return;
        const lowestId = getLowestMessageId();
        if (!lowestId) {
            hasMoreOlder.value = false;
            return;
        }

        isLoadingOlder.value = true;

        try {
            const res = await fetch(
                `${route('messages.conversation')}?phone_number_id=${lineId}&peer=${encodeURIComponent(peerNumber)}&before_id=${lowestId}`,
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
                    if (data.messages.length === 0) {
                        hasMoreOlder.value = false;
                    } else {
                        const existingIds = new Set(messages.value.map(m => m.id));
                        const older = data.messages.filter((m: ChatMessage) => !existingIds.has(m.id));
                        if (older.length > 0) {
                            messages.value = [...older, ...messages.value];
                            if (onPrepend) {
                                onPrepend(older.length);
                            }
                        } else {
                            hasMoreOlder.value = false;
                        }
                    }
                }
            }
        } catch (err) {
            console.error('Error loading older messages:', err);
        } finally {
            isLoadingOlder.value = false;
        }
    };

    /**
     * Optimistically dispatch a message.
     */
    const sendOptimisticMessage = async (
        lineId: number,
        peerNumber: string,
        body: string,
        senderNumber: string,
        onSuccess?: (msg: ChatMessage) => void,
        onError?: (errorText: string) => void
    ): Promise<boolean> => {
        const tempId = `opt_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
        const optimisticMsg: ChatMessage = {
            id: -Date.now(),
            client_message_id: tempId,
            phone_number_id: lineId,
            destination_number: peerNumber,
            sender: senderNumber,
            peer_number: peerNumber,
            direction: 'outbound',
            body,
            server_received_at: new Date().toISOString(),
            server_received_at_formatted: __('ui.composables.chat.just_now'),
            processing_status: 'pending',
            is_optimistic: true,
        };

        // Immediately append to local messages list
        messages.value.push(optimisticMsg);

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
                    from_number_id: lineId,
                    destinations: [peerNumber],
                    body,
                    client_message_id: tempId,
                }),
            });

            const data = await res.json();
            const target = messages.value.find(m => m.client_message_id === tempId);

            if (res.ok && data.success) {
                if (target) {
                    const serverMsg = data.created_message;
                    target.id = serverMsg?.id || data.results?.[0]?.message_id || Date.now();
                    target.processing_status = serverMsg?.processing_status || 'sent';
                    target.is_optimistic = false;
                    if (serverMsg?.server_received_at) {
                        target.server_received_at = serverMsg.server_received_at;
                        target.server_received_at_formatted = serverMsg.server_received_at_formatted;
                    }
                    if (onSuccess) onSuccess(target);
                }
                return true;
            } else {
                const errMsg = data.message || data.results?.[0]?.reason || __('ui.composables.chat.send_error');
                if (target) {
                    target.processing_status = 'failed';
                    target.error_message = errMsg;
                }
                if (onError) onError(errMsg);
                return false;
            }
        } catch (err: any) {
            const target = messages.value.find(m => m.client_message_id === tempId);
            if (target) {
                target.processing_status = 'failed';
                target.error_message = __('ui.composables.chat.connection_error');
            }
            if (onError) onError(__('ui.composables.chat.network_error'));
            return false;
        }
    };

    /**
     * Retry sending a previously failed message.
     */
    const retryMessage = async (
        failedMsg: ChatMessage,
        lineId: number,
        onSuccess?: (msg: ChatMessage) => void,
        onError?: (errorText: string) => void
    ) => {
        if (!failedMsg || failedMsg.processing_status !== 'failed') return;

        failedMsg.processing_status = 'pending';
        failedMsg.error_message = undefined;

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
                    from_number_id: lineId,
                    destinations: [failedMsg.peer_number],
                    body: failedMsg.body,
                    client_message_id: failedMsg.client_message_id,
                }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                const serverMsg = data.created_message;
                failedMsg.id = serverMsg?.id || data.results?.[0]?.message_id || Date.now();
                failedMsg.processing_status = serverMsg?.processing_status || 'sent';
                failedMsg.is_optimistic = false;
                if (onSuccess) onSuccess(failedMsg);
            } else {
                const errMsg = data.message || __('ui.composables.chat.resend_error');
                failedMsg.processing_status = 'failed';
                failedMsg.error_message = errMsg;
                if (onError) onError(errMsg);
            }
        } catch {
            failedMsg.processing_status = 'failed';
            failedMsg.error_message = __('ui.composables.chat.connection_fail');
            if (onError) onError(__('ui.composables.chat.network_error'));
        }
    };

    /**
     * Merge incoming real-time broadcast message into the active conversation.
     */
    const handleIncomingMessage = (newMsg: any) => {
        // 1. Check if already exists by DB id
        const existingIdx = messages.value.findIndex(m => m.id === newMsg.id);
        if (existingIdx !== -1) return false;

        // 2. Check if this is an echo of our own optimistic message
        const optimisticIdx = messages.value.findIndex(
            m => m.is_optimistic &&
                 m.direction === 'outbound' &&
                 m.body === newMsg.body &&
                 (m.peer_number === newMsg.peer_number || m.destination_number === newMsg.destination_number)
        );

        if (optimisticIdx !== -1) {
            messages.value[optimisticIdx] = {
                id: newMsg.id,
                phone_number_id: newMsg.phone_number_id,
                destination_number: newMsg.destination_number,
                sender: newMsg.sender,
                peer_number: newMsg.peer_number,
                direction: newMsg.direction,
                body: newMsg.body,
                server_received_at: newMsg.server_received_at,
                server_received_at_formatted: newMsg.server_received_at_formatted,
                processing_status: newMsg.processing_status || 'sent',
                is_optimistic: false,
            };
            return true;
        }

        // 3. New message from peer or server
        messages.value.push({
            id: newMsg.id,
            phone_number_id: newMsg.phone_number_id,
            destination_number: newMsg.destination_number,
            sender: newMsg.sender,
            peer_number: newMsg.peer_number,
            direction: newMsg.direction,
            body: newMsg.body,
            server_received_at: newMsg.server_received_at,
            server_received_at_formatted: newMsg.server_received_at_formatted,
            processing_status: newMsg.processing_status,
        });
        return true;
    };

    /**
     * Update message status from WebSocket or Polling status update.
     */
    const handleStatusUpdate = (update: { id: number; processing_status: any }) => {
        const msg = messages.value.find(m => m.id === update.id);
        if (msg) {
            msg.processing_status = update.processing_status;
        }
    };

    return {
        messages,
        isLoading,
        isLoadingOlder,
        hasMoreOlder,
        loadConversation,
        loadOlderMessages,
        sendOptimisticMessage,
        retryMessage,
        handleIncomingMessage,
        handleStatusUpdate,
        getLowestMessageId,
        getHighestMessageId,
    };
}

