package models

import "time"

// Message is the base WebSocket message format.
type Message struct {
	Type      string      `json:"type"`
	Timestamp time.Time   `json:"timestamp"`
	Data      interface{} `json:"data"`
}

// FileCreatedData represents a file.created event.
type FileCreatedData struct {
	Path          string `json:"path"`
	Name          string `json:"name"`
	SizeBytes     int64  `json:"size_bytes"`
	HardlinkCount uint64 `json:"hardlink_count"`
	IsDir         bool   `json:"is_dir"`
}

// FileDeletedData represents a file.deleted event.
type FileDeletedData struct {
	Path string `json:"path"`
	Name string `json:"name"`
}

// FileRenamedData represents a file.renamed event.
type FileRenamedData struct {
	OldPath       string `json:"old_path"`
	NewPath       string `json:"new_path"`
	Name          string `json:"name"`
	SizeBytes     int64  `json:"size_bytes"`
	HardlinkCount uint64 `json:"hardlink_count"`
}

// FileModifiedData represents a file.modified event.
type FileModifiedData struct {
	Path          string `json:"path"`
	Name          string `json:"name"`
	SizeBytes     int64  `json:"size_bytes"`
	HardlinkCount uint64 `json:"hardlink_count"`
}

// ScanStartedData represents a scan.started event.
type ScanStartedData struct {
	Path   string `json:"path"`
	ScanID string `json:"scan_id"`
}

// ScanProgressData represents a scan.progress event.
type ScanProgressData struct {
	ScanID       string `json:"scan_id"`
	FilesScanned int    `json:"files_scanned"`
	DirsScanned  int    `json:"dirs_scanned"`
}

// ScanFileData represents a scan.file event.
type ScanFileData struct {
	ScanID        string    `json:"scan_id"`
	Path          string    `json:"path"`
	Name          string    `json:"name"`
	SizeBytes     int64     `json:"size_bytes"`
	HardlinkCount uint64    `json:"hardlink_count"`
	IsDir         bool      `json:"is_dir"`
	ModTime       time.Time `json:"mod_time"`
}

// ScanCompletedData represents a scan.completed event.
type ScanCompletedData struct {
	ScanID         string `json:"scan_id"`
	Path           string `json:"path"`
	TotalFiles     int    `json:"total_files"`
	TotalDirs      int    `json:"total_dirs"`
	TotalSizeBytes int64  `json:"total_size_bytes"`
	DurationMs     int64  `json:"duration_ms"`
}

// WatcherStatusData represents a watcher.status event.
type WatcherStatusData struct {
	Status        string   `json:"status"`
	WatchedPaths  []string `json:"watched_paths"`
	UptimeSeconds int64    `json:"uptime_seconds"`
}

// CommandScanData represents a command.scan message from the API.
type CommandScanData struct {
	Path   string `json:"path"`
	ScanID string `json:"scan_id"`
}

// CommandWatchData represents a command.watch.add or command.watch.remove message.
type CommandWatchData struct {
	Path string `json:"path"`
}

// AuthMessage is the initial authentication message.
type AuthMessage struct {
	Type string        `json:"type"`
	Data AuthTokenData `json:"data"`
}

// AuthTokenData holds the auth token.
type AuthTokenData struct {
	Token string `json:"token"`
}
