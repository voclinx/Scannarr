package filter

import (
	"path/filepath"
	"strings"
)

// Allowed video extensions.
var mediaExtensions = map[string]bool{
	".mkv": true,
	".mp4": true,
	".avi": true,
	".m4v": true,
	".wmv": true,
	".ts":  true,
	".iso": true,
}

// Temporary file extensions to ignore.
var tempExtensions = map[string]bool{
	".part":     true,
	".tmp":      true,
	".download": true,
	".!qb":      true,
}

// System directories to ignore.
var ignoredDirs = map[string]bool{
	"@eaDir":                     true,
	"$RECYCLE.BIN":               true,
	"System Volume Information":  true,
}

// IsMediaFile returns true if the file has a recognized media extension.
func IsMediaFile(name string) bool {
	ext := strings.ToLower(filepath.Ext(name))
	return mediaExtensions[ext]
}

// IsTempFile returns true if the file has a temporary extension.
func IsTempFile(name string) bool {
	ext := strings.ToLower(filepath.Ext(name))
	return tempExtensions[ext]
}

// IsHiddenFile returns true if the file name starts with a dot.
func IsHiddenFile(name string) bool {
	base := filepath.Base(name)
	return len(base) > 0 && base[0] == '.'
}

// IsIgnoredDir returns true if the directory should be ignored.
func IsIgnoredDir(name string) bool {
	base := filepath.Base(name)
	if ignoredDirs[base] {
		return true
	}
	// .Trash-* directories
	if strings.HasPrefix(base, ".Trash-") {
		return true
	}
	return false
}

// ShouldProcess returns true if the file should be processed by the watcher.
func ShouldProcess(path string) bool {
	name := filepath.Base(path)

	if IsHiddenFile(name) {
		return false
	}
	if IsTempFile(name) {
		return false
	}
	if !IsMediaFile(name) {
		return false
	}
	return true
}
