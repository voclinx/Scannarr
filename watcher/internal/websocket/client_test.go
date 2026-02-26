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

// TEST-GO-014: Connect with valid token succeeds - verify auth message received
func TestClient_ConnectAndAuth(t *testing.T) {
	authReceived := make(chan models.AuthMessage, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read the auth message
		_, raw, err := conn.ReadMessage()
		if err != nil {
			t.Logf("read error: %v", err)
			return
		}

		var authMsg models.AuthMessage
		if err := json.Unmarshal(raw, &authMsg); err != nil {
			t.Logf("unmarshal error: %v", err)
			return
		}
		authReceived <- authMsg

		// Keep the connection open for a bit
		time.Sleep(500 * time.Millisecond)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "my-secret-token", 1*time.Second, 30*time.Second)
	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Verify the client is connected
	if !client.IsConnected() {
		t.Error("IsConnected() = false after Connect(), want true")
	}

	// Verify the auth message was received by the server
	select {
	case authMsg := <-authReceived:
		if authMsg.Type != "auth" {
			t.Errorf("auth message type = %q, want %q", authMsg.Type, "auth")
		}
		if authMsg.Data.Token != "my-secret-token" {
			t.Errorf("auth token = %q, want %q", authMsg.Data.Token, "my-secret-token")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for auth message")
	}
}

// TEST-GO-015 + TEST-GO-016: Reconnection after disconnect
func TestClient_ReconnectAfterDisconnect(t *testing.T) {
	var mu sync.Mutex
	connectionCount := 0

	// This server counts connections. Each connection reads the auth message then closes.
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

		// Read auth message
		_, _, _ = conn.ReadMessage()

		if count == 1 {
			// First connection: close immediately to trigger reconnection
			return
		}

		// Second connection: keep alive
		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 100*time.Millisecond, 30*time.Second)
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

	// Should have reconnected at least once (connection count >= 2)
	if count < 2 {
		t.Errorf("connection count = %d, want >= 2 (initial + reconnect)", count)
	}
}

// TEST-GO-017: Ping periodic - verify ping messages are sent at the configured interval
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

		// Set a ping handler to detect pings from the client
		conn.SetPingHandler(func(appData string) error {
			pingReceived <- struct{}{}
			// Respond with pong
			return conn.WriteControl(gorilla_ws.PongMessage, []byte(appData), time.Now().Add(5*time.Second))
		})

		// Read auth message first
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

	// Use a short ping interval to speed up the test
	pingInterval := 200 * time.Millisecond
	client := NewClient(httpToWs(server.URL), "token", 1*time.Second, pingInterval)
	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Wait for at least 2 ping intervals
	time.Sleep(500 * time.Millisecond)

	// Count received pings
	count := len(pingReceived)
	if count < 1 {
		t.Errorf("ping count = %d, want >= 1 (with %v interval over 500ms)", count, pingInterval)
	}
}

// TEST-GO-018: Command.scan received - verify OnCommand callback is triggered
func TestClient_OnCommand_Scan(t *testing.T) {
	commandReceived := make(chan models.Message, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read auth message
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

		// Keep connection open
		time.Sleep(1 * time.Second)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 1*time.Second, 30*time.Second)
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

// TEST-GO-019: Command.watch.add received
func TestClient_OnCommand_WatchAdd(t *testing.T) {
	commandReceived := make(chan models.Message, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read auth message
		_, _, err := conn.ReadMessage()
		if err != nil {
			return
		}

		// Send a command.watch.add message
		watchCmd := models.Message{
			Type:      "command.watch.add",
			Timestamp: time.Now().UTC(),
			Data: models.CommandWatchData{
				Path: "/mnt/media/new-volume",
			},
		}
		if err := conn.WriteJSON(watchCmd); err != nil {
			t.Logf("failed to send command.watch.add: %v", err)
			return
		}

		time.Sleep(1 * time.Second)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 1*time.Second, 30*time.Second)
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
		if msg.Type != "command.watch.add" {
			t.Errorf("command type = %q, want %q", msg.Type, "command.watch.add")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for command.watch.add callback")
	}
}

// TEST-GO-020: Command.watch.remove received
func TestClient_OnCommand_WatchRemove(t *testing.T) {
	commandReceived := make(chan models.Message, 1)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read auth message
		_, _, err := conn.ReadMessage()
		if err != nil {
			return
		}

		// Send a command.watch.remove message
		watchCmd := models.Message{
			Type:      "command.watch.remove",
			Timestamp: time.Now().UTC(),
			Data: models.CommandWatchData{
				Path: "/mnt/media/old-volume",
			},
		}
		if err := conn.WriteJSON(watchCmd); err != nil {
			t.Logf("failed to send command.watch.remove: %v", err)
			return
		}

		time.Sleep(1 * time.Second)
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 1*time.Second, 30*time.Second)
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
		if msg.Type != "command.watch.remove" {
			t.Errorf("command type = %q, want %q", msg.Type, "command.watch.remove")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("timeout waiting for command.watch.remove callback")
	}
}

