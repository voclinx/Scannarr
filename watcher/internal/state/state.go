package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
)

const defaultStatePath = "/etc/scanarr/watcher-state.json"

// State holds the persisted watcher state (auth token + cached config).
type State struct {
	AuthToken  string                 `json:"auth_token,omitempty"`
	ConfigHash string                 `json:"config_hash,omitempty"`
	Config     map[string]interface{} `json:"config,omitempty"`
}

// statePath returns the path to the state file, using SCANARR_STATE_PATH if set.
func statePath() string {
	if p := os.Getenv("SCANARR_STATE_PATH"); p != "" {
		return p
	}
	return defaultStatePath
}

// Save persists the state to disk with 0600 permissions.
func Save(s *State) error {
	p := statePath()

	// Ensure parent directory exists (0700)
	if err := os.MkdirAll(filepath.Dir(p), 0700); err != nil {
		return err
	}

	data, err := json.Marshal(s)
	if err != nil {
		return err
	}

	return os.WriteFile(p, data, 0600)
}

// Load reads the state from disk. Returns an empty State if the file doesn't exist.
func Load() (*State, error) {
	p := statePath()

	data, err := os.ReadFile(p)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return &State{}, nil
		}
		return nil, err
	}

	var s State
	if err := json.Unmarshal(data, &s); err != nil {
		// Corrupt state file — return empty state (not a fatal error)
		return &State{}, nil
	}

	return &s, nil
}

// Clear removes the state file (called on token rejection).
func Clear() error {
	p := statePath()
	err := os.Remove(p)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}
