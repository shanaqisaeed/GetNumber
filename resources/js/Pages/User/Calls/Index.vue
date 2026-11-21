<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import UserLayout from '@/Layouts/UserLayout.vue';
import ContactPickerModal, { type ContactItem as PickerContactItem } from '@/Components/ContactPickerModal.vue';
import { CalmRingtone } from '@/Utils/ringtone';
import { __ } from '@/Composables/useTrans';
import { usePwaInstall } from '@/Composables/usePwaInstall';

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
const { acquireWakeLock, releaseWakeLock } = usePwaInstall();

// WebRTC Video Calling & Multi-Camera Management
const isVideoCall = ref(false);
const isCameraOn = ref(false);
const currentFacingMode = ref<'user' | 'environment'>('user');
const isSwitchingCamera = ref(false);
const availableCameras = ref<{ deviceId: string; label: string; facingMode: 'user' | 'environment' }[]>([]);
const selectedCameraId = ref<string>('');
const videoQuality = ref<'hd' | 'sd' | 'low'>('hd');
const localVideo = ref<HTMLVideoElement | null>(null);
const remoteVideo = ref<HTMLVideoElement | null>(null);
const videoContainerRef = ref<HTMLElement | null>(null);
const isFullscreen = ref(false);
const optimalRouteInfo = ref<string>('');
const isNetworkDisconnected = ref(false);
const isPeerReconnecting = ref(false);
let remoteMediaStream: MediaStream | null = null;
let remoteVideoStream: MediaStream | null = null;
let isRenegotiating = false;
let renegotiationQueued = false;
let wsReconnectTimer: any = null;
let iceRestartDebounceTimer: any = null;

// Screen Sharing State
const isScreenSharing = ref(false);
const isRemoteScreenSharing = ref(false);
let screenMediaStream: MediaStream | null = null;
let originalCameraTrack: MediaStreamTrack | null = null;

// Remote Video Streaming Indicator (To avoid sticking in video view when neither side is streaming)
const isRemoteVideoStreaming = ref(false);
const isExpandedWorkspace = ref(false); // Theater / Full Width Mode

// Audio Output Routing State (Loudspeaker vs Earpiece)
const audioOutputDevices = ref<{ deviceId: string; label: string; isSpeaker: boolean }[]>([]);
const selectedAudioOutputId = ref<string>('default');

// Interactive Whiteboard State & WebRTC DataChannel
const isWhiteboardOpen = ref(false);
const isRemoteWhiteboardOpen = ref(false);
const isWhiteboardCameraActive = ref(true); // User preference: camera PiP during whiteboard session
const whiteboardCanvasRef = ref<HTMLCanvasElement | null>(null);
const whiteboardPipVideo = ref<HTMLVideoElement | null>(null);
const whiteboardRemoteVideo = ref<HTMLVideoElement | null>(null);
const whiteboardTool = ref<'pen' | 'highlighter' | 'eraser'>('pen');
const whiteboardColor = ref<string>('#ef4444');
const whiteboardLineWidth = ref<number>(3);
const isDataChannelReady = ref(false);
let whiteboardDataChannel: RTCDataChannel | null = null;
let videoTransceiverInstance: RTCRtpTransceiver | null = null;
let isDrawingOnCanvas = false;
let lastCanvasNx = 0;
let lastCanvasNy = 0;
const whiteboardUndoStack = ref<string[]>([]);

// Web Audio API for Realistic Speaker / Earpiece Acoustic Profiles
let audioContext: AudioContext | null = null;
let remoteAudioSourceNode: MediaStreamAudioSourceNode | null = null;
let biquadFilterNode: BiquadFilterNode | null = null;
let gainNode: GainNode | null = null;

// User Identity for Whiteboard Attribution
const localUserName = computed(() => {
    const u = (props as any).auth?.user;
    return u?.name || u?.phone || 'شما';
});

// Live Collaborative Indicators (Remote Cursor & Stroke Attribution)
const remotePeerCursor = ref<{ x: number; y: number; visible: boolean; name: string }>({
    x: 0,
    y: 0,
    visible: false,
    name: 'طرف مقابل',
});
let remoteCursorHideTimer: any = null;
const lastDrawingAuthor = ref<string>('');

// 16 Vibrant Whiteboard Colors
const whiteboardColors = [
    { hex: '#0f172a', name: 'مشکی زغالی' },
    { hex: '#ffffff', name: 'سفید' },
    { hex: '#64748b', name: 'خاکستری' },
    { hex: '#ef4444', name: 'قرمز مرجانی' },
    { hex: '#f43f5e', name: 'سرخابی' },
    { hex: '#f97316', name: 'نارنجی' },
    { hex: '#f59e0b', name: 'کهربایی' },
    { hex: '#eab308', name: 'زرد فسفری' },
    { hex: '#10b981', name: 'سبز زمردی' },
    { hex: '#059669', name: 'سبز تیره' },
    { hex: '#06b6d4', name: 'فیروزه‌ای' },
    { hex: '#0ea5e9', name: 'آبی آسمانی' },
    { hex: '#3b82f6', name: 'آبی اصلی' },
    { hex: '#1d4ed8', name: 'آبی کاربنی' },
    { hex: '#8b5cf6', name: 'بنفش' },
    { hex: '#ec4899', name: 'صورتی روشن' },
];

const activeCameraDisplayName = computed(() => {
    if (availableCameras.value.length === 0) {
        return currentFacingMode.value === 'user' ? 'دوربین جلو (سلفی)' : 'دوربین عقب (اصلی)';
    }
    const current = availableCameras.value.find(c => c.deviceId === selectedCameraId.value);
    if (current) return current.label;
    return currentFacingMode.value === 'user' ? 'دوربین جلو (سلفی)' : 'دوربین عقب (اصلی)';
});

const activeCameraShortName = computed(() => {
    if (availableCameras.value.length === 0) {
        return currentFacingMode.value === 'user' ? 'دوربین جلو' : 'دوربین عقب';
    }
    const current = availableCameras.value.find(c => c.deviceId === selectedCameraId.value);
    if (!current) return currentFacingMode.value === 'user' ? 'دوربین جلو' : 'دوربین عقب';
    if (current.label.includes('سلفی') || current.label.includes('جلو')) return 'دوربین جلو';
    if (current.label.includes('عریض') || current.label.includes('واید')) return 'دوربین واید';
    if (current.label.includes('تله') || current.label.includes('زوم')) return 'دوربین زوم';
    if (current.label.includes('پشت') || current.label.includes('عقب')) return 'دوربین عقب';
    return current.label.slice(0, 14);
});

const currentCameraIndexDisplay = computed(() => {
    if (availableCameras.value.length <= 1) return '';
    const idx = availableCameras.value.findIndex(c => c.deviceId === selectedCameraId.value);
    return `${(idx >= 0 ? idx : 0) + 1} از ${availableCameras.value.length}`;
});

const toggleFullscreen = async () => {
    try {
        if (!document.fullscreenElement) {
            if (videoContainerRef.value?.requestFullscreen) {
                await videoContainerRef.value.requestFullscreen();
                isFullscreen.value = true;
            }
        } else {
            if (document.exitFullscreen) {
                await document.exitFullscreen();
                isFullscreen.value = false;
            }
        }
    } catch {}
};

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

const getNormalizedDestination = (): string => {
    let dest = destinationInput.value.trim();
    if (!dest) return '';
    if (keypadMode.value === 'alphanumeric') {
        if (!dest.startsWith('@')) {
            dest = '@' + dest.replace(/^@+/, '');
        }
    } else {
        if (/[a-zA-Z]/.test(dest) && !dest.startsWith('+') && !dest.startsWith('@')) {
            dest = '@' + dest;
        }
    }
    return dest;
};

watch(destinationInput, (val) => {
    if (val && (val.startsWith('@') || (/[a-zA-Z]/.test(val) && !val.startsWith('+')))) {
        keypadMode.value = 'alphanumeric';
    }
});

// Camera Device Enumeration with Intelligent Multi-Lens, IR Filter & Front Camera Deduplication
const enumerateCameras = async () => {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(d => d.kind === 'videoinput');

        let seenFront = false;
        let backIdx = 0;
        const result: { deviceId: string; label: string; facingMode: 'user' | 'environment' }[] = [];

        for (let i = 0; i < videoDevices.length; i++) {
            const d = videoDevices[i];
            const raw = d.label || '';
            const lower = raw.toLowerCase();

            // 1. Filter out IR, Depth, ToF and non-standard virtual sensors
            if (lower.includes('ir ') || lower.includes('infra') || lower.includes('depth') || lower.includes('tof')) {
                continue;
            }

            let facing: 'user' | 'environment' = 'user';
            if (lower.includes('back') || lower.includes('rear') || lower.includes('environment')) {
                facing = 'environment';
                backIdx++;
            } else if (lower.includes('front') || lower.includes('user') || lower.includes('selfie')) {
                facing = 'user';
            } else {
                facing = i === 0 ? 'user' : 'environment';
                if (facing === 'environment') backIdx++;
            }

            // 2. Fix duplicate front camera issue on Android / PC Hello cameras:
            // Strictly keep only 1 front camera (primary selfie sensor) to avoid confusing duplicates
            if (facing === 'user') {
                if (seenFront) {
                    continue;
                }
                seenFront = true;
            }

            let label = raw;
            if (!label) {
                label = facing === 'user' ? 'دوربین جلو (سلفی)' : (backIdx > 1 ? `دوربین پشت (${backIdx})` : 'دوربین اصلی پشت');
            } else {
                if (lower.includes('back') || lower.includes('rear')) {
                    if (lower.includes('wide') || lower.includes('ultra')) {
                        label = 'دوربین پشت (فوق‌عریض / Ultra Wide)';
                    } else if (lower.includes('tele') || lower.includes('zoom')) {
                        label = 'دوربین پشت (تله‌فوتو / Zoom)';
                    } else if (lower.includes('macro')) {
                        label = 'دوربین پشت (ماکرو / Macro)';
                    } else {
                        label = backIdx > 1 ? `دوربین پشت شماره ${backIdx}` : 'دوربین اصلی پشت';
                    }
                } else if (lower.includes('front') || lower.includes('selfie') || lower.includes('user')) {
                    if (lower.includes('wide') || lower.includes('ultra')) {
                        label = 'دوربین جلو (فوق‌عریض / Ultra Wide)';
                    } else {
                        label = 'دوربین جلو (سلفی)';
                    }
                } else {
                    label = `وب‌کم (${raw.slice(0, 18)})`;
                }
            }

            result.push({
                deviceId: d.deviceId,
                label,
                facingMode: facing,
            });
        }

        availableCameras.value = result;

        if (availableCameras.value.length > 0) {
            const match = availableCameras.value.find(c => c.deviceId === selectedCameraId.value);
            if (!match) {
                selectedCameraId.value = availableCameras.value[0].deviceId;
                currentFacingMode.value = availableCameras.value[0].facingMode;
            }
        }
        console.log('[WebRTC-Cameras] 📷 Available cameras detected (clean):', availableCameras.value);
    } catch (e) {
        console.warn('[WebRTC-Cameras] Could not enumerate cameras:', e);
    }
};

const enumerateAudioOutputs = async () => {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const outputs = devices.filter(d => d.kind === 'audiooutput');
        audioOutputDevices.value = outputs.map((d, i) => {
            const raw = d.label || `خروجی ${i + 1}`;
            const lower = raw.toLowerCase();
            const isSpeaker = lower.includes('speaker') || lower.includes('loud') || lower.includes('بلندگو') || !lower.includes('earpiece');
            return {
                deviceId: d.deviceId,
                label: lower.includes('earpiece') ? 'گوشی (Earpiece)' : lower.includes('speaker') ? 'اسپیکر (بلندگو)' : raw,
                isSpeaker,
            };
        });
    } catch {}
};

const getVideoQualityResolution = () => {
    if (videoQuality.value === 'hd') {
        return { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } };
    } else if (videoQuality.value === 'sd') {
        return { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 24, max: 30 } };
    }
    return { width: { ideal: 480 }, height: { ideal: 360 }, frameRate: { ideal: 15, max: 20 } };
};

const getVideoConstraints = (): MediaTrackConstraints => {
    const base: MediaTrackConstraints = { ...getVideoQualityResolution() };
    if (selectedCameraId.value && selectedCameraId.value.length > 5) {
        base.deviceId = { ideal: selectedCameraId.value };
    }
    base.facingMode = { ideal: currentFacingMode.value };
    return base;
};

const syncAllVideoElements = async () => {
    await nextTick();
    const effectiveRemoteStream = remoteVideoStream || (remoteMediaStream?.getVideoTracks().length ? remoteMediaStream : null);
    if (effectiveRemoteStream) {
        if (remoteVideo.value) {
            if (remoteVideo.value.srcObject !== effectiveRemoteStream) {
                remoteVideo.value.srcObject = effectiveRemoteStream;
            }
            remoteVideo.value.play().catch(() => {});
        }
        if (whiteboardRemoteVideo.value) {
            if (whiteboardRemoteVideo.value.srcObject !== effectiveRemoteStream) {
                whiteboardRemoteVideo.value.srcObject = effectiveRemoteStream;
            }
            whiteboardRemoteVideo.value.play().catch(() => {});
        }
    }
    if (localMediaStream) {
        if (localVideo.value) {
            if (localVideo.value.srcObject !== localMediaStream) {
                localVideo.value.srcObject = localMediaStream;
            }
            localVideo.value.play().catch(() => {});
        }
        if (whiteboardPipVideo.value) {
            if (whiteboardPipVideo.value.srcObject !== localMediaStream) {
                whiteboardPipVideo.value.srcObject = localMediaStream;
            }
            whiteboardPipVideo.value.play().catch(() => {});
        }
    }

    // Secondary delayed check to ensure dynamically mounted DOM video elements attach properly
    setTimeout(() => {
        const stream = remoteVideoStream || (remoteMediaStream?.getVideoTracks().length ? remoteMediaStream : null);
        if (stream) {
            if (remoteVideo.value && remoteVideo.value.srcObject !== stream) {
                remoteVideo.value.srcObject = stream;
                remoteVideo.value.play().catch(() => {});
            }
            if (whiteboardRemoteVideo.value && whiteboardRemoteVideo.value.srcObject !== stream) {
                whiteboardRemoteVideo.value.srcObject = stream;
                whiteboardRemoteVideo.value.play().catch(() => {});
            }
        }
    }, 120);
};

