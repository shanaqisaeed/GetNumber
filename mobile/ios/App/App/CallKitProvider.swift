import Foundation
import CallKit
import AVFoundation

@objc public class CallKitProvider: NSObject, CXProviderDelegate {
    
    private let provider: CXProvider
    private let callController = CXCallController()
    public static let shared = CallKitProvider()
    
    public override init() {
        let configuration = CXProviderConfiguration(localizedName: "GetNumber")
        configuration.supportsVideo = true
        configuration.maximumCallsPerCallGroup = 1
        configuration.supportedHandleTypes = [.phoneNumber, .generic]
        configuration.includesCallsInRecents = true
        
        self.provider = CXProvider(configuration: configuration)
        super.init()
        self.provider.setDelegate(self, queue: nil)
    }
    
    // MARK: - Incoming Call Trigger (from APNs VoIP Push or Local Websocket)
    @objc public func reportIncomingCall(uuid: UUID, handle: String, hasVideo: Bool, completion: ((Error?) -> Void)? = nil) {
        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: handle)
        update.hasVideo = hasVideo
        update.localizedCallerName = handle
        update.supportsDTMF = true
        update.supportsHolding = false
        update.supportsGrouping = false
        update.supportsUngrouping = false
        
        provider.reportNewIncomingCall(with: uuid, update: update) { error in
            completion?(error)
        }
    }
    
    // MARK: - CXProviderDelegate Callbacks
    public func providerDidReset(_ provider: CXProvider) {
        // Stop audio and release WebRTC engine if active
    }
    
    public func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        // Configure AVAudioSession for voice call
        let audioSession = AVAudioSession.sharedInstance()
        do {
            try audioSession.setCategory(.playAndRecord, mode: .voiceChat, options: [.allowBluetooth, .allowBluetoothA2DP])
            try audioSession.setActive(true)
        } catch {
            print("Failed to configure audio session: \(error)")
        }
        
        // Notify WebView / JavaScript to connect WebRTC peer connection
        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberCallAnswered"),
            object: nil,
            userInfo: ["callUUID": action.callUUID.uuidString]
        )
        
        action.fulfill()
    }
    
    public func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        // Notify WebView / JavaScript to hangup WebRTC peer connection
        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberCallEnded"),
            object: nil,
            userInfo: ["callUUID": action.callUUID.uuidString]
        )
        
        let audioSession = AVAudioSession.sharedInstance()
        try? audioSession.setActive(false, options: .notifyOthersOnDeactivation)
        
        action.fulfill()
    }
    
    public func provider(_ provider: CXProvider, perform action: CXSetMutedCallAction) {
        NotificationCenter.default.post(
            name: NSNotification.Name("GetNumberCallMuteToggled"),
            object: nil,
            userInfo: ["callUUID": action.callUUID.uuidString, "isMuted": action.isMuted]
        )
        action.fulfill()
    }
}
