package com.getnumber.softphone;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.telecom.CallAudioState;
import android.telecom.Connection;
import android.telecom.ConnectionRequest;
import android.telecom.ConnectionService;
import android.telecom.DisconnectCause;
import android.telecom.PhoneAccount;
import android.telecom.PhoneAccountHandle;
import android.telecom.TelecomManager;
import androidx.annotation.RequiresApi;
import com.getcapacitor.JSObject;

/**
 * Self-Managed Android Telecom ConnectionService (Stage 6.1 & 6.4).
 *
 * Registers Ravyno's self-managed VoIP PhoneAccount with Android's TelecomManager,
 * coordinates system audio focus & Bluetooth/Speaker routing, and handles Lock Screen
 * Answer / Reject / Disconnect callbacks.
 */
@RequiresApi(api = Build.VERSION_CODES.O)
public class GetNumberCallConnectionService extends ConnectionService {

    public static final String EXTRA_CALL_UUID = "com.getnumber.softphone.CALL_UUID";
    public static final String EXTRA_CALL_ID = "com.getnumber.softphone.CALL_ID";
    public static final String EXTRA_CALLER_NAME = "com.getnumber.softphone.CALLER_NAME";
    public static final String EXTRA_HAS_VIDEO = "com.getnumber.softphone.HAS_VIDEO";

    private static volatile GetNumberConnection activeConnection;

    public static PhoneAccountHandle getPhoneAccountHandle(Context context) {
        ComponentName componentName = new ComponentName(context.getApplicationContext(), GetNumberCallConnectionService.class);
        return new PhoneAccountHandle(componentName, "RavynoSelfManagedVoipAccount");
    }

    public static void ensurePhoneAccountRegistered(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        try {
            TelecomManager tm = (TelecomManager) context.getSystemService(Context.TELECOM_SERVICE);
            if (tm == null) return;

            PhoneAccountHandle handle = getPhoneAccountHandle(context);
            PhoneAccount account = PhoneAccount.builder(handle, "Ravyno VoIP")
                .setCapabilities(PhoneAccount.CAPABILITY_SELF_MANAGED)
                .build();
            tm.registerPhoneAccount(account);
        } catch (Exception ignored) {}
    }

