package websocket

import (
	"encoding/json"
	"log/slog"
	"os"
	"runtime"
	"sync"
	"sync/atomic"
	"time"

	gorilla_ws "github.com/gorilla/websocket"
	"github.com/voclinx/scanarr-watcher/internal/models"
)

// Client is a WebSocket client with automatic reconnection and the new hello/auth protocol.
type Client struct {
	url       string
	watcherID string

	// token is the auth token received from the API after approval.
	// It is stored atomically so it can be updated from any goroutine.
	token atomic.Pointer[string]

	// configHash is the hash of the last received config.
	configHash atomic.Pointer[string]

	reconnectDelay atomic.Int64 // nanoseconds
	pingInterval   atomic.Int64 // nanoseconds

	conn      *gorilla_ws.Conn
	mu        sync.Mutex
	done      chan struct{}
	closeOnce sync.Once
	msgChan   chan models.Message

	// droppedMessages is set to true when the buffer overflows.
	droppedMessages atomic.Bool

	// wasConnected tracks whether we had a successful connection before.
	wasConnected atomic.Bool

	// reconnecting prevents concurrent reconnection attempts.
	reconnecting atomic.Bool

	// OnCommand is called when a command is received from the API.
	OnCommand func(msg models.Message)

	// OnReconnect is called after a successful reconnection if messages were dropped.
	OnReconnect func()

	// OnConfig is called when the API sends a new config (watcher.config message).
	OnConfig func(config models.WatcherConfigData)
}

// NewClient creates a new WebSocket client with the new protocol.
func NewClient(url, watcherID string) *Client {
	c := &Client{
		url:       url,
		watcherID: watcherID,
		done:      make(chan struct{}),
		msgChan:   make(chan models.Message, 10000),
	}

	// Set sensible defaults for timing
	c.reconnectDelay.Store(int64(5 * time.Second))
	c.pingInterval.Store(int64(30 * time.Second))

	return c
}

// SetToken sets (or updates) the auth token. Call before ConnectWithRetry if you have a cached token.
func (c *Client) SetToken(token string) {
	c.token.Store(&token)
}

// GetToken returns the current auth token (may be empty if not yet authenticated).
func (c *Client) GetToken() string {
	if p := c.token.Load(); p != nil {
		return *p
	}
	return ""
}

// GetConfigHash returns the last known config hash.
func (c *Client) GetConfigHash() string {
	if p := c.configHash.Load(); p != nil {
		return *p
	}
	return ""
}

// SetReconnectDelay sets the base reconnect delay.
func (c *Client) SetReconnectDelay(d time.Duration) {
	c.reconnectDelay.Store(int64(d))
}

// SetPingInterval sets the ping interval.
func (c *Client) SetPingInterval(d time.Duration) {
	c.pingInterval.Store(int64(d))
}

// Connect establishes the WebSocket connection and starts read/write loops.
func (c *Client) Connect() error {
	if err := c.dial(); err != nil {
		return err
	}
	go c.readLoop()
	go c.writeLoop()
	go c.pingLoop()
	return nil
}

// ConnectWithRetry keeps trying to connect with exponential backoff.
func (c *Client) ConnectWithRetry() {
	baseDelay := time.Duration(c.reconnectDelay.Load())
	delay := baseDelay
	maxDelay := 60 * time.Second

	for {
		select {
		case <-c.done:
			return
		default:
		}

		if err := c.dial(); err != nil {
			slog.Warn("WebSocket connection failed, retrying",
				"error", err,
				"delay", delay,
			)
			time.Sleep(delay)
			delay = min(delay*2, maxDelay)
			continue
		}

		isReconnect := c.wasConnected.Load()
		c.wasConnected.Store(true)

		slog.Info("WebSocket connected", "url", c.url, "reconnect", isReconnect)
		delay = baseDelay // reset backoff

		go c.readLoop()
		go c.writeLoop()
		go c.pingLoop()

		// If this is a reconnection and events were dropped, trigger a resync scan
		if isReconnect && c.droppedMessages.Swap(false) {
			slog.Warn("Events were dropped during disconnection, triggering resync scan")
			if c.OnReconnect != nil {
				go c.OnReconnect()
			}
		}

		return
	}
}

