package main

import (
	"bufio"
	"crypto/sha1"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

func computeAcceptKey(challengeKey string) string {
	h := sha1.New()
	h.Write([]byte(challengeKey + wsGUID))
	return base64.StdEncoding.EncodeToString(h.Sum(nil))
}

// SFUMessage represents all signaling, media control, and interactive frames for conference rooms.
type SFUMessage struct {
	Type          string          `json:"type"`
	RoomUUID      string          `json:"room_uuid,omitempty"`
	ParticipantID string          `json:"participant_id,omitempty"`
	UserID        int64           `json:"user_id,omitempty"`
	PhoneNumber   string          `json:"phone_number,omitempty"`
	DisplayName   string          `json:"display_name,omitempty"`
	Role          string          `json:"role,omitempty"`      // host, co_host, participant
	TargetID      string          `json:"target_id,omitempty"` // specific peer participant_id, or empty for broadcast
	TopologyMode  string          `json:"topology_mode,omitempty"`
	MediaUDPPort    int             `json:"media_udp_port,omitempty"`
	IsMuted         bool            `json:"is_muted,omitempty"`
	IsCameraOn      bool            `json:"is_camera_on,omitempty"`
	CanShareScreen  bool            `json:"can_share_screen,omitempty"`
	IsSharingScreen bool            `json:"is_sharing_screen,omitempty"`
	HandRaised      bool            `json:"hand_raised,omitempty"`
	Payload         json.RawMessage `json:"payload,omitempty"`
	Timestamp     int64           `json:"timestamp,omitempty"`
}

// SFUPeer represents a connected participant in a conference room.
type SFUPeer struct {
	ID              string
	RoomUUID        string
	UserID          int64
	PhoneNumber     string
	DisplayName     string
	Role            string
	Conn            net.Conn
	Writer          *bufio.Writer
	WriteLock       sync.Mutex
	IsMuted         bool
	IsCameraOn      bool
	CanShareScreen  bool
	IsSharingScreen bool
	HandRaised      bool
	UDPAddr         *net.UDPAddr
	JoinedAt        time.Time
	LastSeenAt      int64
}

func (p *SFUPeer) Send(msg SFUMessage) error {
	if p == nil || p.Writer == nil {
		return nil
	}
	p.WriteLock.Lock()
	defer p.WriteLock.Unlock()

	if msg.Timestamp == 0 {
		msg.Timestamp = time.Now().UnixMilli()
	}

	data, err := json.Marshal(msg)
	if err != nil {
		return err
	}

	if p.Conn != nil {
		_ = p.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
	}
	return writeWSFrame(p.Writer, 0x01, data)
}

func (p *SFUPeer) SendControlFrame(opcode byte, payload []byte) error {
	if p == nil || p.Writer == nil {
		return nil
	}
	p.WriteLock.Lock()
	defer p.WriteLock.Unlock()

	if p.Conn != nil {
		_ = p.Conn.SetWriteDeadline(time.Now().Add(5 * time.Second))
	}
	return writeWSFrame(p.Writer, opcode, payload)
}

// SFUUDPRelay binds a UDP socket from the pre-opened 49152-49250 firewall port range
// and selectively forwards RTP/RTCP media packets (1-to-N fan-out) when room size >= 3.
type SFUUDPRelay struct {
	Port             int
	Conn             *net.UDPConn
	Room             *SFURoom
	ForwardedBytes   uint64
	ForwardedPackets uint64
	closed           int32
	Endpoints        map[string]*net.UDPAddr
}

func (relay *SFUUDPRelay) Run() {
	buf := make([]byte, 4096)
	for atomic.LoadInt32(&relay.closed) == 0 {
		_ = relay.Conn.SetReadDeadline(time.Now().Add(5 * time.Second))
		n, senderAddr, err := relay.Conn.ReadFromUDP(buf)
		if err != nil {
			if netErr, ok := err.(net.Error); ok && netErr.Timeout() {
				continue
			}
			if atomic.LoadInt32(&relay.closed) != 0 {
				return
			}
			continue
		}
		if n <= 0 {
			continue
		}

		relay.Room.Lock()
		if relay.Endpoints == nil {
			relay.Endpoints = make(map[string]*net.UDPAddr)
		}
		addrKey := senderAddr.String()
		relay.Endpoints[addrKey] = senderAddr

		// Associate senderAddr with a peer if not yet associated
		for _, peer := range relay.Room.Peers {
			if peer.UDPAddr == nil {
				peer.UDPAddr = senderAddr
				break
			}
		}

		// Forward RTP/RTCP packet to all other registered endpoints in this room
		for key, targetAddr := range relay.Endpoints {
			if key == addrKey || targetAddr == nil {
				continue
			}
			written, err := relay.Conn.WriteToUDP(buf[:n], targetAddr)
			if err == nil && written > 0 {
				atomic.AddUint64(&relay.ForwardedBytes, uint64(written))
				atomic.AddUint64(&relay.ForwardedPackets, 1)
				atomic.AddUint64(&relay.Room.Hub.totalForwardedBytes, uint64(written))
			}
		}
		relay.Room.Unlock()
	}
}

func (relay *SFUUDPRelay) Close() {
	if atomic.CompareAndSwapInt32(&relay.closed, 0, 1) {
		if relay.Conn != nil {
			_ = relay.Conn.Close()
		}
	}
}

// normalizeRoomUUID ensures room UUIDs with or without conf_ prefix map to the identical SFURoom instance.
func normalizeRoomUUID(uuid string) string {
	clean := strings.TrimSpace(uuid)
	clean = strings.TrimPrefix(clean, "conf_")
	return clean
}

// SFURoom represents an active conference session with Smart Hybrid (P2P <= 2, SFU >= 3) routing.
type SFURoom struct {
	sync.RWMutex
	UUID              string
	WebinarMode       bool
	IsLocked          bool
	ScreenSharePolicy string
	MuteOthersOnShare bool
	AllowWhiteboard   bool
	Peers             map[string]*SFUPeer // ID -> SFUPeer
	PendingOffers     map[string]SFUMessage // targetID -> latest pending SFUMessage
	WhiteboardHistory []json.RawMessage
	UDPRelay          *SFUUDPRelay
	Hub               *SFUHub
	EmptySince        time.Time
}

// TopologyMode returns "sfu" for all conference rooms ensuring stable UDP media relay across all NAT/firewalls.
func (r *SFURoom) TopologyMode() string {
	return "sfu"
}

func (r *SFURoom) EnsureUDPRelay() int {
	r.Lock()
	defer r.Unlock()

	if r.UDPRelay != nil {
		return r.UDPRelay.Port
	}

	if r.Hub != nil && r.Hub.udpPool != nil {
		port, err := r.Hub.udpPool.AllocatePort(r)
		if err == nil && port > 0 {
			return port
		}
		log.Printf("[SFU] Warning: UDPPortPool AllocatePort failed: %v", err)
	}

	relay, err := r.Hub.BindUDPRelay(r)
	if err != nil {
		log.Printf("[SFU] Warning: Could not bind dedicated UDP relay port for room %s: %v", r.UUID, err)
		return 0
	}
	r.UDPRelay = relay
	go relay.Run()
	return relay.Port
}

func (r *SFURoom) Broadcast(msg SFUMessage, excludeID string) {
	r.RLock()
	peers := make([]*SFUPeer, 0, len(r.Peers))
	for id, peer := range r.Peers {
		if id != excludeID {
			peers = append(peers, peer)
		}
	}
	r.RUnlock()

	for _, peer := range peers {
		_ = peer.Send(msg)
	}
}

func (r *SFURoom) SendTo(targetID string, msg SFUMessage) bool {
	r.RLock()
	defer r.RUnlock()

	targetID = strings.TrimSpace(targetID)
	if peer, ok := r.Peers[targetID]; ok && peer != nil {
		_ = peer.Send(msg)
		return true
	}
	for _, peer := range r.Peers {
		if fmt.Sprintf("%d", peer.UserID) == targetID && peer != nil {
			_ = peer.Send(msg)
			return true
		}
	}
	return false
}

// SFUHub manages rooms, peer connections, and UDP port reuse (49152-49250) for WebRTC media streams.
type SFUHub struct {
	sync.RWMutex
	rooms               map[string]*SFURoom
	secret              string
	minPort             int
	maxPort             int
	nextPort            int
	activePorts         map[int]bool
	portLock            sync.Mutex
	totalForwardedBytes uint64
	udpPool             *UDPPortPool
	redisMgr            *RedisManager
}

func NewSFUHub(secret string, minPort, maxPort int, udpPool *UDPPortPool, redisMgr *RedisManager) *SFUHub {
	if minPort <= 0 {
		minPort = 49152
	}
	if maxPort <= 0 || maxPort < minPort {
		maxPort = 49250
	}

	// Start SFU allocation from maxPort downward so it naturally avoids TURN allocations starting from minPort upward
	hub := &SFUHub{
		rooms:       make(map[string]*SFURoom),
		secret:      secret,
		minPort:     minPort,
		maxPort:     maxPort,
		nextPort:    maxPort,
		activePorts: make(map[int]bool),
		udpPool:     udpPool,
		redisMgr:    redisMgr,
	}
	hub.startJanitor()
	return hub
}

// startJanitor starts a background worker that checks and releases ports for rooms that remain empty past deadline.
func (hub *SFUHub) startJanitor() {
	go func() {
		ticker := time.NewTicker(10 * time.Second)
		defer ticker.Stop()
		for range ticker.C {
			hub.cleanIdleRooms()
		}
	}()
}

// cleanIdleRooms scans all conference rooms and releases UDP ports for rooms that have been empty for 30+ seconds.
func (hub *SFUHub) cleanIdleRooms() {
	hub.Lock()
	defer hub.Unlock()

	now := time.Now()
	for uuid, room := range hub.rooms {
		room.Lock()
		peerCount := len(room.Peers)
		var relayToRelease *SFUUDPRelay
		shouldDelete := false

		if peerCount == 0 {
			if room.EmptySince.IsZero() {
				room.EmptySince = now
			} else if now.Sub(room.EmptySince) >= 30*time.Second {
				shouldDelete = true
				if room.UDPRelay != nil {
					relayToRelease = room.UDPRelay
					room.UDPRelay = nil
				}
				if hub.udpPool != nil {
					hub.udpPool.ReleasePort(uuid)
				}
				log.Printf("[SFU-GC] 🧹 Room %s deadline reached (empty for %v). UDP Port and resources released.", uuid, now.Sub(room.EmptySince).Round(time.Second))
			}
		} else {
			room.EmptySince = time.Time{}
		}
		room.Unlock()

		if relayToRelease != nil {
			hub.ReleaseUDPRelay(relayToRelease)
		}
		if shouldDelete {
			delete(hub.rooms, uuid)
		}
	}
}

// Stats returns live telemetry of the SFU conference hub for /health and monitoring.
func (hub *SFUHub) Stats() map[string]interface{} {
	hub.RLock()
	roomCount := len(hub.rooms)
	peerCount := 0
	for _, r := range hub.rooms {
		r.RLock()
		peerCount += len(r.Peers)
		r.RUnlock()
	}
	hub.RUnlock()

	hub.portLock.Lock()
	allocatedPorts := len(hub.activePorts)
	hub.portLock.Unlock()

	stats := map[string]interface{}{
		"active_rooms":        roomCount,
		"active_peers":        peerCount,
		"allocated_udp_ports": allocatedPorts,
		"udp_port_range":      fmt.Sprintf("%d-%d", hub.minPort, hub.maxPort),
		"forwarded_bytes":     atomic.LoadUint64(&hub.totalForwardedBytes),
	}

	if hub.udpPool != nil {
		stats["udp_pool"] = hub.udpPool.Stats()
	}

	return stats
}

// Close gracefully closes all active rooms, WebSocket connections, and UDP relays on daemon shutdown.
func (hub *SFUHub) Close() {
	hub.Lock()
	rooms := make([]*SFURoom, 0, len(hub.rooms))
	for _, r := range hub.rooms {
		rooms = append(rooms, r)
	}
	hub.rooms = make(map[string]*SFURoom)
	hub.Unlock()

	for _, r := range rooms {
		r.Lock()
		if r.UDPRelay != nil {
			hub.ReleaseUDPRelay(r.UDPRelay)
			r.UDPRelay = nil
		}
		for _, p := range r.Peers {
			if p.Conn != nil {
				_ = p.Conn.Close()
			}
		}
		r.Peers = make(map[string]*SFUPeer)
		r.Unlock()
	}
}

// BindUDPRelay allocates and binds an available UDP socket inside the pre-opened [minPort, maxPort] range.
func (hub *SFUHub) BindUDPRelay(room *SFURoom) (*SFUUDPRelay, error) {
	hub.portLock.Lock()
	defer hub.portLock.Unlock()

	totalRange := hub.maxPort - hub.minPort + 1
	for i := 0; i < totalRange; i++ {
		port := hub.nextPort
		hub.nextPort--
		if hub.nextPort < hub.minPort {
			hub.nextPort = hub.maxPort
		}

		if hub.activePorts[port] {
			continue
		}

		addr := &net.UDPAddr{IP: net.IPv4zero, Port: port}
		conn, err := net.ListenUDP("udp4", addr)
		if err != nil {
			// Port may currently be in use by TURN relay; skip to next port cleanly
			continue
		}

		// Increase UDP socket buffers (4 MB) to prevent video/screen-share packet drops
		_ = conn.SetReadBuffer(4 * 1024 * 1024)
		_ = conn.SetWriteBuffer(4 * 1024 * 1024)

		hub.activePorts[port] = true
		log.Printf("[SFU] Allocated UDP media port %d for room %s (Range: %d-%d)", port, room.UUID, hub.minPort, hub.maxPort)
		return &SFUUDPRelay{
			Port: port,
			Conn: conn,
			Room: room,
		}, nil
	}

	return nil, fmt.Errorf("no available UDP port in range %d-%d", hub.minPort, hub.maxPort)
}

func (hub *SFUHub) ReleaseUDPRelay(relay *SFUUDPRelay) {
	if relay == nil {
		return
	}
	relay.Close()

	hub.portLock.Lock()
	delete(hub.activePorts, relay.Port)
	hub.portLock.Unlock()
	log.Printf("[SFU] Released UDP media port %d", relay.Port)
}

func (hub *SFUHub) GetOrCreateRoom(uuid string) *SFURoom {
	uuid = normalizeRoomUUID(uuid)
	hub.Lock()
	defer hub.Unlock()

	if room, exists := hub.rooms[uuid]; exists {
		return room
	}

	room := &SFURoom{
		UUID:              uuid,
		WebinarMode:       false,
		IsLocked:          false,
		ScreenSharePolicy: "by_permission",
		MuteOthersOnShare: true,
		AllowWhiteboard:   true,
		Peers:             make(map[string]*SFUPeer),
		PendingOffers:     make(map[string]SFUMessage),
		WhiteboardHistory: make([]json.RawMessage, 0),
		Hub:               hub,
	}
	hub.rooms[uuid] = room
	return room
}

func (hub *SFUHub) RemovePeer(roomUUID, peerID string) {
	roomUUID = normalizeRoomUUID(roomUUID)
	hub.Lock()
	room, exists := hub.rooms[roomUUID]
	if !exists {
		hub.Unlock()
		return
	}

	room.Lock()
	peer, peerExists := room.Peers[peerID]
	if peerExists {
		delete(room.Peers, peerID)
	}
	remaining := len(room.Peers)
	var relayToRelease *SFUUDPRelay
	// Release UDP relay port only when room becomes completely empty
	if remaining == 0 {
		if room.UDPRelay != nil {
			relayToRelease = room.UDPRelay
			room.UDPRelay = nil
		}
		if hub.udpPool != nil {
			hub.udpPool.ReleasePort(roomUUID)
		}
	}
	room.Unlock()

	if remaining == 0 {
		delete(hub.rooms, roomUUID)
	}
	hub.Unlock()

	if relayToRelease != nil {
		hub.ReleaseUDPRelay(relayToRelease)
	}

	if peerExists {
		topology := room.TopologyMode()
		room.Broadcast(SFUMessage{
			Type:          "peer_left",
			RoomUUID:      roomUUID,
			ParticipantID: peerID,
			PhoneNumber:   peer.PhoneNumber,
			DisplayName:   peer.DisplayName,
			TopologyMode:  topology,
			Timestamp:     time.Now().UnixMilli(),
		}, peerID)
	}

	if remaining == 0 {
		log.Printf("[SFU] Room %s closed (all peers left)", roomUUID)
	}
}

// HandleWS upgrades HTTP connections to RFC 6455 WebSockets for conference SFU media and signaling.
func (hub *SFUHub) HandleWS(w http.ResponseWriter, r *http.Request) {
	if !strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		http.Error(w, "Expected WebSocket Upgrade", http.StatusBadRequest)
		return
	}

	key := r.Header.Get("Sec-WebSocket-Key")
	if key == "" {
		http.Error(w, "Missing Sec-WebSocket-Key", http.StatusBadRequest)
		return
	}

	hj, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "Webserver does not support hijacking", http.StatusInternalServerError)
		return
	}
	conn, bufrw, err := hj.Hijack()
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	defer conn.Close()

	acceptKey := computeAcceptKey(key)
	response := fmt.Sprintf(
		"HTTP/1.1 101 Switching Protocols\r\n"+
			"Upgrade: websocket\r\n"+
			"Connection: Upgrade\r\n"+
			"Sec-WebSocket-Accept: %s\r\n\r\n",
		acceptKey,
	)
	if _, err := bufrw.WriteString(response); err != nil {
		return
	}
	if err := bufrw.Flush(); err != nil {
		return
	}

	var currentPeer *SFUPeer
	var currentRoom *SFURoom
	doneCh := make(chan struct{})

	defer func() {
		close(doneCh)
		if currentPeer != nil && currentRoom != nil {
			hub.RemovePeer(currentRoom.UUID, currentPeer.ID)
		}
	}()

	// Periodic WebSocket Ping Keepalive to detect dead sockets and keep reverse proxies alive
	go func() {
		ticker := time.NewTicker(25 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-doneCh:
				return
			case <-ticker.C:
				if currentPeer != nil {
					if err := currentPeer.SendControlFrame(0x09, []byte("ping")); err != nil {
						_ = conn.Close()
						return
					}
				}
			}
		}
	}()

	for {
		_ = conn.SetReadDeadline(time.Now().Add(90 * time.Second))
		frame, err := readWSFrame(bufrw.Reader)
		if err != nil {
			break
		}

		if currentPeer != nil {
			atomic.StoreInt64(&currentPeer.LastSeenAt, time.Now().Unix())
		}

		if frame.Opcode == 0x08 { // Close frame
			break
		} else if frame.Opcode == 0x09 { // Ping frame
			if currentPeer != nil {
				_ = currentPeer.SendControlFrame(0x0A, frame.Payload)
			} else {
				_ = writeWSFrame(bufrw.Writer, 0x0A, frame.Payload)
			}
			continue
		} else if frame.Opcode == 0x0A { // Pong frame
			continue
		}

		if frame.Opcode != 0x01 { // Text frame only
			continue
		}

		var msg SFUMessage
		if err := json.Unmarshal(frame.Payload, &msg); err != nil {
			continue
		}

		switch msg.Type {
		case "ping", "heartbeat":
			if currentPeer != nil {
				_ = currentPeer.Send(SFUMessage{
					Type:      "pong",
					Timestamp: time.Now().UnixMilli(),
				})
			}

		case "join":
			var joinPayload struct {
				RoomUUID      string `json:"room_uuid"`
				ParticipantID string `json:"participant_id"`
				UserID        int64  `json:"user_id"`
				PhoneNumber   string `json:"phone_number"`
				DisplayName   string `json:"display_name"`
				Role          string `json:"role"`
				WebinarMode   *bool  `json:"webinar_mode"`
				IsMuted       *bool  `json:"is_muted"`
				CanShareScreen *bool `json:"can_share_screen"`
			}
			if len(msg.Payload) > 0 {
				_ = json.Unmarshal(msg.Payload, &joinPayload)
			}

			roomUUID := normalizeRoomUUID(msg.RoomUUID)
			if roomUUID == "" {
				roomUUID = normalizeRoomUUID(joinPayload.RoomUUID)
			}
			if roomUUID == "" {
				_ = writeWSFrame(bufrw.Writer, 0x01, []byte(`{"type":"error","payload":"missing room_uuid"}`))
				continue
			}

			peerID := strings.TrimSpace(msg.ParticipantID)
			if peerID == "" {
				peerID = strings.TrimSpace(joinPayload.ParticipantID)
			}
			userID := msg.UserID
			if userID == 0 {
				userID = joinPayload.UserID
			}
			if peerID == "" {
				peerID = fmt.Sprintf("%d", userID)
			}

			phoneNumber := msg.PhoneNumber
			if phoneNumber == "" {
				phoneNumber = joinPayload.PhoneNumber
			}
			displayName := msg.DisplayName
			if displayName == "" {
				displayName = joinPayload.DisplayName
			}
			role := msg.Role
			if role == "" {
				role = joinPayload.Role
			}

			currentRoom = hub.GetOrCreateRoom(roomUUID)

			isMuted := false
			if joinPayload.IsMuted != nil {
				isMuted = *joinPayload.IsMuted
			}

			canShare := true
			if joinPayload.CanShareScreen != nil {
				canShare = *joinPayload.CanShareScreen
			}

			currentPeer = &SFUPeer{
				ID:              peerID,
				RoomUUID:        roomUUID,
				UserID:          userID,
				PhoneNumber:     phoneNumber,
				DisplayName:     displayName,
				Role:            role,
				Conn:            conn,
				Writer:          bufrw.Writer,
				IsMuted:         isMuted,
				IsCameraOn:      false,
				CanShareScreen:  canShare,
				IsSharingScreen: false,
				HandRaised:      false,
				JoinedAt:        time.Now(),
				LastSeenAt:      time.Now().Unix(),
			}

			currentRoom.Lock()
			if oldPeer, exists := currentRoom.Peers[peerID]; exists && oldPeer.Conn != nil && oldPeer.Conn != conn {
				_ = oldPeer.Conn.Close()
			}
			existingPeers := make([]map[string]interface{}, 0, len(currentRoom.Peers))
			for _, ep := range currentRoom.Peers {
				if ep.ID == peerID {
					continue
				}
				existingPeers = append(existingPeers, map[string]interface{}{
					"id":                ep.ID,
					"user_id":           ep.UserID,
					"phone_number":      ep.PhoneNumber,
					"display_name":      ep.DisplayName,
					"role":              ep.Role,
					"is_muted":          ep.IsMuted,
					"is_camera_on":      ep.IsCameraOn,
					"can_share_screen":  ep.CanShareScreen,
					"is_sharing_screen": ep.IsSharingScreen,
					"hand_raised":       ep.HandRaised,
				})
			}
			currentRoom.Peers[peerID] = currentPeer
			peerCount := len(currentRoom.Peers)

			// Check and extract any pending offer queued for this peer before they connected
			var pendingOffer *SFUMessage
			if currentRoom.PendingOffers != nil {
				if po, exists := currentRoom.PendingOffers[peerID]; exists {
					pendingOfferCopy := po
					pendingOffer = &pendingOfferCopy
					delete(currentRoom.PendingOffers, peerID)
				}
			}

			var wbHistory []json.RawMessage
			if currentRoom.Hub != nil && currentRoom.Hub.redisMgr != nil {
				wbHistory, _ = currentRoom.Hub.redisMgr.GetWhiteboardHistory(roomUUID)
			}
			if len(wbHistory) == 0 {
				wbHistory = make([]json.RawMessage, len(currentRoom.WhiteboardHistory))
				copy(wbHistory, currentRoom.WhiteboardHistory)
			}
			currentRoom.Unlock()

			// Always enable SFU with UDP relay for guaranteed media transfer across all NATs/networks
			topology := "sfu"
			udpPort := currentRoom.EnsureUDPRelay()

			peersJSON, _ := json.Marshal(existingPeers)
			_ = currentPeer.Send(SFUMessage{
				Type:          "joined",
				RoomUUID:      roomUUID,
				ParticipantID: peerID,
				TopologyMode:  topology,
				MediaUDPPort:  udpPort,
				Payload:       peersJSON,
			})

			// Deliver buffered offer if peer joined after offer was dispatched
			if pendingOffer != nil {
				log.Printf("[SFU] Delivering buffered offer to newly connected peer %s in room %s", peerID, roomUUID)
				_ = currentPeer.Send(*pendingOffer)
			}

			if len(wbHistory) > 0 {
				wbData, _ := json.Marshal(wbHistory)
				_ = currentPeer.Send(SFUMessage{
					Type:     "whiteboard_sync",
					RoomUUID: roomUUID,
					Payload:  wbData,
				})
			}

			currentRoom.Broadcast(SFUMessage{
				Type:            "peer_joined",
				RoomUUID:        roomUUID,
				ParticipantID:   peerID,
				UserID:          userID,
				PhoneNumber:     phoneNumber,
				DisplayName:     displayName,
				Role:            role,
				TopologyMode:    topology,
				MediaUDPPort:    udpPort,
				IsMuted:         currentPeer.IsMuted,
				IsCameraOn:      currentPeer.IsCameraOn,
				CanShareScreen:  currentPeer.CanShareScreen,
				IsSharingScreen: currentPeer.IsSharingScreen,
				HandRaised:      currentPeer.HandRaised,
			}, peerID)

			log.Printf("[SFU] Peer %s (%s) joined room %s (peers: %d, mode: %s, udpPort: %d)", peerID, phoneNumber, roomUUID, peerCount, topology, udpPort)

		case "offer", "answer", "candidate", "renegotiate_needed":
			if currentRoom == nil || currentPeer == nil {
				continue
			}

			targetID := strings.TrimSpace(msg.TargetID)
			if targetID == "" && len(msg.Payload) > 0 {
				var targetPayload struct {
					TargetID            interface{} `json:"target_id"`
					TargetParticipantID interface{} `json:"target_participant_id"`
				}
				if err := json.Unmarshal(msg.Payload, &targetPayload); err == nil {
					if targetPayload.TargetID != nil {
						targetID = strings.TrimSpace(fmt.Sprintf("%v", targetPayload.TargetID))
					} else if targetPayload.TargetParticipantID != nil {
						targetID = strings.TrimSpace(fmt.Sprintf("%v", targetPayload.TargetParticipantID))
					}
				}
			}

			if targetID == "" {
				continue
			}

			msg.TargetID = targetID
			msg.ParticipantID = currentPeer.ID
			msg.UserID = currentPeer.UserID
			msg.PhoneNumber = currentPeer.PhoneNumber
			msg.DisplayName = currentPeer.DisplayName
			msg.Role = currentPeer.Role

			if msg.Type == "offer" {
				currentRoom.Lock()
				if currentRoom.PendingOffers == nil {
					currentRoom.PendingOffers = make(map[string]SFUMessage)
				}
				currentRoom.PendingOffers[targetID] = msg
				currentRoom.Unlock()
			} else if msg.Type == "answer" {
				currentRoom.Lock()
				if currentRoom.PendingOffers != nil {
					delete(currentRoom.PendingOffers, currentPeer.ID)
				}
				currentRoom.Unlock()
			}

			delivered := currentRoom.SendTo(targetID, msg)
			if !delivered && msg.Type != "offer" {
				log.Printf("[SFU] Info: Signal %s from %s to %s could not be directly delivered (target not currently connected to SFU in room %s)", msg.Type, currentPeer.ID, targetID, currentRoom.UUID)
			}

		case "media_state":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			var state struct {
				IsMuted         *bool `json:"is_muted"`
				IsCameraOn      *bool `json:"is_camera_on"`
				IsSharingScreen *bool `json:"is_sharing_screen"`
			}
			if err := json.Unmarshal(msg.Payload, &state); err == nil {
				currentRoom.Lock()
				if state.IsMuted != nil {
					currentPeer.IsMuted = *state.IsMuted
				}
				if state.IsCameraOn != nil {
					currentPeer.IsCameraOn = *state.IsCameraOn
				}
				if state.IsSharingScreen != nil {
					currentPeer.IsSharingScreen = *state.IsSharingScreen
				}
				currentRoom.Unlock()

				currentRoom.Broadcast(SFUMessage{
					Type:          "peer_media_updated",
					RoomUUID:      currentRoom.UUID,
					ParticipantID: currentPeer.ID,
					PhoneNumber:   currentPeer.PhoneNumber,
					DisplayName:   currentPeer.DisplayName,
					Payload:       msg.Payload,
				}, currentPeer.ID)
			}

		case "screen_share_start":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			currentRoom.Lock()
			currentPeer.IsSharingScreen = true
			muteOthers := currentRoom.MuteOthersOnShare
			if muteOthers {
				for id, ep := range currentRoom.Peers {
					if id != currentPeer.ID && ep.Role != "host" {
						ep.IsMuted = true
					}
				}
			}
			currentRoom.Unlock()

			if muteOthers {
				currentRoom.Broadcast(SFUMessage{
					Type:          "mute_all",
					RoomUUID:      currentRoom.UUID,
					ParticipantID: currentPeer.ID,
				}, currentPeer.ID)
			}

			currentRoom.Broadcast(SFUMessage{
				Type:          "screen_share_started",
				RoomUUID:      currentRoom.UUID,
				ParticipantID: currentPeer.ID,
				PhoneNumber:   currentPeer.PhoneNumber,
				DisplayName:   currentPeer.DisplayName,
			}, currentPeer.ID)

		case "screen_share_stop":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			currentRoom.Lock()
			currentPeer.IsSharingScreen = false
			currentRoom.Unlock()

			currentRoom.Broadcast(SFUMessage{
				Type:          "screen_share_stopped",
				RoomUUID:      currentRoom.UUID,
				ParticipantID: currentPeer.ID,
			}, currentPeer.ID)

		case "grant_screen_share":
			if currentPeer == nil || currentRoom == nil || currentPeer.Role != "host" {
				continue
			}
			var grantPayload struct {
				TargetID string `json:"target_id"`
				CanShare bool   `json:"can_share"`
			}
			if err := json.Unmarshal(msg.Payload, &grantPayload); err == nil {
				targetID := msg.TargetID
				if targetID == "" {
					targetID = grantPayload.TargetID
				}
				currentRoom.Lock()
				if targetPeer, ok := currentRoom.Peers[targetID]; ok {
					targetPeer.CanShareScreen = grantPayload.CanShare
					if !grantPayload.CanShare {
						targetPeer.IsSharingScreen = false
					}
				}
				currentRoom.Unlock()

				currentRoom.Broadcast(SFUMessage{
					Type:          "screen_share_permission_updated",
					RoomUUID:      currentRoom.UUID,
					TargetID:      targetID,
					ParticipantID: currentPeer.ID,
					Payload:       msg.Payload,
				}, "")
			}

		case "room_policy_update":
			if currentPeer == nil || currentRoom == nil || currentPeer.Role != "host" {
				continue
			}
			var policy struct {
				IsLocked *bool `json:"is_locked"`
			}
			if err := json.Unmarshal(msg.Payload, &policy); err == nil {
				currentRoom.Lock()
				if policy.IsLocked != nil {
					currentRoom.IsLocked = *policy.IsLocked
				}
				currentRoom.Unlock()

				currentRoom.Broadcast(SFUMessage{
					Type:          "room_policy_updated",
					RoomUUID:      currentRoom.UUID,
					ParticipantID: currentPeer.ID,
					Payload:       msg.Payload,
				}, "")
			}

		case "raise_hand":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			currentRoom.Lock()
			currentPeer.HandRaised = true
			currentRoom.Unlock()

			currentRoom.Broadcast(SFUMessage{
				Type:          "hand_raised",
				RoomUUID:      currentRoom.UUID,
				ParticipantID: currentPeer.ID,
				DisplayName:   currentPeer.DisplayName,
				PhoneNumber:   currentPeer.PhoneNumber,
			}, "")

		case "lower_hand":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			currentRoom.Lock()
			currentPeer.HandRaised = false
			currentRoom.Unlock()

			currentRoom.Broadcast(SFUMessage{
				Type:          "hand_lowered",
				RoomUUID:      currentRoom.UUID,
				ParticipantID: currentPeer.ID,
			}, "")

		case "chat_message":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			msg.UserID = currentPeer.UserID
			msg.PhoneNumber = currentPeer.PhoneNumber
			msg.DisplayName = currentPeer.DisplayName

			if msg.TargetID != "" {
				currentRoom.SendTo(msg.TargetID, msg)
				_ = currentPeer.Send(msg)
			} else {
				currentRoom.Broadcast(msg, "")
			}

		case "poll_created", "poll_voted", "poll_closed":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			currentRoom.Broadcast(msg, "")

		case "whiteboard_draw":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			msg.UserID = currentPeer.UserID
			msg.PhoneNumber = currentPeer.PhoneNumber
			msg.DisplayName = currentPeer.DisplayName

			if currentRoom.Hub != nil && currentRoom.Hub.redisMgr != nil {
				_ = currentRoom.Hub.redisMgr.SaveWhiteboardStroke(currentRoom.UUID, msg.Payload)
			} else {
				currentRoom.Lock()
				if len(currentRoom.WhiteboardHistory) < 1000 {
					currentRoom.WhiteboardHistory = append(currentRoom.WhiteboardHistory, msg.Payload)
				}
				currentRoom.Unlock()
			}

			currentRoom.Broadcast(msg, currentPeer.ID)

		case "whiteboard_cursor":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			msg.UserID = currentPeer.UserID
			msg.PhoneNumber = currentPeer.PhoneNumber
			msg.DisplayName = currentPeer.DisplayName
			currentRoom.Broadcast(msg, currentPeer.ID)

		case "whiteboard_clear":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			if currentRoom.Hub != nil && currentRoom.Hub.redisMgr != nil {
				_ = currentRoom.Hub.redisMgr.ClearWhiteboard(currentRoom.UUID)
			}
			currentRoom.Lock()
			currentRoom.WhiteboardHistory = make([]json.RawMessage, 0)
			currentRoom.Unlock()

			msg.ParticipantID = currentPeer.ID
			currentRoom.Broadcast(msg, currentPeer.ID)

		case "whiteboard_toggle":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			msg.UserID = currentPeer.UserID
			msg.PhoneNumber = currentPeer.PhoneNumber
			msg.DisplayName = currentPeer.DisplayName
			currentRoom.Broadcast(msg, currentPeer.ID)

		case "room_settings_updated":
			if currentPeer == nil || currentRoom == nil {
				continue
			}
			msg.ParticipantID = currentPeer.ID
			currentRoom.Broadcast(msg, "")

		case "mute_all":
			if currentPeer == nil || currentRoom == nil || currentPeer.Role != "host" {
				continue
			}
			currentRoom.Lock()
			for id, ep := range currentRoom.Peers {
				if id != currentPeer.ID && ep.Role != "host" {
					ep.IsMuted = true
				}
			}
			currentRoom.Unlock()
			currentRoom.Broadcast(SFUMessage{
				Type:          "mute_all",
				RoomUUID:      currentRoom.UUID,
				ParticipantID: currentPeer.ID,
			}, currentPeer.ID)

		case "admit_participant", "reject_participant":
			if currentPeer == nil || currentRoom == nil || currentPeer.Role != "host" {
				continue
			}
			currentRoom.Broadcast(msg, "")

		case "kick":
			if currentPeer == nil || currentRoom == nil || currentPeer.Role != "host" {
				continue
			}
			if msg.TargetID != "" {
				currentRoom.SendTo(msg.TargetID, SFUMessage{
					Type:     "kicked",
					RoomUUID: currentRoom.UUID,
				})
				hub.RemovePeer(currentRoom.UUID, msg.TargetID)
			}
		}
	}
}
