import Foundation
import NetworkExtension

/**
 * Apple Local Push Connectivity Provider Extension (`NEAppPushProvider`).
 *
 * Runs as an OS-managed background extension when the iPhone connects to a configured
 * Intranet Wi-Fi SSID. Maintains a persistent WebSocket connection to the Go Voice Bridge
 * Local Push Server (`/ws/local-push`), acknowledges delivery (`ack`), and invokes
 * `reportIncomingCall(userInfo:)` to wake the main app and display CallKit on the Lock Screen.
 */
@objc(LocalPushProvider)
public final class LocalPushProvider: NEAppPushProvider {

    private var urlSession: URLSession?
    private var webSocketTask: URLSessionWebSocketTask?
    private var isRunning = false
    private var reconnectAttempt = 0
    private var reconnectWorkItem: DispatchWorkItem?

    public override func start(completionHandler: @escaping (Error?) -> Void) {
        isRunning = true
        reconnectAttempt = 0
        connectWebSocket()
        completionHandler(nil)
    }

    public override func stop(with reason: NEProviderStopReason, completionHandler: @escaping () -> Void) {
        isRunning = false
        reconnectWorkItem?.cancel()
        reconnectWorkItem = nil
        webSocketTask?.cancel(with: .goingAway, reason: nil)
        webSocketTask = nil
        completionHandler()
    }

    public override func handleTimerEvent() {
        guard isRunning else { return }
        guard let task = webSocketTask, task.state == .running else {
            connectWebSocket()
            return
        }

        let pingPayload: [String: Any] = [
            "type": "ping",
            "client_time": Int64(Date().timeIntervalSince1970 * 1000)
        ]
        if let data = try? JSONSerialization.data(withJSONObject: pingPayload),
           let jsonStr = String(data: data, encoding: .utf8) {
            task.send(.string(jsonStr)) { [weak self] error in
                if error != nil {
                    self?.scheduleReconnect()
                }
            }
        }
    }

    private func connectWebSocket() {
        guard isRunning,
              let config = providerConfiguration,
              let rawWsUrl = config["ws_url"] as? String,
              let ticket = config["ticket"] as? String else {
            return
        }

        webSocketTask?.cancel(with: .normalClosure, reason: nil)

        let separator = rawWsUrl.contains("?") ? "&" : "?"
        let encodedTicket = ticket.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ticket
        let fullUrlStr = "\(rawWsUrl)\(separator)ticket=\(encodedTicket)&platform=ios"
        guard let url = URL(string: fullUrlStr) else { return }

        let session = URLSession(configuration: .ephemeral)
        self.urlSession = session
        let task = session.webSocketTask(with: url)
        self.webSocketTask = task
        task.resume()
        receiveNextMessage()
    }

    private func receiveNextMessage() {
        guard isRunning, let task = webSocketTask else { return }

        task.receive { [weak self] result in
            guard let self = self, self.isRunning else { return }

            switch result {
            case .success(let message):
                self.reconnectAttempt = 0
                switch message {
                case .string(let text):
                    self.handleIncomingText(text)
                case .data(let data):
                    if let text = String(data: data, encoding: .utf8) {
                        self.handleIncomingText(text)
                    }
                @unknown default:
                    break
                }
                self.receiveNextMessage()

            case .failure:
                self.scheduleReconnect()
            }
        }
    }

    private func handleIncomingText(_ text: String) {
        guard let data = text.data(using: .utf8),
              let envelope = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return
        }

        let type = (envelope["type"] as? String) ?? ""
        if type == "push_event" {
            let msgId = (envelope["msg_id"] as? String) ?? ""
            let payload = (envelope["payload"] as? [String: Any]) ?? [:]
            let callUuid = (payload["call_uuid"] as? String) ?? ""
            let expiresAt = (payload["expires_at"] as? Int64) ?? Int64((payload["expires_at"] as? Int) ?? 0)
            let nowSec = Int64(Date().timeIntervalSince1970)

            // 1. Immediately send Delivery ACK back over Local Push WebSocket
            if !msgId.isEmpty {
                let ack: [String: Any] = [
                    "type": "ack",
                    "msg_id": msgId,
                    "call_uuid": callUuid
                ]
                if let ackData = try? JSONSerialization.data(withJSONObject: ack),
                   let ackStr = String(data: ackData, encoding: .utf8) {
                    webSocketTask?.send(.string(ackStr)) { _ in }
                }
            }

            // 2. Ignore expired call invitations
            if expiresAt > 0 && nowSec > expiresAt {
                return
            }

            // 3. Invoke Apple official NEAppPushProvider API to wake main app and trigger CallKit
            self.reportIncomingCall(userInfo: payload)
        }
    }

    private func scheduleReconnect() {
        guard isRunning else { return }
        reconnectWorkItem?.cancel()
        reconnectAttempt = min(reconnectAttempt + 1, 6)
        let baseDelay = min(30.0, pow(2.0, Double(reconnectAttempt)))
        let jitter = Double.random(in: 0.2...1.2)
        let work = DispatchWorkItem { [weak self] in
            self?.connectWebSocket()
        }
        reconnectWorkItem = work
        DispatchQueue.main.asyncAfter(deadline: .now() + baseDelay + jitter, execute: work)
    }
}
