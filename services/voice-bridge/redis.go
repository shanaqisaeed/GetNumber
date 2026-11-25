package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"sort"
	"sync"
	"sync/atomic"
	"time"

	"github.com/redis/go-redis/v9"
)

// RedisConfig holds connection settings for the Redis service.
type RedisConfig struct {
	Host     string
	Port     int
	Password string
	DB       int
}

// RedisManager manages the connection to Redis with automatic reconnects
// and transparent local in-memory fallback if Redis is unavailable or uninstalled.
type RedisManager struct {
	cfg           RedisConfig
	client        *redis.Client
	isAvailable   atomic.Bool
	ctx           context.Context
	cancel        context.CancelFunc
	mu            sync.RWMutex
	memoryStorage map[string]interface{}
}

// NewRedisManager initializes the Redis client or prepares the fallback memory storage.
func NewRedisManager(cfg RedisConfig) *RedisManager {
	if cfg.Host == "" {
		cfg.Host = "127.0.0.1"
	}
	if cfg.Port == 0 {
		cfg.Port = 6379
	}

	ctx, cancel := context.WithCancel(context.Background())
	mgr := &RedisManager{
		cfg:           cfg,
		ctx:           ctx,
		cancel:        cancel,
		memoryStorage: make(map[string]interface{}),
	}

	addr := fmt.Sprintf("%s:%d", cfg.Host, cfg.Port)
	rdb := redis.NewClient(&redis.Options{
		Addr:         addr,
		Password:     cfg.Password,
		DB:           cfg.DB,
		DialTimeout:  2 * time.Second,
		ReadTimeout:  2 * time.Second,
		WriteTimeout: 2 * time.Second,
		PoolSize:     20,
	})
	mgr.client = rdb

	// Initial probe with short timeout
	probeCtx, probeCancel := context.WithTimeout(ctx, 1*time.Second)
	defer probeCancel()

	if err := rdb.Ping(probeCtx).Err(); err != nil {
		mgr.isAvailable.Store(false)
		log.Printf("[Redis] ⚠️ Connection to %s not available (%v). Operating in local high-speed in-memory mode.", addr, err)
	} else {
		mgr.isAvailable.Store(true)
		log.Printf("[Redis] 🚀 Successfully connected to Redis instance at %s", addr)
	}

	// Periodic health checker in background to auto-reconnect if Redis comes online later
	go mgr.monitorConnection()

	return mgr
}

func (m *RedisManager) monitorConnection() {
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-m.ctx.Done():
			return
		case <-ticker.C:
			checkCtx, checkCancel := context.WithTimeout(m.ctx, 2*time.Second)
			err := m.client.Ping(checkCtx).Err()
			checkCancel()

			wasAvailable := m.isAvailable.Load()
			if err != nil {
				if wasAvailable {
					m.isAvailable.Store(false)
					log.Printf("[Redis] ⚠️ Lost connection to Redis: %v. Switched to local in-memory fallback.", err)
				}
			} else {
				if !wasAvailable {
					m.isAvailable.Store(true)
					log.Printf("[Redis] 🔄 Reconnected to Redis at %s:%d. Resumed distributed state syncing.", m.cfg.Host, m.cfg.Port)
				}
			}
		}
	}
}

// IsAvailable returns true if active Redis connection is established.
func (m *RedisManager) IsAvailable() bool {
	return m.isAvailable.Load()
}

// PingStatus returns diagnostic ping results for the asymmetric handshake feedback.
func (m *RedisManager) PingStatus() (bool, int64, string) {
	if !m.isAvailable.Load() {
		return false, 0, "operating in high-speed local memory fallback (Redis offline or not installed)"
	}

	start := time.Now()
	pingCtx, cancel := context.WithTimeout(context.Background(), 1*time.Second)
	defer cancel()

	err := m.client.Ping(pingCtx).Err()
	latencyMs := time.Since(start).Milliseconds()
	if latencyMs == 0 {
		latencyMs = 1
	}

	if err != nil {
		return false, latencyMs, err.Error()
	}
	return true, latencyMs, "connected"
}

// --- WHITEBOARD STROKE STORAGE ---

// SaveWhiteboardStroke appends a new drawing stroke to the room's history.
func (m *RedisManager) SaveWhiteboardStroke(roomUUID string, stroke json.RawMessage) error {
	key := fmt.Sprintf("voice_bridge:whiteboard:%s", roomUUID)
	if m.isAvailable.Load() {
		pipeCtx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()

		pipe := m.client.Pipeline()
		pipe.RPush(pipeCtx, key, string(stroke))
		pipe.LTrim(pipeCtx, key, -1000, -1) // Retain last 1,000 strokes max to avoid unbounded memory
		pipe.Expire(pipeCtx, key, 24*time.Hour)
		_, err := pipe.Exec(pipeCtx)
		if err == nil {
			return nil
		}
		log.Printf("[Redis] Whiteboard save failed on Redis: %v, falling back to memory", err)
	}

	// In-memory fallback
	m.mu.Lock()
	defer m.mu.Unlock()
	raw, ok := m.memoryStorage[key]
	var list []json.RawMessage
	if ok {
		list = raw.([]json.RawMessage)
	}
	list = append(list, stroke)
	if len(list) > 1000 {
		list = list[len(list)-1000:]
	}
	m.memoryStorage[key] = list
	return nil
}

