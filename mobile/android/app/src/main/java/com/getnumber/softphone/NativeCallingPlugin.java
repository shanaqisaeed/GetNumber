package com.getnumber.softphone;

import android.content.Context;
import android.content.Intent;
import android.media.AudioDeviceInfo;
import android.media.AudioManager;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.os.Build;
import android.telecom.DisconnectCause;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Unified Native Calling Capacitor Plugin for Android (`NativeCalling`).
 * Implements the exact same 9 Commands and 9 Events contract as iOS (Stage 5.4 / 6.5).
 */
@CapacitorPlugin(name = "NativeCalling")
public class NativeCallingPlugin extends Plugin implements NativeCallStateStore.NativeEventListener {

    @Override
    public void load() {
        super.load();
        NativeCallStateStore.getInstance().setEventListener(this);
        refreshNetworkCapabilities();
    }

    @Override
    protected void handleOnDestroy() {
        NativeCallStateStore.getInstance().setEventListener(null);
        super.handleOnDestroy();
    }

    @Override
    public void onNativeEvent(String eventName, JSObject payload) {
        notifyListeners(eventName, payload, true);
    }

    private void refreshNetworkCapabilities() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getContext().getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return;
            Network active = cm.getActiveNetwork();
            if (active == null) {
                NativeCallStateStore.getInstance().networkMode = "OFFLINE";
                NativeCallStateStore.getInstance().hasWifi = false;
                NativeCallStateStore.getInstance().hasCellular = false;
                return;
            }
            NetworkCapabilities caps = cm.getNetworkCapabilities(active);
            if (caps == null) return;

