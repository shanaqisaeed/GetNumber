<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { __ } from '@/Composables/useTrans';

interface ParticipantItem {
    id: number;
    user_id: number;
    is_self: boolean;
    phone_number: string;
    display_name: string;
    status: string;
    role: string;
    is_muted: boolean;
    is_camera_on: boolean;
    can_share_screen: boolean;
    is_sharing_screen: boolean;
    hand_raised: boolean;
    hand_raised_at: string | null;
}

interface MessageItem {
    id: number;
    sender_id: number;
    sender_name: string;
    is_self: boolean;
    is_private: boolean;
    recipient_name?: string;
    message: string;
    created_at: string;
}

interface PollOption {
    index: number;
    label: string;
    votes: number;
    percentage: number;
}

interface PollData {
    id: number;
    question: string;
    options: string[];
    has_voted: boolean;
    user_voted_index?: number;
    results: {
        total_votes: number;
        options: PollOption[];
    };
}

interface WhiteboardStroke {
    x0: number;
    y0: number;
    x1: number;
    y1: number;
    color: string;
    size: number;
    userId: number;
    phone: string;
    label: string;
}

interface RemoteCursor {
    x: number;
    y: number;
    color: string;
    label: string;
    updatedAt: number;
}

const props = defineProps<{
    room: {
        id: number;
        uuid: string;
        title: string;
        is_host: boolean;
        host_user_id: number;
        admission_policy: string;
        initial_audio_state?: string;
        webinar_mode: boolean;
        screen_share_policy: string;
        mute_others_on_share: boolean;
        allow_whiteboard: boolean;
        watermark_enabled?: boolean;
        is_locked: boolean;
        max_participants: number;
    };
    currentParticipant: {
        id: number;
        user_id: number;
        role: string;
        phone_number: string;
        display_name: string;
        is_muted: boolean;
        is_camera_on: boolean;
        can_share_screen: boolean;
        is_sharing_screen: boolean;
        hand_raised: boolean;
    };
    rtcConfig: {
        iceServers: RTCIceServer[];
        iceTransportPolicy?: RTCIceTransportPolicy;
    };
    sfuConfig: {
        enabled: boolean;
        wsUrl: string;
        wsPort?: number;
        wsPath?: string;
        minPort?: number;
        maxPort?: number;
    };
}>();

// Room reactive policies
const roomState = ref({
    admission_policy: props.room.admission_policy,
    initial_audio_state: props.room.initial_audio_state || 'open',
    screen_share_policy: props.room.screen_share_policy,
    mute_others_on_share: props.room.mute_others_on_share,
    webinar_mode: props.room.webinar_mode,
    allow_whiteboard: props.room.allow_whiteboard,
    watermark_enabled: Boolean(props.room.watermark_enabled),
    is_locked: props.room.is_locked,
});
const isSettingsModalOpen = ref(false);
const settingsForm = ref({ ...roomState.value });
const isRoomLocked = ref(props.room.is_locked);
const isWebinarMode = ref(props.room.webinar_mode);
const canShareScreen = ref(props.currentParticipant.can_share_screen);
const topologyMode = ref<'p2p' | 'sfu'>('p2p');

// Local media states
const localStream = ref<MediaStream | null>(null);
const localVideoRef = ref<HTMLVideoElement | null>(null);
const screenStream = ref<MediaStream | null>(null);
const localScreenVideoRef = ref<HTMLVideoElement | null>(null);
const isMuted = ref(props.currentParticipant.is_muted);
const isCameraOn = ref(props.currentParticipant.is_camera_on === true);
const isSharingScreen = ref(props.currentParticipant.is_sharing_screen);
const handRaised = ref(props.currentParticipant.hand_raised);

// Participants, chat, poll states
const participants = ref<ParticipantItem[]>([]);
const messages = ref<MessageItem[]>([]);
const activePoll = ref<PollData | null>(null);
const latestSignalSeq = ref(0);

// Computed participant groups
const admittedParticipants = computed(() =>
    participants.value.filter((p) => p.status === 'admitted')
);
const remoteAdmittedParticipants = computed(() =>
    admittedParticipants.value.filter((p) => !p.is_self)
);
const waitingParticipants = computed(() =>
    participants.value.filter((p) => p.status === 'waiting_approval')
);
const speakingQueueParticipants = computed(() =>
    admittedParticipants.value
        .filter((p) => p.hand_raised)
        .sort((a, b) => (a.hand_raised_at || '').localeCompare(b.hand_raised_at || ''))
);
const activePresenter = computed(() =>
    admittedParticipants.value.find((p) => p.is_sharing_screen) || null
);

// UI Panel toggles
const activeSideDrawer = ref<'participants' | 'chat' | 'polls' | null>(null);
const isWhiteboardOpen = ref(false);
const isPollCreateModalOpen = ref(false);
const activeSpeakerId = ref<number | null>(null);
const toastMessage = ref<string | null>(null);

// Screen Share Pinch-to-Zoom & Pan states (Mobile & Desktop)
const zoomScale = ref(1);
const panX = ref(0);
const panY = ref(0);
let initialPinchDistance = 0;
let initialZoomScale = 1;
let isPanning = false;
let panStartX = 0;
let panStartY = 0;

// In-meeting chat form
const chatInput = ref('');
const chatRecipientMode = ref<'everyone' | 'host' | number>('everyone');

// In-meeting poll creation form
const newPollQuestion = ref('');
const newPollOptions = ref(['', '']);

// Whiteboard states (16:9 locked aspect ratio with user ID + phone attribution)
const canvasRef = ref<HTMLCanvasElement | null>(null);
const isDrawing = ref(false);
const lastDrawPoint = ref<{ x: number; y: number } | null>(null);
const currentColor = ref('#4f46e5');
const currentBrushSize = ref(4);
const strokeHistory = ref<WhiteboardStroke[]>([]);
const remoteCursors = reactive<Record<number, RemoteCursor>>({});

const localIdentityLabel = computed(
    () => `${__('conference.common.user')} #${props.currentParticipant.user_id} (${props.currentParticipant.phone_number})`
);

// WebRTC Multi-Peer Connection Registry
const peerConnections = new Map<number, RTCPeerConnection>();
const remoteStreams = reactive<Record<number, MediaStream>>({});
const remoteVideoRefs = new Map<number, HTMLVideoElement>();
const makingOfferMap = new Map<number, boolean>();
const processedSignalKeys = new Set<string>();

// WebSocket & Audio Analyzer handles
let sfuSocket: WebSocket | null = null;
let pollTimer: any = null;
let speakerDetectInterval: any = null;
let audioCtx: AudioContext | null = null;
const peerAnalysers = new Map<number, AnalyserNode>();

function showToast(msg: string) {
    toastMessage.value = msg;
    setTimeout(() => {
        if (toastMessage.value === msg) {
            toastMessage.value = null;
        }
    }, 3800);
}

async function bindLocalVideoStream() {
    await nextTick();
    if (localVideoRef.value) {
        if (isCameraOn.value && localStream.value && localStream.value.getVideoTracks().length > 0) {
            if (localVideoRef.value.srcObject !== localStream.value) {
                localVideoRef.value.srcObject = localStream.value;
            }
            try {
                await localVideoRef.value.play();
            } catch {}
        } else {
            localVideoRef.value.srcObject = null;
        }
    }
}

watch(isCameraOn, async (on) => {
    await bindLocalVideoStream();
});

// 1. Initialize Local Media with Independent Audio/Video Fallbacks & Immediate Video Playback
async function initLocalMedia() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        isCameraOn.value = false;
        showToast(__('conference.controls.media_permission_error'));
        return;
    }

    const canBroadcastVideo = !(isWebinarMode.value && !props.room.is_host && !canShareScreen.value);
    const shouldStartWithVideo = isCameraOn.value && canBroadcastVideo;
    let stream: MediaStream | null = null;

    if (shouldStartWithVideo) {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
        } catch {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                });
            } catch {}
        }
    } else {
        // DO NOT request camera video at all! Hardware webcam and LED will NOT turn on!
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
        } catch {}
    }

    if (!stream) {
        isCameraOn.value = false;
        showToast(__('conference.controls.media_permission_error'));
        return;
    }

    localStream.value = stream;

    const audioTrack = stream.getAudioTracks()[0];
    if (audioTrack) {
        audioTrack.enabled = !isMuted.value;
    } else {
        isMuted.value = true;
    }

    const videoTrack = stream.getVideoTracks()[0];
    if (videoTrack && shouldStartWithVideo) {
        videoTrack.enabled = true;
        isCameraOn.value = true;
    } else {
        isCameraOn.value = false;
    }

    await bindLocalVideoStream();
    setupAudioAnalyser(props.currentParticipant.id, stream);

    // Sync actual initial media state with backend
    axios
        .post(route('org.conference.media_state', { uuid: props.room.uuid }), {
            is_muted: isMuted.value,
            is_camera_on: isCameraOn.value,
        })
        .catch(() => {});
}

// 2. Web Audio API Active Speaker Detection
function setupAudioAnalyser(participantId: number, stream: MediaStream) {
    try {
        if (stream.getAudioTracks().length === 0) return;
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => {});
        }
        const source = audioCtx.createMediaStreamSource(stream);
        const analyser = audioCtx.createAnalyser();
        analyser.fftSize = 64;
        source.connect(analyser);
        peerAnalysers.set(participantId, analyser);
    } catch {
        // Ignore audio context restriction until user gesture
    }
}

function detectActiveSpeaker() {
    let maxVolume = 18; // noise floor threshold
    let loudestId: number | null = null;

    peerAnalysers.forEach((analyser, partId) => {
        const p = admittedParticipants.value.find((item) => item.id === partId);
        if (!p || p.is_muted) return;

        const data = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) {
            sum += data[i];
        }
        const avg = sum / data.length;
        if (avg > maxVolume) {
            maxVolume = avg;
            loudestId = partId;
        }
    });

    activeSpeakerId.value = loudestId;
}

// 3. WebRTC PeerConnection Manager (Smart Hybrid P2P / TURN Relay)
function setRemoteVideoRef(participantId: number, el: any) {
    if (el instanceof HTMLVideoElement) {
        remoteVideoRefs.set(participantId, el);
        const stream = remoteStreams[participantId];
        if (stream && el.srcObject !== stream) {
            el.srcObject = stream;
            el.play().catch(() => {});
        }
    } else {
        remoteVideoRefs.delete(participantId);
    }
}