// Send queues a message to be sent over WebSocket.
func (c *Client) Send(msg models.Message) {
	select {
	case c.msgChan <- msg:
	default:
		if !c.droppedMessages.Load() {
			c.droppedMessages.Store(true)
			slog.Warn("Message buffer full, events are being dropped — a resync scan will be triggered on reconnection", "type", msg.Type)
		}
	}
}

// SendEvent is a helper to send an event with the current timestamp.
func (c *Client) SendEvent(eventType string, data interface{}) {
	c.Send(models.Message{
		Type:      eventType,
		Timestamp: time.Now().UTC(),
		Data:      data,
	})
}

// ForwardLog sends a log entry to the API as a watcher.log message.
// Implements the logger.LogForwarder interface.
func (c *Client) ForwardLog(level, message string, context map[string]interface{}) {
	c.SendEvent("watcher.log", models.WatcherLogData{
		Level:     level,
		Message:   message,
		Context:   context,
		Timestamp: time.Now().UTC().Format(time.RFC3339),
	})
}

// Close cleanly shuts down the client. It is safe to call multiple times.
func (c *Client) Close() {
	c.closeOnce.Do(func() {
		close(c.done)
		c.mu.Lock()
		defer c.mu.Unlock()
		if c.conn != nil {
			_ = c.conn.WriteMessage(
				gorilla_ws.CloseMessage,
				gorilla_ws.FormatCloseMessage(gorilla_ws.CloseNormalClosure, ""),
			)
			_ = c.conn.Close()
		}
	})
}

// IsConnected returns whether the client has an active connection.
func (c *Client) IsConnected() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.conn != nil
}

func (c *Client) dial() error {
	conn, _, err := gorilla_ws.DefaultDialer.Dial(c.url, nil)
	if err != nil {
		return err
	}

	c.mu.Lock()
	c.conn = conn
	c.mu.Unlock()

	// Send hello or auth depending on whether we have a token
	token := c.GetToken()
	if token == "" {
		// No token yet — introduce ourselves, wait for approval
		return c.writeJSON(models.Message{
			Type:      "watcher.hello",
			Timestamp: time.Now().UTC(),
			Data: models.WatcherHelloData{
				WatcherID: c.watcherID,
				Hostname:  hostname(),
				Version:   version(),
			},
		})
	}

	// We have a token — send hello first, then auth will follow after server's challenge
	if err := c.writeJSON(models.Message{
		Type:      "watcher.hello",
		Timestamp: time.Now().UTC(),
		Data: models.WatcherHelloData{
			WatcherID: c.watcherID,
			Hostname:  hostname(),
			Version:   version(),
		},
	}); err != nil {
		return err
	}

	return nil
}

func (c *Client) writeJSON(v interface{}) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn == nil {
		return nil
	}
	return c.conn.WriteJSON(v)
}

func (c *Client) readLoop() {
	for {
		select {
		case <-c.done:
			return
		default:
		}

		c.mu.Lock()
		conn := c.conn
		c.mu.Unlock()

		if conn == nil {
			return
		}

		_, rawMsg, err := conn.ReadMessage()
		if err != nil {
			slog.Warn("WebSocket read error", "error", err)
			c.reconnect()
			return
		}

		var msg models.Message
		if err := json.Unmarshal(rawMsg, &msg); err != nil {
			slog.Warn("Failed to parse WebSocket message", "error", err, "raw", string(rawMsg))
			continue
		}

		// Handle watcher lifecycle messages before forwarding to OnCommand
		switch msg.Type {
		case "watcher.auth_required":
			c.handleAuthRequired()
		case "watcher.config":
			c.handleConfigMessage(rawMsg)
		case "watcher.pending":
			slog.Info("Watcher is pending approval by an admin", "watcher_id", c.watcherID)
		case "watcher.rejected":
			c.handleRejected(rawMsg)
		default:
			if c.OnCommand != nil {
				c.OnCommand(msg)
			}
		}
	}
}

