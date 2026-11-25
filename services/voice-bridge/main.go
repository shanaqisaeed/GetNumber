package main

import (
	"bufio"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/pion/logging"
	"github.com/pion/stun/v3"
	"github.com/pion/turn/v4"
)

// --- CONFIGURATION ---

type Config struct {
	Host        string
	APIPort     int
	WSPort      int
	Secret      string
	SecretFile  string
	CallbackURL string

	// Embedded STUN / TURN Relay Configuration
	TurnEnabled    bool
	TurnPort       int
	TurnsPort      int
	TurnRealm      string
	TurnPublicIP   string
	TurnMinPort    int
	TurnMaxPort    int
	TurnSecret     string
	TurnStaticUser string
	TurnStaticPass string
	TurnCertFile   string
	TurnKeyFile    string

	// Embedded SFU Conference Media & Control Configuration
	SFUEnabled bool
	SFUWSPath  string
	SFUMinPort int
	SFUMaxPort int

	// Redis Integration Configuration
	RedisHost     string
	RedisPort     int
	RedisPassword string
	RedisDB       int

	// UDP Media Port Pool (Capacity up to 300 ports)
	UDPPoolMinPort int
	UDPPoolMaxPort int
}

// --- STRING CLEANING & DOTENV HELPERS ---

func loadDotEnvFile(filename string) {
	data, err := os.ReadFile(filename)
	if err != nil {
		return
	}
	lines := strings.Split(string(data), "\n")
	for _, line := range lines {
		line = cleanEnvStr(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.SplitN(line, "=", 2)
		if len(parts) == 2 {
			k := cleanEnvStr(parts[0])
			v := cleanEnvStr(parts[1])
			v = strings.Trim(v, `"'`)
			if os.Getenv(k) == "" {
				os.Setenv(k, v)
			}
		}
	}
}

func cleanEnvStr(s string) string {
	s = strings.ReplaceAll(s, "\r", "")
	s = strings.ReplaceAll(s, "\n", "")
	return strings.TrimSpace(s)
}

func loadConfig() Config {
	// 1. Command-line flags
	flagHost := flag.String("host", "", "Bridge bind host (default 0.0.0.0 or BRIDGE_HOST)")
	flagAPIPort := flag.Int("api-port", 0, "REST API port (default 49000 or BRIDGE_API_PORT)")
	flagWSPort := flag.Int("ws-port", 0, "WebSocket signaling port (default 49001 or BRIDGE_WS_PORT)")
	flagSecret := flag.String("secret", "", "Bridge authentication secret token")
	flagSecretFile := flag.String("secret-file", "", "Path to .secret file")
	flagCallbackURL := flag.String("callback-url", "", "Default Laravel webhook callback URL")

	// STUN / TURN Relay flags
	flagTurnEnabled := flag.Bool("turn-enabled", true, "Enable embedded STUN and TURN relay (default true or TURN_ENABLED)")
	flagTurnPort := flag.Int("turn-port", 0, "STUN/TURN UDP/TCP port (default 3478 or TURN_PORT)")
	flagTurnsPort := flag.Int("turns-port", 0, "TURNS TLS port (default 5349 or TURNS_PORT)")
	flagTurnRealm := flag.String("turn-realm", "", "TURN authentication realm (default ravy-no or TURN_REALM)")
	flagTurnPublicIP := flag.String("turn-public-ip", "", "Explicit public or routable IP for TURN relay (default auto-detected or TURN_PUBLIC_IP)")
	flagTurnMinPort := flag.Int("turn-min-port", 0, "TURN media relay min UDP port (default 49152 or TURN_MIN_PORT)")
	flagTurnMaxPort := flag.Int("turn-max-port", 0, "TURN media relay max UDP port (default 49250 or TURN_MAX_PORT)")
	flagTurnSecret := flag.String("turn-secret", "", "Shared secret for TURN REST API authentication (default bridge secret or TURN_SECRET)")
	flagTurnStaticUser := flag.String("turn-user", "", "Optional static TURN username (or TURN_STATIC_USER)")
	flagTurnStaticPass := flag.String("turn-pass", "", "Optional static TURN password (or TURN_STATIC_PASS)")
	flagTurnCertFile := flag.String("turn-cert", "", "Path to TLS certificate for TURNS (or TURN_CERT_FILE)")
	flagTurnKeyFile := flag.String("turn-key", "", "Path to TLS private key for TURNS (or TURN_KEY_FILE)")

	// SFU Conference Engine flags
	flagSFUEnabled := flag.Bool("sfu-enabled", true, "Enable embedded SFU conference engine (default true or SFU_ENABLED)")
	flagSFUWSPath := flag.String("sfu-ws-path", "", "SFU WebSocket endpoint path (default /ws/sfu or SFU_WS_PATH)")
	flagSFUMinPort := flag.Int("sfu-min-port", 0, "SFU media min UDP port (default 49001 or SFU_MIN_PORT)")
	flagSFUMaxPort := flag.Int("sfu-max-port", 0, "SFU media max UDP port (default 49300 or SFU_MAX_PORT)")

	// Redis Integration flags
	flagRedisHost := flag.String("redis-host", "", "Redis host (default 127.0.0.1 or REDIS_HOST)")
	flagRedisPort := flag.Int("redis-port", 0, "Redis port (default 6379 or REDIS_PORT)")
	flagRedisPass := flag.String("redis-pass", "", "Redis password (or REDIS_PASSWORD)")
	flagRedisDB := flag.Int("redis-db", 0, "Redis DB number (default 0 or REDIS_DB)")

	// UDP Media Port Pool flags (capacity up to 300 ports)
	flagUDPPoolMin := flag.Int("udp-pool-min", 0, "UDP media port pool min (default 49001 or UDP_POOL_MIN_PORT)")
	flagUDPPoolMax := flag.Int("udp-pool-max", 0, "UDP media port pool max (default 49300 or UDP_POOL_MAX_PORT)")
	flag.Parse()

	// Automatically load .env file if present in working directory or binary directory
	loadDotEnvFile(".env")
	if exe, err := os.Executable(); err == nil {
		loadDotEnvFile(filepath.Join(filepath.Dir(exe), ".env"))
	}

	// Host
	host := cleanEnvStr(*flagHost)
	if host == "" {
		host = cleanEnvStr(os.Getenv("BRIDGE_HOST"))
	}
	if host == "" {
		host = "0.0.0.0"
	}

	// API Port
	apiPort := *flagAPIPort
	if apiPort == 0 {
		if p := cleanEnvStr(os.Getenv("BRIDGE_API_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				apiPort = val
			}
		}
	}
	if apiPort == 0 {
		apiPort = 49000
	}

	// WS Port
	wsPort := *flagWSPort
	if wsPort == 0 {
		if p := cleanEnvStr(os.Getenv("BRIDGE_WS_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				wsPort = val
			}
		}
	}
	if wsPort == 0 {
		wsPort = 49001
	}

	// Secret File Path
	secretFile := cleanEnvStr(*flagSecretFile)
	if secretFile == "" {
		secretFile = cleanEnvStr(os.Getenv("BRIDGE_SECRET_FILE"))
	}
	if secretFile == "" {
		// Look in executable directory or current working directory
		if exe, err := os.Executable(); err == nil {
			secretFile = filepath.Join(filepath.Dir(exe), ".secret")
		} else {
			secretFile = ".secret"
		}
	}

	// Secret Value
	secret := cleanEnvStr(*flagSecret)
	if secret == "" {
		secret = cleanEnvStr(os.Getenv("BRIDGE_SECRET"))
	}
	if secret == "" {
		secret = cleanEnvStr(os.Getenv("BRIDGE_AUTH_TOKEN"))
	}

	// If still empty, check .secret file
	if secret == "" {
		if data, err := os.ReadFile(secretFile); err == nil {
			s := cleanEnvStr(string(data))
			if s != "" {
				secret = s
			}
		}
	}

	// If still empty, auto-generate a secure 32-character secret and save to .secret file
	isAutoGenerated := false
	if secret == "" {
		randomBytes := make([]byte, 16)
		if _, err := rand.Read(randomBytes); err == nil {
			secret = hex.EncodeToString(randomBytes)
			isAutoGenerated = true
			_ = os.WriteFile(secretFile, []byte(secret+"\n"), 0600)
		} else {
			// Fallback timestamp-based token
			secret = fmt.Sprintf("gn_sec_%d", time.Now().UnixNano())
			isAutoGenerated = true
			_ = os.WriteFile(secretFile, []byte(secret+"\n"), 0600)
		}
	}

	if isAutoGenerated {
		log.Printf("[Voice Bridge] 🔑 Auto-generated new bridge secret: %s", secret)
		log.Printf("[Voice Bridge] 💾 Saved secret to file: %s", secretFile)
	}

	callbackURL := cleanEnvStr(*flagCallbackURL)
	if callbackURL == "" {
		callbackURL = cleanEnvStr(os.Getenv("BRIDGE_CALLBACK_URL"))
	}
	callbackURL = normalizeCallbackURL(callbackURL)

	// Embedded STUN / TURN Configuration
	turnEnabled := *flagTurnEnabled
	if te := cleanEnvStr(os.Getenv("TURN_ENABLED")); te != "" {
		turnEnabled = te == "true" || te == "1" || te == "yes"
	}

	turnPort := *flagTurnPort
	if turnPort == 0 {
		if p := cleanEnvStr(os.Getenv("TURN_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				turnPort = val
			}
		}
	}
	if turnPort == 0 {
		turnPort = 3478
	}

	turnsPort := *flagTurnsPort
	if turnsPort == 0 {
		if p := cleanEnvStr(os.Getenv("TURNS_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				turnsPort = val
			}
		}
	}
	if turnsPort == 0 {
		turnsPort = 5349
	}

	turnRealm := cleanEnvStr(*flagTurnRealm)
	if turnRealm == "" {
		turnRealm = cleanEnvStr(os.Getenv("TURN_REALM"))
	}
	if turnRealm == "" {
		turnRealm = "ravy-no"
	}

	turnPublicIP := cleanEnvStr(*flagTurnPublicIP)
	if turnPublicIP == "" {
		turnPublicIP = cleanEnvStr(os.Getenv("TURN_PUBLIC_IP"))
	}

	turnMinPort := *flagTurnMinPort
	if turnMinPort == 0 {
		if p := cleanEnvStr(os.Getenv("TURN_MIN_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				turnMinPort = val
			}
		}
	}
	if turnMinPort == 0 {
		turnMinPort = 49001
	}

	turnMaxPort := *flagTurnMaxPort
	if turnMaxPort == 0 {
		if p := cleanEnvStr(os.Getenv("TURN_MAX_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				turnMaxPort = val
			}
		}
	}
	if turnMaxPort == 0 {
		turnMaxPort = 49300
	}

	turnSecret := cleanEnvStr(*flagTurnSecret)
	if turnSecret == "" {
		turnSecret = cleanEnvStr(os.Getenv("TURN_SECRET"))
	}
	if turnSecret == "" {
		turnSecret = secret // Default to bridge secret token
	}

	turnStaticUser := cleanEnvStr(*flagTurnStaticUser)
	if turnStaticUser == "" {
		turnStaticUser = cleanEnvStr(os.Getenv("TURN_STATIC_USER"))
	}
	if turnStaticUser == "" {
		turnStaticUser = cleanEnvStr(os.Getenv("TURN_USER"))
	}

	turnStaticPass := cleanEnvStr(*flagTurnStaticPass)
	if turnStaticPass == "" {
		turnStaticPass = cleanEnvStr(os.Getenv("TURN_STATIC_PASS"))
	}
	if turnStaticPass == "" {
		turnStaticPass = cleanEnvStr(os.Getenv("TURN_PASS"))
	}

	turnCertFile := cleanEnvStr(*flagTurnCertFile)
	if turnCertFile == "" {
		turnCertFile = cleanEnvStr(os.Getenv("TURN_CERT_FILE"))
	}
	if turnCertFile == "" {
		turnCertFile = cleanEnvStr(os.Getenv("TURN_CERT"))
	}

	turnKeyFile := cleanEnvStr(*flagTurnKeyFile)
	if turnKeyFile == "" {
		turnKeyFile = cleanEnvStr(os.Getenv("TURN_KEY_FILE"))
	}
	if turnKeyFile == "" {
		turnKeyFile = cleanEnvStr(os.Getenv("TURN_KEY"))
	}

	// SFU Conference Engine Settings (Reusing 49152-49250 UDP port range)
	sfuEnabled := *flagSFUEnabled
	if env := cleanEnvStr(os.Getenv("SFU_ENABLED")); env != "" {
		sfuEnabled = (env == "true" || env == "1" || env == "yes")
	}

	sfuWSPath := cleanEnvStr(*flagSFUWSPath)
	if sfuWSPath == "" {
		sfuWSPath = cleanEnvStr(os.Getenv("SFU_WS_PATH"))
	}
	if sfuWSPath == "" {
		sfuWSPath = "/ws/sfu"
	}

	sfuMinPort := *flagSFUMinPort
	if sfuMinPort == 0 {
		if p := cleanEnvStr(os.Getenv("SFU_MIN_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				sfuMinPort = val
			}
		}
	}
	if sfuMinPort == 0 {
		sfuMinPort = turnMinPort
	}

	sfuMaxPort := *flagSFUMaxPort
	if sfuMaxPort == 0 {
		if p := cleanEnvStr(os.Getenv("SFU_MAX_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				sfuMaxPort = val
			}
		}
	}
	if sfuMaxPort == 0 {
		sfuMaxPort = turnMaxPort
	}
	if sfuMinPort <= 0 || sfuMinPort > 65535 {
		sfuMinPort = 49001
	}
	if sfuMaxPort <= 0 || sfuMaxPort > 65535 || sfuMaxPort < sfuMinPort {
		sfuMaxPort = 49300
	}
	if !strings.HasPrefix(sfuWSPath, "/") {
		sfuWSPath = "/" + sfuWSPath
	}

	// UDP Media Port Pool (up to 300 UDP ports)
	udpPoolMin := *flagUDPPoolMin
	if udpPoolMin == 0 {
		if p := cleanEnvStr(os.Getenv("UDP_POOL_MIN_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				udpPoolMin = val
			}
		}
	}
	if udpPoolMin == 0 {
		udpPoolMin = sfuMinPort
	}

	udpPoolMax := *flagUDPPoolMax
	if udpPoolMax == 0 {
		if p := cleanEnvStr(os.Getenv("UDP_POOL_MAX_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				udpPoolMax = val
			}
		}
	}
	if udpPoolMax == 0 {
		udpPoolMax = sfuMaxPort
	}
	if udpPoolMax < udpPoolMin {
		udpPoolMax = udpPoolMin + 299
	}

	// Redis Configuration
	redisHost := cleanEnvStr(*flagRedisHost)
	if redisHost == "" {
		redisHost = cleanEnvStr(os.Getenv("REDIS_HOST"))
	}
	if redisHost == "" {
		redisHost = "127.0.0.1"
	}

	redisPort := *flagRedisPort
	if redisPort == 0 {
		if p := cleanEnvStr(os.Getenv("REDIS_PORT")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				redisPort = val
			}
		}
	}
	if redisPort == 0 {
		redisPort = 6379
	}

	redisPass := cleanEnvStr(*flagRedisPass)
	if redisPass == "" {
		redisPass = cleanEnvStr(os.Getenv("REDIS_PASSWORD"))
	}

	redisDB := *flagRedisDB
	if redisDB == 0 {
		if p := cleanEnvStr(os.Getenv("REDIS_DB")); p != "" {
			if val, err := strconv.Atoi(p); err == nil {
				redisDB = val
			}
		}
	}

	return Config{
		Host:           host,
		APIPort:        apiPort,
		WSPort:         wsPort,
		Secret:         secret,
		SecretFile:     secretFile,
		CallbackURL:    callbackURL,
		TurnEnabled:    turnEnabled,
		TurnPort:       turnPort,
		TurnsPort:      turnsPort,
		TurnRealm:      turnRealm,
		TurnPublicIP:   turnPublicIP,
		TurnMinPort:    turnMinPort,
		TurnMaxPort:    turnMaxPort,
		TurnSecret:     turnSecret,
		TurnStaticUser: turnStaticUser,
		TurnStaticPass: turnStaticPass,
		TurnCertFile:   turnCertFile,
		TurnKeyFile:    turnKeyFile,
		SFUEnabled:     sfuEnabled,
		SFUWSPath:      sfuWSPath,
		SFUMinPort:     sfuMinPort,
		SFUMaxPort:     sfuMaxPort,
		RedisHost:      redisHost,
		RedisPort:      redisPort,
		RedisPassword:  redisPass,
		RedisDB:        redisDB,
		UDPPoolMinPort: udpPoolMin,
		UDPPoolMaxPort: udpPoolMax,
	}
}

