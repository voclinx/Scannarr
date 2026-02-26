package config

import (
	"testing"
	"time"
)

// TEST-GO-001: Config - charge correctement les variables d'environnement
func TestLoad_AllEnvVarsSet(t *testing.T) {
	// Set all environment variables explicitly
	t.Setenv("SCANARR_WS_URL", "ws://myhost:9090/ws/watcher")
	t.Setenv("SCANARR_WS_RECONNECT_DELAY", "10s")
	t.Setenv("SCANARR_WS_PING_INTERVAL", "45s")
	t.Setenv("SCANARR_WATCH_PATHS", "/mnt/media1, /mnt/media2, /mnt/media3")
	t.Setenv("SCANARR_SCAN_ON_START", "false")
	t.Setenv("SCANARR_LOG_LEVEL", "debug")
	t.Setenv("SCANARR_AUTH_TOKEN", "my-secret-token")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("expected no error, got %v", err)
	}

	if cfg.WsURL != "ws://myhost:9090/ws/watcher" {
		t.Errorf("WsURL = %q, want %q", cfg.WsURL, "ws://myhost:9090/ws/watcher")
	}
	if cfg.WsReconnectDelay != 10*time.Second {
		t.Errorf("WsReconnectDelay = %v, want %v", cfg.WsReconnectDelay, 10*time.Second)
	}
	if cfg.WsPingInterval != 45*time.Second {
		t.Errorf("WsPingInterval = %v, want %v", cfg.WsPingInterval, 45*time.Second)
	}
	if len(cfg.WatchPaths) != 3 {
		t.Fatalf("WatchPaths len = %d, want 3", len(cfg.WatchPaths))
	}
	// Verify paths are trimmed
	expectedPaths := []string{"/mnt/media1", "/mnt/media2", "/mnt/media3"}
	for i, p := range cfg.WatchPaths {
		if p != expectedPaths[i] {
			t.Errorf("WatchPaths[%d] = %q, want %q", i, p, expectedPaths[i])
		}
	}
	if cfg.ScanOnStart != false {
		t.Errorf("ScanOnStart = %v, want false", cfg.ScanOnStart)
	}
	if cfg.LogLevel != "debug" {
		t.Errorf("LogLevel = %q, want %q", cfg.LogLevel, "debug")
	}
	if cfg.AuthToken != "my-secret-token" {
		t.Errorf("AuthToken = %q, want %q", cfg.AuthToken, "my-secret-token")
	}
}

// TEST-GO-002: Config - valeurs par défaut si env vars manquantes
func TestLoad_DefaultValues(t *testing.T) {
	// Set only the required environment variables
	t.Setenv("SCANARR_WATCH_PATHS", "/mnt/videos")
	t.Setenv("SCANARR_AUTH_TOKEN", "token123")

	// Clear optional env vars to ensure defaults apply
	t.Setenv("SCANARR_WS_URL", "")
	t.Setenv("SCANARR_WS_RECONNECT_DELAY", "")
	t.Setenv("SCANARR_WS_PING_INTERVAL", "")
	t.Setenv("SCANARR_SCAN_ON_START", "")
	t.Setenv("SCANARR_LOG_LEVEL", "")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("expected no error, got %v", err)
	}

	if cfg.WsURL != "ws://localhost:8081/ws/watcher" {
		t.Errorf("WsURL default = %q, want %q", cfg.WsURL, "ws://localhost:8081/ws/watcher")
	}
	if cfg.WsReconnectDelay != 5*time.Second {
		t.Errorf("WsReconnectDelay default = %v, want %v", cfg.WsReconnectDelay, 5*time.Second)
	}
	if cfg.WsPingInterval != 30*time.Second {
		t.Errorf("WsPingInterval default = %v, want %v", cfg.WsPingInterval, 30*time.Second)
	}
	if cfg.ScanOnStart != true {
		t.Errorf("ScanOnStart default = %v, want true", cfg.ScanOnStart)
	}
	if cfg.LogLevel != "info" {
		t.Errorf("LogLevel default = %q, want %q", cfg.LogLevel, "info")
	}
}

// TEST-GO-001 (continued): Missing SCANARR_WATCH_PATHS returns error
func TestLoad_MissingWatchPaths(t *testing.T) {
	t.Setenv("SCANARR_WATCH_PATHS", "")
	t.Setenv("SCANARR_AUTH_TOKEN", "some-token")

	cfg, err := Load()
	if err == nil {
		t.Fatalf("expected error for missing SCANARR_WATCH_PATHS, got nil (cfg=%+v)", cfg)
	}

	expected := "SCANARR_WATCH_PATHS is required"
	if err.Error() != expected {
		t.Errorf("error = %q, want %q", err.Error(), expected)
	}
}

// TEST-GO-002 (continued): Missing SCANARR_AUTH_TOKEN returns error
func TestLoad_MissingAuthToken(t *testing.T) {
	t.Setenv("SCANARR_WATCH_PATHS", "/mnt/media")
	t.Setenv("SCANARR_AUTH_TOKEN", "")

	cfg, err := Load()
	if err == nil {
		t.Fatalf("expected error for missing SCANARR_AUTH_TOKEN, got nil (cfg=%+v)", cfg)
	}

	expected := "SCANARR_AUTH_TOKEN is required"
	if err.Error() != expected {
		t.Errorf("error = %q, want %q", err.Error(), expected)
	}
}
