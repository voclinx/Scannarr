package scanner

import (
	"log/slog"
	"os"
	"path/filepath"
	"time"

	"github.com/voclinx/scanarr-watcher/internal/filter"
	"github.com/voclinx/scanarr-watcher/internal/hardlink"
	"github.com/voclinx/scanarr-watcher/internal/hash"
	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

// Scanner performs recursive directory scans and reports results via WebSocket.
type Scanner struct {
	wsClient *websocket.Client
}

// New creates a new Scanner.
func New(wsClient *websocket.Client) *Scanner {
	return &Scanner{wsClient: wsClient}
}

// Scan performs a recursive scan of the given path and sends results via WebSocket.
func (s *Scanner) Scan(path string, scanID string) error {
	slog.Info("Starting scan", "path", path, "scan_id", scanID)

	s.wsClient.SendEvent("scan.started", models.ScanStartedData{
		Path:   path,
		ScanID: scanID,
	})

	startTime := time.Now()
	totalFiles := 0
	totalDirs := 0
	var totalSize int64

	err := filepath.Walk(path, func(filePath string, info os.FileInfo, err error) error {
		if err != nil {
			slog.Warn("Error accessing path", "path", filePath, "error", err)
			return nil // continue scanning
		}

		if info.IsDir() {
			if filter.IsIgnoredDir(filePath) {
				return filepath.SkipDir
			}
			totalDirs++
			return nil
		}

		if !filter.ShouldProcess(filePath) {
			return nil
		}

		hlCount, hlErr := hardlink.Count(filePath)
		if hlErr != nil {
			hlCount = 1
		}

		// Calculate partial hash (graceful failure)
		partialHash, hashErr := hash.Calculate(filePath)
		if hashErr != nil {
			slog.Warn("Failed to calculate partial hash", "path", filePath, "error", hashErr)
			partialHash = ""
		}

		totalFiles++
		totalSize += info.Size()

		s.wsClient.SendEvent("scan.file", models.ScanFileData{
			ScanID:        scanID,
			Path:          filePath,
			Name:          info.Name(),
			SizeBytes:     info.Size(),
			HardlinkCount: hlCount,
			IsDir:         false,
			ModTime:       info.ModTime().UTC(),
			PartialHash:   partialHash,
		})

		// Send progress every 100 files
		if totalFiles%100 == 0 {
			s.wsClient.SendEvent("scan.progress", models.ScanProgressData{
				ScanID:       scanID,
				FilesScanned: totalFiles,
				DirsScanned:  totalDirs,
			})
		}

		return nil
	})

	duration := time.Since(startTime)

	s.wsClient.SendEvent("scan.completed", models.ScanCompletedData{
		ScanID:         scanID,
		Path:           path,
		TotalFiles:     totalFiles,
		TotalDirs:      totalDirs,
		TotalSizeBytes: totalSize,
		DurationMs:     duration.Milliseconds(),
	})

	slog.Info("Scan completed",
		"path", path,
		"scan_id", scanID,
		"total_files", totalFiles,
		"total_dirs", totalDirs,
		"duration_ms", duration.Milliseconds(),
	)

	return err
}
