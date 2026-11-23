import Foundation
import Security
import Network

// MARK: - Keychain Helper (Accessible After First Unlock for Lock-Screen VoIP / Local Push)
public final class KeychainHelper {
    public static let shared = KeychainHelper()
    private let serviceName = "com.ravyno.softphone.keychain"

    public func set(_ value: String, forKey key: String) {
        guard let data = value.data(using: .utf8) else { return }
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key
        ]
        SecItemDelete(query as CFDictionary)

        var insert = query
        insert[kSecValueData as String] = data
        insert[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        SecItemAdd(insert as CFDictionary, nil)
    }

    public func get(_ key: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        guard status == errSecSuccess, let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    public func remove(_ key: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key
        ]
        SecItemDelete(query as CFDictionary)
    }
}

// MARK: - Shared Native Call State Store & Backend Pre-Answer Client
public struct NativeCallRecord {
    public var callUuid: String
    public var callId: Int
    public var status: String
    public var direction: String
    public var callerHandle: String
    public var callerName: String
    public var destinationLine: String
    public var hasVideo: Bool
    public var answeredFromLockScreen: Bool
    public var isMuted: Bool
    public var isOnHold: Bool
    public var audioRoute: String
    public var bridgeSessionId: String?
    public var bridgeWsEndpoint: String?
    public var rtcConfig: [String: Any]?
    public var startedAtMs: Int64
    public var answeredAtMs: Int64?

    public func toDictionary() -> [String: Any] {
        var dict: [String: Any] = [
            "hasActiveOrPendingCall": !["rejected", "cancelled", "missed", "failed", "ended"].contains(status),
            "callUuid": callUuid,
            "callId": callId,
            "status": status,
            "direction": direction,
            "callerHandle": callerHandle,
            "callerName": callerName,
            "destinationLine": destinationLine,
            "hasVideo": hasVideo,
            "answeredFromLockScreen": answeredFromLockScreen,
            "isMuted": isMuted,
            "isOnHold": isOnHold,
            "audioRoute": audioRoute
        ]
        if let sid = bridgeSessionId { dict["bridgeSessionId"] = sid }
        if let ws = bridgeWsEndpoint { dict["bridgeWsEndpoint"] = ws }
        if let rtc = rtcConfig { dict["rtcConfig"] = rtc }
        if let ans = answeredAtMs { dict["answeredAtMs"] = ans }
        return dict
    }
}

public final class NativeCallStateStore {
    public static let shared = NativeCallStateStore()
    private let lock = NSLock()

    public var currentCall: NativeCallRecord?
    public var voipPushToken: String?
    public var notificationPushToken: String?
    public var localPushConnected: Bool = false
    public var networkMode: String = "HYBRID"
    public var hasWifi: Bool = true
    public var hasCellular: Bool = false
    public var currentSsid: String?

    public var deviceUuid: String {
        if let existing = KeychainHelper.shared.get("device_uuid"), !existing.isEmpty {
            return existing
        }
        let generated = "ios-" + UUID().uuidString.lowercased()
        KeychainHelper.shared.set(generated, forKey: "device_uuid")
        return generated
    }

    public var apiBaseUrl: String {
        get { KeychainHelper.shared.get("api_base_url") ?? "https://number.ravyno.site" }
        set { KeychainHelper.shared.set(newValue, forKey: "api_base_url") }
    }

    public var deviceAuthToken: String? {
        get { KeychainHelper.shared.get("device_auth_token") }
        set {
            if let token = newValue, !token.isEmpty {
                KeychainHelper.shared.set(token, forKey: "device_auth_token")
            } else {
                KeychainHelper.shared.remove("device_auth_token")
            }
        }
    }

    public func updateCall(_ block: (inout NativeCallRecord?) -> Void) {
        lock.lock()
        defer { lock.unlock() }
        block(&currentCall)
    }

    public func snapshotDictionary() -> [String: Any] {
        lock.lock()
        defer { lock.unlock() }
        if let call = currentCall {
            return call.toDictionary()
        }
        return [
            "hasActiveOrPendingCall": false,
            "callUuid": NSNull(),
            "callId": NSNull(),
            "status": NSNull(),
            "direction": NSNull(),
            "callerHandle": NSNull(),
            "callerName": NSNull(),
            "hasVideo": false,
            "answeredFromLockScreen": false,
            "isMuted": false,
            "isOnHold": false,
            "audioRoute": "earpiece"
        ]
    }
}

// MARK: - Direct Native Backend REST Client (for Lock Screen Answer / Reject / Ringing Ack)
public final class NativeCallApiClient {
    public static let shared = NativeCallApiClient()

    private func buildRequest(path: String, method: String = "POST", body: [String: Any]? = nil) -> URLRequest? {
        let base = NativeCallStateStore.shared.apiBaseUrl.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: "\(base)\(path)") else { return nil }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.timeoutInterval = 4.0
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = NativeCallStateStore.shared.deviceAuthToken, !token.isEmpty {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            req.setValue(token, forHTTPHeaderField: "X-Device-Token")
        }
        if let body = body {
            req.httpBody = try? JSONSerialization.data(withJSONObject: body, options: [])
        }
        return req
    }

    public func sendRingingAck(callUuid: String, provider: String) {
        guard let req = buildRequest(
            path: "/api/v1/mobile/calls/\(callUuid)/ringing-ack",
            body: [
                "device_uuid": NativeCallStateStore.shared.deviceUuid,
                "provider": provider
            ]
        ) else { return }

        URLSession.shared.dataTask(with: req).resume()
    }

    public func answerCallFromLockScreen(callUuid: String, completion: @escaping (Bool, [String: Any]?) -> Void) {
        guard let req = buildRequest(
            path: "/api/v1/mobile/calls/\(callUuid)/answer",
            body: [
                "device_uuid": NativeCallStateStore.shared.deviceUuid
            ]
        ) else {
            completion(false, nil)
            return
        }

        URLSession.shared.dataTask(with: req) { data, response, _ in
            guard let http = response as? HTTPURLResponse,
                  let data = data,
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                completion(false, nil)
                return
            }
            if http.statusCode == 200, let callDict = json["call"] as? [String: Any] {
                NativeCallStateStore.shared.updateCall { record in
                    record?.status = "accepted"
                    record?.bridgeSessionId = callDict["bridge_session_id"] as? String
                    record?.bridgeWsEndpoint = callDict["bridge_ws_endpoint"] as? String
                    record?.rtcConfig = callDict["rtc_config"] as? [String: Any]
                }
                completion(true, callDict)
            } else {
                completion(false, json)
            }
        }.resume()
    }

    public func rejectCallFromLockScreen(callUuid: String, reason: String = "rejected_by_callee") {
        guard let req = buildRequest(
            path: "/api/v1/mobile/calls/\(callUuid)/reject",
            body: [
                "device_uuid": NativeCallStateStore.shared.deviceUuid,
                "reason": reason
            ]
        ) else { return }

        URLSession.shared.dataTask(with: req).resume()
    }

    public func endCallFromNative(callUuid: String, durationSeconds: Int) {
        guard let req = buildRequest(
            path: "/api/v1/mobile/calls/\(callUuid)/end",
            body: [
                "device_uuid": NativeCallStateStore.shared.deviceUuid,
                "duration_seconds": durationSeconds
            ]
        ) else { return }

        URLSession.shared.dataTask(with: req).resume()
    }
}
