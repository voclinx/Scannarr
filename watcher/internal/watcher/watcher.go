package watcher

import (
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/fsnotify/fsnotify"
	"github.com/voclinx/scanarr-watcher/internal/filter"
	"github.com/voclinx/scanarr-watcher/internal/hardlink"
	"github.com/voclinx/scanarr-watcher/internal/models"
	"github.com/voclinx/scanarr-watcher/internal/websocket"
)

// FileWatcher watches directories for filesystem changes using fsnotify.
type FileWatcher struct {
	fsWatcher *fsnotify.Watcher
	wsClient  *websocket.Client
	paths     []string

	// Debounce: track recently seen events to avoid duplicates
	recentEvents map[string]time.Time
	mu           sync.Mutex
	debounceDur  time.Duration
}

// New creates a new FileWatcher.
func New(wsClient *websocket.Client, paths []string) (*FileWatcher, error) {
	fsw, err := fsnotify.NewWatcher()
	if err != nil {
		return nil, err
	}

	return &FileWatcher{
		fsWatcher:    fsw,
		wsClient:     wsClient,
		paths:        paths,
		recentEvents: make(map[string]time.Time),
		debounceDur:  500 * time.Millisecond,
	}, nil
}

// Start begins watching all configured paths.
func (w *FileWatcher) Start() error {
	for _, path := range w.paths {
		if err := w.addRecursive(path); err != nil {
			slog.Warn("Failed to watch path", "path", path, "error", err)
		}
	}

	go w.eventLoop()
	go w.cleanupLoop()

	slog.Info("FileWatcher started", "paths", w.paths)
	return nil
}

// AddPath adds a new path to watch.
func (w *FileWatcher) AddPath(path string) error {
	w.paths = append(w.paths, path)
	return w.addRecursive(path)
}

// RemovePath removes a path from watching.
func (w *FileWatcher) RemovePath(path string) error {
	_ = w.fsWatcher.Remove(path)
	newPaths := make([]string, 0, len(w.paths))
	for _, p := range w.paths {
		if p != path {
			newPaths = append(newPaths, p)
		}
	}
	w.paths = newPaths
	return nil
}

// GetWatchedPaths returns the currently watched paths.
func (w *FileWatcher) GetWatchedPaths() []string {
	return w.paths
}

// Close stops the watcher.
func (w *FileWatcher) Close() error {
	return w.fsWatcher.Close()
}

func (w *FileWatcher) addRecursive(root string) error {
	return filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return nil
		}
		if info.IsDir() {
			if filter.IsIgnoredDir(path) {
				return filepath.SkipDir
			}
			if err := w.fsWatcher.Add(path); err != nil {
				slog.Warn("Failed to add directory to watcher", "path", path, "error", err)
			}
		}
		return nil
	})
}

func (w *FileWatcher) eventLoop() {
	// Track renames: fsnotify sends Rename then Create for a move
	var lastRenamePath string

	for {
		select {
		case event, ok := <-w.fsWatcher.Events:
			if !ok {
				return
			}
			w.handleEvent(event, &lastRenamePath)

		case err, ok := <-w.fsWatcher.Errors:
			if !ok {
				return
			}
			slog.Error("Watcher error", "error", err)
		}
	}
}

