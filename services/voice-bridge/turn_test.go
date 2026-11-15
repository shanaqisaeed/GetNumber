package main

import (
	"crypto/hmac"
	"crypto/sha1"
	"encoding/base64"
	"fmt"
	"net"
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
