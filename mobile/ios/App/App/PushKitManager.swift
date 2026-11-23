import Foundation
import PushKit
import CallKit

@objc public final class PushKitManager: NSObject, PKPushRegistryDelegate {
    public static let shared = PushKitManager()
    private var voipRegistry: PKPushRegistry?

    public func start() {
        let registry = PKPushRegistry(queue: DispatchQueue.main)
        registry.delegate = self
        registry.desiredPushTypes = [.voIP]
        self.voipRegistry = registry
    }

    // MARK: - PKPushRegistryDelegate
    public func pushRegistry(_ registry: PKPushRegistry, didUpdate pushCredentials: PKPushCredentials, for type: PKPushType) {
        guard type == .voIP else { return }
        let tokenString = pushCredentials.token.map { String(format: "%02x", $0) }.joined()
        NativeCallStateStore.shared.voipPushToken = tokenString
        KeychainHelper.shared.set(tokenString, forKey: "voip_push_token")

        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil,
            userInfo: [
                "event": "onDeviceTokenChanged",
                "data": [
                    "tokenType": "apns_voip",
                    "token": tokenString
                ]
            ]
        )
    }

    public func pushRegistry(_ registry: PKPushRegistry, didInvalidatePushTokenFor type: PKPushType) {
        guard type == .voIP else { return }
        NativeCallStateStore.shared.voipPushToken = nil
        KeychainHelper.shared.remove("voip_push_token")
    }

    public func pushRegistry(
        _ registry: PKPushRegistry,
        didReceiveIncomingPushWith payload: PKPushPayload,
        for type: PKPushType,
        completion: @escaping () -> Void
    ) {
        guard type == .voIP else {
            completion()
            return
        }

        let dict = payload.dictionaryPayload
        let callData = (dict["call_data"] as? [String: Any]) ?? (dict as? [String: Any]) ?? [:]

        let eventType = (callData["event_type"] as? String) ?? "call.invite"
        let rawUuid = (callData["call_uuid"] as? String) ?? UUID().uuidString
        let callUuid = UUID(uuidString: rawUuid) ?? UUID()
        let callId = (callData["call_id"] as? Int) ?? Int((callData["call_id"] as? String) ?? "0") ?? 0
        let callerHandle = (callData["caller_handle"] as? String) ?? "تماس ورودی"
        let callerName = (callData["caller_display_name"] as? String) ?? callerHandle
        let destLine = (callData["destination_line"] as? String) ?? ""
        let hasVideo = (callData["has_video"] as? Bool) ?? ((callData["has_video"] as? String) == "true")
        let expiresAt = (callData["expires_at"] as? Int64) ?? Int64((callData["expires_at"] as? Int) ?? 0)
        let nowSec = Int64(Date().timeIntervalSince1970)
        let isExpired = (expiresAt > 0 && nowSec > expiresAt)

        // Apple iOS 13+ Mandate: Every PushKit VoIP push MUST synchronously invoke reportNewIncomingCall
        // before completion() returns. If the push is a cancellation or expired invite, we report and immediately end.
        CallKitProvider.shared.reportIncomingCall(
            uuid: callUuid,
            callId: callId,
            handle: callerHandle,
            callerName: callerName,
            destinationLine: destLine,
            hasVideo: hasVideo,
            providerSource: "ios_apns_voip"
        ) { _ in
            if isExpired || eventType != "call.invite" {
                let reason: CXCallEndedReason = (eventType == "call.answered_elsewhere")
                    ? .answeredElsewhere
                    : .remoteEnded
                let statusName = (eventType == "call.answered_elsewhere") ? "answered_elsewhere" : "cancelled"
                CallKitProvider.shared.reportRemoteCallEnded(uuid: callUuid, reason: reason, statusName: statusName)
            }
            completion()
        }
    }
}