const attachLocalStreamToVideo = async () => {
    await syncAllVideoElements();
};

watch([isVideoCall, isWhiteboardOpen, isWhiteboardCameraActive, isCameraOn, isScreenSharing, isRemoteScreenSharing, isRemoteVideoStreaming], () => {
    syncAllVideoElements();
});

// Request Media Access with Noise Suppression and Optional Video Track
const requestMediaAccess = async (withVideo = false): Promise<boolean> => {
    micPermissionGranted.value = null;
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
        micPermissionGranted.value = false;
        callError.value = __('user.calls.mic_unsupported');
        return false;
    }

    // Step 1: Request microphone track
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: { ideal: true },
                noiseSuppression: { ideal: isNoiseCancellationEnabled.value },
                autoGainControl: { ideal: true },
            },
        });
        localMediaStream = stream;
        micPermissionGranted.value = true;
        isMicModalOpen.value = false;
        callError.value = '';
    } catch {
        micPermissionGranted.value = false;
        isMicModalOpen.value = true;
        callError.value = __('user.calls.mic_blocked');
        return false;
    }

    // Step 2: If withVideo requested, explicitly request camera stream
    if (withVideo) {
        try {
            const videoStream = await navigator.mediaDevices.getUserMedia({
                video: getVideoConstraints(),
            });
            const vTrack = videoStream.getVideoTracks()[0];
            if (vTrack && localMediaStream) {
                localMediaStream.addTrack(vTrack);
                isCameraOn.value = true;
                isVideoCall.value = true;
                await attachLocalStreamToVideo();
                await enumerateCameras();
            }
        } catch (videoErr: any) {
            console.warn('[WebRTC] Camera access failed or blocked:', videoErr);
            isVideoCall.value = false;
            isCameraOn.value = false;
            callError.value = __('user.calls.audio_only_fallback');
        }
    }

    console.log('[WebRTC] 🎙️ Audio/Video initialized. Video:', withVideo, '| Noise Cancellation:', isNoiseCancellationEnabled.value);
    return true;
};

const retryMicrophoneAndCall = async () => {
    const ok = await requestMediaAccess(isVideoCall.value);
    if (ok) {
        await startCall(false, isVideoCall.value);
    }
};

const resetMediaControlStates = () => {
    isMuted.value = false;
    isSpeakerOn.value = false;
    isScreenSharing.value = false;
    isRemoteScreenSharing.value = false;
    isRemoteVideoStreaming.value = false;
    isExpandedWorkspace.value = false;
    isWhiteboardOpen.value = false;
    isRemoteWhiteboardOpen.value = false;
    if (localMediaStream) {
        localMediaStream.getAudioTracks().forEach(track => {
            track.enabled = true;
        });
        localMediaStream.getVideoTracks().forEach(track => {
            track.enabled = true;
        });
    }
    if (remoteAudio.value) {
        remoteAudio.value.muted = false;
        remoteAudio.value.volume = 0.55;
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

const initWebAudioRouting = (stream: MediaStream) => {
    try {
        const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
        if (!AudioCtx) return;
        if (!audioContext) {
            audioContext = new AudioCtx();
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume().catch(() => {});
        }
        if (remoteAudioSourceNode) {
            try { remoteAudioSourceNode.disconnect(); } catch {}
        }
        remoteAudioSourceNode = audioContext.createMediaStreamSource(stream);
        biquadFilterNode = audioContext.createBiquadFilter();
        gainNode = audioContext.createGain();

        // Connect source -> telephony filter -> gain -> destination
        remoteAudioSourceNode.connect(biquadFilterNode);
        biquadFilterNode.connect(gainNode);
        gainNode.connect(audioContext.destination);

        applySpeakerEarpieceProfile();
    } catch (e) {
        console.warn('[WebAudio] Routing graph init fallback:', e);
    }
};

const applySpeakerEarpieceProfile = () => {
    if (remoteAudio.value) {
        remoteAudio.value.volume = isSpeakerOn.value ? 1.0 : 0.35;
    }
    if (!audioContext || !biquadFilterNode || !gainNode) return;
    try {
        if (isSpeakerOn.value) {
            // Loudspeaker: Allpass flat filter + 100% gain
            biquadFilterNode.type = 'allpass';
            gainNode.gain.setValueAtTime(1.0, audioContext.currentTime);
        } else {
            // Earpiece: Bandpass filter 300Hz-3400Hz (telephony characteristic) + attenuated volume for ear comfort
            biquadFilterNode.type = 'bandpass';
            biquadFilterNode.frequency.setValueAtTime(1200, audioContext.currentTime);
            biquadFilterNode.Q.setValueAtTime(0.8, audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.35, audioContext.currentTime);
        }
    } catch {}
};

const toggleSpeaker = async () => {
    isSpeakerOn.value = !isSpeakerOn.value;
    applySpeakerEarpieceProfile();

    if (remoteAudio.value && typeof (remoteAudio.value as any).setSinkId === 'function') {
        try {
            if (audioOutputDevices.value.length === 0) {
                await enumerateAudioOutputs();
            }
            if (isSpeakerOn.value) {
                const speaker = audioOutputDevices.value.find(d => d.isSpeaker && d.deviceId);
                await (remoteAudio.value as any).setSinkId(speaker ? speaker.deviceId : '');
            } else {
                const earpiece = audioOutputDevices.value.find(d => !d.isSpeaker && d.deviceId);
                if (earpiece) {
                    await (remoteAudio.value as any).setSinkId(earpiece.deviceId);
                } else {
                    await (remoteAudio.value as any).setSinkId('');
                }
            }
        } catch (err) {
            console.warn('[WebRTC-Audio] setSinkId audio routing note:', err);
        }
    }
};

const handlePageUnload = () => {
    if (!isCalling.value || !activeCallId.value) return;

    const terminatingId = activeCallId.value;
    const sessId = bridgeSessionId.value;

    // 1. Immediately send WebSocket end_call if bridge socket is open
    if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
        try {
            bridgeSocket.send(JSON.stringify({
                type: 'end_call',
                session_id: sessId || undefined,
                call_id: terminatingId,
            }));
            bridgeSocket.close();
        } catch {}
    }

    // 2. Dispatch keepalive fetch / sendBeacon to guarantee backend call termination
    try {
        const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
        const endUrl = route('voice.end', terminatingId);
        const payload = JSON.stringify({
            duration_seconds: callDuration.value,
            _token: token,
        });

        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(endUrl, blob);
        } else {
            fetch(endUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: payload,
                keepalive: true,
            });
        }
    } catch {}

    // 3. Immediately close peer connection & release hardware media streams
    if (peerConnection) {
        try { peerConnection.close(); } catch {}
    }
    if (localMediaStream) {
        localMediaStream.getTracks().forEach(t => t.stop());
    }
};

// WebRTC SDP Mid-Call Dynamic Renegotiation
const renegotiatePeerConnection = async () => {
    if (!peerConnection || !isCalling.value) return;
    if (peerConnection.signalingState !== 'stable') {
        console.log('[WebRTC-Renegotiate] ⏳ Signaling state is ' + peerConnection.signalingState + ', queueing renegotiation...');
        renegotiationQueued = true;
        return;
    }
    if (isRenegotiating) {
        renegotiationQueued = true;
        return;
    }
    isRenegotiating = true;
    try {
        console.log('[WebRTC-Renegotiate] 🔄 Creating renegotiation offer...');
        const offer = await peerConnection.createOffer();
        if (offer.sdp) {
            offer.sdp = enhanceOpusSdp(offer.sdp);
        }
        await peerConnection.setLocalDescription(offer);
        await sendSignalingMessage('offer', offer);
        console.log('[WebRTC-Renegotiate] 📤 Renegotiation offer sent successfully');
    } catch (e) {
        console.warn('[WebRTC-Renegotiate] ⚠️ Renegotiation error:', e);
    } finally {
        isRenegotiating = false;
    }
};

// Live Camera Toggle During Call
const toggleCamera = async () => {
    if (!localMediaStream) {
        localMediaStream = new MediaStream();
    }

    const currentVideoTracks = localMediaStream.getVideoTracks();
    if (currentVideoTracks.length > 0 && isCameraOn.value) {
        currentVideoTracks.forEach(track => {
            track.stop();
            localMediaStream?.removeTrack(track);
        });
        isCameraOn.value = false;

        if (peerConnection) {
            let videoSender = peerConnection.getSenders().find(s => s.track?.kind === 'video' || (s as any).kind === 'video');
            if (!videoSender && videoTransceiverInstance) {
                videoSender = videoTransceiverInstance.sender;
            }
            if (videoSender) {
                videoSender.replaceTrack(null).catch(() => {});
            }
            if (videoTransceiverInstance) {
                videoTransceiverInstance.direction = isRemoteVideoStreaming.value ? 'recvonly' : 'inactive';
            }
        }

        await syncAllVideoElements();
        await sendSignalingMessage('camera_status', { is_camera_on: false });

        // If neither side is streaming video or sharing screen/whiteboard, collapse back to audio mode
        if (!isScreenSharing.value && !isRemoteScreenSharing.value && !isRemoteVideoStreaming.value && !isWhiteboardOpen.value && !isRemoteWhiteboardOpen.value) {
            isVideoCall.value = false;
        }
    } else {
        try {
            const videoStream = await navigator.mediaDevices.getUserMedia({
                video: getVideoConstraints(),
            });
            const newTrack = videoStream.getVideoTracks()[0];
            if (newTrack) {
                localMediaStream.addTrack(newTrack);
                isCameraOn.value = true;
                isVideoCall.value = true;
                await enumerateCameras();

                if (peerConnection) {
                    let videoSender = peerConnection.getSenders().find(s => s.track?.kind === 'video' || (s as any).kind === 'video');
                    if (!videoSender && videoTransceiverInstance) {
                        videoSender = videoTransceiverInstance.sender;
                    }
                    if (videoSender) {
                        await videoSender.replaceTrack(newTrack);
                        if (videoTransceiverInstance) {
                            videoTransceiverInstance.direction = 'sendrecv';
                        }
                    } else if (videoTransceiverInstance) {
                        await videoTransceiverInstance.sender.replaceTrack(newTrack);
                        videoTransceiverInstance.direction = 'sendrecv';
                    } else {
                        peerConnection.addTrack(newTrack, localMediaStream);
                    }
                }

                await syncAllVideoElements();
                await sendSignalingMessage('camera_status', { is_camera_on: true });
            }
        } catch (e) {
            console.warn('[WebRTC-Video] Could not enable camera:', e);
            callError.value = __('softphone.devices.cam_permission_denied') || __('user.calls.audio_only_fallback');
        }
    }
};

// Switch Camera (Front / Rear & Multi-Lens / USB Webcams)
const switchCamera = async () => {
    if (!localMediaStream || isSwitchingCamera.value) return;
    isSwitchingCamera.value = true;

    // 1. Advance to the next available camera
    if (availableCameras.value.length > 1) {
        const currentIndex = availableCameras.value.findIndex(c => c.deviceId === selectedCameraId.value);
        const nextIndex = (currentIndex + 1) % availableCameras.value.length;
        selectedCameraId.value = availableCameras.value[nextIndex].deviceId;
        currentFacingMode.value = availableCameras.value[nextIndex].facingMode;
    } else {
        // Toggle facing mode if no deviceId list yet
        currentFacingMode.value = currentFacingMode.value === 'user' ? 'environment' : 'user';
    }

    // 2. CRITICAL: Stop previous tracks BEFORE requesting the new track so the hardware sensor lock is released!
    const currentVideoTracks = localMediaStream.getVideoTracks();
    currentVideoTracks.forEach(t => {
        t.stop();
        localMediaStream?.removeTrack(t);
    });

    try {
        let newStream: MediaStream | null = null;

        // Attempt 1: By exact deviceId (if available)
        if (selectedCameraId.value && selectedCameraId.value.length > 5) {
            try {
                newStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        deviceId: { exact: selectedCameraId.value },
                        ...getVideoQualityResolution(),
                    },
                });
            } catch (err1) {
                console.log('[WebRTC-Video] Exact deviceId request failed, falling back:', err1);
            }
        }

        // Attempt 2: By exact facingMode (standard iOS Safari & Android Chrome)
        if (!newStream) {
            try {
                newStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { exact: currentFacingMode.value },
                        ...getVideoQualityResolution(),
                    },
                });
            } catch (err2) {
                console.log('[WebRTC-Video] Exact facingMode request failed, falling back:', err2);
            }
        }

        // Attempt 3: By ideal facingMode
        if (!newStream) {
            try {
                newStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: currentFacingMode.value },
                        ...getVideoQualityResolution(),
                    },
                });
            } catch (err3) {
                console.log('[WebRTC-Video] Ideal facingMode request failed:', err3);
            }
        }

        // Attempt 4: General fallback constraints
        if (!newStream) {
            newStream = await navigator.mediaDevices.getUserMedia({
                video: getVideoConstraints(),
            });
        }

        const newTrack = newStream?.getVideoTracks()[0];
        if (newTrack) {
            localMediaStream.addTrack(newTrack);
            await syncAllVideoElements();

            // Sync track settings
            const settings = newTrack.getSettings?.();
            if (settings?.deviceId) {
                selectedCameraId.value = settings.deviceId;
            }
            if (settings?.facingMode) {
                currentFacingMode.value = settings.facingMode as 'user' | 'environment';
            }

            // Seamlessly swap track on WebRTC peer connection
            if (peerConnection) {
                let videoSender = peerConnection.getSenders().find(s => s.track?.kind === 'video' || (s as any).kind === 'video');
                if (!videoSender && videoTransceiverInstance) {
                    videoSender = videoTransceiverInstance.sender;
                }
                if (videoSender) {
                    await videoSender.replaceTrack(newTrack);
                    if (videoTransceiverInstance) {
                        videoTransceiverInstance.direction = 'sendrecv';
                    }
                } else {
                    peerConnection.addTrack(newTrack, localMediaStream);
                }
                await renegotiatePeerConnection();
            }

            await enumerateCameras();
        }
    } catch (e) {
        console.error('[WebRTC-Video] Could not switch camera:', e);
        callError.value = 'خطا در سوییچ به دوربین انتخابی سخت‌افزار.';
        // Attempt fallback to user camera
        try {
            const fallbackStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'user' }, ...getVideoQualityResolution() }
            });
            const fallbackTrack = fallbackStream.getVideoTracks()[0];
            if (fallbackTrack && localMediaStream) {
                localMediaStream.addTrack(fallbackTrack);
                await syncAllVideoElements();
            }
        } catch {}
    } finally {
        isSwitchingCamera.value = false;
    }
};

