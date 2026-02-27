package main

import (
	"encoding/json"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/google/uuid"
	"github.com/voclinx/scanarr-watcher/internal/config"
	"github.com/voclinx/scanarr-watcher/internal/deleter"
	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/scanner"
	"github.com/voclinx/scanarr-watcher/internal/watcher"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

func main() {
	// Load config
	cfg, err := config.Load()
	if err != nil {
		slog.Error("Failed to load config", "error", err)
		os.Exit(1)
	}

	// Setup structured logging
	setupLogger(cfg.LogLevel)

	slog.Info("Scanarr Watcher starting",
		"ws_url", cfg.WsURL,
		"watch_paths", cfg.WatchPaths,
		"scan_on_start", cfg.ScanOnStart,
	)

	// Create WebSocket client
	wsClient := websocket.NewClient(cfg.WsURL, cfg.AuthToken, cfg.WsReconnectDelay, cfg.WsPingInterval)

	// Create scanner
	fileScanner := scanner.New(wsClient)

	// Create file watcher
	fileWatcher, err := watcher.New(wsClient, cfg.WatchPaths)
	if err != nil {
		slog.Error("Failed to create file watcher", "error", err)
		os.Exit(1)
	}

	// Create file deleter
	fileDeleter := deleter.New(wsClient)

	// Handle commands from API
	startTime := time.Now()
	wsClient.OnCommand = func(msg models.Message) {
		handleCommand(msg, fileScanner, fileWatcher, fileDeleter)
	}

	// Handle reconnection with dropped events — trigger a full resync scan
	wsClient.OnReconnect = func() {
		slog.Info("Resync scan triggered after reconnection with dropped events")
		// Wait briefly for the connection to stabilize
		time.Sleep(2 * time.Second)
		for _, path := range cfg.WatchPaths {
			scanID := uuid.New().String()
			slog.Info("Resync scanning path", "path", path, "scan_id", scanID)
			if err := fileScanner.Scan(path, scanID); err != nil {
				slog.Error("Resync scan failed", "path", path, "error", err)
			}
		}
	}

	// Connect to WebSocket (with retry)
	wsClient.ConnectWithRetry()

	// Start filesystem watcher
	if err := fileWatcher.Start(); err != nil {
		slog.Error("Failed to start file watcher", "error", err)
		os.Exit(1)
	}

	// Run initial scan if configured
	if cfg.ScanOnStart {
		go func() {
			// Wait for WebSocket to be connected
			time.Sleep(2 * time.Second)
			for _, path := range cfg.WatchPaths {
				scanID := uuid.New().String()
				if err := fileScanner.Scan(path, scanID); err != nil {
					slog.Error("Initial scan failed", "path", path, "error", err)
				}
			}
		}()
	}

	// Send periodic status
	go func() {
		ticker := time.NewTicker(60 * time.Second)
		defer ticker.Stop()
		for range ticker.C {
			wsClient.SendEvent("watcher.status", models.WatcherStatusData{
				Status:        "watching",
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

func handleCommand(msg models.Message, fileScanner *scanner.Scanner, fileWatcher *watcher.FileWatcher, fileDeleter *deleter.Deleter) {
	switch msg.Type {
	case "command.scan":
		// Parse command data
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

	default:
		slog.Debug("Unknown command", "type", msg.Type)
	}
}

func setupLogger(level string) {
	var logLevel slog.Level
	switch level {
	case "debug":
		logLevel = slog.LevelDebug
	case "warn":
		logLevel = slog.LevelWarn
	case "error":
		logLevel = slog.LevelError
	default:
		logLevel = slog.LevelInfo
	}

	handler := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: logLevel})
	slog.SetDefault(slog.New(handler))
}
