package com.getnumber.softphone;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;
import android.os.Bundle;

/**
 * High-Priority FCM Data & Push Intent Receiver (Stage 6.1 Public Mode).
 *
 * Receives incoming data-only push payloads (from FCM c2dm intent or internal push broadcast),
 * validates expiration (`expires_at`) and deduplication (`call_uuid`), and hands the call
 * to `GetNumberForegroundService` + `GetNumberCallConnectionService` (Android Telecom).
 */
public class RavynoPushReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) return;
        Bundle extras = intent.getExtras();
        if (extras == null) return;

        String eventType = extras.getString("event_type", "");
        String callUuid = extras.getString("call_uuid", "");
        if (callUuid == null || callUuid.isEmpty()) {
            return;
        }

        if ("call.cancelled".equals(eventType) || "call.answered_elsewhere".equals(eventType) || "call.missed".equals(eventType) || "call.rejected".equals(eventType)) {
            Intent svc = new Intent(context, GetNumberForegroundService.class);
            svc.setAction(GetNumberForegroundService.ACTION_DISMISS_INCOMING_CALL);
            svc.putExtra("call_uuid", callUuid);
            svc.putExtra("event_type", eventType);
            startServiceSafely(context, svc);
            return;
        }

        if ("call.invite".equals(eventType) || eventType.isEmpty()) {
            long expiresAt = 0L;
            try {
                String expStr = extras.getString("expires_at", "0");
                expiresAt = Long.parseLong(expStr != null ? expStr : "0");
            } catch (Exception ignored) {}

            if (!NativeCallStateStore.getInstance().shouldProcessInvite(callUuid, expiresAt)) {
                return;
            }

            int callId = 0;
            try {
                String idStr = extras.getString("call_id", "0");
                callId = Integer.parseInt(idStr != null ? idStr : "0");
            } catch (Exception ignored) {}

            String callerHandle = extras.getString("caller_handle", "تماس ورودی");
            String callerName = extras.getString("caller_display_name", callerHandle);
            String destLine = extras.getString("destination_line", "");
            boolean hasVideo = "true".equalsIgnoreCase(extras.getString("has_video", "false"));

            Intent svc = new Intent(context, GetNumberForegroundService.class);
            svc.setAction(GetNumberForegroundService.ACTION_TRIGGER_INCOMING_CALL);
            svc.putExtra("call_uuid", callUuid);
            svc.putExtra("call_id", callId);
            svc.putExtra("caller", callerHandle);
            svc.putExtra("caller_name", callerName);
            svc.putExtra("destination_line", destLine);
            svc.putExtra("has_video", hasVideo);
            startServiceSafely(context, svc);
        }
    }

    private void startServiceSafely(Context context, Intent svc) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(svc);
            } else {
                context.startService(svc);
            }
        } catch (Exception ignored) {}
    }
}
