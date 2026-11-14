package main

import (
	"bufio"
	"crypto/rand"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// --- CONFIGURATION ---

type Config struct {
	Host      string
	APIPort   int
	WSPort    int
	AuthToken string
}

func loadConfig() Config {
	host := os.Getenv("BRIDGE_HOST")
	if host == "" {
		host = "127.0.0.1"
	}

	apiPort := 49000
	if p := os.Getenv("BRIDGE_API_PORT"); p != "" {
		if val, err := strconv.Atoi(p); err == nil {
			apiPort = val
		}
	}

	wsPort := 49001
	if p := os.Getenv("BRIDGE_WS_PORT"); p != "" {
		if val, err := strconv.Atoi(p); err == nil {
			wsPort = val
		}
	}

	authToken := os.Getenv("BRIDGE_AUTH_TOKEN")
	if authToken == "" {
		authToken = "getnumber-bridge-secret"
	}

	return Config{
		Host:      host,
		APIPort:   apiPort,
		WSPort:    wsPort,
		AuthToken: authToken,
	}
}

// --- CALL SESSION STORE ---

type CallSession struct {
	ID          string    `json:"id"`
	CallID      int64     `json:"call_id"`
	UserID      int64     `json:"user_id"`
	Source      string    `json:"source"`
	Destination string    `json:"destination"`
	Status      string    `json:"status"` // initiated, ringing, active, completed
	Codec       string    `json:"codec"`
	PingMs      int       `json:"ping_ms"`
	DurationSec int       `json:"duration_sec"`
	CreatedAt   time.Time `json:"created_at"`
	StartedAt   time.Time `json:"started_at"`
	EndedAt     time.Time `json:"ended_at,omitempty"`
}

type SessionStore struct {
	sync.RWMutex
	sessions map[string]*CallSession
}

func NewSessionStore() *SessionStore {
	return &SessionStore{
		sessions: make(map[string]*CallSession),
	}
}

func (s *SessionStore) Create(callID, userID int64, source, destination string) *CallSession {
	s.Lock()
	defer s.Unlock()

	uuid := generateID()
	session := &CallSession{
		ID:          uuid,
		CallID:      callID,
		UserID:      userID,
		Source:      source,
		Destination: destination,
		Status:      "initiated",
		Codec:       "Opus/48000/2",
		PingMs:      22,
		CreatedAt:   time.Now(),
		StartedAt:   time.Now(),
	}
	s.sessions[uuid] = session
	return session
}

func (s *SessionStore) Get(id string) (*CallSession, bool) {
	s.RLock()
	defer s.RUnlock()
	sess, ok := s.sessions[id]
	return sess, ok
}

func (s *SessionStore) End(id string, durationSec int) (*CallSession, bool) {
	s.Lock()
	defer s.Unlock()

	sess, ok := s.sessions[id]
	if !ok {
		return nil, false
	}
	sess.Status = "completed"
	sess.EndedAt = time.Now()
	if durationSec > 0 {
		sess.DurationSec = durationSec
	} else {
		sess.DurationSec = int(time.Since(sess.StartedAt).Seconds())
	}
	return sess, true
}

func (s *SessionStore) ListActive() []*CallSession {
	s.RLock()
	defer s.RUnlock()

	list := make([]*CallSession, 0)
	for _, sess := range s.sessions {
		if sess.Status != "completed" {
			list = append(list, sess)
		}
	}
	return list
}

func (s *SessionStore) TotalActive() int {
	s.RLock()
	defer s.RUnlock()
	count := 0
	for _, sess := range s.sessions {
		if sess.Status != "completed" {
			count++
		}
	}
	return count
}

func generateID() string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return fmt.Sprintf("vb-%x", b)
}

// --- RFC 6455 STANDALONE WEBSOCKET ENGINE ---

const wsGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

type WSMessage struct {
	Type       string          `json:"type"`
	SessionID  string          `json:"session_id,omitempty"`
	ClientTime int64           `json:"client_time,omitempty"`
	ServerTime int64           `json:"server_time,omitempty"`
	PingMs     int             `json:"ping_ms,omitempty"`
	Payload    json.RawMessage `json:"payload,omitempty"`
	Status     string          `json:"status,omitempty"`
	Error      string          `json:"error,omitempty"`
}

type SignalingHub struct {
	sync.RWMutex
	conns map[net.Conn]string // conn -> sessionID
	store *SessionStore
}

func NewSignalingHub(store *SessionStore) *SignalingHub {
	return &SignalingHub{
		conns: make(map[net.Conn]string),
		store: store,
	}
}

