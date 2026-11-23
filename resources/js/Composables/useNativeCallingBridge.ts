import { ref } from 'vue';
import axios from 'axios';

export type NetworkMode = 'PUBLIC' | 'INTRANET' | 'HYBRID' | 'DEGRADED' | 'OFFLINE';

export interface NativeCallStateSnapshot {
    hasActiveOrPendingCall: boolean;
    callUuid: string | null;
    callId: number | null;
    status: string | null;
    direction: 'inbound' | 'outbound' | null;
    callerHandle: string | null;
    callerName: string | null;
    destinationLine?: string | null;
    hasVideo: boolean;
    answeredFromLockScreen: boolean;
    isMuted: boolean;
    isOnHold: boolean;
    audioRoute: 'earpiece' | 'speaker' | 'bluetooth' | 'wired' | 'unknown';
    bridgeSessionId?: string | null;
    bridgeWsEndpoint?: string | null;
    rtcConfig?: any | null;
    answeredAtMs?: number | null;
}

export interface NativeBridgeEventCallbacks {
    onIncomingCall?: (data: any) => void;
    onCallAnswered?: (data: NativeCallStateSnapshot) => void;
    onCallRejected?: (data: { callUuid?: string; callId?: number; reason?: string }) => void;
    onCallEnded?: (data: { callUuid?: string; callId?: number; reason?: string; durationSeconds?: number }) => void;
    onCallStateChanged?: (data: { callUuid?: string; isMuted?: boolean; isOnHold?: boolean; currentState?: string }) => void;
    onDeviceTokenChanged?: (data: { tokenType: string; token: string }) => void;
    onLocalPushConnectionChanged?: (data: { connected: boolean; endpoint?: string }) => void;
    onAudioRouteChanged?: (data: { route: string; availableRoutes?: string[] }) => void;
    onNetworkModeChanged?: (data: { previousMode: NetworkMode; currentMode: NetworkMode; hasWifi?: boolean }) => void;
}

const currentNetworkMode = ref<NetworkMode>('HYBRID');
const isLocalPushConnected = ref<boolean>(false);
const registeredDeviceUuid = ref<string | null>(
    typeof window !== 'undefined' ? localStorage.getItem('getnumber_device_uuid') : null
);
const isNativePlatform = ref<boolean>(false);
let isInitialized = false;
let probeTimer: ReturnType<typeof setInterval> | null = null;

function getCapacitorNativePlugin(): any | null {
    if (typeof window === 'undefined') return null;
    const cap = (window as any).Capacitor;
    if (!cap) return null;
    if (typeof cap.isNativePlatform === 'function' && cap.isNativePlatform()) {
        isNativePlatform.value = true;
    }
    return cap.Plugins?.NativeCalling || null;
}

function getOrCreateBrowserDeviceUuid(): string {
    if (registeredDeviceUuid.value) {
        return registeredDeviceUuid.value;
    }
    const rand = 'web-' + Math.random().toString(36).substring(2, 10) + '-' + Date.now().toString(36);
    if (typeof window !== 'undefined') {
        localStorage.setItem('getnumber_device_uuid', rand);
    }
    registeredDeviceUuid.value = rand;
    return rand;
}

