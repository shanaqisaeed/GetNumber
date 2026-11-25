package main

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"
	"time"
)

// HandshakeRequest represents the payload sent by Laravel to initiate a two-way diagnostic check.
type HandshakeRequest struct {
	Host        string `json:"host"`
	Token       string `json:"token"`
	CallbackURL string `json:"callback_url"`
	Secret      string `json:"secret"`
	PortsPool   *struct {
		Min int `json:"min"`
		Max int `json:"max"`
	} `json:"ports_pool,omitempty"`
	Redis *struct {
		Host     string `json:"host"`
		Port     int    `json:"port"`
		Password string `json:"password"`
	} `json:"redis,omitempty"`
}

// HandshakeFeedbackPayload is the full diagnostic report posted back to Laravel's callback URL.
type HandshakeFeedbackPayload struct {
	Event      string                 `json:"event"`
	Status     string                 `json:"status"`
	Version    string                 `json:"version"`
	BridgeHost string                 `json:"bridge_host"`
	APIPort    int                    `json:"api_port"`
	WSPort     int                    `json:"ws_port"`
	Timestamp  int64                  `json:"timestamp"`
	Redis      map[string]interface{} `json:"redis"`
	UDPPool    map[string]interface{} `json:"udp_pool"`
	STUNTurn   map[string]interface{} `json:"stun_turn"`
	SFU        map[string]interface{} `json:"sfu"`
	Errors     []string               `json:"errors,omitempty"`
}

// handleHandshake processes the initial POST /api/handshake from Laravel.
func handleHandshake(
	cfg *Config,
	redisMgr *RedisManager,
	udpPool *UDPPortPool,
	turnSrv *TurnServer,
	sfuHub *SFUHub,
) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")

		if r.Method != http.MethodPost {
			w.WriteHeader(http.StatusMethodNotAllowed)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"success": false,
				"error":   "Method Not Allowed. Use POST.",
			})
			return
		}

		var req HandshakeRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			w.WriteHeader(http.StatusBadRequest)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"success": false,
				"error":   "Invalid JSON payload: " + err.Error(),
			})
			return
		}

		// Validate token
		token := strings.TrimSpace(req.Token)
		if token == "" {
			token = extractSecret(r)
		}

		if cfg.Secret != "" && token != "" {
			if !validateSecret(token, cfg.Secret) {
				log.Printf("[HANDSHAKE] ❌ Authentication rejected: Invalid secret token provided from %s", r.RemoteAddr)
				w.WriteHeader(http.StatusUnauthorized)
				_ = json.NewEncoder(w).Encode(map[string]interface{}{
					"success": false,
					"error":   "Unauthorized: Bridge secret token mismatch",
				})
				return
			}
		}

		targetCallback := strings.TrimSpace(req.CallbackURL)
		if targetCallback == "" {
			targetCallback = cfg.CallbackURL
		}
		targetCallback = normalizeCallbackURL(targetCallback)

		log.Printf("[HANDSHAKE] 🤝 Received handshake request from %s | Callback: %s", r.RemoteAddr, targetCallback)

		// Acknowledge receipt immediately to avoid Laravel timeout on slow connections
		w.WriteHeader(http.StatusOK)
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":      true,
			"status":       "acknowledged",
			"message":      "Handshake received. Internal diagnostics running; full report will be posted to callback_url.",
			"callback_url": targetCallback,
			"daemon_time":  time.Now().Unix(),
		})

		// Run deep diagnostics and dispatch async callback report in background
		go performDiagnosticsAndSendFeedback(cfg, redisMgr, udpPool, turnSrv, sfuHub, targetCallback)
	}
}

