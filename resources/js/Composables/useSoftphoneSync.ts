import { ref, onMounted, onUnmounted } from 'vue';

export interface SoftphoneSyncMessage {
    type: 'CALL_INCOMING' | 'CALL_ANSWERED' | 'CALL_ENDED' | 'LINE_SWITCHED' | 'PRESENCE_CHANGED';
    callId?: number | string;
    lineKey?: string;
    presence?: string;
    tabId: string;
    timestamp: number;
}

const currentTabId = 'tab_' + Math.random().toString(36).substring(2, 9);
const isCallActiveInOtherTab = ref(false);
const activeCallTabId = ref<string | null>(null);

let channel: BroadcastChannel | null = null;

export function useSoftphoneSync() {
    function initChannel(onLineSwitch?: (key: string) => void) {
        if (typeof window === 'undefined' || typeof BroadcastChannel === 'undefined') {
            return;
        }

        if (!channel) {
            channel = new BroadcastChannel('getnumber_softphone_channel');

            channel.onmessage = (event: MessageEvent<SoftphoneSyncMessage>) => {
                const data = event.data;
                if (!data || data.tabId === currentTabId) return;

                switch (data.type) {
                    case 'CALL_ANSWERED':
                        isCallActiveInOtherTab.value = true;
                        activeCallTabId.value = data.tabId;
                        break;

                    case 'CALL_ENDED':
                        isCallActiveInOtherTab.value = false;
                        activeCallTabId.value = null;
                        break;

                    case 'LINE_SWITCHED':
                        if (data.lineKey && onLineSwitch) {
                            onLineSwitch(data.lineKey);
                        }
                        break;
                }
            };
        }
    }

    function broadcastCallAnswered(callId: number | string) {
        if (!channel) return;
        channel.postMessage({
            type: 'CALL_ANSWERED',
            callId,
            tabId: currentTabId,
            timestamp: Date.now(),
        });
    }

    function broadcastCallEnded() {
        if (!channel) return;
        isCallActiveInOtherTab.value = false;
        activeCallTabId.value = null;
        channel.postMessage({
            type: 'CALL_ENDED',
            tabId: currentTabId,
            timestamp: Date.now(),
        });
    }

    function broadcastLineSwitched(lineKey: string) {
        if (!channel) return;
        channel.postMessage({
            type: 'LINE_SWITCHED',
            lineKey,
            tabId: currentTabId,
            timestamp: Date.now(),
        });
    }

    return {
        currentTabId,
        isCallActiveInOtherTab,
        initChannel,
        broadcastCallAnswered,
        broadcastCallEnded,
        broadcastLineSwitched,
    };
}
