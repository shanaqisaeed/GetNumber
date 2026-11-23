import Foundation
import Capacitor
import Network

/**
 * Unified Native Calling Capacitor Plugin for iOS (`NativeCalling`).
 * Implements the versioned 1.0.0 contract shared across iOS and Android (Stage 5.4 / 6.5).
 */
@objc(NativeCallingPlugin)
public class NativeCallingPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "NativeCallingPlugin"
    public let jsName = "NativeCalling"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "registerDevice", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "getCallState", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "answerCall", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "rejectCall", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "endCall", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setMute", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setHold", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setAudioRoute", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "getNetworkMode", returnType: CAPPluginReturnPromise)
    ]

    private let pathMonitor = NWPathMonitor()
    private let monitorQueue = DispatchQueue(label: "com.ravyno.softphone.networkmonitor")

    public override func load() {
        super.load()
        PushKitManager.shared.start()

        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleNativeEvent(_:)),
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil
        )

        startNetworkPathMonitor()
    }

    deinit {
        NotificationCenter.default.removeObserver(self)
        pathMonitor.cancel()
    }

    @objc private func handleNativeEvent(_ notification: Notification) {
        guard let info = notification.userInfo,
              let eventName = info["event"] as? String,
              let data = info["data"] as? [String: Any] else { return }

        notifyListeners(eventName, data: data, retainUntilConsumed: true)
    }

    private func startNetworkPathMonitor() {
        pathMonitor.pathUpdateHandler = { [weak self] path in
            guard let self = self else { return }
            let hasWifi = path.usesInterfaceType(.wifi)
            let hasCellular = path.usesInterfaceType(.cellular)
            let isSatisfied = (path.status == .satisfied)

            let prevMode = NativeCallStateStore.shared.networkMode
            var nextMode = prevMode

            if !isSatisfied {
                nextMode = "OFFLINE"
            } else if hasWifi && NativeCallStateStore.shared.localPushConnected {
                nextMode = "HYBRID"
            } else if hasWifi {
                nextMode = "INTRANET"
            } else if hasCellular {
                nextMode = "PUBLIC"
            }

            NativeCallStateStore.shared.hasWifi = hasWifi
            NativeCallStateStore.shared.hasCellular = hasCellular
            NativeCallStateStore.shared.networkMode = nextMode

            if prevMode != nextMode {
                self.notifyListeners("onNetworkModeChanged", data: [
                    "previousMode": prevMode,
                    "currentMode": nextMode,
                    "hasWifi": hasWifi,
                    "hasCellular": hasCellular,
                    "localPushConnected": NativeCallStateStore.shared.localPushConnected,
                    "reason": "nw_path_monitor_update"
                ], retainUntilConsumed: true)
            }
        }
        pathMonitor.start(queue: monitorQueue)
    }

    // MARK: - 1. registerDevice
    @objc func registerDevice(_ call: CAPPluginCall) {
        if let token = call.getString("deviceAuthToken"), !token.isEmpty {
            NativeCallStateStore.shared.deviceAuthToken = token
        }
        if let apiBase = call.getString("apiBaseUrl"), !apiBase.isEmpty {
            NativeCallStateStore.shared.apiBaseUrl = apiBase
        }

        let wsUrl = call.getString("localPushWsUrl") ?? ""
        let ticket = call.getString("localPushTicket") ?? ""
        let ssids = (call.getArray("allowedIntranetSsids", String.self)) ?? []
        let devUuid = NativeCallStateStore.shared.deviceUuid

        if !ssids.isEmpty && !wsUrl.isEmpty {
            LocalPushManager.shared.configure(
                allowedSsids: ssids,
                localPushWsUrl: wsUrl,
                localPushTicket: ticket,
                apiBaseUrl: NativeCallStateStore.shared.apiBaseUrl,
                deviceUuid: devUuid
            )
        }

        let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0"
        let osVersion = "iOS " + UIDevice.current.systemVersion

        call.resolve([
            "bridgeVersion": "1.0.0",
            "deviceUuid": devUuid,
            "platform": "ios",
            "osVersion": osVersion,
            "appVersion": appVersion,
            "voipPushToken": NativeCallStateStore.shared.voipPushToken ?? KeychainHelper.shared.get("voip_push_token") ?? NSNull(),
            "notificationPushToken": NativeCallStateStore.shared.notificationPushToken ?? NSNull(),
            "localPushConnected": NativeCallStateStore.shared.localPushConnected,
            "networkMode": NativeCallStateStore.shared.networkMode
        ])
    }

    // MARK: - 2. getCallState
    @objc func getCallState(_ call: CAPPluginCall) {
        call.resolve(NativeCallStateStore.shared.snapshotDictionary())
    }

    // MARK: - 3. answerCall
    @objc func answerCall(_ call: CAPPluginCall) {
        let rawUuid = call.getString("callUuid") ?? NativeCallStateStore.shared.currentCall?.callUuid ?? ""
        guard let uuid = UUID(uuidString: rawUuid) else {
            call.reject("Invalid or missing callUuid")
            return
        }

        CallKitProvider.shared.performAnswerAction(uuid: uuid) { success in
            call.resolve([
                "success": success,
                "state": NativeCallStateStore.shared.snapshotDictionary()
            ])
        }
    }

    // MARK: - 4. rejectCall
    @objc func rejectCall(_ call: CAPPluginCall) {
        let rawUuid = call.getString("callUuid") ?? NativeCallStateStore.shared.currentCall?.callUuid ?? ""
        guard let uuid = UUID(uuidString: rawUuid) else {
            call.reject("Invalid or missing callUuid")
            return
        }

        CallKitProvider.shared.performEndAction(uuid: uuid) { success in
            call.resolve(["success": success])
        }
    }

    // MARK: - 5. endCall
    @objc func endCall(_ call: CAPPluginCall) {
        let rawUuid = call.getString("callUuid") ?? NativeCallStateStore.shared.currentCall?.callUuid ?? ""
        guard let uuid = UUID(uuidString: rawUuid) else {
            call.resolve(["success": true])
            return
        }

        CallKitProvider.shared.performEndAction(uuid: uuid) { success in
            call.resolve(["success": success])
        }
    }

    // MARK: - 6. setMute
    @objc func setMute(_ call: CAPPluginCall) {
        let muted = call.getBool("muted") ?? false
        let rawUuid = call.getString("callUuid") ?? NativeCallStateStore.shared.currentCall?.callUuid ?? ""
        guard let uuid = UUID(uuidString: rawUuid) else {
            call.resolve(["success": false, "muted": muted])
            return
        }

        CallKitProvider.shared.performMuteAction(uuid: uuid, muted: muted) { success in
            call.resolve(["success": success, "muted": muted])
        }
    }

    // MARK: - 7. setHold
    @objc func setHold(_ call: CAPPluginCall) {
        let onHold = call.getBool("onHold") ?? false
        let rawUuid = call.getString("callUuid") ?? NativeCallStateStore.shared.currentCall?.callUuid ?? ""
        guard let uuid = UUID(uuidString: rawUuid) else {
            call.resolve(["success": false, "onHold": onHold])
            return
        }

        CallKitProvider.shared.performHoldAction(uuid: uuid, onHold: onHold) { success in
            call.resolve(["success": success, "onHold": onHold])
        }
    }

    // MARK: - 8. setAudioRoute
    @objc func setAudioRoute(_ call: CAPPluginCall) {
        let target = call.getString("route") ?? "earpiece"
        let active = CallKitProvider.shared.setAudioRoute(target)
        call.resolve([
            "success": true,
            "activeRoute": active
        ])
    }

    // MARK: - 9. getNetworkMode
    @objc func getNetworkMode(_ call: CAPPluginCall) {
        call.resolve([
            "mode": NativeCallStateStore.shared.networkMode,
            "hasWifi": NativeCallStateStore.shared.hasWifi,
            "hasCellular": NativeCallStateStore.shared.hasCellular,
            "ssid": NativeCallStateStore.shared.currentSsid ?? NSNull(),
            "localPushConnected": NativeCallStateStore.shared.localPushConnected,
            "internalApiReachable": NativeCallStateStore.shared.networkMode != "OFFLINE",
            "lastProbeAtMs": Int64(Date().timeIntervalSince1970 * 1000)
        ])
    }
}