// =========================================================================
// SCREEN SHARING (Display Media API & Dynamic Track Replacement)
// =========================================================================
const toggleScreenShare = async () => {
    if (!isCalling.value) return;

    if (isScreenSharing.value) {
        await stopScreenShare();
        return;
    }

    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getDisplayMedia) {
        callError.value = 'مرورگر یا دستگاه شما از اشتراک‌گذاری صفحه نمایش پشتیبانی نمی‌کند. در موبایل از وایت‌برد تعاملی استفاده فرمایید.';
        return;
    }

    const isMobileDevice = typeof navigator !== 'undefined' && /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const isIOS = typeof navigator !== 'undefined' && (/iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));

    if (isIOS) {
        callError.value = 'سیستم‌عامل iOS و سافاری اجازه اشتراک مستقیم صفحه در وب را نمی‌دهند. برای اشتراک یادداشت و ترسیمات از «وایت‌برد تعاملی» استفاده فرمایید.';
        return;
    }

    try {
        // Mobile-safe display media options: omit desktop-only parameters that cause TypeError / OverconstrainedError on mobile browsers
        const displayOptions: DisplayMediaStreamOptions = isMobileDevice
            ? ({ video: true, audio: false } as any)
            : ({ video: { cursor: 'always' } as any, audio: false } as any);

        const stream = await navigator.mediaDevices.getDisplayMedia(displayOptions);

        const screenTrack = stream.getVideoTracks()[0];
        if (!screenTrack) return;

        screenMediaStream = stream;
        isScreenSharing.value = true;
        isVideoCall.value = true;

        // Auto-revert when user clicks browser native stop sharing pill
        screenTrack.onended = () => {
            stopScreenShare();
        };

        // Cache original camera track if one was active
        const currentVideoTracks = localMediaStream ? localMediaStream.getVideoTracks() : [];
        if (currentVideoTracks.length > 0) {
            originalCameraTrack = currentVideoTracks[0];
            localMediaStream?.removeTrack(originalCameraTrack);
        }

        if (localMediaStream) {
            localMediaStream.addTrack(screenTrack);
        } else {
            localMediaStream = new MediaStream([screenTrack]);
        }

        await syncAllVideoElements();

        // Swap on WebRTC peer connection
        if (peerConnection) {
            let videoSender = peerConnection.getSenders().find(s => s.track?.kind === 'video' || (s as any).kind === 'video');
            if (!videoSender && videoTransceiverInstance) {
                videoSender = videoTransceiverInstance.sender;
            }
            if (videoSender) {
                await videoSender.replaceTrack(screenTrack);
                if (videoTransceiverInstance) {
                    videoTransceiverInstance.direction = 'sendrecv';
                }
            } else {
                peerConnection.addTrack(screenTrack, localMediaStream!);
            }
            await renegotiatePeerConnection();
        }

        await sendSignalingMessage('screen_share_status', { is_sharing: true });
    } catch (err: any) {
        if (err.name !== 'NotAllowedError') {
            console.error('[ScreenShare] Error:', err);
            if (isMobileDevice) {
                callError.value = 'اشتراک صفحه در این دستگاه موبایل توسط سیستم‌عامل محدود شده است. می‌توانید به جای آن از وایت‌برد تعاملی استفاده فرمایید.';
            } else {
                callError.value = 'امکان اشتراک‌گذاری صفحه نمایش مقدور نشد.';
            }
        }
        isScreenSharing.value = false;
    }
};

const stopScreenShare = async () => {
    if (!isScreenSharing.value && !screenMediaStream) return;

    if (screenMediaStream) {
        screenMediaStream.getTracks().forEach(t => t.stop());
        screenMediaStream = null;
    }

    isScreenSharing.value = false;

    // Remove ended screen track from local stream
    if (localMediaStream) {
        const screenTracks = localMediaStream.getVideoTracks().filter(t => t.readyState === 'ended' || t.label.toLowerCase().includes('screen') || t !== originalCameraTrack);
        screenTracks.forEach(t => {
            t.stop();
            localMediaStream?.removeTrack(t);
        });
    }

    let restoreTrack = originalCameraTrack;
    if (restoreTrack && restoreTrack.readyState === 'live') {
        localMediaStream?.addTrack(restoreTrack);
    } else if (isCameraOn.value) {
        try {
            const camStream = await navigator.mediaDevices.getUserMedia({
                video: getVideoConstraints(),
            });
            restoreTrack = camStream.getVideoTracks()[0];
            if (restoreTrack && localMediaStream) {
                localMediaStream.addTrack(restoreTrack);
            }
        } catch {}
    }

    originalCameraTrack = null;
    await syncAllVideoElements();

    if (peerConnection) {
        let videoSender = peerConnection.getSenders().find(s => s.track?.kind === 'video' || (s as any).kind === 'video');
        if (!videoSender && videoTransceiverInstance) {
            videoSender = videoTransceiverInstance.sender;
        }
        if (videoSender) {
            await videoSender.replaceTrack(restoreTrack || null);
        }
        if (videoTransceiverInstance) {
            videoTransceiverInstance.direction = restoreTrack ? 'sendrecv' : (isRemoteVideoStreaming.value ? 'recvonly' : 'inactive');
        }
        await renegotiatePeerConnection();
    }

    await sendSignalingMessage('screen_share_status', { is_sharing: false });

    // If camera is off and remote side is not streaming video or whiteboard, collapse back to audio
    if (!isCameraOn.value && !isRemoteScreenSharing.value && !isRemoteVideoStreaming.value && !isWhiteboardOpen.value && !isRemoteWhiteboardOpen.value) {
        isVideoCall.value = false;
    }
};

// =========================================================================
// INTERACTIVE WHITEBOARD (Canvas, WebRTC DataChannel & Dual Transport)
// =========================================================================
const setupWhiteboardDataChannel = (channel: RTCDataChannel) => {
    whiteboardDataChannel = channel;
    channel.onopen = () => {
        console.log('[WebRTC-DataChannel] 🟢 Whiteboard DataChannel opened successfully');
        isDataChannelReady.value = true;
        if (isWhiteboardOpen.value) {
            sendWhiteboardMessage({ type: 'wb_sync_req' });
        }
    };
    channel.onclose = () => {
        console.log('[WebRTC-DataChannel] 🔴 Whiteboard DataChannel closed');
        isDataChannelReady.value = false;
    };
    channel.onerror = (err) => {
        console.warn('[WebRTC-DataChannel] ⚠️ DataChannel error:', err);
    };
    channel.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            handleWhiteboardDataMessage(data);
        } catch (e) {
            console.warn('[WebRTC-DataChannel] Bad payload:', e);
        }
    };
};

const sendWhiteboardMessage = (msg: any) => {
    if (whiteboardDataChannel && whiteboardDataChannel.readyState === 'open') {
        try {
            whiteboardDataChannel.send(JSON.stringify(msg));
            return;
        } catch (e) {
            console.warn('[WebRTC-DataChannel] Send failed, falling back to signaling:', e);
        }
    }
    // Dual transport fallback via signaling mailbox / WebSocket
    sendSignalingMessage('whiteboard_event', msg);
};

const handleWhiteboardDataMessage = (data: any) => {
    if (!data || typeof data !== 'object') return;

    if (data.type === 'wb_open') {
        isRemoteWhiteboardOpen.value = true;
        if (!isWhiteboardOpen.value) {
            openWhiteboard(true);
        }
    } else if (data.type === 'wb_close') {
        isRemoteWhiteboardOpen.value = false;
        isWhiteboardOpen.value = false; // Also close recipient whiteboard synchronously
        if (!isCameraOn.value && !isScreenSharing.value && !isRemoteScreenSharing.value && !isRemoteVideoStreaming.value) {
            isVideoCall.value = false;
        }
    } else if (data.type === 'wb_draw') {
        drawSegment(data.from[0], data.from[1], data.to[0], data.to[1], data.tool, data.color, data.width);
        if (data.author) {
            lastDrawingAuthor.value = data.author;
        }
    } else if (data.type === 'wb_cursor') {
        remotePeerCursor.value = {
            x: data.x,
            y: data.y,
            visible: true,
            name: data.author || 'طرف مقابل',
        };
        if (remoteCursorHideTimer) clearTimeout(remoteCursorHideTimer);
        remoteCursorHideTimer = setTimeout(() => {
            remotePeerCursor.value.visible = false;
        }, 3000);
    } else if (data.type === 'wb_clear') {
        clearCanvasLocal();
    } else if (data.type === 'wb_undo' && data.image) {
        restoreCanvasFromDataUrl(data.image, false);
    } else if (data.type === 'wb_sync_req') {
        const canvas = whiteboardCanvasRef.value;
        if (canvas) {
            sendWhiteboardMessage({ type: 'wb_sync_res', image: canvas.toDataURL() });
        }
    } else if (data.type === 'wb_sync_res' && data.image) {
        restoreCanvasFromDataUrl(data.image, false);
    }
};

let lastCursorBroadcastTime = 0;
const broadcastCursorLocation = (nx: number, ny: number) => {
    const now = Date.now();
    if (now - lastCursorBroadcastTime > 80) {
        lastCursorBroadcastTime = now;
        sendWhiteboardMessage({
            type: 'wb_cursor',
            x: nx,
            y: ny,
            author: localUserName.value,
        });
    }
};

const getNormalizedCoords = (e: MouseEvent | Touch, canvas: HTMLCanvasElement) => {
    const rect = canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    const nx = Math.max(0, Math.min(1, x / rect.width));
    const ny = Math.max(0, Math.min(1, y / rect.height));
    return { nx, ny };
};

const drawSegment = (
    fromNx: number,
    fromNy: number,
    toNx: number,
    toNy: number,
    tool: 'pen' | 'highlighter' | 'eraser',
    color: string,
    width: number
) => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const rect = canvas.getBoundingClientRect();
    const fromX = fromNx * rect.width;
    const fromY = fromNy * rect.height;
    const toX = toNx * rect.width;
    const toY = toNy * rect.height;

    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    if (tool === 'eraser') {
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = width * 4;
        ctx.globalAlpha = 1.0;
        ctx.globalCompositeOperation = 'source-over';
    } else if (tool === 'highlighter') {
        ctx.strokeStyle = color;
        ctx.lineWidth = width * 3;
        ctx.globalAlpha = 0.35;
        ctx.globalCompositeOperation = 'source-over';
    } else {
        ctx.strokeStyle = color;
        ctx.lineWidth = width;
        ctx.globalAlpha = 1.0;
        ctx.globalCompositeOperation = 'source-over';
    }

    ctx.beginPath();
    ctx.moveTo(fromX, fromY);
    ctx.lineTo(toX, toY);
    ctx.stroke();
    ctx.restore();
};

const clearCanvasLocal = () => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const rect = canvas.getBoundingClientRect();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, rect.width, rect.height);
};

const clearWhiteboard = () => {
    clearCanvasLocal();
    saveUndoState();
    sendWhiteboardMessage({ type: 'wb_clear' });
};

const saveUndoState = () => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const dataUrl = canvas.toDataURL();
    if (whiteboardUndoStack.value.length > 25) {
        whiteboardUndoStack.value.shift();
    }
    whiteboardUndoStack.value.push(dataUrl);
};

const undoWhiteboard = () => {
    if (whiteboardUndoStack.value.length <= 1) {
        clearWhiteboard();
        return;
    }
    whiteboardUndoStack.value.pop();
    const prev = whiteboardUndoStack.value[whiteboardUndoStack.value.length - 1];
    if (prev) {
        restoreCanvasFromDataUrl(prev, false);
        sendWhiteboardMessage({ type: 'wb_undo', image: prev });
    }
};

const restoreCanvasFromDataUrl = (dataUrl: string, pushUndo = true) => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const img = new Image();
    img.onload = () => {
        const rect = canvas.getBoundingClientRect();
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, rect.width, rect.height);
        ctx.drawImage(img, 0, 0, rect.width, rect.height);
        if (pushUndo) {
            saveUndoState();
        }
    };
    img.src = dataUrl;
};