            boolean wifi = caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI);
            boolean cell = caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR);
            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.hasWifi = wifi;
            store.hasCellular = cell;
            if (wifi && store.localPushConnected) {
                store.networkMode = "HYBRID";
            } else if (wifi) {
                store.networkMode = "INTRANET";
            } else if (cell) {
                store.networkMode = "PUBLIC";
            }
        } catch (Exception ignored) {}
    }

    // MARK: - 1. registerDevice
    @PluginMethod
    public void registerDevice(PluginCall call) {
        Context ctx = getContext();
        NativeCallStateStore store = NativeCallStateStore.getInstance();

        String apiBaseUrl = call.getString("apiBaseUrl", store.getApiBaseUrl(ctx));
        String deviceAuthToken = call.getString("deviceAuthToken", store.getDeviceAuthToken(ctx));
        String localPushWsUrl = call.getString("localPushWsUrl", store.getLocalPushWsUrl(ctx));
        String localPushTicket = call.getString("localPushTicket", store.getLocalPushTicket(ctx));

        store.saveRegistrationConfig(ctx, apiBaseUrl, deviceAuthToken, localPushWsUrl, localPushTicket);

        // Trigger Local Push client reconnection with updated ticket/endpoint
        try {
            Intent svc = new Intent(ctx, GetNumberForegroundService.class);
            svc.setAction(GetNumberForegroundService.ACTION_RECONNECT_LOCAL_PUSH);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                ctx.startForegroundService(svc);
            } else {
                ctx.startService(svc);
            }
        } catch (Exception ignored) {}

        refreshNetworkCapabilities();

        JSObject res = new JSObject();
        res.put("bridgeVersion", "1.0.0");
        res.put("deviceUuid", store.getDeviceUuid(ctx));
        res.put("platform", "android");
        res.put("osVersion", "Android " + Build.VERSION.RELEASE + " (API " + Build.VERSION.SDK_INT + ")");
        res.put("appVersion", "1.0.0");
        res.put("voipPushToken", JSObject.NULL);
        String fcmToken = store.getFcmToken(ctx);
        res.put("notificationPushToken", fcmToken != null ? fcmToken : JSObject.NULL);
        res.put("localPushConnected", store.localPushConnected);
        res.put("networkMode", store.networkMode);
        call.resolve(res);
    }

    // MARK: - 2. getCallState
    @PluginMethod
    public void getCallState(PluginCall call) {
        call.resolve(NativeCallStateStore.getInstance().toSnapshotJSObject());
    }

    // MARK: - 3. answerCall
    @PluginMethod
    public void answerCall(PluginCall call) {
        Context ctx = getContext();
        NativeCallStateStore store = NativeCallStateStore.getInstance();
        String callUuid = call.getString("callUuid", store.callUuid);

        GetNumberForegroundService.cancelIncomingCallNotification(ctx);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null) {
                conn.setActive();
            }
        }

        synchronized (store) {
            if (callUuid != null && callUuid.equals(store.callUuid)) {
                store.status = "accepted";
                if (store.answeredAtMs == 0L) {
                    store.answeredAtMs = System.currentTimeMillis();
                }
            }
        }

        configureAudioManagerForVoip(true);

        JSObject res = new JSObject();
        res.put("success", true);
        res.put("state", store.toSnapshotJSObject());
        call.resolve(res);
    }

    // MARK: - 4. rejectCall
    @PluginMethod
    public void rejectCall(PluginCall call) {
        Context ctx = getContext();
        NativeCallStateStore store = NativeCallStateStore.getInstance();
        String callUuid = call.getString("callUuid", store.callUuid);

        GetNumberForegroundService.cancelIncomingCallNotification(ctx);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null) {
                conn.setDisconnected(new DisconnectCause(DisconnectCause.REJECTED));
                conn.destroy();
            }
        }

        store.status = "rejected";
        store.rejectCallFromLockScreenAsync(ctx, callUuid, call.getString("reason", "rejected_by_callee"));
        configureAudioManagerForVoip(false);

        JSObject res = new JSObject();
        res.put("success", true);
        call.resolve(res);
    }

    // MARK: - 5. endCall
    @PluginMethod
    public void endCall(PluginCall call) {
        Context ctx = getContext();
        NativeCallStateStore store = NativeCallStateStore.getInstance();
        String callUuid = call.getString("callUuid", store.callUuid);
        Integer durationSec = call.getInt("durationSeconds", 0);

        GetNumberForegroundService.cancelIncomingCallNotification(ctx);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null) {
                conn.setDisconnected(new DisconnectCause(DisconnectCause.LOCAL));
                conn.destroy();
            }
        }

        store.status = "ended";
        if (callUuid != null && !callUuid.isEmpty()) {
            store.endCallFromNativeAsync(ctx, callUuid, durationSec != null ? durationSec : 0);
        }
        configureAudioManagerForVoip(false);

        JSObject res = new JSObject();
        res.put("success", true);
        call.resolve(res);
    }

    // MARK: - 6. setMute
    @PluginMethod
    public void setMute(PluginCall call) {
        boolean muted = Boolean.TRUE.equals(call.getBoolean("muted", false));
        try {
            AudioManager am = (AudioManager) getContext().getSystemService(Context.AUDIO_SERVICE);
            if (am != null) {
                am.setMicrophoneMute(muted);
            }
        } catch (Exception ignored) {}

        NativeCallStateStore store = NativeCallStateStore.getInstance();
        store.isMuted = muted;

        JSObject res = new JSObject();
        res.put("success", true);
        res.put("muted", muted);
        call.resolve(res);
    }

    // MARK: - 7. setHold
    @PluginMethod
    public void setHold(PluginCall call) {
        boolean onHold = Boolean.TRUE.equals(call.getBoolean("onHold", false));
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null) {
                if (onHold) conn.onHold();
                else conn.onUnhold();
            }
        }
        NativeCallStateStore.getInstance().isOnHold = onHold;

        JSObject res = new JSObject();
        res.put("success", true);
        res.put("onHold", onHold);
        call.resolve(res);
    }

    // MARK: - 8. setAudioRoute
    @PluginMethod
    public void setAudioRoute(PluginCall call) {
        String target = call.getString("route", "earpiece");
        if (target == null) target = "earpiece";

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null) {
                conn.requestAudioRouteChange(target);
            }
        }

        try {
            AudioManager am = (AudioManager) getContext().getSystemService(Context.AUDIO_SERVICE);
            if (am != null) {
                am.setMode(AudioManager.MODE_IN_COMMUNICATION);
                boolean speaker = "speaker".equalsIgnoreCase(target);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    for (AudioDeviceInfo dev : am.getAvailableCommunicationDevices()) {
                        if (speaker && dev.getType() == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER) {
                            am.setCommunicationDevice(dev);
                            break;
                        } else if (!speaker && dev.getType() == AudioDeviceInfo.TYPE_BUILTIN_EARPIECE) {
                            am.setCommunicationDevice(dev);
                            break;
                        } else if ("bluetooth".equalsIgnoreCase(target) && (dev.getType() == AudioDeviceInfo.TYPE_BLUETOOTH_SCO || dev.getType() == AudioDeviceInfo.TYPE_BLE_HEADSET)) {
                            am.setCommunicationDevice(dev);
                            break;
                        }
                    }
                } else {
                    am.setSpeakerphoneOn(speaker);
                }
            }
        } catch (Exception ignored) {}

        NativeCallStateStore store = NativeCallStateStore.getInstance();
        store.audioRoute = target.toLowerCase();

        JSObject res = new JSObject();
        res.put("success", true);
        res.put("activeRoute", store.audioRoute);
        call.resolve(res);
    }

    // MARK: - 9. getNetworkMode
    @PluginMethod
    public void getNetworkMode(PluginCall call) {
        refreshNetworkCapabilities();
        NativeCallStateStore store = NativeCallStateStore.getInstance();
        JSObject res = new JSObject();
        res.put("mode", store.networkMode);
        res.put("hasWifi", store.hasWifi);
        res.put("hasCellular", store.hasCellular);
        res.put("ssid", store.currentSsid != null ? store.currentSsid : JSObject.NULL);
        res.put("localPushConnected", store.localPushConnected);
        res.put("internalApiReachable", !"OFFLINE".equals(store.networkMode));
        res.put("lastProbeAtMs", System.currentTimeMillis());
        call.resolve(res);
    }

    private void configureAudioManagerForVoip(boolean active) {
        try {
            AudioManager am = (AudioManager) getContext().getSystemService(Context.AUDIO_SERVICE);
            if (am == null) return;
            if (active) {
                am.setMode(AudioManager.MODE_IN_COMMUNICATION);
            } else {
                am.setMode(AudioManager.MODE_NORMAL);
                am.setMicrophoneMute(false);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    am.clearCommunicationDevice();
                } else {
                    am.setSpeakerphoneOn(false);
                }
            }
        } catch (Exception ignored) {}
    }
}
