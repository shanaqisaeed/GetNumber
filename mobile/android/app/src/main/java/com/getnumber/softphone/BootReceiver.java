package com.getnumber.softphone;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

/**
 * Auto-start receiver on device reboot (Stage 6.3).
 * Safely respects Android 12-14+ ForegroundServiceStartNotAllowedException limits.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) return;

        String action = intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(action) ||
            "android.intent.action.QUICKBOOT_POWERON".equals(action) ||
            "com.htc.intent.action.QUICKBOOT_POWERON".equals(action)) {

            String wsUrl = NativeCallStateStore.getInstance().getLocalPushWsUrl(context);
            if (wsUrl == null || wsUrl.isEmpty()) {
                return;
            }

            try {
                Intent serviceIntent = new Intent(context, GetNumberForegroundService.class);
                serviceIntent.setAction(GetNumberForegroundService.ACTION_START_LOCAL_PUSH);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    context.startForegroundService(serviceIntent);
                } else {
                    context.startService(serviceIntent);
                }
            } catch (Exception ignored) {
                // On strict OEM / Android 14+ devices where boot FGS start is restricted without exemption,
                // fail silently without crashing the application process.
            }
        }
    }
}
