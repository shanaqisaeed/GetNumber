package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestSFUHubPortAllocationAndRedisWhiteboard(t *testing.T) {
	redisMgr := NewRedisManager(RedisConfig{
		Host: "127.0.0.1",
		Port: 6379,
	})
	defer redisMgr.Close()

	udpPool := NewUDPPortPool(UDPPoolConfig{
		MinPort: 49001,
		MaxPort: 49300,
		Host:    "127.0.0.1",
	}, redisMgr)
	defer udpPool.Close()

	hub := NewSFUHub("test-secret", 49001, 49300, udpPool, redisMgr)
	defer hub.Close()

	room := hub.GetOrCreateRoom("conf_test_sfu_room")
	if room.TopologyMode() != "sfu" {
		t.Fatalf("expected room topology to be sfu, got %s", room.TopologyMode())
	}

	// 1. Verify UDP Port Allocation via Pool
	port := room.EnsureUDPRelay()
	if port < 49001 || port > 49300 {
		t.Fatalf("expected allocated UDP relay port in [49001, 49300], got %d", port)
	}

	stats := hub.Stats()
	if stats["active_rooms"] != 1 {
		t.Fatalf("expected 1 active room, got %v", stats["active_rooms"])
	}

	// 2. Verify Whiteboard storage and retrieval (Redis / local memory fallback)
	stroke := json.RawMessage(`{"type":"stroke","points":[[10,20],[30,40]],"color":"#ff0000"}`)
	if err := redisMgr.SaveWhiteboardStroke(room.UUID, stroke); err != nil {
		t.Fatalf("failed to save stroke: %v", err)
	}

	history, err := redisMgr.GetWhiteboardHistory(room.UUID)
	if err != nil {
		t.Fatalf("failed to get whiteboard history: %v", err)
	}
	if len(history) != 1 {
		t.Fatalf("expected 1 whiteboard stroke, got %d", len(history))
	}

	_ = redisMgr.ClearWhiteboard(room.UUID)
	clearedHistory, _ := redisMgr.GetWhiteboardHistory(room.UUID)
	if len(clearedHistory) != 0 {
		t.Fatalf("expected 0 strokes after clear, got %d", len(clearedHistory))
	}

	// 3. Clean up room
	hub.RemovePeer(room.UUID, "non_existent")
}

func TestAsymmetricHandshakeAndCallback(t *testing.T) {
	secret := "test-secret-token-xyz"
	cfg := Config{
		Host:           "127.0.0.1",
		APIPort:        49000,
		WSPort:         49001,
		Secret:         secret,
		TurnEnabled:    true,
		TurnPort:       3478,
		TurnRealm:      "test-realm",
		TurnMinPort:    49001,
		TurnMaxPort:    49300,
		UDPPoolMinPort: 49001,
		UDPPoolMaxPort: 49300,
	}

	redisMgr := NewRedisManager(RedisConfig{})
	defer redisMgr.Close()

	udpPool := NewUDPPortPool(UDPPoolConfig{
		MinPort: 49001,
		MaxPort: 49300,
		Host:    "127.0.0.1",
	}, redisMgr)
	defer udpPool.Close()

	// Mock Laravel callback receiver server
	callbackReceived := make(chan HandshakeFeedbackPayload, 1)
	laravelCallbackServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var feedback HandshakeFeedbackPayload
		_ = json.NewDecoder(r.Body).Decode(&feedback)
		callbackReceived <- feedback
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"success"}`))
	}))
	defer laravelCallbackServer.Close()

	handler := handleHandshake(&cfg, redisMgr, udpPool, nil, nil)

	// Send POST /api/handshake
	reqBody, _ := json.Marshal(map[string]interface{}{
		"host":         "127.0.0.1",
		"token":        secret,
		"callback_url": laravelCallbackServer.URL,
	})
	req := httptest.NewRequest(http.MethodPost, "/api/handshake", bytes.NewBuffer(reqBody))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	handler(w, req)

	resp := w.Result()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("expected HTTP 200 from handshake, got %d", resp.StatusCode)
	}

	var syncResp map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&syncResp)
	if syncResp["status"] != "acknowledged" {
		t.Fatalf("expected status=acknowledged, got %v", syncResp["status"])
	}

	// Wait for async callback report to be received by the mock Laravel server
	select {
	case feedback := <-callbackReceived:
		if feedback.Event != "handshake_feedback" {
			t.Fatalf("expected event=handshake_feedback, got %s", feedback.Event)
		}
		if feedback.Version != "2.4.0" {
			t.Fatalf("expected version=2.4.0, got %s", feedback.Version)
		}
		if feedback.UDPPool == nil || feedback.UDPPool["status"] != "ready" {
			t.Fatalf("expected udp_pool status=ready, got %v", feedback.UDPPool)
		}
		if feedback.Redis == nil {
			t.Fatalf("expected redis diagnostics in feedback")
		}
	case <-time.After(3 * time.Second):
		t.Fatalf("timed out waiting for asynchronous handshake feedback to be dispatched to callback_url")
	}
}
