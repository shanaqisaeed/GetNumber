package com.getnumber.softphone;

import android.content.Context;
import android.content.SharedPreferences;
import com.getcapacitor.JSObject;
import org.json.JSONObject;
import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Thread-safe Native Call State Store, Device Registry Persistence & Direct Lock-Screen API Client.
 */
public final class NativeCallStateStore {

    private static final String PREFS_NAME = "ravyno_native_calling_prefs";
    private static final NativeCallStateStore INSTANCE = new NativeCallStateStore();

    private final ExecutorService ioExecutor = Executors.newCachedThreadPool();

    // Recent processed call_uuids to prevent duplicate ringing from Local Push + FCM race
    private final Map<String, Long> processedInvites = Collections.synchronizedMap(
        new LinkedHashMap<String, Long>(64, 0.75f, true) {
            @Override
            protected boolean removeEldestEntry(Map.Entry<String, Long> eldest) {
                return size() > 64;
            }
        }
    );

    public interface NativeEventListener {
        void onNativeEvent(String eventName, JSObject payload);
    }

    private volatile NativeEventListener eventListener;

    // Active Call State
    public volatile String callUuid = null;
    public volatile int callId = 0;
    public volatile String status = null; // initiated, ringing, accepted, connecting, active, rejected, cancelled, missed, failed, ended
    public volatile String direction = null;
    public volatile String callerHandle = null;
    public volatile String callerName = null;
    public volatile String destinationLine = null;
    public volatile boolean hasVideo = false;
    public volatile boolean answeredFromLockScreen = false;
    public volatile boolean isMuted = false;
    public volatile boolean isOnHold = false;
    public volatile String audioRoute = "earpiece";
    public volatile String bridgeSessionId = null;
    public volatile String bridgeWsEndpoint = null;
    public volatile JSONObject rtcConfig = null;
    public volatile long startedAtMs = 0L;
    public volatile long answeredAtMs = 0L;

    // Network & Push Telemetry
    public volatile boolean localPushConnected = false;
    public volatile String networkMode = "HYBRID";
    public volatile boolean hasWifi = true;
    public volatile boolean hasCellular = false;
    public volatile String currentSsid = null;

    private NativeCallStateStore() {}

    public static NativeCallStateStore getInstance() {
        return INSTANCE;
    }

    public void setEventListener(NativeEventListener listener) {
        this.eventListener = listener;
    }

    public void emitEvent(String eventName, JSObject data) {
        NativeEventListener listener = this.eventListener;
        if (listener != null) {
            listener.onNativeEvent(eventName, data);
        }
    }

    public synchronized boolean shouldProcessInvite(String uuid, long expiresAtSec) {
        if (uuid == null || uuid.isEmpty()) return false;
        long nowSec = System.currentTimeMillis() / 1000L;
        if (expiresAtSec > 0 && nowSec > expiresAtSec) {
            return false; // Expired invite
        }
        Long prev = processedInvites.get(uuid);
        if (prev != null && (nowSec - prev) < 60) {
            return false; // Duplicate invite within 60s window
        }
        processedInvites.put(uuid, nowSec);
        return true;
    }

    public synchronized void setIncomingCall(
        String uuid,
        int id,
        String handle,
        String displayName,
        String line,
        boolean video
    ) {
        this.callUuid = uuid;
        this.callId = id;
        this.status = "ringing";
        this.direction = "inbound";
        this.callerHandle = handle;
        this.callerName = (displayName != null && !displayName.isEmpty()) ? displayName : handle;
        this.destinationLine = line != null ? line : "";
        this.hasVideo = video;
        this.answeredFromLockScreen = false;
        this.isMuted = false;
        this.isOnHold = false;
        this.bridgeSessionId = null;
        this.bridgeWsEndpoint = null;
        this.rtcConfig = null;
        this.startedAtMs = System.currentTimeMillis();
        this.answeredAtMs = 0L;
    }