// handleAuthRequired — server is asking us to authenticate.
func (c *Client) handleAuthRequired() {
	token := c.GetToken()
	if token == "" {
		slog.Warn("Received watcher.auth_required but no token available — waiting for approval")
		return
	}

	slog.Info("Sending watcher.auth")
	if err := c.writeJSON(models.Message{
		Type:      "watcher.auth",
		Timestamp: time.Now().UTC(),
		Data:      models.WatcherAuthData{Token: token},
	}); err != nil {
		slog.Warn("Failed to send watcher.auth", "error", err)
	}
}

// handleConfigMessage — server sent us a config update.
func (c *Client) handleConfigMessage(rawMsg []byte) {
	// Decode the config payload
	var envelope struct {
		Data models.WatcherConfigData `json:"data"`
	}
	if err := json.Unmarshal(rawMsg, &envelope); err != nil {
		slog.Warn("Failed to parse watcher.config", "error", err)
		return
	}

	cfg := envelope.Data

	// Track whether this is the first-time approval (we had no token before)
	wasUnauthenticated := c.GetToken() == ""

	// If the message includes a new auth token (on first approval), store it
	if cfg.AuthToken != "" {
		c.SetToken(cfg.AuthToken)
		slog.Info("Auth token received and stored")
	}

	// Update config hash
	if cfg.ConfigHash != "" {
		c.configHash.Store(&cfg.ConfigHash)
	}

	slog.Info("Received config from API",
		"config_hash", cfg.ConfigHash,
		"watch_paths", cfg.WatchPaths,
		"log_level", cfg.LogLevel,
	)

	if c.OnConfig != nil {
		c.OnConfig(cfg)
	}

	// First-time approval: reconnect so we go through the full watcher.auth flow
	// and the server marks us as "connected" in the database.
	if wasUnauthenticated && cfg.AuthToken != "" {
		slog.Info("First-time approval received — reconnecting to authenticate")
		go c.reconnect()
	}
}

// handleRejected — server rejected our token.
func (c *Client) handleRejected(rawMsg []byte) {
	slog.Warn("Watcher rejected by server — clearing token and state")

	// Clear token in memory
	empty := ""
	c.token.Store(&empty)

	// Notify main to clear the state file
	// We signal this by calling OnConfig with a zero-value config that has an empty token
	// But actually the cleanest way is to use a dedicated callback...
	// For now, just log — the state.Clear() will be handled by main via OnConfig with empty token
	if c.OnConfig != nil {
		c.OnConfig(models.WatcherConfigData{AuthToken: "__rejected__"})
	}
}

func (c *Client) writeLoop() {
	for {
		select {
		case <-c.done:
			return
		case msg := <-c.msgChan:
			if err := c.writeJSON(msg); err != nil {
				slog.Warn("WebSocket write error", "error", err, "type", msg.Type)
				c.reconnect()
				return
			}
		}
	}
}

func (c *Client) pingLoop() {
	interval := time.Duration(c.pingInterval.Load())
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-c.done:
			return
		case <-ticker.C:
			c.mu.Lock()
			conn := c.conn
			c.mu.Unlock()

			if conn == nil {
				return
			}

			if err := conn.WriteControl(gorilla_ws.PingMessage, []byte{}, time.Now().Add(10*time.Second)); err != nil {
				slog.Warn("Ping failed", "error", err)
				c.reconnect()
				return
			}
		}
	}
}

func (c *Client) reconnect() {
	if !c.reconnecting.CompareAndSwap(false, true) {
		// Another goroutine is already reconnecting — skip
		return
	}

	c.mu.Lock()
	if c.conn != nil {
		_ = c.conn.Close()
		c.conn = nil
	}
	c.mu.Unlock()

	slog.Info("Reconnecting to WebSocket...")
	go func() {
		c.ConnectWithRetry()
		c.reconnecting.Store(false)
	}()
}

// hostname returns the system hostname (best-effort).
func hostname() string {
	h, err := os.Hostname()
	if err != nil {
		return "unknown"
	}
	return h
}

// version returns the watcher version string.
func version() string {
	return "1.5.0-" + runtime.Version()
}