function getOrCreatePeerConnection(remoteParticipantId: number): RTCPeerConnection {
    const existing = peerConnections.get(remoteParticipantId);
    if (existing && existing.connectionState !== 'closed') {
        return existing;
    }

    const pc = new RTCPeerConnection({
        iceServers: props.rtcConfig?.iceServers || [],
        iceTransportPolicy: props.rtcConfig?.iceTransportPolicy || 'all',
    });

    // Attach local audio & active video (screen share or camera) tracks
    if (localStream.value) {
        const audioTrack = localStream.value.getAudioTracks()[0];
        if (audioTrack) {
            pc.addTrack(audioTrack, localStream.value);
        }
    }

    const activeVideoTrack = isSharingScreen.value && screenStream.value
        ? screenStream.value.getVideoTracks()[0]
        : localStream.value?.getVideoTracks()[0];

    if (activeVideoTrack && localStream.value) {
        pc.addTrack(activeVideoTrack, localStream.value);
    }

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            dispatchPeerSignal('candidate', remoteParticipantId, {
                candidate: event.candidate.toJSON(),
            });
        }
    };

    pc.ontrack = (event) => {
        let stream = remoteStreams[remoteParticipantId];
        if (!stream) {
            stream = new MediaStream();
            remoteStreams[remoteParticipantId] = stream;
        }
        if (!stream.getTracks().some((t) => t.id === event.track.id)) {
            stream.addTrack(event.track);
        }

        const videoEl = remoteVideoRefs.get(remoteParticipantId);
        if (videoEl) {
            if (videoEl.srcObject !== stream) {
                videoEl.srcObject = stream;
            }
            videoEl.play().catch(() => {});
        }

        if (event.track.kind === 'audio') {
            setupAudioAnalyser(remoteParticipantId, stream);
        }
    };

    pc.onnegotiationneeded = async () => {
        // Deterministic initiator: participant with smaller ID initiates to avoid offer glare
        if (props.currentParticipant.id < remoteParticipantId) {
            await initiateOfferToPeer(remoteParticipantId, pc);
        }
    };

    peerConnections.set(remoteParticipantId, pc);
    return pc;
}

async function initiateOfferToPeer(remoteParticipantId: number, pc?: RTCPeerConnection) {
    const conn = pc || getOrCreatePeerConnection(remoteParticipantId);
    if (makingOfferMap.get(remoteParticipantId) || conn.signalingState !== 'stable') {
        return;
    }

    try {
        makingOfferMap.set(remoteParticipantId, true);
        const offer = await conn.createOffer({
            offerToReceiveAudio: true,
            offerToReceiveVideo: true,
        });
        await conn.setLocalDescription(offer);
        dispatchPeerSignal('offer', remoteParticipantId, {
            sdp: conn.localDescription,
        });
    } catch (e) {
        console.warn('Offer creation error:', e);
    } finally {
        makingOfferMap.set(remoteParticipantId, false);
    }
}

async function handleIncomingRtcSignal(type: string, senderParticipantId: number, payload: any) {
    if (!senderParticipantId || senderParticipantId === props.currentParticipant.id) return;

    const sigKey = `${type}:${senderParticipantId}:${JSON.stringify(payload).slice(0, 120)}`;
    if (processedSignalKeys.has(sigKey)) return;
    processedSignalKeys.add(sigKey);
    if (processedSignalKeys.size > 400) {
        const firstKey = processedSignalKeys.values().next().value;
        if (firstKey) processedSignalKeys.delete(firstKey);
    }

    const pc = getOrCreatePeerConnection(senderParticipantId);

    try {
        if (type === 'offer' && payload?.sdp) {
            const polite = props.currentParticipant.id > senderParticipantId;
            const offerCollision = makingOfferMap.get(senderParticipantId) || pc.signalingState !== 'stable';
            if (offerCollision && !polite) {
                return;
            }
            await pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            dispatchPeerSignal('answer', senderParticipantId, {
                sdp: pc.localDescription,
            });
        } else if (type === 'answer' && payload?.sdp) {
            if (pc.signalingState === 'have-local-offer') {
                await pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
            }
        } else if (type === 'candidate' && payload?.candidate) {
            if (pc.remoteDescription) {
                await pc.addIceCandidate(new RTCIceCandidate(payload.candidate));
            }
        }
    } catch (e) {
        console.warn('WebRTC signaling handling warning:', e);
    }
}

function syncPeerConnectionsWithRoster() {
    const activeRemoteIds = new Set(remoteAdmittedParticipants.value.map((p) => p.id));

    // Update Smart Hybrid topology indicator (1-2 participants = P2P, 3+ = SFU)
    topologyMode.value = admittedParticipants.value.length >= 3 ? 'sfu' : 'p2p';

    // Close connections for peers who left
    Array.from(peerConnections.keys()).forEach((peerId) => {
        if (!activeRemoteIds.has(peerId)) {
            const pc = peerConnections.get(peerId);
            if (pc) pc.close();
            peerConnections.delete(peerId);
            delete remoteStreams[peerId];
            peerAnalysers.delete(peerId);
        }
    });

    // Establish connections with admitted peers
    remoteAdmittedParticipants.value.forEach((p) => {
        if (!peerConnections.has(p.id)) {
            const pc = getOrCreatePeerConnection(p.id);
            if (props.currentParticipant.id < p.id) {
                initiateOfferToPeer(p.id, pc);
            }
        }
    });
}

async function replaceOutgoingVideoTrack(newTrack: MediaStreamTrack | null) {
    for (const [peerId, pc] of peerConnections.entries()) {
        const videoSender = pc.getSenders().find((s) => s.track?.kind === 'video');
        if (videoSender) {
            try {
                await videoSender.replaceTrack(newTrack);
            } catch {}
        } else if (newTrack && localStream.value) {
            pc.addTrack(newTrack, localStream.value);
            await initiateOfferToPeer(peerId, pc);
        }
    }
}

// 4. Dual-Path Signal Dispatcher (Go /ws/sfu WebSocket + HTTP Cache Queue Fallback)
function dispatchPeerSignal(type: string, targetParticipantId: number | null, payload: any) {
    // Path 1: Fast Go SFU WebSocket
    if (sfuSocket && sfuSocket.readyState === WebSocket.OPEN) {
        sfuSocket.send(
            JSON.stringify({
                type,
                room_uuid: props.room.uuid,
                participant_id: String(props.currentParticipant.id),
                user_id: props.currentParticipant.user_id,
                phone_number: props.currentParticipant.phone_number,
                display_name: props.currentParticipant.display_name,
                role: props.currentParticipant.role,
                target_id: targetParticipantId ? String(targetParticipantId) : '',
                payload,
            })
        );
    }

    // Path 2: Resilient HTTP/Cache Signaling Bus
    axios
        .post(route('org.conference.signal', { uuid: props.room.uuid }), {
            type,
            target_participant_id: targetParticipantId,
            payload,
        })
        .catch(() => {});
}

// 5. Toggle Audio / Mute
async function toggleAudio() {
    if (!localStream.value || localStream.value.getAudioTracks().length === 0) {
        try {
            const aStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
            const aTrack = aStream.getAudioTracks()[0];
            if (aTrack) {
                if (!localStream.value) {
                    localStream.value = new MediaStream([aTrack]);
                } else {
                    localStream.value.addTrack(aTrack);
                }
                setupAudioAnalyser(props.currentParticipant.id, localStream.value);
            }
        } catch {
            showToast(__('conference.controls.media_permission_error'));
            return;
        }
    }

    isMuted.value = !isMuted.value;
    if (localStream.value) {
        const audioTrack = localStream.value.getAudioTracks()[0];
        if (audioTrack) audioTrack.enabled = !isMuted.value;
    }
    await axios.post(route('org.conference.media_state', { uuid: props.room.uuid }), {
        is_muted: isMuted.value,
    });
    sendSFUMessage('media_state', { is_muted: isMuted.value });
}

// 6. Toggle Camera (With Lazy Acquisition & Playback Guarantee)
async function toggleVideo() {
    if (isWebinarMode.value && !props.room.is_host && !canShareScreen.value) {
        showToast(__('conference.room.webinar_mode_desc'));
        return;
    }

    const targetState = !isCameraOn.value;

    if (targetState) {
        let videoTrack = localStream.value?.getVideoTracks()[0];
        if (!videoTrack || videoTrack.readyState !== 'live') {
            try {
                const vStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                });
                videoTrack = vStream.getVideoTracks()[0];
                if (videoTrack) {
                    if (!localStream.value) {
                        localStream.value = new MediaStream([videoTrack]);
                    } else {
                        localStream.value.getVideoTracks().forEach((old) => {
                            old.stop();
                            localStream.value?.removeTrack(old);
                        });
                        localStream.value.addTrack(videoTrack);
                    }
                }
            } catch {
                showToast(__('conference.controls.media_permission_error'));
                return;
            }
        }

        if (videoTrack) {
            videoTrack.enabled = true;
            isCameraOn.value = true;
            await bindLocalVideoStream();
            if (!isSharingScreen.value) {
                await replaceOutgoingVideoTrack(videoTrack);
            }
        }
    } else {
        isCameraOn.value = false;
        if (localStream.value) {
            const videoTracks = localStream.value.getVideoTracks();
            videoTracks.forEach((t) => {
                t.stop();
                localStream.value?.removeTrack(t);
            });
            if (!isSharingScreen.value) {
                await replaceOutgoingVideoTrack(null);
            }
        }
        await bindLocalVideoStream();
    }

    await axios.post(route('org.conference.media_state', { uuid: props.room.uuid }), {
        is_camera_on: isCameraOn.value,
    });
    sendSFUMessage('media_state', { is_camera_on: isCameraOn.value });
}

