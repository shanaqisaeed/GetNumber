package com.getnumber.softphone;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.NetworkRequest;
import android.net.Uri;
import android.util.Base64;
import com.getcapacitor.JSObject;
import org.json.JSONObject;
import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.ByteArrayOutputStream;
import java.io.DataInputStream;
import java.io.IOException;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import javax.net.SocketFactory;
import javax.net.ssl.SSLSocketFactory;

/**
 * Native Persistent Local Push Client for Android (Stage 6.2).
 *
 * Features:
 * - Binds directly to the active Wi-Fi Network (`TRANSPORT_WIFI`) when available so Android never
 *   routes Intranet LAN connections over Mobile Data when WAN is cut!
 * - Zero external dependencies (Pure RFC 6455 WebSocket implementation over Socket / SSLSocket).
 * - Bi-directional Heartbeat (`ping`/`pong`) every 20s.
 * - Automatic Reconnect with Exponential Backoff + Full Jitter (1s..30s).
 * - Immediate Delivery Acknowledgement (`ack`) and Duplicate Suppression.
 */
public final class LocalPushWebSocketClient {

    public interface CallInviteHandler {
        void onIncomingCallInvite(String callUuid, int callId, String callerHandle, String callerName, String destinationLine, boolean hasVideo);
        void onCallCancelledOrHandledElsewhere(String callUuid, String eventType);
    }

    private final Context context;
    private final CallInviteHandler handler;
    private final ScheduledExecutorService scheduler = Executors.newSingleThreadScheduledExecutor();
    private final SecureRandom secureRandom = new SecureRandom();
    private final AtomicBoolean running = new AtomicBoolean(false);
    private final AtomicBoolean connected = new AtomicBoolean(false);

    private volatile Socket activeSocket;
    private volatile BufferedOutputStream activeOut;
    private final Object writeLock = new Object();

    private volatile Network wifiNetwork = null;
    private ConnectivityManager.NetworkCallback networkCallback;
    private ScheduledFuture<?> reconnectFuture;
    private ScheduledFuture<?> heartbeatFuture;
    private int reconnectAttempts = 0;

    public LocalPushWebSocketClient(Context context, CallInviteHandler handler) {
        this.context = context.getApplicationContext();
        this.handler = handler;
    }

    public void start() {
        if (running.getAndSet(true)) {
            return;
        }
        registerWifiNetworkCallback();
        scheduleConnect(0);
        heartbeatFuture = scheduler.scheduleAtFixedRate(this::sendHeartbeat, 20, 20, TimeUnit.SECONDS);
    }

    public void stop() {
        running.set(false);
        if (reconnectFuture != null) reconnectFuture.cancel(true);
        if (heartbeatFuture != null) heartbeatFuture.cancel(true);
        unregisterWifiNetworkCallback();
        closeSocket();
    }

    public void reconnectNow() {
        if (!running.get()) {
            start();
            return;
        }
        reconnectAttempts = 0;
        closeSocket();
        scheduleConnect(100);
    }

    private void registerWifiNetworkCallback() {
        try {
            ConnectivityManager cm = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return;

            NetworkRequest req = new NetworkRequest.Builder()
                .addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
                .build();

            networkCallback = new ConnectivityManager.NetworkCallback() {
                @Override
                public void onAvailable(Network network) {
                    wifiNetwork = network;
                    NativeCallStateStore.getInstance().hasWifi = true;
                    if (!connected.get()) {
                        reconnectAttempts = 0;
                        scheduleConnect(200);
                    }
                }

                @Override
                public void onLost(Network network) {
                    if (wifiNetwork != null && wifiNetwork.equals(network)) {
                        wifiNetwork = null;
                        NativeCallStateStore.getInstance().hasWifi = false;
                        closeSocket();
                        scheduleConnect(1000);
                    }
                }
            };
            cm.registerNetworkCallback(req, networkCallback);
        } catch (Exception ignored) {}
    }

