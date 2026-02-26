package scanner

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	gorilla_ws "github.com/gorilla/websocket"
	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

// newTestWSServer creates a test WebSocket server that collects all messages
// sent by the client into a channel. The server automatically reads and
// discards the initial auth message.
func newTestWSServer(t *testing.T) (*httptest.Server, chan []byte) {
	t.Helper()
	messages := make(chan []byte, 5000)
	upgrader := gorilla_ws.Upgrader{
		CheckOrigin: func(r *http.Request) bool { return true },
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			t.Logf("upgrade error: %v", err)
			return
		}
		defer conn.Close()

		// Read and discard the auth message
		_, _, err = conn.ReadMessage()
		if err != nil {
			t.Logf("failed to read auth message: %v", err)
			return
		}

		// Read all subsequent messages
		for {
			_, msg, err := conn.ReadMessage()
			if err != nil {
				break
			}
			messages <- msg
		}
	}))

	return server, messages
}

// httpToWs converts an http:// URL to ws:// for WebSocket dialing.
func httpToWs(url string) string {
	return "ws" + strings.TrimPrefix(url, "http")
}

// createTestClient connects a websocket.Client to the test server and waits
// for the connection to be established.
func createTestClient(t *testing.T, wsURL string) *websocket.Client {
	t.Helper()
	client := websocket.NewClient(wsURL, "test-token", 1*time.Second, 30*time.Second)
	if err := client.Connect(); err != nil {
		t.Fatalf("failed to connect to test server: %v", err)
	}
	// Give the write loop a moment to start
	time.Sleep(50 * time.Millisecond)
	return client
}

// collectMessages drains the message channel with a timeout and returns all messages.
func collectMessages(ch chan []byte, timeout time.Duration) []models.Message {
	var msgs []models.Message
	timer := time.NewTimer(timeout)
	defer timer.Stop()

	for {
		select {
		case raw := <-ch:
			var msg models.Message
			if err := json.Unmarshal(raw, &msg); err == nil {
				msgs = append(msgs, msg)
			}
		case <-timer.C:
			return msgs
		}
	}
}

// createTempMediaFiles creates n .mkv files in the given directory.
// Each file has 1024 bytes of content.
func createTempMediaFiles(t *testing.T, dir string, n int) {
	t.Helper()
	content := make([]byte, 1024)
	for i := 0; i < n; i++ {
		name := filepath.Join(dir, fmt.Sprintf("movie_%04d.mkv", i))
		if err := os.WriteFile(name, content, 0644); err != nil {
			t.Fatalf("failed to create temp file %s: %v", name, err)
		}
	}
}

// TEST-GO-009: Scan finds .mkv files in a temp directory
func TestScan_FindsMkvFiles(t *testing.T) {
	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)

	// Create a temp directory with media files
	tmpDir := t.TempDir()
	createTempMediaFiles(t, tmpDir, 5)

	// Run the scan
	err := scanner.Scan(tmpDir, "test-scan-001")
	if err != nil {
		t.Fatalf("Scan returned error: %v", err)
	}

	// Give the write loop time to flush messages
	time.Sleep(300 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 500*time.Millisecond)

	// Verify scan.started
	var started, completed int
	var fileEvents int
	for _, msg := range msgs {
		switch msg.Type {
		case "scan.started":
			started++
		case "scan.completed":
			completed++
		case "scan.file":
			fileEvents++
		}
	}

	if started != 1 {
		t.Errorf("scan.started count = %d, want 1", started)
	}
	if completed != 1 {
		t.Errorf("scan.completed count = %d, want 1", completed)
	}
	if fileEvents != 5 {
		t.Errorf("scan.file count = %d, want 5", fileEvents)
	}
}