// 7. Toggle Screen Sharing (Condition Check, Permission Gating & Auto-Mute Others)
async function toggleScreenShare() {
    if (isSharingScreen.value) {
        await stopScreenShare();
        return;
    }

    if (!canShareScreen.value && !props.room.is_host) {
        showToast(__('conference.controls.screen_share_denied'));
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getDisplayMedia({
            video: { cursor: 'always' } as any,
            audio: true,
        });

        const resp = await axios.post(route('org.conference.start_screen_share', { uuid: props.room.uuid }));
        if (!resp.data.success) {
            stream.getTracks().forEach((t) => t.stop());
            showToast(resp.data.message || __('conference.controls.screen_share_denied'));
            return;
        }

        screenStream.value = stream;
        isSharingScreen.value = true;
        resetZoom();

        nextTick(() => {
            if (localScreenVideoRef.value) {
                localScreenVideoRef.value.srcObject = stream;
                localScreenVideoRef.value.play().catch(() => {});
            }
        });

        const screenTrack = stream.getVideoTracks()[0];
        if (screenTrack) {
            screenTrack.onended = () => {
                stopScreenShare();
            };
            await replaceOutgoingVideoTrack(screenTrack);
        }

        sendSFUMessage('screen_share_start', {});
        if (props.room.mute_others_on_share) {
            showToast(__('conference.room.mute_others_on_share_desc'));
        }
        fetchState();
    } catch (err: any) {
        if (err?.response?.data?.message) {
            showToast(err.response.data.message);
        }
    }
}

async function stopScreenShare() {
    if (screenStream.value) {
        screenStream.value.getTracks().forEach((track) => track.stop());
        screenStream.value = null;
    }
    isSharingScreen.value = false;
    resetZoom();

    const camTrack = isCameraOn.value && localStream.value ? localStream.value.getVideoTracks()[0] : null;
    await replaceOutgoingVideoTrack(camTrack || null);

    await axios.post(route('org.conference.stop_screen_share', { uuid: props.room.uuid }));
    sendSFUMessage('screen_share_stop', {});
    fetchState();
}

// 8. Mobile Pinch-to-Zoom & Pan Handlers for Screen Sharing Viewer
function resetZoom() {
    zoomScale.value = 1;
    panX.value = 0;
    panY.value = 0;
}

function onPresenterWheel(e: WheelEvent) {
    const delta = e.deltaY < 0 ? 0.2 : -0.2;
    const next = Math.min(4, Math.max(1, Number((zoomScale.value + delta).toFixed(2))));
    zoomScale.value = next;
    if (next === 1) {
        panX.value = 0;
        panY.value = 0;
    }
}

function getTouchDistance(touches: TouchList): number {
    const dx = touches[0].clientX - touches[1].clientX;
    const dy = touches[0].clientY - touches[1].clientY;
    return Math.hypot(dx, dy);
}

function onPresenterTouchStart(e: TouchEvent) {
    if (e.touches.length === 2) {
        initialPinchDistance = getTouchDistance(e.touches);
        initialZoomScale = zoomScale.value;
    } else if (e.touches.length === 1 && zoomScale.value > 1) {
        isPanning = true;
        panStartX = e.touches[0].clientX - panX.value;
        panStartY = e.touches[0].clientY - panY.value;
    }
}

function onPresenterTouchMove(e: TouchEvent) {
    if (e.touches.length === 2 && initialPinchDistance > 0) {
        const currentDist = getTouchDistance(e.touches);
        const ratio = currentDist / initialPinchDistance;
        zoomScale.value = Math.min(4, Math.max(1, Number((initialZoomScale * ratio).toFixed(2))));
        if (zoomScale.value === 1) {
            panX.value = 0;
            panY.value = 0;
        }
    } else if (e.touches.length === 1 && isPanning && zoomScale.value > 1) {
        panX.value = e.touches[0].clientX - panStartX;
        panY.value = e.touches[0].clientY - panStartY;
    }
}

function onPresenterTouchEnd() {
    initialPinchDistance = 0;
    isPanning = false;
}

// 9. Raise Hand & Speaking Queue
async function toggleRaiseHand() {
    handRaised.value = !handRaised.value;
    await axios.post(route('org.conference.raise_hand', { uuid: props.room.uuid }));
    sendSFUMessage(handRaised.value ? 'raise_hand' : 'lower_hand', {});
    fetchState();
}

// 10. Host Actions: Admit, Reject, Kick, Mute, Mute All, Grant Screen Share, Lock Room, Webinar Mode
async function admitWaitingParticipant(p: ParticipantItem) {
    await axios.post(route('org.conference.admit', { uuid: props.room.uuid, participant: p.id }));
    sendSFUMessage('admit_participant', { target_id: String(p.id) });
    fetchState();
}

async function rejectWaitingParticipant(p: ParticipantItem) {
    await axios.post(route('org.conference.reject', { uuid: props.room.uuid, participant: p.id }));
    sendSFUMessage('reject_participant', { target_id: String(p.id) });
    fetchState();
}

async function toggleParticipantMute(p: ParticipantItem) {
    await axios.post(route('org.conference.mute', { uuid: props.room.uuid, participant: p.id }), {
        is_muted: !p.is_muted,
    });
    fetchState();
}

async function toggleParticipantScreenShare(p: ParticipantItem) {
    const nextShare = !p.can_share_screen;
    await axios.post(route('org.conference.grant_screen_share', { uuid: props.room.uuid, participant: p.id }), {
        can_share: nextShare,
    });
    sendSFUMessage('grant_screen_share', {
        target_id: String(p.id),
        can_share: nextShare,
    });
    fetchState();
}

async function kickParticipant(p: ParticipantItem) {
    await axios.post(route('org.conference.kick', { uuid: props.room.uuid, participant: p.id }));
    if (sfuSocket && sfuSocket.readyState === WebSocket.OPEN) {
        sfuSocket.send(
            JSON.stringify({
                type: 'kick',
                room_uuid: props.room.uuid,
                target_id: String(p.id),
            })
        );
    }
    fetchState();
}

async function muteAll() {
    await axios.post(route('org.conference.mute_all', { uuid: props.room.uuid }));
    sendSFUMessage('mute_all', {});
    showToast(__('conference.controls.all_muted_toast'));
    fetchState();
}

async function toggleRoomLock() {
    const resp = await axios.post(route('org.conference.lock', { uuid: props.room.uuid }));
    if (resp.data.success) {
        isRoomLocked.value = resp.data.is_locked;
        sendSFUMessage('room_policy_update', { is_locked: isRoomLocked.value });
        showToast(resp.data.message);
    }
}

async function toggleWebinarMode() {
    const resp = await axios.post(route('org.conference.webinar', { uuid: props.room.uuid }));
    if (resp.data.success) {
        isWebinarMode.value = resp.data.webinar_mode;
        sendSFUMessage('room_policy_update', { webinar_mode: isWebinarMode.value });
        showToast(resp.data.message);
        fetchState();
    }
}

// 11. In-Meeting Chat (Public & Private to Host)
async function sendChatMessage() {
    const text = chatInput.value.trim();
    if (!text) return;

    let toUserId: number | null = null;
    if (chatRecipientMode.value === 'host') {
        toUserId = props.room.host_user_id;
    } else if (typeof chatRecipientMode.value === 'number') {
        toUserId = chatRecipientMode.value;
    }

    try {
        const resp = await axios.post(route('org.conference.messages.store', { uuid: props.room.uuid }), {
            message: text,
            to_user_id: toUserId,
        });

        if (resp.data.success) {
            chatInput.value = '';
            if (resp.data.message) {
                const m = resp.data.message;
                messages.value.push({
                    id: m.id,
                    sender_id: props.currentParticipant.user_id,
                    sender_name: props.currentParticipant.display_name,
                    is_self: true,
                    is_private: Boolean(toUserId),
                    recipient_name: toUserId ? (chatRecipientMode.value === 'host' ? __('conference.common.host') : '') : undefined,
                    message: text,
                    created_at: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                });
            }
            fetchState();
            sendSFUMessage('chat_message', {
                target_user_id: toUserId,
                text,
            });
        }
    } catch {
        // Ignore error
    }
}

// 12. Instant In-Meeting Polls (Create, Vote, Close)
function addPollOption() {
    if (newPollOptions.value.length < 6) {
        newPollOptions.value.push('');
    }
}

function removePollOption(idx: number) {
    if (newPollOptions.value.length > 2) {
        newPollOptions.value.splice(idx, 1);
    }
}

async function submitCreatePoll() {
    const question = newPollQuestion.value.trim();
    const options = newPollOptions.value.map((o) => o.trim()).filter((o) => o !== '');

    if (!question || options.length < 2) {
        showToast(__('conference.polls.validation_error'));
        return;
    }

    try {
        const resp = await axios.post(route('org.conference.polls.store', { uuid: props.room.uuid }), {
            question,
            options,
        });

        if (resp.data.success) {
            isPollCreateModalOpen.value = false;
            newPollQuestion.value = '';
            newPollOptions.value = ['', ''];
            fetchState();
            sendSFUMessage('poll_created', {});
            showToast(__('conference.polls.published_toast'));
        }
    } catch {
        showToast(__('conference.polls.validation_error'));
    }
}

async function closeActivePoll() {
    if (!activePoll.value) return;
    await axios.post(route('org.conference.polls.close', { uuid: props.room.uuid, poll: activePoll.value.id }));
    sendSFUMessage('poll_closed', {});
    showToast(__('conference.polls.closed_toast'));
    fetchState();
}

async function submitVote(optionIndex: number) {
    if (!activePoll.value || activePoll.value.has_voted) return;

    try {
        const resp = await axios.post(
            route('org.conference.polls.vote', { uuid: props.room.uuid, poll: activePoll.value.id }),
            { option_index: optionIndex }
        );

        if (resp.data.success) {
            activePoll.value.has_voted = true;
            activePoll.value.user_voted_index = optionIndex;
            activePoll.value.results = resp.data.results;
            sendSFUMessage('poll_voted', {});
            showToast(__('conference.polls.vote_success'));
        }
    } catch (err: any) {
        showToast(err.response?.data?.message || __('conference.polls.already_voted'));
    }
}

// 13. Multi-User 16:9 Whiteboard with User ID & Phone Number Attribution
function initWhiteboard() {
    nextTick(() => {
        if (!canvasRef.value) return;
        const canvas = canvasRef.value;
        canvas.width = 1920;
        canvas.height = 1080;
        redrawWhiteboardCanvas();
    });
}

