package main

import (
	"testing"
)

func TestSFUHubPortAllocationAndSmartHybridSwitch(t *testing.T) {
	hub := NewSFUHub("test-secret", 49200, 49210)
	defer hub.Close()

	room := hub.GetOrCreateRoom("conf_test_hybrid")
	if room.TopologyMode() != "p2p" {
		t.Fatalf("expected initial room topology to be p2p, got %s", room.TopologyMode())
	}

	room.Lock()
	room.Peers["1"] = &SFUPeer{ID: "1", RoomUUID: room.UUID, Role: "host"}
	room.Peers["2"] = &SFUPeer{ID: "2", RoomUUID: room.UUID, Role: "participant"}
	room.Unlock()

	if room.TopologyMode() != "p2p" {
		t.Fatalf("expected 2-peer room topology to be p2p (0 server media bandwidth), got %s", room.TopologyMode())
	}

	// Add 3rd participant -> should switch to SFU and bind UDP relay port in range [49200, 49210]
	room.Lock()
	room.Peers["3"] = &SFUPeer{ID: "3", RoomUUID: room.UUID, Role: "participant"}
	room.Unlock()

	if room.TopologyMode() != "sfu" {
		t.Fatalf("expected 3-peer room topology to switch to sfu, got %s", room.TopologyMode())
	}

	port := room.EnsureUDPRelay()
	if port < 49200 || port > 49210 {
		t.Fatalf("expected allocated UDP relay port in [49200, 49210], got %d", port)
	}

	stats := hub.Stats()
	if stats["allocated_udp_ports"] != 1 {
		t.Fatalf("expected 1 allocated UDP port, got %v", stats["allocated_udp_ports"])
	}

	// Removing a peer back down to 2 peers should release the UDP relay port
	hub.RemovePeer(room.UUID, "3")

	statsAfter := hub.Stats()
	if statsAfter["allocated_udp_ports"] != 0 {
		t.Fatalf("expected 0 allocated UDP ports after dropping to 2 peers, got %v", statsAfter["allocated_udp_ports"])
	}
}