// TEST-GO-010: Hardlink count detection via os.Link
func TestScan_HardlinkCount(t *testing.T) {
	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)

	tmpDir := t.TempDir()
	originalFile := filepath.Join(tmpDir, "original.mkv")
	hardlinkFile := filepath.Join(tmpDir, "hardlink.mkv")

	// Create original file
	content := make([]byte, 2048)
	if err := os.WriteFile(originalFile, content, 0644); err != nil {
		t.Fatalf("failed to write original: %v", err)
	}

	// Create a hardlink to the same file
	if err := os.Link(originalFile, hardlinkFile); err != nil {
		t.Fatalf("failed to create hardlink: %v", err)
	}

	// Scan the directory
	err := scanner.Scan(tmpDir, "test-scan-hl")
	if err != nil {
		t.Fatalf("Scan returned error: %v", err)
	}

	time.Sleep(300 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 500*time.Millisecond)

	// Both files should report hardlink_count = 2
	var fileMessages []models.Message
	for _, msg := range msgs {
		if msg.Type == "scan.file" {
			fileMessages = append(fileMessages, msg)
		}
	}

	if len(fileMessages) != 2 {
		t.Fatalf("expected 2 scan.file messages, got %d", len(fileMessages))
	}

	for _, msg := range fileMessages {
		// msg.Data is unmarshalled as map[string]interface{} by default
		data, ok := msg.Data.(map[string]interface{})
		if !ok {
			t.Fatalf("scan.file data is not a map: %T", msg.Data)
		}
		hlCount, ok := data["hardlink_count"].(float64) // JSON numbers are float64
		if !ok {
			t.Fatalf("hardlink_count not found or wrong type in scan.file data")
		}
		if int(hlCount) != 2 {
			t.Errorf("hardlink_count = %d, want 2 (for file with one hardlink)", int(hlCount))
		}
	}
}

// TEST-GO-011: Non-media files (.srt, .nfo, .txt) are skipped
func TestScan_SkipsNonMediaFiles(t *testing.T) {
	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)

	tmpDir := t.TempDir()
	content := []byte("test content")

	// Create a mix of media and non-media files
	files := map[string]bool{
		"movie.mkv":    true,  // media -> should be scanned
		"video.mp4":    true,  // media -> should be scanned
		"subtitle.srt": false, // non-media -> should be skipped
		"info.nfo":     false, // non-media -> should be skipped
		"readme.txt":   false, // non-media -> should be skipped
		"poster.jpg":   false, // non-media -> should be skipped
	}

	for name := range files {
		if err := os.WriteFile(filepath.Join(tmpDir, name), content, 0644); err != nil {
			t.Fatalf("failed to create %s: %v", name, err)
		}
	}

	err := scanner.Scan(tmpDir, "test-scan-filter")
	if err != nil {
		t.Fatalf("Scan returned error: %v", err)
	}

	time.Sleep(300 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 500*time.Millisecond)

	var scannedFiles []string
	for _, msg := range msgs {
		if msg.Type == "scan.file" {
			data, ok := msg.Data.(map[string]interface{})
			if ok {
				if name, ok := data["name"].(string); ok {
					scannedFiles = append(scannedFiles, name)
				}
			}
		}
	}

	if len(scannedFiles) != 2 {
		t.Errorf("expected 2 scanned files (mkv, mp4), got %d: %v", len(scannedFiles), scannedFiles)
	}

	// Verify only media files were scanned
	for _, name := range scannedFiles {
		if !files[name] {
			t.Errorf("unexpected non-media file scanned: %s", name)
		}
	}
}

// TEST-GO-012: scan.progress is sent every 100 files
func TestScan_ProgressEvery100Files(t *testing.T) {
	if testing.Short() {
		t.Skip("skipping test with 150+ files in short mode")
	}

	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)

	tmpDir := t.TempDir()
	createTempMediaFiles(t, tmpDir, 150)

	err := scanner.Scan(tmpDir, "test-scan-progress")
	if err != nil {
		t.Fatalf("Scan returned error: %v", err)
	}

	time.Sleep(500 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 1*time.Second)

	var progressCount int
	for _, msg := range msgs {
		if msg.Type == "scan.progress" {
			progressCount++
		}
	}

	// With 150 files, progress should be sent at file 100 -> 1 progress event
	if progressCount != 1 {
		t.Errorf("scan.progress count = %d, want 1 (for 150 files, progress at 100)", progressCount)
	}
}

