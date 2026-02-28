# Scanarr — Watcher Go

> **Prérequis** : [ARCHITECTURE.md](ARCHITECTURE.md)
> **Version** : V2.0

---

## 7. Watcher Go

### 7.1 Configuration minimale (variables d'environnement)

En V2.0, le watcher ne nécessite que 2 variables d'environnement. Toute la configuration (volumes, extensions, etc.) est récupérée dynamiquement depuis l'API via le protocole hello/auth/config.

```env
SCANARR_API_URL=ws://localhost:8081/ws/watcher
SCANARR_AUTH_TOKEN=watcher-unique-token-abc123
```

Optionnel :

```env
SCANARR_RECONNECT_DELAY=5s
SCANARR_PING_INTERVAL=30s
SCANARR_LOG_LEVEL=info
SCANARR_STATE_FILE=/var/lib/scanarr/watcher-state.json
```

### 7.2 Protocole de connexion (hello/auth/config)

Au démarrage, le watcher initie un handshake en 3 étapes :

**Étape 1 — Hello (Watcher → API)**

```json
{
  "type": "watcher.hello",
  "data": {
    "hostname": "nas-principal",
    "version": "2.0.0",
    "os": "linux",
    "arch": "amd64"
  }
}
```

**Étape 2 — Auth (API → Watcher)**

L'API identifie le watcher par son token (envoyé dans le header WebSocket ou le premier message). Réponse :

```json
{
  "type": "watcher.auth",
  "data": {
    "status": "authenticated",
    "watcher_id": "uuid-watcher",
    "watcher_name": "Watcher NAS Principal"
  }
}
```

Si le token est invalide :

```json
{
  "type": "watcher.auth",
  "data": {
    "status": "rejected",
    "reason": "invalid_token"
  }
}
```

**Étape 3 — Config (API → Watcher)**

Immédiatement après l'auth réussie, l'API envoie la configuration :

```json
{
  "type": "watcher.config",
  "data": {
    "watch_paths": [
      {"id": "uuid-vol-1", "path": "/volume1/filmarr/media/movies", "name": "Films HD"},
      {"id": "uuid-vol-2", "path": "/volume1/filmarr/torrents/movies", "name": "Torrents HD"},
      {"id": "uuid-vol-3", "path": "/volume1/filmarr/links", "name": "Cross-seed"}
    ],
    "scan_extensions": ["mkv", "mp4", "avi", "m4v", "ts", "wmv"],
    "scan_on_start": true,
    "disable_deletion": false
  }
}
```

Le watcher applique cette config et démarre la surveillance. Si la config change côté API (ajout/suppression de volume, modification des extensions), l'API renvoie un `watcher.config` et le watcher se reconfigure à chaud.

### 7.3 Persistance d'état

Le watcher persiste son état dans un fichier JSON local pour survivre aux redémarrages :

```json
{
  "last_config": { ... },
  "last_scan_timestamps": {
    "uuid-vol-1": "2026-02-28T10:00:00Z",
    "uuid-vol-2": "2026-02-28T10:00:00Z"
  }
}
```

Si l'API est indisponible au démarrage, le watcher utilise la dernière config connue et tente de se reconnecter en boucle.

### 7.4 Messages WebSocket (Watcher → API)

Tous les messages suivent ce format :

```json
{
  "type": "event_type",
  "timestamp": "2026-02-24T15:30:00Z",
  "data": { ... }
}
```

#### `scan.file` — fichier trouvé pendant le scan

**V2.0 : inclut inode et device_id**

```json
{
  "type": "scan.file",
  "timestamp": "2026-02-24T15:34:05Z",
  "data": {
    "scan_id": "uuid",
    "volume_id": "uuid-vol-1",
    "volume_path": "/volume1/filmarr/media/movies",
    "relative_path": "Inception/Inception.2010.2160p.BluRay.x265.mkv",
    "filename": "Inception.2010.2160p.BluRay.x265.mkv",
    "inode": 12345,
    "device_id": 2049,
    "size_bytes": 50000000000,
    "hardlink_count": 3,
    "mod_time": "2026-01-15T10:20:00Z"
  }
}
```

#### `file.created` — nouveau fichier détecté (fsnotify)