// GetWhiteboardHistory retrieves all cached whiteboard strokes for room participants.
func (m *RedisManager) GetWhiteboardHistory(roomUUID string) ([]json.RawMessage, error) {
	key := fmt.Sprintf("voice_bridge:whiteboard:%s", roomUUID)
	if m.isAvailable.Load() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()

		items, err := m.client.LRange(ctx, key, 0, -1).Result()
		if err == nil && len(items) > 0 {
			res := make([]json.RawMessage, 0, len(items))
			for _, item := range items {
				res = append(res, json.RawMessage(item))
			}
			return res, nil
		}
	}

	// In-memory fallback
	m.mu.RLock()
	defer m.mu.RUnlock()
	raw, ok := m.memoryStorage[key]
	if !ok {
		return []json.RawMessage{}, nil
	}
	list := raw.([]json.RawMessage)
	res := make([]json.RawMessage, len(list))
	copy(res, list)
	return res, nil
}

// ClearWhiteboard clears the drawing history for a given room.
func (m *RedisManager) ClearWhiteboard(roomUUID string) error {
	key := fmt.Sprintf("voice_bridge:whiteboard:%s", roomUUID)
	if m.isAvailable.Load() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = m.client.Del(ctx, key).Err()
	}

	m.mu.Lock()
	delete(m.memoryStorage, key)
	m.mu.Unlock()
	return nil
}

// --- UDP PORT LOAD MANAGEMENT ---

// RecordPortAllocation registers or increments the load on a UDP port for a specific room.
func (m *RedisManager) RecordPortAllocation(port int, roomUUID string) {
	portKey := fmt.Sprintf("%d", port)
	if m.isAvailable.Load() {
		ctx, cancel := context.WithTimeout(context.Background(), 1*time.Second)
		defer cancel()

		pipe := m.client.Pipeline()
		pipe.HIncrBy(ctx, "voice_bridge:udp_port_loads", portKey, 1)
		pipe.SAdd(ctx, fmt.Sprintf("voice_bridge:udp_port_rooms:%d", port), roomUUID)
		pipe.Expire(ctx, "voice_bridge:udp_port_loads", 24*time.Hour)
		_, _ = pipe.Exec(ctx)
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	memKey := "udp_load_" + portKey
	if val, ok := m.memoryStorage[memKey].(int); ok {
		m.memoryStorage[memKey] = val + 1
	} else {
		m.memoryStorage[memKey] = 1
	}
}

// ReleasePortAllocation decrements the load on a UDP port when a room closes.
func (m *RedisManager) ReleasePortAllocation(port int, roomUUID string) {
	portKey := fmt.Sprintf("%d", port)
	if m.isAvailable.Load() {
		ctx, cancel := context.WithTimeout(context.Background(), 1*time.Second)
		defer cancel()

		pipe := m.client.Pipeline()
		pipe.HIncrBy(ctx, "voice_bridge:udp_port_loads", portKey, -1)
		pipe.SRem(ctx, fmt.Sprintf("voice_bridge:udp_port_rooms:%d", port), roomUUID)
		_, _ = pipe.Exec(ctx)
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	memKey := "udp_load_" + portKey
	if val, ok := m.memoryStorage[memKey].(int); ok && val > 0 {
		m.memoryStorage[memKey] = val - 1
	}
}

// GetPortLoads returns a map of port -> current active rooms count.
func (m *RedisManager) GetPortLoads(ports []int) map[int]int {
	loads := make(map[int]int, len(ports))
	for _, p := range ports {
		loads[p] = 0
	}

	if m.isAvailable.Load() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()

		redisLoads, err := m.client.HGetAll(ctx, "voice_bridge:udp_port_loads").Result()
		if err == nil {
			for pStr, countStr := range redisLoads {
				var p, count int
				_, _ = fmt.Sscanf(pStr, "%d", &p)
				_, _ = fmt.Sscanf(countStr, "%d", &count)
				if count < 0 {
					count = 0
				}
				loads[p] = count
			}
			return loads
		}
	}

	// Memory fallback
	m.mu.RLock()
	defer m.mu.RUnlock()
	for _, p := range ports {
		memKey := fmt.Sprintf("udp_load_%d", p)
		if val, ok := m.memoryStorage[memKey].(int); ok {
			loads[p] = val
		}
	}
	return loads
}

// SelectLeastLoadedPort selects the UDP port with the lowest active session load.
func (m *RedisManager) SelectLeastLoadedPort(ports []int) int {
	if len(ports) == 0 {
		return 0
	}
	loads := m.GetPortLoads(ports)

	type portLoadPair struct {
		port int
		load int
	}
	pairs := make([]portLoadPair, 0, len(ports))
	for _, p := range ports {
		pairs = append(pairs, portLoadPair{port: p, load: loads[p]})
	}

	sort.Slice(pairs, func(i, j int) bool {
		return pairs[i].load < pairs[j].load
	})

	return pairs[0].port
}

// Close gracefully closes the Redis connection and cancels background monitoring.
func (m *RedisManager) Close() {
	m.cancel()
	if m.client != nil {
		_ = m.client.Close()
	}
}