// TEST-GO-013: scan.completed contains correct stats
func TestScan_CompletedStats(t *testing.T) {
	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)

	tmpDir := t.TempDir()

	// Create a subdirectory with some files
	subDir := filepath.Join(tmpDir, "movies")
	if err := os.Mkdir(subDir, 0755); err != nil {
		t.Fatalf("failed to create subdir: %v", err)
	}

	fileSize := int64(4096)
	content := make([]byte, fileSize)

	// Create 3 media files in root and 2 in subdirectory
	for _, name := range []string{"a.mkv", "b.mp4", "c.avi"} {
		if err := os.WriteFile(filepath.Join(tmpDir, name), content, 0644); err != nil {
			t.Fatalf("failed to create %s: %v", name, err)
		}
	}
	for _, name := range []string{"d.mkv", "e.mp4"} {
		if err := os.WriteFile(filepath.Join(subDir, name), content, 0644); err != nil {
			t.Fatalf("failed to create %s: %v", name, err)
		}
	}

	// Also add a non-media file (should be skipped)
	if err := os.WriteFile(filepath.Join(tmpDir, "info.nfo"), []byte("info"), 0644); err != nil {
		t.Fatalf("failed to create info.nfo: %v", err)
	}

	err := scanner.Scan(tmpDir, "test-scan-stats")
	if err != nil {
		t.Fatalf("Scan returned error: %v", err)
	}

	time.Sleep(300 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 500*time.Millisecond)

	// Find the scan.completed message
	var completedMsg *models.Message
	for i, msg := range msgs {
		if msg.Type == "scan.completed" {
			completedMsg = &msgs[i]
			break
		}
	}

	if completedMsg == nil {
		t.Fatal("scan.completed message not found")
	}

	data, ok := completedMsg.Data.(map[string]interface{})
	if !ok {
		t.Fatalf("scan.completed data is not a map: %T", completedMsg.Data)
	}

	// Verify total_files = 5 (only media files)
	totalFiles := int(data["total_files"].(float64))
	if totalFiles != 5 {
		t.Errorf("total_files = %d, want 5", totalFiles)
	}

	// Verify total_size_bytes = 5 * 4096
	totalSize := int64(data["total_size_bytes"].(float64))
	expectedSize := int64(5) * fileSize
	if totalSize != expectedSize {
		t.Errorf("total_size_bytes = %d, want %d", totalSize, expectedSize)
	}

	// Verify scan_id
	scanID, ok := data["scan_id"].(string)
	if !ok || scanID != "test-scan-stats" {
		t.Errorf("scan_id = %q, want %q", scanID, "test-scan-stats")
	}

	// Verify path
	scanPath, ok := data["path"].(string)
	if !ok || scanPath != tmpDir {
		t.Errorf("path = %q, want %q", scanPath, tmpDir)
	}

	// Verify duration_ms is non-negative
	durationMs := int64(data["duration_ms"].(float64))
	if durationMs < 0 {
		t.Errorf("duration_ms = %d, want >= 0", durationMs)
	}

	// Verify total_dirs >= 1 (at least the "movies" subdirectory)
	// filepath.Walk counts directories as they are encountered.
	totalDirs := int(data["total_dirs"].(float64))
	if totalDirs < 1 {
		t.Errorf("total_dirs = %d, want >= 1", totalDirs)
	}
}

// TestScan_EmptyDirectory verifies scan completes with zero files on an empty directory.
func TestScan_EmptyDirectory(t *testing.T) {
	server, messages := newTestWSServer(t)
	defer server.Close()

	client := createTestClient(t, httpToWs(server.URL))
	defer client.Close()

	scanner := New(client)
	tmpDir := t.TempDir()

	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		err := scanner.Scan(tmpDir, "test-scan-empty")
		if err != nil {
			t.Errorf("Scan returned error: %v", err)
		}
	}()

	wg.Wait()
	time.Sleep(300 * time.Millisecond)
	client.Close()

	msgs := collectMessages(messages, 500*time.Millisecond)

	var started, completed, fileEvents int
	for _, msg := range msgs {
		switch msg.Type {
		case "scan.started":
			started++
		case "scan.completed":
			completed++
		case "scan.file":
			fileEvents++
		}
	}

	if started != 1 {
		t.Errorf("scan.started count = %d, want 1", started)
	}
	if completed != 1 {
		t.Errorf("scan.completed count = %d, want 1", completed)
	}
	if fileEvents != 0 {
		t.Errorf("scan.file count = %d, want 0 (empty directory)", fileEvents)
	}
}
