import { ref } from 'vue';

export interface RtcServerConfig {
    iceServers?: RTCIceServer[];
    iceTransportPolicy?: RTCIceTransportPolicy;
    hasTurn?: boolean;
    isOfflineMode?: boolean;
}

export interface UseWebRtcPeerOptions {
    rtcConfig?: RtcServerConfig | null;
    forceRelayOnly?: boolean;
    onTrack?: (event: RTCTrackEvent) => void;
    onIceCandidate?: (candidate: RTCIceCandidate) => void;
    onConnectionStateChange?: (state: RTCPeerConnectionState) => void;
}

/**
 * Sanitizes SDP strings to fix compatibility issues across Chromium / WebKit / Firefox implementations.
 * - Strips `a=max-message-size:\d+` which causes "Failed to parse SessionDescription. a=max-message-size:... Invalid SDP line" in Chromium.
 * - Fixes dummy/empty msid hyphens: `a=ssrc:... msid:- ...` and `a=msid:- ...`.
 * - Strips empty/corrupt lines and strictly enforces RFC 8866 CRLF (\r\n) line terminators.
 */
export function sanitizeSdp(sdp?: string): string {
    if (!sdp || typeof sdp !== 'string') return '';

    // Normalize all line endings to \n first for consistent line processing
    let normalized = sdp.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

    // Fix msid hyphens (Chromium bug with unnamed streams)
    normalized = normalized.replace(/^(a=ssrc:\d+\s+msid):\s*-\s+/gm, '$1:stream ');
    normalized = normalized.replace(/^(a=msid):\s*-\s+/gm, '$1:stream ');

    // Filter out problematic lines and clean empty lines
    const lines = normalized.split('\n');
    const filteredLines: string[] = [];

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) continue;

        // Chromium WebRTC bug: a=max-message-size causes parse error in setRemoteDescription
        if (line.startsWith('a=max-message-size:')) {
            continue;
        }

        filteredLines.push(line);
    }

    return filteredLines.join('\r\n') + '\r\n';
}

