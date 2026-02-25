package config

import (
	"fmt"
	"os"
	"strings"
	"time"
)

// Config holds all watcher configuration.
type Config struct {
	WsURL            string
	WsReconnectDelay time.Duration
	WsPingInterval   time.Duration
	WatchPaths       []string
	ScanOnStart      bool
	LogLevel         string
	AuthToken        string
}

// Load reads configuration from environment variables.
func Load() (*Config, error) {
	wsURL := getEnv("SCANARR_WS_URL", "ws://localhost:8081/ws/watcher")
	reconnectDelay := parseDuration("SCANARR_WS_RECONNECT_DELAY", "5s")
	pingInterval := parseDuration("SCANARR_WS_PING_INTERVAL", "30s")

	watchPathsStr := getEnv("SCANARR_WATCH_PATHS", "")
	if watchPathsStr == "" {
		return nil, fmt.Errorf("SCANARR_WATCH_PATHS is required")
	}
	watchPaths := strings.Split(watchPathsStr, ",")
	for i, p := range watchPaths {
		watchPaths[i] = strings.TrimSpace(p)
	}

	scanOnStart := getEnv("SCANARR_SCAN_ON_START", "true") == "true"
	logLevel := getEnv("SCANARR_LOG_LEVEL", "info")
	authToken := getEnv("SCANARR_AUTH_TOKEN", "")

	if authToken == "" {
		return nil, fmt.Errorf("SCANARR_AUTH_TOKEN is required")
	}

	return &Config{
		WsURL:            wsURL,
		WsReconnectDelay: reconnectDelay,
		WsPingInterval:   pingInterval,
		WatchPaths:       watchPaths,
		ScanOnStart:      scanOnStart,
		LogLevel:         logLevel,
		AuthToken:        authToken,
	}, nil
}

func getEnv(key, fallback string) string {
	if val := os.Getenv(key); val != "" {
		return val
	}
	return fallback
}

func parseDuration(key, fallback string) time.Duration {
	val := getEnv(key, fallback)
	d, err := time.ParseDuration(val)
	if err != nil {
		d, _ = time.ParseDuration(fallback)
	}
	return d
}