    public static void reportIncomingCallToTelecom(Context context, String callUuid, int callId, String callerName, boolean hasVideo) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        try {
            ensurePhoneAccountRegistered(context);
            TelecomManager tm = (TelecomManager) context.getSystemService(Context.TELECOM_SERVICE);
            if (tm == null) return;

            Bundle extras = new Bundle();
            extras.putString(EXTRA_CALL_UUID, callUuid);
            extras.putInt(EXTRA_CALL_ID, callId);
            extras.putString(EXTRA_CALLER_NAME, callerName);
            extras.putBoolean(EXTRA_HAS_VIDEO, hasVideo);

            tm.addNewIncomingCall(getPhoneAccountHandle(context), extras);
        } catch (Exception ignored) {}
    }

    public static GetNumberConnection getActiveConnection() {
        return activeConnection;
    }

    @Override
    public Connection onCreateIncomingConnection(PhoneAccountHandle connectionManagerPhoneAccount, ConnectionRequest request) {
        GetNumberConnection connection = new GetNumberConnection(getApplicationContext());
        connection.setConnectionProperties(Connection.PROPERTY_SELF_MANAGED);
        connection.setAudioModeIsVoip(true);
        connection.setConnectionCapabilities(
            Connection.CAPABILITY_HOLD |
            Connection.CAPABILITY_SUPPORT_HOLD |
            Connection.CAPABILITY_MUTE
        );

        String callUuid = "";
        int callId = 0;
        String callerName = "تماس ورودی";

        if (request.getExtras() != null) {
            callUuid = request.getExtras().getString(EXTRA_CALL_UUID, "");
            callId = request.getExtras().getInt(EXTRA_CALL_ID, 0);
            callerName = request.getExtras().getString(EXTRA_CALLER_NAME, callerName);
        }

        connection.setCallerDisplayName(callerName, TelecomManager.PRESENTATION_ALLOWED);
        connection.setAddress(Uri.fromParts("tel", callerName, null), TelecomManager.PRESENTATION_ALLOWED);
        connection.setCallMetadata(callUuid, callId);
        connection.setInitializing();
        connection.setRinging();

        activeConnection = connection;
        return connection;
    }

    public static class GetNumberConnection extends Connection {
        private final Context appContext;
        private String callUuid;
        private int callId;

        public GetNumberConnection(Context appContext) {
            this.appContext = appContext;
        }

        public void setCallMetadata(String callUuid, int callId) {
            this.callUuid = callUuid;
            this.callId = callId;
        }

        public String getCallUuid() {
            return callUuid;
        }

        public void requestAudioRouteChange(String targetRoute) {
            if ("speaker".equalsIgnoreCase(targetRoute)) {
                setAudioRoute(CallAudioState.ROUTE_SPEAKER);
            } else if ("bluetooth".equalsIgnoreCase(targetRoute)) {
                setAudioRoute(CallAudioState.ROUTE_BLUETOOTH);
            } else {
                setAudioRoute(CallAudioState.ROUTE_EARPIECE);
            }
        }

        @Override
        public void onCallAudioStateChanged(CallAudioState state) {
            super.onCallAudioStateChanged(state);
            if (state == null) return;

            String route = "earpiece";
            int r = state.getRoute();
            if (r == CallAudioState.ROUTE_SPEAKER) {
                route = "speaker";
            } else if (r == CallAudioState.ROUTE_BLUETOOTH) {
                route = "bluetooth";
            } else if (r == CallAudioState.ROUTE_WIRED_HEADSET) {
                route = "wired";
            }

            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.audioRoute = route;
            store.isMuted = state.isMuted();

            JSObject ev = new JSObject();
            ev.put("route", route);
            store.emitEvent("onAudioRouteChanged", ev);
        }

        @Override
        public void onAnswer() {
            super.onAnswer();
            setActive();
            GetNumberForegroundService.cancelIncomingCallNotification(appContext);

            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.answerCallFromLockScreenAsync(appContext, callUuid, (success, answeredElsewhere) -> {
                if (answeredElsewhere) {
                    setDisconnected(new DisconnectCause(DisconnectCause.ANSWERED_ELSEWHERE));
                    destroy();
                    activeConnection = null;
                    return;
                }
                store.emitEvent("onCallAnswered", store.toSnapshotJSObject());
            });

            Intent launchIntent = new Intent(appContext, MainActivity.class);
            launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            launchIntent.putExtra("call_uuid", callUuid);
            launchIntent.putExtra("call_id", String.valueOf(callId));
            launchIntent.putExtra("auto_answer", true);
            appContext.startActivity(launchIntent);
        }

        @Override
        public void onReject() {
            super.onReject();
            GetNumberForegroundService.cancelIncomingCallNotification(appContext);
            setDisconnected(new DisconnectCause(DisconnectCause.REJECTED));
            destroy();
            if (activeConnection == this) activeConnection = null;

            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.status = "rejected";
            store.rejectCallFromLockScreenAsync(appContext, callUuid, "rejected_by_callee");

            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("callId", callId);
            ev.put("reason", "user_rejected");
            store.emitEvent("onCallRejected", ev);
        }

        @Override
        public void onHold() {
            super.onHold();
            setOnHold();
            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.isOnHold = true;
            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("isOnHold", true);
            ev.put("currentState", store.status);
            store.emitEvent("onCallStateChanged", ev);
        }

        @Override
        public void onUnhold() {
            super.onUnhold();
            setActive();
            NativeCallStateStore store = NativeCallStateStore.getInstance();
            store.isOnHold = false;
            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("isOnHold", false);
            ev.put("currentState", store.status);
            store.emitEvent("onCallStateChanged", ev);
        }

        @Override
        public void onDisconnect() {
            super.onDisconnect();
            GetNumberForegroundService.cancelIncomingCallNotification(appContext);
            setDisconnected(new DisconnectCause(DisconnectCause.LOCAL));
            destroy();
            if (activeConnection == this) activeConnection = null;

            NativeCallStateStore store = NativeCallStateStore.getInstance();
            int durationSec = 0;
            if (store.answeredAtMs > 0) {
                durationSec = Math.max(1, (int) ((System.currentTimeMillis() - store.answeredAtMs) / 1000L));
            }
            store.status = "ended";
            store.endCallFromNativeAsync(appContext, callUuid, durationSec);

            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("callId", callId);
            ev.put("reason", "user_hangup");
            ev.put("durationSeconds", durationSec);
            store.emitEvent("onCallEnded", ev);
        }

        @Override
        public void onAbort() {
            super.onAbort();
            GetNumberForegroundService.cancelIncomingCallNotification(appContext);
            setDisconnected(new DisconnectCause(DisconnectCause.CANCELED));
            destroy();
            if (activeConnection == this) activeConnection = null;
        }
    }
}
