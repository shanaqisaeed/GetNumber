import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { getEchoInstance, subscribeConnectionState, ConnectionState } from '@/echo';

export interface RealtimeMessagePayload {
    id: number;
    phone_number_id: number;
    destination_number: string;
    sender: string;
    peer_number: string;
    direction: 'inbound' | 'outbound';
    body: string;
    processing_status: string;
    server_received_at: string;
    server_received_at_formatted: string;
    client_message_id?: string | null;
    user_id?: number | null;
    unread_count?: number;
}

export interface RealtimeStatusPayload {
    id: number;
    phone_number_id: number;
    processing_status: string;
    client_message_id?: string | null;
    user_id?: number | null;
}

export interface UseRealtimeMessagesOptions {
    userId?: number | null;
    lineId?: number | null;
    isAdmin?: boolean;
    onMessage?: (message: RealtimeMessagePayload) => void;
    onStatusUpdate?: (status: RealtimeStatusPayload) => void;
    onUnreadUpdate?: (unreadTotal: number, byLine?: Record<number, number>) => void;
    enablePollingFallback?: boolean;
    pollIntervalMs?: number;
    pollUrl?: string;
    pollParams?: () => Record<string, any>;
    onPollResponse?: (data: any) => void;
}

export function useRealtimeMessages(options: UseRealtimeMessagesOptions = {}) {
    const {
        userId = null,
        lineId = null,
        isAdmin = false,
        onMessage,
        onStatusUpdate,
        onUnreadUpdate,
        enablePollingFallback = true,
        pollIntervalMs = 4000,
        pollUrl,
        pollParams,
        onPollResponse,
    } = options;

    const connectionState = ref<ConnectionState>('connecting');
    const isWebSocketActive = computed(() => connectionState.value === 'connected');
    const seenMessageIds = new Set<number>();

    // Readable label and indicator
    const connectionLabel = computed(() => {
        switch (connectionState.value) {
            case 'connected':
                return 'اتصال بلادرنگ';
            case 'connecting':
                return 'در حال برقراری ارتباط...';
            case 'polling':
            case 'unavailable':
            case 'failed':
                return 'به‌روزرسانی خودکار (Polling)';
            case 'disconnected':
            default:
                return 'قطع ارتباط';
        }
    });

    const connectionStatusDotClass = computed(() => {
        switch (connectionState.value) {
            case 'connected':
                return 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]';
            case 'connecting':
                return 'bg-amber-500 animate-pulse';
            case 'polling':
            case 'unavailable':
            case 'failed':
                return 'bg-sky-500';
            case 'disconnected':
            default:
                return 'bg-slate-400';
        }
    });

    // Safely record message ID with LRU-like limit to avoid memory leaks
    const registerSeenId = (id: number): boolean => {
        if (seenMessageIds.has(id)) {
            return false;
        }
        seenMessageIds.add(id);
        if (seenMessageIds.size > 1000) {
            const firstKey = seenMessageIds.values().next().value;
            if (firstKey !== undefined) {
                seenMessageIds.delete(firstKey);
            }
        }
        return true;
    };

    // Internal message dispatch handler
    const handleIncomingMessage = (payload: RealtimeMessagePayload) => {
        if (!payload || !payload.id) return;
        const isFresh = registerSeenId(payload.id);
        if (isFresh && onMessage) {
            onMessage(payload);
        }
        if (typeof payload.unread_count === 'number' && onUnreadUpdate) {
            onUnreadUpdate(payload.unread_count);
        }
    };

    const handleStatusUpdate = (payload: RealtimeStatusPayload) => {
        if (!payload || !payload.id) return;
        if (onStatusUpdate) {
            onStatusUpdate(payload);
        }
    };

    // Polling machinery
    let pollTimer: ReturnType<typeof setInterval> | null = null;
    let isPollingBusy = false;

    const performPoll = async () => {
        if (!enablePollingFallback || isPollingBusy) return;
        if (typeof document !== 'undefined' && document.hidden) return;

        // If WebSocket is alive and well, do not hammer server with frequent polls
        if (isWebSocketActive.value) {
            return;
        }

        isPollingBusy = true;
        try {
            const targetUrl = pollUrl || (window as any).route?.('messages.unread_summary') || '/messages/unread-summary';
            const paramsObj = pollParams ? pollParams() : {};
            const searchParams = new URLSearchParams();
            for (const [k, v] of Object.entries(paramsObj)) {
                if (v !== undefined && v !== null && v !== '') {
                    searchParams.append(k, String(v));
                }
            }
            const fullUrl = searchParams.toString() ? `${targetUrl}?${searchParams.toString()}` : targetUrl;

            const res = await fetch(fullUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    if (onPollResponse) {
                        onPollResponse(data);
                    }

                    if (typeof data.unread_total === 'number' && onUnreadUpdate) {
                        onUnreadUpdate(data.unread_total, data.unread_by_line);
                    }

                    if (Array.isArray(data.new_messages)) {
                        for (const msg of data.new_messages) {
                            handleIncomingMessage(msg);
                        }
                    } else if (data.latest_message && registerSeenId(data.latest_message.id)) {
                        if (onMessage) {
                            onMessage(data.latest_message);
                        }
                    }
                }
            }
        } catch {
            // Silently tolerate temporary intranet network hiccups
        } finally {
            isPollingBusy = false;
        }
    };

    const startPolling = (intervalMs = pollIntervalMs) => {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(performPoll, intervalMs);
    };

    const stopPolling = () => {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    };

    // Page visibility handler (pause when tab is inactive, poll immediately when tab activates)
    const handleVisibilityChange = () => {
        if (document.hidden) {
            stopPolling();
        } else {
            performPoll();
            startPolling();
        }
    };

    // Subscriptions cleanup trackers
    let unsubscribeState: (() => void) | null = null;
    let activeEchoChannelNames: string[] = [];

    const setupEcho = () => {
        const echo = getEchoInstance();
        if (!echo) {
            connectionState.value = 'polling';
            startPolling();
            return;
        }

        unsubscribeState = subscribeConnectionState(state => {
            connectionState.value = state;
            if (state === 'connected') {
                // If connected via WebSocket, stop fast polling and perform a single sync
                stopPolling();
                performPoll();
            } else {
                // If disconnected/polling/error, ensure polling fallback is active
                startPolling();
            }
        });

        // 1. Subscribe to User Private Channel
        if (userId) {
            const userChannel = `users.${userId}`;
            activeEchoChannelNames.push(userChannel);
            echo.private(userChannel)
                .listen('.message.received', handleIncomingMessage)
                .listen('.message.status.updated', handleStatusUpdate);
        }

        // 2. Subscribe to Line Private Channel if given
        if (lineId) {
            const lineChannel = `lines.${lineId}`;
            activeEchoChannelNames.push(lineChannel);
            echo.private(lineChannel)
                .listen('.message.received', handleIncomingMessage)
                .listen('.message.status.updated', handleStatusUpdate);
        }

        // 3. Subscribe to Admin Private Channel if admin
        if (isAdmin) {
            const adminChannel = 'admin.messages';
            activeEchoChannelNames.push(adminChannel);
            echo.private(adminChannel)
                .listen('.message.received', handleIncomingMessage)
                .listen('.message.status.updated', handleStatusUpdate);
        }

        // If not connected within 4.5 seconds, ensure polling is running smoothly
        setTimeout(() => {
            if (connectionState.value !== 'connected') {
                connectionState.value = 'polling';
                startPolling();
            }
        }, 4500);
    };

    onMounted(() => {
        setupEcho();
        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', handleVisibilityChange);
        }
        // Initial sync
        performPoll();
    });

    onUnmounted(() => {
        stopPolling();
        if (unsubscribeState) {
            unsubscribeState();
        }
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        }

        const echo = getEchoInstance();
        if (echo) {
            for (const ch of activeEchoChannelNames) {
                echo.leave(ch);
            }
        }
    });

    return {
        connectionState,
        connectionLabel,
        connectionStatusDotClass,
        isWebSocketActive,
        performPoll,
        registerSeenId,
    };
}