export function useNativeCallingBridge() {
    /**
     * Probe End-to-End Network Mode from Backend (/api/v1/network/probe)
     */
    const probeNetworkMode = async (): Promise<NetworkMode> => {
        try {
            const plugin = getCapacitorNativePlugin();
            if (plugin && typeof plugin.getNetworkMode === 'function') {
                const nativeNet = await plugin.getNetworkMode();
                if (nativeNet?.mode) {
                    currentNetworkMode.value = nativeNet.mode as NetworkMode;
                    isLocalPushConnected.value = Boolean(nativeNet.localPushConnected);
                }
            }

            const res = await axios.get('/api/v1/network/probe', { timeout: 3000 });
            if (res.data?.mode) {
                currentNetworkMode.value = res.data.mode as NetworkMode;
                return currentNetworkMode.value;
            }
        } catch {
            if (typeof navigator !== 'undefined' && !navigator.onLine) {
                currentNetworkMode.value = 'OFFLINE';
            }
        }
        return currentNetworkMode.value;
    };

    /**
     * Register device with Backend Device Registry and initialize Native Calling Plugin (PushKit / CallKit / Telecom / Local Push)
     */
    const initNativeBridge = async (callbacks: NativeBridgeEventCallbacks = {}) => {
        const plugin = getCapacitorNativePlugin();

        // 1. Attach Native Capacitor Listeners if running inside iOS/Android App
        if (plugin && typeof plugin.addListener === 'function') {
            if (callbacks.onIncomingCall) {
                plugin.addListener('onIncomingCall', callbacks.onIncomingCall);
            }
            if (callbacks.onCallAnswered) {
                plugin.addListener('onCallAnswered', callbacks.onCallAnswered);
            }
            if (callbacks.onCallRejected) {
                plugin.addListener('onCallRejected', callbacks.onCallRejected);
            }
            if (callbacks.onCallEnded) {
                plugin.addListener('onCallEnded', callbacks.onCallEnded);
            }
            if (callbacks.onCallStateChanged) {
                plugin.addListener('onCallStateChanged', callbacks.onCallStateChanged);
            }
            if (callbacks.onAudioRouteChanged) {
                plugin.addListener('onAudioRouteChanged', callbacks.onAudioRouteChanged);
            }
            plugin.addListener('onLocalPushConnectionChanged', (ev: any) => {
                isLocalPushConnected.value = Boolean(ev?.connected);
                callbacks.onLocalPushConnectionChanged?.(ev);
            });
            plugin.addListener('onNetworkModeChanged', (ev: any) => {
                if (ev?.currentMode) {
                    currentNetworkMode.value = ev.currentMode as NetworkMode;
                }
                callbacks.onNetworkModeChanged?.(ev);
            });
            plugin.addListener('onDeviceTokenChanged', async (ev: any) => {
                callbacks.onDeviceTokenChanged?.(ev);
                if (ev?.token && ev?.tokenType) {
                    try {
                        await axios.post('/api/v1/mobile/devices/register', {
                            device_uuid: registeredDeviceUuid.value || getOrCreateBrowserDeviceUuid(),
                            platform: ev.tokenType === 'apns_voip' ? 'ios' : 'android',
                            voip_push_token: ev.tokenType === 'apns_voip' ? ev.token : undefined,
                            notification_push_token: ev.tokenType !== 'apns_voip' ? ev.token : undefined,
                            issue_device_token: false,
                        });
                    } catch {}
                }
            });
        }

        // 2. Also listen for window CustomEvent('native_call_action') from Android MainActivity intent
        if (typeof window !== 'undefined') {
            window.addEventListener('native_call_action', ((event: CustomEvent) => {
                const detail = event.detail || {};
                if (detail.autoAnswer && callbacks.onCallAnswered) {
                    callbacks.onCallAnswered({
                        hasActiveOrPendingCall: true,
                        callUuid: detail.callUuid || null,
                        callId: detail.callId ? parseInt(String(detail.callId), 10) : null,
                        status: 'accepted',
                        direction: 'inbound',
                        callerHandle: detail.caller || null,
                        callerName: detail.caller || null,
                        hasVideo: Boolean(detail.hasVideo),
                        answeredFromLockScreen: true,
                        isMuted: false,
                        isOnHold: false,
                        audioRoute: 'earpiece',
                    });
                }
            }) as EventListener);
        }

        if (isInitialized) {
            return;
        }
        isInitialized = true;

        // 3. Synchronize Device Registration with Backend and Native Layer
        try {
            let platform: 'ios' | 'android' | 'web' = 'web';
            let deviceUuid = getOrCreateBrowserDeviceUuid();
            let voipToken: string | undefined;
            let notifToken: string | undefined;
            let osVersion = typeof navigator !== 'undefined' ? navigator.userAgent.slice(0, 32) : 'Web';

            if (plugin && typeof plugin.registerDevice === 'function') {
                const initialNative = await plugin.registerDevice({
                    apiBaseUrl: window.location.origin,
                });
                if (initialNative?.deviceUuid) {
                    deviceUuid = initialNative.deviceUuid;
                    registeredDeviceUuid.value = deviceUuid;
                    localStorage.setItem('getnumber_device_uuid', deviceUuid);
                }
                if (initialNative?.platform === 'ios' || initialNative?.platform === 'android') {
                    platform = initialNative.platform;
                }
                if (initialNative?.osVersion) osVersion = initialNative.osVersion;
                if (initialNative?.voipPushToken) voipToken = initialNative.voipPushToken;
                if (initialNative?.notificationPushToken) notifToken = initialNative.notificationPushToken;
            }

            const regRes = await axios.post('/api/v1/mobile/devices/register', {
                device_uuid: deviceUuid,
                platform: platform,
                os_version: osVersion,
                voip_push_token: voipToken,
                notification_push_token: notifToken,
                local_push_connected: isLocalPushConnected.value,
                network_mode: currentNetworkMode.value,
            });

            if (regRes.data?.success) {
                if (regRes.data.server_network_mode) {
                    currentNetworkMode.value = regRes.data.server_network_mode as NetworkMode;
                }

                // Hand backend-issued device_auth_token and Local Push ticket/SSIDs to Native Layer
                if (plugin && typeof plugin.registerDevice === 'function') {
                    const updatedNative = await plugin.registerDevice({
                        apiBaseUrl: window.location.origin,
                        deviceAuthToken: regRes.data.device_auth_token || '',
                        localPushWsUrl: regRes.data.local_push?.ws_url || '',
                        localPushTicket: regRes.data.local_push?.ticket || '',
                        allowedIntranetSsids: regRes.data.local_push?.allowed_intranet_ssids || [],
                    });
                    isLocalPushConnected.value = Boolean(updatedNative?.localPushConnected);
                }
            }
        } catch {
            // Silently tolerate unauthenticated or offline init
        }

        await probeNetworkMode();
        if (!probeTimer) {
            probeTimer = setInterval(probeNetworkMode, 25000);
        }
    };

    const getCallState = async (): Promise<NativeCallStateSnapshot | null> => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.getCallState !== 'function') return null;
        try {
            return await plugin.getCallState();
        } catch {
            return null;
        }
    };

    const notifyCallAnswered = async (callUuid?: string | null, withVideo = false) => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.answerCall !== 'function' || !callUuid) return;
        try {
            await plugin.answerCall({ callUuid, withVideo });
        } catch {}
    };

    const notifyCallRejected = async (callUuid?: string | null, reason = 'rejected_by_callee') => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.rejectCall !== 'function' || !callUuid) return;
        try {
            await plugin.rejectCall({ callUuid, reason });
        } catch {}
    };

    const notifyCallEnded = async (callUuid?: string | null, durationSeconds = 0) => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.endCall !== 'function') return;
        try {
            await plugin.endCall({ callUuid: callUuid || '', durationSeconds });
        } catch {}
    };

    const setNativeMute = async (callUuid: string | null | undefined, muted: boolean) => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.setMute !== 'function') return;
        try {
            await plugin.setMute({ callUuid: callUuid || '', muted });
        } catch {}
    };

    const setNativeHold = async (callUuid: string | null | undefined, onHold: boolean) => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.setHold !== 'function') return;
        try {
            await plugin.setHold({ callUuid: callUuid || '', onHold });
        } catch {}
    };

    const setNativeAudioRoute = async (route: 'earpiece' | 'speaker' | 'bluetooth') => {
        const plugin = getCapacitorNativePlugin();
        if (!plugin || typeof plugin.setAudioRoute !== 'function') return;
        try {
            await plugin.setAudioRoute({ route });
        } catch {}
    };

    return {
        currentNetworkMode,
        isLocalPushConnected,
        isNativePlatform,
        registeredDeviceUuid,
        initNativeBridge,
        probeNetworkMode,
        getCallState,
        notifyCallAnswered,
        notifyCallRejected,
        notifyCallEnded,
        setNativeMute,
        setNativeHold,
        setNativeAudioRoute,
    };
}