// TestClient_SendEvent verifies that SendEvent correctly formats and sends messages.
func TestClient_SendEvent(t *testing.T) {
	messagesReceived := make(chan []byte, 10)

	server := newTestServer(t, func(conn *gorilla_ws.Conn) {
		defer conn.Close()

		// Read auth message
		_, _, _ = conn.ReadMessage()

		// Read subsequent messages
		for {
			_, raw, err := conn.ReadMessage()
			if err != nil {
				break
			}
			messagesReceived <- raw
		}
	})
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 1*time.Second, 30*time.Second)
	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Give the write loop time to start
	time.Sleep(50 * time.Millisecond)

	// Send an event
	client.SendEvent("file.created", models.FileCreatedData{
		Path:          "/mnt/media/movie.mkv",
		Name:          "movie.mkv",
		SizeBytes:     1073741824,
		HardlinkCount: 1,
		IsDir:         false,
	})

	// Wait for the message to arrive
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
	client := NewClient("ws://localhost:9999/ws", "token", 1*time.Second, 30*time.Second)
	if client.IsConnected() {
		t.Error("IsConnected() = true before Connect(), want false")
	}
}

// TestClient_DroppedMessages_TriggersResync verifies that when the message buffer
// overflows during a disconnection, a resync scan is triggered after reconnection.
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

		// Read auth message
		_, _, _ = conn.ReadMessage()

		if count == 1 {
			// First connection: close to trigger reconnection
			return
		}

		// Second connection: keep alive
		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 100*time.Millisecond, 30*time.Second)
	client.OnReconnect = func() {
		resyncCalled <- struct{}{}
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Simulate dropped messages by setting the flag directly
	// (in production this happens when msgChan buffer overflows)
	client.droppedMessages.Store(true)

	// Wait for reconnection to happen (first connection closes immediately)
	select {
	case <-resyncCalled:
		// Success: OnReconnect was called because droppedMessages was true
	case <-time.After(3 * time.Second):
		t.Fatal("timeout waiting for OnReconnect callback after dropped messages")
	}

	// Verify the flag was reset
	if client.droppedMessages.Load() {
		t.Error("droppedMessages should be reset to false after OnReconnect")
	}
}

// TestClient_NoResync_WhenNoDroppedMessages verifies that OnReconnect is NOT called
// when no messages were dropped during disconnection.
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

		// Read auth message
		_, _, _ = conn.ReadMessage()

		if count == 1 {
			// First connection: close to trigger reconnection
			return
		}

		// Second connection: keep alive
		time.Sleep(2 * time.Second)
	}))
	defer server.Close()

	client := NewClient(httpToWs(server.URL), "token", 100*time.Millisecond, 30*time.Second)
	client.OnReconnect = func() {
		resyncCalled <- struct{}{}
	}

	err := client.Connect()
	if err != nil {
		t.Fatalf("Connect() returned error: %v", err)
	}
	defer client.Close()

	// Do NOT set droppedMessages — buffer didn't overflow

	// Wait for reconnection, then a bit more
	time.Sleep(1 * time.Second)

	select {
	case <-resyncCalled:
		t.Error("OnReconnect was called but no messages were dropped")
	default:
		// Good: OnReconnect was not called
	}
}