    public synchronized JSObject toSnapshotJSObject() {
        JSObject obj = new JSObject();
        boolean activeOrPending = status != null
            && !("rejected".equals(status) || "cancelled".equals(status) || "missed".equals(status) || "failed".equals(status) || "ended".equals(status));

        obj.put("hasActiveOrPendingCall", activeOrPending);
        obj.put("callUuid", callUuid);
        obj.put("callId", callId > 0 ? callId : null);
        obj.put("status", status);
        obj.put("direction", direction);
        obj.put("callerHandle", callerHandle);
        obj.put("callerName", callerName);
        obj.put("destinationLine", destinationLine);
        obj.put("hasVideo", hasVideo);
        obj.put("answeredFromLockScreen", answeredFromLockScreen);
        obj.put("isMuted", isMuted);
        obj.put("isOnHold", isOnHold);
        obj.put("audioRoute", audioRoute);
        if (bridgeSessionId != null) obj.put("bridgeSessionId", bridgeSessionId);
        if (bridgeWsEndpoint != null) obj.put("bridgeWsEndpoint", bridgeWsEndpoint);
        if (rtcConfig != null) obj.put("rtcConfig", rtcConfig);
        if (answeredAtMs > 0) obj.put("answeredAtMs", answeredAtMs);
        return obj;
    }

    // MARK: - Device Configuration Persistence
    private SharedPreferences prefs(Context ctx) {
        return ctx.getApplicationContext().getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
    }

    public String getDeviceUuid(Context ctx) {
        SharedPreferences sp = prefs(ctx);
        String existing = sp.getString("device_uuid", null);
        if (existing != null && !existing.isEmpty()) {
            return existing;
        }
        String generated = "and-" + UUID.randomUUID().toString().toLowerCase();
        sp.edit().putString("device_uuid", generated).apply();
        return generated;
    }

    public void saveRegistrationConfig(
        Context ctx,
        String apiBaseUrl,
        String deviceAuthToken,
        String localPushWsUrl,
        String localPushTicket
    ) {
        SharedPreferences.Editor ed = prefs(ctx).edit();
        if (apiBaseUrl != null && !apiBaseUrl.isEmpty()) ed.putString("api_base_url", apiBaseUrl);
        if (deviceAuthToken != null && !deviceAuthToken.isEmpty()) ed.putString("device_auth_token", deviceAuthToken);
        if (localPushWsUrl != null && !localPushWsUrl.isEmpty()) ed.putString("local_push_ws_url", localPushWsUrl);
        if (localPushTicket != null && !localPushTicket.isEmpty()) ed.putString("local_push_ticket", localPushTicket);
        ed.putBoolean("local_push_enabled", true);
        ed.apply();
    }

    public String getApiBaseUrl(Context ctx) {
        return prefs(ctx).getString("api_base_url", "https://number.ravyno.site");
    }

    public String getDeviceAuthToken(Context ctx) {
        return prefs(ctx).getString("device_auth_token", null);
    }

    public String getLocalPushWsUrl(Context ctx) {
        return prefs(ctx).getString("local_push_ws_url", null);
    }

    public String getLocalPushTicket(Context ctx) {
        return prefs(ctx).getString("local_push_ticket", null);
    }

    public String getFcmToken(Context ctx) {
        return prefs(ctx).getString("fcm_token", null);
    }

    public void setFcmToken(Context ctx, String token) {
        prefs(ctx).edit().putString("fcm_token", token).apply();
    }