function redrawWhiteboardCanvas() {
    if (!canvasRef.value) return;
    const canvas = canvasRef.value;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.clearRect(0, 0, 1920, 1080);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    const latestStrokePerUser = new Map<number, WhiteboardStroke>();

    for (const s of strokeHistory.value) {
        ctx.beginPath();
        ctx.strokeStyle = s.color || '#4f46e5';
        ctx.lineWidth = s.size || 4;
        ctx.moveTo(s.x0 * 1920, s.y0 * 1080);
        ctx.lineTo(s.x1 * 1920, s.y1 * 1080);
        ctx.stroke();
        if (s.userId) {
            latestStrokePerUser.set(s.userId, s);
        }
    }

    // Render verified user ID + phone number attribution badge at the end of each user's stroke
    latestStrokePerUser.forEach((s) => {
        if (!s.label) return;
        const px = Math.min(1820, Math.max(20, s.x1 * 1920 + 10));
        const py = Math.min(1050, Math.max(30, s.y1 * 1080 - 10));
        ctx.save();
        ctx.font = 'bold 20px Vazirmatn, sans-serif';
        const textWidth = ctx.measureText(s.label).width;
        ctx.fillStyle = s.color || '#4f46e5';
        ctx.beginPath();
        ctx.roundRect(px - 8, py - 24, textWidth + 16, 32, 8);
        ctx.fill();
        ctx.fillStyle = '#ffffff';
        ctx.fillText(s.label, px, py);
        ctx.restore();
    });
}

function renderStrokeSegment(stroke: WhiteboardStroke, record = true) {
    if (record) {
        strokeHistory.value.push(stroke);
    }
    redrawWhiteboardCanvas();
}

function startDraw(e: MouseEvent | TouchEvent) {
    if (!canvasRef.value) return;
    isDrawing.value = true;
    lastDrawPoint.value = getNormalizedCanvasPos(e);
}

function drawMove(e: MouseEvent | TouchEvent) {
    if (!canvasRef.value) return;
    const pos = getNormalizedCanvasPos(e);

    if (!isDrawing.value || !lastDrawPoint.value) {
        return;
    }

    const stroke: WhiteboardStroke = {
        x0: lastDrawPoint.value.x,
        y0: lastDrawPoint.value.y,
        x1: pos.x,
        y1: pos.y,
        color: currentColor.value,
        size: currentBrushSize.value,
        userId: props.currentParticipant.user_id,
        phone: props.currentParticipant.phone_number,
        label: localIdentityLabel.value,
    };

    lastDrawPoint.value = pos;
    renderStrokeSegment(stroke, true);
    dispatchPeerSignal('whiteboard_draw', null, stroke);
}

function stopDraw() {
    isDrawing.value = false;
    lastDrawPoint.value = null;
}

function clearWhiteboard() {
    strokeHistory.value = [];
    redrawWhiteboardCanvas();
    dispatchPeerSignal('whiteboard_clear', null, {});
}

function getNormalizedCanvasPos(e: MouseEvent | TouchEvent) {
    const canvas = canvasRef.value!;
    const rect = canvas.getBoundingClientRect();
    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
    return {
        x: Math.max(0, Math.min(1, (clientX - rect.left) / rect.width)),
        y: Math.max(0, Math.min(1, (clientY - rect.top) / rect.height)),
    };
}

// 14. Go SFU WebSocket Connection
function connectSFU() {
    if (props.sfuConfig && props.sfuConfig.enabled === false) {
        return;
    }

    const wsPath = props.sfuConfig?.wsPath || '/ws/sfu';
    const wsPort = props.sfuConfig?.wsPort || 49001;
    let wsUrl = (props.sfuConfig?.wsUrl || '').trim();

    if (!wsUrl || wsUrl === wsPath) {
        if (window.location.protocol === 'https:') {
            // On HTTPS domains without an explicit wsUrl, connect via same-origin WSS reverse proxy (/ws/sfu)
            wsUrl = `wss://${window.location.host}${wsPath}`;
        } else {
            wsUrl = `ws://${window.location.hostname}:${wsPort}${wsPath}`;
        }
    } else if (window.location.protocol === 'https:' && wsUrl.startsWith('ws://')) {
        const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        if (!isLocal) {
            // Avoid Mixed-Content block on HTTPS browsers by upgrading to same-origin WSS proxy
            wsUrl = `wss://${window.location.host}${wsPath}`;
        }
    }

    try {
        sfuSocket = new WebSocket(wsUrl);

        sfuSocket.onopen = () => {
            sendSFUMessage('join', {
                room_uuid: props.room.uuid,
                participant_id: String(props.currentParticipant.id),
                user_id: props.currentParticipant.user_id,
                phone_number: props.currentParticipant.phone_number,
                display_name: props.currentParticipant.display_name,
                role: props.currentParticipant.role,
                webinar_mode: isWebinarMode.value,
            });
        };

        sfuSocket.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                handleSFUMessage(msg);
            } catch {}
        };

        sfuSocket.onclose = () => {
            setTimeout(() => {
                if (pollTimer) connectSFU();
            }, 5000);
        };
    } catch {
        // Resilient HTTP/Cache signaling fallback handles everything automatically
    }
}

function sendSFUMessage(type: string, payload: any) {
    if (sfuSocket && sfuSocket.readyState === WebSocket.OPEN) {
        sfuSocket.send(
            JSON.stringify({
                type,
                room_uuid: props.room.uuid,
                participant_id: String(props.currentParticipant.id),
                user_id: props.currentParticipant.user_id,
                phone_number: props.currentParticipant.phone_number,
                display_name: props.currentParticipant.display_name,
                role: props.currentParticipant.role,
                payload,
            })
        );
    }
}

function handleSFUMessage(msg: any) {
    if (msg.topology_mode === 'sfu' || msg.topology_mode === 'p2p') {
        topologyMode.value = msg.topology_mode;
    }

    const senderId = Number(msg.participant_id || msg.sender_participant_id || 0);

    switch (msg.type) {
        case 'joined':
        case 'peer_joined':
        case 'peer_left':
        case 'peer_media_updated':
        case 'screen_share_started':
        case 'screen_share_stopped':
        case 'screen_share_permission_updated':
        case 'room_policy_updated':
        case 'room_settings_updated':
            if (msg.payload) {
                Object.assign(roomState.value, msg.payload);
                roomState.value.watermark_enabled = Boolean(msg.payload.watermark_enabled);
                isRoomLocked.value = Boolean(msg.payload.is_locked);
                isWebinarMode.value = Boolean(msg.payload.webinar_mode);
            }
            fetchState();
            break;
        case 'offer':
        case 'answer':
        case 'candidate':
            handleIncomingRtcSignal(msg.type, senderId, msg.payload);
            break;
        case 'mute_all':
            if (!props.room.is_host) {
                isMuted.value = true;
                if (localStream.value) {
                    const audioTrack = localStream.value.getAudioTracks()[0];
                    if (audioTrack) audioTrack.enabled = false;
                }
                showToast(__('conference.controls.muted_by_host'));
            }
            fetchState();
            break;
        case 'hand_raised':
            showToast(__('conference.controls.hand_raised_alert', { name: msg.display_name || __('conference.common.participant') }));
            fetchState();
            break;
        case 'hand_lowered':
        case 'chat_message':
        case 'poll_created':
        case 'poll_voted':
        case 'poll_closed':
            fetchState();
            break;
        case 'whiteboard_sync':
            if (Array.isArray(msg.payload)) {
                strokeHistory.value = msg.payload;
                if (isWhiteboardOpen.value) {
                    redrawWhiteboardCanvas();
                }
            }
            break;
        case 'whiteboard_draw':
            if (msg.payload) {
                if (!isWhiteboardOpen.value) {
                    isWhiteboardOpen.value = true;
                    initWhiteboard();
                }
                renderStrokeSegment(msg.payload as WhiteboardStroke, true);
                if (senderId) {
                    remoteCursors[senderId] = {
                        x: msg.payload.x1,
                        y: msg.payload.y1,
                        color: msg.payload.color || '#4f46e5',
                        label: msg.payload.label || `${__('conference.common.user')} #${msg.user_id || senderId}`,
                        updatedAt: Date.now(),
                    };
                }
            }
            break;
        case 'whiteboard_clear':
            strokeHistory.value = [];
            redrawWhiteboardCanvas();
            break;
        case 'kicked':
            alert(__('conference.controls.kicked_alert'));
            window.location.href = route('org.conference.index');
            break;
    }
}

