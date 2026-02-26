package websocket

import (
	"encoding/json"
	"log/slog"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/voclinx/scanarr-watcher/internal/models"
)

// Client is a WebSocket client with automatic reconnection.
type Client struct {
	url            string
	authToken      string
	reconnectDelay time.Duration
	pingInterval   time.Duration

	conn      *websocket.Conn
	mu        sync.Mutex
	done      chan struct{}
	closeOnce sync.Once
	msgChan   chan models.Message

	// OnCommand is called when a command is received from the API.
	OnCommand func(msg models.Message)
}

// NewClient creates a new WebSocket client.
func NewClient(url, authToken string, reconnectDelay, pingInterval time.Duration) *Client {
	return &Client{
		url:            url,
		authToken:      authToken,
		reconnectDelay: reconnectDelay,
		pingInterval:   pingInterval,
		done:           make(chan struct{}),
		msgChan:        make(chan models.Message, 100),
	}
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
	delay := c.reconnectDelay
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

		slog.Info("WebSocket connected", "url", c.url)
		delay = c.reconnectDelay // reset backoff

		go c.readLoop()
		go c.writeLoop()
		go c.pingLoop()
		return
	}
}

// Send queues a message to be sent over WebSocket.
func (c *Client) Send(msg models.Message) {
	select {
	case c.msgChan <- msg:
	default:
		slog.Warn("Message channel full, dropping message", "type", msg.Type)
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

// Close cleanly shuts down the client. It is safe to call multiple times.
func (c *Client) Close() {
	c.closeOnce.Do(func() {
		close(c.done)
		c.mu.Lock()
		defer c.mu.Unlock()
		if c.conn != nil {
			_ = c.conn.WriteMessage(
				websocket.CloseMessage,
				websocket.FormatCloseMessage(websocket.CloseNormalClosure, ""),
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
	conn, _, err := websocket.DefaultDialer.Dial(c.url, nil)
	if err != nil {
		return err
	}

	c.mu.Lock()
	c.conn = conn
	c.mu.Unlock()

	// Send auth message immediately
	authMsg := models.AuthMessage{
		Type: "auth",
		Data: models.AuthTokenData{Token: c.authToken},
	}
	return c.writeJSON(authMsg)
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

		if c.OnCommand != nil {
			c.OnCommand(msg)
		}
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
	ticker := time.NewTicker(c.pingInterval)
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

			if err := conn.WriteControl(websocket.PingMessage, []byte{}, time.Now().Add(10*time.Second)); err != nil {
				slog.Warn("Ping failed", "error", err)
				c.reconnect()
				return
			}
		}
	}
}

func (c *Client) reconnect() {
	c.mu.Lock()
	if c.conn != nil {
		_ = c.conn.Close()
		c.conn = nil
	}
	c.mu.Unlock()

	slog.Info("Reconnecting to WebSocket...")
	go c.ConnectWithRetry()
}