func performDiagnosticsAndSendFeedback(
	cfg *Config,
	redisMgr *RedisManager,
	udpPool *UDPPortPool,
	turnSrv *TurnServer,
	sfuHub *SFUHub,
	callbackURL string,
) {
	time.Sleep(100 * time.Millisecond) // Ensure sync HTTP response completes cleanly first
	log.Printf("[HANDSHAKE-DIAG] 🔬 Running comprehensive diagnostics on Voice Bridge subsystems...")

	var errorsList []string
	overallHealthy := true

	// 1. Diagnostic: Redis connection
	redisInfo := map[string]interface{}{}
	if redisMgr != nil {
		connected, latency, msg := redisMgr.PingStatus()
		redisInfo["connected"] = connected
		redisInfo["latency_ms"] = latency
		redisInfo["status"] = msg
		if !connected {
			log.Printf("[HANDSHAKE-DIAG] ℹ️ Redis: %s (non-fatal, local fallback active)", msg)
		} else {
			log.Printf("[HANDSHAKE-DIAG] ✅ Redis: Connected (%dms latency)", latency)
		}
	} else {
		redisInfo["connected"] = false
		redisInfo["status"] = "not_initialized"
	}

	// 2. Diagnostic: UDP Port Pool (300 ports capacity & multiplexing)
	udpInfo := map[string]interface{}{}
	if udpPool != nil {
		stats := udpPool.Stats()
		for k, v := range stats {
			udpInfo[k] = v
		}
		verifiedCount, poolErrors := udpPool.VerifyPool()
		udpInfo["verified_sample_ports"] = verifiedCount
		if len(poolErrors) > 0 {
			udpInfo["bind_warnings"] = poolErrors
			log.Printf("[HANDSHAKE-DIAG] ⚠️ UDP Port Pool warnings: %v", poolErrors)
		} else {
			log.Printf("[HANDSHAKE-DIAG] ✅ UDP Port Pool: %d/%d sample ports successfully bound and ready", verifiedCount, verifiedCount)
		}
		udpInfo["status"] = "ready"
	} else {
		udpInfo["status"] = "disabled"
	}

	// 3. Diagnostic: STUN / TURN Relay Engine
	turnInfo := map[string]interface{}{
		"enabled": cfg.TurnEnabled,
		"port":    cfg.TurnPort,
		"realm":   cfg.TurnRealm,
	}
	if turnSrv != nil {
		turnInfo["active"] = true
		if turnSrv.relayIP != nil {
			turnInfo["relay_ip"] = turnSrv.relayIP.String()
		}
		turnInfo["relay_range"] = fmt.Sprintf("%d-%d", turnSrv.minPort, turnSrv.maxPort)
		turnInfo["has_tls"] = turnSrv.hasTLS
		turnInfo["turns_port"] = turnSrv.turnsPort
		log.Printf("[HANDSHAKE-DIAG] ✅ STUN/TURN Server: Active on UDP/TCP :%d (Relay range %s)", cfg.TurnPort, turnInfo["relay_range"])
	} else {
		turnInfo["active"] = false
		if cfg.TurnEnabled {
			overallHealthy = false
			errorsList = append(errorsList, "TURN server enabled in config but listener is inactive")
			log.Printf("[HANDSHAKE-DIAG] ❌ STUN/TURN Server: Configured but listener is inactive!")
		}
	}

	// 4. Diagnostic: SFU Conference Engine
	sfuInfo := map[string]interface{}{
		"enabled": cfg.SFUEnabled,
		"active":  cfg.SFUEnabled && sfuHub != nil,
		"ws_path": cfg.SFUWSPath,
	}
	if sfuHub != nil {
		for k, v := range sfuHub.Stats() {
			sfuInfo[k] = v
		}
		log.Printf("[HANDSHAKE-DIAG] ✅ SFU Engine: Active on %s", cfg.SFUWSPath)
	}

	statusStr := "healthy"
	if !overallHealthy {
		statusStr = "degraded"
	}

	feedback := HandshakeFeedbackPayload{
		Event:      "handshake_feedback",
		Status:     statusStr,
		Version:    "2.4.0",
		BridgeHost: cfg.Host,
		APIPort:    cfg.APIPort,
		WSPort:     cfg.WSPort,
		Timestamp:  time.Now().Unix(),
		Redis:      redisInfo,
		UDPPool:    udpInfo,
		STUNTurn:   turnInfo,
		SFU:        sfuInfo,
		Errors:     errorsList,
	}

	if callbackURL == "" {
		log.Println("[HANDSHAKE-DIAG] ℹ️ No callback_url provided; skipping feedback POST dispatch.")
		return
	}

	// 5. Send POST feedback back to Laravel callback URL
	payloadBytes, err := json.Marshal(feedback)
	if err != nil {
		log.Printf("[HANDSHAKE-DIAG] ❌ Failed to serialize feedback payload: %v", err)
		return
	}

	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, // Allow local self-signed or internal intranet certificates
	}
	client := &http.Client{
		Timeout:   10 * time.Second,
		Transport: tr,
	}

	httpReq, err := http.NewRequest(http.MethodPost, callbackURL, bytes.NewBuffer(payloadBytes))
	if err != nil {
		log.Printf("[HANDSHAKE-DIAG] ❌ Failed to create HTTP POST request to %s: %v", callbackURL, err)
		return
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("User-Agent", "RavyNo-VoiceBridge/2.4.0 (HandshakeDiagnostics)")
	if cfg.Secret != "" {
		httpReq.Header.Set("X-Voice-Bridge-Secret", cfg.Secret)
		httpReq.Header.Set("Authorization", "Bearer "+cfg.Secret)
	}

	resp, err := client.Do(httpReq)
	if err != nil {
		log.Printf("[HANDSHAKE-DIAG] ⚠️ Asymmetric callback POST to %s failed: %v", callbackURL, err)
		return
	}
	defer resp.Body.Close()

	log.Printf("[HANDSHAKE-DIAG] 📬 Asymmetric feedback successfully delivered to Laravel: %s (Status: %d)",
		callbackURL, resp.StatusCode)
}
