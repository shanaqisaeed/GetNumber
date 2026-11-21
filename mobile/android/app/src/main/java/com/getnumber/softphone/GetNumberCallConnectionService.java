package com.getnumber.softphone;

import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.telecom.Connection;
import android.telecom.ConnectionRequest;
import android.telecom.ConnectionService;
import android.telecom.PhoneAccountHandle;
import android.telecom.TelecomManager;
import androidx.annotation.RequiresApi;

@RequiresApi(api = Build.VERSION_CODES.O)
public class GetNumberCallConnectionService extends ConnectionService {

    public static final String EXTRA_CALL_ID = "com.getnumber.softphone.CALL_ID";
    public static final String EXTRA_CALLER_NAME = "com.getnumber.softphone.CALLER_NAME";

    @Override
    public Connection onCreateIncomingConnection(PhoneAccountHandle connectionManagerPhoneAccount, ConnectionRequest request) {
        GetNumberConnection connection = new GetNumberConnection();
        connection.setConnectionProperties(Connection.PROPERTY_SELF_MANAGED);
        connection.setAudioModeIsVoip(true);

        String callId = "";
        String callerName = "تماس ناشناس";

        if (request.getExtras() != null) {
            callId = request.getExtras().getString(EXTRA_CALL_ID, "");
            callerName = request.getExtras().getString(EXTRA_CALLER_NAME, callerName);
        }

        connection.setCallerDisplayName(callerName, TelecomManager.PRESENTATION_ALLOWED);
        connection.setAddress(Uri.fromParts("tel", callerName, null), TelecomManager.PRESENTATION_ALLOWED);
        connection.setCallId(callId);
        connection.setRinging();

        return connection;
    }

    public class GetNumberConnection extends Connection {
        private String callId;

        public void setCallId(String callId) {
            this.callId = callId;
        }

        @Override
        public void onAnswer() {
            super.onAnswer();
            setActive();

            // Launch MainActivity with auto_answer flag
            Intent launchIntent = new Intent(GetNumberCallConnectionService.this, MainActivity.class);
            launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            launchIntent.putExtra("call_id", callId);
            launchIntent.putExtra("auto_answer", true);
            startActivity(launchIntent);
        }

        @Override
        public void onReject() {
            super.onReject();
            setDisconnected(new android.telecom.DisconnectCause(android.telecom.DisconnectCause.REJECTED));
            destroy();

            // Broadcast call rejection
            Intent intent = new Intent("com.getnumber.softphone.CALL_REJECTED");
            intent.putExtra("call_id", callId);
            sendBroadcast(intent);
        }

        @Override
        public void onDisconnect() {
            super.onDisconnect();
            setDisconnected(new android.telecom.DisconnectCause(android.telecom.DisconnectCause.LOCAL));
            destroy();
        }

        @Override
        public void onAbort() {
            super.onAbort();
            setDisconnected(new android.telecom.DisconnectCause(android.telecom.DisconnectCause.CANCELED));
            destroy();
        }
    }
}
