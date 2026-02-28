# Scanarr — Base de données PostgreSQL

> **Prérequis** : Aucun
> **Version** : V1.5.1 (ajout inode/device_id — tables V1.5 dans QBIT_STATS_AND_SCORING.md et CROSS_SEED.md)

---

## 4. Base de données — Schéma PostgreSQL

### 4.1 Diagramme des entités

```
users
  │
  ├──< activity_logs
  ├──< scheduled_deletions ──< scheduled_deletion_items
  │
volumes
  │
  ├──< media_files ──< movie_files >── movies
  │
radarr_instances
media_player_instances
settings
```

### 4.2 Tables détaillées

#### `users`

```sql
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email VARCHAR(180) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,           -- hash bcrypt/argon2
    role VARCHAR(30) NOT NULL DEFAULT 'ROLE_USER',
    -- Valeurs possibles : ROLE_ADMIN, ROLE_ADVANCED_USER, ROLE_USER, ROLE_GUEST
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMP
);
```

#### `volumes`

```sql
CREATE TABLE volumes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,                -- nom affiché (ex: "NAS Principal")
    path VARCHAR(500) NOT NULL UNIQUE,         -- chemin dans le container API Docker (ex: /mnt/volume1)
    host_path VARCHAR(500) NOT NULL,           -- chemin réel sur le serveur hôte (ex: /mnt/media1)
    type VARCHAR(20) NOT NULL DEFAULT 'local', -- 'local' ou 'network'
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active', 'inactive', 'error'
    total_space_bytes BIGINT,                  -- espace total (mis à jour par scan)
    used_space_bytes BIGINT,                   -- espace utilisé (mis à jour par scan)
    last_scan_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### `media_files`

```sql
CREATE TABLE media_files (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    volume_id UUID NOT NULL REFERENCES volumes(id) ON DELETE CASCADE,
    file_path VARCHAR(1000) NOT NULL,          -- chemin relatif au volume
    file_name VARCHAR(500) NOT NULL,           -- nom du fichier
    file_size_bytes BIGINT NOT NULL DEFAULT 0,
    hardlink_count INTEGER NOT NULL DEFAULT 1,
    resolution VARCHAR(20),                    -- '720p', '1080p', '2160p', etc.
    codec VARCHAR(50),                         -- 'x264', 'x265', 'HEVC', etc.
    quality VARCHAR(50),                       -- 'Bluray', 'WEB-DL', 'Remux', etc.
    is_linked_radarr BOOLEAN NOT NULL DEFAULT false,
    is_linked_media_player BOOLEAN NOT NULL DEFAULT false,
    radarr_instance_id UUID REFERENCES radarr_instances(id) ON DELETE SET NULL,
    file_hash VARCHAR(64),                     -- SHA-256 optionnel pour cross-seed futur
    partial_hash VARCHAR(128),                 -- hash partiel (premiers + derniers octets) pour matching rapide
    inode BIGINT,                              -- numéro inode filesystem (remonté par le watcher)
    device_id BIGINT,                          -- identifiant device filesystem / st_dev (remonté par le watcher)
    is_protected BOOLEAN NOT NULL DEFAULT false,
    detected_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(volume_id, file_path)
);

CREATE INDEX idx_media_files_volume ON media_files(volume_id);
CREATE INDEX idx_media_files_radarr ON media_files(is_linked_radarr);
CREATE INDEX idx_media_files_name ON media_files(file_name);
CREATE INDEX idx_media_files_partial_hash ON media_files(partial_hash);
CREATE INDEX idx_media_files_inode ON media_files(device_id, inode);
```

> **Colonnes inode (V1.5.1)** : Le couple `(device_id, inode)` identifie un fichier physique de manière unique sur un filesystem. Deux filesystems différents (ex: `/dev/sda1` et `/dev/sdb1`) peuvent avoir le même numéro d'inode — d'où la nécessité du `device_id`. Ces colonnes sont nullable car les fichiers existants avant l'ajout de cette fonctionnalité n'ont pas encore été re-scannés. Après un re-scan complet, tous les fichiers actifs ont un inode renseigné.
>
> **Méthodes repository** :
> - `findByInode(deviceId, inode)` : retourne le premier `MediaFile` correspondant
> - `findAllByInode(deviceId, inode)` : retourne **tous** les `MediaFile` partageant le même inode (= hardlinks connus)
> - `findSiblingsByInode(file)` : comme `findAllByInode` mais exclut le fichier passé en paramètre
>
> **Usage clé** : `DeletionService::executeDeletion()` utilise `findAllByInode()` pour collecter automatiquement tous les hardlinks connus d'un fichier avant envoi au watcher, garantissant la libération effective de l'espace disque.

#### `movies`

```sql
CREATE TABLE movies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tmdb_id INTEGER UNIQUE,                    -- ID TMDB
    radarr_id INTEGER,                         -- ID dans Radarr (peut différer par instance)
    title VARCHAR(500) NOT NULL,
    original_title VARCHAR(500),
    year INTEGER,
    synopsis TEXT,
    poster_url VARCHAR(1000),                  -- URL affiche TMDB
    backdrop_url VARCHAR(1000),
    genres VARCHAR(500),                       -- JSON array sérialisé ou comma-separated
    rating DECIMAL(3,1),                       -- note TMDB
    runtime_minutes INTEGER,
    radarr_instance_id UUID REFERENCES radarr_instances(id) ON DELETE SET NULL,
    radarr_monitored BOOLEAN DEFAULT true,
    radarr_has_file BOOLEAN DEFAULT false,
    is_protected BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_movies_tmdb ON movies(tmdb_id);
