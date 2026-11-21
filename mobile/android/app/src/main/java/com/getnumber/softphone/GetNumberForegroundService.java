package com.getnumber.softphone;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;
import androidx.core.app.NotificationCompat;

public class GetNumberForegroundService extends Service {

    public static final String CHANNEL_ID = "getnumber_foreground_channel";
    public static final String CALL_CHANNEL_ID = "getnumber_incoming_call_channel";
    private static final int FOREGROUND_NOTIFICATION_ID = 1001;

    private PowerManager.WakeLock wakeLock;

    @Override
    public void onCreate() {
        super.onCreate();

        // 1. Acquire Partial WakeLock to keep CPU running in deep sleep
        PowerManager powerManager = (PowerManager) getSystemService(Context.POWER_SERVICE);
        if (powerManager != null) {
            wakeLock = powerManager.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "GetNumber::PermanentWakeLock");
            wakeLock.acquire();
        }

        // 2. Create Required Notification Channels
        createNotificationChannels();

        // 3. Promote Service to Foreground Sticky Service
        startForeground(FOREGROUND_NOTIFICATION_ID, buildForegroundNotification());
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && "TRIGGER_INCOMING_CALL".equals(intent.getAction())) {
            String callId = intent.getStringExtra("call_id");
            String caller = intent.getStringExtra("caller");
            showIncomingCallNotification(callId, caller);
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        if (wakeLock != null && wakeLock.isHeld()) {
            wakeLock.release();
        }
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void createNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager == null) return;

            // Channel 1: Background Service Status
            NotificationChannel statusChannel = new NotificationChannel(
                CHANNEL_ID,
                "سرویس پس‌زمینه سافت‌فون",
                NotificationManager.IMPORTANCE_LOW
            );
            statusChannel.setDescription("فعال نگه داشتن ارتباط اینترانت و دریافت تماس‌ها");
            manager.createNotificationChannel(statusChannel);

            // Channel 2: Real-time Incoming Call Heads-Up Screen
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
        PendingIntent pendingIntent = PendingIntent.getActivity(
            this, 0, notificationIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0)
        );

        return new NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("سافت‌فون Ravyno آماده تماس است")
            .setContentText("سرویس زنگ بومی و دریافت پیامک در پس‌زمینه فعال است")
            .setSmallIcon(android.R.drawable.stat_sys_phone_call)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build();
    }

    public void showIncomingCallNotification(String callId, String caller) {
        Intent fullScreenIntent = new Intent(this, MainActivity.class);
        fullScreenIntent.putExtra("call_id", callId);
        fullScreenIntent.putExtra("caller", caller);
        fullScreenIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);

        PendingIntent fullScreenPendingIntent = PendingIntent.getActivity(
            this,
            (int) System.currentTimeMillis(),
            fullScreenIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0)
        );

        Intent answerIntent = new Intent(this, MainActivity.class);
        answerIntent.putExtra("call_id", callId);
        answerIntent.putExtra("auto_answer", true);
        PendingIntent answerPendingIntent = PendingIntent.getActivity(
            this,
            (int) System.currentTimeMillis() + 1,
            answerIntent,
            PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0)
        );

        Notification notification = new NotificationCompat.Builder(this, CALL_CHANNEL_ID)
            .setContentTitle("📞 تماس صوتی ورودی")
            .setContentText("تماس از طرف: " + (caller != null ? caller : "شماره ناشناس"))
            .setSmallIcon(android.R.drawable.sym_call_incoming)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setAutoCancel(true)
            .setOngoing(true)
            .setFullScreenIntent(fullScreenPendingIntent, true)
            .addAction(android.R.drawable.ic_menu_call, "پاسخ به تماس", answerPendingIntent)
            .build();

        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.notify(2002, notification);
        }
    }
}
