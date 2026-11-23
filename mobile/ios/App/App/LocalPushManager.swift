import Foundation
import NetworkExtension
import CallKit

/**
 * Manages Apple Local Push Connectivity (`NEAppPushManager`) for Intranet / WAN-Disconnected operation.
 * Note: Requires `com.apple.developer.networking.networkextension` (`app-push-provider`) entitlement on device.
 */
@objc public final class LocalPushManager: NSObject, NEAppPushDelegate {
    public static let shared = LocalPushManager()
    private var activeManager: NEAppPushManager?
    private let providerBundleId = "com.ravyno.softphone.localpush"

    public func configure(
        allowedSsids: [String],
        localPushWsUrl: String,
        localPushTicket: String,
        apiBaseUrl: String,
        deviceUuid: String
    ) {
        guard !allowedSsids.isEmpty, !localPushWsUrl.isEmpty else {
            return
        }

        NEAppPushManager.loadAllFromPreferences { [weak self] managers, error in
            guard let self = self else { return }
            if let error = error {
                print("[LocalPushManager] loadAllFromPreferences note (requires Apple Entitlement): \(error.localizedDescription)")
                return
            }

            let manager = managers?.first ?? NEAppPushManager()
            manager.localizedDescription = "Ravyno Intranet Local Push Connectivity"
            manager.providerBundleIdentifier = self.providerBundleId
            manager.matchSSIDs = allowedSsids
            manager.isEnabled = true
            manager.delegate = self
            manager.providerConfiguration = [
                "ws_url": localPushWsUrl,
                "ticket": localPushTicket,
                "api_base_url": apiBaseUrl,
                "device_uuid": deviceUuid
            ]

            manager.saveToPreferences { saveErr in
                if let saveErr = saveErr {
                    print("[LocalPushManager] saveToPreferences error (Entitlement required): \(saveErr.localizedDescription)")
                    return
                }
                self.activeManager = manager
                NativeCallStateStore.shared.localPushConnected = manager.isActive
                NotificationCenter.default.post(
                    name: NSNotification.Name("GetNumberNativeEvent"),
                    object: nil,
                    userInfo: [
                        "event": "onLocalPushConnectionChanged",
                        "data": [
                            "connected": manager.isActive,
                            "endpoint": localPushWsUrl
                        ]
                    ]
                )
            }
        }
    }

    // MARK: - NEAppPushDelegate (Invoked when NEAppPushProvider Extension calls reportIncomingCall(userInfo:))
    public func appPushManager(_ manager: NEAppPushManager, didReceiveIncomingCallWithUserInfo userInfo: [AnyHashable: Any] = [:]) {
        let eventType = (userInfo["event_type"] as? String) ?? "call.invite"
        let rawUuid = (userInfo["call_uuid"] as? String) ?? UUID().uuidString
        guard let callUuid = UUID(uuidString: rawUuid) else { return }

        if eventType == "call.cancelled" || eventType == "call.missed" || eventType == "call.rejected" {
            CallKitProvider.shared.reportRemoteCallEnded(uuid: callUuid, reason: .remoteEnded, statusName: "cancelled")
            return
        }
        if eventType == "call.answered_elsewhere" {
            CallKitProvider.shared.reportRemoteCallEnded(uuid: callUuid, reason: .answeredElsewhere, statusName: "answered_elsewhere")
            return
        }

        let callId = (userInfo["call_id"] as? Int) ?? Int((userInfo["call_id"] as? String) ?? "0") ?? 0
        let callerHandle = (userInfo["caller_handle"] as? String) ?? "تماس داخلی"
        let callerName = (userInfo["caller_display_name"] as? String) ?? callerHandle
        let destLine = (userInfo["destination_line"] as? String) ?? ""
        let hasVideo = (userInfo["has_video"] as? Bool) ?? ((userInfo["has_video"] as? String) == "true")

        CallKitProvider.shared.reportIncomingCall(
            uuid: callUuid,
            callId: callId,
            handle: callerHandle,
            callerName: callerName,
            destinationLine: destLine,
            hasVideo: hasVideo,
            providerSource: "ios_local"
        )
    }
}
