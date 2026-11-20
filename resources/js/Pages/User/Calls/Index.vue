<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import UserLayout from '@/Layouts/UserLayout.vue';
import ContactPickerModal, { type ContactItem as PickerContactItem } from '@/Components/ContactPickerModal.vue';
import { CalmRingtone } from '@/Utils/ringtone';
import { __ } from '@/Composables/useTrans';

interface LineItem {
    id: number;
    key?: string;
    number: string;
    formatted_number: string;
    display_title: string;
    status: string;
    is_active: boolean;
    type?: string;
    type_label?: string;
}

interface CallItem {
    id: number;
    phone_number_id: number | null;
    source_identifier: string;
    destination: string;
    direction: string;
    status: string;
    started_at: string | null;
    ended_at: string | null;
    duration_seconds: number;
    duration_formatted: string;
    cost: number;
    line_number: string;
}

interface ContactItem {
    id: number;
    name: string;
    phone_number: string;
    avatar_color: string;
    notes: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    lines: LineItem[];
    selected_line_key?: string | null;
    selected_line_id?: number | null;
    initial_destination?: string;
    calls: {
        data: CallItem[];
        links: PaginationLink[];
        total: number;
        current_page: number;
        last_page: number;
    };
    contacts: ContactItem[];
    rate_per_minute: number;
    wallet_balance: string;
    currency: string;
    rtc_config?: {
        iceServers?: RTCIceServer[];
        iceTransportPolicy?: RTCIceTransportPolicy;
        hasTurn?: boolean;
        isOfflineMode?: boolean;
    };
}>();

const activeTab = ref<'dialer' | 'history'>('dialer');

// Outgoing line selection (Supports both Phone Lines and Custom IDs)
const selectedLineKey = ref<string | null>(
    props.selected_line_key || (props.lines.length > 0 ? (props.lines[0].key || 'phone_' + props.lines[0].id) : null)
);

const selectedLine = computed(() => {
    return props.lines.find((l) => l.key === selectedLineKey.value) 
        || props.lines.find((l) => l.id === props.selected_line_id) 
        || props.lines[0] 
        || null;
});

const onLineChanged = (newKey: string | null) => {
    if (!newKey) return;
    selectedLineKey.value = newKey;
    router.get(
        route('calls.index'),
        { line_key: newKey },
        { preserveState: true, replace: true, only: ['calls', 'selected_line_key', 'selected_line_id'] }
    );
};

// Dialer State
const destinationInput = ref(props.initial_destination || '');
const keypadMode = ref<'numeric' | 'alphanumeric'>(
    props.initial_destination?.startsWith('@') ? 'alphanumeric' : 'numeric'
);
const isContactPickerOpen = ref(false);
const isCalling = ref(false);
const activeCallId = ref<number | null>(null);
const callDuration = ref(0);
let callTimer: ReturnType<typeof setInterval> | null = null;
let pulseTimer: ReturnType<typeof setInterval> | null = null;
const pingMs = ref<number>(24);
let pingTimer: ReturnType<typeof setInterval> | null = null;
const isMuted = ref(false);
const isSpeakerOn = ref(false);
const isNoiseCancellationEnabled = ref(
    typeof window !== 'undefined'
        ? localStorage.getItem('getnumber_noise_cancellation') !== 'false'
        : true
);
const callError = ref('');

// Bridge & WebRTC State
const bridgeSessionId = ref<string | null>(null);
const bridgeWsEndpoint = ref<string | null>(null);
const isBridgeConnected = ref(false);
const isInternalCall = ref(false);
let bridgeSocket: WebSocket | null = null;
let bridgePingInterval: ReturnType<typeof setInterval> | null = null;
let localMediaStream: MediaStream | null = null;
const micPermissionGranted = ref<boolean | null>(null);
const isMicModalOpen = ref(false);
const isSecureContext = ref(
    typeof window !== 'undefined'
        ? (window.isSecureContext ?? (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1'))
        : true
);

// Incoming Call Notification State
const incomingCall = ref<any>(null);
const isIncomingCallModalOpen = ref(false);
let incomingPollTimer: ReturnType<typeof setInterval> | null = null;
const calmRingtone = new CalmRingtone();

const handleContactSelected = (contact: PickerContactItem) => {
    destinationInput.value = contact.phone_number.trim();
    if (contact.phone_number.startsWith('@')) {
        keypadMode.value = 'alphanumeric';
    }
    activeTab.value = 'dialer';
};

// Keypad digits
const keypadRows = [
    [
        { digit: '1', sub: ' ' },
        { digit: '2', sub: 'ABC' },
        { digit: '3', sub: 'DEF' },
    ],
    [
        { digit: '4', sub: 'GHI' },
        { digit: '5', sub: 'JKL' },
        { digit: '6', sub: 'MNO' },
    ],
    [
        { digit: '7', sub: 'PQRS' },
        { digit: '8', sub: 'TUV' },
        { digit: '9', sub: 'WXYZ' },
    ],
    [
        { digit: '*', sub: ' ' },
        { digit: '0', sub: '+' },
        { digit: '#', sub: ' ' },
    ],
];

const appendDigit = (char: string) => {
    if (destinationInput.value.length < 24) {
        destinationInput.value += char;
    }
};

const backspace = () => {
    destinationInput.value = destinationInput.value.slice(0, -1);
};

const clearInput = () => {
    destinationInput.value = '';
};

const ensureAtPrefix = () => {
    if (!destinationInput.value.startsWith('@')) {
        destinationInput.value = '@' + destinationInput.value;
    }
};

// Request Microphone Access with Intelligent Acoustic Noise Suppression
const requestMicrophoneAccess = async (): Promise<boolean> => {
    micPermissionGranted.value = null;
    if (typeof navigator === 'undefined' || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        micPermissionGranted.value = false;
        callError.value = __('user.calls.mic_unsupported');
        return false;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: { ideal: true },
                noiseSuppression: { ideal: isNoiseCancellationEnabled.value },
                autoGainControl: { ideal: true },
                // Advanced WebRTC & Chromium constraints for crystal-clear noise reduction
                // @ts-ignore
                googEchoCancellation: { ideal: true },
                // @ts-ignore
                googAutoGainControl: { ideal: true },
                // @ts-ignore
                googNoiseSuppression: { ideal: isNoiseCancellationEnabled.value },
                // @ts-ignore
                googHighpassFilter: { ideal: isNoiseCancellationEnabled.value },
                // @ts-ignore
                googTypingNoiseDetection: { ideal: isNoiseCancellationEnabled.value },
                // @ts-ignore
                googAudioMirroring: { ideal: false },
            },
        });
        localMediaStream = stream;
        micPermissionGranted.value = true;
        isMicModalOpen.value = false;
        callError.value = '';
        console.log('[WebRTC] 🎙️ Microphone initialized with Noise Cancellation:', isNoiseCancellationEnabled.value);
        return true;
    } catch {
        micPermissionGranted.value = false;
        isMicModalOpen.value = true;
        callError.value = __('user.calls.mic_blocked');
        return false;
    }
};

const retryMicrophoneAndCall = async () => {
    const ok = await requestMicrophoneAccess();
    if (ok) {
        await startCall(false);
    }
};

