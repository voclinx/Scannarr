package filter

import (
	"testing"
)

// TEST-GO-003: IsMediaFile detects media files; ShouldProcess returns true for valid media
func TestIsMediaFile(t *testing.T) {
	tests := []struct {
		name     string
		filename string
		want     bool
	}{
		{"mkv file", "movie.mkv", true},
		{"mp4 file", "video.mp4", true},
		{"avi file", "clip.avi", true},
		{"m4v file", "show.m4v", true},
		{"wmv file", "recording.wmv", true},
		{"ts file", "episode.ts", true},
		{"iso file", "disc.iso", true},
		{"srt subtitle", "movie.srt", false},
		{"nfo file", "movie.nfo", false},
		{"txt file", "readme.txt", false},
		{"jpg image", "poster.jpg", false},
		{"no extension", "noext", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := IsMediaFile(tt.filename)
			if got != tt.want {
				t.Errorf("IsMediaFile(%q) = %v, want %v", tt.filename, got, tt.want)
			}
		})
	}
}

// TEST-GO-003 (continued): ShouldProcess returns true for /path/movie.mkv
func TestShouldProcess_ValidMediaFile(t *testing.T) {
	tests := []struct {
		name string
		path string
		want bool
	}{
		{"mkv in subdir", "/mnt/media/Movies/movie.mkv", true},
		{"mp4 at root", "/videos/clip.mp4", true},
		{"avi nested", "/mnt/media/Shows/Season 1/episode.avi", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := ShouldProcess(tt.path)
			if got != tt.want {
				t.Errorf("ShouldProcess(%q) = %v, want %v", tt.path, got, tt.want)
			}
		})
	}
}

// TEST-GO-004: ShouldProcess returns true for deleted file detection (name-based filtering)
func TestShouldProcess_DeletedFileDetection(t *testing.T) {
	// When a file is deleted, the watcher receives the path.
	// The filter should validate based on the file name only.
	tests := []struct {
		name string
		path string
		want bool
	}{
		{"deleted mkv", "/mnt/media/Movies/deleted_movie.mkv", true},
		{"deleted mp4", "/mnt/media/deleted.mp4", true},
		{"deleted srt should be ignored", "/mnt/media/subtitle.srt", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := ShouldProcess(tt.path)
			if got != tt.want {
				t.Errorf("ShouldProcess(%q) = %v, want %v", tt.path, got, tt.want)
			}
		})
	}
}

// TEST-GO-005: File rename detection - filter validates new name
func TestShouldProcess_RenamedFile(t *testing.T) {
	tests := []struct {
		name    string
		newPath string
		want    bool
	}{
		{"renamed to mkv", "/mnt/media/new_name.mkv", true},
		{"renamed to mp4", "/mnt/media/renamed_movie.mp4", true},
		{"renamed to non-media", "/mnt/media/notes.txt", false},
		{"renamed to temp file", "/mnt/media/downloading.part", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := ShouldProcess(tt.newPath)
			if got != tt.want {
				t.Errorf("ShouldProcess(%q) = %v, want %v", tt.newPath, got, tt.want)
			}
		})
	}
}

// TEST-GO-006: IsTempFile returns true for temp extensions; ShouldProcess returns false
func TestIsTempFile(t *testing.T) {
	tests := []struct {
		name     string
		filename string
		wantTemp bool
	}{
		{".part file", "movie.part", true},
		{".tmp file", "video.tmp", true},
		{".download file", "file.download", true},
		{".!qb file (qBittorrent)", "torrent.!qb", true},
		{".mkv not temp", "movie.mkv", false},
		{".mp4 not temp", "video.mp4", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := IsTempFile(tt.filename)
			if got != tt.wantTemp {
				t.Errorf("IsTempFile(%q) = %v, want %v", tt.filename, got, tt.wantTemp)
			}
		})
	}

	// ShouldProcess must return false for temp files even if they have media-like names
	tempPaths := []string{
		"/mnt/media/movie.part",
		"/mnt/media/video.tmp",
		"/mnt/media/file.download",
		"/mnt/media/torrent.!qb",
	}
	for _, p := range tempPaths {
		t.Run("ShouldProcess_"+p, func(t *testing.T) {
			if ShouldProcess(p) {
				t.Errorf("ShouldProcess(%q) = true, want false (temp file)", p)
			}
		})
	}
}

