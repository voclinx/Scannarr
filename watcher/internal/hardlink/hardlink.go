package hardlink

import "syscall"

// FileInfo holds inode, device and hardlink count for a file.
type FileInfo struct {
	Nlink    uint64
	Inode    uint64
	DeviceID uint64
}

// Info returns the FileInfo (nlink, inode, device_id) for the given path.
func Info(path string) (FileInfo, error) {
	var stat syscall.Stat_t
	err := syscall.Stat(path, &stat)
	if err != nil {
		return FileInfo{}, err
	}
	return FileInfo{
		Nlink:    uint64(stat.Nlink),
		Inode:    stat.Ino,
		DeviceID: uint64(stat.Dev),
	}, nil
}

// Count returns the number of hardlinks for the given file path.
// Kept for backward compatibility — prefer Info() for new callers.
func Count(path string) (uint64, error) {
	fi, err := Info(path)
	if err != nil {
		return 0, err
	}
	return fi.Nlink, nil
}