```json
{
  "type": "file.created",
  "timestamp": "2026-02-24T15:30:00Z",
  "data": {
    "volume_id": "uuid-vol-1",
    "relative_path": "NewMovie/NewMovie.2026.2160p.mkv",
    "filename": "NewMovie.2026.2160p.mkv",
    "inode": 67890,
    "device_id": 2049,
    "size_bytes": 45000000000,
    "hardlink_count": 1
  }
}
```

#### `file.deleted` — fichier supprimé

```json
{
  "type": "file.deleted",
  "timestamp": "2026-02-24T15:31:00Z",
  "data": {
    "volume_id": "uuid-vol-1",
    "relative_path": "OldMovie/OldMovie.mkv",
    "filename": "OldMovie.mkv"
  }
}
```

#### `file.renamed` — fichier renommé/déplacé

```json
{
  "type": "file.renamed",
  "timestamp": "2026-02-24T15:32:00Z",
  "data": {
    "volume_id": "uuid-vol-1",
    "old_relative_path": "movie_old.mkv",
    "new_relative_path": "Movie (2024)/movie_new.mkv",
    "filename": "movie_new.mkv",
    "inode": 12345,
    "device_id": 2049,
    "size_bytes": 12000000000,
    "hardlink_count": 2
  }
}
```

#### `file.modified` — fichier modifié (taille changée)

```json
{
  "type": "file.modified",
  "timestamp": "2026-02-24T15:33:00Z",
  "data": {
    "volume_id": "uuid-vol-1",
    "relative_path": "movie.mkv",
    "filename": "movie.mkv",
    "inode": 12345,
    "device_id": 2049,
    "size_bytes": 12500000000,
    "hardlink_count": 2
  }
}
```

#### `scan.started` / `scan.progress` / `scan.completed`

```json
{
  "type": "scan.started",
  "data": {
    "scan_id": "uuid",
    "volume_id": "uuid-vol-1",
    "volume_path": "/volume1/filmarr/media/movies"
  }
}
```

```json
{
  "type": "scan.progress",
  "data": {
    "scan_id": "uuid",
    "files_scanned": 150,
    "dirs_scanned": 45
  }
}
```

```json
{
  "type": "scan.completed",
  "data": {
    "scan_id": "uuid",
    "volume_id": "uuid-vol-1",
    "total_files": 800,
    "total_dirs": 200,
    "total_size_bytes": 12000000000000,
    "duration_ms": 12500
  }
}
```

#### `watcher.status` — heartbeat

```json
{
  "type": "watcher.status",
  "timestamp": "2026-02-24T15:40:00Z",
  "data": {
    "status": "watching",
    "volumes": [
      {"id": "uuid-vol-1", "path": "/volume1/filmarr/media/movies", "status": "watching"},
      {"id": "uuid-vol-2", "path": "/volume1/filmarr/torrents/movies", "status": "watching"}
    ],
    "uptime_seconds": 3600
  }
}
```

### 7.5 Messages WebSocket (API → Watcher)

#### `command.scan` — demande de scan

```json
{
  "type": "command.scan",
  "data": {
    "volume_id": "uuid-vol-1",
    "scan_id": "uuid"
  }
}
```

#### `command.scan.all` — scan complet de tous les volumes

```json
{
  "type": "command.scan.all",
  "data": {
    "scan_id": "uuid"
  }
}
```

#### `watcher.config` — mise à jour de la config à chaud

```json
{
  "type": "watcher.config",
  "data": {
    "watch_paths": [ ... ],
    "scan_extensions": [ ... ],
    "scan_on_start": true,
    "disable_deletion": false
  }
}
```

Le watcher compare avec sa config actuelle et ajuste (ajoute/retire des watchers fsnotify).

#### `command.files.delete` — supprimer des fichiers

**V2.0 : les fichiers sont groupés par media_file (inode). Tous les file_paths connus sont envoyés.**

```json
{
  "type": "command.files.delete",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "files": [
      {
        "media_file_id": "uuid-mf-1",
        "paths": [
          {"volume_path": "/volume1/filmarr/media/movies", "relative_path": "Inception/Inception.mkv"},
          {"volume_path": "/volume1/filmarr/torrents/movies", "relative_path": "Inception.2010.2160p.x265-GRP.mkv"},
          {"volume_path": "/volume1/filmarr/links", "relative_path": "movies/Inception.2010.2160p.x265-GRP.mkv"}
        ]
      }
    ]
  }
}
```

