package hash

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
)

// Calculate computes the partial hash (first 1MB + last 1MB) of a file using SHA-256
func Calculate(filePath string) (string, error) {
	f, err := os.Open(filePath)
	if err != nil {
		return "", err
	}
	defer f.Close()

	stat, err := f.Stat()
	if err != nil {
		return "", err
	}

	h := sha256.New()
	buf := make([]byte, 1024*1024) // 1MB buffer

	// Read first 1MB
	n, err := f.Read(buf)
	if err != nil && err != io.EOF {
		return "", err
	}
	h.Write(buf[:n])

	// Read last 1MB (if file > 2MB)
	if stat.Size() > 2*1024*1024 {
		_, err = f.Seek(-1024*1024, io.SeekEnd)
		if err != nil {
			return "", err
		}
		n, err = f.Read(buf)
		if err != nil && err != io.EOF {
			return "", err
		}
		h.Write(buf[:n])
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}