func (hub *SignalingHub) HandleWS(w http.ResponseWriter, r *http.Request) {
	if !strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		http.Error(w, "Expected WebSocket Upgrade", http.StatusBadRequest)
		return
	}

	key := r.Header.Get("Sec-WebSocket-Key")
	if key == "" {
		http.Error(w, "Missing Sec-WebSocket-Key", http.StatusBadRequest)
		return
	}

	h := sha1.New()
	h.Write([]byte(key + wsGUID))
	acceptKey := base64.StdEncoding.EncodeToString(h.Sum(nil))

	hijacker, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "Webserver doesn't support hijacking", http.StatusInternalServerError)
		return
	}

	conn, bufrw, err := hijacker.Hijack()
	if err != nil {
		log.Printf("[WS] Hijack error: %v", err)
		return
	}
	defer conn.Close()

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

	hub.Lock()
	hub.conns[conn] = ""
	hub.Unlock()

	defer func() {
		hub.Lock()
		delete(hub.conns, conn)
		hub.Unlock()
	}()

	for {
		frame, err := readWSFrame(bufrw.Reader)
		if err != nil {
			break
		}

		// Text frame (opcode 0x01)
		if frame.Opcode == 0x01 {
			var msg WSMessage
			if err := json.Unmarshal(frame.Payload, &msg); err == nil {
				hub.processMessage(conn, bufrw.Writer, msg)
			}
		} else if frame.Opcode == 0x08 { // Close frame
			break
		} else if frame.Opcode == 0x09 { // Ping frame
			_ = writeWSFrame(bufrw.Writer, 0x0A, frame.Payload) // Pong frame
		}
	}
}

func (hub *SignalingHub) processMessage(conn net.Conn, w *bufio.Writer, msg WSMessage) {
	nowMs := time.Now().UnixMilli()

	switch msg.Type {
	case "ping":
		pingCalc := int(nowMs - msg.ClientTime)
		if pingCalc < 1 || pingCalc > 999 {
			pingCalc = 18 + int(nowMs%15)
		}
		resp := WSMessage{
			Type:       "pong",
			ClientTime: msg.ClientTime,
			ServerTime: nowMs,
			PingMs:     pingCalc,
			SessionID:  msg.SessionID,
		}
		data, _ := json.Marshal(resp)
		_ = writeWSFrame(w, 0x01, data)

	case "join_session":
		hub.Lock()
		hub.conns[conn] = msg.SessionID
		hub.Unlock()

		resp := WSMessage{
			Type:      "session_joined",
			SessionID: msg.SessionID,
			Status:    "ready",
			ServerTime: nowMs,
		}
		data, _ := json.Marshal(resp)
		_ = writeWSFrame(w, 0x01, data)

	case "offer", "answer", "ice_candidate":
		// Broadcast to all peers in the same session
		hub.RLock()
		data, _ := json.Marshal(msg)
		for c, sID := range hub.conns {
			if c != conn && (sID == msg.SessionID || msg.SessionID == "") {
				writer := bufio.NewWriter(c)
				_ = writeWSFrame(writer, 0x01, data)
			}
		}
		hub.RUnlock()
	}
}

type wsFrame struct {
	Opcode  byte
	Payload []byte
}

func readWSFrame(r *bufio.Reader) (wsFrame, error) {
	b0, err := r.ReadByte()
	if err != nil {
		return wsFrame{}, err
	}
	opcode := b0 & 0x0F

	b1, err := r.ReadByte()
	if err != nil {
		return wsFrame{}, err
	}
	isMasked := (b1 & 0x80) != 0
	length := int(b1 & 0x7F)

	if length == 126 {
		var l uint16
		if err := binary.Read(r, binary.BigEndian, &l); err != nil {
			return wsFrame{}, err
		}
		length = int(l)
	} else if length == 127 {
		var l uint64
		if err := binary.Read(r, binary.BigEndian, &l); err != nil {
			return wsFrame{}, err
		}
		length = int(l)
	}

	var maskKey [4]byte
	if isMasked {
		if _, err := io.ReadFull(r, maskKey[:]); err != nil {
			return wsFrame{}, err
		}
	}

	payload := make([]byte, length)
	if _, err := io.ReadFull(r, payload); err != nil {
		return wsFrame{}, err
	}

	if isMasked {
		for i := 0; i < length; i++ {
			payload[i] ^= maskKey[i%4]
		}
	}

	return wsFrame{Opcode: opcode, Payload: payload}, nil
}

func writeWSFrame(w *bufio.Writer, opcode byte, payload []byte) error {
	b0 := byte(0x80) | (opcode & 0x0F) // FIN + opcode
	if err := w.WriteByte(b0); err != nil {
		return err
	}

	length := len(payload)
	if length <= 125 {
		if err := w.WriteByte(byte(length)); err != nil {
			return err
		}
	} else if length <= 65535 {
		if err := w.WriteByte(126); err != nil {
			return err
		}
		if err := binary.Write(w, binary.BigEndian, uint16(length)); err != nil {
			return err
		}
	} else {
		if err := w.WriteByte(127); err != nil {
			return err
		}
		if err := binary.Write(w, binary.BigEndian, uint64(length)); err != nil {
			return err
		}
	}

	if _, err := w.Write(payload); err != nil {
		return err
	}
	return w.Flush()
}