Le watcher supprime chaque chemin et répond :

#### `files.delete.progress` / `files.delete.completed`

```json
{
  "type": "files.delete.progress",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "media_file_id": "uuid-mf-1",
    "paths_deleted": 3,
    "paths_failed": 0,
    "dirs_removed": 2
  }
}
```

```json
{
  "type": "files.delete.completed",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "total_media_files": 1,
    "deleted": 1,
    "failed": 0,
    "total_paths_deleted": 3,
    "dirs_removed": 2,
    "results": [
      {
        "media_file_id": "uuid-mf-1",
        "status": "deleted",
        "paths_deleted": 3,
        "dirs_removed": 2,
        "size_bytes": 50000000000
      }
    ]
  }
}
```

#### `command.files.hardlink` — créer un hardlink

(Inchangé par rapport à [HARDLINK_MANAGEMENT.md](HARDLINK_MANAGEMENT.md))

### 7.6 Architecture interne du Watcher

```go
// main.go
// 1. Charge la config minimale (env vars : API URL + token)
// 2. Charge l'état persisté (state file)
// 3. Connecte WebSocket → handshake hello/auth/config
// 4. Applique la config reçue (volumes, extensions)
// 5. Lance le scan initial si configuré
// 6. Boucle principale : fsnotify + commands WebSocket

// internal/config/config.go
type Config struct {
    ApiURL           string         // ws://...
    AuthToken        string
    ReconnectDelay   time.Duration
    PingInterval     time.Duration
    LogLevel         string
    StateFile        string         // persistance locale
}

type DynamicConfig struct {
    WatchPaths     []WatchPath
    ScanExtensions []string
    ScanOnStart    bool
    DisableDeletion bool
}

type WatchPath struct {
    ID   string
    Path string
    Name string
}

// internal/watcher/watcher.go
type FileWatcher struct {
    fsWatcher    *fsnotify.Watcher
    wsClient     *websocket.Client
    config       *DynamicConfig
    // Debounce, filtrage temporaires, etc.
}

// internal/scanner/scanner.go
type Scanner struct {
    wsClient   *websocket.Client
    extensions map[string]bool  // set d'extensions à scanner
}

// Scan envoie inode + device_id pour chaque fichier :
func (s *Scanner) scanFile(path string, volumeID string, volumePath string) {
    var stat syscall.Stat_t
    syscall.Stat(path, &stat)
    // stat.Ino = inode
    // stat.Dev = device_id
    // stat.Nlink = hardlink_count
    // stat.Size = file size
}

// internal/state/state.go
type State struct {
    LastConfig         *DynamicConfig
    LastScanTimestamps map[string]time.Time  // volume_id → timestamp
}
// Load(path) / Save(path)
```

### 7.7 Obtention de l'inode et du device_id

```go
import "syscall"

type FileInfo struct {
    Inode         uint64
    DeviceID      uint64
    HardlinkCount uint64
    Size          int64
}

func getFileInfo(path string) (FileInfo, error) {
    var stat syscall.Stat_t
    err := syscall.Stat(path, &stat)
    if err != nil {
        return FileInfo{}, err
    }
    return FileInfo{
        Inode:         stat.Ino,
        DeviceID:      uint64(stat.Dev),
        HardlinkCount: stat.Nlink,
        Size:          stat.Size,
    }, nil
}
```

> **Performance** : `syscall.Stat()` est un appel système instantané (pas de lecture de données). Même sur des milliers de fichiers, l'overhead est négligeable comparé au `partial_hash` V1.x qui lisait 2 MB par fichier.

### 7.8 Règles de filtrage

Le watcher ignore :

- Les fichiers temporaires : `*.part`, `*.tmp`, `*.download`, `*.!qB`
- Les fichiers cachés : commençant par `.`
- Les fichiers de métadonnées : `*.nfo`, `*.jpg`, `*.png`, `*.srt`, `*.sub`, `*.idx`
- Les dossiers système : `@eaDir`, `.Trash-*`, `$RECYCLE.BIN`, `System Volume Information`

Extensions médias configurables via l'API (défaut : `mkv`, `mp4`, `avi`, `m4v`, `ts`, `wmv`).

---
