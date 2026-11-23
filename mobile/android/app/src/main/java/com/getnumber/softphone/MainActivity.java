package com.getnumber.softphone;

import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.WindowManager;
import android.webkit.PermissionRequest;
import android.webkit.WebChromeClient;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(NativeCallingPlugin.class);
        super.onCreate(savedInstanceState);

        // 1. Configure Window Flags to Turn Screen On and Display Over Keyguard / Lock Screen
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
        } else {
            getWindow().addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED |
                WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD |
                WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON |
                WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON
            );
        }

        // 2. Register Self-Managed Telecom PhoneAccount
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            GetNumberCallConnectionService.ensurePhoneAccountRegistered(this);
        }

        // 3. Configure WebView WebRTC Audio/Video Permission Delegation
        configureWebViewMediaPermissions();

        // 4. Start Intranet Local Push Service safely
        startLocalPushServiceSafely();

        // 5. Request Battery Optimization Exemption for reliable Intranet Local Push keepalive
        requestBatteryOptimizationExemption();

        // 6. Handle incoming call extras from Lock Screen Notification / Telecom intent
        handleIntent(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        handleIntent(intent);
    }

    private void configureWebViewMediaPermissions() {
        if (getBridge() == null || getBridge().getWebView() == null) return;
        final WebChromeClient existingClient = getBridge().getWebView().getWebChromeClient();
        getBridge().getWebView().setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> {
                    try {
                        request.grant(request.getResources());
                    } catch (Exception e) {
                        if (existingClient != null) {
                            existingClient.onPermissionRequest(request);
                        }
                    }
                });
            }
        });
    }

    private void startLocalPushServiceSafely() {
        try {
            Intent serviceIntent = new Intent(this, GetNumberForegroundService.class);
            serviceIntent.setAction(GetNumberForegroundService.ACTION_START_LOCAL_PUSH);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(serviceIntent);
            } else {
                startService(serviceIntent);
            }
        } catch (Exception ignored) {}
    }

    private void handleIntent(Intent intent) {
        if (intent == null) return;

        String callUuid = intent.getStringExtra("call_uuid");
        String callId = intent.getStringExtra("call_id");
        boolean autoAnswer = intent.getBooleanExtra("auto_answer", false);
        boolean hasVideo = intent.getBooleanExtra("has_video", false);

        if ((callUuid != null && !callUuid.isEmpty()) || (callId != null && !callId.isEmpty())) {
            String safeCallId = (callId != null && !callId.isEmpty()) ? callId : "";
            String safeCallUuid = (callUuid != null && !callUuid.isEmpty()) ? callUuid : "";
            String js = String.format(
                "window.dispatchEvent(new CustomEvent('native_call_action', { detail: { callId: '%s', callUuid: '%s', autoAnswer: %b, hasVideo: %b } }));",
                safeCallId, safeCallUuid, autoAnswer, hasVideo
            );
            if (getBridge() != null && getBridge().getWebView() != null) {
                getBridge().getWebView().postDelayed(
                    () -> getBridge().getWebView().evaluateJavascript(js, null),
                    350
                );
            }
        }
    }

    private void requestBatteryOptimizationExemption() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            if (pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
                try {
                    Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                    intent.setData(Uri.parse("package:" + getPackageName()));
                    startActivity(intent);
                } catch (Exception ignored) {
                }
            }
        }
    }
}
