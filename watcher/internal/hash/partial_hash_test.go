package hash

import (
	"os"
	"path/filepath"
	"testing"
)

func TestCalculate_SmallFile(t *testing.T) {
	// Create temp file < 1MB
	tmpDir := t.TempDir()
	filePath := filepath.Join(tmpDir, "small.txt")

	content := make([]byte, 500*1024) // 500KB
	for i := range content {
		content[i] = byte(i % 256)
	}

	err := os.WriteFile(filePath, content, 0644)
	if err != nil {
		t.Fatalf("Failed to create test file: %v", err)
	}

	hash, err := Calculate(filePath)
	if err != nil {
		t.Fatalf("Calculate() failed: %v", err)
	}

	if hash == "" {
		t.Error("Expected non-empty hash")
	}

	if len(hash) != 64 { // SHA-256 produces 64 hex characters
		t.Errorf("Expected hash length 64, got %d", len(hash))
	}
}

func TestCalculate_MediumFile(t *testing.T) {
	// Create temp file between 1MB and 2MB
	tmpDir := t.TempDir()
	filePath := filepath.Join(tmpDir, "medium.txt")

	content := make([]byte, 1500*1024) // 1.5MB
	for i := range content {
		content[i] = byte(i % 256)
	}

	err := os.WriteFile(filePath, content, 0644)
	if err != nil {
		t.Fatalf("Failed to create test file: %v", err)
	}

	hash, err := Calculate(filePath)
	if err != nil {
		t.Fatalf("Calculate() failed: %v", err)
	}

	if hash == "" {
		t.Error("Expected non-empty hash")
	}

	if len(hash) != 64 {
		t.Errorf("Expected hash length 64, got %d", len(hash))
	}
}

func TestCalculate_LargeFile(t *testing.T) {
	// Create temp file > 2MB
	tmpDir := t.TempDir()
	filePath := filepath.Join(tmpDir, "large.txt")

	f, err := os.Create(filePath)
	if err != nil {
		t.Fatalf("Failed to create test file: %v", err)
	}
	defer f.Close()

	// Write 5MB of data
	chunk := make([]byte, 1024*1024) // 1MB chunk
	for i := 0; i < 5; i++ {
		for j := range chunk {
			chunk[j] = byte((i*len(chunk) + j) % 256)
		}
		_, err = f.Write(chunk)
		if err != nil {
			t.Fatalf("Failed to write to test file: %v", err)
		}
	}
	f.Close()

	hash, err := Calculate(filePath)
	if err != nil {
		t.Fatalf("Calculate() failed: %v", err)
	}

	if hash == "" {
		t.Error("Expected non-empty hash")
	}

	if len(hash) != 64 {
		t.Errorf("Expected hash length 64, got %d", len(hash))
	}
}

func TestCalculate_NonExistentFile(t *testing.T) {
	_, err := Calculate("/path/to/nonexistent/file.txt")
	if err == nil {
		t.Error("Expected error for non-existent file, got nil")
	}
}

func TestCalculate_Consistency(t *testing.T) {
	// Ensure the same file produces the same hash
	tmpDir := t.TempDir()
	filePath := filepath.Join(tmpDir, "consistent.txt")

	content := make([]byte, 3*1024*1024) // 3MB
	for i := range content {
		content[i] = byte(i % 256)
	}

	err := os.WriteFile(filePath, content, 0644)
	if err != nil {
		t.Fatalf("Failed to create test file: %v", err)
	}

	hash1, err := Calculate(filePath)
	if err != nil {
		t.Fatalf("First Calculate() failed: %v", err)
	}

	hash2, err := Calculate(filePath)
	if err != nil {
		t.Fatalf("Second Calculate() failed: %v", err)
	}

	if hash1 != hash2 {
		t.Errorf("Hashes don't match: %s != %s", hash1, hash2)
	}
}