    private void unregisterWifiNetworkCallback() {
        try {
            ConnectivityManager cm = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm != null && networkCallback != null) {
                cm.unregisterNetworkCallback(networkCallback);
            }
        } catch (Exception ignored) {}
    }

    private void scheduleConnect(long delayMs) {
        if (!running.get()) return;
        if (reconnectFuture != null && !reconnectFuture.isDone()) {
            reconnectFuture.cancel(false);
        }
        reconnectFuture = scheduler.schedule(this::connectLoop, Math.max(0, delayMs), TimeUnit.MILLISECONDS);
    }

    private void scheduleBackoffReconnect() {
        if (!running.get()) return;
        reconnectAttempts = Math.min(reconnectAttempts + 1, 6);
        long baseMs = Math.min(30000L, (1L << reconnectAttempts) * 1000L);
        long jitterMs = secureRandom.nextInt(1000);
        scheduleConnect(baseMs + jitterMs);
    }

    private void connectLoop() {
        if (!running.get()) return;

        String rawWsUrl = NativeCallStateStore.getInstance().getLocalPushWsUrl(context);
        String ticket = NativeCallStateStore.getInstance().getLocalPushTicket(context);
        if (rawWsUrl == null || rawWsUrl.isEmpty() || ticket == null || ticket.isEmpty()) {
            scheduleBackoffReconnect();
            return;
        }

        try {
            Uri uri = Uri.parse(rawWsUrl);
            String scheme = uri.getScheme() != null ? uri.getScheme().toLowerCase() : "ws";
            boolean useTls = "wss".equals(scheme) || "https".equals(scheme);
            String host = uri.getHost();
            if (host == null || host.isEmpty()) {
                scheduleBackoffReconnect();
                return;
            }
            int port = uri.getPort();
            if (port <= 0) {
                port = useTls ? 443 : 49001;
            }

            String path = uri.getEncodedPath();
            if (path == null || path.isEmpty()) path = "/ws/local-push";
            String existingQuery = uri.getEncodedQuery();
            String fullPath = path + "?" + (existingQuery != null && !existingQuery.isEmpty() ? existingQuery + "&" : "")
                + "ticket=" + Uri.encode(ticket) + "&platform=android";

            Socket sock;
            Network boundWifi = this.wifiNetwork;
            if (useTls) {
                SSLSocketFactory sslFactory = (SSLSocketFactory) SSLSocketFactory.getDefault();
                Socket underlying = (boundWifi != null) ? boundWifi.getSocketFactory().createSocket() : new Socket();
                underlying.connect(new InetSocketAddress(host, port), 4000);
                sock = sslFactory.createSocket(underlying, host, port, true);
            } else {
                SocketFactory factory = (boundWifi != null) ? boundWifi.getSocketFactory() : SocketFactory.getDefault();
                sock = factory.createSocket();
                sock.connect(new InetSocketAddress(host, port), 4000);
            }

            sock.setKeepAlive(true);
            sock.setTcpNoDelay(true);

            BufferedInputStream in = new BufferedInputStream(sock.getInputStream());
            BufferedOutputStream out = new BufferedOutputStream(sock.getOutputStream());

            byte[] nonce = new byte[16];
            secureRandom.nextBytes(nonce);
            String wsKey = Base64.encodeToString(nonce, Base64.NO_WRAP);

            String handshake = "GET " + fullPath + " HTTP/1.1\r\n"
                + "Host: " + host + ":" + port + "\r\n"
                + "Upgrade: websocket\r\n"
                + "Connection: Upgrade\r\n"
                + "Sec-WebSocket-Key: " + wsKey + "\r\n"
                + "Sec-WebSocket-Version: 13\r\n\r\n";

            out.write(handshake.getBytes(StandardCharsets.UTF_8));
            out.flush();

            String statusLine = readHttpLine(in);
            if (statusLine == null || !statusLine.contains("101")) {
                sock.close();
                scheduleBackoffReconnect();
                return;
            }
            while (true) {
                String hdr = readHttpLine(in);
                if (hdr == null || hdr.isEmpty()) break;
            }

            this.activeSocket = sock;
            this.activeOut = out;
            this.reconnectAttempts = 0;
            updateConnectionStatus(true, rawWsUrl);

            DataInputStream dataIn = new DataInputStream(in);
            while (running.get() && !sock.isClosed()) {
                int b0 = dataIn.read();
                if (b0 < 0) break;
                int opcode = b0 & 0x0F;

                int b1 = dataIn.read();
                if (b1 < 0) break;
                boolean masked = (b1 & 0x80) != 0;
                long payloadLen = b1 & 0x7F;

                if (payloadLen == 126) {
                    payloadLen = dataIn.readUnsignedShort();
                } else if (payloadLen == 127) {
                    payloadLen = dataIn.readLong();
                }

                if (payloadLen < 0 || payloadLen > 1024 * 1024) break;

                byte[] maskKey = new byte[4];
                if (masked) {
                    dataIn.readFully(maskKey);
                }

                byte[] payload = new byte[(int) payloadLen];
                dataIn.readFully(payload);

                if (masked) {
                    for (int i = 0; i < payload.length; i++) {
                        payload[i] ^= maskKey[i % 4];
                    }
                }

                if (opcode == 0x01) { // Text frame
                    String text = new String(payload, StandardCharsets.UTF_8);
                    handleTextFrame(text);
                } else if (opcode == 0x08) { // Close frame
                    break;
                } else if (opcode == 0x09) { // Ping frame -> send Pong
                    sendMaskedFrame(0x0A, payload);
                }
            }
        } catch (Exception ignored) {
        } finally {
            closeSocket();
            updateConnectionStatus(false, rawWsUrl);
            if (running.get()) {
                scheduleBackoffReconnect();
            }
        }
    }

    private void handleTextFrame(String text) {
        try {
            JSONObject env = new JSONObject(text);
            String type = env.optString("type", "");
            if ("push_event".equals(type)) {
                String msgId = env.optString("msg_id", "");
                JSONObject payload = env.optJSONObject("payload");
                if (payload == null) return;

                String callUuid = payload.optString("call_uuid", "");
                String eventType = payload.optString("event_type", "call.invite");

                // 1. Immediately send Delivery ACK back to Local Push Server
                if (!msgId.isEmpty()) {
                    JSONObject ack = new JSONObject();
                    ack.put("type", "ack");
                    ack.put("msg_id", msgId);
                    ack.put("call_uuid", callUuid);
                    sendMaskedFrame(0x01, ack.toString().getBytes(StandardCharsets.UTF_8));
                }

                // 2. Process call control or incoming call invitation
                if ("call.cancelled".equals(eventType) || "call.answered_elsewhere".equals(eventType) || "call.missed".equals(eventType) || "call.rejected".equals(eventType)) {
                    if (handler != null) {
                        handler.onCallCancelledOrHandledElsewhere(callUuid, eventType);
                    }
                    return;
                }

                if ("call.invite".equals(eventType)) {
                    long expiresAt = payload.optLong("expires_at", 0L);
                    if (!NativeCallStateStore.getInstance().shouldProcessInvite(callUuid, expiresAt)) {
                        return;
                    }

                    int callId = payload.optInt("call_id", 0);
                    String callerHandle = payload.optString("caller_handle", "تماس داخلی");
                    String callerName = payload.optString("caller_display_name", callerHandle);
                    String destLine = payload.optString("destination_line", "");
                    boolean hasVideo = payload.optBoolean("has_video", false);

                    if (handler != null) {
                        handler.onIncomingCallInvite(callUuid, callId, callerHandle, callerName, destLine, hasVideo);
                    }
                }
            }
        } catch (Exception ignored) {}
    }

    private void sendHeartbeat() {
        if (!connected.get()) return;
        try {
            JSONObject ping = new JSONObject();
            ping.put("type", "ping");
            ping.put("client_time", System.currentTimeMillis());
            sendMaskedFrame(0x01, ping.toString().getBytes(StandardCharsets.UTF_8));
        } catch (Exception e) {
            closeSocket();
        }
    }

    private void sendMaskedFrame(int opcode, byte[] payload) throws IOException {
        synchronized (writeLock) {
            BufferedOutputStream out = this.activeOut;
            if (out == null) throw new IOException("Socket output stream closed");

            out.write(0x80 | (opcode & 0x0F));
            int len = payload.length;
            if (len <= 125) {
                out.write(0x80 | len);
            } else if (len <= 65535) {
                out.write(0x80 | 126);
                out.write((len >> 8) & 0xFF);
                out.write(len & 0xFF);
            } else {
                out.write(0x80 | 127);
                long l = len;
                for (int i = 7; i >= 0; i--) {
                    out.write((int) ((l >> (8 * i)) & 0xFF));
                }
            }

            byte[] mask = new byte[4];
            secureRandom.nextBytes(mask);
            out.write(mask);

            byte[] masked = new byte[len];
            for (int i = 0; i < len; i++) {
                masked[i] = (byte) (payload[i] ^ mask[i % 4]);
            }
            out.write(masked);
            out.flush();
        }
    }

    private String readHttpLine(BufferedInputStream in) throws IOException {
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        int prev = -1;
        while (true) {
            int cur = in.read();
            if (cur < 0) return null;
            if (prev == '\r' && cur == '\n') {
                byte[] bytes = baos.toByteArray();
                return new String(bytes, 0, Math.max(0, bytes.length - 1), StandardCharsets.UTF_8);
            }
            baos.write(cur);
            prev = cur;
        }
    }

    private void closeSocket() {
        synchronized (writeLock) {
            this.activeOut = null;
            Socket s = this.activeSocket;
            this.activeSocket = null;
            if (s != null) {
                try { s.close(); } catch (Exception ignored) {}
            }
        }
    }

    private void updateConnectionStatus(boolean isConnected, String endpoint) {
        boolean changed = (connected.getAndSet(isConnected) != isConnected);
        NativeCallStateStore store = NativeCallStateStore.getInstance();
        store.localPushConnected = isConnected;
        if (isConnected && store.hasWifi) {
            store.networkMode = "HYBRID";
        }
        if (changed) {
            JSObject ev = new JSObject();
            ev.put("connected", isConnected);
            ev.put("endpoint", endpoint != null ? endpoint : "");
            store.emitEvent("onLocalPushConnectionChanged", ev);
        }
    }
}
