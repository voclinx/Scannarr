# Scanarr — Watcher Go

> **Prérequis** : [ARCHITECTURE.md](ARCHITECTURE.md)
> **Version** : V1.2.1

---

## 7. Watcher Go

### 7.1 Configuration (variables d'environnement)

```env
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WS_RECONNECT_DELAY=5s
SCANARR_WS_PING_INTERVAL=30s
SCANARR_WATCH_PATHS=/mnt/media/movies,/mnt/nas/movies4k
SCANARR_SCAN_ON_START=true
SCANARR_LOG_LEVEL=info
SCANARR_AUTH_TOKEN=secret-watcher-token
```

### 7.2 Messages WebSocket (Watcher → API)

Tous les messages suivent ce format :

```json
{
  "type": "event_type",
  "timestamp": "2026-02-24T15:30:00Z",
  "data": { ... }
}
```

#### Types de messages

**`file.created`** — nouveau fichier détecté

```json
{
  "type": "file.created",
  "timestamp": "2026-02-24T15:30:00Z",
  "data": {
    "path": "/mnt/volume1/Inception (2010)/Inception.2010.2160p.BluRay.x265.mkv",
    "name": "Inception.2010.2160p.BluRay.x265.mkv",
    "size_bytes": 45000000000,
    "hardlink_count": 1,
    "is_dir": false
  }
}
```

**`file.deleted`** — fichier supprimé

```json
{
  "type": "file.deleted",
  "timestamp": "2026-02-24T15:31:00Z",
  "data": {
    "path": "/mnt/volume1/OldMovie/OldMovie.mkv",
    "name": "OldMovie.mkv"
  }
}
```

**`file.renamed`** — fichier renommé/déplacé

```json
{
  "type": "file.renamed",
  "timestamp": "2026-02-24T15:32:00Z",
  "data": {
    "old_path": "/mnt/volume1/movie_old.mkv",
    "new_path": "/mnt/volume1/Movie (2024)/movie_new.mkv",
    "name": "movie_new.mkv",
    "size_bytes": 12000000000,
    "hardlink_count": 2
  }
}
```

**`file.modified`** — fichier modifié (taille changée)

```json
{
  "type": "file.modified",
  "timestamp": "2026-02-24T15:33:00Z",
  "data": {
    "path": "/mnt/volume1/movie.mkv",
    "name": "movie.mkv",
    "size_bytes": 12500000000,
    "hardlink_count": 2
  }
}
```

**`scan.started`** — début d'un scan

```json
{
  "type": "scan.started",
  "timestamp": "2026-02-24T15:34:00Z",
  "data": {
    "path": "/mnt/volume1",
    "scan_id": "uuid"
  }
}
```

**`scan.progress`** — progression du scan

```json
{
  "type": "scan.progress",
  "timestamp": "2026-02-24T15:34:05Z",
  "data": {
    "scan_id": "uuid",
    "files_scanned": 150,
    "dirs_scanned": 45
  }
}
```

**`scan.file`** — fichier trouvé pendant le scan

```json
{
  "type": "scan.file",
  "timestamp": "2026-02-24T15:34:05Z",
  "data": {
    "scan_id": "uuid",
    "path": "/mnt/volume1/Movie/movie.mkv",
    "name": "movie.mkv",
    "size_bytes": 12000000000,
    "hardlink_count": 2,
    "is_dir": false,
    "mod_time": "2026-01-15T10:20:00Z"
  }
}
```

**`scan.completed`** — fin du scan

```json
{
  "type": "scan.completed",
  "timestamp": "2026-02-24T15:35:00Z",
  "data": {
    "scan_id": "uuid",
    "path": "/mnt/volume1",
    "total_files": 800,
    "total_dirs": 200,
    "total_size_bytes": 12000000000000,
    "duration_ms": 12500
  }
}
```

**`watcher.status`** — heartbeat / status

```json
{
  "type": "watcher.status",
  "timestamp": "2026-02-24T15:40:00Z",
  "data": {
    "status": "watching",
    "watched_paths": ["/mnt/volume1", "/mnt/volume2"],
    "uptime_seconds": 3600
  }
}
```

### 7.3 Messages WebSocket (API → Watcher)

**`command.scan`** — demande de scan

```json
{
  "type": "command.scan",
  "data": {
    "path": "/mnt/volume1",
    "scan_id": "uuid"
  }
}
```

**`command.watch.add`** — ajouter un chemin à surveiller

```json
{
  "type": "command.watch.add",
  "data": {
    "path": "/mnt/volume3"
  }
}
```

**`command.watch.remove`** — retirer un chemin

```json
{
  "type": "command.watch.remove",
  "data": {
    "path": "/mnt/volume3"
  }
}
```

**`command.files.delete`** — supprimer des fichiers physiques

```json
{
  "type": "command.files.delete",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "files": [
      {
        "media_file_id": "uuid-file-1",
        "volume_path": "/mnt/nas1",
        "file_path": "Movies/Inception/Inception.2010.mkv"
      },
      {
        "media_file_id": "uuid-file-2",
        "volume_path": "/mnt/nas2",
        "file_path": "Movies/Inception/Inception.2010.mkv"
      }
    ]
  }
}
```

