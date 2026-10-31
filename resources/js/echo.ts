import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'> | null;
    }
}

window.Pusher = Pusher;

export type ConnectionState = 'connecting' | 'connected' | 'unavailable' | 'failed' | 'disconnected' | 'polling';

let echoInstance: Echo<'reverb'> | null = null;
let currentConnectionState: ConnectionState = 'disconnected';
const stateListeners = new Set<(state: ConnectionState) => void>();

function notifyStateChange(state: ConnectionState) {
    currentConnectionState = state;
    stateListeners.forEach(listener => {
        try {
            listener(state);
        } catch {
            // ignore
        }
    });
}

export function subscribeConnectionState(callback: (state: ConnectionState) => void): () => void {
    stateListeners.add(callback);
    callback(currentConnectionState);
    return () => {
        stateListeners.delete(callback);
    };
}

export function getCurrentConnectionState(): ConnectionState {
    return currentConnectionState;
}

export function getEchoInstance(): Echo<'reverb'> | null {
    if (echoInstance) {
        return echoInstance;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        notifyStateChange('polling');
        return null;
    }

    try {
        const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
        const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
        const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
        const useTLS = scheme === 'https';

        const getCsrfToken = () => {
            return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
        };

        echoInstance = new Echo({
            broadcaster: 'reverb',
            key: key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: useTLS,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            },
        });

        const pusher = (echoInstance as any).connector?.pusher;
        if (pusher && pusher.connection) {
            pusher.connection.bind('state_change', (states: { current: string; previous: string }) => {
                const current = states.current;
                if (current === 'connected') {
                    notifyStateChange('connected');
                } else if (current === 'connecting') {
                    notifyStateChange('connecting');
                } else if (current === 'unavailable' || current === 'failed') {
                    notifyStateChange('polling');
                } else if (current === 'disconnected') {
                    notifyStateChange('disconnected');
                }
            });

            pusher.connection.bind('error', () => {
                notifyStateChange('polling');
            });
        }

        window.Echo = echoInstance;
        return echoInstance;
    } catch {
        notifyStateChange('polling');
        return null;
    }
}