const toggleMute = () => {
    isMuted.value = !isMuted.value;
    if (localMediaStream) {
        localMediaStream.getAudioTracks().forEach(track => {
            track.enabled = !isMuted.value;
        });
    }
};

const toggleSpeaker = () => {
    isSpeakerOn.value = !isSpeakerOn.value;
    if (remoteAudio.value) {
        remoteAudio.value.volume = isSpeakerOn.value ? 1.0 : 0.7;
    }
};

// Toggle Noise Cancellation live during or before a call
const toggleNoiseCancellation = async () => {
    isNoiseCancellationEnabled.value = !isNoiseCancellationEnabled.value;
    if (typeof window !== 'undefined') {
        localStorage.setItem('getnumber_noise_cancellation', String(isNoiseCancellationEnabled.value));
    }
    if (localMediaStream) {
        const audioTracks = localMediaStream.getAudioTracks();
        for (const track of audioTracks) {
            try {
                await track.applyConstraints({
                    echoCancellation: true,
                    noiseSuppression: isNoiseCancellationEnabled.value,
                    autoGainControl: true,
                    // @ts-ignore
                    googEchoCancellation: true,
                    // @ts-ignore
                    googAutoGainControl: true,
                    // @ts-ignore
                    googNoiseSuppression: isNoiseCancellationEnabled.value,
                    // @ts-ignore
                    googHighpassFilter: isNoiseCancellationEnabled.value,
                    // @ts-ignore
                    googTypingNoiseDetection: isNoiseCancellationEnabled.value,
                });
                console.log('[WebRTC] 🎧 Live track noise cancellation updated:', isNoiseCancellationEnabled.value);
            } catch (err) {
                console.warn('[WebRTC] ⚠️ Failed applying live track noise cancellation constraints:', err);
            }
        }
    }
};

// Enhance Opus SDP with In-Band FEC & DTX (Discontinuous Transmission to eliminate background noise during silence)
const enhanceOpusSdp = (sdp?: string): string => {
    if (!sdp || !sdp.includes('opus/48000')) {
        return sdp || '';
    }
    const match = sdp.match(/a=rtpmap:(\d+)\s+opus\/48000/i);
    if (!match) {
        return sdp;
    }
    const pt = match[1];
    const fmtpRegex = new RegExp(`a=fmtp:${pt}\\s+([^\\r\\n]+)`, 'i');
    const existing = sdp.match(fmtpRegex);

    if (existing) {
        let params = existing[1];
        if (!params.includes('useinbandfec=')) {
            params += ';useinbandfec=1';
        }
        if (!params.includes('usedtx=')) {
            params += ';usedtx=1';
        }
        if (!params.includes('stereo=')) {
            params += ';stereo=0';
        }
        return sdp.replace(fmtpRegex, `a=fmtp:${pt} ${params}`);
    } else {
        const replacement = `a=rtpmap:${pt} opus/48000/2\r\na=fmtp:${pt} minptime=10;useinbandfec=1;usedtx=1;stereo=0`;
        return sdp.replace(new RegExp(`a=rtpmap:${pt}\\s+opus\\/48000\\/2`, 'i'), replacement);
    }
};

// WebRTC & Audio Infrastructure
const remoteAudio = ref<HTMLAudioElement | null>(null);
let peerConnection: RTCPeerConnection | null = null;
let signalPollTimer: ReturnType<typeof setInterval> | null = null;
let lastSignalId = 0;
let isPollingSignals = false;
let processedSignalHashes = new Set<string>();
const mediaConnectionState = ref<'new' | 'connecting' | 'connected' | 'disconnected' | 'failed'>('new');
const isAudioPlaybackBlocked = ref(false);
const pendingWsMessages = ref<string[]>([]);
const activeRtcConfig = ref<{
    iceServers?: RTCIceServer[];
    iceTransportPolicy?: RTCIceTransportPolicy;
    hasTurn?: boolean;
    isOfflineMode?: boolean;
} | null>(props.rtc_config || null);

const buildRtcConfiguration = (): RTCConfiguration => {
    const cfg = activeRtcConfig.value || props.rtc_config;
    const iceServers: RTCIceServer[] = Array.isArray(cfg?.iceServers) ? cfg.iceServers : [];
    console.log('[WebRTC] 🌐 Effective ICE Servers configuration:', JSON.stringify(iceServers, null, 2));
    console.log('[WebRTC] 🛡️ Has TURN:', Boolean(cfg?.hasTurn), '| Policy:', cfg?.iceTransportPolicy || 'all', '| Offline Mode:', Boolean(cfg?.isOfflineMode));
    return {
        iceServers: iceServers,
        iceTransportPolicy: (cfg?.iceTransportPolicy as RTCIceTransportPolicy) || 'all',
        iceCandidatePoolSize: 4,
    };
};

const unblockRemoteAudioPlayback = async () => {
    if (!remoteAudio.value) return;
    try {
        remoteAudio.value.muted = false;
        remoteAudio.value.volume = isSpeakerOn.value ? 1.0 : 0.85;
        await remoteAudio.value.play();
        isAudioPlaybackBlocked.value = false;
    } catch {
        isAudioPlaybackBlocked.value = true;
    }
};

const sendSignalingMessage = async (type: string, payload: any) => {
    if (!activeCallId.value) return;

    const rawWsPayload = JSON.stringify({
        type: type,
        session_id: bridgeSessionId.value,
        payload: payload,
    });

    // 1. Go WebSocket bridge signaling (ultra-low latency if connected or queued)
    if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN && bridgeSessionId.value) {
        try {
            bridgeSocket.send(rawWsPayload);
            console.log('[WebRTC-WS] 📤 Sent signaling message directly via WebSocket:', type);
        } catch (e) {
            console.warn('[WebRTC-WS] ⚠️ WebSocket send error:', e);
        }
    } else if (bridgeSocket && bridgeSocket.readyState === WebSocket.CONNECTING && bridgeSessionId.value) {
        pendingWsMessages.value.push(rawWsPayload);
        console.log('[WebRTC-WS] ⏳ Queued signaling message until WebSocket open:', type);
    }

    // 2. Atomic DB signaling mailbox in Laravel (guaranteed across all networks/devices)
    try {
        await axios.post(route('voice.signal', activeCallId.value), {
            type: type,
            payload: payload,
        });
        console.log('[WebRTC-DB] 📬 Saved signaling message in DB mailbox:', type);
    } catch (e) {
        console.warn('[WebRTC-DB] ⚠️ DB signaling mailbox error:', e);
    }
};

let pendingCandidates: RTCIceCandidateInit[] = [];