// --- CALL SESSION STORE ---

type CallSession struct {
	ID              string      `json:"id"`
	CallID          int64       `json:"call_id"`
	UserID          int64       `json:"user_id"`
	Source          string      `json:"source"`
	Destination     string      `json:"destination"`
	Status          string      `json:"status"` // initiated, ringing, active, completed
	Codec           string      `json:"codec"`
	PingMs          int         `json:"ping_ms"`
	DurationSec     int         `json:"duration_sec"`
	CallbackURL     string      `json:"callback_url,omitempty"`
	BufferedSignals []WSMessage `json:"-"`
	CreatedAt       time.Time   `json:"created_at"`
	StartedAt       time.Time   `json:"started_at"`
	EndedAt         time.Time   `json:"ended_at,omitempty"`
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

func (s *SessionStore) Create(callID, userID int64, source, destination, callbackURL string) *CallSession {
	s.Lock()
	defer s.Unlock()

	uuid := generateID()
	session := &CallSession{
		ID:              uuid,
		CallID:          callID,
		UserID:          userID,
		Source:          source,
		Destination:     destination,
		Status:          "initiated",
		Codec:           "Opus/48000/2",
		PingMs:          22,
		CallbackURL:     callbackURL,
		BufferedSignals: make([]WSMessage, 0),
		CreatedAt:       time.Now(),
		StartedAt:       time.Now(),
	}
	s.sessions[uuid] = session
	return session
}

func (s *SessionStore) BufferSignal(id string, msg WSMessage) {
	s.Lock()
	defer s.Unlock()
	sess, ok := s.sessions[id]
	if !ok || sess.Status == "completed" {
		return
	}
	if msg.Type == "offer" {
		// Replace any previous offer and clear stale ICE candidates
		filtered := make([]WSMessage, 0)
		for _, m := range sess.BufferedSignals {
			if m.Type != "offer" && m.Type != "ice_candidate" {
				filtered = append(filtered, m)
			}
		}
		sess.BufferedSignals = append(filtered, msg)
	} else {
		if len(sess.BufferedSignals) < 64 {
			sess.BufferedSignals = append(sess.BufferedSignals, msg)
		}
	}
}

func (s *SessionStore) GetBufferedSignals(id string) []WSMessage {
	s.RLock()
	defer s.RUnlock()
	sess, ok := s.sessions[id]
	if !ok || len(sess.BufferedSignals) == 0 {
		return nil
	}
	out := make([]WSMessage, len(sess.BufferedSignals))
	copy(out, sess.BufferedSignals)
	return out
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

// --- AUTHENTICATION HELPERS ---

func extractSecret(r *http.Request) string {
	// 1. Header: X-Bridge-Secret
	if h := r.Header.Get("X-Bridge-Secret"); h != "" {
		return strings.TrimSpace(h)
	}

	// 2. Header: Authorization: Bearer <secret>
	if auth := r.Header.Get("Authorization"); auth != "" {
		parts := strings.SplitN(auth, " ", 2)
		if len(parts) == 2 && strings.EqualFold(parts[0], "Bearer") {
			return strings.TrimSpace(parts[1])
		}
	}

	// 3. Query string: ?secret=... or ?token=...
	if q := r.URL.Query().Get("secret"); q != "" {
		return strings.TrimSpace(q)
	}
	if q := r.URL.Query().Get("token"); q != "" {
		return strings.TrimSpace(q)
	}

	return ""
}

func validateSecret(provided, expected string) bool {
	if expected == "" {
		return true // Auth disabled if no secret is set
	}
	if provided == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(provided), []byte(expected)) == 1
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
	conns              map[net.Conn]string // conn -> sessionID
	store              *SessionStore
	secret             string
	defaultCallbackURL string
	disconnectTimers   map[string]*time.Timer // sessionID -> grace period timer
}

func NewSignalingHub(store *SessionStore, secret string, defaultCallbackURL string) *SignalingHub {
	return &SignalingHub{
		conns:              make(map[net.Conn]string),
		store:              store,
		secret:             secret,
		defaultCallbackURL: defaultCallbackURL,
		disconnectTimers:   make(map[string]*time.Timer),
	}
}

func (hub *SignalingHub) HandleWS(w http.ResponseWriter, r *http.Request) {
	// Authorize via either master secret OR valid provisioned session ID (?session=vb-xxx)
	sessionParam := strings.TrimSpace(r.URL.Query().Get("session"))
	if sessionParam == "" {
		sessionParam = strings.TrimSpace(r.URL.Query().Get("session_id"))
	}

	if hub.secret != "" {
		provided := extractSecret(r)
		secretValid := validateSecret(provided, hub.secret)
		sessionValid := false
		if sessionParam != "" {
			if sess, ok := hub.store.Get(sessionParam); ok && sess.Status != "completed" {
				sessionValid = true
			}
		}
		if !secretValid && !sessionValid {
			http.Error(w, "Unauthorized: invalid or missing bridge secret or session token", http.StatusUnauthorized)
			return
		}
	}

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
		sessID := hub.conns[conn]
		delete(hub.conns, conn)

		peersLeft := 0
		for _, s := range hub.conns {
			if s == sessID {
				peersLeft++
			}
		}
		hub.Unlock()

		if sessID != "" {
			if peersLeft > 0 {
				// At least one peer remains waiting in session: start 15s grace period for temporary network/socket recovery
				reconnMsg := WSMessage{
					Type:       "peer_reconnecting",
					SessionID:  sessID,
					Status:     "reconnecting",
					ServerTime: time.Now().UnixMilli(),
				}
				data, _ := json.Marshal(reconnMsg)

				hub.RLock()
				for c, s := range hub.conns {
					if s == sessID {
						writer := bufio.NewWriter(c)
						_ = writeWSFrame(writer, 0x01, data)
					}
				}
				hub.RUnlock()

				log.Printf("[VOICE-BRIDGE] ⏳ Peer temporarily disconnected (session: %s), 15s grace period active for network recovery...", sessID)

				hub.Lock()
				if existingTimer, exists := hub.disconnectTimers[sessID]; exists {
					existingTimer.Stop()
				}
				hub.disconnectTimers[sessID] = time.AfterFunc(15*time.Second, func() {
					hub.Lock()
					delete(hub.disconnectTimers, sessID)
					curPeers := 0
					for _, s := range hub.conns {
						if s == sessID {
							curPeers++
						}
					}
					hub.Unlock()

					// If still not reconnected, finalize termination
					if curPeers <= 1 {
						sess, hasSess := hub.store.Get(sessID)
						callbackURL := hub.defaultCallbackURL
						if hasSess && sess.CallbackURL != "" {
							callbackURL = sess.CallbackURL
						}
						hub.store.End(sessID, 0)

						endMsg := WSMessage{
							Type:       "call_ended",
							SessionID:  sessID,
							Status:     "completed",
							ServerTime: time.Now().UnixMilli(),
						}
						endData, _ := json.Marshal(endMsg)

						hub.RLock()
						for c, s := range hub.conns {
							if s == sessID {
								writer := bufio.NewWriter(c)
								_ = writeWSFrame(writer, 0x01, endData)
							}
						}
						hub.RUnlock()

						if callbackURL != "" {
							notifyLaravelWebhook(callbackURL, hub.secret, "session_ended", sessID, 0)
						}
						log.Printf("[VOICE-BRIDGE] ⚠️ Grace period expired for session %s (peer did not reconnect). Call ended.", sessID)
					}
				})
				hub.Unlock()
			} else {
				// No peers left at all in session - clean up session
				sess, hasSess := hub.store.Get(sessID)
				callbackURL := hub.defaultCallbackURL
				if hasSess && sess.CallbackURL != "" {
					callbackURL = sess.CallbackURL
				}
				hub.store.End(sessID, 0)

				if callbackURL != "" {
					notifyLaravelWebhook(callbackURL, hub.secret, "session_ended", sessID, 0)
				}
				log.Printf("[VOICE-BRIDGE] ⚠️ All peers left session %s, session ended.", sessID)
			}
		}
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

func normalizeCallbackURL(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	raw = strings.TrimRight(raw, "/")
	if !strings.Contains(raw, "bridge-event") {
		if strings.HasSuffix(raw, "/api") {
			return raw + "/voice/bridge-event"
		}
		return raw + "/api/voice/bridge-event"
	}
	return raw
}

func notifyLaravelWebhook(callbackURL, secret, event, sessionID string, duration int) {
	callbackURL = normalizeCallbackURL(callbackURL)
	if callbackURL == "" {
		return
	}
	go func() {
		payload := map[string]interface{}{
			"event":            event,
			"session_id":       sessionID,
			"duration_seconds": duration,
		}
		data, _ := json.Marshal(payload)
		req, err := http.NewRequest("POST", callbackURL, strings.NewReader(string(data)))
		if err != nil {
			return
		}
		req.Header.Set("Content-Type", "application/json")
		if secret != "" {
			req.Header.Set("X-Bridge-Secret", secret)
		}
		client := &http.Client{Timeout: 3 * time.Second}
		resp, err := client.Do(req)
		if err == nil && resp != nil {
			_ = resp.Body.Close()
			log.Printf("[VOICE-BRIDGE] 📡 Laravel webhook dispatched (event: %s | session: %s)", event, sessionID)
		}
	}()
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
		if timer, exists := hub.disconnectTimers[msg.SessionID]; exists {
			timer.Stop()
			delete(hub.disconnectTimers, msg.SessionID)
			log.Printf("[VOICE-BRIDGE] ✅ Peer reconnected successfully within grace period (session: %s)! Disconnect timer cancelled.", msg.SessionID)
		}
		activeInSession := 0
		for _, sID := range hub.conns {
			if sID == msg.SessionID {
				activeInSession++
			}
		}
		hub.Unlock()

		sess, hasSess := hub.store.Get(msg.SessionID)
		srcNum := "unknown"
		dstNum := "unknown"
		if hasSess {
			srcNum = sess.Source
			dstNum = sess.Destination
		}

		log.Printf("[VOICE-BRIDGE] 🔗 WebSocket connected: voice signaling channel active %s <-> %s (session: %s | active_peers: %d)", srcNum, dstNum, msg.SessionID, activeInSession)

		resp := WSMessage{
			Type:       "session_joined",
			SessionID:  msg.SessionID,
			Status:     "ready",
			ServerTime: nowMs,
		}
		data, _ := json.Marshal(resp)
		_ = writeWSFrame(w, 0x01, data)

		// Replay any buffered WebRTC offer / ICE candidates sent by the caller before the callee joined
		if buffered := hub.store.GetBufferedSignals(msg.SessionID); len(buffered) > 0 && activeInSession >= 2 {
			for _, bMsg := range buffered {
				bData, _ := json.Marshal(bMsg)
				_ = writeWSFrame(w, 0x01, bData)
			}
			log.Printf("[VOICE-BRIDGE] 🔁 Replayed %d buffered WebRTC signal(s) to newly joined peer (session: %s)", len(buffered), msg.SessionID)
		}

		if activeInSession >= 2 {
			log.Printf("[VOICE-BRIDGE] 🚀 Two-way voice channel synchronized: %s <-> %s (session: %s)", srcNum, dstNum, msg.SessionID)
			connectedMsg := WSMessage{
				Type:       "channel_connected",
				SessionID:  msg.SessionID,
				Status:     "connected",
				ServerTime: nowMs,
			}
			cData, _ := json.Marshal(connectedMsg)
			hub.RLock()
			for c, sID := range hub.conns {
				if sID == msg.SessionID {
					writer := bufio.NewWriter(c)
					_ = writeWSFrame(writer, 0x01, cData)
				}
			}
			hub.RUnlock()
		}

	case "offer", "answer", "ice_candidate", "renegotiate", "camera_status", "screen_share_status", "whiteboard_event", "route_selected":
		sess, hasSess := hub.store.Get(msg.SessionID)
		srcNum := "caller"
		dstNum := "callee"
		if hasSess {
			srcNum = sess.Source
			dstNum = sess.Destination
		}

		extraInfo := ""
		if msg.Type == "ice_candidate" && len(msg.Payload) > 0 {
			var candMap map[string]interface{}
			if err := json.Unmarshal(msg.Payload, &candMap); err == nil {
				if candStr, ok := candMap["candidate"].(string); ok {
					if strings.Contains(candStr, "typ relay") {
						extraInfo = " [RELAY (TURN)]"
					} else if strings.Contains(candStr, "typ srflx") {
						extraInfo = " [SRFLX (STUN)]"
					} else if strings.Contains(candStr, "typ host") {
						extraInfo = " [HOST (LAN)]"
					}
				}
			}
		} else if msg.Type == "offer" || msg.Type == "answer" {
			extraInfo = fmt.Sprintf(" [SDP %d bytes]", len(msg.Payload))
		}

		log.Printf("[VOICE-BRIDGE] 🎙️ WebRTC signal relayed: %s%s (%s <-> %s | session: %s)", msg.Type, extraInfo, srcNum, dstNum, msg.SessionID)

		// Buffer offers and ICE candidates in session so late-joining peers receive them
		if msg.SessionID != "" && (msg.Type == "offer" || msg.Type == "ice_candidate") {
			hub.store.BufferSignal(msg.SessionID, msg)
		}

		// Broadcast ONLY to peers in the exact same session (never leak when SessionID is empty)
		if msg.SessionID != "" {
			hub.RLock()
			data, _ := json.Marshal(msg)
			for c, sID := range hub.conns {
				if c != conn && sID == msg.SessionID {
					writer := bufio.NewWriter(c)
					_ = writeWSFrame(writer, 0x01, data)
				}
			}
			hub.RUnlock()
		}

	case "end_call":
		hub.Lock()
		if timer, exists := hub.disconnectTimers[msg.SessionID]; exists {
			timer.Stop()
			delete(hub.disconnectTimers, msg.SessionID)
		}
		hub.Unlock()

		sess, hasSess := hub.store.Get(msg.SessionID)
		srcNum := "caller"
		dstNum := "callee"
		callbackURL := hub.defaultCallbackURL
		if hasSess {
			srcNum = sess.Source
			dstNum = sess.Destination
			if sess.CallbackURL != "" {
				callbackURL = sess.CallbackURL
			}
			hub.store.End(msg.SessionID, 0)
		}
		log.Printf("[VOICE-BRIDGE] 🛑 Call termination signal received from peer (%s <-> %s | session: %s)", srcNum, dstNum, msg.SessionID)

		// Broadcast call_ended to all peers in this session so the other side immediately hangs up!
		if msg.SessionID != "" {
			endMsg := WSMessage{
				Type:       "call_ended",
				SessionID:  msg.SessionID,
				Status:     "completed",
				ServerTime: nowMs,
			}
			data, _ := json.Marshal(endMsg)
			hub.RLock()
			for c, sID := range hub.conns {
				if c != conn && sID == msg.SessionID {
					writer := bufio.NewWriter(c)
					_ = writeWSFrame(writer, 0x01, data)
				}
			}
			hub.RUnlock()
		}

		if callbackURL != "" {
			notifyLaravelWebhook(callbackURL, hub.secret, "session_ended", msg.SessionID, 0)
		}
	}
}

type wsFrame struct {
	Opcode  byte
	Payload []byte
}

const maxWSFrameBytes = 2 * 1024 * 1024 // 2 MB cap against malformed/oversized frames

func readRawWSFragment(r *bufio.Reader) (fin bool, opcode byte, payload []byte, err error) {
	b0, err := r.ReadByte()
	if err != nil {
		return false, 0, nil, err
	}
	fin = (b0 & 0x80) != 0
	opcode = b0 & 0x0F

	b1, err := r.ReadByte()
	if err != nil {
		return false, 0, nil, err
	}
	isMasked := (b1 & 0x80) != 0
	length := int(b1 & 0x7F)

	if length == 126 {
		var l uint16
		if err := binary.Read(r, binary.BigEndian, &l); err != nil {
			return false, 0, nil, err
		}
		length = int(l)
	} else if length == 127 {
		var l uint64
		if err := binary.Read(r, binary.BigEndian, &l); err != nil {
			return false, 0, nil, err
		}
		if l > uint64(maxWSFrameBytes) {
			return false, 0, nil, fmt.Errorf("websocket frame exceeds max size (%d bytes)", l)
		}
		length = int(l)
	}

	if length < 0 || length > maxWSFrameBytes {
		return false, 0, nil, fmt.Errorf("invalid websocket frame length: %d", length)
	}

	var maskKey [4]byte
	if isMasked {
		if _, err := io.ReadFull(r, maskKey[:]); err != nil {
			return false, 0, nil, err
		}
	}

	payload = make([]byte, length)
	if _, err := io.ReadFull(r, payload); err != nil {
		return false, 0, nil, err
	}

	if isMasked {
		for i := 0; i < length; i++ {
			payload[i] ^= maskKey[i%4]
		}
	}

	return fin, opcode, payload, nil
}

// readWSFrame reads a full RFC 6455 message, automatically reassembling fragmented frames
// (FIN=0 followed by Opcode=0x00 continuation frames) for large WebRTC Video SDPs and whiteboard syncs.
func readWSFrame(r *bufio.Reader) (wsFrame, error) {
	fin, opcode, payload, err := readRawWSFragment(r)
	if err != nil {
		return wsFrame{}, err
	}

	// Control frames (Close 0x08, Ping 0x09, Pong 0x0A) are never fragmented
	if fin || opcode >= 0x08 {
		return wsFrame{Opcode: opcode, Payload: payload}, nil
	}

	initialOpcode := opcode
	assembled := make([]byte, 0, len(payload)*2)
	assembled = append(assembled, payload...)

	for {
		nextFin, nextOp, nextPayload, err := readRawWSFragment(r)
		if err != nil {
			return wsFrame{}, err
		}
		// Control frames may be interleaved between fragments per RFC 6455
		if nextOp >= 0x08 {
			return wsFrame{Opcode: nextOp, Payload: nextPayload}, nil
		}
		if len(assembled)+len(nextPayload) > maxWSFrameBytes {
			return wsFrame{}, fmt.Errorf("reassembled websocket message exceeds max size")
		}
		assembled = append(assembled, nextPayload...)
		if nextFin {
			break
		}
	}

	return wsFrame{Opcode: initialOpcode, Payload: assembled}, nil
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

// --- EMBEDDED STUN & TURN RELAY SERVER ---

func resolveRelayIP(explicitIP string) net.IP {
	if explicitIP != "" {
		if ip := net.ParseIP(strings.TrimSpace(explicitIP)); ip != nil {
			return ip
		}
		// Try resolving hostname or domain name
		if ips, err := net.LookupIP(strings.TrimSpace(explicitIP)); err == nil && len(ips) > 0 {
			for _, ip := range ips {
				if ip4 := ip.To4(); ip4 != nil {
					return ip4
				}
			}
		}
		log.Printf("[Voice Bridge TURN] ⚠️ Could not parse or resolve explicit IP '%s', auto-detecting...", explicitIP)
	}

	// 1. Dial dummy UDP packet to detect primary default route interface
	conn, err := net.Dial("udp", "8.8.8.8:80")
	if err == nil {
		defer conn.Close()
		localAddr := conn.LocalAddr().(*net.UDPAddr)
		if localAddr.IP != nil && !localAddr.IP.IsLoopback() && localAddr.IP.To4() != nil {
			return localAddr.IP.To4()
		}
	}

	// 2. Scan network interfaces for the first active non-loopback IPv4 address
	ifaces, err := net.Interfaces()
	if err == nil {
		for _, iface := range ifaces {
			if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
				continue
			}
			addrs, err := iface.Addrs()
			if err != nil {
				continue
			}
			for _, addr := range addrs {
				var ip net.IP
				switch v := addr.(type) {
				case *net.IPNet:
					ip = v.IP
				case *net.IPAddr:
					ip = v.IP
				}
				if ip != nil && !ip.IsLoopback() && ip.To4() != nil {
					return ip.To4()
				}
			}
		}
	}

	return net.ParseIP("127.0.0.1")
}

// --- STUN & TURN LOGGING WRAPPERS ---

type stunLoggingPacketConn struct {
	net.PacketConn
	label string
}

func (s *stunLoggingPacketConn) ReadFrom(p []byte) (n int, addr net.Addr, err error) {
	n, addr, err = s.PacketConn.ReadFrom(p)
	if err == nil && n > 0 && stun.IsMessage(p[:n]) {
		var m stun.Message
		m.Raw = p[:n]
		if dErr := m.Decode(); dErr == nil {
			log.Printf("[Voice Bridge STUN/TURN] 📥 INBOUND %s %s from %s (%d bytes)",
				m.Type.Class, m.Type.Method, addr.String(), n)
		}
	}
	return
}

func (s *stunLoggingPacketConn) WriteTo(p []byte, addr net.Addr) (n int, err error) {
	n, err = s.PacketConn.WriteTo(p, addr)
	if err == nil && n > 0 && stun.IsMessage(p[:n]) {
		var m stun.Message
		m.Raw = p[:n]
		if dErr := m.Decode(); dErr == nil {
			var errCode stun.ErrorCodeAttribute
			statusExtra := ""
			if errCode.GetFrom(&m) == nil {
				statusExtra = fmt.Sprintf(" [%d: %s]", errCode.Code, errCode.Reason)
			}
			log.Printf("[Voice Bridge STUN/TURN] 📤 OUTBOUND %s %s to %s (%d bytes)%s",
				m.Type.Class, m.Type.Method, addr.String(), n, statusExtra)
		}
	}
	return
}

type mediaLoggingPacketConn struct {
	net.PacketConn
	relayAddr    string
	readPackets  uint64
	readBytes    uint64
	writePackets uint64
	writeBytes   uint64
}

func (m *mediaLoggingPacketConn) ReadFrom(p []byte) (n int, addr net.Addr, err error) {
	n, addr, err = m.PacketConn.ReadFrom(p)
	if err == nil && n > 0 {
		pkts := atomic.AddUint64(&m.readPackets, 1)
		atomic.AddUint64(&m.readBytes, uint64(n))
		if pkts == 1 {
			log.Printf("[Voice Bridge MEDIA] 🔊 FIRST Inbound audio packet on relay %s: %d bytes from %s",
				m.relayAddr, n, addr.String())
		} else if pkts%100 == 0 {
			log.Printf("[Voice Bridge MEDIA] 📈 Inbound audio traffic on %s: %d pkts (%d KB) from %s",
				m.relayAddr, pkts, atomic.LoadUint64(&m.readBytes)/1024, addr.String())
		}
	}
	return
}

func (m *mediaLoggingPacketConn) WriteTo(p []byte, addr net.Addr) (n int, err error) {
	n, err = m.PacketConn.WriteTo(p, addr)
	if err == nil && n > 0 {
		pkts := atomic.AddUint64(&m.writePackets, 1)
		atomic.AddUint64(&m.writeBytes, uint64(n))
		if pkts == 1 {
			log.Printf("[Voice Bridge MEDIA] 🔊 FIRST Outbound audio packet on relay %s: %d bytes to %s",
				m.relayAddr, n, addr.String())
		} else if pkts%100 == 0 {
			log.Printf("[Voice Bridge MEDIA] 📈 Outbound audio traffic on %s: %d pkts (%d KB) to %s",
				m.relayAddr, pkts, atomic.LoadUint64(&m.writeBytes)/1024, addr.String())
		}
	}
	return
}

func (m *mediaLoggingPacketConn) Close() error {
	inPkts := atomic.LoadUint64(&m.readPackets)
	outPkts := atomic.LoadUint64(&m.writePackets)
	inBytes := atomic.LoadUint64(&m.readBytes)
	outBytes := atomic.LoadUint64(&m.writeBytes)
	log.Printf("[Voice Bridge MEDIA] ⏹️ Media relay session ended on %s (In: %d pkts/%d KB | Out: %d pkts/%d KB)",
		m.relayAddr, inPkts, inBytes/1024, outPkts, outBytes/1024)
	return m.PacketConn.Close()
}

type loggingRelayGenerator struct {
	gen turn.RelayAddressGenerator
}

func (g *loggingRelayGenerator) Validate() error {
	return g.gen.Validate()
}

func (g *loggingRelayGenerator) AllocateConn(network string, requestedPort int) (net.Conn, net.Addr, error) {
	conn, addr, err := g.gen.AllocateConn(network, requestedPort)
	if err != nil {
		log.Printf("[Voice Bridge RELAY] ❌ AllocateConn failed (port %d): %v", requestedPort, err)
		return nil, nil, err
	}
	log.Printf("[Voice Bridge RELAY] 🎯 Relay TCP socket ALLOCATED on %s", addr.String())
	return conn, addr, nil
}

func (g *loggingRelayGenerator) AllocatePacketConn(network string, requestedPort int) (net.PacketConn, net.Addr, error) {
	conn, addr, err := g.gen.AllocatePacketConn(network, requestedPort)
	if err != nil {
		log.Printf("[Voice Bridge RELAY] ❌ AllocatePacketConn failed (port %d): %v", requestedPort, err)
		return nil, nil, err
	}
	log.Printf("[Voice Bridge RELAY] 🎯 Relay UDP socket ALLOCATED on %s (Ready for bidirectional voice stream!)", addr.String())
	return &mediaLoggingPacketConn{PacketConn: conn, relayAddr: addr.String()}, addr, nil
}

func buildTurnAuthHandler(sharedSecret, realm, staticUser, staticPass string) turn.AuthHandler {
	cleanSecret := cleanEnvStr(sharedSecret)
	cleanRealm := cleanEnvStr(realm)
	cleanStaticUser := cleanEnvStr(staticUser)
	cleanStaticPass := cleanEnvStr(staticPass)

	return func(username, reqRealm string, srcAddr net.Addr) ([]byte, bool) {
		clientIP := "unknown"
		if srcAddr != nil {
			clientIP = srcAddr.String()
		}

		effectiveRealm := cleanEnvStr(reqRealm)
		if effectiveRealm == "" {
			effectiveRealm = cleanRealm
		}

		log.Printf("[Voice Bridge TURN-AUTH] 🔍 Auth attempt: User='%s' | ReqRealm='%s' (Using '%s') from %s",
			username, reqRealm, effectiveRealm, clientIP)

		// 1. Static user/pass fallback
		if cleanStaticUser != "" && username == cleanStaticUser {
			log.Printf("[Voice Bridge TURN-AUTH] ✅ Static auth SUCCESS for user: '%s' from %s", username, clientIP)
			return turn.GenerateAuthKey(username, effectiveRealm, cleanStaticPass), true
		}

		// 2. TURN REST ephemeral credentials: "timestamp:userId"
		if cleanSecret != "" {
			parts := strings.SplitN(username, ":", 2)
			if len(parts) == 2 {
				expiry, err := strconv.ParseInt(parts[0], 10, 64)
				if err == nil {
					now := time.Now().Unix()
					// Allow 5 minutes clock skew between server and client/Laravel
					if now <= expiry+300 {
						mac := hmac.New(sha1.New, []byte(cleanSecret))
						mac.Write([]byte(username))
						expectedPassword := base64.StdEncoding.EncodeToString(mac.Sum(nil))
						key := turn.GenerateAuthKey(username, effectiveRealm, expectedPassword)
						log.Printf("[Voice Bridge TURN-AUTH] ✅ HMAC REST auth SUCCESS for user: '%s' (expires in %ds) from %s",
							username, expiry-now, clientIP)
						return key, true
					} else {
						log.Printf("[Voice Bridge TURN-AUTH] ❌ HMAC auth FAILED: Token expired! (Expiry: %d, ServerNow: %d, Skew: %ds) for user: '%s' from %s",
							expiry, now, now-expiry, username, clientIP)
					}
				} else {
					log.Printf("[Voice Bridge TURN-AUTH] ❌ HMAC auth FAILED: Non-numeric timestamp in username '%s' from %s",
						username, clientIP)
				}
			} else {
				log.Printf("[Voice Bridge TURN-AUTH] ❌ HMAC auth FAILED: Username '%s' does not contain 'timestamp:id' delimiter from %s",
					username, clientIP)
			}
		} else {
			log.Printf("[Voice Bridge TURN-AUTH] ❌ Auth FAILED: No shared secret configured on server! from %s", clientIP)
		}

		log.Printf("[Voice Bridge TURN-AUTH] ❌ Auth REJECTED for user: '%s' from %s", username, clientIP)
		return nil, false
	}
}

type TurnServer struct {
	server       *turn.Server
	udpConn      net.PacketConn
	tcpListener  net.Listener
	tlsListener  net.Listener
	relayIP      net.IP
	port         int
	turnsPort    int
	realm        string
	minPort      int
	maxPort      int
	hasTLS       bool
}

func (t *TurnServer) Close() error {
	if t == nil || t.server == nil {
		return nil
	}
	log.Println("[Voice Bridge TURN] Stopping STUN/TURN server...")
	return t.server.Close()
}

func startTurnServer(cfg Config) (*TurnServer, error) {
	if !cfg.TurnEnabled {
		log.Println("[Voice Bridge TURN] Embedded STUN/TURN server is disabled by configuration.")
		return nil, nil
	}

	publicIP := cfg.TurnPublicIP
	if publicIP == "" && cfg.Host != "" && cfg.Host != "0.0.0.0" && cfg.Host != "127.0.0.1" && cfg.Host != "localhost" {
		publicIP = cfg.Host
	}
	relayIP := resolveRelayIP(publicIP)
	log.Printf("[Voice Bridge STUN/TURN] 🌍 Relay address bound to IP: %s (media relay range: %d-%d)",
		relayIP.String(), cfg.TurnMinPort, cfg.TurnMaxPort)

	authHandler := buildTurnAuthHandler(cfg.TurnSecret, cfg.TurnRealm, cfg.TurnStaticUser, cfg.TurnStaticPass)

	rawRelayGen := &turn.RelayAddressGeneratorPortRange{
		RelayAddress: relayIP,
		Address:      "0.0.0.0",
		MinPort:      uint16(cfg.TurnMinPort),
		MaxPort:      uint16(cfg.TurnMaxPort),
	}
	relayGen := &loggingRelayGenerator{gen: rawRelayGen}

	var packetConnConfigs []turn.PacketConnConfig
	var listenerConfigs []turn.ListenerConfig

	// Bind to 0.0.0.0 (all interfaces) so STUN/TURN accepts packets on any interface,
	// and never fails with "bind: cannot assign requested address" when Host is a public IP.
	bindHost := "0.0.0.0"
	if cfg.Host == "127.0.0.1" || cfg.Host == "localhost" {
		bindHost = "127.0.0.1"
	}

	// 1. UDP Listener for STUN (RFC 5389) and TURN (RFC 5766 / RFC 8656)
	udpAddr := fmt.Sprintf("%s:%d", bindHost, cfg.TurnPort)
	udpConn, err := net.ListenPacket("udp4", udpAddr)
	if err != nil {
		return nil, fmt.Errorf("failed to bind UDP STUN/TURN on %s: %w", udpAddr, err)
	}

	packetConnConfigs = append(packetConnConfigs, turn.PacketConnConfig{
		PacketConn:             &stunLoggingPacketConn{PacketConn: udpConn, label: fmt.Sprintf("UDP:%d", cfg.TurnPort)},
		RelayAddressGenerator: relayGen,
	})

	// 2. TCP Listener for TURN over TCP
	tcpAddr := fmt.Sprintf("%s:%d", bindHost, cfg.TurnPort)
	tcpListener, err := net.Listen("tcp4", tcpAddr)
	if err != nil {
		log.Printf("[Voice Bridge TURN] ⚠️ Warning: Failed to bind TCP on %s (UDP only): %v", tcpAddr, err)
	} else {
		listenerConfigs = append(listenerConfigs, turn.ListenerConfig{
			Listener:               tcpListener,
			RelayAddressGenerator: relayGen,
		})
	}

	// 3. Optional TLS Listener for TURNS (RFC 5766 TLS on port 5349)
	var tlsListener net.Listener
	hasTLS := false
	if cfg.TurnCertFile != "" && cfg.TurnKeyFile != "" {
		cert, err := tls.LoadX509KeyPair(cfg.TurnCertFile, cfg.TurnKeyFile)
		if err != nil {
			log.Printf("[Voice Bridge TURNS] ⚠️ Failed to load TLS certificates (%s, %s): %v", cfg.TurnCertFile, cfg.TurnKeyFile, err)
		} else {
			tlsConfig := &tls.Config{
				Certificates: []tls.Certificate{cert},
			}
			turnsAddr := fmt.Sprintf("%s:%d", bindHost, cfg.TurnsPort)
			tlsListener, err = tls.Listen("tcp4", turnsAddr, tlsConfig)
			if err != nil {
				log.Printf("[Voice Bridge TURNS] ⚠️ Failed to bind TURNS TLS listener on %s: %v", turnsAddr, err)
			} else {
				hasTLS = true
				listenerConfigs = append(listenerConfigs, turn.ListenerConfig{
					Listener:               tlsListener,
					RelayAddressGenerator: relayGen,
				})
				log.Printf("[Voice Bridge TURNS] 🔒 TURNS (TLS) active on turns://%s:%d", cfg.Host, cfg.TurnsPort)
			}
		}
	}

	pionLoggerFactory := logging.NewDefaultLoggerFactory()
	pionLoggerFactory.DefaultLogLevel = logging.LogLevelInfo

	server, err := turn.NewServer(turn.ServerConfig{
		Realm:             cfg.TurnRealm,
		AuthHandler:       authHandler,
		LoggerFactory:     pionLoggerFactory,
		PacketConnConfigs: packetConnConfigs,
		ListenerConfigs:   listenerConfigs,
	})
	if err != nil {
		_ = udpConn.Close()
		if tcpListener != nil {
			_ = tcpListener.Close()
		}
		if tlsListener != nil {
			_ = tlsListener.Close()
		}
		return nil, fmt.Errorf("failed to start TURN server: %w", err)
	}

	log.Printf("[Voice Bridge STUN/TURN] 📡 Listening on UDP/TCP :%d (Relay UDP: %d-%d, Realm: %s)",
		cfg.TurnPort, cfg.TurnMinPort, cfg.TurnMaxPort, cfg.TurnRealm)

	return &TurnServer{
		server:      server,
		udpConn:     udpConn,
		tcpListener: tcpListener,
		tlsListener: tlsListener,
		relayIP:     relayIP,
		port:        cfg.TurnPort,
		turnsPort:   cfg.TurnsPort,
		realm:       cfg.TurnRealm,
		minPort:     cfg.TurnMinPort,
		maxPort:     cfg.TurnMaxPort,
		hasTLS:      hasTLS,
	}, nil
}

// --- LOCAL PUSH CONNECTIVITY SERVER (iOS NEAppPushProvider & Android Local Client) ---

type LocalPushClient struct {
	DeviceUUID   string    `json:"device_uuid"`
	UserID       int64     `json:"user_id"`
	Platform     string    `json:"platform"`
	ConnectionID string    `json:"connection_id"`
	RemoteAddr   string    `json:"remote_addr"`
	ConnectedAt  time.Time `json:"connected_at"`
	LastSeenAt   time.Time `json:"last_seen_at"`
	conn         net.Conn
	writer       *bufio.Writer
	writeMu      sync.Mutex
}

type LocalPushHub struct {
	sync.RWMutex
	clients            map[string]*LocalPushClient // deviceUUID -> client
	ackWaiters         map[string]chan bool        // msgID -> ack channel
	secret             string
	defaultCallbackURL string
}

func NewLocalPushHub(secret string, defaultCallbackURL string) *LocalPushHub {
	return &LocalPushHub{
		clients:            make(map[string]*LocalPushClient),
		ackWaiters:         make(map[string]chan bool),
		secret:             secret,
		defaultCallbackURL: defaultCallbackURL,
	}
}

func verifyLocalPushTicket(ticket string, secret string) (string, int64, bool) {
	ticket = strings.TrimSpace(ticket)
	if ticket == "" {
		return "", 0, false
	}

	padded := strings.ReplaceAll(strings.ReplaceAll(ticket, "-", "+"), "_", "/")
	if mod := len(padded) % 4; mod > 0 {
		padded += strings.Repeat("=", 4-mod)
	}

	decoded, err := base64.StdEncoding.DecodeString(padded)
	if err != nil || len(decoded) == 0 {
		return "", 0, false
	}

	parts := strings.Split(string(decoded), "|")
	if len(parts) != 4 {
		return "", 0, false
	}

	deviceUUID := strings.TrimSpace(parts[0])
	userIDStr := strings.TrimSpace(parts[1])
	expiresAtStr := strings.TrimSpace(parts[2])
	providedSig := strings.TrimSpace(parts[3])

	if deviceUUID == "" {
		return "", 0, false
	}

	expiresAt, err := strconv.ParseInt(expiresAtStr, 10, 64)
	if err != nil || expiresAt < time.Now().Unix() {
		return "", 0, false
	}

	userID, _ := strconv.ParseInt(userIDStr, 10, 64)

	effectiveSecret := secret
	if effectiveSecret == "" {
		effectiveSecret = "ravyno_default_secret"
	}

	mac := hmac.New(sha256.New, []byte(effectiveSecret))
	mac.Write([]byte(fmt.Sprintf("%s|%s|%s", deviceUUID, userIDStr, expiresAtStr)))
	expectedSig := hex.EncodeToString(mac.Sum(nil))

	if subtle.ConstantTimeCompare([]byte(strings.ToLower(providedSig)), []byte(strings.ToLower(expectedSig))) != 1 {
		return "", 0, false
	}

	return deviceUUID, userID, true
}

func (lph *LocalPushHub) authenticateRequest(r *http.Request) (string, int64, string, bool) {
	platform := strings.ToLower(strings.TrimSpace(r.URL.Query().Get("platform")))
	if platform == "" {
		platform = strings.ToLower(strings.TrimSpace(r.Header.Get("X-Device-Platform")))
	}
	if platform == "" {
		platform = "mobile"
	}

	// 1. Verify signed Local Push Ticket (?ticket=... or X-Local-Push-Ticket)
	ticket := strings.TrimSpace(r.URL.Query().Get("ticket"))
	if ticket == "" {
		ticket = strings.TrimSpace(r.Header.Get("X-Local-Push-Ticket"))
	}
	if ticket != "" {
		if devUUID, uid, ok := verifyLocalPushTicket(ticket, lph.secret); ok {
			return devUUID, uid, platform, true
		}
	}

	// 2. Fallback: Master bridge secret + explicit device_uuid
	providedSecret := extractSecret(r)
	if validateSecret(providedSecret, lph.secret) {
		devUUID := strings.TrimSpace(r.URL.Query().Get("device_uuid"))
		if devUUID == "" {
			devUUID = strings.TrimSpace(r.Header.Get("X-Device-UUID"))
		}
		if devUUID != "" {
			uid, _ := strconv.ParseInt(strings.TrimSpace(r.URL.Query().Get("user_id")), 10, 64)
			return devUUID, uid, platform, true
		}
	}

	return "", 0, "", false
}

func notifyLaravelLocalPushWebhook(callbackURL, secret, event, deviceUUID, connID, clientIP string) {
	callbackURL = normalizeCallbackURL(callbackURL)
	if callbackURL == "" {
		return
	}
	go func() {
		payload := map[string]interface{}{
			"event":         event,
			"device_uuid":   deviceUUID,
			"connection_id": connID,
			"client_ip":     clientIP,
		}
		data, _ := json.Marshal(payload)
		req, err := http.NewRequest("POST", callbackURL, strings.NewReader(string(data)))
		if err != nil {
			return
		}
		req.Header.Set("Content-Type", "application/json")
		if secret != "" {
			req.Header.Set("X-Bridge-Secret", secret)
		}
		client := &http.Client{Timeout: 3 * time.Second}
		resp, err := client.Do(req)
		if err == nil && resp != nil {
			_ = resp.Body.Close()
		}
	}()
}

func (lph *LocalPushHub) HandleWS(w http.ResponseWriter, r *http.Request) {
	deviceUUID, userID, platform, ok := lph.authenticateRequest(r)
	if !ok {
		http.Error(w, "Unauthorized: invalid local push ticket or device credentials", http.StatusUnauthorized)
		return
	}

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
		log.Printf("[LOCAL-PUSH] Hijack error: %v", err)
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

	connID := generateID()
	client := &LocalPushClient{
		DeviceUUID:   deviceUUID,
		UserID:       userID,
		Platform:     platform,
		ConnectionID: connID,
		RemoteAddr:   r.RemoteAddr,
		ConnectedAt:  time.Now(),
		LastSeenAt:   time.Now(),
		conn:         conn,
		writer:       bufrw.Writer,
	}

	lph.Lock()
	if old, exists := lph.clients[deviceUUID]; exists && old.conn != nil {
		_ = old.conn.Close()
	}
	lph.clients[deviceUUID] = client
	lph.Unlock()

	log.Printf("[LOCAL-PUSH] 📱 Device connected: %s (user_id: %d | platform: %s | conn: %s | from: %s)",
		deviceUUID, userID, platform, connID, r.RemoteAddr)

	if lph.defaultCallbackURL != "" {
		notifyLaravelLocalPushWebhook(lph.defaultCallbackURL, lph.secret, "local_push_connected", deviceUUID, connID, r.RemoteAddr)
	}

	// Send initial ready handshake frame
	readyPayload, _ := json.Marshal(map[string]interface{}{
		"type":          "local_push_ready",
		"device_uuid":   deviceUUID,
		"connection_id": connID,
		"server_time":   time.Now().UnixMilli(),
	})
	client.writeMu.Lock()
	_ = writeWSFrame(client.writer, 0x01, readyPayload)
	client.writeMu.Unlock()

	defer func() {
		lph.Lock()
		current, exists := lph.clients[deviceUUID]
		if exists && current.ConnectionID == connID {
			delete(lph.clients, deviceUUID)
		}
		lph.Unlock()

		log.Printf("[LOCAL-PUSH] 🔌 Device disconnected: %s (conn: %s)", deviceUUID, connID)
		if lph.defaultCallbackURL != "" {
			notifyLaravelLocalPushWebhook(lph.defaultCallbackURL, lph.secret, "local_push_disconnected", deviceUUID, connID, r.RemoteAddr)
		}
	}()

	for {
		frame, err := readWSFrame(bufrw.Reader)
		if err != nil {
			break
		}

		lph.Lock()
		client.LastSeenAt = time.Now()
		lph.Unlock()

		if frame.Opcode == 0x01 { // Text JSON frame
			var msg struct {
				Type       string `json:"type"`
				MsgID      string `json:"msg_id"`
				CallUUID   string `json:"call_uuid"`
				ClientTime int64  `json:"client_time"`
			}
			if err := json.Unmarshal(frame.Payload, &msg); err == nil {
				switch msg.Type {
				case "ping", "heartbeat":
					pongData, _ := json.Marshal(map[string]interface{}{
						"type":        "pong",
						"client_time": msg.ClientTime,
						"server_time": time.Now().UnixMilli(),
					})
					client.writeMu.Lock()
					_ = writeWSFrame(client.writer, 0x01, pongData)
					client.writeMu.Unlock()

				case "ack":
					if msg.MsgID != "" {
						lph.RLock()
						ch, waiting := lph.ackWaiters[msg.MsgID]
						lph.RUnlock()
						if waiting {
							select {
							case ch <- true:
							default:
							}
						}
						log.Printf("[LOCAL-PUSH] ✅ Delivery ACK received from device %s (msg_id: %s | call_uuid: %s)",
							deviceUUID, msg.MsgID, msg.CallUUID)
					}
				}
			}
		} else if frame.Opcode == 0x08 { // Close frame
			break
		} else if frame.Opcode == 0x09 { // Ping frame
			client.writeMu.Lock()
			_ = writeWSFrame(client.writer, 0x0A, frame.Payload)
			client.writeMu.Unlock()
		}
	}
}

func (lph *LocalPushHub) Dispatch(deviceUUID string, msgID string, payload interface{}, requireAck bool, ackTimeoutMs int) (bool, bool, error) {
	lph.RLock()
	client, exists := lph.clients[deviceUUID]
	lph.RUnlock()

	if !exists || client == nil || client.conn == nil {
		return false, false, fmt.Errorf("local_socket_not_connected")
	}

	if msgID == "" {
		msgID = generateID()
	}

	var ackCh chan bool
	if requireAck {
		ackCh = make(chan bool, 1)
		lph.Lock()
		lph.ackWaiters[msgID] = ackCh
		lph.Unlock()
		defer func() {
			lph.Lock()
			delete(lph.ackWaiters, msgID)
			lph.Unlock()
		}()
	}

	envelope := map[string]interface{}{
		"type":        "push_event",
		"msg_id":      msgID,
		"device_uuid": deviceUUID,
		"server_time": time.Now().UnixMilli(),
		"payload":     payload,
	}
	data, err := json.Marshal(envelope)
	if err != nil {
		return false, false, err
	}

	client.writeMu.Lock()
	writeErr := writeWSFrame(client.writer, 0x01, data)
	client.writeMu.Unlock()

	if writeErr != nil {
		_ = client.conn.Close()
		lph.Lock()
		delete(lph.clients, deviceUUID)
		lph.Unlock()
		return false, false, writeErr
	}

	if !requireAck {
		return true, true, nil
	}

	if ackTimeoutMs <= 0 || ackTimeoutMs > 5000 {
		ackTimeoutMs = 1400
	}

	select {
	case <-ackCh:
		return true, true, nil
	case <-time.After(time.Duration(ackTimeoutMs) * time.Millisecond):
		return true, false, nil
	}
}

func (lph *LocalPushHub) DisconnectDevice(deviceUUID string) bool {
	lph.Lock()
	client, exists := lph.clients[deviceUUID]
	if exists {
		delete(lph.clients, deviceUUID)
	}
	lph.Unlock()

	if exists && client != nil && client.conn != nil {
		_ = client.conn.Close()
		return true
	}
	return false
}

func (lph *LocalPushHub) ListClients() []*LocalPushClient {
	lph.RLock()
	defer lph.RUnlock()
	list := make([]*LocalPushClient, 0, len(lph.clients))
	for _, c := range lph.clients {
		list = append(list, c)
	}
	return list
}

func (lph *LocalPushHub) Count() int {
	lph.RLock()
	defer lph.RUnlock()
	return len(lph.clients)
}

// --- REST API SERVER ---

func startAPIServer(cfg Config, store *SessionStore, localPushHub *LocalPushHub, turnSrv *TurnServer, sfuHub *SFUHub, redisMgr *RedisManager, udpPool *UDPPortPool, startTime time.Time) *http.Server {
	mux := http.NewServeMux()

	// CORS & Auth Middleware Helper
	cors := func(next http.HandlerFunc) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Access-Control-Allow-Origin", "*")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Bridge-Secret, X-Voice-Bridge-Secret")
			if r.Method == "OPTIONS" {
				w.WriteHeader(http.StatusOK)
				return
			}
			next(w, r)
		}
	}

	requireAuth := func(next http.HandlerFunc) http.HandlerFunc {
		return cors(func(w http.ResponseWriter, r *http.Request) {
			if cfg.Secret != "" {
				provided := extractSecret(r)
				if !validateSecret(provided, cfg.Secret) {
					w.Header().Set("Content-Type", "application/json")
					w.WriteHeader(http.StatusUnauthorized)
					_ = json.NewEncoder(w).Encode(map[string]interface{}{
						"success": false,
						"error":   "Unauthorized: missing or invalid bridge secret token",
					})
					return
				}
			}
			next(w, r)
		})
	}

	// 0. Asymmetric Handshake & Diagnostics endpoint (Two-way test & Auto-Configuration)
	mux.HandleFunc("/api/handshake", cors(handleHandshake(&cfg, redisMgr, udpPool, turnSrv, sfuHub)))

	// 1. Health check (public, but verifies secret if supplied)
	mux.HandleFunc("/health", cors(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		uptime := int(time.Since(startTime).Seconds())

		providedSecret := extractSecret(r)
		authValid := true
		if cfg.Secret != "" && providedSecret != "" {
			authValid = validateSecret(providedSecret, cfg.Secret)
		}

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
		} else {
			turnInfo["active"] = false
		}

		localPushCount := 0
		if localPushHub != nil {
			localPushCount = localPushHub.Count()
		}

		sfuInfo := map[string]interface{}{
			"enabled":        cfg.SFUEnabled,
			"active":         cfg.SFUEnabled && sfuHub != nil,
			"ws_path":        cfg.SFUWSPath,
			"udp_port_range": fmt.Sprintf("%d-%d", cfg.SFUMinPort, cfg.SFUMaxPort),
		}
		if sfuHub != nil {
			for k, v := range sfuHub.Stats() {
				sfuInfo[k] = v
			}
		}

		redisData := map[string]interface{}{"status": "not_configured", "connected": false}
		if redisMgr != nil {
			conn, lat, msg := redisMgr.PingStatus()
			redisData["connected"] = conn
			redisData["latency_ms"] = lat
			redisData["status"] = msg
		}

		udpPoolData := map[string]interface{}{}
		if udpPool != nil {
			udpPoolData = udpPool.Stats()
		}

		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"status":          "ok",
			"service":         "RavyNo Voice Bridge Daemon",
			"version":         "2.4.0",
			"uptime_seconds":  uptime,
			"active_sessions": store.TotalActive(),
			"bridge_host":     cfg.Host,
			"api_port":        cfg.APIPort,
			"ws_port":         cfg.WSPort,
			"callback_url":    cfg.CallbackURL,
			"auth_required":   cfg.Secret != "",
			"auth_valid":      authValid,
			"stun_turn":       turnInfo,
			"sfu":             sfuInfo,
			"redis":           redisData,
			"udp_pool":        udpPoolData,
			"local_push": map[string]interface{}{
				"enabled":           true,
				"ws_path":           "/ws/local-push",
				"connected_devices": localPushCount,
			},
			"timestamp": time.Now().Format(time.RFC3339),
		})
	}))

	// 2. Ping endpoint (public)
	mux.HandleFunc("/ping", cors(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"pong":                true,
			"server_time_ms":      time.Now().UnixMilli(),
			"latency_baseline_ms": 1,
			"audio_sampling_rate": 48000,
			"supported_codecs":    []string{"Opus/48000", "PCMA/8000", "PCMU/8000"},
		})
	}))

	// 3. Start Session (Protected by Secret)
	mux.HandleFunc("/api/sessions/start", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		var req struct {
			CallID      int64  `json:"call_id"`
			UserID      int64  `json:"user_id"`
			Source      string `json:"source"`
			Destination string `json:"destination"`
			CallbackURL string `json:"callback_url"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON body", http.StatusBadRequest)
			return
		}

		effectiveCallback := req.CallbackURL
		if effectiveCallback == "" {
			effectiveCallback = cfg.CallbackURL
		}

		session := store.Create(req.CallID, req.UserID, req.Source, req.Destination, effectiveCallback)
		log.Printf("[VOICE-BRIDGE] 📞 Provisioned new call session: %s -> %s | session_id: %s | user_id: %d", req.Source, req.Destination, session.ID, req.UserID)

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":     true,
			"session":     session,
			"ws_endpoint": fmt.Sprintf("ws://%s:%d/ws/signaling", cfg.Host, cfg.WSPort),
			"message":     "Voice bridge session successfully provisioned.",
		})
	}))

	// 4. End Session (Protected by Secret)
	mux.HandleFunc("/api/sessions/end", requireAuth(func(w http.ResponseWriter, r *http.Request) {
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
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusNotFound)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"success": false,
				"error":   "Session Not Found",
			})
			return
		}

		log.Printf("[VOICE-BRIDGE] ⏹️ Terminated call session: %s <-> %s | duration: %ds | session_id: %s", sess.Source, sess.Destination, sess.DurationSec, sess.ID)

		callbackURL := sess.CallbackURL
		if callbackURL == "" {
			callbackURL = cfg.CallbackURL
		}
		if callbackURL != "" {
			notifyLaravelWebhook(callbackURL, cfg.Secret, "session_ended", sess.ID, sess.DurationSec)
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": true,
			"session": sess,
			"message": "Voice bridge session closed.",
		})
	}))

	// 5. List Sessions (Protected by Secret)
	mux.HandleFunc("/api/sessions", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":         true,
			"active_sessions": store.ListActive(),
			"count":           store.TotalActive(),
		})
	}))

	// 6. Generate Ephemeral TURN REST Credentials & ICE Server List (Protected by Secret)
	mux.HandleFunc("/turn/credentials", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		userID := strings.TrimSpace(r.URL.Query().Get("user_id"))
		if userID == "" {
			userID = "guest"
		}

		durationSec := int64(6 * 3600) // 6 hours
		expiresAt := time.Now().Unix() + durationSec
		username := fmt.Sprintf("%d:%s", expiresAt, userID)

		mac := hmac.New(sha1.New, []byte(cfg.TurnSecret))
		mac.Write([]byte(username))
		credential := base64.StdEncoding.EncodeToString(mac.Sum(nil))

		hostName := cfg.TurnPublicIP
		if hostName == "" && turnSrv != nil && turnSrv.relayIP != nil {
			hostName = turnSrv.relayIP.String()
		}
		if hostName == "" {
			hostName = cfg.Host
		}

		stunURL := fmt.Sprintf("stun:%s:%d", hostName, cfg.TurnPort)
		turnUDP := fmt.Sprintf("turn:%s:%d?transport=udp", hostName, cfg.TurnPort)
		turnTCP := fmt.Sprintf("turn:%s:%d?transport=tcp", hostName, cfg.TurnPort)

		iceServers := []map[string]interface{}{
			{
				"urls": []string{stunURL},
			},
			{
				"urls":       []string{turnUDP, turnTCP},
				"username":   username,
				"credential": credential,
			},
		}

		if turnSrv != nil && turnSrv.hasTLS {
			turnsTLS := fmt.Sprintf("turns:%s:%d?transport=tcp", hostName, cfg.TurnsPort)
			iceServers = append(iceServers, map[string]interface{}{
				"urls":       []string{turnsTLS},
				"username":   username,
				"credential": credential,
			})
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":     true,
			"realm":       cfg.TurnRealm,
			"username":    username,
			"credential":  credential,
			"expires_at":  expiresAt,
			"ttl":         durationSec,
			"relay_ip":    hostName,
			"ice_servers": iceServers,
		})
	}))

	// 7. Dispatch Incoming Call / Control Event to Connected Local Push Device (Protected by Secret)
	mux.HandleFunc("/api/local-push/dispatch", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		var req struct {
			DeviceUUID   string      `json:"device_uuid"`
			UserID       int64       `json:"user_id"`
			Platform     string      `json:"platform"`
			MsgID        string      `json:"msg_id"`
			RequireAck   bool        `json:"require_ack"`
			AckTimeoutMs int         `json:"ack_timeout_ms"`
			Payload      interface{} `json:"payload"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON body", http.StatusBadRequest)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		if localPushHub == nil || req.DeviceUUID == "" {
			w.WriteHeader(http.StatusBadRequest)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"success":      false,
				"delivered":    false,
				"acknowledged": false,
				"error":        "missing_device_uuid",
			})
			return
		}

		delivered, acked, err := localPushHub.Dispatch(req.DeviceUUID, req.MsgID, req.Payload, req.RequireAck, req.AckTimeoutMs)
		errMsg := ""
		if err != nil {
			errMsg = err.Error()
		}

		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":      delivered,
			"delivered":    delivered,
			"acknowledged": acked,
			"device_uuid":  req.DeviceUUID,
			"msg_id":       req.MsgID,
			"error":        errMsg,
		})
	}))

	// 8. List Connected Local Push Devices (Protected by Secret)
	mux.HandleFunc("/api/local-push/devices", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		var devices []*LocalPushClient
		if localPushHub != nil {
			devices = localPushHub.ListClients()
		}
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success": true,
			"count":   len(devices),
			"devices": devices,
		})
	}))

	// 9. Force Disconnect Revoked Local Push Device (Protected by Secret)
	mux.HandleFunc("/api/local-push/disconnect", requireAuth(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}
		var req struct {
			DeviceUUID string `json:"device_uuid"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		disconnected := false
		if localPushHub != nil && req.DeviceUUID != "" {
			disconnected = localPushHub.DisconnectDevice(req.DeviceUUID)
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"success":      true,
			"disconnected": disconnected,
			"device_uuid":  req.DeviceUUID,
		})
	}))

	if localPushHub != nil {
		mux.HandleFunc("/ws/local-push", localPushHub.HandleWS)
	}

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

func startWSServer(cfg Config, hub *SignalingHub, localPushHub *LocalPushHub, sfuHub *SFUHub) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("/ws/signaling", hub.HandleWS)
	if localPushHub != nil {
		mux.HandleFunc("/ws/local-push", localPushHub.HandleWS)
	}
	if sfuHub != nil && cfg.SFUEnabled {
		mux.HandleFunc(cfg.SFUWSPath, sfuHub.HandleWS)
	}

	server := &http.Server{
		Addr:    fmt.Sprintf("%s:%d", cfg.Host, cfg.WSPort),
		Handler: mux,
	}

	go func() {
		log.Printf("[Voice Bridge WebSocket] Listening on ws://%s:%d/ws/signaling, /ws/local-push & %s", cfg.Host, cfg.WSPort, cfg.SFUWSPath)
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
	hub := NewSignalingHub(store, cfg.Secret, cfg.CallbackURL)
	localPushHub := NewLocalPushHub(cfg.Secret, cfg.CallbackURL)

	// 1. Initialize Redis Manager (with high-speed local memory fallback)
	redisMgr := NewRedisManager(RedisConfig{
		Host:     cfg.RedisHost,
		Port:     cfg.RedisPort,
		Password: cfg.RedisPassword,
		DB:       cfg.RedisDB,
	})
	defer redisMgr.Close()

	// 2. Initialize 300 UDP Ports Media Pool with 5-Tuple Multiplexing
	udpPool := NewUDPPortPool(UDPPoolConfig{
		MinPort: cfg.UDPPoolMinPort,
		MaxPort: cfg.UDPPoolMaxPort,
		Host:    cfg.Host,
	}, redisMgr)
	defer udpPool.Close()

	var sfuHub *SFUHub
	if cfg.SFUEnabled {
		sfuHub = NewSFUHub(cfg.Secret, cfg.SFUMinPort, cfg.SFUMaxPort, udpPool, redisMgr)
	}

	turnSrv, err := startTurnServer(cfg)
	if err != nil {
		log.Printf("[Voice Bridge TURN] ⚠️ Warning: Failed to start embedded TURN server: %v", err)
	}

	fmt.Println("==================================================================")
	fmt.Println("🚀 RavyNo Voice Bridge, Local Push & STUN/TURN Media Relay 2.4")
	fmt.Println("------------------------------------------------------------------")
	fmt.Printf("📡 REST API Engine:       http://%s:%d\n", cfg.Host, cfg.APIPort)
	fmt.Printf("⚡ WebSocket Signaling:   ws://%s:%d/ws/signaling\n", cfg.Host, cfg.WSPort)
	fmt.Printf("📲 Local Push Server:     ws://%s:%d/ws/local-push\n", cfg.Host, cfg.WSPort)
	if cfg.SFUEnabled {
		fmt.Printf("🎥 SFU Conference Hub:    ws://%s:%d%s (UDP Ports: %d-%d)\n", cfg.Host, cfg.WSPort, cfg.SFUWSPath, cfg.SFUMinPort, cfg.SFUMaxPort)
	}
	if udpPool != nil {
		fmt.Printf("🏊 UDP Media Port Pool:   %d ports (%d-%d, Capacity: 20,000 rooms)\n", udpPool.totalPorts, udpPool.minPort, udpPool.maxPort)
	}
	if redisMgr != nil {
		if redisMgr.IsAvailable() {
			fmt.Printf("📦 Redis Distributed:     redis://%s:%d (Connected)\n", cfg.RedisHost, cfg.RedisPort)
		} else {
			fmt.Printf("📦 State Storage Mode:    Local High-Speed In-Memory (Redis offline or uninstalled)\n")
		}
	}
	if cfg.TurnEnabled {
		fmt.Printf("🔄 STUN Server:           stun:%s:%d (UDP/TCP)\n", cfg.Host, cfg.TurnPort)
		fmt.Printf("🛡️  TURN Relay Server:     turn:%s:%d (UDP/TCP)\n", cfg.Host, cfg.TurnPort)
		if turnSrv != nil && turnSrv.hasTLS {
			fmt.Printf("🔒 TURNS TLS Relay:       turns:%s:%d (TLS)\n", cfg.Host, cfg.TurnsPort)
		}
		if turnSrv != nil && turnSrv.relayIP != nil {
			fmt.Printf("🌍 Relay Bound IP:        %s (Ports %d-%d)\n", turnSrv.relayIP.String(), cfg.TurnMinPort, cfg.TurnMaxPort)
		}
	}
	if cfg.CallbackURL != "" {
		fmt.Printf("🌐 Default Callback:      %s\n", cfg.CallbackURL)
	}
	fmt.Println("------------------------------------------------------------------")
	fmt.Printf("🔑 BRIDGE SECRET TOKEN:   %s\n", cfg.Secret)
	fmt.Printf("💾 Secret File:            %s\n", cfg.SecretFile)
	fmt.Println("👉 Set this secret in Admin: Settings > Voice Bridge > Secret")
	fmt.Println("==================================================================")

	apiServer := startAPIServer(cfg, store, localPushHub, turnSrv, sfuHub, redisMgr, udpPool, startTime)
	wsServer := startWSServer(cfg, hub, localPushHub, sfuHub)

	// Graceful shutdown handling
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
	<-stop

	log.Println("[Voice Bridge] Shutting down servers gracefully...")
	if sfuHub != nil {
		sfuHub.Close()
	}
	if turnSrv != nil {
		_ = turnSrv.Close()
	}
	_ = apiServer.Close()
	_ = wsServer.Close()
	log.Println("[Voice Bridge] Stopped cleanly.")
}