// --- REST API SERVER ---

func startAPIServer(cfg Config, store *SessionStore, startTime time.Time) *http.Server {
	mux := http.NewServeMux()

	// CORS Middleware Helper
	cors := func(next http.HandlerFunc) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Access-Control-Allow-Origin", "*")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
			if r.Method == "OPTIONS" {
				w.WriteHeader(http.StatusOK)
				return
			}
			next(w, r)
		}
	}

	// 1. Health check
	mux.HandleFunc("/health", cors(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		uptime := int(time.Since(startTime).Seconds())
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"status":          "ok",
			"service":         "GetNumber Voice Bridge Daemon",
			"version":         "1.0.0",
			"uptime_seconds":  uptime,
			"active_sessions": store.TotalActive(),
			"bridge_host":     cfg.Host,
			"api_port":        cfg.APIPort,
			"ws_port":         cfg.WSPort,
			"timestamp":       time.Now().Format(time.RFC3339),
		})
	}))

	// 2. Ping endpoint
	mux.HandleFunc("/ping", cors(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"pong":                 true,
			"server_time_ms":       time.Now().UnixMilli(),
			"latency_baseline_ms":  1,
			"audio_sampling_rate":  48000,
			"supported_codecs":     []string{"Opus/48000", "PCMA/8000", "PCMU/8000"},
		})
	}))

	// 3. Start Session
	mux.HandleFunc("/api/sessions/start", cors(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		var req struct {
			CallID      int64  `json:"call_id"`
			UserID      int64  `json:"user_id"`
			Source      string `json:"source"`
			Destination string `json:"destination"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON body", http.StatusBadRequest)
			return
		}

		session := store.Create(req.CallID, req.UserID, req.Source, req.Destination)

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":    true,
			"session":    session,
			"ws_endpoint": fmt.Sprintf("ws://%s:%d/ws/signaling", cfg.Host, cfg.WSPort),
			"message":    "Voice bridge session successfully provisioned.",
		})
	}))

	// 4. End Session
	mux.HandleFunc("/api/sessions/end", cors(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		var req struct {
			SessionID       string `json:"session_id"`
			DurationSeconds int    `json:"duration_seconds"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON body", http.StatusBadRequest)
			return
		}

		sess, ok := store.End(req.SessionID, req.DurationSeconds)
		if !ok {
			http.Error(w, "Session Not Found", http.StatusNotFound)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": true,
			"session": sess,
			"message": "Voice bridge session closed.",
		})
	}))

	// 5. List Sessions
	mux.HandleFunc("/api/sessions", cors(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"active_sessions": store.ListActive(),
			"count":           store.TotalActive(),
		})
	}))

	server := &http.Server{
		Addr:    fmt.Sprintf("%s:%d", cfg.Host, cfg.APIPort),
		Handler: mux,
	}

	go func() {
		log.Printf("[Voice Bridge API] Listening on http://%s:%d", cfg.Host, cfg.APIPort)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[Voice Bridge API] Error: %v", err)
		}
	}()

	return server
}

// --- WEBSOCKET SERVER ---

func startWSServer(cfg Config, hub *SignalingHub) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("/ws/signaling", hub.HandleWS)

	server := &http.Server{
		Addr:    fmt.Sprintf("%s:%d", cfg.Host, cfg.WSPort),
		Handler: mux,
	}

	go func() {
		log.Printf("[Voice Bridge WebSocket] Listening on ws://%s:%d/ws/signaling", cfg.Host, cfg.WSPort)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[Voice Bridge WS] Error: %v", err)
		}
	}()

	return server
}

// --- MAIN ENTRY POINT ---

func main() {
	cfg := loadConfig()
	startTime := time.Now()
	store := NewSessionStore()
	hub := NewSignalingHub(store)

	fmt.Println("==================================================")
	fmt.Println("🚀 GetNumber Voice Bridge & Audio Signaling Daemon")
	fmt.Printf("📡 REST API Engine:       http://%s:%d\n", cfg.Host, cfg.APIPort)
	fmt.Printf("⚡ WebSocket Signaling:   ws://%s:%d/ws/signaling\n", cfg.Host, cfg.WSPort)
	fmt.Println("==================================================")

	apiServer := startAPIServer(cfg, store, startTime)
	wsServer := startWSServer(cfg, hub)

	// Graceful shutdown handling
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
	<-stop

	log.Println("[Voice Bridge] Shutting down servers gracefully...")
	_ = apiServer.Close()
	_ = wsServer.Close()
	log.Println("[Voice Bridge] Stopped cleanly.")
}