    // MARK: - Direct Native Backend REST Calls (Lock Screen Atomic Answer / Reject / Ringing Ack)
    public void sendRingingAckAsync(Context ctx, String targetCallUuid, String provider) {
        if (targetCallUuid == null || targetCallUuid.isEmpty()) return;
        ioExecutor.execute(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("device_uuid", getDeviceUuid(ctx));
                body.put("provider", provider);
                executeJsonPost(ctx, "/api/v1/mobile/calls/" + targetCallUuid + "/ringing-ack", body);
            } catch (Exception ignored) {}
        });
    }

    public interface AnswerCallback {
        void onResult(boolean success, boolean answeredElsewhere);
    }

    public void answerCallFromLockScreenAsync(Context ctx, String targetCallUuid, AnswerCallback callback) {
        if (targetCallUuid == null || targetCallUuid.isEmpty()) {
            if (callback != null) callback.onResult(false, false);
            return;
        }
        ioExecutor.execute(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("device_uuid", getDeviceUuid(ctx));
                HttpResult res = executeJsonPost(ctx, "/api/v1/mobile/calls/" + targetCallUuid + "/answer", body);
                if (res.statusCode == 200 && res.json != null) {
                    JSONObject callObj = res.json.optJSONObject("call");
                    synchronized (NativeCallStateStore.this) {
                        status = "accepted";
                        answeredFromLockScreen = true;
                        answeredAtMs = System.currentTimeMillis();
                        if (callObj != null) {
                            bridgeSessionId = callObj.optString("bridge_session_id", null);
                            bridgeWsEndpoint = callObj.optString("bridge_ws_endpoint", null);
                            rtcConfig = callObj.optJSONObject("rtc_config");
                            if (callId == 0) callId = callObj.optInt("id", 0);
                        }
                    }
                    if (callback != null) callback.onResult(true, false);
                } else if (res.statusCode == 409) {
                    synchronized (NativeCallStateStore.this) {
                        status = "ended";
                    }
                    if (callback != null) callback.onResult(false, true);
                } else {
                    if (callback != null) callback.onResult(false, false);
                }
            } catch (Exception e) {
                if (callback != null) callback.onResult(false, false);
            }
        });
    }

    public void rejectCallFromLockScreenAsync(Context ctx, String targetCallUuid, String reason) {
        if (targetCallUuid == null || targetCallUuid.isEmpty()) return;
        ioExecutor.execute(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("device_uuid", getDeviceUuid(ctx));
                body.put("reason", reason != null ? reason : "rejected_by_callee");
                executeJsonPost(ctx, "/api/v1/mobile/calls/" + targetCallUuid + "/reject", body);
            } catch (Exception ignored) {}
        });
    }

    public void endCallFromNativeAsync(Context ctx, String targetCallUuid, int durationSeconds) {
        if (targetCallUuid == null || targetCallUuid.isEmpty()) return;
        ioExecutor.execute(() -> {
            try {
                JSONObject body = new JSONObject();
                body.put("device_uuid", getDeviceUuid(ctx));
                body.put("duration_seconds", Math.max(0, durationSeconds));
                executeJsonPost(ctx, "/api/v1/mobile/calls/" + targetCallUuid + "/end", body);
            } catch (Exception ignored) {}
        });
    }

    private static final class HttpResult {
        final int statusCode;
        final JSONObject json;
        HttpResult(int statusCode, JSONObject json) {
            this.statusCode = statusCode;
            this.json = json;
        }
    }

    private HttpResult executeJsonPost(Context ctx, String path, JSONObject payload) throws Exception {
        String base = getApiBaseUrl(ctx);
        if (base.endsWith("/")) base = base.substring(0, base.length() - 1);
        URL url = new URL(base + path);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setRequestMethod("POST");
        conn.setConnectTimeout(3500);
        conn.setReadTimeout(3500);
        conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
        conn.setRequestProperty("Accept", "application/json");

        String token = getDeviceAuthToken(ctx);
        if (token != null && !token.isEmpty()) {
            conn.setRequestProperty("Authorization", "Bearer " + token);
            conn.setRequestProperty("X-Device-Token", token);
        }

        conn.setDoOutput(true);
        byte[] bytes = payload.toString().getBytes(StandardCharsets.UTF_8);
        try (OutputStream os = conn.getOutputStream()) {
            os.write(bytes);
        }

        int code = conn.getResponseCode();
        BufferedReader reader = new BufferedReader(new InputStreamReader(
            code >= 200 && code < 400 ? conn.getInputStream() : conn.getErrorStream(),
            StandardCharsets.UTF_8
        ));
        StringBuilder sb = new StringBuilder();
        String line;
        while ((line = reader.readLine()) != null) {
            sb.append(line);
        }
        reader.close();
        conn.disconnect();

        JSONObject parsed = null;
        if (sb.length() > 0) {
            try {
                parsed = new JSONObject(sb.toString());
            } catch (Exception ignored) {}
        }
        return new HttpResult(code, parsed);
    }
}
