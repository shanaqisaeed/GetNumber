package com.getnumber.softphone;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Person;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;
import android.telecom.DisconnectCause;
import androidx.core.app.NotificationCompat;
import com.getcapacitor.JSObject;

/**
 * Android 14 (API 34) Compliant Intranet Local Push & Incoming Call Service (Stage 6.1 - 6.3).
 *
 * Key rules enforced:
 * - Never uses `FOREGROUND_SERVICE_TYPE_PHONE_CALL` or `MICROPHONE` for background idle keepalive.
 * - Uses `FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING` / `SPECIAL_USE` for Intranet Local Push socket reception.
 * - Hosts `LocalPushWebSocketClient` (bound to Wi-Fi network with heartbeat & backoff + jitter).
 * - Displays high-priority `CallStyle` incoming call notifications with Lock Screen Answer & Decline actions,
 *   checking `canUseFullScreenIntent()` on Android 14+.
 */
public class GetNumberForegroundService extends Service implements LocalPushWebSocketClient.CallInviteHandler {

    public static final String CHANNEL_ID = "getnumber_foreground_channel";
    public static final String CALL_CHANNEL_ID = "getnumber_incoming_call_channel";
    public static final int FOREGROUND_NOTIFICATION_ID = 1001;
    public static final int INCOMING_CALL_NOTIFICATION_ID = 2002;

    public static final String ACTION_START_LOCAL_PUSH = "com.getnumber.softphone.START_LOCAL_PUSH";
    public static final String ACTION_RECONNECT_LOCAL_PUSH = "com.getnumber.softphone.RECONNECT_LOCAL_PUSH";
    public static final String ACTION_TRIGGER_INCOMING_CALL = "TRIGGER_INCOMING_CALL";
    public static final String ACTION_DISMISS_INCOMING_CALL = "DISMISS_INCOMING_CALL";

    private PowerManager.WakeLock wakeLock;
    private LocalPushWebSocketClient localPushClient;

