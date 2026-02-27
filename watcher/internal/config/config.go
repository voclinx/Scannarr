package config

import (
	"fmt"
	"os"
	"time"
)

// EnvConfig holds the minimal static configuration loaded from environment variables.
// Only 2 variables are required; everything else comes from the API via watcher.config.
type EnvConfig struct {
	WsURL     string
	WatcherID string
}

// RuntimeConfig holds the dynamic configuration received from the API.
type RuntimeConfig struct {
	WatchPaths             []string
	ScanOnStart            bool
	LogLevel               string
	DisableDeletion        bool
	WsReconnectDelaySecs   int
	WsPingIntervalSecs     int
	LogRetentionDays       int
	DebugLogRetentionHours int
}

// DefaultRuntimeConfig returns sensible defaults used before config is received from the API.
func DefaultRuntimeConfig() *RuntimeConfig {
	return &RuntimeConfig{
		WatchPaths:             []string{},
		ScanOnStart:            false, // Don't scan until we get config from API
		LogLevel:               "info",
		DisableDeletion:        false,
		WsReconnectDelaySecs:   5,
		WsPingIntervalSecs:     30,
		LogRetentionDays:       7,
		DebugLogRetentionHours: 24,
	}
}

// LoadEnv reads the two required environment variables.
// Returns an error if SCANARR_WS_URL or SCANARR_WATCHER_ID is missing.
func LoadEnv() (*EnvConfig, error) {
	wsURL := getEnv("SCANARR_WS_URL", "ws://localhost:8081/ws/watcher")
	watcherID := getEnv("SCANARR_WATCHER_ID", "")

	if watcherID == "" {
		return nil, fmt.Errorf("SCANARR_WATCHER_ID is required")
	}

	return &EnvConfig{
		WsURL:     wsURL,
		WatcherID: watcherID,
	}, nil
}

func getEnv(key, fallback string) string {
	if val := os.Getenv(key); val != "" {
		return val
	}
	return fallback
}

func parseDuration(s, fallback string) time.Duration {
	d, err := time.ParseDuration(s)
	if err != nil {
		d, _ = time.ParseDuration(fallback)
	}
	return d
}
