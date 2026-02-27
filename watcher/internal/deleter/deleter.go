package deleter

import (
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"

	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

// Deleter handles physical file deletion commands from the API.
type Deleter struct {
	wsClient *websocket.Client
}

// New creates a new Deleter instance.
func New(wsClient *websocket.Client) *Deleter {
	return &Deleter{wsClient: wsClient}
}

// ProcessDeleteCommand processes a command.files.delete from the API.
// For each file: delete it, cleanup empty parent dirs, report progress.
// At the end, send a summary completion message.
func (d *Deleter) ProcessDeleteCommand(cmd models.CommandFilesDeleteData) {
	totalDeleted := 0
	totalFailed := 0
	totalDirsRemoved := 0
	var results []models.FilesDeleteResultItem

	for _, file := range cmd.Files {
		result := d.deleteFile(file)
		results = append(results, result)

		if result.Status == "deleted" {
			totalDeleted++
		} else {
			totalFailed++
		}
		totalDirsRemoved += result.DirsRemoved

		// Send per-file progress
		d.wsClient.SendEvent("files.delete.progress", models.FilesDeleteProgressData{
			RequestID:   cmd.RequestID,
			DeletionID:  cmd.DeletionID,
			MediaFileID: result.MediaFileID,
			Status:      result.Status,
			Error:       result.Error,
			DirsRemoved: result.DirsRemoved,
		})
	}

	// Send completion summary
	d.wsClient.SendEvent("files.delete.completed", models.FilesDeleteCompletedData{
		RequestID:   cmd.RequestID,
		DeletionID:  cmd.DeletionID,
		Total:       len(cmd.Files),
		Deleted:     totalDeleted,
		Failed:      totalFailed,
		DirsRemoved: totalDirsRemoved,
		Results:     results,
	})

	slog.Info("Delete command completed",
		"request_id", cmd.RequestID,
		"deletion_id", cmd.DeletionID,
		"total", len(cmd.Files),
		"deleted", totalDeleted,
		"failed", totalFailed,
		"dirs_removed", totalDirsRemoved,
	)
}

// deleteFile deletes a single file and cleans up empty parent directories.
func (d *Deleter) deleteFile(file models.FileDeleteRequest) models.FilesDeleteResultItem {
	result := models.FilesDeleteResultItem{
		MediaFileID: file.MediaFileID,
		Status:      "deleted",
	}

	absolutePath := filepath.Clean(filepath.Join(file.VolumePath, file.FilePath))
	volumeRoot := filepath.Clean(file.VolumePath)

	// Security: ensure the resolved path is strictly under the volume root.
	// Prevents path traversal attacks via ../../ in file_path.
	if !strings.HasPrefix(absolutePath, volumeRoot+string(filepath.Separator)) {
		result.Status = "failed"
		result.Error = "path traversal detected: resolved path is outside volume root"
		slog.Error("Path traversal blocked",
			"volume_root", volumeRoot,
			"resolved_path", absolutePath,
			"file_path", file.FilePath,
		)
		return result
	}

	// Get file size before deletion
	if info, err := os.Stat(absolutePath); err == nil {
		result.SizeBytes = info.Size()
	}

	// Delete the file
	if err := os.Remove(absolutePath); err != nil && !os.IsNotExist(err) {
		result.Status = "failed"
		result.Error = err.Error()
		slog.Error("Failed to delete file", "path", absolutePath, "error", err)
		return result
	}

	if os.IsNotExist(nil) {
		// This won't execute, but for clarity: os.IsNotExist means file was already gone = success
	}

	slog.Info("File deleted", "path", absolutePath)

	// Cleanup empty parent directories
	result.DirsRemoved = d.cleanupEmptyDirs(absolutePath, volumeRoot)

	return result
}

// CreateHardlink creates a hardlink from source to target.
// Both source and target must resolve to paths under volumeRoot (anti path traversal).
func (d *Deleter) CreateHardlink(source, target, volumeRoot string) models.HardlinkResult {
	result := models.HardlinkResult{
		SourcePath: source,
		TargetPath: target,
		Status:     "created",
	}

	cleanSource := filepath.Clean(source)
	cleanTarget := filepath.Clean(target)
	cleanRoot := filepath.Clean(volumeRoot)
	sep := string(filepath.Separator)

	// Security: both paths must be strictly under volumeRoot
	if !strings.HasPrefix(cleanSource, cleanRoot+sep) {
		result.Status = "failed"
		result.Error = "path traversal detected: source path is outside volume root"
		return result
	}
	if !strings.HasPrefix(cleanTarget, cleanRoot+sep) {
		result.Status = "failed"
		result.Error = "path traversal detected: target path is outside volume root"
		return result
	}

	// Verify source exists
	if _, err := os.Stat(cleanSource); err != nil {
		result.Status = "failed"
		result.Error = fmt.Sprintf("source file not found: %s", err)
		return result
	}

	// Create parent directories of target
	if err := os.MkdirAll(filepath.Dir(cleanTarget), 0o755); err != nil {
		result.Status = "failed"
		result.Error = fmt.Sprintf("failed to create target directory: %s", err)
		return result
	}

	// Remove target if it already exists (replace)
	_ = os.Remove(cleanTarget) // ignore error — file may not exist

	// Create hardlink
	if err := os.Link(cleanSource, cleanTarget); err != nil {
		result.Status = "failed"
		result.Error = fmt.Sprintf("hardlink creation failed: %s", err)
		slog.Error("Hardlink creation failed", "source", cleanSource, "target", cleanTarget, "error", err)
		return result
	}

	slog.Info("Hardlink created", "source", cleanSource, "target", cleanTarget)
	return result
}

// ProcessHardlinkCommand handles a command.files.hardlink from the API.
func (d *Deleter) ProcessHardlinkCommand(cmd models.CommandFilesHardlinkData) {
	result := d.CreateHardlink(cmd.SourcePath, cmd.TargetPath, cmd.VolumePath)
	d.wsClient.SendEvent("files.hardlink.completed", models.FilesHardlinkCompletedData{
		RequestID:  cmd.RequestID,
		DeletionID: cmd.DeletionID,
		Status:     result.Status,
		SourcePath: result.SourcePath,
		TargetPath: result.TargetPath,
		Error:      result.Error,
	})
}

// cleanupEmptyDirs walks up from the parent of filePath to volumeRoot,
// removing each empty directory. Never removes volumeRoot itself.
func (d *Deleter) cleanupEmptyDirs(filePath string, volumeRoot string) int {
	removed := 0
	dir := filepath.Dir(filePath)

	for dir != volumeRoot && strings.HasPrefix(dir, volumeRoot) {
		entries, err := os.ReadDir(dir)
		if err != nil || len(entries) > 0 {
			break
		}
		if err := os.Remove(dir); err != nil {
			slog.Warn("Failed to remove empty dir", "path", dir, "error", err)
			break
		}
		slog.Info("Removed empty directory", "path", dir)
		removed++
		dir = filepath.Dir(dir)
	}
	return removed
}