CREATE INDEX idx_movies_title ON movies(title);
```

#### `movie_files` (table de liaison)

```sql
CREATE TABLE movie_files (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    movie_id UUID NOT NULL REFERENCES movies(id) ON DELETE CASCADE,
    media_file_id UUID NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
    matched_by VARCHAR(30) NOT NULL DEFAULT 'filename',
    -- 'radarr_api', 'filename_parse', 'manual'
    confidence DECIMAL(3,2) DEFAULT 1.0,       -- 0.0 à 1.0
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(movie_id, media_file_id)
);
```

#### `radarr_instances`

```sql
CREATE TABLE radarr_instances (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,                -- nom affiché (ex: "Radarr 4K")
    url VARCHAR(500) NOT NULL,                 -- ex: http://192.168.1.10:7878
    api_key VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT true,
    root_folders JSONB,                        -- cache des root folders Radarr
    -- ex: [{"id": 1, "path": "/movies", "mapped_path": "/mnt/nas/movies"}]
    last_sync_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### `media_player_instances`

```sql
CREATE TABLE media_player_instances (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,                -- ex: "Plex principal"
    type VARCHAR(20) NOT NULL,                 -- 'plex' ou 'jellyfin'
    url VARCHAR(500) NOT NULL,
    token VARCHAR(255) NOT NULL,               -- Plex token ou Jellyfin API key
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### `scheduled_deletions`

```sql
CREATE TABLE scheduled_deletions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    created_by UUID NOT NULL REFERENCES users(id),
    scheduled_date DATE NOT NULL,              -- date de suppression
    execution_time TIME NOT NULL DEFAULT '23:59:00',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- 'pending', 'reminder_sent', 'executing', 'waiting_watcher', 'completed', 'failed', 'cancelled'
    delete_physical_files BOOLEAN NOT NULL DEFAULT true,
    delete_radarr_reference BOOLEAN NOT NULL DEFAULT false,
    delete_media_player_reference BOOLEAN NOT NULL DEFAULT false,
    disable_radarr_auto_search BOOLEAN NOT NULL DEFAULT false,
    reminder_days_before INTEGER DEFAULT 3,    -- rappel X jours avant
    reminder_sent_at TIMESTAMP,
    executed_at TIMESTAMP,
    execution_report JSONB,                    -- rapport détaillé post-exécution
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### `scheduled_deletion_items`

```sql
CREATE TABLE scheduled_deletion_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    scheduled_deletion_id UUID NOT NULL REFERENCES scheduled_deletions(id) ON DELETE CASCADE,
    movie_id UUID NOT NULL REFERENCES movies(id),
    media_file_ids JSONB NOT NULL DEFAULT '[]', -- liste des UUID media_files à supprimer
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- 'pending', 'deleted', 'failed', 'skipped'
    error_message TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### Suppression unifiée via `scheduled_deletions` (pas de table `deletion_requests`)

Les suppressions immédiates (via `MovieController::delete()`, `FileController::delete()`, `FileController::globalDelete()`) créent une **`ScheduledDeletion` éphémère** avec `scheduled_date = today` et appellent `DeletionService::executeDeletion()`. Cela unifie le pipeline de suppression : toute suppression (planifiée ou immédiate) passe par la même chaîne `ScheduledDeletion → DeletionService → Watcher`.

La corrélation async avec le watcher se fait via `deletion_id` (= `ScheduledDeletion.id`) transmis dans la commande `command.files.delete`. Quand le watcher renvoie `files.delete.completed`, le `WatcherMessageProcessor` retrouve la `ScheduledDeletion` par `deletion_id` pour finaliser (cleanup BDD, refresh Plex/Jellyfin, Discord).

> **Note** : Le champ `request_id` est également envoyé au watcher pour traçabilité dans les logs, mais la corrélation BDD se fait uniquement via `deletion_id`.

> **Note V1.5.1 — Collecte inode** : `DeletionService::executeDeletion()` enrichit automatiquement la liste de fichiers à supprimer avec les siblings inode. Pour chaque fichier explicitement sélectionné, l'API appelle `findAllByInode(device_id, inode)` et ajoute tous les hardlinks connus à la commande `command.files.delete`. Les doublons sont éliminés via un set de `seenFileIds`. Cela garantit que tous les hardlinks connus sont supprimés ensemble → libération effective de l'espace disque.

#### `settings`

```sql
CREATE TABLE settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type VARCHAR(20) NOT NULL DEFAULT 'string',
    -- 'string', 'integer', 'boolean', 'json'
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Settings par défaut à insérer au premier lancement :
-- discord_webhook_url (string)
-- discord_reminder_days (integer, default: 3)
-- qbittorrent_url (string)
-- qbittorrent_username (string)
-- qbittorrent_password (string, chiffré)
-- setup_completed (boolean, default: false)
```

#### `activity_logs`

```sql
CREATE TABLE activity_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    -- 'file.deleted', 'file.deleted_global', 'movie.deleted',
    -- 'deletion.scheduled', 'deletion.executed', 'deletion.cancelled',
    -- 'settings.updated', 'volume.created', 'volume.deleted',
    -- 'radarr.synced', 'user.created', 'user.updated'
    entity_type VARCHAR(50),                   -- 'media_file', 'movie', 'volume', etc.
    entity_id UUID,
    details JSONB,                             -- détails supplémentaires
    ip_address VARCHAR(45),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_action ON activity_logs(action);
CREATE INDEX idx_activity_logs_created ON activity_logs(created_at DESC);
```

---
