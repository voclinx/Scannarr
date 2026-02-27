package config

import (
	"testing"
)

// TestLoadEnv_AllEnvVarsSet verifies all supported env vars are loaded correctly.
func TestLoadEnv_AllEnvVarsSet(t *testing.T) {
	t.Setenv("SCANARR_WS_URL", "ws://myhost:9090/ws/watcher")
	t.Setenv("SCANARR_WATCHER_ID", "my-watcher-id")

	cfg, err := LoadEnv()
	if err != nil {
		t.Fatalf("LoadEnv() returned error: %v", err)
	}

	if cfg.WsURL != "ws://myhost:9090/ws/watcher" {
		t.Errorf("WsURL = %q, want %q", cfg.WsURL, "ws://myhost:9090/ws/watcher")
	}
	if cfg.WatcherID != "my-watcher-id" {
		t.Errorf("WatcherID = %q, want %q", cfg.WatcherID, "my-watcher-id")
	}
}

// TestLoadEnv_DefaultWsURL verifies the default WS URL is used when not set.
func TestLoadEnv_DefaultWsURL(t *testing.T) {
	t.Setenv("SCANARR_WS_URL", "")
	t.Setenv("SCANARR_WATCHER_ID", "my-watcher-id")

	cfg, err := LoadEnv()
	if err != nil {
		t.Fatalf("LoadEnv() returned error: %v", err)
	}

	if cfg.WsURL != "ws://localhost:8081/ws/watcher" {
		t.Errorf("WsURL default = %q, want %q", cfg.WsURL, "ws://localhost:8081/ws/watcher")
	}
}

// TestLoadEnv_MissingWatcherID verifies that missing SCANARR_WATCHER_ID returns an error.
func TestLoadEnv_MissingWatcherID(t *testing.T) {
	t.Setenv("SCANARR_WS_URL", "ws://localhost:8081/ws/watcher")
	t.Setenv("SCANARR_WATCHER_ID", "")

	cfg, err := LoadEnv()
	if err == nil {
		t.Fatalf("expected error for missing SCANARR_WATCHER_ID, got nil (cfg=%+v)", cfg)
	}

	expected := "SCANARR_WATCHER_ID is required"
	if err.Error() != expected {
		t.Errorf("error = %q, want %q", err.Error(), expected)
	}
}

// TestDefaultRuntimeConfig verifies sensible defaults.
func TestDefaultRuntimeConfig(t *testing.T) {
	cfg := DefaultRuntimeConfig()

	if cfg.LogLevel != "info" {
		t.Errorf("LogLevel = %q, want %q", cfg.LogLevel, "info")
	}
	if cfg.ScanOnStart != false {
		t.Errorf("ScanOnStart = %v, want false", cfg.ScanOnStart)
	}
	if len(cfg.WatchPaths) != 0 {
		t.Errorf("WatchPaths len = %d, want 0", len(cfg.WatchPaths))
	}
}
