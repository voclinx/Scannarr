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
	PartialHash   string `json:"partial_hash"`
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
	PartialHash   string `json:"partial_hash"`
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
	PartialHash   string    `json:"partial_hash"`
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
	WatcherID     string   `json:"watcher_id"`
	ConfigHash    string   `json:"config_hash"`
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

// ──────────────────────────────────────────────
// File deletion command and response models
// ──────────────────────────────────────────────

// CommandFilesDeleteData — command from API to watcher to delete files.
type CommandFilesDeleteData struct {
	RequestID  string              `json:"request_id"`
	DeletionID string              `json:"deletion_id"`
	Files      []FileDeleteRequest `json:"files"`
}

// FileDeleteRequest — a single file to delete.
type FileDeleteRequest struct {
	MediaFileID string `json:"media_file_id"`
	VolumePath  string `json:"volume_path"` // e.g. "/mnt/nas1"
	FilePath    string `json:"file_path"`   // relative to volume
}

// FilesDeleteProgressData — per-file progress response from watcher.
type FilesDeleteProgressData struct {
	RequestID   string `json:"request_id"`
	DeletionID  string `json:"deletion_id"`
	MediaFileID string `json:"media_file_id"`
	Status      string `json:"status"` // "deleted" or "failed"
	Error       string `json:"error,omitempty"`
	DirsRemoved int    `json:"dirs_removed"`
}

// FilesDeleteCompletedData — completion summary from watcher.
type FilesDeleteCompletedData struct {
	RequestID   string                  `json:"request_id"`
	DeletionID  string                  `json:"deletion_id"`
	Total       int                     `json:"total"`
	Deleted     int                     `json:"deleted"`
	Failed      int                     `json:"failed"`
	DirsRemoved int                     `json:"dirs_removed"`
	Results     []FilesDeleteResultItem `json:"results"`
}

// FilesDeleteResultItem — result for a single file deletion.
type FilesDeleteResultItem struct {
	MediaFileID string `json:"media_file_id"`
	Status      string `json:"status"`
	Error       string `json:"error,omitempty"`
	DirsRemoved int    `json:"dirs_removed"`
	SizeBytes   int64  `json:"size_bytes"`
}

// ──────────────────────────────────────────────
// Hardlink command and response models
// ──────────────────────────────────────────────

// CommandFilesHardlinkData — command from API to watcher to create a hardlink.
type CommandFilesHardlinkData struct {
	RequestID  string `json:"request_id"`
	DeletionID string `json:"deletion_id"`
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
	VolumePath string `json:"volume_path"`
}

// HardlinkResult — result of a single hardlink creation attempt.
type HardlinkResult struct {
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
	Status     string `json:"status"` // "created" or "failed"
	Error      string `json:"error,omitempty"`
}

// FilesHardlinkCompletedData — response from watcher after hardlink creation.
type FilesHardlinkCompletedData struct {
	RequestID  string `json:"request_id"`
	DeletionID string `json:"deletion_id"`
	Status     string `json:"status"` // "created" or "failed"
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
	Error      string `json:"error,omitempty"`
}

// ──────────────────────────────────────────────
// New protocol: watcher lifecycle messages (V1.5 Phase 5)
// ──────────────────────────────────────────────

// WatcherHelloData — sent by watcher on first connection.
type WatcherHelloData struct {
	WatcherID string `json:"watcher_id"`
	Hostname  string `json:"hostname"`
	Version   string `json:"version"`
}

// WatcherAuthData — sent by watcher to authenticate with its token.
type WatcherAuthData struct {
	Token string `json:"token"`
}

// WatcherConfigData — sent by the API to the watcher after authentication.
// Contains all runtime configuration fields.
type WatcherConfigData struct {
	WatchPaths             []string `json:"watch_paths"`
	ScanOnStart            bool     `json:"scan_on_start"`
	LogLevel               string   `json:"log_level"`
	DisableDeletion        bool     `json:"disable_deletion"`
	WsReconnectDelaySecs   int      `json:"ws_reconnect_delay_seconds"`
	WsPingIntervalSecs     int      `json:"ws_ping_interval_seconds"`
	LogRetentionDays       int      `json:"log_retention_days"`
	DebugLogRetentionHours int      `json:"debug_log_retention_hours"`
	ConfigHash             string   `json:"config_hash"`
	AuthToken              string   `json:"auth_token,omitempty"` // only set on initial approval
}

// WatcherConfigHashData — sent by the API to notify the watcher of a config change.
type WatcherConfigHashData struct {
	ConfigHash string `json:"config_hash"`
}

// WatcherLogData — sent by the watcher to forward a log entry to the API.
type WatcherLogData struct {
	Level     string                 `json:"level"`
	Message   string                 `json:"message"`
	Context   map[string]interface{} `json:"context,omitempty"`
	Timestamp string                 `json:"timestamp"`
}

// ──────────────────────────────────────────────
// Legacy auth (backward compat — kept until all watchers migrate)
// ──────────────────────────────────────────────

// AuthMessage is the legacy authentication message.
type AuthMessage struct {
	Type string        `json:"type"`
	Data AuthTokenData `json:"data"`
}

// AuthTokenData holds the legacy auth token.
type AuthTokenData struct {
	Token string `json:"token"`
}