const createPeerConnection = () => {
    if (peerConnection) {
        try {
            peerConnection.close();
        } catch {}
        peerConnection = null;
    }
    pendingCandidates = [];
    mediaConnectionState.value = 'connecting';

    const pc = new RTCPeerConnection(buildRtcConfiguration());

    // Attach local microphone tracks (or create a sendrecv transceiver if mic is not yet available)
    const audioTracks = localMediaStream ? localMediaStream.getAudioTracks() : [];
    if (audioTracks.length > 0 && localMediaStream) {
        audioTracks.forEach(track => {
            try {
                pc.addTrack(track, localMediaStream!);
            } catch {}
        });
    } else {
        try {
            pc.addTransceiver('audio', { direction: 'sendrecv' });
        } catch {}
    }

    // Connect remote incoming audio stream to audio element
    pc.ontrack = (event) => {
        let stream = event.streams && event.streams[0] ? event.streams[0] : null;
        if (!stream && event.track) {
            stream = new MediaStream([event.track]);
        }
        if (remoteAudio.value && stream) {
            remoteAudio.value.srcObject = stream;
            remoteAudio.value.muted = false;
            remoteAudio.value.volume = isSpeakerOn.value ? 1.0 : 0.85;
            remoteAudio.value.play()
                .then(() => {
                    isAudioPlaybackBlocked.value = false;
                })
                .catch(e => {
                    console.warn('Audio autoplay blocked by browser, awaiting user tap:', e);
                    isAudioPlaybackBlocked.value = true;
                });
        }
    };

    // Relay local ICE candidates
    pc.onicecandidate = (event) => {
        if (event.candidate) {
            console.log('[WebRTC] ❄️ Local candidate gathered:', event.candidate.type, event.candidate.address || event.candidate.relatedAddress, event.candidate.port, event.candidate.candidate);
            sendSignalingMessage('ice_candidate', event.candidate.toJSON());
        } else {
            console.log('[WebRTC] ❄️ ICE candidate gathering complete');
        }
    };

    const updateStateFromPc = () => {
        const st = pc.iceConnectionState;
        console.log('[WebRTC] 🔄 ICE State:', st, '| Connection:', pc.connectionState, '| Signaling:', pc.signalingState);
        if (st === 'connected' || st === 'completed' || pc.connectionState === 'connected') {
            mediaConnectionState.value = 'connected';
            isBridgeConnected.value = true;
        } else if (st === 'failed' || pc.connectionState === 'failed') {
            mediaConnectionState.value = 'failed';
            const hasTurn = Boolean((activeRtcConfig.value || props.rtc_config)?.hasTurn);
            if (!hasTurn) {
                callError.value = __('user.calls.turn_required_error');
            }
        } else if (st === 'disconnected') {
            mediaConnectionState.value = 'disconnected';
        } else if (st === 'checking') {
            mediaConnectionState.value = 'connecting';
        }
    };

    pc.onconnectionstatechange = updateStateFromPc;
    pc.oniceconnectionstatechange = updateStateFromPc;

    peerConnection = pc;
    return pc;
};

const handleSignalingPayload = async (type: string, payload: any) => {
    if (!peerConnection || !payload) return;

    // Deduplicate identical signaling messages arriving via both WebSocket and HTTP poll
    try {
        const sigKey = `${type}:${typeof payload === 'string' ? payload : JSON.stringify(payload)}`;
        if (type !== 'end_call' && type !== 'call_ended') {
            if (processedSignalHashes.has(sigKey)) {
                return;
            }
            processedSignalHashes.add(sigKey);
        }
    } catch {}

    try {
        if (type === 'offer') {
            if (peerConnection.signalingState !== 'stable') {
                try {
                    await peerConnection.setLocalDescription({ type: 'rollback' } as RTCSessionDescriptionInit);
                } catch {}
            }
            await peerConnection.setRemoteDescription(new RTCSessionDescription(payload));
            // Drain any pending ICE candidates queued before remote description was ready
            for (const cand of pendingCandidates) {
                try {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(cand));
                } catch {}
            }
            pendingCandidates = [];

            const answer = await peerConnection.createAnswer();
            if (answer.sdp) {
                answer.sdp = enhanceOpusSdp(answer.sdp);
            }
            await peerConnection.setLocalDescription(answer);
            await sendSignalingMessage('answer', answer);
        } else if (type === 'answer') {
            if (peerConnection.signalingState === 'have-local-offer') {
                await peerConnection.setRemoteDescription(new RTCSessionDescription(payload));
                for (const cand of pendingCandidates) {
                    try {
                        await peerConnection.addIceCandidate(new RTCIceCandidate(cand));
                    } catch {}
                }
                pendingCandidates = [];
            }
        } else if (type === 'ice_candidate') {
            if (!peerConnection.remoteDescription || !peerConnection.remoteDescription.type) {
                pendingCandidates.push(payload);
            } else {
                try {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(payload));
                } catch {}
            }
        } else if (type === 'end_call' || type === 'call_ended') {
            callError.value = __('user.calls.call_ended_by_peer');
            await endCall();
        }
    } catch (err) {
        console.warn('Signaling error:', err);
    }
};

const pollSignals = async () => {
    if (!activeCallId.value || !isCalling.value || isPollingSignals) return;
    isPollingSignals = true;

    try {
        const res = await axios.get(route('voice.signals', activeCallId.value), {
            params: { after_id: lastSignalId },
        });
        if (res.data?.success) {
            if (!res.data.is_active || res.data.status === 'completed' || res.data.status === 'declined') {
                callError.value = __('user.calls.call_ended_by_peer');
                await endCall();
                return;
            }

            if (typeof res.data.last_signal_id === 'number' && res.data.last_signal_id > lastSignalId) {
                lastSignalId = res.data.last_signal_id;
            }

            if (Array.isArray(res.data.signals)) {
                for (const sig of res.data.signals) {
                    if (typeof sig.id === 'number' && sig.id > lastSignalId) {
                        lastSignalId = sig.id;
                    }
                    await handleSignalingPayload(sig.type, sig.payload);
                }
            }
        }
    } catch {} finally {
        isPollingSignals = false;
    }
};