const saveWhiteboardImage = () => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const link = document.createElement('a');
    link.download = `whiteboard-${activeCallId.value || 'session'}-${Date.now()}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
};

const onCanvasMouseDown = (e: MouseEvent) => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    isDrawingOnCanvas = true;
    const { nx, ny } = getNormalizedCoords(e, canvas);
    lastCanvasNx = nx;
    lastCanvasNy = ny;
    const authorTag = localUserName.value;
    lastDrawingAuthor.value = `شما (${authorTag})`;
    drawSegment(nx, ny, nx + 0.0001, ny + 0.0001, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);
    sendWhiteboardMessage({
        type: 'wb_draw',
        tool: whiteboardTool.value,
        color: whiteboardColor.value,
        width: whiteboardLineWidth.value,
        from: [nx, ny],
        to: [nx + 0.0001, ny + 0.0001],
        author: authorTag,
    });
};

const onCanvasMouseMove = (e: MouseEvent) => {
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const { nx, ny } = getNormalizedCoords(e, canvas);
    broadcastCursorLocation(nx, ny);

    if (!isDrawingOnCanvas) return;
    const authorTag = localUserName.value;
    lastDrawingAuthor.value = `شما (${authorTag})`;
    drawSegment(lastCanvasNx, lastCanvasNy, nx, ny, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);
    sendWhiteboardMessage({
        type: 'wb_draw',
        tool: whiteboardTool.value,
        color: whiteboardColor.value,
        width: whiteboardLineWidth.value,
        from: [lastCanvasNx, lastCanvasNy],
        to: [nx, ny],
        author: authorTag,
    });
    lastCanvasNx = nx;
    lastCanvasNy = ny;
};

const onCanvasMouseUp = () => {
    if (!isDrawingOnCanvas) return;
    isDrawingOnCanvas = false;
    saveUndoState();
};

const handleTouchStart = (e: TouchEvent) => {
    e.preventDefault();
    if (e.touches.length !== 1) return;
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    isDrawingOnCanvas = true;
    const { nx, ny } = getNormalizedCoords(e.touches[0], canvas);
    lastCanvasNx = nx;
    lastCanvasNy = ny;
    const authorTag = localUserName.value;
    lastDrawingAuthor.value = `شما (${authorTag})`;
    drawSegment(nx, ny, nx + 0.0001, ny + 0.0001, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);
    sendWhiteboardMessage({
        type: 'wb_draw',
        tool: whiteboardTool.value,
        color: whiteboardColor.value,
        width: whiteboardLineWidth.value,
        from: [nx, ny],
        to: [nx + 0.0001, ny + 0.0001],
        author: authorTag,
    });
};

const handleTouchMove = (e: TouchEvent) => {
    e.preventDefault();
    if (e.touches.length !== 1) return;
    const canvas = whiteboardCanvasRef.value;
    if (!canvas) return;
    const { nx, ny } = getNormalizedCoords(e.touches[0], canvas);
    broadcastCursorLocation(nx, ny);

    if (!isDrawingOnCanvas) return;
    const authorTag = localUserName.value;
    lastDrawingAuthor.value = `شما (${authorTag})`;
    drawSegment(lastCanvasNx, lastCanvasNy, nx, ny, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);
    sendWhiteboardMessage({
        type: 'wb_draw',
        tool: whiteboardTool.value,
        color: whiteboardColor.value,
        width: whiteboardLineWidth.value,
        from: [lastCanvasNx, lastCanvasNy],
        to: [nx, ny],
        author: authorTag,
    });
    lastCanvasNx = nx;
    lastCanvasNy = ny;
};

const handleTouchEnd = (e: TouchEvent) => {
    e.preventDefault();
    if (!isDrawingOnCanvas) return;
    isDrawingOnCanvas = false;
    saveUndoState();
};

const setupCanvas = () => {
    nextTick(() => {
        const canvas = whiteboardCanvasRef.value;
        if (!canvas) return;
        const rect = canvas.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const oldImage = canvas.toDataURL();

        canvas.width = Math.round(rect.width * dpr);
        canvas.height = Math.round(rect.height * dpr);
        const ctx = canvas.getContext('2d');
        if (ctx) {
            ctx.scale(dpr, dpr);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, rect.width, rect.height);
            if (oldImage && oldImage.length > 100) {
                const img = new Image();
                img.onload = () => {
                    ctx.drawImage(img, 0, 0, rect.width, rect.height);
                };
                img.src = oldImage;
            }
        }
        if (whiteboardUndoStack.value.length === 0) {
            saveUndoState();
        }

        canvas.removeEventListener('touchstart', handleTouchStart as any);
        canvas.removeEventListener('touchmove', handleTouchMove as any);
        canvas.removeEventListener('touchend', handleTouchEnd as any);
        canvas.addEventListener('touchstart', handleTouchStart as any, { passive: false });
        canvas.addEventListener('touchmove', handleTouchMove as any, { passive: false });
        canvas.addEventListener('touchend', handleTouchEnd as any, { passive: false });

        syncAllVideoElements();
    });
};

const toggleExpandedWorkspace = () => {
    isExpandedWorkspace.value = !isExpandedWorkspace.value;
    if (isWhiteboardOpen.value) {
        setTimeout(setupCanvas, 250);
    }
};

const openWhiteboard = (fromRemote = false) => {
    isWhiteboardOpen.value = true;
    setupCanvas();
    if (!fromRemote) {
        sendWhiteboardMessage({ type: 'wb_open' });
    } else {
        setTimeout(() => {
            sendWhiteboardMessage({ type: 'wb_sync_req' });
        }, 300);
    }
    syncAllVideoElements();
};

const closeWhiteboard = () => {
    isWhiteboardOpen.value = false;
    isRemoteWhiteboardOpen.value = false;
    sendWhiteboardMessage({ type: 'wb_close' });
    if (!isCameraOn.value && !isScreenSharing.value && !isRemoteScreenSharing.value && !isRemoteVideoStreaming.value) {
        isVideoCall.value = false;
    }
};

const toggleWhiteboard = () => {
    if (isWhiteboardOpen.value) {
        closeWhiteboard();
    } else {
        openWhiteboard();
    }
};

const toggleWhiteboardCamera = async () => {
    await toggleCamera();
    isWhiteboardCameraActive.value = isCameraOn.value;
    syncAllVideoElements();
};

watch([isWhiteboardOpen, isWhiteboardCameraActive], async ([isOpen, isCamActive]) => {
    if (isOpen && isCamActive) {
        await nextTick();
        if (whiteboardPipVideo.value && localMediaStream) {
            whiteboardPipVideo.value.srcObject = localMediaStream;
            whiteboardPipVideo.value.play().catch(() => {});
        }
    }
});

// Change Video Resolution Quality
const setVideoQuality = async (q: 'hd' | 'sd' | 'low') => {
    videoQuality.value = q;
    if (!localMediaStream) return;
    const videoTracks = localMediaStream.getVideoTracks();
    if (videoTracks.length > 0) {
        try {
            await videoTracks[0].applyConstraints(getVideoConstraints());
        } catch (e) {
            console.warn('[WebRTC-Video] Could not apply video constraints:', e);
        }
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
                });
                console.log('[WebRTC] 🎧 Live track noise cancellation updated:', isNoiseCancellationEnabled.value);
            } catch (err) {
                console.warn('[WebRTC] ⚠️ Failed applying live track noise cancellation constraints:', err);
            }
        }
    }
};

// Enhance Opus SDP with In-Band FEC & DTX safely without corrupting SDP structure
const enhanceOpusSdp = (sdp?: string): string => {
    if (!sdp || !sdp.includes('opus/48000')) {
        return sdp || '';
    }
    try {
        return sdp.replace(/(a=fmtp:\d+\s+[^;\r\n]+)/gi, (match) => {
            let updated = match;
            if (!updated.includes('useinbandfec=')) updated += ';useinbandfec=1';
            if (!updated.includes('usedtx=')) updated += ';usedtx=1';
            return updated;
        });
    } catch {
        return sdp;
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

    // 1. Go WebSocket bridge signaling
    if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN && bridgeSessionId.value) {
        try {
            bridgeSocket.send(rawWsPayload);
            console.log('[WebRTC-WS] 📤 Sent signaling message directly via WebSocket:', type);
        } catch (e) {
            console.warn('[WebRTC-WS] ⚠️ WebSocket send error:', e);
        }
    } else if (bridgeSocket && bridgeSocket.readyState === WebSocket.CONNECTING && bridgeSessionId.value) {
        pendingWsMessages.value.push(rawWsPayload);
    }

    // 2. Atomic DB signaling mailbox in Laravel
    try {
        await axios.post(route('voice.signal', activeCallId.value), {
            type: type,
            payload: payload,
        });
    } catch (e) {
        console.warn('[WebRTC-DB] ⚠️ DB signaling mailbox error:', e);
    }
};

let pendingCandidates: RTCIceCandidateInit[] = [];

// Real-time Candidate & Route Evaluation
const detectOptimalRoute = (candidate: RTCIceCandidate) => {
    let routeDesc = '';
    if (candidate.type === 'relay') {
        routeDesc = 'سرور رله امن';
    } else if (candidate.type === 'srflx') {
        routeDesc = 'ارتباط اینترنتی مستقیم';
    } else if (candidate.type === 'host') {
        routeDesc = 'اتصال مستقیم همتا‌به‌همتا';
    }
    if (routeDesc && (!optimalRouteInfo.value || candidate.type === 'relay')) {
        optimalRouteInfo.value = routeDesc;
        sendSignalingMessage('route_selected', {
            type: candidate.type,
            candidate: candidate.candidate,
            route: routeDesc,
        });
    }
};

// Call duration timer: strictly starts only when media is connected
const startCallTimer = () => {
    if (callTimer) return;
    callDuration.value = 0;
    callTimer = setInterval(() => {
        callDuration.value++;
    }, 1000);
};

const stopCallTimer = () => {
    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }
};

const createPeerConnection = (isCaller = false) => {
    if (peerConnection) {
        try {
            peerConnection.close();
        } catch {}
        peerConnection = null;
    }
    pendingCandidates = [];
    mediaConnectionState.value = 'connecting';

    const pc = new RTCPeerConnection(buildRtcConfiguration());

    // 1. Audio Track / Transceiver FIRST (m=audio, mid 0)
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

    // 2. Video Transceiver SECOND (m=video, mid 1)
    const videoTracks = localMediaStream ? localMediaStream.getVideoTracks() : [];
    try {
        if (videoTracks.length > 0 && localMediaStream) {
            videoTransceiverInstance = pc.addTransceiver(videoTracks[0], {
                direction: 'sendrecv',
                streams: [localMediaStream],
            });
        } else {
            videoTransceiverInstance = pc.addTransceiver('video', {
                direction: (isVideoCall.value || isCameraOn.value) ? 'sendrecv' : 'recvonly',
            });
        }
    } catch (e) {
        console.warn('[WebRTC] Video transceiver setup fallback:', e);
        if (videoTracks.length > 0 && localMediaStream) {
            videoTracks.forEach(track => {
                try { pc.addTrack(track, localMediaStream!); } catch {}
            });
        }
    }

    // 3. DataChannel for whiteboard THIRD (m=application, mid 2) - caller creates it, callee receives via ondatachannel
    if (isCaller) {
        try {
            whiteboardDataChannel = pc.createDataChannel('whiteboard', { ordered: true });
            setupWhiteboardDataChannel(whiteboardDataChannel);
        } catch (e) {
            console.warn('[WebRTC] DataChannel creation warning:', e);
        }
    }

    pc.ondatachannel = (event) => {
        if (event.channel.label === 'whiteboard') {
            setupWhiteboardDataChannel(event.channel);
        }
    };

    // Connect remote incoming audio and video streams
    pc.ontrack = async (event) => {
        console.log('[WebRTC] 🎧 Incoming track:', event.track.kind, 'id:', event.track.id, 'readyState:', event.track.readyState);

        if (event.track.kind === 'video') {
            isRemoteVideoStreaming.value = true;
            isVideoCall.value = true;

            // Dedicated clean video MediaStream directly containing the remote video track
            if (!remoteVideoStream) {
                remoteVideoStream = new MediaStream([event.track]);
            } else {
                const curTracks = remoteVideoStream.getVideoTracks();
                curTracks.forEach(t => {
                    if (t.id !== event.track.id) {
                        remoteVideoStream?.removeTrack(t);
                    }
                });
                if (!remoteVideoStream.getVideoTracks().some(t => t.id === event.track.id)) {
                    remoteVideoStream.addTrack(event.track);
                }
            }

            if (!remoteMediaStream) {
                remoteMediaStream = new MediaStream();
            }
            if (!remoteMediaStream.getVideoTracks().some(t => t.id === event.track.id)) {
                remoteMediaStream.addTrack(event.track);
            }

            await syncAllVideoElements();

            event.track.onunmute = () => {
                console.log('[WebRTC] 📹 Remote video track unmuted:', event.track.id);
                isRemoteVideoStreaming.value = true;
                syncAllVideoElements();
            };
            event.track.onended = () => {
                console.log('[WebRTC] 📹 Remote video track ended:', event.track.id);
                isRemoteVideoStreaming.value = false;
                if (!isCameraOn.value && !isScreenSharing.value && !isRemoteScreenSharing.value && !isWhiteboardOpen.value && !isRemoteWhiteboardOpen.value) {
                    isVideoCall.value = false;
                }
            };
        } else if (event.track.kind === 'audio') {
            const audioStream = event.streams && event.streams[0] ? event.streams[0] : new MediaStream([event.track]);
            if (!remoteMediaStream) {
                remoteMediaStream = audioStream;
            } else if (!remoteMediaStream.getAudioTracks().some(t => t.id === event.track.id)) {
                try {
                    remoteMediaStream.addTrack(event.track);
                } catch {}
            }

            if (remoteAudio.value) {
                remoteAudio.value.srcObject = audioStream;
                remoteAudio.value.muted = false;
                remoteAudio.value.volume = isSpeakerOn.value ? 1.0 : 0.35;
                remoteAudio.value.play()
                    .then(() => {
                        isAudioPlaybackBlocked.value = false;
                    })
                    .catch(e => {
                        console.warn('Audio autoplay blocked by browser:', e);
                        isAudioPlaybackBlocked.value = true;
                    });
            }
            initWebAudioRouting(audioStream);

            event.track.onunmute = () => {
                if (remoteAudio.value) {
                    remoteAudio.value.play().catch(() => {});
                }
            };
        }
    };

    // Auto-renegotiate removed: transceivers and replaceTrack handle track switching seamlessly without SDP renegotiation glare
    pc.onnegotiationneeded = null;

    // Process queued renegotiations on stable signaling state
    pc.onsignalingstatechange = () => {
        console.log('[WebRTC] 🔄 Signaling State:', pc.signalingState);
        if (pc.signalingState === 'stable' && renegotiationQueued) {
            renegotiationQueued = false;
            setTimeout(renegotiatePeerConnection, 100);
        }
    };

    // Relay local ICE candidates
    pc.onicecandidate = (event) => {
        if (event.candidate) {
            detectOptimalRoute(event.candidate);
            sendSignalingMessage('ice_candidate', event.candidate.toJSON());
        }
    };

    const updateStateFromPc = () => {
        const st = pc.iceConnectionState;
        console.log('[WebRTC] 🔄 ICE State:', st, '| Connection:', pc.connectionState, '| Signaling:', pc.signalingState);
        if (st === 'connected' || st === 'completed' || pc.connectionState === 'connected') {
            mediaConnectionState.value = 'connected';
            isBridgeConnected.value = true;
            isNetworkDisconnected.value = false;
            isPeerReconnecting.value = false;
            startCallTimer();
            syncAllVideoElements();
            if (remoteVideo.value) {
                remoteVideo.value.play().catch(() => {});
            }
        } else if (st === 'failed' || pc.connectionState === 'failed') {
            mediaConnectionState.value = 'failed';
            isNetworkDisconnected.value = true;
            const hasTurn = Boolean((activeRtcConfig.value || props.rtc_config)?.hasTurn);
            if (!hasTurn) {
                callError.value = __('user.calls.turn_required_error');
            }
            setTimeout(() => {
                if (isCalling.value) performIceRestart();
            }, 1000);
        } else if (st === 'disconnected') {
            mediaConnectionState.value = 'disconnected';
            isNetworkDisconnected.value = true;
            setTimeout(() => {
                if (isCalling.value && pc.iceConnectionState === 'disconnected') {
                    performIceRestart();
                }
            }, 2500);
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

    try {
        const stateEvents = ['camera_status', 'screen_share_status', 'whiteboard_event', 'end_call', 'call_ended'];
        if (!stateEvents.includes(type)) {
            const sigKey = `${type}:${typeof payload === 'string' ? payload : JSON.stringify(payload)}`;
            if (processedSignalHashes.has(sigKey)) {
                return;
            }
            processedSignalHashes.add(sigKey);
        }
    } catch {}

    try {
        if (type === 'route_selected' && payload?.route) {
            optimalRouteInfo.value = payload.route;
        } else if (type === 'screen_share_status') {
            isRemoteScreenSharing.value = Boolean(payload?.is_sharing);
        } else if (type === 'camera_status') {
            const isCam = Boolean(payload?.is_camera_on);
            isRemoteVideoStreaming.value = isCam;
            if (isCam) {
                isVideoCall.value = true;
                syncAllVideoElements();
            } else {
                remoteVideoStream = null;
                if (remoteVideo.value && !isRemoteScreenSharing.value) {
                    remoteVideo.value.srcObject = null;
                }
                if (whiteboardRemoteVideo.value) {
                    whiteboardRemoteVideo.value.srcObject = null;
                }
                if (!isCameraOn.value && !isScreenSharing.value && !isRemoteScreenSharing.value && !isWhiteboardOpen.value && !isRemoteWhiteboardOpen.value) {
                    isVideoCall.value = false;
                }
            }
        } else if (type === 'whiteboard_event') {
            handleWhiteboardDataMessage(payload);
        } else if (type === 'offer') {
            const offerData = typeof payload === 'string' ? JSON.parse(payload) : payload;
            if (peerConnection.signalingState !== 'stable') {
                try {
                    await peerConnection.setLocalDescription({ type: 'rollback' } as RTCSessionDescriptionInit);
                } catch {}
            }
            await peerConnection.setRemoteDescription(new RTCSessionDescription(offerData));
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
            syncAllVideoElements();
        } else if (type === 'answer') {
            const answerData = typeof payload === 'string' ? JSON.parse(payload) : payload;
            if (peerConnection.signalingState === 'have-local-offer') {
                await peerConnection.setRemoteDescription(new RTCSessionDescription(answerData));
                for (const cand of pendingCandidates) {
                    try {
                        await peerConnection.addIceCandidate(new RTCIceCandidate(cand));
                    } catch {}
                }
                pendingCandidates = [];
                syncAllVideoElements();
                if (renegotiationQueued) {
                    renegotiationQueued = false;
                    setTimeout(renegotiatePeerConnection, 100);
                }
            }
        } else if (type === 'ice_candidate') {
            const candData = typeof payload === 'string' ? JSON.parse(payload) : payload;
            if (!peerConnection.remoteDescription || !peerConnection.remoteDescription.type) {
                pendingCandidates.push(candData);
            } else {
                try {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(candData));
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

// Perform WebRTC ICE Restart and Media Track Recovery
const performIceRestart = async () => {
    if (!peerConnection || !isCalling.value) return;
    if (iceRestartDebounceTimer) return;
    iceRestartDebounceTimer = setTimeout(() => {
        iceRestartDebounceTimer = null;
    }, 2000);

    console.log('[WebRTC] 🔄 Performing ICE restart and renegotiating media tracks...');
    try {
        if (localMediaStream) {
            const senders = peerConnection.getSenders();
            localMediaStream.getTracks().forEach(track => {
                if (track.readyState === 'live') {
                    const sender = senders.find(s => s.track?.kind === track.kind);
                    if (sender) {
                        sender.replaceTrack(track).catch(() => {});
                    }
                }
            });
        }

        const offer = await peerConnection.createOffer({ iceRestart: true });
        if (offer.sdp) {
            offer.sdp = enhanceOpusSdp(offer.sdp);
        }
        await peerConnection.setLocalDescription(offer);
        await sendSignalingMessage('offer', offer);

        if (remoteVideo.value) {
            remoteVideo.value.play().catch(() => {});
        }
        if (remoteAudio.value) {
            remoteAudio.value.play().catch(() => {});
        }
    } catch (e) {
        console.warn('[WebRTC] ICE restart failed:', e);
    }
};

const scheduleWsReconnect = () => {
    if (!isCalling.value || !bridgeWsEndpoint.value) return;
    if (wsReconnectTimer) return;
    wsReconnectTimer = setTimeout(() => {
        wsReconnectTimer = null;
        if (isCalling.value && bridgeWsEndpoint.value && (!bridgeSocket || bridgeSocket.readyState === WebSocket.CLOSED || bridgeSocket.readyState === WebSocket.CLOSING)) {
            console.log('[WebRTC-WS] 🔄 Retrying connection to voice bridge...');
            connectBridgeWebSocket(bridgeWsEndpoint.value);
        }
    }, 1500);
};

// WebSocket signaling connection to Voice Bridge
const connectBridgeWebSocket = (wsUrl: string) => {
    try {
        if (bridgeSocket) {
            bridgeSocket.close();
            bridgeSocket = null;
        }

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
        bridgeSocket.onopen = () => {
            if (wsReconnectTimer) {
                clearTimeout(wsReconnectTimer);
                wsReconnectTimer = null;
            }
            isBridgeConnected.value = true;
            isNetworkDisconnected.value = false;
            if (bridgeSessionId.value) {
                bridgeSocket?.send(JSON.stringify({
                    type: 'join_session',
                    session_id: bridgeSessionId.value,
                }));
            }
            while (pendingWsMessages.value.length > 0) {
                const nextMsg = pendingWsMessages.value.shift();
                if (nextMsg && bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
                    try {
                        bridgeSocket.send(nextMsg);
                    } catch {}
                }
            }
            if (bridgePingInterval) clearInterval(bridgePingInterval);
            bridgePingInterval = setInterval(() => {
                if (bridgeSocket && bridgeSocket.readyState === WebSocket.OPEN) {
                    bridgeSocket.send(JSON.stringify({
                        type: 'ping',
                        client_time: Date.now(),
                        session_id: bridgeSessionId.value || undefined,
                    }));
                }
            }, 2500);

            // If reconnecting while peer connection was interrupted, trigger ICE restart to rebind media
            if (peerConnection && (mediaConnectionState.value === 'disconnected' || mediaConnectionState.value === 'failed' || isNetworkDisconnected.value)) {
                setTimeout(performIceRestart, 400);
            }
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
                } else if (msg.type === 'channel_connected' || msg.type === 'peer_reconnected') {
                    isBridgeConnected.value = true;
                    isPeerReconnecting.value = false;
                    isNetworkDisconnected.value = false;
                    mediaConnectionState.value = 'connected';
                    startCallTimer();
                    if (remoteVideo.value) {
                        remoteVideo.value.play().catch(() => {});
                    }
                } else if (msg.type === 'peer_reconnecting') {
                    isPeerReconnecting.value = true;
                } else if (msg.type === 'call_ended') {
                    callError.value = __('user.calls.call_ended_by_peer');
                    endCall();
                } else if (msg.type === 'offer' || msg.type === 'answer' || msg.type === 'ice_candidate' || msg.type === 'route_selected' || msg.type === 'screen_share_status' || msg.type === 'camera_status' || msg.type === 'whiteboard_event') {
                    handleSignalingPayload(msg.type, msg.data || msg.payload);
                }
            } catch (e) {
                console.warn('[WebRTC-WS] ⚠️ Error parsing WebSocket message:', e);
            }
        };
        bridgeSocket.onerror = () => {
            isBridgeConnected.value = false;
            scheduleWsReconnect();
        };
        bridgeSocket.onclose = () => {
            isBridgeConnected.value = false;
            if (bridgePingInterval) clearInterval(bridgePingInterval);
            scheduleWsReconnect();
        };
    } catch {
        isBridgeConnected.value = false;
        scheduleWsReconnect();
    }
};

// Start Voice or Video Call
const startCall = async (skipMicCheck = false, withVideo = false) => {
    const cleanDest = getNormalizedDestination();
    if (!cleanDest || !selectedLine.value || isCalling.value) return;

    resetMediaControlStates();
    callError.value = '';
    lastSignalId = 0;
    processedSignalHashes.clear();
    pendingWsMessages.value = [];
    isAudioPlaybackBlocked.value = false;
    mediaConnectionState.value = 'new';
    optimalRouteInfo.value = '';
    isVideoCall.value = withVideo;
    
    // Check & request media permission
    if (!skipMicCheck) {
        const hasMedia = await requestMediaAccess(withVideo);
        if (!hasMedia) return;
    }

    if (isMicModalOpen.value) {
        isMicModalOpen.value = false;
    }

    isCalling.value = true;
    callDuration.value = 0;
    acquireWakeLock();

    if (remoteAudio.value) {
        remoteAudio.value.muted = false;
        remoteAudio.value.play().catch(() => {});
    }

    try {
        const res = await axios.post(route('voice.initiate'), {
            line_key: selectedLineKey.value || undefined,
            phone_number_id: selectedLine.value?.id,
            destination: cleanDest,
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

            // Establish WebRTC Peer Connection as Caller
            const pc = createPeerConnection(true);
            const offer = await pc.createOffer();
            if (offer.sdp) {
                offer.sdp = enhanceOpusSdp(offer.sdp);
            }
            await pc.setLocalDescription(offer);
            await sendSignalingMessage('offer', offer);

            // Periodic Balance & Active Status Heartbeat
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

            // WebRTC Signaling Queue Poll
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

// End Call (Mutual Hangup)
const endCall = async () => {
    if (signalPollTimer) {
        clearInterval(signalPollTimer);
        signalPollTimer = null;
    }
    if (pulseTimer) {
        clearInterval(pulseTimer);
        pulseTimer = null;
    }
    stopCallTimer();
    if (pingTimer) {
        clearInterval(pingTimer);
        pingTimer = null;
    }
    if (bridgePingInterval) {
        clearInterval(bridgePingInterval);
        bridgePingInterval = null;
    }

    if (activeCallId.value) {
        sendSignalingMessage('end_call', { reason: 'user_hangup' });
    }

    if (isScreenSharing.value) {
        await stopScreenShare();
    }
    if (isWhiteboardOpen.value) {
        closeWhiteboard();
    }
    if (whiteboardDataChannel) {
        try { whiteboardDataChannel.close(); } catch {}
        whiteboardDataChannel = null;
    }
    isDataChannelReady.value = false;
    isRemoteScreenSharing.value = false;
    isRemoteWhiteboardOpen.value = false;

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
    if (remoteVideo.value) {
        remoteVideo.value.srcObject = null;
    }
    if (whiteboardRemoteVideo.value) {
        whiteboardRemoteVideo.value.srcObject = null;
    }
    if (localVideo.value) {
        localVideo.value.srcObject = null;
    }
    if (whiteboardPipVideo.value) {
        whiteboardPipVideo.value.srcObject = null;
    }
    remoteVideoStream = null;
    isRenegotiating = false;
    renegotiationQueued = false;
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
    isCameraOn.value = false;
    isVideoCall.value = false;
    isRemoteVideoStreaming.value = false;
    isExpandedWorkspace.value = false;
    optimalRouteInfo.value = '';

    const terminatingCallId = activeCallId.value;
    activeCallId.value = null;
    isCalling.value = false;
    const finalDuration = callDuration.value;
    callDuration.value = 0;
    resetMediaControlStates();
    releaseWakeLock();

    if (!terminatingCallId) return;

    try {
        await axios.post(route('voice.end', terminatingCallId), {
            duration_seconds: finalDuration,
        });
    } catch {
    } finally {
        router.reload({ only: ['calls', 'wallet_balance'] });
    }
};

// Incoming Call Sound & Ringtone
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

const answerIncomingCall = async (withVideo = false) => {
    if (!incomingCall.value) return;
    stopRingtone();
    resetMediaControlStates();
    lastSignalId = 0;
    processedSignalHashes.clear();
    pendingWsMessages.value = [];
    isAudioPlaybackBlocked.value = false;
    mediaConnectionState.value = 'new';
    optimalRouteInfo.value = '';
    isVideoCall.value = withVideo;

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
            acquireWakeLock();
            pingMs.value = answeredCall.ping_ms || incomingCall.value.ping_ms || 22;
            bridgeSessionId.value = answeredCall.bridge_session_id || incomingCall.value.bridge_session_id || null;
            bridgeWsEndpoint.value = answeredCall.bridge_ws_endpoint || incomingCall.value.bridge_ws_endpoint || null;
            isBridgeConnected.value = Boolean(bridgeSessionId.value);
            isInternalCall.value = true;
            if (answeredCall.rtc_config) {
                activeRtcConfig.value = answeredCall.rtc_config;
            }

            await requestMediaAccess(withVideo);

            createPeerConnection(false);

            if (bridgeWsEndpoint.value) {
                connectBridgeWebSocket(bridgeWsEndpoint.value);
            }

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
    setTab('dialer');
};

const setTab = (tab: 'dialer' | 'history') => {
    activeTab.value = tab;
    if (typeof window !== 'undefined') {
        const url = new URL(window.location.href);
        if (tab === 'history') {
            url.searchParams.set('tab', 'history');
        } else {
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url.toString());
    }
};

const navigateHistoryPage = (url: string | null) => {
    if (!url) return;
    const urlObj = new URL(url, window.location.origin);
    urlObj.searchParams.set('tab', 'history');
    activeTab.value = 'history';
    router.visit(urlObj.toString(), {
        preserveState: true,
        preserveScroll: true,
        only: ['calls'],
    });
};

// Call History Selection & Soft-Deletion
const selectedCallIds = ref<number[]>([]);
const isDeletingCalls = ref(false);

const isAllCallsSelected = computed(() => {
    const list = props.calls?.data || [];
    return list.length > 0 && selectedCallIds.value.length === list.length;
});

const toggleSelectAllCalls = () => {
    const list = props.calls?.data || [];
    if (isAllCallsSelected.value) {
        selectedCallIds.value = [];
    } else {
        selectedCallIds.value = list.map(c => c.id);
    }
};

const toggleSelectCall = (id: number) => {
    const idx = selectedCallIds.value.indexOf(id);
    if (idx > -1) {
        selectedCallIds.value.splice(idx, 1);
    } else {
        selectedCallIds.value.push(id);
    }
};

const deleteSingleCall = async (id: number) => {
    if (!confirm('آیا از حذف این تماس از تاریخچه اطمینان دارید؟')) return;
    try {
        await router.delete(route('calls.destroy', id), {
            preserveScroll: true,
            onSuccess: () => {
                selectedCallIds.value = selectedCallIds.value.filter(i => i !== id);
            },
        });
    } catch (e) {
        console.error('Failed to delete call:', e);
    }
};

const deleteBulkCalls = async () => {
    if (selectedCallIds.value.length === 0) return;
    if (!confirm(`آیا از حذف ${selectedCallIds.value.length} تماس انتخابی اطمینان دارید؟`)) return;
    isDeletingCalls.value = true;
    try {
        await router.post(route('calls.destroy_bulk'), {
            ids: selectedCallIds.value,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                selectedCallIds.value = [];
            },
            onFinish: () => {
                isDeletingCalls.value = false;
            },
        });
    } catch (e) {
        console.error('Failed to bulk delete calls:', e);
        isDeletingCalls.value = false;
    }
};

const handleOnline = () => {
    console.log('[WebRTC] 🌐 Network online event detected');
    isNetworkDisconnected.value = false;
    if (isCalling.value) {
        scheduleWsReconnect();
        setTimeout(() => {
            performIceRestart();
        }, 600);
    }
};

const handleOffline = () => {
    console.warn('[WebRTC] ⚠️ Network offline event detected');
    if (isCalling.value) {
        isNetworkDisconnected.value = true;
    }
};

onMounted(() => {
    resetMediaControlStates();
    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', handlePageUnload);
        window.addEventListener('pagehide', handlePageUnload);
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
        document.addEventListener('fullscreenchange', () => {
            isFullscreen.value = Boolean(document.fullscreenElement);
        });
        const params = new URLSearchParams(window.location.search);
        const target = params.get('destination') || params.get('peer') || params.get('to') || params.get('number') || params.get('custom_id');
        
        // Prioritize history tab if page or tab=history is present
        if (params.get('tab') === 'history' || params.has('page')) {
            activeTab.value = 'history';
            if (target) {
                destinationInput.value = target.trim();
            }
        } else if (target) {
            destinationInput.value = target.trim();
            if (target.startsWith('@') || /[a-zA-Z]/.test(target)) {
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

    // Auto-answer or open incoming call if redirected
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
                    answerIncomingCall(false);
                }, 300);
            } else {
                isIncomingCallModalOpen.value = true;
                playRingtone();
            }
        }
    }

    checkIncomingCall();
    incomingPollTimer = setInterval(checkIncomingCall, 3500);

    // Initial hardware discovery and devicechange listener
    if (typeof navigator !== 'undefined' && navigator.mediaDevices) {
        enumerateCameras();
        enumerateAudioOutputs();
        if (navigator.mediaDevices.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', handleDeviceChange);
        }
    }

    // Service Worker incoming call push and action synchronization
    if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            const data = event.data;
            if (data?.type === 'incoming_call_push') {
                checkIncomingCall();
            } else if (data?.type === 'call_declined_from_notification') {
                stopRingtone();
                isIncomingCallModalOpen.value = false;
                incomingCall.value = null;
            } else if (data?.type === 'answer_incoming_call') {
                if (incomingCall.value && String(incomingCall.value.id) === String(data.callId)) {
                    answerIncomingCall(false);
                } else if (data.callId) {
                    incomingCall.value = { id: parseInt(data.callId, 10), source: 'تماس ورودی' };
                    answerIncomingCall(false);
                }
            }
        });
    }
});

const handleDeviceChange = () => {
    enumerateCameras();
    enumerateAudioOutputs();
};

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('beforeunload', handlePageUnload);
        window.removeEventListener('pagehide', handlePageUnload);
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
    }
    if (typeof navigator !== 'undefined' && navigator.mediaDevices?.removeEventListener) {
        navigator.mediaDevices.removeEventListener('devicechange', handleDeviceChange);
    }
    if (wsReconnectTimer) clearTimeout(wsReconnectTimer);
    if (iceRestartDebounceTimer) clearTimeout(iceRestartDebounceTimer);
    handlePageUnload();
    resetMediaControlStates();
    releaseWakeLock();
    stopCallTimer();
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
    if (remoteVideo.value) {
        remoteVideo.value.srcObject = null;
    }
    if (whiteboardRemoteVideo.value) {
        whiteboardRemoteVideo.value.srcObject = null;
    }
    if (localVideo.value) {
        localVideo.value.srcObject = null;
    }
    if (whiteboardPipVideo.value) {
        whiteboardPipVideo.value.srcObject = null;
    }
    remoteVideoStream = null;
    isRenegotiating = false;
    renegotiationQueued = false;
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
                        @click="setTab('dialer')"
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
                        @click="setTab('history')"
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

                <div class="flex items-center gap-2">
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
            </div>

            <!-- ======================================================== -->
            <!-- TAB 1: PHONE DIALER & ACTIVE CALL (VOICE & VIDEO)        -->
            <!-- ======================================================== -->
            <div v-if="activeTab === 'dialer'" :class="['glass-card mx-auto space-y-5 bg-white/95 dark:bg-slate-900/80 border border-slate-200/90 dark:border-white/10 shadow-xl transition-all duration-300', isCalling && (isVideoCall || isWhiteboardOpen) ? (isExpandedWorkspace ? 'max-w-full w-full !p-2 sm:!p-4' : 'max-w-6xl xl:max-w-7xl w-full !p-3 sm:!p-6') : 'max-w-lg !p-5']">
                <!-- If currently in active call session -->
                <div v-if="isCalling" class="text-center py-4 space-y-4 animate-in fade-in zoom-in-95 duration-200">
                    <!-- Network Status Alert Banner -->
                    <div
                        v-if="isNetworkDisconnected || isPeerReconnecting"
                        class="p-3 rounded-2xl bg-amber-500/20 border border-amber-500/40 text-amber-600 dark:text-amber-300 text-xs font-bold text-center flex items-center justify-center gap-2 animate-pulse shadow-lg"
                    >
                        <svg class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>
                            {{ isNetworkDisconnected 
                                ? 'اتصال اینترنت شما قطع شد. در حال تلاش برای اتصال مجدد و همگام‌سازی دوباره تصویر و صدا...' 
                                : 'ارتباط طرف مقابل موقتاً قطع شده است. در حال انتظار برای بازیابی اتصال همتا...' }}
                        </span>
                    </div>

                    <!-- Remote Whiteboard Invitation Alert Banner -->
                    <div
                        v-if="!isWhiteboardOpen && isRemoteWhiteboardOpen"
                        class="p-3 rounded-2xl bg-indigo-500/20 border border-indigo-500/40 text-indigo-700 dark:text-indigo-300 text-xs font-bold text-center flex items-center justify-between gap-2 shadow-lg animate-pulse"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-base">🎨</span>
                            <span>طرف مقابل وایت‌برد تعاملی را باز کرده است.</span>
                        </div>
                        <button
                            type="button"
                            @click="openWhiteboard(true)"
                            class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition-colors"
                        >
                            پیوستن به تخته
                        </button>
                    </div>

                    <!-- 1. INTERACTIVE WHITEBOARD CONTAINER -->
                    <div
                        v-if="isWhiteboardOpen"
                        class="relative w-full rounded-3xl overflow-hidden bg-slate-900 border border-slate-700 shadow-2xl flex flex-col animate-in fade-in zoom-in-95 duration-200"
                    >
                        <!-- Whiteboard Top Toolbar -->
                        <div class="p-2 sm:p-3 bg-slate-950/95 border-b border-slate-800 flex flex-wrap items-center justify-between gap-2 z-20 backdrop-blur-md">
                            <!-- Tools: Pen, Highlighter, Eraser -->
                            <div class="flex items-center gap-1 bg-slate-900 p-1 rounded-xl border border-slate-800">
                                <button
                                    type="button"
                                    @click="whiteboardTool = 'pen'"
                                    class="px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 transition-all"
                                    :class="whiteboardTool === 'pen' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                                    title="قلم معمولی"
                                >
                                    <span>✏️</span>
                                    <span class="hidden sm:inline">قلم</span>
                                </button>
                                <button
                                    type="button"
                                    @click="whiteboardTool = 'highlighter'"
                                    class="px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 transition-all"
                                    :class="whiteboardTool === 'highlighter' ? 'bg-amber-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                                    title="ماژیک هایلایتر نیمه‌شفاف"
                                >
                                    <span>🖍️</span>
                                    <span class="hidden sm:inline">هایلایتر</span>
                                </button>
                                <button
                                    type="button"
                                    @click="whiteboardTool = 'eraser'"
                                    class="px-2.5 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 transition-all"
                                    :class="whiteboardTool === 'eraser' ? 'bg-rose-600 text-white shadow' : 'text-slate-400 hover:text-white'"
                                    title="پاک‌کن"
                                >
                                    <span>🧹</span>
                                    <span class="hidden sm:inline">پاک‌کن</span>
                                </button>
                            </div>

                            <!-- Color Palette (16 Colors + Custom Color Picker) -->
                            <div class="flex items-center gap-1 bg-slate-900 p-1.5 rounded-xl border border-slate-800 flex-wrap max-w-sm sm:max-w-md">
                                <button
                                    v-for="c in whiteboardColors"
                                    :key="c.hex"
                                    type="button"
                                    @click="whiteboardColor = c.hex"
                                    class="w-5 h-5 rounded-full border-2 transition-transform active:scale-90 shrink-0"
                                    :class="whiteboardColor === c.hex ? 'border-white scale-110 shadow-md ring-2 ring-indigo-400' : 'border-transparent opacity-80 hover:opacity-100'"
                                    :style="{ backgroundColor: c.hex }"
                                    :title="c.name"
                                ></button>
                                <!-- Custom Color Picker -->
                                <label class="relative w-5 h-5 rounded-full border border-slate-600 overflow-hidden cursor-pointer flex items-center justify-center bg-gradient-to-tr from-amber-400 via-rose-500 to-indigo-500 shrink-0 active:scale-90" title="انتخاب رنگ دلخواه">
                                    <input type="color" v-model="whiteboardColor" class="opacity-0 absolute inset-0 cursor-pointer w-full h-full" />
                                </label>
                            </div>

                            <!-- Stroke Width -->
                            <div class="flex items-center gap-1 bg-slate-900 p-1 rounded-xl border border-slate-800">
                                <button
                                    v-for="w in [2, 5, 10]"
                                    :key="w"
                                    type="button"
                                    @click="whiteboardLineWidth = w"
                                    class="w-7 h-7 rounded-lg text-[10px] font-mono font-bold flex items-center justify-center transition-all"
                                    :class="whiteboardLineWidth === w ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-white'"
                                >
                                    {{ w === 2 ? 'نازک' : w === 5 ? 'عادی' : 'ضخیم' }}
                                </button>
                            </div>

                            <!-- Actions: Undo, Clear, Save, Theater Mode, Camera PiP Toggle, Close -->
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <button
                                    type="button"
                                    @click="undoWhiteboard"
                                    class="px-2 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold flex items-center gap-1 transition-all"
                                    title="لغو آخرین رسم"
                                >
                                    <span>↩️</span>
                                    <span class="hidden md:inline">لغو</span>
                                </button>
                                <button
                                    type="button"
                                    @click="clearWhiteboard"
                                    class="px-2 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-rose-400 text-xs font-bold flex items-center gap-1 transition-all"
                                    title="پاک‌کردن کل تخته"
                                >
                                    <span>🗑️</span>
                                    <span class="hidden md:inline">پاک‌سازی</span>
                                </button>
                                <button
                                    type="button"
                                    @click="saveWhiteboardImage"
                                    class="px-2 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 text-xs font-bold flex items-center gap-1 transition-all"
                                    title="دانلود تصویر وایت‌برد"
                                >
                                    <span>💾</span>
                                    <span class="hidden md:inline">ذخیره</span>
                                </button>

                                <!-- Expanded Theater Workspace Toggle -->
                                <button
                                    type="button"
                                    @click="toggleExpandedWorkspace"
                                    class="px-2.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold flex items-center gap-1 transition-all"
                                    :title="isExpandedWorkspace ? 'خروج از فضای عریض' : 'فضای عریض کارگاهی (Theater Mode)'"
                                >
                                    <span>{{ isExpandedWorkspace ? '🗗' : '🗖' }}</span>
                                    <span class="hidden sm:inline">{{ isExpandedWorkspace ? 'عادی' : 'تمام‌عرض' }}</span>
                                </button>
                                
                                <!-- User Choice: Toggle Camera PiP on Whiteboard -->
                                <button
                                    type="button"
                                    @click="toggleWhiteboardCamera"
                                    class="px-2.5 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 border transition-all"
                                    :class="isCameraOn
                                        ? 'bg-indigo-600/30 border-indigo-500/70 text-indigo-300 hover:bg-indigo-600/40 shadow-sm'
                                        : 'bg-slate-800 border-slate-700 text-slate-400 hover:text-slate-200'"
                                    :title="isCameraOn ? 'خاموش کردن دوربین' : 'فعال‌سازی همزمان دوربین دوطرف روی وایت‌برد'"
                                >
                                    <span>{{ isCameraOn ? '📹 دوربین: روشن' : '📷 دوربین: خاموش' }}</span>
                                </button>

                                <!-- Close Whiteboard Button -->
                                <button
                                    type="button"
                                    @click="closeWhiteboard"
                                    class="px-2.5 py-1.5 rounded-xl bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 border border-rose-500/30 text-xs font-bold flex items-center gap-1 transition-all"
                                    title="بستن وایت‌برد و بازگشت به تماس"
                                >
                                    <span>✖️</span>
                                    <span>بستن تخته</span>
                                </button>
                            </div>
                        </div>

                        <!-- Whiteboard Canvas Drawing Surface with Touch & Mouse support -->
                        <div
                            class="relative w-full rounded-2xl bg-white overflow-hidden touch-none select-none border border-slate-700/60 shadow-inner aspect-video mx-auto max-h-[75vh]"
                        >
                            <canvas
                                ref="whiteboardCanvasRef"
                                @mousedown="onCanvasMouseDown"
                                @mousemove="onCanvasMouseMove"
                                @mouseup="onCanvasMouseUp"
                                @mouseleave="onCanvasMouseUp"
                                class="w-full h-full cursor-crosshair block"
                            ></canvas>

                            <!-- Remote Live Cursor with Name Tag -->
                            <div
                                v-if="remotePeerCursor.visible"
                                class="absolute pointer-events-none transition-all duration-75 ease-out z-40 flex items-center gap-1"
                                :style="{ left: `${remotePeerCursor.x * 100}%`, top: `${remotePeerCursor.y * 100}%` }"
                            >
                                <svg class="w-4 h-4 text-indigo-500 fill-indigo-500 drop-shadow -rotate-45" viewBox="0 0 24 24">
                                    <path d="M3 3l7 18 3-7 7-3L3 3z"/>
                                </svg>
                                <span class="px-2 py-0.5 rounded-md bg-indigo-600 text-white text-[10px] font-bold shadow-md whitespace-nowrap">
                                    {{ remotePeerCursor.name }}
                                </span>
                            </div>

                            <!-- Last Drawing Author Tag -->
                            <div v-if="lastDrawingAuthor" class="absolute top-2 right-2 px-2.5 py-1 rounded-xl bg-slate-950/85 border border-slate-700/80 text-white text-[11px] font-bold backdrop-blur-md flex items-center gap-1.5 shadow-lg pointer-events-none z-30">
                                <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                                <span>آخرین رسم: <strong class="text-indigo-300">{{ lastDrawingAuthor }}</strong></span>
                            </div>

                            <!-- Floating Remote Peer Video on Whiteboard -->
                            <div
                                v-if="isRemoteVideoStreaming || isRemoteScreenSharing"
                                class="absolute top-3 left-3 w-32 sm:w-48 aspect-video rounded-2xl overflow-hidden border-2 border-indigo-500 shadow-2xl bg-slate-950 z-30 pointer-events-auto transition-all"
                            >
                                <video
                                    ref="whiteboardRemoteVideo"
                                    autoplay
                                    playsinline
                                    muted
                                    class="w-full h-full object-cover"
                                ></video>
                                <div class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/70 text-[9px] text-white font-mono flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                                    <span>تصویر طرف مقابل</span>
                                </div>
                            </div>

                            <!-- Floating Local Camera PiP on Whiteboard -->
                            <div
                                v-if="isWhiteboardCameraActive && (isVideoCall || isCameraOn)"
                                class="absolute bottom-3 left-3 w-28 sm:w-44 aspect-video rounded-2xl overflow-hidden border-2 border-emerald-500 shadow-2xl bg-slate-950 z-30 pointer-events-auto transition-all"
                            >
                                <video
                                    ref="whiteboardPipVideo"
                                    autoplay
                                    playsinline
                                    muted
                                    class="w-full h-full object-cover transition-transform"
                                    :class="currentFacingMode === 'user' ? '-scale-x-100' : ''"
                                ></video>
                                <div class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/70 text-[9px] text-white font-mono flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    <span>تصویر شما</span>
                                </div>
                                <button
                                    v-if="availableCameras.length > 1"
                                    type="button"
                                    @click.stop="switchCamera"
                                    :disabled="isSwitchingCamera"
                                    class="absolute top-1 right-1 px-1.5 py-0.5 rounded bg-black/80 hover:bg-black text-[9px] text-white flex items-center gap-1 transition-all"
                                    :title="`سوییچ دوربین: ${activeCameraDisplayName}`"
                                >
                                    <svg class="w-3 h-3 text-indigo-400" :class="isSwitchingCamera ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Real-time sync badge indicator -->
                            <div class="absolute bottom-2 right-2 px-2.5 py-0.5 rounded-full bg-black/60 text-white text-[10px] font-mono flex items-center gap-1.5 backdrop-blur-sm pointer-events-none">
                                <span class="w-1.5 h-1.5 rounded-full" :class="isDataChannelReady ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400'"></span>
                                <span>{{ isDataChannelReady ? 'کانال داده همتا (WebRTC DataChannel)' : 'همگام‌سازی ابری' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. VIDEO CALL CONTAINER (When Video is enabled and Whiteboard is closed) -->
                    <div
                        v-else-if="isVideoCall"
                        ref="videoContainerRef"
                        class="relative w-full rounded-3xl overflow-hidden bg-slate-950 border border-slate-800 shadow-2xl flex items-center justify-center group"
                        :class="isExpandedWorkspace ? 'h-[65vh] sm:h-[75vh]' : 'aspect-video min-h-[340px] sm:min-h-[460px]'"
                    >
                        <!-- Remote Video Feed -->
                        <video
                            ref="remoteVideo"
                            autoplay
                            playsinline
                            muted
                            class="w-full h-full object-cover"
                        ></video>

                        <!-- Screen Sharing Status Banners -->
                        <div
                            v-if="isScreenSharing"
                            class="absolute top-3 left-1/2 -translate-x-1/2 z-20 flex items-center gap-2 bg-emerald-600/90 backdrop-blur-md px-3.5 py-1 rounded-full text-white text-xs font-bold shadow-lg"
                        >
                            <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                            <span>صفحه نمایش شما در حال اشتراک است</span>
                            <button
                                type="button"
                                @click="stopScreenShare"
                                class="px-2 py-0.5 rounded-md bg-white/20 hover:bg-white/30 text-white text-[10px] transition-colors"
                            >
                                توقف
                            </button>
                        </div>
                        <div
                            v-else-if="isRemoteScreenSharing"
                            class="absolute top-3 left-1/2 -translate-x-1/2 z-20 flex items-center gap-2 bg-indigo-600/90 backdrop-blur-md px-3.5 py-1 rounded-full text-white text-xs font-bold shadow-lg"
                        >
                            <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                            <span>در حال مشاهده صفحه نمایش طرف مقابل</span>
                        </div>

                        <!-- Local Video Picture-in-Picture Preview -->
                        <div class="absolute top-3 left-3 w-28 sm:w-40 aspect-video rounded-2xl overflow-hidden border-2 border-white/40 shadow-2xl bg-slate-900 z-20 transition-all">
                            <video
                                ref="localVideo"
                                autoplay
                                playsinline
                                muted
                                class="w-full h-full object-cover transition-transform"
                                :class="currentFacingMode === 'user' ? '-scale-x-100' : ''"
                            ></video>
                            <span v-if="!isCameraOn && !isScreenSharing" class="absolute inset-0 flex items-center justify-center bg-slate-900/80 text-[10px] text-slate-300 font-bold">
                                {{ __('user.calls.toggle_camera_off') }}
                            </span>
                            <span v-else-if="isScreenSharing" class="absolute bottom-1 left-1 px-1.5 py-0.5 rounded bg-emerald-600/80 text-[9px] text-white font-mono">
                                صفحه نمایش
                            </span>
                            <!-- Quick Switch Camera Button on PiP -->
                            <button
                                v-if="isCameraOn && !isScreenSharing"
                                type="button"
                                @click.stop="switchCamera"
                                :disabled="isSwitchingCamera"
                                class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded-md bg-black/75 hover:bg-black/95 text-white flex items-center gap-1 backdrop-blur-sm transition-all text-[9px] font-bold"
                                :title="`سوییچ دوربین: ${activeCameraDisplayName}`"
                            >
                                <svg class="w-3 h-3 text-indigo-400" :class="isSwitchingCamera ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span>{{ activeCameraShortName }}</span>
                                <span v-if="currentCameraIndexDisplay" class="text-[8px] opacity-75 font-mono">({{ currentCameraIndexDisplay }})</span>
                            </button>
                        </div>

                        <!-- Video Call Floating Top Status Badge -->
                        <div class="absolute top-3 right-3 z-20 flex items-center gap-1.5 bg-black/60 backdrop-blur-md px-3 py-1.5 rounded-full text-white text-xs font-mono">
                            <span class="w-2 h-2 rounded-full" :class="mediaConnectionState === 'connected' ? 'bg-emerald-400 animate-pulse' : 'bg-amber-400 animate-ping'"></span>
                            <span class="font-bold">{{ mediaConnectionState === 'connected' ? formatTimer(callDuration) : __('user.calls.call_connecting') }}</span>
                            <span v-if="optimalRouteInfo" class="text-[10px] text-slate-300 border-r border-white/20 pr-1.5 mr-1.5 hidden sm:inline">
                                {{ optimalRouteInfo }}
                            </span>
                            <button
                                type="button"
                                @click="toggleExpandedWorkspace"
                                class="p-1 rounded-md bg-white/10 hover:bg-white/20 text-white text-[10px] transition-colors mr-1"
                                :title="isExpandedWorkspace ? 'خروج از فضای عریض' : 'فضای عریض'"
                            >
                                {{ isExpandedWorkspace ? '🗗' : '🗖' }}
                            </button>
                        </div>

                        <!-- Fullscreen Toggle Overlay Button -->
                        <button
                            type="button"
                            @click="toggleFullscreen"
                            class="absolute bottom-3 right-3 z-20 w-8 h-8 rounded-xl bg-black/60 hover:bg-black/80 backdrop-blur-md text-white flex items-center justify-center transition-all opacity-80 hover:opacity-100"
                            :title="isFullscreen ? __('user.calls.exit_fullscreen') : __('user.calls.fullscreen')"
                        >
                            <svg v-if="isFullscreen" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                            </svg>
                        </button>
                    </div>

                    <!-- AUDIO CALL CONTAINER (Standard Voice Wave Avatar with Pulsating Rings) -->
                    <div v-else class="relative w-28 h-28 mx-auto flex items-center justify-center my-3">
                        <span class="absolute inset-0 rounded-full bg-emerald-500/20 animate-ping"></span>
                        <span class="absolute -inset-3 rounded-full bg-emerald-500/10 animate-pulse duration-1000"></span>
                        <div class="w-20 h-20 rounded-full bg-gradient-to-tr from-emerald-600 to-teal-500 text-white flex items-center justify-center shadow-xl shadow-emerald-500/40 relative z-10 ring-4 ring-white/80 dark:ring-slate-800">
                            <svg class="w-9 h-9 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div class="text-xs text-slate-400">{{ __('user.calls.calling_from_line', { line: selectedLine?.formatted_number || '' }) }}</div>
                        <div class="text-2xl font-black font-mono tracking-wider text-slate-900 dark:text-white" dir="ltr">
                            {{ destinationInput }}
                        </div>
                        <div v-if="!isVideoCall" class="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400 mt-2">
                            {{ mediaConnectionState === 'connected' ? formatTimer(callDuration) : __('user.calls.call_connecting') }}
                        </div>

                        <!-- Real-time Bridge & WebRTC Media Channel Status Indicator -->
                        <div class="pt-2 flex flex-col items-center gap-1.5">
                            <div
                                v-if="mediaConnectionState === 'connected'"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs font-mono font-bold"
                            >
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>{{ isVideoCall ? 'اتصال صوتی و تصویری برقرار است' : __('user.calls.audio_connected') }}</span>
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

                            <!-- Optimal Route Display -->
                            <div v-if="optimalRouteInfo" class="text-[11px] font-bold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-full border border-slate-200 dark:border-white/5 flex items-center gap-1.5">
                                <span>🌐</span>
                                <span>مسیر ارتباطی: <strong>{{ optimalRouteInfo }}</strong></span>
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

                    <!-- Video Controls Row (When in Video Call) -->
                    <div v-if="isVideoCall" class="p-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-white/10 flex items-center justify-around gap-2 max-w-sm mx-auto text-xs flex-wrap">
                        <!-- Camera Switcher -->
                        <button
                            v-if="isCameraOn"
                            type="button"
                            @click="switchCamera"
                            :disabled="isSwitchingCamera"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-white/10 font-bold flex items-center gap-1.5 hover:bg-slate-50 active:scale-95 transition-all shadow-sm disabled:opacity-50"
                            :title="`سوییچ به دوربین بعدی (${activeCameraDisplayName})`"
                        >
                            <svg class="w-3.5 h-3.5 text-indigo-500" :class="isSwitchingCamera ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span class="text-[10px] font-bold">{{ activeCameraDisplayName }}</span>
                            <span v-if="currentCameraIndexDisplay" class="text-[9px] px-1 rounded bg-indigo-500/10 text-indigo-500 font-mono">
                                {{ currentCameraIndexDisplay }}
                            </span>
                        </button>

                        <!-- Video Quality Selector -->
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-slate-400 font-bold">{{ __('user.calls.video_quality_label') }}</span>
                            <button
                                type="button"
                                @click="setVideoQuality('hd')"
                                class="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold"
                                :class="videoQuality === 'hd' ? 'bg-indigo-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
                            >
                                HD
                            </button>
                            <button
                                type="button"
                                @click="setVideoQuality('sd')"
                                class="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold"
                                :class="videoQuality === 'sd' ? 'bg-indigo-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
                            >
                                SD
                            </button>
                            <button
                                type="button"
                                @click="setVideoQuality('low')"
                                class="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold"
                                :class="videoQuality === 'low' ? 'bg-indigo-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'"
                            >
                                360p
                            </button>
                        </div>
                    </div>

                    <!-- Call Action Controls: Studio Modern Floating Control Dock -->
                    <div class="pt-3">
                        <div class="inline-flex items-center justify-center gap-2 sm:gap-3 p-2 sm:p-2.5 rounded-3xl bg-slate-900/95 dark:bg-slate-950/95 border border-slate-700/80 shadow-2xl backdrop-blur-xl max-w-full flex-wrap">
                            <!-- Mute / Unmute Mic -->
                            <button
                                type="button"
                                @click="toggleMute"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border transition-all active:scale-95 group shadow-sm"
                                :class="isMuted
                                    ? 'bg-amber-500/20 border-amber-500/70 text-amber-500 ring-2 ring-amber-500/30'
                                    : 'bg-slate-800/80 border-slate-700 text-slate-200 hover:bg-slate-700 hover:text-white'"
                                :title="isMuted ? __('user.calls.mic_muted_title') : __('user.calls.mic_active_title')"
                            >
                                <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ isMuted ? __('user.calls.muted_label') : __('user.calls.unmuted_label') }}</span>
                            </button>

                            <!-- Camera Toggle -->
                            <button
                                type="button"
                                @click="toggleCamera"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border transition-all active:scale-95 group shadow-sm"
                                :class="isCameraOn
                                    ? 'bg-indigo-600/30 border-indigo-500 text-indigo-300 ring-2 ring-indigo-500/40 shadow-indigo-500/20'
                                    : 'bg-slate-800/80 border-slate-700 text-slate-400 hover:text-slate-200 hover:bg-slate-700'"
                                :title="isCameraOn ? __('user.calls.toggle_camera_off') : __('user.calls.toggle_camera_on')"
                            >
                                <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ isCameraOn ? 'دوربین: روشن' : 'دوربین' }}</span>
                            </button>

                            <!-- Camera Switch Button -->
                            <button
                                v-if="isCameraOn && !isScreenSharing && availableCameras.length > 1"
                                type="button"
                                @click="switchCamera"
                                :disabled="isSwitchingCamera"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border border-slate-700 bg-slate-800/80 text-slate-300 hover:text-white hover:bg-slate-700 transition-all active:scale-95 disabled:opacity-50"
                                :title="`سوییچ دوربین: ${activeCameraDisplayName}`"
                            >
                                <svg class="w-5 h-5 mb-0.5 text-indigo-400" :class="isSwitchingCamera ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ activeCameraShortName }}</span>
                            </button>

                            <!-- Screen Sharing Toggle Button -->
                            <button
                                type="button"
                                @click="toggleScreenShare"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border transition-all active:scale-95 shadow-sm"
                                :class="isScreenSharing
                                    ? 'bg-emerald-600/30 border-emerald-500 text-emerald-300 ring-2 ring-emerald-500/40'
                                    : 'bg-slate-800/80 border-slate-700 text-slate-400 hover:text-slate-200 hover:bg-slate-700'"
                                :title="isScreenSharing ? __('user.calls.screen_share_stop') : __('user.calls.screen_share_start')"
                            >
                                <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ isScreenSharing ? 'توقف اشتراک' : 'اشتراک صفحه' }}</span>
                            </button>

                            <!-- Interactive Whiteboard Toggle Button -->
                            <button
                                type="button"
                                @click="toggleWhiteboard"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border transition-all active:scale-95 relative shadow-sm"
                                :class="isWhiteboardOpen
                                    ? 'bg-purple-600/30 border-purple-500 text-purple-300 ring-2 ring-purple-500/40 shadow-purple-500/20'
                                    : 'bg-slate-800/80 border-slate-700 text-slate-400 hover:text-slate-200 hover:bg-slate-700'"
                                :title="isWhiteboardOpen ? __('user.calls.whiteboard_close') : __('user.calls.whiteboard_btn')"
                            >
                                <span v-if="isRemoteWhiteboardOpen && !isWhiteboardOpen" class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-purple-500 animate-ping"></span>
                                <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ isWhiteboardOpen ? 'بستن تخته' : 'وایت‌برد' }}</span>
                            </button>

                            <!-- Speaker / Earpiece Audio Routing Button -->
                            <button
                                type="button"
                                @click="toggleSpeaker"
                                class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl flex flex-col items-center justify-center border transition-all active:scale-95 shadow-sm"
                                :class="isSpeakerOn
                                    ? 'bg-sky-600/30 border-sky-500 text-sky-300 ring-2 ring-sky-500/40 shadow-sky-500/20'
                                    : 'bg-slate-800/80 border-slate-700 text-slate-400 hover:text-slate-200 hover:bg-slate-700'"
                                :title="isSpeakerOn ? 'خروجی: بلندگو (اسپیکر)' : 'خروجی: گوشی تلفن (Earpiece)'"
                            >
                                <svg v-if="isSpeakerOn" class="w-5 h-5 mb-0.5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                                </svg>
                                <svg v-else class="w-5 h-5 mb-0.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <span class="text-[9px] font-bold">{{ isSpeakerOn ? 'اسپیکر' : 'گوشی' }}</span>
                            </button>

                            <!-- End Call / Hangup Button -->
                            <button
                                type="button"
                                @click="endCall"
                                class="w-13 h-13 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-tr from-rose-600 to-rose-700 hover:from-rose-500 hover:to-rose-600 text-white flex items-center justify-center shadow-xl shadow-rose-600/50 transition-all active:scale-95 ml-1 ring-2 ring-rose-500/40"
                                :title="__('user.calls.hangup_title')"
                            >
                                <svg class="w-7 h-7 sm:w-8 sm:h-8 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                            </button>
                        </div>
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

                    <!-- Clean Symmetrical Dialed Number Display Area -->
                    <div class="px-3 py-2 sm:px-4 sm:py-2.5 rounded-2xl bg-slate-100/90 dark:bg-slate-950/60 border border-slate-200 dark:border-white/10 flex items-center justify-between gap-1.5 min-h-[64px] relative overflow-hidden">
                        <!-- Right Side (RTL Start): Contact Picker & @ Badge (Fixed Symmetrical Width) -->
                        <div class="w-20 shrink-0 flex items-center justify-start gap-1">
                            <button
                                type="button"
                                @click="isContactPickerOpen = true"
                                class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/10 active:scale-95 transition-all"
                                :title="__('user.calls.pick_contact_title')"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </button>
                            <span v-if="keypadMode === 'alphanumeric'" class="px-1.5 py-0.5 rounded-lg bg-indigo-500/10 text-indigo-500 font-mono font-black text-xs border border-indigo-500/20">
                                @
                            </span>
                        </div>

                        <!-- Center: Clean Dialed Number Input (Zero Native Outline/Border, 100% Centered) -->
                        <input
                            type="text"
                            v-model="destinationInput"
                            :placeholder="keypadMode === 'numeric' ? __('user.calls.placeholder_number') : __('user.calls.placeholder_handle')"
                            class="dialer-number-input flex-1 min-w-0 text-center font-mono font-black text-2xl sm:text-3xl text-slate-900 dark:text-white bg-transparent border-none outline-none ring-0 shadow-none focus:outline-none focus:ring-0 focus:border-none focus-visible:outline-none tracking-wider select-all"
                            style="outline: none !important; border: none !important; box-shadow: none !important; -webkit-tap-highlight-color: transparent;"
                            dir="ltr"
                        />

                        <!-- Left Side (RTL End): Actions (Fixed Symmetrical Width) -->
                        <div class="w-20 shrink-0 flex items-center justify-end gap-1">
                            <template v-if="destinationInput">
                                <!-- Clear All Button -->
                                <button
                                    type="button"
                                    @click="clearInput"
                                    class="px-2 py-1 rounded-lg text-[11px] font-bold text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 active:scale-95 transition-all"
                                    :title="__('user.calls.clear_input')"
                                >
                                    {{ __('user.calls.clear_input') }}
                                </button>

                                <!-- Single Character Backspace Button -->
                                <button
                                    type="button"
                                    @click="backspace"
                                    class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center text-slate-400 hover:text-rose-500 hover:bg-rose-500/10 active:scale-95 transition-all"
                                    :title="__('user.calls.clear_number') || 'حذف رقم'"
                                >
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414-6.414a2 2 0 011.414-.586H19a2 2 0 012 2v10a2 2 0 01-2 2h-7.172a2 2 0 01-1.414-.586L3 12z" />
                                    </svg>
                                </button>
                            </template>
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

                    <!-- Dual Action Call Buttons (Voice Call & Video Call) -->
                    <div class="pt-2 flex items-center justify-center gap-3">
                        <button
                            type="button"
                            @click="startCall(false, false)"
                            :disabled="!destinationInput.trim() || !selectedLine"
                            class="flex-1 py-3.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-xs sm:text-sm flex items-center justify-center gap-2 shadow-lg shadow-emerald-600/30 transition-all active:scale-98 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            <span>{{ __('user.calls.start_call_btn') }}</span>
                        </button>

                        <button
                            type="button"
                            @click="startCall(false, true)"
                            :disabled="!destinationInput.trim() || !selectedLine"
                            class="flex-1 py-3.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs sm:text-sm flex items-center justify-center gap-2 shadow-lg shadow-indigo-600/30 transition-all active:scale-98 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <span>{{ __('user.calls.start_video_call_btn') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 2: CALL HISTORY                                      -->
            <!-- ======================================================== -->
            <div v-else-if="activeTab === 'history'" class="space-y-3">
                <!-- Selected Line History Indicator & Bulk Actions -->
                <div class="glass-card !p-3 bg-white/90 dark:bg-slate-900/60 border border-slate-200/90 dark:border-white/10 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex items-center gap-2">
                        <!-- Select All Checkbox -->
                        <label v-if="calls.data && calls.data.length > 0" class="inline-flex items-center gap-1.5 cursor-pointer ml-1">
                            <input
                                type="checkbox"
                                :checked="isAllCallsSelected"
                                @change="toggleSelectAllCalls"
                                class="rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span class="text-[11px] text-slate-600 dark:text-slate-300 font-bold">انتخاب همه</span>
                        </label>
                        <span class="text-slate-500 dark:text-slate-400">{{ __('user.calls.history_for_line') }}</span>
                        <span class="font-mono font-black text-indigo-600 dark:text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded-lg" dir="ltr">
                            {{ selectedLine?.formatted_number }}
                        </span>
                        <span class="text-[11px] text-slate-400">({{ selectedLine?.display_title }})</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Bulk Delete Button -->
                        <button
                            v-if="selectedCallIds.length > 0"
                            type="button"
                            @click="deleteBulkCalls"
                            :disabled="isDeletingCalls"
                            class="px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center gap-1.5 shadow-sm transition-all active:scale-95 disabled:opacity-50"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            <span>حذف موارد انتخابی ({{ selectedCallIds.length }})</span>
                        </button>
                        <span class="text-[11px] text-slate-500">
                            {{ __('user.calls.calls_count', { count: calls.total }) }}
                        </span>
                    </div>
                </div>

                <div v-if="calls.data && calls.data.length > 0" class="space-y-2">
                    <div
                        v-for="call in calls.data"
                        :key="call.id"
                        class="glass-card !p-3.5 bg-white/95 dark:bg-slate-900/75 border border-slate-200/90 dark:border-white/10 flex flex-wrap items-center justify-between gap-3 text-xs"
                    >
                        <div class="flex items-center gap-2.5 sm:gap-3">
                            <!-- Row Checkbox -->
                            <input
                                type="checkbox"
                                :checked="selectedCallIds.includes(call.id)"
                                @change="toggleSelectCall(call.id)"
                                class="rounded border-slate-300 dark:border-slate-700 text-indigo-600 focus:ring-indigo-500 shrink-0"
                            />
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

                        <div class="flex items-center gap-2 sm:gap-3">
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

                            <!-- Single Call Delete Button -->
                            <button
                                type="button"
                                @click="deleteSingleCall(call.id)"
                                class="w-8 h-8 rounded-xl text-slate-400 hover:text-rose-500 hover:bg-rose-500/10 flex items-center justify-center transition-colors"
                                title="حذف این تماس از تاریخچه"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="calls.links && calls.links.length > 3" class="flex justify-center gap-1 pt-3">
                        <button
                            v-for="(link, i) in calls.links"
                            :key="i"
                            type="button"
                            @click="navigateHistoryPage(link.url)"
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

        <!-- Incoming Call Modal Notification (Supports Voice or Video) -->
        <div
            v-if="isIncomingCallModalOpen"
            class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-3 sm:p-4 bg-slate-950/85 backdrop-blur-lg animate-in fade-in duration-300"
        >
            <div class="glass-card w-full max-w-sm sm:max-w-md p-6 sm:p-8 bg-white/95 dark:bg-slate-900/95 border border-slate-200 dark:border-white/10 rounded-3xl space-y-6 shadow-2xl text-center relative overflow-hidden">
                <!-- Glowing Ambient Background Blob -->
                <div class="absolute -top-24 -left-24 w-48 h-48 bg-emerald-500/20 rounded-full blur-3xl pointer-events-none animate-pulse"></div>
                <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none animate-pulse"></div>

                <!-- Animated Avatar with Multi-layer Ripples -->
                <div class="relative w-28 h-28 mx-auto flex items-center justify-center">
                    <span class="absolute inset-0 rounded-full bg-emerald-500/20 animate-ping"></span>
                    <span class="absolute -inset-3 rounded-full bg-emerald-500/15 animate-pulse duration-1000"></span>
                    <span class="absolute -inset-6 rounded-full bg-emerald-500/10 animate-pulse duration-1500"></span>
                    <div class="w-20 h-20 rounded-full bg-gradient-to-tr from-emerald-600 to-teal-500 text-white flex items-center justify-center shadow-xl shadow-emerald-500/40 relative z-10 ring-4 ring-white dark:ring-slate-800">
                        <svg class="w-10 h-10 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                </div>

                <!-- Caller Info & Type -->
                <div class="space-y-2 relative z-10">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs font-bold font-mono">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>{{ __('user.calls.incoming_call_title') }}</span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-black font-mono tracking-wider text-slate-900 dark:text-white" dir="ltr">
                        {{ incomingCall?.source }}
                    </div>
                    <div class="text-xs text-slate-400 font-medium">
                        {{ __('user.calls.to_destination', { destination: incomingCall?.destination || '' }) }}
                    </div>
                </div>

                <!-- Action Buttons: Decline, Voice Answer, Video Answer -->
                <div class="grid grid-cols-3 gap-2 sm:gap-3 pt-2 relative z-10">
                    <!-- Decline button -->
                    <button
                        type="button"
                        @click="declineIncomingCall"
                        class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-rose-500 to-rose-700 hover:from-rose-600 hover:to-rose-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-rose-600/30 transition-all active:scale-95 group"
                    >
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 rotate-135" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                        <span>{{ __('user.calls.decline_btn') }}</span>
                    </button>

                    <!-- Answer Voice button -->
                    <button
                        type="button"
                        @click="answerIncomingCall(false)"
                        class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-emerald-500 to-emerald-700 hover:from-emerald-600 hover:to-emerald-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-emerald-600/30 transition-all active:scale-95 group"
                    >
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                        <span>پاسخ صوتی</span>
                    </button>

                    <!-- Answer Video button -->
                    <button
                        type="button"
                        @click="answerIncomingCall(true)"
                        class="py-3.5 sm:py-4 px-2 rounded-2xl bg-gradient-to-b from-indigo-500 to-indigo-700 hover:from-indigo-600 hover:to-indigo-800 text-white font-bold text-xs flex flex-col items-center justify-center gap-1.5 shadow-lg shadow-indigo-600/30 transition-all active:scale-95 group"
                    >
                        <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span>پاسخ تصویری</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Microphone & Media Access Helper Modal -->
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

        <!-- Hidden WebRTC Remote Audio Player -->
        <audio ref="remoteAudio" autoplay playsinline class="fixed -top-96 -left-96 w-1 h-1 opacity-0 pointer-events-none"></audio>
    </UserLayout>
</template>

<style scoped>
.dialer-number-input {
    outline: none !important;
    border: none !important;
    box-shadow: none !important;
    background: transparent !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
}
.dialer-number-input:focus,
.dialer-number-input:focus-visible,
.dialer-number-input:active {
    outline: none !important;
    border: none !important;
    box-shadow: none !important;
    border-color: transparent !important;
}
</style>
