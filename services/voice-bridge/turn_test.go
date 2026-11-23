package main

import (
	"bufio"
	"crypto/hmac"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/pion/logging"
	"github.com/pion/stun/v3"
	"github.com/pion/turn/v4"
)

func TestTurnAuthAndServerStartup(t *testing.T) {
	secret := "test-turn-secret-12345"
	realm := "testrealm"
	handler := buildTurnAuthHandler(secret, realm, "admin", "adminpass")

	// Test 1: Static Auth
	key, ok := handler("admin", realm, nil)
	if !ok || len(key) == 0 {
		t.Fatalf("expected static auth to succeed")
	}

	// Test 2: Valid REST token
	expiry := time.Now().Unix() + 3600
	username := fmt.Sprintf("%d:user-42", expiry)
	mac := hmac.New(sha1.New, []byte(secret))
	mac.Write([]byte(username))
	pass := base64.StdEncoding.EncodeToString(mac.Sum(nil))

	key2, ok2 := handler(username, realm, nil)
	if !ok2 || len(key2) == 0 {
		t.Fatalf("expected REST token auth to succeed")
	}

	expectedKey := turn.GenerateAuthKey(username, realm, pass)
	if string(key2) != string(expectedKey) {
		t.Fatalf("auth key mismatch")
	}

	// Test 3: Expired REST token
	expiredUser := fmt.Sprintf("%d:user-42", time.Now().Unix()-600)
	_, ok3 := handler(expiredUser, realm, nil)
	if ok3 {
		t.Fatalf("expected expired token to fail")
	}

	// Test 4: Start Turn Server using startTurnServer
	cfg := Config{
		Host:         "127.0.0.1",
		TurnEnabled:  true,
		TurnPort:     34789,
		TurnRealm:    realm,
		TurnSecret:   secret,
		TurnMinPort:  34800,
		TurnMaxPort:  34810,
		TurnPublicIP: "127.0.0.1",
	}

	srv, err := startTurnServer(cfg)
	if err != nil {
		t.Fatalf("failed to start turn server: %v", err)
	}
	defer srv.Close()
}

func TestStunBindingResolution(t *testing.T) {
	udpConn, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("failed to listen UDP: %v", err)
	}
	defer udpConn.Close()

	addr := udpConn.LocalAddr().String()

	server, err := turn.NewServer(turn.ServerConfig{
		Realm:         "stuntest",
		LoggerFactory: logging.NewDefaultLoggerFactory(),
		PacketConnConfigs: []turn.PacketConnConfig{
			{
				PacketConn: udpConn,
				RelayAddressGenerator: &turn.RelayAddressGeneratorPortRange{
					RelayAddress: net.ParseIP("127.0.0.1"),
					Address:      "127.0.0.1",
					MinPort:      40000,
					MaxPort:      40010,
				},
			},
		},
	})
	if err != nil {
		t.Fatalf("failed to start turn server: %v", err)
	}
	defer server.Close()

	// Client sends STUN binding request
	c, err := stun.Dial("udp4", addr)
	if err != nil {
		t.Fatalf("failed to dial stun: %v", err)
	}
	defer c.Close()

	message := stun.MustBuild(stun.TransactionID, stun.BindingRequest)
	var xorAddr stun.XORMappedAddress
	if err := c.Do(message, func(res stun.Event) {
		if res.Error != nil {
			t.Fatalf("STUN response error: %v", res.Error)
		}
		if err := xorAddr.GetFrom(res.Message); err != nil {
			t.Fatalf("failed to parse XOR-MAPPED-ADDRESS: %v", err)
		}
	}); err != nil {
		t.Fatalf("failed to perform STUN transaction: %v", err)
	}

	if xorAddr.IP.String() != "127.0.0.1" {
		t.Fatalf("expected 127.0.0.1, got %s", xorAddr.IP.String())
	}
}

