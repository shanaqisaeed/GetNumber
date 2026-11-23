package com.getnumber.softphone;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;
import android.telecom.DisconnectCause;
import com.getcapacitor.JSObject;

/**
 * Handles Lock Screen Notification CallStyle Actions (Answer, Reject, End).
 * Immediately performs the atomic backend call (`answer` or `reject`) and coordinates
 * with Android Telecom ConnectionService and Capacitor WebView.
 */
public class CallActionReceiver extends BroadcastReceiver {

    public static final String ACTION_ANSWER = "com.getnumber.softphone.ACTION_CALL_ANSWER";
    public static final String ACTION_REJECT = "com.getnumber.softphone.ACTION_CALL_REJECT";
    public static final String ACTION_END = "com.getnumber.softphone.ACTION_CALL_END";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;

        String action = intent.getAction();
        String callUuid = intent.getStringExtra("call_uuid");
        int callId = intent.getIntExtra("call_id", 0);
        boolean hasVideo = intent.getBooleanExtra("has_video", false);

        NativeCallStateStore store = NativeCallStateStore.getInstance();
        if (callUuid == null || callUuid.isEmpty()) {
            callUuid = store.callUuid;
        }
        if (callId == 0) {
            callId = store.callId;
        }

        GetNumberForegroundService.cancelIncomingCallNotification(context);

        if (ACTION_ANSWER.equals(action)) {
            final String targetUuid = callUuid;
            final int targetId = callId;

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
                if (conn != null) {
                    conn.setActive();
                }
            }

            // 1. Atomically accept call on backend immediately (even before WebView finishes loading)
            store.answerCallFromLockScreenAsync(context, targetUuid, (success, answeredElsewhere) -> {
                if (answeredElsewhere) {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
                        if (conn != null) {
                            conn.setDisconnected(new DisconnectCause(DisconnectCause.ANSWERED_ELSEWHERE));
                            conn.destroy();
                        }
                    }
                    return;
                }
                store.emitEvent("onCallAnswered", store.toSnapshotJSObject());
            });

            // 2. Bring MainActivity / WebView to front over Lock Screen
            Intent launchIntent = new Intent(context, MainActivity.class);
            launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            launchIntent.putExtra("call_uuid", targetUuid);
            launchIntent.putExtra("call_id", String.valueOf(targetId));
            launchIntent.putExtra("has_video", hasVideo);
            launchIntent.putExtra("auto_answer", true);
            context.startActivity(launchIntent);

        } else if (ACTION_REJECT.equals(action)) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
                if (conn != null) {
                    conn.setDisconnected(new DisconnectCause(DisconnectCause.REJECTED));
                    conn.destroy();
                }
            }

            store.status = "rejected";
            store.rejectCallFromLockScreenAsync(context, callUuid, "rejected_by_callee");

            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("callId", callId);
            ev.put("reason", "user_rejected");
            store.emitEvent("onCallRejected", ev);

        } else if (ACTION_END.equals(action)) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
                if (conn != null) {
                    conn.onDisconnect();
                    return;
                }
            }
            int durationSec = 0;
            if (store.answeredAtMs > 0) {
                durationSec = Math.max(1, (int) ((System.currentTimeMillis() - store.answeredAtMs) / 1000L));
            }
            store.status = "ended";
            store.endCallFromNativeAsync(context, callUuid, durationSec);

            JSObject ev = new JSObject();
            ev.put("callUuid", callUuid);
            ev.put("callId", callId);
            ev.put("reason", "user_hangup");
            ev.put("durationSeconds", durationSec);
            store.emitEvent("onCallEnded", ev);
        }
    }
}