// 15. HTTP State & Fallback Signal Synchronizer
async function fetchState() {
    try {
        const resp = await axios.get(route('org.conference.state', { uuid: props.room.uuid }), {
            params: { since_seq: latestSignalSeq.value },
        });
        if (!resp.data) return;

        if (!resp.data.room?.is_active) {
            alert(__('conference.controls.room_ended_alert'));
            window.location.href = route('org.conference.index');
            return;
        }

        if (resp.data.selfStatus === 'kicked' || resp.data.selfStatus === 'rejected') {
            alert(__('conference.controls.kicked_alert'));
            window.location.href = route('org.conference.index');
            return;
        }

        if (resp.data.room) {
            isRoomLocked.value = Boolean(resp.data.room.is_locked);
            isWebinarMode.value = Boolean(resp.data.room.webinar_mode);
            Object.assign(roomState.value, resp.data.room);
            roomState.value.watermark_enabled = Boolean(resp.data.room.watermark_enabled);
        }

        participants.value = resp.data.participants || [];
        messages.value = resp.data.messages || [];
        activePoll.value = resp.data.activePoll || null;

        // Sync self state changes triggered by host (mute, screen share permission, webinar mode)
        const selfRecord = participants.value.find((p) => p.is_self);
        if (selfRecord) {
            if (selfRecord.is_muted !== isMuted.value) {
                isMuted.value = selfRecord.is_muted;
                if (localStream.value) {
                    const audioTrack = localStream.value.getAudioTracks()[0];
                    if (audioTrack) audioTrack.enabled = !isMuted.value;
                }
            }
            if (selfRecord.can_share_screen !== canShareScreen.value) {
                canShareScreen.value = selfRecord.can_share_screen;
                showToast(
                    canShareScreen.value
                        ? __('conference.controls.screen_share_granted')
                        : __('conference.controls.screen_share_revoked')
                );
            }
            if (isWebinarMode.value && !props.room.is_host && !canShareScreen.value && isCameraOn.value) {
                isCameraOn.value = false;
                if (localStream.value) {
                    const videoTracks = localStream.value.getVideoTracks();
                    videoTracks.forEach((t) => {
                        t.stop();
                        localStream.value?.removeTrack(t);
                    });
                }
                bindLocalVideoStream();
            }
        }

        // Process buffered fallback signals (WebRTC SDP + Whiteboard + Settings)
        if (typeof resp.data.latest_seq === 'number' && resp.data.latest_seq > latestSignalSeq.value) {
            latestSignalSeq.value = resp.data.latest_seq;
        }
        if (Array.isArray(resp.data.signals)) {
            for (const sig of resp.data.signals) {
                if (sig.type === 'offer' || sig.type === 'answer' || sig.type === 'candidate') {
                    await handleIncomingRtcSignal(sig.type, Number(sig.sender_participant_id), sig.payload);
                } else if (sig.type === 'room_settings_updated' && sig.payload) {
                    Object.assign(roomState.value, sig.payload);
                    roomState.value.watermark_enabled = Boolean(sig.payload.watermark_enabled);
                    isRoomLocked.value = Boolean(sig.payload.is_locked);
                    isWebinarMode.value = Boolean(sig.payload.webinar_mode);
                } else if (sig.type === 'whiteboard_draw' && sig.payload) {
                    handleSFUMessage({
                        type: 'whiteboard_draw',
                        participant_id: sig.sender_participant_id,
                        user_id: sig.sender_user_id,
                        payload: sig.payload,
                    });
                } else if (sig.type === 'whiteboard_clear') {
                    handleSFUMessage({ type: 'whiteboard_clear' });
                }
            }
        }

        syncPeerConnectionsWithRoster();
    } catch {
        // Resilient to transient network hiccups
    }
}

// 16. Host Room Settings Management
function openSettingsModal() {
    settingsForm.value = { ...roomState.value };
    isSettingsModalOpen.value = true;
}

async function saveRoomSettings() {
    try {
        const resp = await axios.post(route('org.conference.settings', { uuid: props.room.uuid }), settingsForm.value);
        if (resp.data.success) {
            Object.assign(roomState.value, resp.data.room);
            roomState.value.watermark_enabled = Boolean(resp.data.room.watermark_enabled);
            isRoomLocked.value = Boolean(resp.data.room.is_locked);
            isWebinarMode.value = Boolean(resp.data.room.webinar_mode);
            isSettingsModalOpen.value = false;
            showToast(__('conference.room.settings_saved'));
            sendSFUMessage('room_settings_updated', roomState.value);
        }
    } catch (e: any) {
        showToast(e.response?.data?.message || __('conference.controls.media_permission_error'));
    }
}

// 17. Exit / Leave / End room
async function leaveRoom() {
    if (confirm(__('conference.controls.leave_confirm'))) {
        await axios.post(route('org.conference.leave', { uuid: props.room.uuid }));
        window.location.href = route('org.conference.index');
    }
}

async function endRoomForAll() {
    if (confirm(__('conference.controls.end_all_confirm'))) {
        await axios.post(route('org.conference.end', { uuid: props.room.uuid }));
        window.location.href = route('org.conference.index');
    }
}

watch(isWhiteboardOpen, (open) => {
    if (open) {
        initWhiteboard();
    }
});

onMounted(async () => {
    await initLocalMedia();
    connectSFU();
    await fetchState();
    pollTimer = setInterval(fetchState, 2200);
    speakerDetectInterval = setInterval(detectActiveSpeaker, 400);
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    if (speakerDetectInterval) {
        clearInterval(speakerDetectInterval);
    }
    if (sfuSocket) {
        sfuSocket.close();
        sfuSocket = null;
    }
    peerConnections.forEach((pc) => pc.close());
    peerConnections.clear();
    if (audioCtx) {
        try {
            audioCtx.close();
        } catch {}
    }
    if (localStream.value) {
        localStream.value.getTracks().forEach((track) => track.stop());
    }
    if (screenStream.value) {
        screenStream.value.getTracks().forEach((track) => track.stop());
    }
});
</script>