func (w *FileWatcher) handleEvent(event fsnotify.Event, lastRenamePath *string) {
	path := event.Name

	// Check if it's a directory event
	info, statErr := os.Stat(path)
	isDir := statErr == nil && info.IsDir()

	// If a new directory is created, add it to the watcher
	if isDir && event.Has(fsnotify.Create) {
		if !filter.IsIgnoredDir(path) {
			_ = w.addRecursive(path)
		}
		return
	}

	// Only process media files
	if !isDir && !filter.ShouldProcess(path) {
		return
	}

	// Debounce: skip if we've seen this exact event recently
	eventKey := event.Op.String() + ":" + path
	if w.isDuplicate(eventKey) {
		return
	}

	switch {
	case event.Has(fsnotify.Create):
		w.mu.Lock()
		rename := *lastRenamePath
		if rename != "" {
			// This is the second part of a rename (old Rename + new Create)
			*lastRenamePath = ""
			w.mu.Unlock()
			w.handleRename(rename, path)
			return
		}
		w.mu.Unlock()
		w.handleCreate(path)

	case event.Has(fsnotify.Remove):
		w.handleDelete(path)

	case event.Has(fsnotify.Rename):
		w.mu.Lock()
		*lastRenamePath = path
		w.mu.Unlock()
		// Wait briefly for the Create event that follows a rename
		go func(oldPath string) {
			time.Sleep(100 * time.Millisecond)
			w.mu.Lock()
			if *lastRenamePath == oldPath {
				// No Create followed — treat as delete
				*lastRenamePath = ""
				w.mu.Unlock()
				w.handleDelete(oldPath)
			} else {
				w.mu.Unlock()
			}
		}(path)

	case event.Has(fsnotify.Write):
		w.handleModified(path)
	}
}

func (w *FileWatcher) handleCreate(path string) {
	info, err := os.Stat(path)
	if err != nil {
		return
	}

	fileInfo, _ := hardlink.Info(path)
	if fileInfo.Nlink == 0 {
		fileInfo.Nlink = 1
	}

	slog.Info("File created", "path", path)
	w.wsClient.SendEvent("file.created", models.FileCreatedData{
		Path:          path,
		Name:          filepath.Base(path),
		SizeBytes:     info.Size(),
		HardlinkCount: fileInfo.Nlink,
		Inode:         fileInfo.Inode,
		DeviceID:      fileInfo.DeviceID,
		IsDir:         false,
	})
}

func (w *FileWatcher) handleDelete(path string) {
	slog.Info("File deleted", "path", path)
	w.wsClient.SendEvent("file.deleted", models.FileDeletedData{
		Path: path,
		Name: filepath.Base(path),
	})
}

func (w *FileWatcher) handleRename(oldPath, newPath string) {
	info, err := os.Stat(newPath)
	if err != nil {
		// New path doesn't exist — treat as delete of old path
		w.handleDelete(oldPath)
		return
	}

	fileInfo, _ := hardlink.Info(newPath)
	if fileInfo.Nlink == 0 {
		fileInfo.Nlink = 1
	}

	slog.Info("File renamed", "old_path", oldPath, "new_path", newPath)
	w.wsClient.SendEvent("file.renamed", models.FileRenamedData{
		OldPath:       oldPath,
		NewPath:       newPath,
		Name:          filepath.Base(newPath),
		SizeBytes:     info.Size(),
		HardlinkCount: fileInfo.Nlink,
		Inode:         fileInfo.Inode,
		DeviceID:      fileInfo.DeviceID,
	})
}

func (w *FileWatcher) handleModified(path string) {
	info, err := os.Stat(path)
	if err != nil {
		return
	}

	fileInfo, _ := hardlink.Info(path)
	if fileInfo.Nlink == 0 {
		fileInfo.Nlink = 1
	}

	slog.Info("File modified", "path", path)
	w.wsClient.SendEvent("file.modified", models.FileModifiedData{
		Path:          path,
		Name:          filepath.Base(path),
		SizeBytes:     info.Size(),
		HardlinkCount: fileInfo.Nlink,
		Inode:         fileInfo.Inode,
		DeviceID:      fileInfo.DeviceID,
	})
}

func (w *FileWatcher) isDuplicate(key string) bool {
	w.mu.Lock()
	defer w.mu.Unlock()

	if lastSeen, ok := w.recentEvents[key]; ok {
		if time.Since(lastSeen) < w.debounceDur {
			return true
		}
	}
	w.recentEvents[key] = time.Now()
	return false
}

func (w *FileWatcher) cleanupLoop() {
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	for range ticker.C {
		w.mu.Lock()
		now := time.Now()
		for key, ts := range w.recentEvents {
			if now.Sub(ts) > 5*time.Second {
				delete(w.recentEvents, key)
			}
		}
		w.mu.Unlock()
	}
}
