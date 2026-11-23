import Foundation
import CallKit
import AVFoundation

@objc public class CallKitProvider: NSObject, CXProviderDelegate {

    private let provider: CXProvider
    private let callController = CXCallController()
    public static let shared = CallKitProvider()

    public override init() {
        let configuration = CXProviderConfiguration(localizedName: "Ravyno")
        configuration.supportsVideo = true
        configuration.maximumCallsPerCallGroup = 1
        configuration.maximumCallGroups = 1
        configuration.supportedHandleTypes = [.phoneNumber, .generic]
        configuration.includesCallsInRecents = true

        self.provider = CXProvider(configuration: configuration)
        super.init()
        self.provider.setDelegate(self, queue: nil)
        self.setupAudioNotifications()
    }

    private func setupAudioNotifications() {
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleAudioRouteChange(_:)),
            name: AVAudioSession.routeChangeNotification,
            object: nil
        )
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleAudioInterruption(_:)),
            name: AVAudioSession.interruptionNotification,
            object: nil
        )
    }

    // MARK: - Incoming Call Presentation (from PushKit or NEAppPushProvider Local Push)
    @objc public func reportIncomingCall(
        uuid: UUID,
        callId: Int = 0,
        handle: String,
        callerName: String? = nil,
        destinationLine: String = "",
        hasVideo: Bool = false,
        providerSource: String = "apns_voip",
        completion: ((Error?) -> Void)? = nil
    ) {
        let callUuidStr = uuid.uuidString.lowercased()
        let displayName = (callerName?.isEmpty == false) ? callerName! : handle

        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: handle)
        update.hasVideo = hasVideo
        update.localizedCallerName = displayName
        update.supportsDTMF = true
        update.supportsHolding = true
        update.supportsGrouping = false
        update.supportsUngrouping = false

        NativeCallStateStore.shared.updateCall { record in
            record = NativeCallRecord(
                callUuid: callUuidStr,
                callId: callId,
                status: "ringing",
                direction: "inbound",
                callerHandle: handle,
                callerName: displayName,
                destinationLine: destinationLine,
                hasVideo: hasVideo,
                answeredFromLockScreen: false,
                isMuted: false,
                isOnHold: false,
                audioRoute: currentAudioRouteString(),
                bridgeSessionId: nil,
                bridgeWsEndpoint: nil,
                rtcConfig: nil,
                startedAtMs: Int64(Date().timeIntervalSince1970 * 1000),
                answeredAtMs: nil
            )
        }

        provider.reportNewIncomingCall(with: uuid, update: update) { error in
            if error == nil {
                NativeCallApiClient.shared.sendRingingAck(callUuid: callUuidStr, provider: providerSource)
                NotificationCenter.default.post(
                    name: NSNotification.Name("GetNumberNativeEvent"),
                    object: nil,
                    userInfo: [
                        "event": "onIncomingCall",
                        "data": [
                            "callUuid": callUuidStr,
                            "callId": callId,
                            "callerHandle": handle,
                            "callerName": displayName,
                            "destinationLine": destinationLine,
                            "hasVideo": hasVideo,
                            "provider": providerSource
                        ]
                    ]
                )
            }
            completion?(error)
        }
    }

    // MARK: - Remote Termination (Cancelled / Answered Elsewhere / Missed)
    public func reportRemoteCallEnded(uuid: UUID, reason: CXCallEndedReason, statusName: String) {
        let callUuidStr = uuid.uuidString.lowercased()
        provider.reportCall(with: uuid, endedAt: Date(), reason: reason)

        NativeCallStateStore.shared.updateCall { record in
            if record?.callUuid == callUuidStr {
                record?.status = statusName
            }
        }

        let eventName = (reason == .answeredElsewhere || statusName == "rejected" || statusName == "cancelled")
            ? "onCallRejected"
            : "onCallEnded"

        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil,
            userInfo: [
                "event": eventName,
                "data": [
                    "callUuid": callUuidStr,
                    "reason": statusName
                ]
            ]
        )
    }

    // MARK: - Programmatic Actions from Vue Native Bridge
    public func performAnswerAction(uuid: UUID, completion: @escaping (Bool) -> Void) {
        let action = CXAnswerCallAction(call: uuid)
        let transaction = CXTransaction(action: action)
        callController.request(transaction) { error in
            completion(error == nil)
        }
    }

    public func performEndAction(uuid: UUID, completion: @escaping (Bool) -> Void) {
        let action = CXEndCallAction(call: uuid)
        let transaction = CXTransaction(action: action)
        callController.request(transaction) { error in
            completion(error == nil)
        }
    }

    public func performMuteAction(uuid: UUID, muted: Bool, completion: @escaping (Bool) -> Void) {
        let action = CXSetMutedCallAction(call: uuid, muted: muted)
        let transaction = CXTransaction(action: action)
        callController.request(transaction) { error in
            completion(error == nil)
        }
    }

    public func performHoldAction(uuid: UUID, onHold: Bool, completion: @escaping (Bool) -> Void) {
        let action = CXSetHeldCallAction(call: uuid, onHold: onHold)
        let transaction = CXTransaction(action: action)
        callController.request(transaction) { error in
            completion(error == nil)
        }
    }

    // MARK: - CXProviderDelegate Callbacks
    public func providerDidReset(_ provider: CXProvider) {
        NativeCallStateStore.shared.updateCall { record in
            record = nil
        }
    }

    public func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        let callUuidStr = action.callUUID.uuidString.lowercased()
        let nowMs = Int64(Date().timeIntervalSince1970 * 1000)

        configureAudioSessionForCall()

        NativeCallStateStore.shared.updateCall { record in
            if record?.callUuid == callUuidStr {
                record?.status = "accepted"
                record?.answeredFromLockScreen = true
                record?.answeredAtMs = nowMs
            }
        }

        // Immediately perform atomic lock-screen accept against Backend API
        NativeCallApiClient.shared.answerCallFromLockScreen(callUuid: callUuidStr) { [weak self] success, callDict in
            guard let self = self else { return }
            if !success, let code = callDict?["code"] as? String, code == "CALL_ALREADY_ACCEPTED_ON_ANOTHER_DEVICE" {
                self.reportRemoteCallEnded(uuid: action.callUUID, reason: .answeredElsewhere, statusName: "answered_elsewhere")
                return
            }

            let snapshot = NativeCallStateStore.shared.snapshotDictionary()
            NotificationCenter.default.post(
                name: NSNotification.Name("GetNumberNativeEvent"),
                object: nil,
                userInfo: [
                    "event": "onCallAnswered",
                    "data": snapshot
                ]
            )
        }

        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        let callUuidStr = action.callUUID.uuidString.lowercased()
        var wasRinging = false
        var durationSec = 0

        NativeCallStateStore.shared.updateCall { record in
            if record?.callUuid == callUuidStr {
                wasRinging = (record?.status == "ringing" || record?.status == "initiated")
                if let ansMs = record?.answeredAtMs {
                    let nowMs = Int64(Date().timeIntervalSince1970 * 1000)
                    durationSec = max(1, Int((nowMs - ansMs) / 1000))
                }
                record?.status = wasRinging ? "rejected" : "ended"
            }
        }

        if wasRinging {
            NativeCallApiClient.shared.rejectCallFromLockScreen(callUuid: callUuidStr)
            NotificationCenter.default.post(
                name: NSNotification.Name("GetNumberNativeEvent"),
                object: nil,
                userInfo: [
                    "event": "onCallRejected",
                    "data": [
                        "callUuid": callUuidStr,
                        "reason": "user_rejected"
                    ]
                ]
            )
        } else {
            NativeCallApiClient.shared.endCallFromNative(callUuid: callUuidStr, durationSeconds: durationSec)
            NotificationCenter.default.post(
                name: NSNotification.Name("GetNumberNativeEvent"),
                object: nil,
                userInfo: [
                    "event": "onCallEnded",
                    "data": [
                        "callUuid": callUuidStr,
                        "reason": "user_hangup",
                        "durationSeconds": durationSec
                    ]
                ]
            )
        }

        let audioSession = AVAudioSession.sharedInstance()
        try? audioSession.setActive(false, options: .notifyOthersOnDeactivation)

        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXSetMutedCallAction) {
        let callUuidStr = action.callUUID.uuidString.lowercased()
        NativeCallStateStore.shared.updateCall { record in
            if record?.callUuid == callUuidStr {
                record?.isMuted = action.isMuted
            }
        }

        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil,
            userInfo: [
                "event": "onCallStateChanged",
                "data": [
                    "callUuid": callUuidStr,
                    "isMuted": action.isMuted,
                    "currentState": NativeCallStateStore.shared.currentCall?.status ?? "active"
                ]
            ]
        )
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXSetHeldCallAction) {
        let callUuidStr = action.callUUID.uuidString.lowercased()
        NativeCallStateStore.shared.updateCall { record in
            if record?.callUuid == callUuidStr {
                record?.isOnHold = action.isOnHold
            }
        }

        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil,
            userInfo: [
                "event": "onCallStateChanged",
                "data": [
                    "callUuid": callUuidStr,
                    "isOnHold": action.isOnHold,
                    "currentState": NativeCallStateStore.shared.currentCall?.status ?? "active"
                ]
            ]
        )
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, didActivate audioSession: AVAudioSession) {
        configureAudioSessionForCall()
    }

    public func provider(_ provider: CXProvider, didDeactivate audioSession: AVAudioSession) {
        try? audioSession.setActive(false, options: .notifyOthersOnDeactivation)
    }

    // MARK: - Audio Session & Route Management
    public func configureAudioSessionForCall() {
        let audioSession = AVAudioSession.sharedInstance()
        do {
            try audioSession.setCategory(
                .playAndRecord,
                mode: .voiceChat,
                options: [.allowBluetooth, .allowBluetoothA2DP]
            )
            try audioSession.setActive(true)
        } catch {
            print("[CallKitProvider] Failed to configure AVAudioSession: \(error)")
        }
    }

    public func setAudioRoute(_ targetRoute: String) -> String {
        let session = AVAudioSession.sharedInstance()
        do {
            configureAudioSessionForCall()
            if targetRoute == "speaker" {
                try session.overrideOutputAudioPort(.speaker)
            } else {
                try session.overrideOutputAudioPort(.none)
            }
        } catch {
            print("[CallKitProvider] Failed to override audio port: \(error)")
        }
        let active = currentAudioRouteString()
        NativeCallStateStore.shared.updateCall { record in
            record?.audioRoute = active
        }
        return active
    }

    public func currentAudioRouteString() -> String {
        let outputs = AVAudioSession.sharedInstance().currentRoute.outputs
        for out in outputs {
            switch out.portType {
            case .builtInSpeaker:
                return "speaker"
            case .bluetoothHFP, .bluetoothA2DP, .bluetoothLE:
                return "bluetooth"
            case .headphones, .headsetMic, .usbAudio:
                return "wired"
            case .builtInReceiver:
                return "earpiece"
            default:
                continue
            }
        }
        return "earpiece"
    }

    @objc private func handleAudioRouteChange(_ notification: Notification) {
        let route = currentAudioRouteString()
        NativeCallStateStore.shared.updateCall { record in
            record?.audioRoute = route
        }
        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberNativeEvent"),
            object: nil,
            userInfo: [
                "event": "onAudioRouteChanged",
                "data": [
                    "route": route,
                    "availableRoutes": ["earpiece", "speaker", "bluetooth"]
                ]
            ]
        )
    }

    @objc private func handleAudioInterruption(_ notification: Notification) {
        guard let info = notification.userInfo,
              let typeVal = info[AVAudioSessionInterruptionTypeKey] as? UInt,
              let type = AVAudioSession.InterruptionType(rawValue: typeVal) else { return }

        if type == .began {
            if let uuidStr = NativeCallStateStore.shared.currentCall?.callUuid,
               let uuid = UUID(uuidString: uuidStr) {
                performHoldAction(uuid: uuid, onHold: true) { _ in }
            }
        } else if type == .ended {
            configureAudioSessionForCall()
            if let uuidStr = NativeCallStateStore.shared.currentCall?.callUuid,
               let uuid = UUID(uuidString: uuidStr) {
                performHoldAction(uuid: uuid, onHold: false) { _ in }
            }
        }
    }
}