// WebSocket signaling connection to Voice Bridge
const connectBridgeWebSocket = (wsUrl: string) => {
    try {
        if (bridgeSocket) {
            bridgeSocket.close();
            bridgeSocket = null;
        }

        // On remote/production HTTPS domain, if wsUrl points to localhost/127.0.0.1 or insecure ws://, avoid connecting to prevent mixed content or localhost connection refused
        const isRemote = window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1';
        if (isRemote && (wsUrl.includes('127.0.0.1') || wsUrl.includes('localhost') || (window.location.protocol === 'https:' && wsUrl.startsWith('ws://')))) {
            isBridgeConnected.value = false;
            return;
        }

        let targetWsUrl = wsUrl;
        if (bridgeSessionId.value && !targetWsUrl.includes('session=')) {
            targetWsUrl += (targetWsUrl.includes('?') ? '&' : '?') + 'session=' + encodeURIComponent(bridgeSessionId.value);
        }

        bridgeSocket = new WebSocket(targetWsUrl);
        console.log('[WebRTC-WS] 🔌 Initiating WebSocket connection to:', targetWsUrl);
        bridgeSocket.onopen = () => {
            console.log('[WebRTC-WS] 🟢 Connected to Voice Bridge signaling WebSocket');
            isBridgeConnected.value = true;
            if (bridgeSessionId.value) {
                bridgeSocket?.send(JSON.stringify({
                    type: 'join_session',
                    session_id: bridgeSessionId.value,
                }));
            }
            // Flush any signaling messages queued before socket opened (e.g. Offer, candidates)
            while (pendingWsMessages.value.length > 0) {
                const nextMsg = pendingWsMessages.value.shift();
                if (nextMsg && bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
                    try {
                        bridgeSocket.send(nextMsg);
                        console.log('[WebRTC-WS] 🚀 Flushed queued signaling message to bridge');
                    } catch (e) {
                        console.warn('[WebRTC-WS] ⚠️ Failed flushing queued message:', e);
                    }
                }
            }
            bridgePingInterval = setInterval(() => {
                if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
                    bridgeSocket.send(JSON.stringify({
                        type: 'ping',
                        client_time: Date.now(),
                        session_id: bridgeSessionId.value || undefined,
                    }));
                }
            }, 2500);
        };
        bridgeSocket.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                if (msg.type === 'pong') {
                    if (typeof msg.client_time === 'number' && msg.client_time > 0) {
                        const rtt = Math.max(1, Date.now() - msg.client_time);
                        if (rtt < 2000) pingMs.value = rtt;
                    } else if (typeof msg.ping_ms === 'number' && msg.ping_ms > 0) {
                        pingMs.value = msg.ping_ms;
                    }
                } else if (msg.type === 'channel_connected') {
                    console.log('[WebRTC-WS] 🎉 Voice channel synchronized between peers!');
                    isBridgeConnected.value = true;
                } else if (msg.type === 'call_ended') {
                    console.log('[WebRTC-WS] ⏹️ Remote peer ended call via bridge');
                    callError.value = __('user.calls.call_ended_by_peer');
                    endCall();
                } else if (msg.type === 'offer' || msg.type === 'answer' || msg.type === 'ice_candidate') {
                    console.log('[WebRTC-WS] 📥 Inbound peer signaling payload received via WS:', msg.type);
                    handleSignalingPayload(msg.type, msg.data || msg.payload);
                }
            } catch (e) {
                console.warn('[WebRTC-WS] ⚠️ Error parsing WebSocket message:', e);
            }
        };
        bridgeSocket.onerror = (e) => {
            console.warn('[WebRTC-WS] ❌ WebSocket error:', e);
            isBridgeConnected.value = false;
        };
        bridgeSocket.onclose = (e) => {
            console.log('[WebRTC-WS] 🔌 WebSocket closed. Code:', e.code, 'Reason:', e.reason);
            isBridgeConnected.value = false;
            if (bridgePingInterval) clearInterval(bridgePingInterval);
        };
    } catch {
        isBridgeConnected.value = false;
    }
};

// Start Voice Call
const startCall = async (skipMicCheck = false) => {
    if (!destinationInput.value.trim() || !selectedLine.value || isCalling.value) return;

    callError.value = '';
    lastSignalId = 0;
    processedSignalHashes.clear();
    pendingWsMessages.value = [];
    isAudioPlaybackBlocked.value = false;
    mediaConnectionState.value = 'new';
    
    // Check & request microphone permission if not explicitly bypassed
    if (!skipMicCheck) {
        const hasMic = await requestMicrophoneAccess();
        if (!hasMic) return;
    }

    if (isMicModalOpen.value) {
        isMicModalOpen.value = false;
    }

    isCalling.value = true;
    callDuration.value = 0;

    if (remoteAudio.value) {
        remoteAudio.value.muted = false;
        remoteAudio.value.play().catch(() => {});
    }

    try {
        const res = await axios.post(route('voice.initiate'), {
            line_key: selectedLineKey.value || undefined,
            phone_number_id: selectedLine.value?.id,
            destination: destinationInput.value.trim(),
        });

        if (res.data?.success && res.data.call) {
            activeCallId.value = res.data.call.id;
            bridgeSessionId.value = res.data.call.bridge_session_id || null;
            bridgeWsEndpoint.value = res.data.call.bridge_ws_endpoint || null;
            isInternalCall.value = Boolean(res.data.call.is_internal);
            pingMs.value = res.data.call.ping_ms || 22;
            if (res.data.call.rtc_config) {
                activeRtcConfig.value = res.data.call.rtc_config;
            }

            if (bridgeWsEndpoint.value) {
                connectBridgeWebSocket(bridgeWsEndpoint.value);
            }

            callTimer = setInterval(() => {
                callDuration.value++;
            }, 1000);

            // Establish WebRTC Audio Peer Connection
            const pc = createPeerConnection();
            const offer = await pc.createOffer({ offerToReceiveAudio: true });
            if (offer.sdp) {
                offer.sdp = enhanceOpusSdp(offer.sdp);
            }
            await pc.setLocalDescription(offer);
            await sendSignalingMessage('offer', offer);

            // Periodic Balance & Active Status Heartbeat (every 2 seconds)
            pulseTimer = setInterval(async () => {
                if (!activeCallId.value || !isCalling.value) return;
                try {
                    const pulseRes = await axios.post(route('voice.pulse', activeCallId.value), {
                        elapsed_seconds: callDuration.value,
                    });
                    if (pulseRes.data?.can_continue === false || pulseRes.data?.active === false) {
                        callError.value = pulseRes.data.message || __('user.calls.call_ended');
                        endCall();
                    }
                } catch {}
            }, 2000);

            // WebRTC Signaling Queue Poll (every 800ms)
            signalPollTimer = setInterval(pollSignals, 800);
        } else {
            throw new Error(res.data?.message || __('user.calls.call_init_error'));
        }
    } catch (err: any) {
        isCalling.value = false;
        callError.value = err.response?.data?.message || err.message || __('user.calls.call_connect_error');
        if (localMediaStream) {
            localMediaStream.getTracks().forEach(t => t.stop());
            localMediaStream = null;
        }
    }
};

const initiateCallAction = () => {
    startCall(false);
};

// End Voice Call (Mutual Hangup)
const endCall = async () => {
    if (signalPollTimer) {
        clearInterval(signalPollTimer);
        signalPollTimer = null;
    }
    if (pulseTimer) {
        clearInterval(pulseTimer);
        pulseTimer = null;
    }
    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }
    if (pingTimer) {
        clearInterval(pingTimer);
        pingTimer = null;
    }
    if (bridgePingInterval) {
        clearInterval(bridgePingInterval);
        bridgePingInterval = null;
    }

    // Send hangup signal to peer first so both sides hang up together
    if (activeCallId.value) {
        sendSignalingMessage('end_call', { reason: 'user_hangup' });
    }

    if (peerConnection) {
        try {
            peerConnection.close();
        } catch {}
        peerConnection = null;
    }
    if (localMediaStream) {
        localMediaStream.getTracks().forEach(t => t.stop());
        localMediaStream = null;
    }
    if (remoteAudio.value) {
        remoteAudio.value.srcObject = null;
    }
    if (bridgeSocket) {
        try {
            bridgeSocket.close();
        } catch {}
        bridgeSocket = null;
    }
    isBridgeConnected.value = false;
    bridgeSessionId.value = null;
    mediaConnectionState.value = 'new';
    isAudioPlaybackBlocked.value = false;
    pendingWsMessages.value = [];
    lastSignalId = 0;
    processedSignalHashes.clear();

    const terminatingCallId = activeCallId.value;
    activeCallId.value = null;
    isCalling.value = false;
    const finalDuration = callDuration.value;
    callDuration.value = 0;

    if (!terminatingCallId) return;

    try {
        await axios.post(route('voice.end', terminatingCallId), {
            duration_seconds: finalDuration,
        });
    } catch {
        // continue clean-up
    } finally {
        router.reload({ only: ['calls', 'wallet_balance'] });
    }
};

// Incoming Call Sound & Ringtone Simulation (Calm Acoustic Chime)
const playRingtone = () => {
    calmRingtone.play();
};

