package deleter

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/voclinx/scanarr-watcher/internal/models"
)

// TestDeleteFilePathTraversalBlocked verifies that a file_path containing ../
// that resolves outside the volume root is rejected.
func TestDeleteFilePathTraversalBlocked(t *testing.T) {
	d := &Deleter{wsClient: nil} // wsClient not needed for deleteFile

	// Create a temp dir structure: volumeRoot/subdir/legit.txt
	volumeRoot := t.TempDir()
	subdir := filepath.Join(volumeRoot, "subdir")
	if err := os.MkdirAll(subdir, 0o755); err != nil {
		t.Fatal(err)
	}

	// Create a file OUTSIDE the volume root that the attacker wants to delete
	outsideDir := t.TempDir()
	outsideFile := filepath.Join(outsideDir, "secret.txt")
	if err := os.WriteFile(outsideFile, []byte("sensitive data"), 0o644); err != nil {
		t.Fatal(err)
	}

	// Craft a malicious file_path with ../../ to escape the volume root
	// e.g., volumeRoot = /tmp/vol, file_path = "../secret-dir/secret.txt"
	relativeEscape := filepath.Join("..", filepath.Base(outsideDir), "secret.txt")

	result := d.deleteFile(models.FileDeleteRequest{
		MediaFileID: "test-file-id",
		VolumePath:  volumeRoot,
		FilePath:    relativeEscape,
	})

	// Must be blocked
	if result.Status != "failed" {
		t.Errorf("expected status 'failed', got %q", result.Status)
	}
	if result.Error != "path traversal detected: resolved path is outside volume root" {
		t.Errorf("unexpected error message: %q", result.Error)
	}

	// The outside file must still exist (not deleted)
	if _, err := os.Stat(outsideFile); os.IsNotExist(err) {
		t.Error("outside file was deleted — path traversal was NOT blocked!")
	}
}

// TestDeleteFilePathTraversalWithDoubleSlash verifies VolumePath + "../../etc/passwd" style attacks.
func TestDeleteFilePathTraversalWithDoubleSlash(t *testing.T) {
	d := &Deleter{wsClient: nil}

	volumeRoot := t.TempDir()

	result := d.deleteFile(models.FileDeleteRequest{
		MediaFileID: "test-file-id",
		VolumePath:  volumeRoot,
		FilePath:    "../../etc/passwd",
	})

	if result.Status != "failed" {
		t.Errorf("expected status 'failed', got %q", result.Status)
	}
	if result.Error != "path traversal detected: resolved path is outside volume root" {
		t.Errorf("unexpected error message: %q", result.Error)
	}
}

// TestDeleteFileLegitimatePathSucceeds verifies that a normal file within
// the volume root can still be deleted.
func TestDeleteFileLegitimatePathSucceeds(t *testing.T) {
	d := &Deleter{wsClient: nil}

	volumeRoot := t.TempDir()
	movieDir := filepath.Join(volumeRoot, "Movie (2024)")
	if err := os.MkdirAll(movieDir, 0o755); err != nil {
		t.Fatal(err)
	}
	filePath := filepath.Join(movieDir, "movie.mkv")
	if err := os.WriteFile(filePath, []byte("fake content"), 0o644); err != nil {
		t.Fatal(err)
	}

	result := d.deleteFile(models.FileDeleteRequest{
		MediaFileID: "test-file-id",
		VolumePath:  volumeRoot,
		FilePath:    "Movie (2024)/movie.mkv",
	})

	if result.Status != "deleted" {
		t.Errorf("expected status 'deleted', got %q (error: %s)", result.Status, result.Error)
	}

	// File should be gone
	if _, err := os.Stat(filePath); !os.IsNotExist(err) {
		t.Error("file should have been deleted")
	}

	// Parent dir should have been cleaned up (it's empty now)
	if _, err := os.Stat(movieDir); !os.IsNotExist(err) {
		t.Error("empty parent directory should have been cleaned up")
	}

	// Volume root must still exist
	if _, err := os.Stat(volumeRoot); os.IsNotExist(err) {
		t.Error("volume root should NOT have been deleted")
	}
}

// TestDeleteFileSizeCaptured verifies that the file size is reported in the result.
func TestDeleteFileSizeCaptured(t *testing.T) {
	d := &Deleter{wsClient: nil}

	volumeRoot := t.TempDir()
	content := make([]byte, 4096)
	filePath := filepath.Join(volumeRoot, "sized.mkv")
	if err := os.WriteFile(filePath, content, 0o644); err != nil {
		t.Fatal(err)
	}

	result := d.deleteFile(models.FileDeleteRequest{
		MediaFileID: "test-file-id",
		VolumePath:  volumeRoot,
		FilePath:    "sized.mkv",
	})

	if result.Status != "deleted" {
		t.Fatalf("expected status 'deleted', got %q (error: %s)", result.Status, result.Error)
	}
	if result.SizeBytes != 4096 {
		t.Errorf("expected SizeBytes=4096, got %d", result.SizeBytes)
	}
}

// TestDeleteFileVolumeRootNotDeleted ensures cleanupEmptyDirs never removes the volume root.
func TestDeleteFileVolumeRootNotDeleted(t *testing.T) {
	d := &Deleter{wsClient: nil}

	volumeRoot := t.TempDir()
	// File directly in volumeRoot (no subdirectory)
	filePath := filepath.Join(volumeRoot, "direct.mkv")
	if err := os.WriteFile(filePath, []byte("data"), 0o644); err != nil {
		t.Fatal(err)
	}

	result := d.deleteFile(models.FileDeleteRequest{
		MediaFileID: "test-file-id",
		VolumePath:  volumeRoot,
		FilePath:    "direct.mkv",
	})

	if result.Status != "deleted" {
		t.Fatalf("expected status 'deleted', got %q", result.Status)
	}
	// Volume root must still exist
	if _, err := os.Stat(volumeRoot); os.IsNotExist(err) {
		t.Error("volume root was deleted — this must never happen")
	}
	// No directories should have been removed
	if result.DirsRemoved != 0 {
		t.Errorf("expected 0 dirs removed, got %d", result.DirsRemoved)
	}
}
