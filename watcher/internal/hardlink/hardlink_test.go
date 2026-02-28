package hardlink_test

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/voclinx/scanarr-watcher/internal/hardlink"
)

func TestInfo_BasicFile(t *testing.T) {
	f, err := os.CreateTemp(t.TempDir(), "hardlink-test-*.txt")
	if err != nil {
		t.Fatalf("failed to create temp file: %v", err)
	}
	path := f.Name()
	f.Close()

	fi, err := hardlink.Info(path)
	if err != nil {
		t.Fatalf("Info() returned error: %v", err)
	}

	if fi.Inode == 0 {
		t.Error("expected Inode > 0")
	}
	if fi.DeviceID == 0 {
		t.Error("expected DeviceID > 0")
	}
	if fi.Nlink < 1 {
		t.Errorf("expected Nlink >= 1, got %d", fi.Nlink)
	}
}

func TestInfo_HardlinksSameInode(t *testing.T) {
	dir := t.TempDir()

	original := filepath.Join(dir, "original.txt")
	if err := os.WriteFile(original, []byte("data"), 0o644); err != nil {
		t.Fatalf("failed to create original file: %v", err)
	}

	linked := filepath.Join(dir, "linked.txt")
	if err := os.Link(original, linked); err != nil {
		t.Fatalf("failed to create hardlink: %v", err)
	}

	fiOriginal, err := hardlink.Info(original)
	if err != nil {
		t.Fatalf("Info(original) error: %v", err)
	}

	fiLinked, err := hardlink.Info(linked)
	if err != nil {
		t.Fatalf("Info(linked) error: %v", err)
	}

	if fiOriginal.Inode != fiLinked.Inode {
		t.Errorf("hardlinks must share inode: got %d vs %d", fiOriginal.Inode, fiLinked.Inode)
	}
	if fiOriginal.DeviceID != fiLinked.DeviceID {
		t.Errorf("hardlinks must share device_id: got %d vs %d", fiOriginal.DeviceID, fiLinked.DeviceID)
	}
	if fiOriginal.Nlink != 2 || fiLinked.Nlink != 2 {
		t.Errorf("expected Nlink=2 for both, got original=%d linked=%d", fiOriginal.Nlink, fiLinked.Nlink)
	}
}

func TestCount_BackwardCompat(t *testing.T) {
	f, err := os.CreateTemp(t.TempDir(), "count-test-*.txt")
	if err != nil {
		t.Fatalf("failed to create temp file: %v", err)
	}
	path := f.Name()
	f.Close()

	count, err := hardlink.Count(path)
	if err != nil {
		t.Fatalf("Count() returned error: %v", err)
	}
	if count < 1 {
		t.Errorf("expected Count >= 1, got %d", count)
	}
}

func TestInfo_NonExistentFile(t *testing.T) {
	_, err := hardlink.Info("/tmp/this-file-does-not-exist-scanarr-test")
	if err == nil {
		t.Error("expected error for non-existent file, got nil")
	}
}