export function useWebRtcPeer(options: UseWebRtcPeerOptions = {}) {
    const connectionState = ref<RTCPeerConnectionState>('new');
    const iceConnectionState = ref<RTCIceConnectionState>('new');
    const isAudioBlocked = ref<boolean>(false);

    // Build standard RTCConfiguration enforcing strict TURN Relay policy
    const buildConfiguration = (overrideConfig?: RtcServerConfig | null): RTCConfiguration => {
        const cfg = overrideConfig || options.rtcConfig;
        const iceServers: RTCIceServer[] = Array.isArray(cfg?.iceServers) ? cfg.iceServers : [];

        // When forceRelayOnly is true or policy is relay, strictly enforce relay
        const policy: RTCIceTransportPolicy = (options.forceRelayOnly === true)
            ? 'relay'
            : ((cfg?.iceTransportPolicy as RTCIceTransportPolicy) || 'all');

        return {
            iceServers,
            iceTransportPolicy: policy,
            iceCandidatePoolSize: 4,
            bundlePolicy: 'max-bundle',
            rtcpMuxPolicy: 'require',
        };
    };

    // Enhance Opus SDP with In-Band Forward Error Correction (FEC) and DTX
    const enhanceOpusSdp = (sdp?: string): string => {
        if (!sdp || !sdp.includes('opus/48000')) {
            return sanitizeSdp(sdp);
        }
        try {
            const enhanced = sdp.replace(/(a=fmtp:\d+\s+[^;\r\n]+)/gi, (match) => {
                let updated = match;
                if (!updated.includes('useinbandfec=')) updated += ';useinbandfec=1';
                if (!updated.includes('usedtx=')) updated += ';usedtx=1';
                return updated;
            });
            return sanitizeSdp(enhanced);
        } catch {
            return sanitizeSdp(sdp);
        }
    };

    // Create and configure RTCPeerConnection with relay candidate filter and transceivers
    const createPeerConnection = (configOverride?: RtcServerConfig | null): RTCPeerConnection => {
        const config = buildConfiguration(configOverride);
        const pc = new RTCPeerConnection(config);

        pc.onconnectionstatechange = () => {
            connectionState.value = pc.connectionState;
            options.onConnectionStateChange?.(pc.connectionState);
            console.log('[useWebRtcPeer] 🔗 Peer connection state:', pc.connectionState);
        };

        pc.oniceconnectionstatechange = () => {
            iceConnectionState.value = pc.iceConnectionState;
            console.log('[useWebRtcPeer] 🧊 ICE connection state:', pc.iceConnectionState);
        };

        // Strict ICE candidate filtering: drop host and srflx candidates if relay-only
        pc.onicecandidate = (event) => {
            if (!event.candidate) {
                return;
            }

            const candidateStr = event.candidate.candidate || '';
            const isRelayCandidate = candidateStr.includes('typ relay') || event.candidate.type === 'relay';

            if (options.forceRelayOnly === true && !isRelayCandidate) {
                console.log('[useWebRtcPeer] 🛡️ Filtered non-relay candidate:', candidateStr);
                return;
            }

            options.onIceCandidate?.(event.candidate);
        };

        if (options.onTrack) {
            pc.ontrack = options.onTrack;
        }

        return pc;
    };

    // Pre-negotiate symmetrical sendrecv transceivers
    const ensureTransceivers = (pc: RTCPeerConnection, includeVideo = true) => {
        const transceivers = pc.getTransceivers();
        const hasAudio = transceivers.some((t) => t.receiver?.track?.kind === 'audio' || t.sender?.track?.kind === 'audio');
        const hasVideo = transceivers.some((t) => t.receiver?.track?.kind === 'video' || t.sender?.track?.kind === 'video');

        if (!hasAudio) {
            try {
                pc.addTransceiver('audio', { direction: 'sendrecv' });
            } catch (e) {
                console.warn('[useWebRtcPeer] Failed adding audio transceiver:', e);
            }
        }

        if (includeVideo && !hasVideo) {
            try {
                pc.addTransceiver('video', { direction: 'sendrecv' });
            } catch (e) {
                console.warn('[useWebRtcPeer] Failed adding video transceiver:', e);
            }
        }
    };

    // Hot-swap audio track without full renegotiation using replaceTrack
    const replaceAudioTrack = async (pc: RTCPeerConnection, newTrack: MediaStreamTrack | null): Promise<boolean> => {
        try {
            const senders = pc.getSenders();
            const audioSender = senders.find((s) => s.track?.kind === 'audio' || (s as any).kind === 'audio');

            if (audioSender) {
                await audioSender.replaceTrack(newTrack);
                console.log('[useWebRtcPeer] 🎙️ Audio track hot-swapped via replaceTrack');
                return true;
            } else if (newTrack) {
                pc.addTrack(newTrack);
                console.log('[useWebRtcPeer] 🎙️ Audio track attached via addTrack');
                return true;
            }
        } catch (err) {
            console.error('[useWebRtcPeer] ❌ Failed to hot-swap audio track:', err);
        }
        return false;
    };

    // Hot-swap video track without full renegotiation using replaceTrack
    const replaceVideoTrack = async (pc: RTCPeerConnection, newTrack: MediaStreamTrack | null): Promise<boolean> => {
        try {
            const senders = pc.getSenders();
            const videoSender = senders.find((s) => s.track?.kind === 'video' || (s as any).kind === 'video');

            if (videoSender) {
                await videoSender.replaceTrack(newTrack);
                console.log('[useWebRtcPeer] 🎥 Video track hot-swapped via replaceTrack');
                return true;
            } else if (newTrack) {
                pc.addTrack(newTrack);
                console.log('[useWebRtcPeer] 🎥 Video track attached via addTrack');
                return true;
            }
        } catch (err) {
            console.error('[useWebRtcPeer] ❌ Failed to hot-swap video track:', err);
        }
        return false;
    };

    // Browser Autoplay Policy Unlocker: Handles blocked audio element playback
    const unblockAudioPlayback = async (audioElement: HTMLMediaElement | null): Promise<boolean> => {
        if (!audioElement) return false;

        try {
            audioElement.muted = false;
            await audioElement.play();
            isAudioBlocked.value = false;
            return true;
        } catch {
            isAudioBlocked.value = true;
            console.warn('[useWebRtcPeer] 🔇 Audio playback blocked by browser autoplay policy. Registering interaction unlocker...');

            const unlockOnUserGesture = async () => {
                try {
                    audioElement.muted = false;
                    await audioElement.play();
                    isAudioBlocked.value = false;
                    console.log('[useWebRtcPeer] 🔊 Audio playback unblocked via user interaction');
                } catch {}

                window.removeEventListener('click', unlockOnUserGesture);
                window.removeEventListener('touchstart', unlockOnUserGesture);
                window.removeEventListener('keydown', unlockOnUserGesture);
            };

            window.addEventListener('click', unlockOnUserGesture, { once: true });
            window.addEventListener('touchstart', unlockOnUserGesture, { once: true });
            window.addEventListener('keydown', unlockOnUserGesture, { once: true });
            return false;
        }
    };

    // Safely tear down peer connection
    const closePeerConnection = (pc: RTCPeerConnection | null) => {
        if (!pc) return;
        try {
            pc.getSenders().forEach((s) => {
                if (s.track) {
                    s.track.stop();
                }
            });
            pc.close();
            connectionState.value = 'closed';
            iceConnectionState.value = 'closed';
            console.log('[useWebRtcPeer] 🛑 Peer connection closed cleanly');
        } catch (e) {
            console.warn('[useWebRtcPeer] Warning while closing peer connection:', e);
        }
    };

    return {
        connectionState,
        iceConnectionState,
        isAudioBlocked,
        buildConfiguration,
        enhanceOpusSdp,
        sanitizeSdp,
        createPeerConnection,
        ensureTransceivers,
        replaceAudioTrack,
        replaceVideoTrack,
        unblockAudioPlayback,
        closePeerConnection,
    };
}
