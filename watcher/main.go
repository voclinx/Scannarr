package main

import (
	"encoding/json"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/google/uuid"
	"github.com/voclinx/scanarr-watcher/internal/config"
	"github.com/voclinx/scanarr-watcher/internal/deleter"
	"github.com/voclinx/scanarr-watcher/internal/logger"
	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/scanner"
	"github.com/voclinx/scanarr-watcher/internal/state"
	"github.com/voclinx/scanarr-watcher/internal/watcher"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

func main() {
	// Step 1: Load minimal env config (2 variables)
	envCfg, err := config.LoadEnv()
	if err != nil {
		slog.Error("Failed to load env config", "error", err)
		os.Exit(1)
	}

	// Step 2: Load cached state (auth token + config)
	cachedState, err := state.Load()
	if err != nil {
		slog.Warn("Failed to load state file, starting fresh", "error", err)
		cachedState = &state.State{}
	}

	// Step 3: Initialize logger with default level (will be updated from config)
	rtCfg := config.DefaultRuntimeConfig()
	if cachedState.Config != nil {
		if level, ok := cachedState.Config["log_level"].(string); ok && level != "" {
			rtCfg.LogLevel = level
		}
	}
	logger.Setup(rtCfg.LogLevel)

	slog.Info("Scanarr Watcher starting",
		"watcher_id", envCfg.WatcherID,
		"ws_url", envCfg.WsURL,
	)

	// Step 4: Create WebSocket client with new 2-param constructor
	wsClient := websocket.NewClient(envCfg.WsURL, envCfg.WatcherID)
	wsClient.SetReconnectDelay(rtCfg.ReconnectDelay)
	wsClient.SetPingInterval(rtCfg.PingInterval)

	// Restore cached token if available
	if cachedState.AuthToken != "" {
		wsClient.SetToken(cachedState.AuthToken)
		slog.Info("Restored auth token from state")
	}

	// Step 5: Create components
	fileScanner := scanner.New(wsClient)
	fileWatcher, err := watcher.New(wsClient, rtCfg.WatchPaths)
	if err != nil {
		slog.Error("Failed to create file watcher", "error", err)
		os.Exit(1)
	}
	fileDeleter := deleter.New(wsClient)

	// watcherReady tracks whether we have received the first config and started components.
	// Distinguishes first startup (scan all paths if ScanOnStart) from reconnections (scan new paths only).
	var watcherReady bool
	var readyMu sync.Mutex

	// Step 6: OnConfig callback — called when API sends watcher.config
	wsClient.OnConfig = func(cfg models.WatcherConfigData) {
		// Handle rejection signal
		if cfg.AuthToken == "__rejected__" {
			slog.Warn("Token rejected — clearing state file")
			if err := state.Clear(); err != nil {
				slog.Warn("Failed to clear state file", "error", err)
			}
			return
		}

		// Update runtime config
		rtCfg = applyWatcherConfig(cfg, rtCfg)

		// Persist new state
		newState := &state.State{
			AuthToken:  wsClient.GetToken(),
			ConfigHash: cfg.ConfigHash,
		}
		if err := state.Save(newState); err != nil {
			slog.Warn("Failed to save state", "error", err)
		}

		// Apply new log level dynamically
		logger.SetLevel(rtCfg.LogLevel)

		readyMu.Lock()
		isFirst := !watcherReady
		if isFirst {
			watcherReady = true
		}
		readyMu.Unlock()

		if isFirst {
			// First config received (startup or post-restart): add all paths, then full scan if ScanOnStart=true.
			applyWatchPathChanges(fileWatcher, nil, rtCfg.WatchPaths)
			if cfg.ScanOnStart && len(rtCfg.WatchPaths) > 0 {
				go func() {
					time.Sleep(2 * time.Second)
					for _, path := range rtCfg.WatchPaths {
						scanID := uuid.New().String()
						slog.Info("Initial scan triggered", "path", path, "scan_id", scanID)
						if err := fileScanner.Scan(path, scanID); err != nil {
							slog.Error("Initial scan failed", "path", path, "error", err)
						}
					}
				}()
			}
		} else {
			// Hot reload or reconnection: only scan newly added paths (no full rescan).
			applyWatchPathChanges(fileWatcher, fileScanner, rtCfg.WatchPaths)
		}
	}

	// Step 7: Handle commands from API
	startTime := time.Now()
	wsClient.OnCommand = func(msg models.Message) {
		handleCommand(msg, fileScanner, fileWatcher, fileDeleter)
	}

	// Step 8: Handle reconnection with dropped events — trigger a full resync scan
	wsClient.OnReconnect = func() {
		slog.Info("Resync scan triggered after reconnection with dropped events")
		time.Sleep(2 * time.Second)
		for _, path := range rtCfg.WatchPaths {
			scanID := uuid.New().String()
			slog.Info("Resync scanning path", "path", path, "scan_id", scanID)
			if err := fileScanner.Scan(path, scanID); err != nil {
				slog.Error("Resync scan failed", "path", path, "error", err)
			}
		}
	}

	// Step 9: Connect to WebSocket (with retry)
	wsClient.ConnectWithRetry()

	// Step 10: Start filesystem watcher (paths may be empty until config arrives)
	if err := fileWatcher.Start(); err != nil {
		slog.Error("Failed to start file watcher", "error", err)
		os.Exit(1)
	}

	// Step 11: Send periodic status with watcher_id and config_hash
	go func() {
		ticker := time.NewTicker(60 * time.Second)
		defer ticker.Stop()
		for range ticker.C {
			wsClient.SendEvent("watcher.status", models.WatcherStatusData{
				Status:        "watching",
				WatcherID:     envCfg.WatcherID,
				ConfigHash:    wsClient.GetConfigHash(),
				WatchedPaths:  fileWatcher.GetWatchedPaths(),
				UptimeSeconds: int64(time.Since(startTime).Seconds()),
			})
		}
	}()

	// Wait for shutdown signal
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	sig := <-sigChan

	slog.Info("Shutting down", "signal", sig)
	fileWatcher.Close()
	wsClient.Close()
	slog.Info("Shutdown complete")
}

// applyWatcherConfig converts a WatcherConfigData into a RuntimeConfig.
func applyWatcherConfig(cfg models.WatcherConfigData, current *config.RuntimeConfig) *config.RuntimeConfig {
	rt := &config.RuntimeConfig{
		WatchPaths:             cfg.WatchPaths,
		ScanOnStart:            cfg.ScanOnStart,
		LogLevel:               cfg.LogLevel,
		ReconnectDelay:         current.ReconnectDelay,
		PingInterval:           current.PingInterval,
		LogRetentionDays:       cfg.LogRetentionDays,
		DebugLogRetentionHours: cfg.DebugLogRetentionHours,
	}

	if cfg.ReconnectDelay != "" {
		if d, err := time.ParseDuration(cfg.ReconnectDelay); err == nil {
			rt.ReconnectDelay = d
		}
	}
	if cfg.PingInterval != "" {
		if d, err := time.ParseDuration(cfg.PingInterval); err == nil {
			rt.PingInterval = d
		}
	}

	if rt.LogLevel == "" {
		rt.LogLevel = "info"
	}

	return rt
}

// applyWatchPathChanges updates fsnotify paths.
// If fs is non-nil, newly added paths are scanned immediately (hot-reload behavior).
// Pass fs=nil on first startup to avoid double-scanning with ScanOnStart.
func applyWatchPathChanges(fw *watcher.FileWatcher, fs *scanner.Scanner, newPaths []string) {
	existing := fw.GetWatchedPaths()

	existingSet := make(map[string]bool, len(existing))
	for _, p := range existing {
		existingSet[p] = true
	}
	newSet := make(map[string]bool, len(newPaths))
	for _, p := range newPaths {
		newSet[p] = true
	}

	// Add new paths; scan them if a scanner is provided (hot-reload)
	for p := range newSet {
		if !existingSet[p] {
			if err := fw.AddPath(p); err != nil {
				slog.Error("Failed to add watch path", "path", p, "error", err)
			} else {
				slog.Info("Added watch path", "path", p)
				if fs != nil {
					go func(path string) {
						scanID := uuid.New().String()
						slog.Info("Scanning newly added path", "path", path, "scan_id", scanID)
						if err := fs.Scan(path, scanID); err != nil {
							slog.Error("Scan of new path failed", "path", path, "error", err)
						}
					}(p)
				}
			}
		}
	}

	// Remove old paths
	for p := range existingSet {
		if !newSet[p] {
			if err := fw.RemovePath(p); err != nil {
				slog.Error("Failed to remove watch path", "path", p, "error", err)
			} else {
				slog.Info("Removed watch path", "path", p)
			}
		}
	}
}

func handleCommand(msg models.Message, fileScanner *scanner.Scanner, fileWatcher *watcher.FileWatcher, fileDeleter *deleter.Deleter) {
	switch msg.Type {
	case "command.scan":
		dataBytes, err := json.Marshal(msg.Data)
		if err != nil {
			slog.Warn("Failed to marshal command data", "error", err)
			return
		}
		var scanCmd models.CommandScanData
		if err := json.Unmarshal(dataBytes, &scanCmd); err != nil {
			slog.Warn("Failed to parse command.scan data", "error", err)
			return
		}
		go func() {
			if err := fileScanner.Scan(scanCmd.Path, scanCmd.ScanID); err != nil {
				slog.Error("Scan failed", "path", scanCmd.Path, "error", err)
			}
		}()

	case "command.watch.add":
		dataBytes, _ := json.Marshal(msg.Data)
		var watchCmd models.CommandWatchData
		if err := json.Unmarshal(dataBytes, &watchCmd); err != nil {
			return
		}
		if err := fileWatcher.AddPath(watchCmd.Path); err != nil {
			slog.Error("Failed to add watch path", "path", watchCmd.Path, "error", err)
		} else {
			slog.Info("Added watch path", "path", watchCmd.Path)
		}

	case "command.watch.remove":
		dataBytes, _ := json.Marshal(msg.Data)
		var watchCmd models.CommandWatchData
		if err := json.Unmarshal(dataBytes, &watchCmd); err != nil {
			return
		}
		if err := fileWatcher.RemovePath(watchCmd.Path); err != nil {
			slog.Error("Failed to remove watch path", "path", watchCmd.Path, "error", err)
		} else {
			slog.Info("Removed watch path", "path", watchCmd.Path)
		}

	case "command.files.delete":
		dataBytes, err := json.Marshal(msg.Data)
		if err != nil {
			slog.Warn("Failed to marshal command data", "error", err)
			return
		}
		var deleteCmd models.CommandFilesDeleteData
		if err := json.Unmarshal(dataBytes, &deleteCmd); err != nil {
			slog.Warn("Failed to parse command.files.delete data", "error", err)
			return
		}
		slog.Info("Received delete command",
			"request_id", deleteCmd.RequestID,
			"deletion_id", deleteCmd.DeletionID,
			"files", len(deleteCmd.Files),
		)
		go fileDeleter.ProcessDeleteCommand(deleteCmd)

	case "command.files.hardlink":
		dataBytes, err := json.Marshal(msg.Data)
		if err != nil {
			slog.Warn("Failed to marshal hardlink command data", "error", err)
			return
		}
		var hardlinkCmd models.CommandFilesHardlinkData
		if err := json.Unmarshal(dataBytes, &hardlinkCmd); err != nil {
			slog.Warn("Failed to parse command.files.hardlink data", "error", err)
			return
		}
		slog.Info("Received hardlink command",
			"request_id", hardlinkCmd.RequestID,
			"deletion_id", hardlinkCmd.DeletionID,
			"source", hardlinkCmd.SourcePath,
			"target", hardlinkCmd.TargetPath,
		)
		go fileDeleter.ProcessHardlinkCommand(hardlinkCmd)

	default:
		slog.Debug("Unknown command", "type", msg.Type)
	}
}

// parseWatchPaths parses a comma-separated list of watch paths.
func parseWatchPaths(s string) []string {
	if s == "" {
		return nil
	}
	parts := strings.Split(s, ",")
	result := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			result = append(result, p)
		}
	}
	return result
}
