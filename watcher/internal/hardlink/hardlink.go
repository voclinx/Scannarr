package hardlink

import "syscall"

// Count returns the number of hardlinks for the given file path.
func Count(path string) (uint64, error) {
	var stat syscall.Stat_t
	err := syscall.Stat(path, &stat)
	if err != nil {
		return 0, err
	}
	return stat.Nlink, nil
}