    public static void cancelIncomingCallNotification(Context context) {
        NotificationManager manager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.cancel(INCOMING_CALL_NOTIFICATION_ID);
        }
    }

    @Override
    public void onCreate() {
        super.onCreate();

        createNotificationChannels();
        promoteToForegroundSafely();

        localPushClient = new LocalPushWebSocketClient(this, this);
        localPushClient.start();
    }

    private void promoteToForegroundSafely() {
        Notification notification = buildForegroundNotification();
        try {
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(
                    FOREGROUND_NOTIFICATION_ID,
                    notification,
                    ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING
                );
            } else {
                startForeground(FOREGROUND_NOTIFICATION_ID, notification);
            }
        } catch (Exception ignored) {
            // Gracefully tolerate Android 14+ background start restrictions if started without exemption
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null) {
            String action = intent.getAction();
            if (ACTION_TRIGGER_INCOMING_CALL.equals(action)) {
                String callUuid = intent.getStringExtra("call_uuid");
                int callId = intent.getIntExtra("call_id", 0);
                if (callUuid == null || callUuid.isEmpty()) {
                    callUuid = intent.getStringExtra("call_id");
                }
                String caller = intent.getStringExtra("caller");
                String callerName = intent.getStringExtra("caller_name");
                String line = intent.getStringExtra("destination_line");
                boolean hasVideo = intent.getBooleanExtra("has_video", false);
                onIncomingCallInvite(
                    callUuid != null ? callUuid : "call-" + System.currentTimeMillis(),
                    callId,
                    caller != null ? caller : "تماس ورودی",
                    callerName != null ? callerName : caller,
                    line != null ? line : "",
                    hasVideo
                );
            } else if (ACTION_RECONNECT_LOCAL_PUSH.equals(action) || ACTION_START_LOCAL_PUSH.equals(action)) {
                if (localPushClient != null) {
                    localPushClient.reconnectNow();
                }
            } else if (ACTION_DISMISS_INCOMING_CALL.equals(action)) {
                cancelIncomingCallNotification(this);
            }
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        if (localPushClient != null) {
            localPushClient.stop();
        }
        releaseWakeLock();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    // MARK: - LocalPushWebSocketClient.CallInviteHandler (Also used by FCM Public Mode)
    @Override
    public void onIncomingCallInvite(
        String callUuid,
        int callId,
        String callerHandle,
        String callerName,
        String destinationLine,
        boolean hasVideo
    ) {
        acquireShortCallWakeLock();

        NativeCallStateStore store = NativeCallStateStore.getInstance();
        store.setIncomingCall(callUuid, callId, callerHandle, callerName, destinationLine, hasVideo);
        store.sendRingingAckAsync(this, callUuid, store.localPushConnected ? "android_local" : "android_fcm");

        // 1. Register incoming call with Android Telecom Framework
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.reportIncomingCallToTelecom(this, callUuid, callId, callerName, hasVideo);
        }

        // 2. Display High-Priority CallStyle Incoming Call Notification (with Full-Screen Intent on Lock Screen)
        showIncomingCallNotification(callUuid, callId, callerHandle, callerName, hasVideo);

        // 3. Emit onIncomingCall to Capacitor Vue Bridge if WebView is active
        JSObject ev = new JSObject();
        ev.put("callUuid", callUuid);
        ev.put("callId", callId);
        ev.put("callerHandle", callerHandle);
        ev.put("callerName", callerName);
        ev.put("destinationLine", destinationLine);
        ev.put("hasVideo", hasVideo);
        ev.put("provider", store.localPushConnected ? "android_local" : "android_fcm");
        store.emitEvent("onIncomingCall", ev);
    }

    @Override
    public void onCallCancelledOrHandledElsewhere(String callUuid, String eventType) {
        cancelIncomingCallNotification(this);
        releaseWakeLock();

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.GetNumberConnection conn = GetNumberCallConnectionService.getActiveConnection();
            if (conn != null && (callUuid == null || callUuid.isEmpty() || callUuid.equals(conn.getCallUuid()))) {
                int cause = "call.answered_elsewhere".equals(eventType)
                    ? DisconnectCause.ANSWERED_ELSEWHERE
                    : DisconnectCause.CANCELED;
                conn.setDisconnected(new DisconnectCause(cause));
                conn.destroy();
            }
        }

        NativeCallStateStore store = NativeCallStateStore.getInstance();
        if (store.callUuid != null && (callUuid == null || callUuid.equals(store.callUuid))) {
            store.status = "call.answered_elsewhere".equals(eventType) ? "ended" : "cancelled";
        }

        JSObject ev = new JSObject();
        ev.put("callUuid", callUuid);
        ev.put("reason", "call.answered_elsewhere".equals(eventType) ? "answered_elsewhere" : "cancelled");
        store.emitEvent("onCallRejected", ev);
    }

    private void acquireShortCallWakeLock() {
        try {
            releaseWakeLock();
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            if (pm != null) {
                wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GetNumber::IncomingCallWakeLock");
                wakeLock.acquire(60 * 1000L); // Max 60 seconds during ringing
            }
        } catch (Exception ignored) {}
    }

    private void releaseWakeLock() {
        try {
            if (wakeLock != null && wakeLock.isHeld()) {
                wakeLock.release();
            }
        } catch (Exception ignored) {}
    }

    private void createNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager == null) return;

            NotificationChannel statusChannel = new NotificationChannel(
                CHANNEL_ID,
                "سرویس ارتباط پایدار اینترانت",
                NotificationManager.IMPORTANCE_LOW
            );
            statusChannel.setDescription("حفظ ارتباط مستقیم شبکه داخلی جهت دریافت تماس بدون نیاز به اینترنت");
            manager.createNotificationChannel(statusChannel);

            NotificationChannel callChannel = new NotificationChannel(
                CALL_CHANNEL_ID,
                "تماس‌های ورودی بومی",
                NotificationManager.IMPORTANCE_HIGH
            );
            callChannel.setDescription("نمایش تمام‌صفحه و زنگ خوردن تماس‌ها روی صفحه قفل");
            callChannel.enableVibration(true);
            callChannel.setVibrationPattern(new long[]{500, 250, 500, 250, 500, 250, 500});
            callChannel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            manager.createNotificationChannel(callChannel);
        }
    }

    private Notification buildForegroundNotification() {
        Intent notificationIntent = new Intent(this, MainActivity.class);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0);
        PendingIntent pendingIntent = PendingIntent.getActivity(this, 0, notificationIntent, flags);

        return new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("سافت‌فون Ravyno آماده دریافت تماس است")
            .setContentText("ارتباط مستقیم شبکه داخلی و سرویس زنگ بومی فعال است")
            .setSmallIcon(android.R.drawable.stat_sys_phone_call)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build();
    }

    public void showIncomingCallNotification(String callUuid, int callId, String callerHandle, String callerName, boolean hasVideo) {
        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager == null) return;

        int piFlags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0);

        Intent fullScreenIntent = new Intent(this, MainActivity.class);
        fullScreenIntent.putExtra("call_uuid", callUuid);
        fullScreenIntent.putExtra("call_id", String.valueOf(callId));
        fullScreenIntent.putExtra("caller", callerHandle);
        fullScreenIntent.putExtra("has_video", hasVideo);
        fullScreenIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent fullScreenPendingIntent = PendingIntent.getActivity(this, 101, fullScreenIntent, piFlags);

        Intent answerBroadcast = new Intent(this, CallActionReceiver.class);
        answerBroadcast.setAction(CallActionReceiver.ACTION_ANSWER);
        answerBroadcast.putExtra("call_uuid", callUuid);
        answerBroadcast.putExtra("call_id", callId);
        answerBroadcast.putExtra("has_video", hasVideo);
        PendingIntent answerPendingIntent = PendingIntent.getBroadcast(this, 102, answerBroadcast, piFlags);

        Intent rejectBroadcast = new Intent(this, CallActionReceiver.class);
        rejectBroadcast.setAction(CallActionReceiver.ACTION_REJECT);
        rejectBroadcast.putExtra("call_uuid", callUuid);
        rejectBroadcast.putExtra("call_id", callId);
        PendingIntent rejectPendingIntent = PendingIntent.getBroadcast(this, 103, rejectBroadcast, piFlags);

        String displayTitle = (callerName != null && !callerName.isEmpty()) ? callerName : (callerHandle != null ? callerHandle : "شماره ناشناس");

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CALL_CHANNEL_ID)
            .setContentTitle(hasVideo ? "📹 تماس تصویری ورودی" : "📞 تماس صوتی ورودی")
            .setContentText("تماس از طرف: " + displayTitle)
            .setSmallIcon(android.R.drawable.sym_call_incoming)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setAutoCancel(false)
            .setOngoing(true)
            .setTimeoutAfter(45000L)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "رد تماس", rejectPendingIntent)
            .addAction(android.R.drawable.ic_menu_call, "پاسخ به تماس", answerPendingIntent);

        // Check Android 14+ Full-Screen Intent permission before attaching
        boolean allowFsi = true;
        if (Build.VERSION.SDK_INT >= 34) {
            allowFsi = manager.canUseFullScreenIntent();
        }
        if (allowFsi) {
            builder.setFullScreenIntent(fullScreenPendingIntent, true);
        } else {
            builder.setContentIntent(fullScreenPendingIntent);
        }

        // On Android 12+ (API 31+), use native Android CallStyle notification
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            Notification.Builder nativeBuilder = new Notification.Builder(this, CALL_CHANNEL_ID)
                .setSmallIcon(android.R.drawable.sym_call_incoming)
                .setContentTitle(hasVideo ? "تماس تصویری ورودی" : "تماس صوتی ورودی")
                .setContentText(displayTitle)
                .setCategory(Notification.CATEGORY_CALL)
                .setVisibility(Notification.VISIBILITY_PUBLIC)
                .setOngoing(true)
                .setTimeoutAfter(45000L)
                .setStyle(Notification.CallStyle.forIncomingCall(
                    new Person.Builder().setName(displayTitle).setImportant(true).build(),
                    rejectPendingIntent,
                    answerPendingIntent
                ));
            if (allowFsi) {
                nativeBuilder.setFullScreenIntent(fullScreenPendingIntent, true);
            } else {
                nativeBuilder.setContentIntent(fullScreenPendingIntent);
            }
            manager.notify(INCOMING_CALL_NOTIFICATION_ID, nativeBuilder.build());
            return;
        }

        manager.notify(INCOMING_CALL_NOTIFICATION_ID, builder.build());
    }
}