// TEST-GO-007: IsHiddenFile returns true for dotfiles; ShouldProcess returns false
func TestIsHiddenFile(t *testing.T) {
	tests := []struct {
		name       string
		filename   string
		wantHidden bool
	}{
		{"hidden dotfile", ".hidden", true},
		{"hidden mkv", ".movie.mkv", true},
		{"hidden config", ".config", true},
		{"regular file", "movie.mkv", false},
		{"no leading dot", "visible.mp4", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := IsHiddenFile(tt.filename)
			if got != tt.wantHidden {
				t.Errorf("IsHiddenFile(%q) = %v, want %v", tt.filename, got, tt.wantHidden)
			}
		})
	}

	// ShouldProcess returns false for hidden media files
	t.Run("ShouldProcess hidden mkv", func(t *testing.T) {
		if ShouldProcess("/mnt/media/.hidden.mkv") {
			t.Error("ShouldProcess(/mnt/media/.hidden.mkv) = true, want false")
		}
	})
}

// TEST-GO-008: IsIgnoredDir returns true for system directories
func TestIsIgnoredDir(t *testing.T) {
	tests := []struct {
		name        string
		dirname     string
		wantIgnored bool
	}{
		{"@eaDir (Synology)", "@eaDir", true},
		{"$RECYCLE.BIN (Windows)", "$RECYCLE.BIN", true},
		{"System Volume Information", "System Volume Information", true},
		{".Trash-1000", ".Trash-1000", true},
		{".Trash-0", ".Trash-0", true},
		{".Trash-65534", ".Trash-65534", true},
		{"regular directory", "Movies", false},
		{"another regular", "Season 1", false},
		{"dot directory (not Trash)", ".config", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := IsIgnoredDir(tt.dirname)
			if got != tt.wantIgnored {
				t.Errorf("IsIgnoredDir(%q) = %v, want %v", tt.dirname, got, tt.wantIgnored)
			}
		})
	}
}

// TEST-GO-007 (additional): IsMediaFile is case-insensitive
func TestIsMediaFile_CaseInsensitive(t *testing.T) {
	tests := []struct {
		name     string
		filename string
		want     bool
	}{
		{"uppercase MKV", "movie.MKV", true},
		{"mixed case Mp4", "video.Mp4", true},
		{"uppercase AVI", "clip.AVI", true},
		{"mixed M4V", "show.M4v", true},
		{"uppercase ISO", "disc.ISO", true},
		{"uppercase TS", "episode.TS", true},
		{"uppercase WMV", "file.WMV", true},
		{"uppercase non-media", "readme.TXT", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := IsMediaFile(tt.filename)
			if got != tt.want {
				t.Errorf("IsMediaFile(%q) = %v, want %v", tt.filename, got, tt.want)
			}
		})
	}
}

// TEST-GO-008 (additional): ShouldProcess returns false for non-media files
func TestShouldProcess_NonMediaFiles(t *testing.T) {
	tests := []struct {
		name string
		path string
		want bool
	}{
		{"subtitle .srt", "/mnt/media/movie.srt", false},
		{"info .nfo", "/mnt/media/movie.nfo", false},
		{"text .txt", "/mnt/media/readme.txt", false},
		{"image .jpg", "/mnt/media/poster.jpg", false},
		{"image .png", "/mnt/media/fanart.png", false},
		{"archive .zip", "/mnt/media/backup.zip", false},
		{"log .log", "/mnt/media/scan.log", false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := ShouldProcess(tt.path)
			if got != tt.want {
				t.Errorf("ShouldProcess(%q) = %v, want %v", tt.path, got, tt.want)
			}
		})
	}
}