<template>
    <Head :title="room.title + ' - ' + __('conference.title')" />

    <div class="h-screen w-screen bg-slate-950 text-white flex flex-col overflow-hidden select-none font-sans relative">
        <!-- Dynamic Anti-Leak Floating Watermark Overlay -->
        <div v-if="roomState.watermark_enabled" class="pointer-events-none fixed inset-0 z-40 overflow-hidden opacity-20 mix-blend-screen flex items-center justify-around flex-wrap p-6 select-none">
            <div
                v-for="i in 6"
                :key="i"
                class="watermark-float transform -rotate-12 text-slate-300 font-mono text-xs sm:text-sm font-black tracking-widest uppercase border border-slate-600/30 rounded-xl px-4 py-2 backdrop-blur-[1px]"
                :style="{ animationDelay: `${i * 1.4}s` }"
            >
                <span>{{ currentParticipant.phone_number }}</span>
                <span class="mx-2">•</span>
                <span>#{{ currentParticipant.user_id }}</span>
                <span class="mx-2">•</span>
                <span>{{ __('conference.watermark.confidential') }}</span>
            </div>
        </div>

        <!-- Top Header Bar -->
        <header class="h-14 px-3 sm:px-6 bg-slate-900/95 backdrop-blur-md border-b border-slate-800 flex items-center justify-between z-30 shrink-0 gap-2">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse shrink-0" :title="__('conference.controls.sfu_connected')"></span>
                <h1 class="text-xs sm:text-base font-bold text-white truncate max-w-[160px] sm:max-w-md">
                    {{ room.title }}
                </h1>

                <!-- Smart Hybrid Mode Badge (P2P vs SFU) -->
                <span
                    class="hidden md:inline-flex px-2 py-0.5 rounded-md text-[10px] font-bold border"
                    :class="topologyMode === 'p2p' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30'"
                >
                    {{ topologyMode === 'p2p' ? __('conference.controls.p2p_mode_badge') : __('conference.controls.sfu_mode_badge') }}
                </span>

                <span v-if="isWebinarMode" class="px-2 py-0.5 rounded-md bg-amber-500/20 text-amber-400 text-[10px] font-bold border border-amber-500/30">
                    {{ __('conference.room.webinar_badge') }}
                </span>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <!-- Host: Unified Room Settings Modal Trigger -->
                <button
                    v-if="room.is_host"
                    @click="openSettingsModal"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-600/90 hover:bg-indigo-500 text-white text-xs font-bold transition shadow-sm cursor-pointer"
                    :title="__('conference.room.settings_title')"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="hidden sm:inline">{{ __('conference.room.settings_title') }}</span>
                </button>

                <!-- Host: Export Attendance CSV -->
                <a
                    v-if="room.is_host"
                    :href="route('org.conference.export_attendance', { uuid: room.uuid })"
                    target="_blank"
                    class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold border border-slate-700 transition"
                    :title="__('conference.controls.export_csv_tooltip')"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="hidden md:inline">{{ __('conference.controls.export_csv') }}</span>
                </a>

                <!-- Leave Room (for everyone including host) -->
                <button
                    @click="leaveRoom"
                    class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white text-xs font-bold transition flex items-center gap-1 border border-slate-700 cursor-pointer"
                    :title="__('conference.controls.leave')"
                >
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    <span>{{ __('conference.controls.leave') }}</span>
                </button>

                <!-- End Room for All (Host Only) -->
                <button
                    v-if="room.is_host"
                    @click="endRoomForAll"
                    class="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition flex items-center gap-1 shadow-md cursor-pointer"
                    :title="__('conference.controls.end_for_all')"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span>{{ __('conference.controls.end_for_all') }}</span>
                </button>
            </div>
        </header>

        <!-- Main Conference Workspace -->
        <main class="flex-1 relative flex overflow-hidden">
            <div class="flex-1 p-2 sm:p-4 flex flex-col items-center justify-center relative overflow-hidden gap-3">
                <!-- 1. Collaborative 16:9 Whiteboard View -->
                <div v-show="isWhiteboardOpen" class="w-full h-full flex flex-col items-center justify-center animate-fade-in relative">
                    <div class="w-full max-w-[calc(78vh*16/9)] aspect-video bg-white rounded-2xl shadow-2xl relative overflow-hidden border-2 border-indigo-500/40">
                        <canvas
                            ref="canvasRef"
                            @mousedown="startDraw"
                            @mousemove="drawMove"
                            @mouseup="stopDraw"
                            @mouseleave="stopDraw"
                            @touchstart.passive="startDraw"
                            @touchmove.passive="drawMove"
                            @touchend="stopDraw"
                            class="w-full h-full cursor-crosshair touch-none"
                        ></canvas>

                        <!-- Floating Whiteboard Toolbar -->
                        <div class="absolute top-3 left-3 bg-slate-900/95 backdrop-blur-md border border-slate-700 rounded-xl p-2 flex flex-wrap items-center gap-2.5 shadow-xl z-20">
                            <span class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 text-[10px] font-mono font-bold">
                                {{ localIdentityLabel }}
                            </span>
                            <input type="color" v-model="currentColor" class="w-7 h-7 rounded border-0 cursor-pointer bg-transparent" />
                            <input type="range" v-model.number="currentBrushSize" min="2" max="16" class="w-20 accent-indigo-500" />
                            <button
                                @click="clearWhiteboard"
                                class="px-2.5 py-1 rounded-lg bg-rose-600/80 hover:bg-rose-600 text-white text-xs font-bold transition"
                            >
                                {{ __('conference.controls.clear_whiteboard') }}
                            </button>
                            <button
                                @click="isWhiteboardOpen = false"
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition"
                            >
                                {{ __('conference.controls.close_whiteboard') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2. Screen Share Presenter Stage (With Mobile Pinch-to-Zoom & Mouse Wheel Zoom) -->
                <div
                    v-if="!isWhiteboardOpen && (isSharingScreen || activePresenter)"
                    class="w-full flex-1 bg-black rounded-2xl border border-indigo-500/40 overflow-hidden relative flex items-center justify-center shadow-2xl"
                    @wheel.prevent="onPresenterWheel"
                    @touchstart.passive="onPresenterTouchStart"
                    @touchmove.passive="onPresenterTouchMove"
                    @touchend="onPresenterTouchEnd"
                >
                    <!-- Presenter Info & Zoom Controls Bar -->
                    <div class="absolute top-3 inset-x-3 flex items-center justify-between gap-2 z-20 pointer-events-auto">
                        <div class="bg-slate-900/90 backdrop-blur-md px-3 py-1.5 rounded-xl border border-white/10 text-xs font-bold flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                            <span class="text-indigo-300">{{ __('conference.controls.presenter_badge') }}</span>
                            <span>{{ activePresenter ? `${activePresenter.display_name} (${activePresenter.phone_number})` : localIdentityLabel }}</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="hidden sm:inline bg-slate-900/80 px-2.5 py-1 rounded-lg text-[11px] text-slate-300 border border-white/10">
                                {{ __('conference.controls.pinch_zoom_hint') }}
                            </span>
                            <button
                                v-if="zoomScale > 1"
                                @click="resetZoom"
                                class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-lg"
                            >
                                {{ __('conference.controls.reset_zoom') }} ({{ Math.round(zoomScale * 100) }}%)
                            </button>
                        </div>
                    </div>

                    <!-- Zoomable Video Container -->
                    <div
                        class="w-full h-full flex items-center justify-center transition-transform duration-75"
                        :style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoomScale})` }"
                    >
                        <video
                            v-if="isSharingScreen"
                            ref="localScreenVideoRef"
                            autoplay
                            muted
                            playsinline
                            class="max-w-full max-h-full object-contain"
                        ></video>
                        <video
                            v-else-if="activePresenter"
                            :ref="(el) => setRemoteVideoRef(activePresenter!.id, el)"
                            autoplay
                            playsinline
                            class="max-w-full max-h-full object-contain"
                        ></video>
                    </div>
                </div>

                <!-- 3. Adaptive Participant Video Grid -->
                <div
                    v-show="!isWhiteboardOpen"
                    class="w-full grid gap-3 sm:gap-4 items-center justify-center auto-rows-fr"
                    :class="[
                        isSharingScreen || activePresenter ? 'h-32 sm:h-40 grid-cols-3 sm:grid-cols-6 shrink-0' : 'h-full',
                        !isSharingScreen && !activePresenter && admittedParticipants.length <= 1 ? 'grid-cols-1 max-w-4xl' : '',
                        !isSharingScreen && !activePresenter && admittedParticipants.length === 2 ? 'grid-cols-1 sm:grid-cols-2' : '',
                        !isSharingScreen && !activePresenter && admittedParticipants.length >= 3 && admittedParticipants.length <= 4 ? 'grid-cols-2' : '',
                        !isSharingScreen && !activePresenter && admittedParticipants.length > 4 ? 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4' : '',
                    ]"
                >
                    <!-- Local User Camera Tile -->
                    <div
                        class="relative bg-slate-900 rounded-2xl overflow-hidden border transition-all flex items-center justify-center w-full h-full aspect-video max-h-[75vh]"
                        :class="activeSpeakerId === currentParticipant.id ? 'border-emerald-400 ring-2 ring-emerald-400/60 shadow-lg shadow-emerald-500/20' : 'border-slate-800'"
                    >
                        <!-- Keep video element mounted without display:none so decoder never pauses -->
                        <video
                            ref="localVideoRef"
                            autoplay
                            muted
                            playsinline
                            class="w-full h-full object-cover transform -scale-x-100 transition-opacity duration-200"
                            :class="isCameraOn ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                        ></video>

                        <div v-if="!isCameraOn" class="absolute inset-0 flex flex-col items-center justify-center text-slate-500 space-y-2 p-2 bg-slate-900">
                            <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-200 font-bold text-base sm:text-lg">
                                {{ currentParticipant.display_name.charAt(0) }}
                            </div>
                            <span class="text-xs text-slate-300 font-semibold text-center truncate max-w-full">
                                {{ currentParticipant.display_name }} ({{ __('conference.common.you') }})
                            </span>
                            <span class="text-[11px] text-rose-400 font-medium">
                                {{ __('conference.controls.camera_off_label') }}
                            </span>
                        </div>

                        <!-- Verified Phone Line & User ID Badge -->
                        <div class="absolute bottom-2 right-2 bg-black/75 backdrop-blur-md px-2.5 py-1 rounded-lg border border-white/10 flex items-center gap-1.5 text-xs text-white z-10 font-mono max-w-[92%] truncate">
                            <span class="w-2 h-2 rounded-full shrink-0" :class="isMuted ? 'bg-rose-500' : 'bg-emerald-500'"></span>
                            <span class="text-[11px] font-sans font-bold truncate">{{ currentParticipant.display_name }}</span>
                            <span class="text-indigo-300 font-mono text-[10px] shrink-0">(#{{ currentParticipant.user_id }} • {{ currentParticipant.phone_number }})</span>
                        </div>

                        <div v-if="handRaised" class="absolute top-2 right-2 bg-amber-500 text-slate-950 font-black px-2 py-0.5 rounded-lg text-[11px] shadow-lg animate-bounce z-10">
                            {{ __('conference.controls.hand_raised_badge') }}
                        </div>
                    </div>

                    <!-- Remote Participants Video Tiles -->
                    <div
                        v-for="p in remoteAdmittedParticipants"
                        :key="p.id"
                        class="relative bg-slate-900 rounded-2xl overflow-hidden border transition-all flex items-center justify-center w-full h-full aspect-video max-h-[75vh]"
                        :class="activeSpeakerId === p.id ? 'border-emerald-400 ring-2 ring-emerald-400/60 shadow-lg shadow-emerald-500/20' : 'border-slate-800'"
                    >
                        <video
                            :ref="(el) => setRemoteVideoRef(p.id, el)"
                            autoplay
                            playsinline
                            class="w-full h-full object-cover transition-opacity duration-200"
                            :class="p.is_camera_on ? 'opacity-100' : 'opacity-0 pointer-events-none'"
                        ></video>

                        <div v-if="!p.is_camera_on" class="absolute inset-0 flex flex-col items-center justify-center text-slate-500 space-y-2 p-2 bg-slate-900">
                            <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-indigo-950/60 border border-indigo-700/40 flex items-center justify-center text-indigo-300 font-bold text-base sm:text-lg">
                                {{ p.display_name.charAt(0) }}
                            </div>
                            <span class="text-xs text-slate-300 font-semibold text-center truncate max-w-full">{{ p.display_name }}</span>
                        </div>

                        <!-- Verified Phone Line & User ID Badge -->
                        <div class="absolute bottom-2 right-2 bg-black/75 backdrop-blur-md px-2.5 py-1 rounded-lg border border-white/10 flex items-center gap-1.5 text-xs text-white z-10 font-mono max-w-[92%] truncate">
                            <span class="w-2 h-2 rounded-full shrink-0" :class="p.is_muted ? 'bg-rose-500' : 'bg-emerald-500'"></span>
                            <span class="text-[11px] font-sans font-bold truncate">{{ p.display_name }}</span>
                            <span class="text-indigo-300 font-mono text-[10px] shrink-0">(#{{ p.user_id }} • {{ p.phone_number }})</span>
                        </div>

                        <div v-if="p.hand_raised" class="absolute top-2 right-2 bg-amber-500 text-slate-950 font-black px-2 py-0.5 rounded-lg text-[11px] shadow-lg animate-bounce z-10">
                            {{ __('conference.controls.hand_raised_badge') }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slide-Over Drawer (Participants / Chat / Polls) -->
            <aside
                v-if="activeSideDrawer"
                class="w-80 sm:w-96 bg-slate-900 border-r border-slate-800 flex flex-col h-full z-30 shadow-2xl animate-fade-in"
            >
                <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-white">
                        <span v-if="activeSideDrawer === 'participants'">{{ __('conference.controls.participants') }} ({{ admittedParticipants.length }})</span>
                        <span v-else-if="activeSideDrawer === 'chat'">{{ __('conference.chat.title') }}</span>
                        <span v-else-if="activeSideDrawer === 'polls'">{{ __('conference.polls.title') }}</span>
                    </h3>
                    <button @click="activeSideDrawer = null" class="text-slate-400 hover:text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- 1. Participants Drawer (Waiting Lobby Requests + Speaking Queue + Admitted Members) -->
                <div v-if="activeSideDrawer === 'participants'" class="flex-1 overflow-y-auto p-3 space-y-4">
                    <!-- A. Waiting Lobby Approval Section (Host Only) -->
                    <div v-if="room.is_host && waitingParticipants.length > 0" class="space-y-2">
                        <div class="text-[11px] font-bold text-amber-400 flex items-center justify-between px-1">
                            <span>{{ __('conference.controls.waiting_requests_title') }}</span>
                            <span class="px-1.5 py-0.5 rounded-full bg-amber-500/20 text-amber-300 font-mono">{{ waitingParticipants.length }}</span>
                        </div>
                        <div
                            v-for="wp in waitingParticipants"
                            :key="wp.id"
                            class="p-3 rounded-xl bg-amber-950/30 border border-amber-500/40 flex items-center justify-between gap-2 text-xs"
                        >
                            <div class="min-w-0">
                                <div class="font-bold text-white truncate">{{ wp.display_name }}</div>
                                <div class="text-[11px] text-amber-300 font-mono">#{{ wp.user_id }} • {{ wp.phone_number }}</div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    @click="admitWaitingParticipant(wp)"
                                    class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[11px] transition"
                                >
                                    {{ __('conference.controls.admit_btn') }}
                                </button>
                                <button
                                    @click="rejectWaitingParticipant(wp)"
                                    class="px-2 py-1 rounded-lg bg-rose-900/70 hover:bg-rose-800 text-rose-200 font-bold text-[11px] transition"
                                >
                                    {{ __('conference.controls.reject_btn') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- B. Speaking Queue (Raised Hands) -->
                    <div v-if="speakingQueueParticipants.length > 0" class="space-y-2">
                        <div class="text-[11px] font-bold text-indigo-400 px-1">
                            {{ __('conference.controls.speaking_queue_title') }} ({{ speakingQueueParticipants.length }})
                        </div>
                        <div
                            v-for="(sp, idx) in speakingQueueParticipants"
                            :key="'sq-' + sp.id"
                            class="p-2.5 rounded-xl bg-indigo-950/40 border border-indigo-500/30 flex items-center justify-between gap-2 text-xs"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-5 h-5 rounded-full bg-amber-500 text-slate-950 font-black flex items-center justify-center text-[10px] shrink-0">
                                    {{ idx + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <div class="font-bold text-slate-200 truncate">{{ sp.display_name }}</div>
                                    <div class="text-[10px] text-slate-400 font-mono">{{ sp.phone_number }}</div>
                                </div>
                            </div>
                            <button
                                v-if="room.is_host && sp.is_muted"
                                @click="toggleParticipantMute(sp)"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-[11px] font-bold shrink-0"
                            >
                                {{ __('conference.controls.mic_on') }}
                            </button>
                        </div>
                    </div>

                    <!-- C. Admitted Active Participants -->
                    <div class="space-y-2">
                        <div class="text-[11px] font-bold text-slate-400 px-1">
                            {{ __('conference.controls.active_members_title') }}
                        </div>
                        <div
                            v-for="p in admittedParticipants"
                            :key="p.id"
                            class="p-3 rounded-xl bg-slate-800/60 border border-slate-700/50 flex items-center justify-between gap-2 text-xs"
                        >
                            <div class="space-y-0.5 min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-bold text-slate-200 truncate">{{ p.display_name }}</span>
                                    <span v-if="p.role === 'host' || p.user_id === room.host_user_id" class="px-2 py-0.5 rounded bg-indigo-500/25 text-indigo-300 border border-indigo-500/30 text-[10px] font-extrabold shrink-0 flex items-center gap-1">
                                        👑 {{ __('conference.common.host') }}
                                    </span>
                                    <span v-if="p.is_self" class="px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400 text-[10px] font-bold shrink-0">
                                        ({{ __('conference.common.you') }})
                                    </span>
                                    <span v-if="p.hand_raised" class="text-amber-400 text-xs shrink-0">✋</span>
                                </div>
                                <div class="text-[11px] text-slate-400 font-mono">#{{ p.user_id }} • {{ p.phone_number }}</div>
                            </div>

                            <!-- Host Controls per Participant: Mute, Grant/Revoke Screen Share, Kick -->
                            <div v-if="room.is_host && !p.is_self" class="flex items-center gap-1 shrink-0">
                                <button
                                    @click="toggleParticipantMute(p)"
                                    :class="p.is_muted ? 'bg-rose-900/50 text-rose-300' : 'bg-slate-700 text-emerald-400'"
                                    class="p-1.5 rounded-lg hover:bg-slate-600 transition"
                                    :title="p.is_muted ? __('conference.controls.mic_on') : __('conference.controls.mic_off')"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                    </svg>
                                </button>

                                <button
                                    @click="toggleParticipantScreenShare(p)"
                                    :class="p.can_share_screen ? 'bg-emerald-600 text-white' : 'bg-slate-700 text-slate-400 hover:bg-slate-600'"
                                    class="p-1.5 rounded-lg transition"
                                    :title="p.can_share_screen ? __('conference.controls.revoke_share_btn') : __('conference.controls.grant_share_btn')"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </button>

                                <button
                                    @click="kickParticipant(p)"
                                    class="p-1.5 rounded-lg bg-rose-950/60 hover:bg-rose-900 text-rose-400 transition"
                                    :title="__('conference.controls.kick_btn')"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Chat Drawer -->
                <div v-else-if="activeSideDrawer === 'chat'" class="flex-1 flex flex-col justify-between overflow-hidden">
                    <div class="flex-1 overflow-y-auto p-4 space-y-3">
                        <div v-if="messages.length === 0" class="text-center text-xs text-slate-500 py-10">
                            {{ __('conference.chat.no_messages') }}
                        </div>
                        <div
                            v-for="msg in messages"
                            :key="msg.id"
                            :class="msg.is_self ? 'text-left' : 'text-right'"
                            class="space-y-1"
                        >
                            <div class="text-[10px] text-slate-400 flex items-center gap-1" :class="msg.is_self ? 'justify-end' : 'justify-start'">
                                <span class="font-bold text-slate-300">{{ msg.sender_name }}</span>
                                <span v-if="msg.is_private" class="px-1.5 py-0.2 rounded bg-amber-500/20 text-amber-400 text-[9px] font-bold">
                                    {{ __('conference.chat.private_badge') }}
                                </span>
                                <span class="font-mono text-[9px]">{{ msg.created_at }}</span>
                            </div>
                            <div
                                :class="msg.is_self ? 'bg-indigo-600 text-white rounded-l-2xl rounded-tr-2xl' : 'bg-slate-800 text-slate-200 rounded-r-2xl rounded-tl-2xl'"
                                class="p-3 text-xs inline-block max-w-[85%] leading-relaxed shadow-sm break-words"
                            >
                                {{ msg.message }}
                            </div>
                        </div>
                    </div>

                    <div class="p-3 border-t border-slate-800 space-y-2 bg-slate-900/90">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-slate-400 text-[11px]">{{ __('conference.chat.recipient_label') }}</span>
                            <select v-model="chatRecipientMode" class="flex-1 bg-slate-800 border-0 rounded-lg text-white text-[11px] py-1 px-2">
                                <option value="everyone">{{ __('conference.chat.to_everyone') }}</option>
                                <option v-if="!room.is_host" value="host">{{ __('conference.chat.to_host_only') }}</option>
                                <template v-if="room.is_host">
                                    <option v-for="p in remoteAdmittedParticipants" :key="p.user_id" :value="p.user_id">
                                        {{ p.display_name }} ({{ p.phone_number }})
                                    </option>
                                </template>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <input
                                v-model="chatInput"
                                @keyup.enter="sendChatMessage"
                                type="text"
                                :placeholder="__('conference.chat.placeholder')"
                                class="flex-1 bg-slate-800 border-slate-700 text-white text-xs rounded-xl px-3 py-2"
                            />
                            <button
                                @click="sendChatMessage"
                                class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition"
                            >
                                {{ __('conference.chat.send') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 3. Polls Drawer -->
                <div v-else-if="activeSideDrawer === 'polls'" class="flex-1 overflow-y-auto p-4 space-y-4">
                    <button
                        v-if="room.is_host"
                        @click="isPollCreateModalOpen = true"
                        class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition shadow-md flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>{{ __('conference.polls.create_new') }}</span>
                    </button>

                    <div v-if="!activePoll" class="text-center text-xs text-slate-500 py-10">
                        {{ __('conference.polls.no_active_poll') }}
                    </div>

                    <div v-else class="bg-slate-800/80 border border-slate-700 rounded-2xl p-4 space-y-4">
                        <div class="space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 text-[10px] font-bold">
                                    {{ __('conference.polls.active_poll') }}
                                </span>
                                <button
                                    v-if="room.is_host"
                                    @click="closeActivePoll"
                                    class="text-[11px] text-rose-400 hover:text-rose-300 font-bold"
                                >
                                    {{ __('conference.polls.close_poll') }}
                                </button>
                            </div>
                            <h4 class="text-sm font-bold text-white pt-1">{{ activePoll.question }}</h4>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="opt in activePoll.results.options"
                                :key="opt.index"
                                @click="!activePoll.has_voted && submitVote(opt.index)"
                                class="p-3 rounded-xl border transition relative overflow-hidden"
                                :class="[
                                    activePoll.has_voted ? 'cursor-default border-slate-700 bg-slate-900/60' : 'cursor-pointer border-slate-700 hover:border-indigo-500 bg-slate-800',
                                    activePoll.user_voted_index === opt.index ? 'border-indigo-500 ring-1 ring-indigo-500' : ''
                                ]"
                            >
                                <div
                                    class="absolute top-0 bottom-0 left-0 bg-indigo-600/30 transition-all duration-500 rounded-xl"
                                    :style="{ width: opt.percentage + '%' }"
                                ></div>

                                <div class="relative flex items-center justify-between text-xs z-10">
                                    <span class="font-semibold text-slate-200">{{ opt.label }}</span>
                                    <span class="font-mono font-bold text-indigo-400">{{ opt.percentage }}% ({{ opt.votes }})</span>
                                </div>
                            </div>
                        </div>

                        <div class="text-[11px] text-slate-400 text-center font-mono">
                            {{ __('conference.polls.total_votes_count', { count: activePoll.results.total_votes }) }}
                        </div>
                    </div>
                </div>
            </aside>
        </main>

        <!-- Bottom Controls Bar -->
        <footer class="h-20 px-3 sm:px-6 bg-slate-900/95 backdrop-blur-md border-t border-slate-800 flex items-center justify-between z-30 shrink-0">
            <!-- Left Controls: Whiteboard & Screen Share -->
            <div class="flex items-center gap-2">
                <button
                    v-if="room.allow_whiteboard"
                    @click="isWhiteboardOpen = !isWhiteboardOpen"
                    :class="isWhiteboardOpen ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                    class="px-3 py-2.5 rounded-xl border border-slate-700 text-xs font-bold transition flex items-center gap-1.5"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                    <span class="hidden sm:inline">{{ __('conference.controls.whiteboard') }}</span>
                </button>

                <button
                    @click="toggleScreenShare"
                    :class="[
                        isSharingScreen ? 'bg-emerald-600 text-white border-emerald-500' : 'bg-slate-800 text-slate-300 hover:bg-slate-700 border-slate-700',
                        !canShareScreen && !room.is_host ? 'opacity-60' : ''
                    ]"
                    class="px-3 py-2.5 rounded-xl border text-xs font-bold transition flex items-center gap-1.5"
                    :title="canShareScreen || room.is_host ? __('conference.controls.share_screen') : __('conference.controls.screen_share_denied')"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span class="hidden sm:inline">{{ isSharingScreen ? __('conference.controls.stop_share_screen') : __('conference.controls.share_screen') }}</span>
                </button>
            </div>

            <!-- Center Controls: Mic (with label), Camera (with label), Raise Hand, Mute All -->
            <div class="flex items-center gap-2 sm:gap-3">
                <button
                    type="button"
                    @click="toggleAudio"
                    :class="isMuted ? 'bg-rose-600 hover:bg-rose-500 text-white border-rose-500' : 'bg-emerald-600/90 hover:bg-emerald-500 text-white border-emerald-400/50'"
                    class="px-3.5 py-3 rounded-2xl border transition shadow-lg flex items-center gap-2 text-xs font-bold cursor-pointer"
                    :title="isMuted ? __('conference.controls.mic_on') : __('conference.controls.mic_off')"
                >
                    <svg v-if="!isMuted" class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                    </svg>
                    <svg v-else class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
                    </svg>
                    <span class="hidden md:inline">{{ isMuted ? __('conference.controls.mic_muted_label') : __('conference.controls.mic_active_label') }}</span>
                </button>

                <button
                    type="button"
                    @click="toggleVideo"
                    :class="!isCameraOn ? 'bg-rose-600 hover:bg-rose-500 text-white border-rose-500' : 'bg-emerald-600/90 hover:bg-emerald-500 text-white border-emerald-400/50'"
                    class="px-3.5 py-3 rounded-2xl border transition shadow-lg flex items-center gap-2 text-xs font-bold cursor-pointer"
                    :title="isCameraOn ? __('conference.controls.camera_off') : __('conference.controls.camera_on')"
                >
                    <svg v-if="isCameraOn" class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <svg v-else class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                    <span class="hidden md:inline">{{ isCameraOn ? __('conference.controls.camera_active_label') : __('conference.controls.camera_off_label') }}</span>
                </button>

                <button
                    @click="toggleRaiseHand"
                    :class="handRaised ? 'bg-amber-500 text-slate-950 font-black' : 'bg-slate-800 text-slate-200 hover:bg-slate-700'"
                    class="px-3.5 py-3 rounded-2xl border border-slate-700 transition shadow-lg flex items-center gap-1.5 text-xs font-bold"
                >
                    <span class="text-base">✋</span>
                    <span class="hidden sm:inline">{{ handRaised ? __('conference.controls.lower_hand') : __('conference.controls.raise_hand') }}</span>
                </button>

                <button
                    v-if="room.is_host"
                    @click="muteAll"
                    class="hidden sm:flex px-3 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 text-rose-400 border border-slate-700 text-xs font-bold transition items-center gap-1.5"
                    :title="__('conference.controls.mute_all')"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
                    </svg>
                    <span>{{ __('conference.controls.mute_all') }}</span>
                </button>
            </div>

            <!-- Right Controls: Drawer Toggles with Live Badges -->
            <div class="flex items-center gap-2">
                <button
                    @click="activeSideDrawer = activeSideDrawer === 'participants' ? null : 'participants'"
                    :class="activeSideDrawer === 'participants' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                    class="p-2.5 rounded-xl border border-slate-700 transition relative"
                    :title="__('conference.controls.participants')"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <span
                        v-if="room.is_host && waitingParticipants.length > 0"
                        class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-amber-500 text-slate-950 font-black text-[10px] flex items-center justify-center animate-bounce"
                    >
                        {{ waitingParticipants.length }}
                    </span>
                </button>

                <button
                    @click="activeSideDrawer = activeSideDrawer === 'chat' ? null : 'chat'"
                    :class="activeSideDrawer === 'chat' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                    class="p-2.5 rounded-xl border border-slate-700 transition relative"
                    :title="__('conference.controls.chat')"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </button>

                <button
                    @click="activeSideDrawer = activeSideDrawer === 'polls' ? null : 'polls'"
                    :class="activeSideDrawer === 'polls' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                    class="p-2.5 rounded-xl border border-slate-700 transition relative"
                    :title="__('conference.controls.polls')"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span
                        v-if="activePoll && !activePoll.has_voted"
                        class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"
                    ></span>
                </button>
            </div>
        </footer>

        <!-- Create New Poll Modal (Host Only) -->
        <div
            v-if="isPollCreateModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-slate-900 border border-slate-700 rounded-3xl w-full max-w-md p-6 space-y-5 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <h3 class="text-base font-bold text-white">{{ __('conference.polls.create_new') }}</h3>
                    <button @click="isPollCreateModalOpen = false" class="text-slate-400 hover:text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1">
                            {{ __('conference.polls.question') }} *
                        </label>
                        <input
                            v-model="newPollQuestion"
                            type="text"
                            :placeholder="__('conference.polls.question_placeholder')"
                            class="w-full px-3.5 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs"
                        />
                    </div>

                    <div class="space-y-2">
                        <div
                            v-for="(_, idx) in newPollOptions"
                            :key="idx"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="newPollOptions[idx]"
                                type="text"
                                :placeholder="__('conference.polls.option', { number: idx + 1 })"
                                class="flex-1 px-3.5 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs"
                            />
                            <button
                                v-if="newPollOptions.length > 2"
                                type="button"
                                @click="removePollOption(idx)"
                                class="p-2 rounded-xl bg-rose-950/50 text-rose-400 hover:bg-rose-900"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <button
                            v-if="newPollOptions.length < 6"
                            type="button"
                            @click="addPollOption"
                            class="text-xs font-bold text-indigo-400 hover:text-indigo-300 flex items-center gap-1 pt-1"
                        >
                            <span>+ {{ __('conference.polls.add_option') }}</span>
                        </button>
                    </div>
                </div>

                <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                    <button
                        type="button"
                        @click="isPollCreateModalOpen = false"
                        class="px-4 py-2 rounded-xl text-slate-400 hover:bg-slate-800 text-xs font-semibold"
                    >
                        {{ __('conference.common.cancel') }}
                    </button>
                    <button
                        type="button"
                        @click="submitCreatePoll"
                        class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-lg"
                    >
                        {{ __('conference.polls.submit_poll') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Unified Room Settings Modal (Host Only) -->
        <div
            v-if="isSettingsModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm animate-fade-in"
        >
            <div class="bg-slate-900 border border-slate-700 rounded-3xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-6 space-y-5 shadow-2xl">
                <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-indigo-600/30 text-indigo-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </span>
                        <h3 class="text-base font-bold text-white">{{ __('conference.room.settings_title') }}</h3>
                    </div>
                    <button @click="isSettingsModalOpen = false" class="text-slate-400 hover:text-white cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-1">
                                {{ __('conference.room.admission_policy') }}
                            </label>
                            <select
                                v-model="settingsForm.admission_policy"
                                class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800 text-white text-xs font-medium"
                            >
                                <option value="open">{{ __('conference.room.admission_open') }}</option>
                                <option value="approval">{{ __('conference.room.admission_approval') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-1">
                                {{ __('conference.room.initial_audio') }}
                            </label>
                            <select
                                v-model="settingsForm.initial_audio_state"
                                class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-800 text-white text-xs font-medium"
                            >
                                <option value="open">{{ __('conference.room.initial_audio_open') }}</option>
                                <option value="muted">{{ __('conference.room.initial_audio_muted') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1">
                            {{ __('conference.room.screen_share_policy') }}
                        </label>
                        <select
                            v-model="settingsForm.screen_share_policy"
                            class="w-full px-3 py-2.5 rounded-xl border border-slate-700 bg-slate-800 text-white text-xs font-medium"
                        >
                            <option value="everyone">{{ __('conference.room.screen_share_everyone') }}</option>
                            <option value="by_permission">{{ __('conference.room.screen_share_by_permission') }}</option>
                            <option value="host_only">{{ __('conference.room.screen_share_host_only') }}</option>
                        </select>
                    </div>

                    <div class="space-y-2.5 pt-1">
                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-800 bg-slate-800/50 cursor-pointer">
                            <input v-model="settingsForm.watermark_enabled" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-200 block">{{ __('conference.room.watermark_enabled_label') }}</span>
                                <span class="text-slate-400 text-[11px]">{{ __('conference.room.watermark_enabled_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-800 bg-slate-800/50 cursor-pointer">
                            <input v-model="settingsForm.mute_others_on_share" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-200 block">{{ __('conference.room.mute_others_on_share') }}</span>
                                <span class="text-slate-400 text-[11px]">{{ __('conference.room.mute_others_on_share_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-800 bg-slate-800/50 cursor-pointer">
                            <input v-model="settingsForm.webinar_mode" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-200 block">{{ __('conference.room.webinar_mode') }}</span>
                                <span class="text-slate-400 text-[11px]">{{ __('conference.room.webinar_mode_desc') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-800 bg-slate-800/50 cursor-pointer">
                            <input v-model="settingsForm.allow_whiteboard" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-200 block">{{ __('conference.room.allow_whiteboard') }}</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-800 bg-slate-800/50 cursor-pointer">
                            <input v-model="settingsForm.is_locked" type="checkbox" class="w-4 h-4 text-indigo-600 rounded" />
                            <div class="text-xs">
                                <span class="font-bold text-slate-200 block">{{ __('conference.controls.lock_room') }}</span>
                                <span class="text-slate-400 text-[11px]">{{ __('conference.controls.lock_room_desc') }}</span>
                            </div>
                        </label>
                    </div>

                    <div class="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                        <button
                            type="button"
                            @click="isSettingsModalOpen = false"
                            class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold transition cursor-pointer"
                        >
                            {{ __('conference.common.cancel') }}
                        </button>
                        <button
                            type="button"
                            @click="saveRoomSettings"
                            class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition shadow-lg cursor-pointer"
                        >
                            {{ __('conference.room.save_settings') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast Notification Alert -->
        <div
            v-if="toastMessage"
            class="fixed top-16 left-1/2 transform -translate-x-1/2 bg-slate-900/95 border border-indigo-500/50 text-white px-5 py-2.5 rounded-2xl text-xs font-semibold shadow-2xl z-50 animate-bounce"
        >
            {{ toastMessage }}
        </div>
    </div>
</template>

<style scoped>
@keyframes floatWatermark {
    0% {
        transform: translate(0px, 0px) rotate(-12deg);
    }
    33% {
        transform: translate(24px, -18px) rotate(-10deg);
    }
    66% {
        transform: translate(-20px, 16px) rotate(-14deg);
    }
    100% {
        transform: translate(0px, 0px) rotate(-12deg);
    }
}
.watermark-float {
    animation: floatWatermark 14s ease-in-out infinite;
}
</style>