- `request_id` : UUID unique pour cette commande (traçabilité logs)
- `deletion_id` : UUID de la `ScheduledDeletion` en BDD (corrélation async)
- `volume_path` : chemin racine du volume sur le serveur du watcher (`volume.hostPath`, avec fallback sur `volume.path`)
- `file_path` : chemin relatif au volume (stocké dans `media_files.file_path`)

Le watcher reconstruit le chemin absolu : `filepath.Join(volume_path, file_path)`

**Réponses du watcher** (Watcher → API) :

**`files.delete.progress`** — résultat par fichier

```json
{
  "type": "files.delete.progress",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "media_file_id": "uuid-file-1",
    "status": "deleted",
    "error": null,
    "dirs_removed": 1
  }
}
```

**`files.delete.completed`** — résumé final

```json
{
  "type": "files.delete.completed",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "total": 2,
    "deleted": 1,
    "failed": 1,
    "dirs_removed": 1,
    "results": [
      {
        "media_file_id": "uuid-file-1",
        "status": "deleted",
        "dirs_removed": 1,
        "size_bytes": 8589934592
      },
      {
        "media_file_id": "uuid-file-2",
        "status": "failed",
        "error": "permission denied",
        "dirs_removed": 0,
        "size_bytes": 0
      }
    ]
  }
}
```

### 7.4 Architecture interne du Watcher

```go
// main.go — point d'entrée
// 1. Charge la config (env vars)
// 2. Initialise le client WebSocket (avec reconnexion auto)
// 3. Initialise le watcher fsnotify sur les chemins configurés
// 4. Lance le scanner initial si SCANARR_SCAN_ON_START=true
// 5. Boucle principale : écoute events fsnotify + commands WebSocket

// internal/config/config.go
type Config struct {
    WsURL             string
    WsReconnectDelay  time.Duration
    WsPingInterval    time.Duration
    WatchPaths        []string
    ScanOnStart       bool
    LogLevel          string
    AuthToken         string
}

// internal/watcher/watcher.go
type FileWatcher struct {
    fsWatcher  *fsnotify.Watcher
    wsClient   *websocket.Client
    // Debounce les événements (éviter les doublons rapides)
    // Filtre les fichiers temporaires (.part, .tmp, etc.)
}

// internal/scanner/scanner.go
type Scanner struct {
    wsClient *websocket.Client
}
// Scan(path string, scanID string) error
// — parcourt récursivement le répertoire
// — envoie scan.file pour chaque fichier trouvé
// — envoie scan.progress toutes les 100 fichiers
// — envoie scan.completed à la fin

// internal/websocket/client.go
type Client struct {
    url       string
    conn      *websocket.Conn
    authToken string
    // Reconnexion automatique avec backoff exponentiel
    // Ping/Pong pour détecter les déconnexions
    // Thread-safe send/receive
}
```

### 7.5 Règles de filtrage

Le watcher doit ignorer :

- Les fichiers temporaires : `*.part`, `*.tmp`, `*.download`, `*.!qB`
- Les fichiers cachés : commençant par `.`
- Les fichiers de métadonnées : `*.nfo`, `*.jpg`, `*.png`, `*.srt`, `*.sub`, `*.idx` (optionnel, configurable)
- Les dossiers système : `@eaDir`, `.Trash-*`, `$RECYCLE.BIN`, `System Volume Information`

Extensions de fichiers médias à surveiller :

- Vidéo : `.mkv`, `.mp4`, `.avi`, `.m4v`, `.wmv`, `.ts`, `.iso`

### 7.6 Obtention du nombre de hardlinks

```go
import "syscall"

func getHardlinkCount(path string) (uint64, error) {
    var stat syscall.Stat_t
    err := syscall.Stat(path, &stat)
    if err != nil {
        return 0, err
    }
    return stat.Nlink, nil
}
```

### 7.7 Correspondance des chemins (Watcher natif ↔ API Docker)

Le watcher tourne sur le serveur hôte et voit les vrais chemins (ex: `/mnt/media1/Movies/Inception/movie.mkv`). L'API tourne dans Docker et voit des chemins montés (ex: `/mnt/volume1/Movies/Inception/movie.mkv`).

**Principe** : les volumes Docker dans `docker-compose.yml` mappent les chemins hôte vers les chemins internes du container :

```yaml
# docker-compose.yml
volumes:
  - /mnt/media1:/mnt/volume1:rw   # hôte → container
```

**Gestion dans Scanarr** : chaque `volume` en BDD stocke deux chemins :

- `path` : le chemin tel que vu par l'API Docker (ex: `/mnt/volume1`) — utilisé pour les opérations de suppression côté API.
- `host_path` : le chemin réel sur le serveur hôte (ex: `/mnt/media1`) — utilisé par le watcher pour la surveillance.

Quand le watcher envoie un événement `file.created` avec le chemin `/mnt/media1/Movies/Inception/movie.mkv`, le `WatcherMessageHandler` traduit le préfixe `/mnt/media1` → volume avec `host_path=/mnt/media1` → le fichier est enregistré en BDD relativement au volume.

> **Il faut donc ajouter le champ `host_path` à la table `volumes`** :

```sql
ALTER TABLE volumes ADD COLUMN host_path VARCHAR(500);
-- host_path = chemin réel sur le serveur hôte (utilisé par le watcher)
-- path = chemin dans le container Docker (utilisé par l'API)
-- Pour une installation full native (sans Docker), host_path = path
```

---

---

