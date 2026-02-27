package websocket

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	gorilla_ws "github.com/gorilla/websocket"
	"github.com/voclinx/scanarr-watcher/internal/models"
)

// newTestServer creates a test WebSocket server with a custom handler.
func newTestServer(t *testing.T, handler func(*gorilla_ws.Conn)) *httptest.Server {
	t.Helper()
	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			t.Logf("upgrade error: %v", err)
			return
		}
		handler(conn)
	}))
}

// httpToWs converts an http:// URL to ws:// for the WebSocket client.
func httpToWs(url string) string {
	return "ws" + strings.TrimPrefix(url, "http")
}

// readMessage reads a decoded models.Message from a connection.
func readMessage(conn *gorilla_ws.Conn) (models.Message, error) {
	_, raw, err := conn.ReadMessage()
	if err != nil {
		return models.Message{}, err
	}
	var msg models.Message
	return msg, json.Unmarshal(raw, &msg)
}

// TEST-GO-014: Client sends watcher.hello on connect (new protocol)
func TestClient_SendsHelloOnConnect(t *testing.T) {
	helloReceived := make(chan models.Message, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		msg, err := readMessage(conn)
		if err != nil {
			t.Logf("read error: %v", err)
			return
		}
		helloReceived <- msg
		time.Sleep(500 * time.Millisecond)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	select {
	case msg := <-helloReceived:
		if msg.Type != "watcher.hello" {
			t.Errorf("first message type = %q, want %q", msg.Type, "watcher.hello")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for watcher.hello")
	}
}

// TEST-GO-015: Client responds to watcher.auth_required with watcher.auth when token is set
func TestClient_AuthFlow(t *testing.T) {
	authReceived := make(chan string, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read watcher.hello
		_, err := readMessage(conn)
		if err != nil {
			t.Logf("read hello error: %v", err)
			return
		}

		// Send watcher.auth_required
		if err := conn.WriteJSON(models.Message{
			Type:      "watcher.auth_required",
			Timestamp: time.Now().UTC(),
			Data:      map[string]string{"watcher_id": "my-watcher-id"},
		}); err != nil {
			t.Logf("write auth_required error: %v", err)
			return
		}

		// Read watcher.auth response
		msg, err := readMessage(conn)
		if err != nil {
			t.Logf("read auth error: %v", err)
			return
		}

		if msg.Type == "watcher.auth" {
			dataBytes, _ := json.Marshal(msg.Data)
			var authData models.WatcherAuthData
			if err := json.Unmarshal(dataBytes, &authData); err == nil {
				authReceived <- authData.Token
			}
		}

		time.Sleep(500 * time.Millisecond)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.SetToken("my-secret-token")

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	select {
	case token := <-authReceived:
		if token != "my-secret-token" {
			t.Errorf("auth token = %q, want %q", token, "my-secret-token")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for watcher.auth")
	}
}

// TEST-GO-016: Reconnection after disconnect
func TestClient_ReconnectAfterDisconnect(t *testing.T) {
	var mu sync.Mutex
	connectionCount := 0

	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		mu.Lock()
		connectionCount++
		count := connectionCount
		mu.Unlock()

		// Read hello message
		_, _, _ = conn.ReadMessage()

		if count == 1 {
			// First connection: close immediately to trigger reconnection
			return
		}

		// Second connection: keep alive
		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.SetReconnectDelay(100 * time.Millisecond)

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Wait enough time for the first connection to drop and reconnection to happen
	time.Sleep(1 * time.Second)

	mu.Lock()
	count := connectionCount
	mu.Unlock()

	if count < 2 {
		t.Errorf("connection count = %d, want >= 2 (initial + reconnect)", count)
	}
}

// TEST-GO-017: Ping periodic
func TestClient_PingPeriodic(t *testing.T) {
	pingReceived := make(chan struct{}, 10)

	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		conn.SetPingHandler(func(appData string) error {
			pingReceived <- struct{}{}
			return conn.WriteControl(gorilla_ws.PongMessage, []byte(appData), time.Now().Add(5*time.Second))
		})

		// Read hello message
		_, _, _ = conn.ReadMessage()

		// Keep reading to process control frames (pings)
		for {
			_, _, err := conn.ReadMessage()
			if err != nil {
				break
			}
		}
	}))
	defer server.Close()

	pingInterval := 200 * time.Millisecond
	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.SetPingInterval(pingInterval)

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	time.Sleep(500 * time.Millisecond)

	count := len(pingReceived)
	if count < 1 {
		t.Errorf("ping count = %d, want >= 1 (with %v interval over 500ms)", count, pingInterval)
	}
}

// TEST-GO-018: Command received via OnCommand callback
func TestClient_OnCommand_Scan(t *testing.T) {
	commandReceived := make(chan models.Message, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read hello message
		_, _, err := conn.ReadMessage()
		if err != nil {
			return
		}

		// Send a command.scan message to the client
		scanCmd := models.Message{
			Type:      "command.scan",
			Timestamp: time.Now().UTC(),
			Data: models.CommandScanData{
				Path:   "/mnt/media/movies",
				ScanID: "scan-abc-123",
			},
		}
		if err := conn.WriteJSON(scanCmd); err != nil {
			t.Logf("failed to send command.scan: %v", err)
			return
		}

		time.Sleep(1 * time.Second)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.OnCommand = func(msg models.Message) {
		commandReceived <- msg
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	select {
	case msg := <-commandReceived:
		if msg.Type != "command.scan" {
			t.Errorf("command type = %q, want %q", msg.Type, "command.scan")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for command.scan callback")
	}
}

// TestClient_OnConfig_ReceivesConfig verifies that OnConfig is called on watcher.config messages.
func TestClient_OnConfig_ReceivesConfig(t *testing.T) {
	configReceived := make(chan models.WatcherConfigData, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read hello
		_, _, err := conn.ReadMessage()
		if err != nil {
			return
		}

		// Send watcher.config
		cfg := models.WatcherConfigData{
			WatchPaths:  []string{"/mnt/media"},
			ScanOnStart: true,
			LogLevel:    "debug",
			ConfigHash:  "abc123",
			AuthToken:   "new-token",
		}
		if err := conn.WriteJSON(models.Message{
			Type:      "watcher.config",
			Timestamp: time.Now().UTC(),
			Data:      cfg,
		}); err != nil {
			t.Logf("failed to send watcher.config: %v", err)
			return
		}

		time.Sleep(1 * time.Second)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.OnConfig = func(cfg models.WatcherConfigData) {
		configReceived <- cfg
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	select {
	case cfg := <-configReceived:
		if len(cfg.WatchPaths) != 1 || cfg.WatchPaths[0] != "/mnt/media" {
			t.Errorf("WatchPaths = %v, want [/mnt/media]", cfg.WatchPaths)
		}
		if cfg.ConfigHash != "abc123" {
			t.Errorf("ConfigHash = %q, want %q", cfg.ConfigHash, "abc123")
		}
		// Verify token was stored
		if client.GetToken() != "new-token" {
			t.Errorf("GetToken() = %q, want %q", client.GetToken(), "new-token")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for watcher.config callback")
	}
}

// TestClient_SendEvent verifies that SendEvent correctly formats and sends messages.
func TestClient_SendEvent(t *testing.T) {
	messagesReceived := make(chan []byte, 10)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read hello
		_, _, _ = conn.ReadMessage()

		for {
			_, raw, err := conn.ReadMessage()
			if err != nil {
				break
			}
			messagesReceived <- raw
		}
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	time.Sleep(50 * time.Millisecond)

	client.SendEvent("file.created", models.FileCreatedData{
		Path:          "/mnt/media/movie.mkv",
		Name:          "movie.mkv",
		SizeBytes:     1073741824,
		HardlinkCount: 1,
		IsDir:         false,
	})

	select {
	case raw := <-messagesReceived:
		var msg models.Message
		if err := json.Unmarshal(raw, &msg); err != nil {
			t.Fatalf("failed to unmarshal sent message: %v", err)
		}
		if msg.Type != "file.created" {
			t.Errorf("message type = %q, want %q", msg.Type, "file.created")
		}
		if msg.Timestamp.IsZero() {
			t.Error("message timestamp is zero, want non-zero")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for sent message")
	}
}

// TestClient_IsConnected_InitiallyFalse verifies the client is not connected before Connect.
func TestClient_IsConnected_InitiallyFalse(t *testing.T) {
	client := NewClient("ws://localhost:9999/ws", "my-watcher-id")
	if client.IsConnected() {
		t.Error("IsConnected() = true before Connect(), want false")
	}
}

// TestClient_DroppedMessages_TriggersResync verifies OnReconnect is called when messages were dropped.
func TestClient_DroppedMessages_TriggersResync(t *testing.T) {
	resyncCalled := make(chan struct{}, 1)

	var mu sync.Mutex
	connectionCount := 0

	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		mu.Lock()
		connectionCount++
		count := connectionCount
		mu.Unlock()

		// Read hello
		_, _, _ = conn.ReadMessage()

		if count == 1 {
			return // close immediately
		}

		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.SetReconnectDelay(100 * time.Millisecond)
	client.OnReconnect = func() {
		resyncCalled <- struct{}{}
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Simulate dropped messages
	client.droppedMessages.Store(true)

	select {
	case <-resyncCalled:
		// Success
	case <-time.After(3 * time.Second):
		t.Fatal("timeout waiting for OnReconnect callback after dropped messages")
	}

	if client.droppedMessages.Load() {
		t.Error("droppedMessages should be reset to false after OnReconnect")
	}
}

// TestClient_NoResync_WhenNoDroppedMessages verifies OnReconnect is NOT called without dropped messages.
func TestClient_NoResync_WhenNoDroppedMessages(t *testing.T) {
	resyncCalled := make(chan struct{}, 1)

	var mu sync.Mutex
	connectionCount := 0

	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()

		mu.Lock()
		connectionCount++
		count := connectionCount
		mu.Unlock()

		_, _, _ = conn.ReadMessage()

		if count == 1 {
			return
		}

		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-watcher-id")
	client.SetReconnectDelay(100 * time.Millisecond)
	client.OnReconnect = func() {
		resyncCalled <- struct{}{}
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	time.Sleep(1 * time.Second)

	select {
	case <-resyncCalled:
		t.Error("OnReconnect was called but no messages were dropped")
	default:
		// Good
	}
}