func TestLocalPushTicketAndDeliveryWithAck(t *testing.T) {
	secret := "bridge-test-secret-999"
	deviceUUID := "ios-device-uuid-001"
	userID := int64(77)
	expiresAt := time.Now().Unix() + 3600

	rawPayload := fmt.Sprintf("%s|%d|%d", deviceUUID, userID, expiresAt)
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(rawPayload))
	sig := hex.EncodeToString(mac.Sum(nil))
	fullRaw := fmt.Sprintf("%s|%s", rawPayload, sig)
	ticket := strings.TrimRight(strings.ReplaceAll(strings.ReplaceAll(base64.StdEncoding.EncodeToString([]byte(fullRaw)), "+", "-"), "/", "_"), "=")

	// 1. Verify ticket helper
	parsedDev, parsedUID, ok := verifyLocalPushTicket(ticket, secret)
	if !ok || parsedDev != deviceUUID || parsedUID != userID {
		t.Fatalf("expected valid local push ticket, got ok=%v dev=%s uid=%d", ok, parsedDev, parsedUID)
	}

	// 2. Start LocalPushHub HTTP test server and simulate mobile device WebSocket connection
	hub := NewLocalPushHub(secret, "")
	srv := httptest.NewServer(http.HandlerFunc(hub.HandleWS))
	defer srv.Close()

	srvAddr := strings.TrimPrefix(srv.URL, "http://")
	conn, err := net.DialTimeout("tcp", srvAddr, 2*time.Second)
	if err != nil {
		t.Fatalf("failed to connect to test server: %v", err)
	}
	defer conn.Close()

	wsReq := fmt.Sprintf(
		"GET /ws/local-push?ticket=%s&platform=ios HTTP/1.1\r\n"+
			"Host: %s\r\n"+
			"Upgrade: websocket\r\n"+
			"Connection: Upgrade\r\n"+
			"Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"+
			"Sec-WebSocket-Version: 13\r\n\r\n",
		ticket, srvAddr,
	)
	if _, err := conn.Write([]byte(wsReq)); err != nil {
		t.Fatalf("failed to write WS upgrade request: %v", err)
	}

	bufrw := bufio.NewReadWriter(bufio.NewReader(conn), bufio.NewWriter(conn))
	statusLine, err := bufrw.ReadString('\n')
	if err != nil || !strings.Contains(statusLine, "101") {
		t.Fatalf("expected HTTP 101 Switching Protocols, got: %s (err: %v)", statusLine, err)
	}
	// Drain remaining HTTP headers
	for {
		line, err := bufrw.ReadString('\n')
		if err != nil || line == "\r\n" {
			break
		}
	}

	// Read initial local_push_ready frame
	readyFrame, err := readWSFrame(bufrw.Reader)
	if err != nil {
		t.Fatalf("failed reading ready frame: %v", err)
	}
	if !strings.Contains(string(readyFrame.Payload), "local_push_ready") {
		t.Fatalf("unexpected ready frame: %s", string(readyFrame.Payload))
	}

	if hub.Count() != 1 {
		t.Fatalf("expected 1 connected local push device, got %d", hub.Count())
	}

	// Spawn mobile client loop to read call invite and send masked ACK frame back
	go func() {
		inviteFrame, err := readWSFrame(bufrw.Reader)
		if err != nil {
			return
		}
		var env struct {
			Type  string `json:"type"`
			MsgID string `json:"msg_id"`
		}
		if err := json.Unmarshal(inviteFrame.Payload, &env); err == nil && env.MsgID != "" {
			ackJSON, _ := json.Marshal(map[string]interface{}{
				"type":      "ack",
				"msg_id":    env.MsgID,
				"call_uuid": "uuid-test-123",
			})
			_ = writeWSFrame(bufrw.Writer, 0x01, ackJSON)
		}
	}()

	// 3. Dispatch incoming call invite and require ACK
	delivered, acked, err := hub.Dispatch(deviceUUID, "lpush_test_msg_1", map[string]interface{}{
		"event_type": "call.invite",
		"call_uuid":  "uuid-test-123",
	}, true, 1500)

	if err != nil || !delivered || !acked {
		t.Fatalf("expected delivered=true, acked=true, err=nil; got delivered=%v, acked=%v, err=%v", delivered, acked, err)
	}
}
