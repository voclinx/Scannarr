package state

import (
	"os"
	"path/filepath"
	"testing"
)

func TestSaveAndLoad(t *testing.T) {
	// Use a temp file for tests
	tmp := filepath.Join(t.TempDir(), "watcher-state.json")
	t.Setenv("SCANARR_STATE_PATH", tmp)

	s := &State{
		AuthToken:  "test-token-abc",
		ConfigHash: "abc123",
		Config:     map[string]interface{}{"log_level": "info"},
	}

	if err := Save(s); err != nil {
		t.Fatalf("Save() error: %v", err)
	}

	loaded, err := Load()
	if err != nil {
		t.Fatalf("Load() error: %v", err)
	}

	if loaded.AuthToken != "test-token-abc" {
		t.Errorf("AuthToken = %q, want %q", loaded.AuthToken, "test-token-abc")
	}
	if loaded.ConfigHash != "abc123" {
		t.Errorf("ConfigHash = %q, want %q", loaded.ConfigHash, "abc123")
	}
	if loaded.Config["log_level"] != "info" {
		t.Errorf("Config[log_level] = %v, want %q", loaded.Config["log_level"], "info")
	}
}

func TestLoadMissing(t *testing.T) {
	// Point to a file that doesn't exist
	tmp := filepath.Join(t.TempDir(), "nonexistent.json")
	t.Setenv("SCANARR_STATE_PATH", tmp)

	s, err := Load()
	if err != nil {
		t.Fatalf("Load() with missing file should not error, got: %v", err)
	}
	if s == nil {
		t.Fatal("Load() with missing file returned nil")
	}
	if s.AuthToken != "" {
		t.Errorf("AuthToken = %q, want empty", s.AuthToken)
	}
}

func TestLoadCorrupt(t *testing.T) {
	// Write invalid JSON to state file
	tmp := filepath.Join(t.TempDir(), "corrupt.json")
	t.Setenv("SCANARR_STATE_PATH", tmp)

	if err := os.WriteFile(tmp, []byte("{not valid json"), 0600); err != nil {
		t.Fatalf("failed to write corrupt file: %v", err)
	}

	s, err := Load()
	if err != nil {
		t.Fatalf("Load() with corrupt file should not error, got: %v", err)
	}
	if s == nil {
		t.Fatal("Load() with corrupt file returned nil")
	}
	// Should return empty state
	if s.AuthToken != "" {
		t.Errorf("AuthToken = %q, want empty for corrupt file", s.AuthToken)
	}
}

func TestClear(t *testing.T) {
	tmp := filepath.Join(t.TempDir(), "watcher-state.json")
	t.Setenv("SCANARR_STATE_PATH", tmp)

	// Save a state
	if err := Save(&State{AuthToken: "token"}); err != nil {
		t.Fatalf("Save() error: %v", err)
	}

	// Clear it
	if err := Clear(); err != nil {
		t.Fatalf("Clear() error: %v", err)
	}

	// Verify it's gone
	if _, err := os.Stat(tmp); !os.IsNotExist(err) {
		t.Error("state file should not exist after Clear()")
	}
}

func TestClearMissing(t *testing.T) {
	tmp := filepath.Join(t.TempDir(), "nonexistent.json")
	t.Setenv("SCANARR_STATE_PATH", tmp)

	// Clear on missing file should not error
	if err := Clear(); err != nil {
		t.Errorf("Clear() on missing file should not error, got: %v", err)
	}
}
