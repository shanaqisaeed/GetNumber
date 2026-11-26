import { ref, onMounted, onUnmounted } from 'vue';
import { __ } from '@/Composables/useTrans';

export interface CameraDevice {
    deviceId: string;
    label: string;
    facingMode: 'user' | 'environment';
}

export interface AudioInputDevice {
    deviceId: string;
    label: string;
}

export interface AudioOutputDevice {
    deviceId: string;
    label: string;
    isSpeaker: boolean;
}

export interface MediaResolution {
    width: { ideal: number };
    height: { ideal: number };
    frameRate: { ideal: number };
}

export function useMediaDevices() {
    const cameras = ref<CameraDevice[]>([]);
    const microphones = ref<AudioInputDevice[]>([]);
    const speakers = ref<AudioOutputDevice[]>([]);

    const selectedCameraId = ref<string>('');
    const selectedMicrophoneId = ref<string>('');
    const selectedSpeakerId = ref<string>('');
    const currentFacingMode = ref<'user' | 'environment'>('user');

    const isEnumerating = ref<boolean>(false);
    const hasCameraPermission = ref<boolean>(false);
    const hasMicrophonePermission = ref<boolean>(false);

    // Enumerate cameras with sensor filtering (IR/ToF/Depth) and human-friendly labeling
    const enumerateCameras = async (): Promise<CameraDevice[]> => {
        if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) {
            return [];
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const videoDevices = devices.filter((d) => d.kind === 'videoinput');

            let backIdx = 0;
            let frontIdx = 0;
            const result: CameraDevice[] = [];

            for (let i = 0; i < videoDevices.length; i++) {
                const d = videoDevices[i];
                const raw = (d.label || '').trim();
                const lower = raw.toLowerCase();

                // Filter out IR, Depth, ToF sensors
                if (lower.includes('ir ') || lower.includes('infra') || lower.includes('depth') || lower.includes('tof')) {
                    continue;
                }

                let facing: 'user' | 'environment' = 'user';
                if (lower.includes('back') || lower.includes('rear') || lower.includes('environment')) {
                    facing = 'environment';
                    backIdx++;
                } else if (lower.includes('front') || lower.includes('user') || lower.includes('selfie')) {
                    facing = 'user';
                    frontIdx++;
                } else {
                    facing = i === 0 ? 'user' : 'environment';
                    if (facing === 'environment') backIdx++;
                    else frontIdx++;
                }

                let label = raw;
                if (!label) {
                    label = facing === 'user'
                        ? (frontIdx > 1 ? __('user.calls.cam_front_numbered', { index: frontIdx }) : __('user.calls.cam_front_selfie'))
                        : (backIdx > 1 ? __('user.calls.cam_rear_numbered', { index: backIdx }) : __('user.calls.cam_rear_main'));
                } else {
                    if (lower.includes('back') || lower.includes('rear')) {
                        if (lower.includes('wide') || lower.includes('ultra')) {
                            label = __('user.calls.cam_rear_ultrawide');
                        } else if (lower.includes('tele') || lower.includes('zoom')) {
                            label = __('user.calls.cam_rear_telephoto');
                        } else if (lower.includes('macro')) {
                            label = __('user.calls.cam_rear_macro');
                        } else {
                            label = backIdx > 1 ? __('user.calls.cam_rear_numbered', { index: backIdx }) : __('user.calls.cam_rear_main');
                        }
                    } else if (lower.includes('front') || lower.includes('selfie') || lower.includes('user')) {
                        if (lower.includes('wide') || lower.includes('ultra')) {
                            label = __('user.calls.cam_front_ultrawide');
                        } else {
                            label = frontIdx > 1 ? __('user.calls.cam_front_numbered', { index: frontIdx }) : __('user.calls.cam_front_selfie');
                        }
                    } else {
                        const cleanName = raw.replace(/integrated webcam/i, __('user.calls.webcam_default')).trim();
                        label = cleanName.length > 0 ? cleanName.slice(0, 32) : __('user.calls.cam_numbered', { index: i + 1 });
                    }
                }

                // Avoid duplicate entries
                if (!result.some((r) => r.deviceId === d.deviceId)) {
                    result.push({
                        deviceId: d.deviceId,
                        label,
                        facingMode: facing,
                    });
                }
            }

            cameras.value = result;
            if (result.length > 0) {
                const match = result.find((c) => c.deviceId === selectedCameraId.value);
                if (!match) {
                    selectedCameraId.value = result[0].deviceId;
                    currentFacingMode.value = result[0].facingMode;
                } else {
                    currentFacingMode.value = match.facingMode;
                }
            }

            return result;
        } catch (e) {
            console.warn('[useMediaDevices] Failed to enumerate cameras:', e);
            return [];
        }
    };

    // Enumerate microphones
    const enumerateMicrophones = async (): Promise<AudioInputDevice[]> => {
        if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) {
            return [];
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const audioInputs = devices.filter((d) => d.kind === 'audioinput');
            const result: AudioInputDevice[] = audioInputs.map((d, i) => {
                const raw = (d.label || '').trim();
                const fallback = i === 0 ? __('user.calls.mic_default') : __('user.calls.mic_numbered', { index: i + 1 });
                return {
                    deviceId: d.deviceId,
                    label: raw || fallback,
                };
            });

            microphones.value = result;
            if (result.length > 0) {
                const match = result.find((m) => m.deviceId === selectedMicrophoneId.value);
                if (!match) {
                    selectedMicrophoneId.value = result[0].deviceId;
                }
            }

            return result;
        } catch (e) {
            console.warn('[useMediaDevices] Failed to enumerate microphones:', e);
            return [];
        }
    };

    // Enumerate audio output devices (speakers / headphones)
    const enumerateAudioOutputs = async (): Promise<AudioOutputDevice[]> => {
        if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) {
            return [];
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const outputs = devices.filter((d) => d.kind === 'audiooutput');
            const result: AudioOutputDevice[] = outputs.map((d, i) => {
                const raw = (d.label || '').trim();
                const lower = raw.toLowerCase();
                const isSpeaker = lower.includes('speaker') || lower.includes('loud') || !lower.includes('earpiece');
                let label = raw;
                if (!label) {
                    label = isSpeaker ? __('user.calls.output_speaker') : __('user.calls.output_indexed', { index: i + 1 });
                } else if (lower.includes('earpiece')) {
                    label = __('user.calls.output_earpiece');
                } else if (lower.includes('speaker')) {
                    label = __('user.calls.output_speaker');
                }

                return {
                    deviceId: d.deviceId,
                    label,
                    isSpeaker,
                };
            });

            speakers.value = result;

            // Restore previously selected speaker from localStorage if available
            const savedSpeaker = typeof window !== 'undefined' ? localStorage.getItem('ravyno_selected_speaker') : null;
            if (savedSpeaker && result.some((s) => s.deviceId === savedSpeaker)) {
                selectedSpeakerId.value = savedSpeaker;
            } else if (result.length > 0 && !selectedSpeakerId.value) {
                selectedSpeakerId.value = result[0].deviceId;
            }

            return result;
        } catch (e) {
            console.warn('[useMediaDevices] Failed to enumerate audio outputs:', e);
            return [];
        }
    };

    // Refresh all devices at once
    const enumerateAllDevices = async () => {
        isEnumerating.value = true;
        try {
            await Promise.all([
                enumerateCameras(),
                enumerateMicrophones(),
                enumerateAudioOutputs(),
            ]);
        } finally {
            isEnumerating.value = false;
        }
    };

    // Video Resolution Preset Mapper
    const getVideoResolution = (quality: 'hd' | 'sd' | 'low' = 'hd'): MediaResolution => {
        if (quality === 'hd') {
            return { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } };
        } else if (quality === 'sd') {
            return { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 24 } };
        }
        return { width: { ideal: 480 }, height: { ideal: 360 }, frameRate: { ideal: 15 } };
    };

    // Switch active camera with progressive fallback strategies
    const switchCamera = async (
        targetDeviceId?: string,
        facing?: 'user' | 'environment',
        quality: 'hd' | 'sd' | 'low' = 'hd'
    ): Promise<{ stream: MediaStream; track: MediaStreamTrack } | null> => {
        const devId = targetDeviceId || selectedCameraId.value;
        const faceMode = facing || currentFacingMode.value;
        const res = getVideoResolution(quality);

        let stream: MediaStream | null = null;

        // Strategy 1: Explicit deviceId
        if (devId && devId.length > 5) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        deviceId: { exact: devId },
                        width: res.width,
                        height: res.height,
                        frameRate: res.frameRate,
                    },
                });
            } catch (err) {
                console.warn('[useMediaDevices] ⚠️ Strategy 1 (exact deviceId) failed, trying ideal:', err);
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            deviceId: { ideal: devId },
                            width: res.width,
                            height: res.height,
                        },
                    });
                } catch {}
            }
        }

        // Strategy 2: FacingMode fallback
        if (!stream) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: faceMode },
                        width: res.width,
                        height: res.height,
                    },
                });
            } catch (err) {
                console.warn('[useMediaDevices] ⚠️ Strategy 2 (facingMode) failed, trying generic:', err);
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: true,
                    });
                } catch (genericErr) {
                    console.error('[useMediaDevices] ❌ All camera stream strategies failed:', genericErr);
                    return null;
                }
            }
        }

        if (stream) {
            const track = stream.getVideoTracks()[0];
            if (track) {
                hasCameraPermission.value = true;
                const settings = track.getSettings();
                if (settings.deviceId) {
                    selectedCameraId.value = settings.deviceId;
                }
                if (settings.facingMode) {
                    currentFacingMode.value = settings.facingMode as 'user' | 'environment';
                }
                return { stream, track };
            }
        }

        return null;
    };

    // Switch active microphone
    const switchMicrophone = async (
        targetDeviceId?: string,
        noiseSuppression = true
    ): Promise<{ stream: MediaStream; track: MediaStreamTrack } | null> => {
        const devId = targetDeviceId || selectedMicrophoneId.value;
        let stream: MediaStream | null = null;

        const baseAudioConstraints: MediaTrackConstraints = {
            echoCancellation: true,
            noiseSuppression,
            autoGainControl: true,
        };

        if (devId && devId.length > 5) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        ...baseAudioConstraints,
                        deviceId: { exact: devId },
                    },
                });
            } catch (err) {
                console.warn('[useMediaDevices] ⚠️ Exact microphone deviceId failed, trying ideal:', err);
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            ...baseAudioConstraints,
                            deviceId: { ideal: devId },
                        },
                    });
                } catch {}
            }
        }

        if (!stream) {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: baseAudioConstraints,
                });
            } catch (err) {
                console.error('[useMediaDevices] ❌ All microphone stream strategies failed:', err);
                return null;
            }
        }

        if (stream) {
            const track = stream.getAudioTracks()[0];
            if (track) {
                hasMicrophonePermission.value = true;
                const settings = track.getSettings();
                if (settings.deviceId) {
                    selectedMicrophoneId.value = settings.deviceId;
                }
                return { stream, track };
            }
        }

        return null;
    };

    // Switch speaker/audio output sink ID
    const switchSpeaker = async (
        deviceId: string,
        targetElements: (HTMLMediaElement | null)[] = []
    ): Promise<boolean> => {
        selectedSpeakerId.value = deviceId;
        if (typeof window !== 'undefined') {
            localStorage.setItem('ravyno_selected_speaker', deviceId);
        }

        let elements = targetElements.filter((el): el is HTMLMediaElement => el !== null);

        // Fallback: If no elements provided, find all audio/video elements on page
        if (elements.length === 0 && typeof document !== 'undefined') {
            elements = Array.from(document.querySelectorAll<HTMLMediaElement>('audio, video'));
        }

        let anySuccess = false;
        for (const el of elements) {
            if (typeof (el as any).setSinkId === 'function') {
                try {
                    await (el as any).setSinkId(deviceId);
                    anySuccess = true;
                    console.log('[useMediaDevices] 🔊 Audio output setSinkId succeeded on element:', deviceId);
                } catch (err) {
                    console.warn('[useMediaDevices] ⚠️ Failed to setSinkId on element:', err);
                }
            }
        }

        return anySuccess;
    };

    // Setup devicechange listener for hot-plugging
    const handleDeviceChange = () => {
        console.log('[useMediaDevices] 🔄 Device change detected, refreshing device lists...');
        enumerateAllDevices();
    };

    onMounted(() => {
        enumerateAllDevices();
        if (typeof navigator !== 'undefined' && navigator.mediaDevices?.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange', handleDeviceChange);
        }
    });

    onUnmounted(() => {
        if (typeof navigator !== 'undefined' && navigator.mediaDevices?.removeEventListener) {
            navigator.mediaDevices.removeEventListener('devicechange', handleDeviceChange);
        }
    });

    return {
        cameras,
        microphones,
        speakers,
        selectedCameraId,
        selectedMicrophoneId,
        selectedSpeakerId,
        currentFacingMode,
        isEnumerating,
        hasCameraPermission,
        hasMicrophonePermission,
        enumerateCameras,
        enumerateMicrophones,
        enumerateAudioOutputs,
        enumerateAllDevices,
        switchCamera,
        switchMicrophone,
        switchSpeaker,
        getVideoResolution,
    };
}