const stopRingtone = () => {
    calmRingtone.stop();
};

const checkIncomingCall = async () => {
    if (isCalling.value) return;
    try {
        const res = await axios.get(route('voice.active_incoming'));
        if (res.data?.has_incoming && res.data.call) {
            if (!incomingCall.value || incomingCall.value.id !== res.data.call.id) {
                incomingCall.value = res.data.call;
                isIncomingCallModalOpen.value = true;
                playRingtone();
            }
        } else {
            if (isIncomingCallModalOpen.value) {
                stopRingtone();
                isIncomingCallModalOpen.value = false;
                incomingCall.value = null;
            }
        }
    } catch {}
};

const answerIncomingCall = async () => {
    if (!incomingCall.value) return;
    stopRingtone();
    lastSignalId = 0;
    processedSignalHashes.clear();
    pendingWsMessages.value = [];
    isAudioPlaybackBlocked.value = false;
    mediaConnectionState.value = 'new';

    if (remoteAudio.value) {
        remoteAudio.value.muted = false;
        remoteAudio.value.play().catch(() => {});
    }
    const callId = incomingCall.value.id;
    try {
        const res = await axios.post(route('voice.answer', callId));
        if (res.data?.success) {
            const answeredCall = res.data.call || {};
            isIncomingCallModalOpen.value = false;
            activeCallId.value = callId;
            destinationInput.value = answeredCall.source || incomingCall.value.source || '';
            isCalling.value = true;
            callDuration.value = 0;
            pingMs.value = answeredCall.ping_ms || incomingCall.value.ping_ms || 22;
            bridgeSessionId.value = answeredCall.bridge_session_id || incomingCall.value.bridge_session_id || null;
            bridgeWsEndpoint.value = answeredCall.bridge_ws_endpoint || incomingCall.value.bridge_ws_endpoint || null;
            isBridgeConnected.value = Boolean(bridgeSessionId.value);
            isInternalCall.value = true;
            if (answeredCall.rtc_config) {
                activeRtcConfig.value = answeredCall.rtc_config;
            }

            await requestMicrophoneAccess();

            callTimer = setInterval(() => {
                callDuration.value++;
            }, 1000);

            // Establish Callee WebRTC Peer Connection
            createPeerConnection();

            if (bridgeWsEndpoint.value) {
                connectBridgeWebSocket(bridgeWsEndpoint.value);
            }

            // Callee Pulse Heartbeat (Detects if caller hung up or balance expired)
            pulseTimer = setInterval(async () => {
                if (!activeCallId.value || !isCalling.value) return;
                try {
                    const pulseRes = await axios.post(route('voice.pulse', activeCallId.value), {
                        elapsed_seconds: callDuration.value,
                    });
                    if (pulseRes.data?.can_continue === false || pulseRes.data?.active === false) {
                        callError.value = pulseRes.data.message || __('user.calls.call_ended');
                        endCall();
                    }
                } catch {}
            }, 2000);

            // Callee Signaling Poll (Fetches caller's Offer & candidates)
            signalPollTimer = setInterval(pollSignals, 800);
            await pollSignals();
        }
    } catch {}
};

const declineIncomingCall = async () => {
    if (!incomingCall.value) return;
    stopRingtone();
    const callId = incomingCall.value.id;
    isIncomingCallModalOpen.value = false;
    incomingCall.value = null;
    try {
        await axios.post(route('voice.decline', callId));
        router.reload({ only: ['calls'] });
    } catch {}
};

const formatTimer = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
};

// Redial / Call from History or Contact
const dialNumber = (number: string) => {
    destinationInput.value = number.replace(/\s+/g, '');
    activeTab.value = 'dialer';
};

onMounted(() => {
    if (typeof window !== 'undefined') {
        const params = new URLSearchParams(window.location.search);
        const target = params.get('to') || params.get('destination') || params.get('number') || params.get('custom_id');
        if (target) {
            destinationInput.value = target.trim();
            if (target.startsWith('@')) {
                keypadMode.value = 'alphanumeric';
            }
            activeTab.value = 'dialer';
        }
        const lineKeyParam = params.get('line_key');
        if (lineKeyParam) {
            const matched = props.lines.find((l) => l.key === lineKeyParam);
            if (matched) {
                selectedLineKey.value = matched.key || null;
            }
        } else {
            const lineParam = params.get('phone_number_id');
            if (lineParam) {
                const matched = props.lines.find((l) => l.id === Number(lineParam));
                if (matched) {
                    selectedLineKey.value = matched.key || null;
                }
            }
        }
    }

    // Auto-answer or open incoming call if redirected from global call modal
    if (typeof window !== 'undefined') {
        const urlParams = new URLSearchParams(window.location.search);
        const autoAnswer = urlParams.get('auto_answer');
        const incomingCallId = urlParams.get('incoming_call_id');
        if (incomingCallId) {
            incomingCall.value = {
                id: parseInt(incomingCallId, 10),
                source: urlParams.get('caller') || __('user.calls.incoming_call_fallback'),
                destination: urlParams.get('line') || '',
            };
            if (autoAnswer === '1') {
                setTimeout(() => {
                    answerIncomingCall();
                }, 300);
            } else {
                isIncomingCallModalOpen.value = true;
                playRingtone();
            }
        }
    }

    // Start incoming call detector
    checkIncomingCall();
    incomingPollTimer = setInterval(checkIncomingCall, 3500);
});

onUnmounted(() => {
    if (callTimer) clearInterval(callTimer);
    if (pulseTimer) clearInterval(pulseTimer);
    if (signalPollTimer) clearInterval(signalPollTimer);
    if (pingTimer) clearInterval(pingTimer);
    if (bridgePingInterval) clearInterval(bridgePingInterval);
    if (incomingPollTimer) clearInterval(incomingPollTimer);
    stopRingtone();
    if (peerConnection) {
        try { peerConnection.close(); } catch {}
        peerConnection = null;
    }
    if (bridgeSocket) {
        bridgeSocket.close();
        bridgeSocket = null;
    }
    if (localMediaStream) {
        localMediaStream.getTracks().forEach(t => t.stop());
        localMediaStream = null;
    }
    if (remoteAudio.value) {
        remoteAudio.value.srcObject = null;
    }
});
</script>

<template>
    <Head :title="__('user.calls.page_title')" />

    <UserLayout>
        <div class="space-y-4">
            <!-- Header Command Card -->
            <div class="dash-wallet-card">
                <div class="wallet-top-row">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <h2 class="wallet-title">{{ __('user.calls.header_title') }}</h2>
                    </div>
                    <span class="wallet-chip bg-emerald-500/15 border-emerald-500/30 text-emerald-600 dark:text-emerald-400">
                        {{ __('user.calls.service_active_badge') }}
                    </span>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ __('user.calls.header_desc') }}
                </p>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-200/80 dark:border-white/10 text-xs">
                    <div class="flex items-center gap-3">
                        <span class="text-slate-500 dark:text-slate-400">
                            {{ __('user.calls.balance_label') }}
                            <strong class="font-mono text-emerald-600 dark:text-emerald-400 font-bold" dir="ltr">
                                {{ Number(wallet_balance).toFixed(2) }} {{ currency }}
                            </strong>
                        </span>
                        <span class="text-slate-300 dark:text-slate-600">|</span>
                        <span class="text-slate-500 dark:text-slate-400">
                            {{ __('user.calls.tariff_label') }}
                            <strong class="font-mono text-indigo-600 dark:text-indigo-400 font-bold" dir="ltr">
                                {{ rate_per_minute }} {{ currency }} / {{ __('user.calls.minute_unit') }}
                            </strong>
                        </span>
                    </div>

                    <div v-if="selectedLine" class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-xs">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 font-bold">{{ __('user.calls.outbound_line_label') }}:</span>
                        <span class="font-mono font-black text-indigo-600 dark:text-indigo-400" dir="ltr">
                            {{ selectedLine.formatted_number }}
                        </span>
                        <span class="text-[10px] text-slate-400 hidden sm:inline">({{ selectedLine.display_title }})</span>
                    </div>
                </div>
            </div>

            <!-- Navigation Switcher: 2 Main Tabs + Direct Contacts Link -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 flex-1 max-w-md">
                    <button
                        type="button"
                        @click="activeTab = 'dialer'"
                        class="flex-1 py-2.5 px-3 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border"
                        :class="activeTab === 'dialer'
                            ? 'bg-emerald-600 border-emerald-600 text-white shadow-md shadow-emerald-600/20'
                            : 'glass-pill text-slate-600 dark:text-slate-300 border-slate-200/80 dark:border-white/10'"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>{{ __('user.calls.tab_dialer') }}</span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'history'"
                        class="flex-1 py-2.5 px-3 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border"
                        :class="activeTab === 'history'
                            ? 'bg-indigo-600 border-indigo-600 text-white shadow-md shadow-indigo-600/20'
                            : 'glass-pill text-slate-600 dark:text-slate-300 border-slate-200/80 dark:border-white/10'"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ __('user.calls.tab_history') }}</span>
                        <span v-if="calls.total > 0" class="text-[10px] px-1.5 py-0.2 rounded-full bg-white/20">
                            {{ calls.total }}
                        </span>
                    </button>
                </div>

                <Link
                    :href="route('contacts.index')"
                    class="py-2.5 px-4 rounded-2xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 border glass-pill text-indigo-600 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800/60 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 shrink-0"
                    :title="__('user.calls.contacts_tooltip')"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span>{{ __('user.calls.contacts_link') }}</span>
                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </Link>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 1: PHONE DIALER (Mobile Layout: 1-2-3 / 4-5-6 / 7-8-9 / *-0-#) -->
            <!-- ======================================================== -->
            <div v-if="activeTab === 'dialer'" class="glass-card !p-5 max-w-md mx-auto space-y-5 bg-white/95 dark:bg-slate-900/80 border border-slate-200/90 dark:border-white/10 shadow-xl">
                <!-- If currently in active call session -->
                <div v-if="isCalling" class="text-center py-6 space-y-4 animate-in fade-in zoom-in-95 duration-200">
                    <div class="relative w-24 h-24 mx-auto flex items-center justify-center">
                        <span class="absolute inset-0 rounded-full bg-emerald-500/20 animate-ping"></span>
                        <div class="w-20 h-20 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-xl shadow-emerald-500/40">
                            <svg class="w-9 h-9" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-xs text-slate-400">{{ __('user.calls.calling_from_line', { line: selectedLine?.formatted_number || '' }) }}</div>
                        <div class="text-2xl font-black font-mono tracking-wider text-slate-900 dark:text-white" dir="ltr">
                            {{ destinationInput }}
                        </div>
                        <div class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400 mt-2">
                            {{ formatTimer(callDuration) }}
                        </div>

                        <!-- Real-time Bridge & WebRTC Media Channel Status Indicator -->
                        <div class="pt-2 flex flex-col items-center gap-1.5">
                            <div
                                v-if="mediaConnectionState === 'connected'"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>{{ __('user.calls.audio_connected') }}</span>
                                <span class="text-slate-400">|</span>
                                <span>{{ __('user.calls.ping_ms', { ms: pingMs }) }}</span>
                            </div>
                            <div
                                v-else-if="mediaConnectionState === 'failed'"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-rose-500/15 border border-rose-500/30 text-rose-600 dark:text-rose-400 text-xs font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping"></span>
                                <span>{{ __('user.calls.audio_failed') }}</span>
                            </div>
                            <div
                                v-else-if="isBridgeConnected || bridgeSessionId"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-indigo-500/15 border border-indigo-500/30 text-indigo-600 dark:text-indigo-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 animate-pulse"></span>
                                <span>{{ __('user.calls.audio_syncing') }}</span>
                                <span class="text-slate-400">|</span>
                                <span>{{ __('user.calls.ping_ms', { ms: pingMs }) }}</span>
                            </div>
                            <div
                                v-else
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-600 dark:text-amber-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                                <span>🟡 {{ __('user.calls.audio_connecting') }}</span>
                                <span class="text-slate-400">|</span>
                                <span>{{ __('user.calls.ping_ms', { ms: pingMs }) }}</span>
                            </div>

                            <!-- Browser Autoplay Unblock Prompt -->
                            <button
                                v-if="isAudioPlaybackBlocked"
                                type="button"
                                @click="unblockRemoteAudioPlayback"
                                class="mt-1 px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold shadow-md animate-bounce"
                            >
                                🔊 {{ __('user.calls.unblock_audio_btn') }}
                            </button>

                            <div class="flex items-center gap-1.5 flex-wrap justify-center">
                                <div v-if="isInternalCall" class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2.5 py-0.5 rounded-full">
                                    ✨ {{ __('user.calls.internal_call_badge') }}
                                </div>
                                <button
                                    type="button"
                                    @click="toggleNoiseCancellation"
                                    class="text-[11px] font-bold px-2.5 py-0.5 rounded-full transition-all flex items-center gap-1 cursor-pointer"
                                    :class="isNoiseCancellationEnabled ? 'text-purple-600 dark:text-purple-400 bg-purple-500/15 border border-purple-500/30' : 'text-slate-400 bg-slate-500/10 border border-slate-500/20'"
                                    :title="isNoiseCancellationEnabled ? __('user.calls.nc_toggle_off_title') : __('user.calls.nc_toggle_on_title')"
                                >
                                    <span>🎧</span>
                                    <span>{{ isNoiseCancellationEnabled ? __('user.calls.nc_active') : __('user.calls.nc_inactive') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Call Controls -->
                    <div class="flex items-center justify-center gap-3 sm:gap-4 pt-3">
                        <!-- Mute / Unmute -->
                        <button
                            type="button"
                            @click="toggleMute"
                            class="w-12 h-12 rounded-2xl flex flex-col items-center justify-center border transition-all"
                            :class="isMuted ? 'bg-amber-500/20 border-amber-500 text-amber-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                            :title="isMuted ? __('user.calls.mic_muted_title') : __('user.calls.mic_active_title')"
                        >
                            <svg class="w-4 h-4 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                            </svg>
                            <span class="text-[9px] font-bold">{{ isMuted ? __('user.calls.muted_label') : __('user.calls.unmuted_label') }}</span>
                        </button>

                        <!-- Noise Cancellation Live Toggle -->
                        <button
                            type="button"
                            @click="toggleNoiseCancellation"
                            class="w-12 h-12 rounded-2xl flex flex-col items-center justify-center border transition-all"
                            :class="isNoiseCancellationEnabled ? 'bg-purple-500/20 border-purple-500 text-purple-600 dark:text-purple-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-400 border-slate-200 dark:border-slate-700'"
                            :title="isNoiseCancellationEnabled ? __('user.calls.nc_btn_active_title') : __('user.calls.nc_btn_inactive_title')"
                        >
                            <svg class="w-4 h-4 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3" />
                            </svg>
                            <span class="text-[9px] font-bold">{{ isNoiseCancellationEnabled ? __('user.calls.nc_short_on') : __('user.calls.nc_short_off') }}</span>
                        </button>

                        <!-- Hang up button -->
                        <button
                            type="button"
                            @click="endCall"
                            class="w-16 h-16 rounded-full bg-rose-600 hover:bg-rose-700 text-white flex items-center justify-center shadow-xl shadow-rose-600/40 transition-transform active:scale-95"
                            :title="__('user.calls.hangup_title')"
                        >
                            <svg class="w-8 h-8 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </button>

                        <!-- Speaker / Earpiece -->
                        <button
                            type="button"
                            @click="toggleSpeaker"
                            class="w-12 h-12 rounded-2xl flex flex-col items-center justify-center border transition-all"
                            :class="isSpeakerOn ? 'bg-blue-500/20 border-blue-500 text-blue-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'"
                            :title="isSpeakerOn ? __('user.calls.speaker_on_title') : __('user.calls.speaker_off_title')"
                        >
                            <svg class="w-4 h-4 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                            </svg>
                            <span class="text-[9px] font-bold">{{ isSpeakerOn ? __('user.calls.speaker_on_label') : __('user.calls.speaker_off_label') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Normal Dialing Keypad View -->
                <div v-else class="space-y-4">
                    <!-- Keypad Mode Switcher (Numbers vs IDs) -->
                    <div class="flex items-center justify-center p-1 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200/80 dark:border-white/10 max-w-xs mx-auto">
                        <button
                            type="button"
                            @click="keypadMode = 'numeric'"
                            class="flex-1 py-1.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                            :class="keypadMode === 'numeric'
                                ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                        >
                            <span>🔢 {{ __('user.calls.keypad_numeric') }}</span>
                        </button>
                        <button
                            type="button"
                            @click="keypadMode = 'alphanumeric'"
                            class="flex-1 py-1.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5"
                            :class="keypadMode === 'alphanumeric'
                                ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                                : 'text-slate-500 hover:text-slate-800 dark:hover:text-white'"
                        >
                            <span>🆔 {{ __('user.calls.keypad_handle') }}</span>
                        </button>
                    </div>

                    <!-- Dialed Number Display Area with Contact Picker Link -->
                    <div class="p-3.5 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-2 min-h-[60px]">
                        <button
                            type="button"
                            v-if="destinationInput"
                            @click="clearInput"
                            class="text-xs text-slate-400 hover:text-rose-500 font-bold px-2"
                        >
                            {{ __('user.calls.clear_input') }}
                        </button>
                        <div v-else class="w-12"></div>

                        <input
                            type="text"
                            v-model="destinationInput"
                            :placeholder="keypadMode === 'numeric' ? __('user.calls.placeholder_number') : __('user.calls.placeholder_handle')"
                            class="flex-1 text-center font-mono font-black text-xl text-slate-900 dark:text-white bg-transparent outline-none tracking-wider"
                            dir="ltr"
                        />

                        <div class="flex items-center gap-1">
                            <!-- Contact Picker Button -->
                            <button
                                type="button"
                                @click="isContactPickerOpen = true"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10 transition-colors"
                                :title="__('user.calls.pick_contact_title')"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </button>

                            <!-- Backspace Button -->
                            <button
                                type="button"
                                @click="backspace"
                                class="w-9 h-9 rounded-xl flex items-center justify-center text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                                :disabled="!destinationInput"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414-6.414a2 2 0 011.414-.586H19a2 2 0 012 2v10a2 2 0 01-2 2h-7.172a2 2 0 01-1.414-.586L3 12z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div v-if="callError" class="p-2.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 text-xs font-bold text-center">
                        {{ callError }}
                    </div>

                    <!-- MODE 1: Standard 3x4 Telephone Dialpad (LTR) -->
                    <div v-if="keypadMode === 'numeric'" class="grid grid-cols-3 gap-2.5" dir="ltr">
                        <template v-for="row in keypadRows" :key="row[0].digit">
                            <button
                                v-for="btn in row"
                                :key="btn.digit"
                                type="button"
                                @click="appendDigit(btn.digit)"
                                class="h-14 rounded-2xl bg-slate-100 hover:bg-slate-200/90 dark:bg-slate-800/80 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-white/5 flex flex-col items-center justify-center text-slate-900 dark:text-white transition-all active:scale-95 shadow-xs"
                            >
                                <span class="font-mono font-black text-xl leading-none">{{ btn.digit }}</span>
                                <span v-if="btn.sub" class="text-[9px] font-mono tracking-widest text-slate-400 dark:text-slate-500 mt-0.5">{{ btn.sub }}</span>
                            </button>
                        </template>
                    </div>

                    <!-- MODE 2: Alphanumeric & ID typing assistant -->
                    <div v-else class="space-y-3">
                        <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/70 dark:border-indigo-500/20 text-xs space-y-2">
                            <div class="font-bold text-indigo-700 dark:text-indigo-300 flex items-center gap-1.5">
                                <span>💡</span>
                                <span>{{ __('user.calls.handle_call_title') }}</span>
                            </div>
                            <p class="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                {{ __('user.calls.handle_call_desc') }}
                            </p>
                            <!-- Quick Prefix Chips -->
                            <div class="flex items-center gap-1.5 pt-1">
                                <span class="text-[10px] text-slate-400">{{ __('user.calls.shortcuts_label') }}</span>
                                <button
                                    type="button"
                                    @click="ensureAtPrefix"
                                    class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-mono font-black text-xs hover:bg-indigo-700 transition-colors"
                                >
                                    @
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('_')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    _
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('.')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    .
                                </button>
                                <button
                                    type="button"
                                    @click="appendDigit('-')"
                                    class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 font-mono text-xs hover:bg-slate-100"
                                >
                                    -
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Noise Cancelling Quick Option in Dialer -->
                    <div class="flex items-center justify-between p-2.5 rounded-2xl bg-slate-100/70 dark:bg-slate-900/40 border border-slate-200/60 dark:border-white/5 text-xs max-w-xs mx-auto">
                        <div class="flex items-center gap-2">
                            <span class="text-base">🎧</span>
                            <div class="text-right">
                                <span class="font-bold text-slate-800 dark:text-slate-200 text-xs">{{ __('user.calls.nc_option_title') }}</span>
                                <p class="text-[10px] text-slate-400">{{ __('user.calls.nc_option_desc') }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="toggleNoiseCancellation"
                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                            :class="isNoiseCancellationEnabled ? 'bg-purple-600' : 'bg-slate-300 dark:bg-slate-700'"
                            role="switch"
                            :aria-checked="isNoiseCancellationEnabled"
                            :title="isNoiseCancellationEnabled ? __('user.calls.nc_btn_active_title_short') : __('user.calls.nc_btn_inactive_title')"
                        >
                            <span
                                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition duration-200 ease-in-out"
                                :class="isNoiseCancellationEnabled ? 'translate-x-0' : '-translate-x-4'"
                            />
                        </button>
                    </div>

                    <!-- Green Action Call Button -->
                    <div class="pt-2 flex justify-center">
                        <button
                            type="button"
                            @click="initiateCallAction"
                            :disabled="!destinationInput.trim() || !selectedLine"
                            class="w-full max-w-xs py-3.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm flex items-center justify-center gap-2 shadow-lg shadow-emerald-600/30 transition-all active:scale-98 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>{{ __('user.calls.start_call_btn') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 2: CALL HISTORY                                      -->
            <!-- ======================================================== -->
            <div v-else-if="activeTab === 'history'" class="space-y-3">
                <!-- Selected Line History Indicator -->
                <div class="glass-card !p-3 bg-white/90 dark:bg-slate-900/60 border border-slate-200/90 dark:border-white/10 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-500 dark:text-slate-400">{{ __('user.calls.history_for_line') }}</span>
                        <span class="font-mono font-black text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-lg" dir="ltr">
                            {{ selectedLine?.formatted_number }}
                        </span>
                        <span class="text-[11px] text-slate-400">({{ selectedLine?.display_title }})</span>
                    </div>
                    <span class="text-[11px] text-slate-500">
                        {{ __('user.calls.calls_count', { count: calls.total }) }}
                    </span>
                </div>

                <div v-if="calls.data && calls.data.length > 0" class="space-y-2">
                    <div
                        v-for="call in calls.data"
                        :key="call.id"
                        class="glass-card !p-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 border"
                                :class="call.direction === 'outbound'
                                    ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20'
                                    : 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20'"
                            >
                                <svg v-if="call.direction === 'outbound'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </div>
                            <div>
                                <div class="font-bold text-slate-800 dark:text-slate-200 flex items-center gap-1.5" dir="ltr">
                                    <span class="font-mono font-black">{{ call.destination }}</span>
                                    <span class="text-[10px] text-slate-400">{{ __('user.calls.from_line', { line: call.line_number }) }}</span>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5">
                                    {{ call.started_at || __('user.calls.unknown_time') }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <div class="text-left font-mono">
                                <div class="font-bold text-slate-800 dark:text-slate-200">{{ call.duration_formatted }}</div>
                                <div class="text-[10px] text-emerald-600 dark:text-emerald-400">{{ call.cost }} {{ currency }}</div>
                            </div>

                            <button
                                type="button"
                                @click="dialNumber(call.destination)"
                                class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-indigo-600 dark:text-indigo-400 font-bold text-[11px] flex items-center gap-1 transition-colors"
                            >
                                <span>{{ __('user.calls.call_again_btn') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="calls.links && calls.links.length > 3" class="flex justify-center gap-1 pt-3">
                        <button
                            v-for="(link, i) in calls.links"
                            :key="i"
                            type="button"
                            @click="link.url && router.visit(link.url)"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
                            :class="[
                                link.active ? 'bg-indigo-600 text-white shadow-md' : 'glass-pill text-slate-600 dark:text-slate-400',
                                !link.url ? 'opacity-40 cursor-not-allowed pointer-events-none' : ''
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>

                <div v-else class="glass-card !p-8 text-center space-y-2 bg-white/90 dark:bg-slate-900/60 border-slate-200/90 dark:border-white/10">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-500/15 text-indigo-600 mx-auto flex items-center justify-center text-lg">📞</div>
                    <h3 class="font-bold text-sm text-slate-900 dark:text-white">{{ __('user.calls.no_calls_title') }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('user.calls.no_calls_desc') }}</p>
                </div>
            </div>
        </div>

        <!-- Contact Picker Modal -->
        <ContactPickerModal
            :show="isContactPickerOpen"
            :initial-contacts="contacts"
            @close="isContactPickerOpen = false"
            @select="handleContactSelected"
        />

        <!-- Incoming Call Modal Notification -->
        <div
            v-if="isIncomingCallModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md animate-in fade-in duration-200"
        >
            <div class="glass-card !p-6 max-w-sm w-full text-center space-y-5 bg-white dark:bg-slate-900 border border-emerald-500/50 rounded-3xl shadow-2xl">
                <div class="relative w-20 h-20 mx-auto flex items-center justify-center">
                    <span class="absolute inset-0 rounded-full bg-emerald-500/30 animate-ping"></span>
                    <div class="w-16 h-16 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/40">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                </div>

                <div class="space-y-1">
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">{{ __('user.calls.incoming_call_title') }}</span>
                    <div class="text-xl font-black font-mono text-slate-900 dark:text-white" dir="ltr">
                        {{ incomingCall?.source }}
                    </div>
                    <div class="text-xs text-slate-400">
                        {{ __('user.calls.to_destination', { destination: incomingCall?.destination || '' }) }}
                    </div>
                </div>

                <div class="flex items-center justify-center gap-3 pt-2">
                    <!-- Decline button -->
                    <button
                        type="button"
                        @click="declineIncomingCall"
                        class="flex-1 py-3 px-4 rounded-2xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-rose-600/30 transition-transform active:scale-95"
                    >
                        <svg class="w-4 h-4 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>{{ __('user.calls.decline_btn') }}</span>
                    </button>

                    <!-- Answer button -->
                    <button
                        type="button"
                        @click="answerIncomingCall"
                        class="flex-1 py-3 px-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-600/30 transition-transform active:scale-95"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>{{ __('user.calls.answer_btn') }}</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Microphone Access & Permission Helper Modal -->
        <div
            v-if="isMicModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-3 sm:p-4 bg-slate-950/70 dark:bg-black/80 backdrop-blur-md"
            @click.self="isMicModalOpen = false"
        >
            <div class="glass-card w-full max-w-sm p-6 bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-3xl space-y-4 shadow-2xl text-center">
                <div class="w-14 h-14 rounded-2xl bg-amber-500/15 text-amber-500 mx-auto flex items-center justify-center">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                    </svg>
                </div>

                <div class="space-y-1.5">
                    <h3 class="text-sm font-black text-slate-900 dark:text-white">{{ __('user.calls.mic_modal_title') }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 font-medium leading-relaxed">
                        {{ __('user.calls.mic_modal_desc_before') }} <strong>{{ __('user.calls.mic_modal_allow_label') }}</strong> {{ __('user.calls.mic_modal_desc_after') }}
                    </p>
                </div>

                <div class="space-y-2 pt-2">
                    <button
                        type="button"
                        @click="retryMicrophoneAndCall"
                        class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition-colors shadow-md shadow-emerald-600/20"
                    >
                        {{ __('user.calls.mic_modal_confirm_btn') }}
                    </button>

                    <button
                        type="button"
                        @click="isMicModalOpen = false"
                        class="w-full py-2 text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                    >
                        {{ __('user.calls.cancel') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Offscreen WebRTC Remote Audio Player (rendered in DOM to prevent browser autoplay throttling) -->
        <audio ref="remoteAudio" autoplay playsinline class="fixed -top-96 -left-96 w-1 h-1 opacity-0 pointer-events-none"></audio>
    </UserLayout>
</template>

