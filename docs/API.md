# Scanarr — API REST (Symfony)

> **Prérequis** : [DATABASE.md](DATABASE.md)
> **Version** : V1.2.1 (endpoints V1.5 dans QBIT_STATS_AND_SCORING.md)

---

## 5. Back-end Symfony — API REST

### 5.1 Convention d'API

- **Préfixe** : `/api/v1/`
- **Format** : JSON
- **Auth** : Bearer token JWT (header `Authorization: Bearer <token>`)
- **Pagination** : `?page=1&limit=25` (défaut : page=1, limit=25)
- **Tri** : `?sort=title&order=asc`
- **Recherche** : `?search=<query>`
- **Codes HTTP** : 200 (OK), 201 (Created), 204 (No Content), 400 (Bad Request), 401 (Unauthorized), 403 (Forbidden), 404 (Not Found), 422 (Validation Error), 500 (Server Error)

### 5.2 Format de réponse standard

```json
// Succès (single)
{
  "data": { ... },
  "meta": {}
}

// Succès (collection)
{
  "data": [ ... ],
  "meta": {
    "total": 150,
    "page": 1,
    "limit": 25,
    "total_pages": 6
  }
}

// Erreur
{
  "error": {
    "code": 422,
    "message": "Validation failed",
    "details": {
      "email": "This value is not a valid email address."
    }
  }
}
```

### 5.3 Endpoints détaillés

#### Auth

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `POST` | `/api/v1/auth/setup` | — | Setup initial (création premier admin). Disponible uniquement si `setup_completed = false` |
| `POST` | `/api/v1/auth/login` | — | Connexion, retourne JWT access + refresh token |
| `POST` | `/api/v1/auth/refresh` | — | Rafraîchir le JWT via refresh token |
| `GET` | `/api/v1/auth/me` | Guest | Retourne les infos de l'utilisateur connecté |

**POST `/api/v1/auth/setup`**
```json
// Request
{
  "email": "admin@scanarr.local",
  "username": "admin",
  "password": "SecureP@ss123"
}
// Response 201
{
  "data": {
    "id": "uuid",
    "email": "admin@scanarr.local",
    "username": "admin",
    "role": "ROLE_ADMIN"
  }
}
```

**POST `/api/v1/auth/login`**
```json
// Request
{
  "email": "admin@scanarr.local",
  "password": "SecureP@ss123"
}
// Response 200
{
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "expires_in": 3600,
    "user": {
      "id": "uuid",
      "username": "admin",
      "role": "ROLE_ADMIN"
    }
  }
}
```

#### Users

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/users` | Admin | Liste des utilisateurs |
| `POST` | `/api/v1/users` | Admin | Créer un utilisateur |
| `PUT` | `/api/v1/users/{id}` | Admin | Modifier un utilisateur |
| `DELETE` | `/api/v1/users/{id}` | Admin | Supprimer un utilisateur |

**POST `/api/v1/users`**
```json
// Request
{
  "email": "john@example.com",
  "username": "john",
  "password": "Password123!",
  "role": "ROLE_ADVANCED_USER"
}
// Response 201
{
  "data": {
    "id": "uuid",
    "email": "john@example.com",
    "username": "john",
    "role": "ROLE_ADVANCED_USER",
    "is_active": true,
    "created_at": "2026-02-24T10:00:00Z"
  }
}
```

#### Volumes

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/volumes` | Guest | Liste des volumes |
| `POST` | `/api/v1/volumes` | Admin | Ajouter un volume |
| `PUT` | `/api/v1/volumes/{id}` | Admin | Modifier un volume |
| `DELETE` | `/api/v1/volumes/{id}` | Admin | Supprimer un volume |
| `POST` | `/api/v1/volumes/{id}/scan` | Admin | Déclencher un scan du volume |

**POST `/api/v1/volumes`**
```json
// Request
{
  "name": "NAS Films 4K",
  "path": "/mnt/volume2",
  "host_path": "/mnt/nas/movies4k",
  "type": "network"
}
// Response 201
{
  "data": {
    "id": "uuid",
    "name": "NAS Films 4K",
    "path": "/mnt/volume2",
    "host_path": "/mnt/nas/movies4k",
    "type": "network",
    "status": "active",
    "total_space_bytes": null,
    "used_space_bytes": null,
    "last_scan_at": null
  }
}
```

**POST `/api/v1/volumes/{id}/scan`**
```json
// Response 202 (Accepted — scan lancé en async)
{
  "data": {
    "message": "Scan initiated for volume 'NAS Films 4K'",
    "volume_id": "uuid"
  }
}
```

#### Files (Explorateur de fichiers)

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/files` | Guest | Liste des fichiers (avec filtres par volume, recherche) |
| `GET` | `/api/v1/files/{id}` | Guest | Détail d'un fichier |
| `DELETE` | `/api/v1/files/{id}` | AdvancedUser | Suppression d'un fichier |
| `DELETE` | `/api/v1/files/{id}/global` | AdvancedUser | Suppression globale (tous les volumes) |

**GET `/api/v1/files?volume_id={uuid}&search=inception&page=1&limit=25`**
```json
// Response 200
{
  "data": [
    {
      "id": "uuid",
      "volume_id": "uuid",
      "volume_name": "NAS Principal",
      "file_path": "Inception (2010)/Inception.2010.2160p.BluRay.x265.mkv",
      "file_name": "Inception.2010.2160p.BluRay.x265.mkv",
      "file_size_bytes": 45000000000,
      "hardlink_count": 2,
      "resolution": "2160p",
      "codec": "x265",
      "quality": "BluRay",
      "is_linked_radarr": true,
      "is_linked_media_player": true,
      "detected_at": "2026-02-20T14:30:00Z"
    }
  ],
  "meta": { "total": 1, "page": 1, "limit": 25, "total_pages": 1 }
}
```

**DELETE `/api/v1/files/{id}`**
```json
// Request
{
  "delete_physical": true,
  "delete_radarr_reference": true
}
// Response 200
{
  "data": {
    "message": "File deleted successfully",
    "physical_deleted": true,
    "radarr_dereferenced": true
  }
}
```

**DELETE `/api/v1/files/{id}/global`**
```json
// Request
{
  "delete_physical": true,
  "delete_radarr_reference": true,
  "disable_radarr_auto_search": false
}
// Response 200
{
  "data": {
    "message": "File globally deleted",
    "files_deleted": 3,
    "volumes_affected": ["NAS Principal", "NAS Backup"],
    "radarr_dereferenced": true,
    "warning": "Radarr auto-search is still enabled for this movie. It may be re-downloaded."
  }
}
```

#### Movies

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/movies` | Guest | Liste des films (avec filtres, recherche, tri) |
| `GET` | `/api/v1/movies/{id}` | Guest | Détail d'un film (avec fichiers liés) |
| `DELETE` | `/api/v1/movies/{id}` | AdvancedUser | Suppression globale d'un film (choix à la carte) |
| `POST` | `/api/v1/movies/sync` | Admin | Déclencher une synchro Radarr + TMDB |

**GET `/api/v1/movies?search=inception&sort=title&order=asc&page=1&limit=25`**
```json
// Response 200
{
  "data": [
    {
      "id": "uuid",
      "tmdb_id": 27205,
      "title": "Inception",
      "original_title": "Inception",
      "year": 2010,
      "synopsis": "A thief who steals corporate secrets...",
      "poster_url": "https://image.tmdb.org/t/p/w500/...",
      "genres": "Action, Science Fiction, Adventure",
      "rating": 8.4,
      "runtime_minutes": 148,
      "file_count": 3,
      "max_file_size_bytes": 45000000000,
      "files_summary": [
        { "id": "uuid", "file_name": "Inception.2010.2160p.BluRay.x265.mkv", "file_size_bytes": 45000000000, "resolution": "2160p", "volume_name": "NAS Principal" },
        { "id": "uuid", "file_name": "Inception.2010.1080p.WEB-DL.x264.mkv", "file_size_bytes": 12000000000, "resolution": "1080p", "volume_name": "NAS Backup" }
      ],
      "is_monitored_radarr": true
    }
  ],
  "meta": { "total": 1, "page": 1, "limit": 25, "total_pages": 1 }
}
```

**GET `/api/v1/movies/{id}`**
```json
// Response 200
{
  "data": {
    "id": "uuid",
    "tmdb_id": 27205,
    "title": "Inception",
    "year": 2010,
    "synopsis": "A thief who steals corporate secrets...",
    "poster_url": "https://image.tmdb.org/t/p/w500/...",
    "backdrop_url": "https://image.tmdb.org/t/p/original/...",
    "genres": "Action, Science Fiction, Adventure",
    "rating": 8.4,
    "runtime_minutes": 148,
    "radarr_instance": {
      "id": "uuid",
      "name": "Radarr 4K"
    },
    "radarr_monitored": true,
    "files": [
      {
        "id": "uuid",
        "volume_id": "uuid",
        "volume_name": "NAS Principal",
        "file_path": "Inception (2010)/Inception.2010.2160p.BluRay.x265.mkv",
        "file_name": "Inception.2010.2160p.BluRay.x265.mkv",
        "file_size_bytes": 45000000000,
        "hardlink_count": 2,
        "resolution": "2160p",
        "codec": "x265",
        "quality": "BluRay",
        "is_linked_radarr": true,
        "is_linked_media_player": true,
        "matched_by": "radarr_api",
        "confidence": 1.0
      }
    ]
  }
}
```

**DELETE `/api/v1/movies/{id}`**
```json
// Request — suppression à la carte
{
  "file_ids": ["uuid-1", "uuid-3"],
  "delete_radarr_reference": true,
  "delete_media_player_reference": false,
  "disable_radarr_auto_search": true
}
// Response 202 (watcher en ligne, suppression async)
{
  "data": {
    "message": "Deletion initiated",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "executing",
    "files_count": 2,
    "radarr_dereferenced": true
  }
}
// Response 202 (watcher offline, mise en attente)
{
  "data": {
    "message": "Deletion initiated",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "waiting_watcher",
    "files_count": 2,
    "radarr_dereferenced": true
  }
}
// Response 200 (pas de fichiers physiques à supprimer)
{
  "data": {
    "message": "Deletion initiated",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "completed",
    "files_count": 0,
    "radarr_dereferenced": true
  }
}
```

**Warning Radarr** : Si `disable_radarr_auto_search = false` et `delete_radarr_reference = false` et que le film est monitoré dans Radarr, la réponse inclut un champ `warning` indiquant que le film pourrait être re-téléchargé.

**Suppression éphémère** : En interne, une `ScheduledDeletion` éphémère est créée avec `scheduled_date = today` pour uniformiser le pipeline. Le `deletion_id` retourné est l'UUID de cette `ScheduledDeletion`.

**Flux asynchrone** : La suppression physique des fichiers et le nettoyage des dossiers sont délégués au watcher via WebSocket. L'API retourne immédiatement avec un `deletion_id` (UUID de la `ScheduledDeletion` éphémère). La suppression des entrées en BDD et le refresh Plex/Jellyfin se font automatiquement quand le watcher renvoie `files.delete.completed`.

#### Scheduled Deletions

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/scheduled-deletions` | User | Liste des suppressions planifiées |
| `POST` | `/api/v1/scheduled-deletions` | AdvancedUser | Créer une suppression planifiée |
| `GET` | `/api/v1/scheduled-deletions/{id}` | User | Détail d'une suppression planifiée |
| `PUT` | `/api/v1/scheduled-deletions/{id}` | AdvancedUser | Modifier (date, items) |
| `DELETE` | `/api/v1/scheduled-deletions/{id}` | AdvancedUser | Annuler une suppression planifiée |

**POST `/api/v1/scheduled-deletions`**
```json
// Request
{
  "scheduled_date": "2026-08-10",
  "delete_physical_files": true,
  "delete_radarr_reference": true,
  "delete_media_player_reference": false,
  "disable_radarr_auto_search": false,
  "reminder_days_before": 3,
  "items": [
    {
      "movie_id": "uuid-movie-1",
      "media_file_ids": ["uuid-file-1", "uuid-file-2"]
    },
    {
      "movie_id": "uuid-movie-2",
      "media_file_ids": ["uuid-file-3"]
    }
  ]
}
// Response 201
{
  "data": {
    "id": "uuid",
    "scheduled_date": "2026-08-10",
    "execution_time": "23:59:00",
    "status": "pending",
    "delete_physical_files": true,
    "delete_radarr_reference": true,
    "delete_media_player_reference": false,
    "reminder_days_before": 3,
    "items_count": 2,
    "total_files_count": 3,
    "created_by": "admin",
    "created_at": "2026-02-24T15:00:00Z"
  }
}
```

#### Radarr

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/radarr-instances` | Admin | Liste des instances Radarr |
| `POST` | `/api/v1/radarr-instances` | Admin | Ajouter une instance |
| `PUT` | `/api/v1/radarr-instances/{id}` | Admin | Modifier |
| `DELETE` | `/api/v1/radarr-instances/{id}` | Admin | Supprimer |
| `POST` | `/api/v1/radarr-instances/{id}/test` | Admin | Tester la connexion |
| `GET` | `/api/v1/radarr-instances/{id}/root-folders` | Admin | Lister les root folders via API Radarr |

**POST `/api/v1/radarr-instances/{id}/test`**
```json
// Response 200
{
  "data": {
    "success": true,
    "version": "5.3.6",
    "movies_count": 450
  }
}
// Response 400
{
  "error": {
    "code": 400,
    "message": "Connection failed: Unauthorized (401). Check your API key."
  }
}
```

#### Media Players

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/media-players` | Admin | Liste des lecteurs |
| `POST` | `/api/v1/media-players` | Admin | Ajouter |
| `PUT` | `/api/v1/media-players/{id}` | Admin | Modifier |
| `DELETE` | `/api/v1/media-players/{id}` | Admin | Supprimer |
| `POST` | `/api/v1/media-players/{id}/test` | Admin | Tester la connexion |

#### Settings

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/settings` | Admin | Récupérer tous les settings |
| `PUT` | `/api/v1/settings` | Admin | Mettre à jour un ou plusieurs settings |
| `POST` | `/api/v1/settings/test-discord` | Admin | Tester le webhook Discord (envoi d'un message test) |
| `POST` | `/api/v1/settings/test-qbittorrent` | Admin | Tester la connexion qBittorrent |

**PUT `/api/v1/settings`**
```json
// Request
{
  "discord_webhook_url": "https://discord.com/api/webhooks/...",
  "discord_reminder_days": 3,
  "qbittorrent_url": "http://192.168.1.10:8080",
  "qbittorrent_username": "admin",
  "qbittorrent_password": "password123"
}
// Response 200
{
  "data": {
    "message": "Settings updated successfully",
    "updated_keys": ["discord_webhook_url", "discord_reminder_days", "qbittorrent_url", "qbittorrent_username", "qbittorrent_password"]
  }
}
```

#### Dashboard

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/dashboard` | Guest | Statistiques du dashboard |

**GET `/api/v1/dashboard`**
```json
// Response 200
{
  "data": {
    "total_movies": 450,
    "total_files": 1230,
    "total_size_bytes": 25000000000000,
    "volumes": [
      {
        "id": "uuid",
        "name": "NAS Principal",
        "total_space_bytes": 16000000000000,
        "used_space_bytes": 12000000000000,
        "file_count": 800
      }
    ],
    "orphan_files_count": 23,
    "upcoming_deletions_count": 2,
    "recent_activity": [
      {
        "action": "file.deleted",
        "entity_type": "media_file",
        "details": { "file_name": "OldMovie.2005.720p.mkv" },
        "user": "admin",
        "created_at": "2026-02-24T14:00:00Z"
      }
    ]
  }
}
```

---


## Annexe A — Regex de parsing des noms de fichiers

```php
/**
 * Parse un nom de fichier média pour en extraire les métadonnées.
 *
 * Formats supportés :
 *   Title.Year.Resolution.Quality.Codec-Group.ext
 *   Title (Year) Resolution Quality Codec-Group.ext
 *   Title.Year.Resolution.Codec.ext
 *
 * Exemples :
 *   "Inception.2010.2160p.BluRay.x265-GROUP.mkv"
 *   "The.Matrix.1999.1080p.WEB-DL.x264-SCENE.mkv"
 *   "Avatar (2009) 720p BDRip x264.mkv"
 */

class FileNameParser
{
    private const RESOLUTIONS = ['2160p', '1080p', '720p', '480p', '4K', 'UHD'];
    private const QUALITIES = ['BluRay', 'Bluray', 'BDRip', 'BRRip', 'WEB-DL', 'WEBRip', 'WEB', 'HDTV', 'DVDRip', 'Remux', 'PROPER', 'REPACK'];
    private const CODECS = ['x264', 'x265', 'H.264', 'H264', 'H.265', 'H265', 'HEVC', 'AVC', 'AV1', 'VP9', 'MPEG-2', 'XviD', 'DivX'];

    public function parse(string $fileName): array
    {
        // Retirer l'extension
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        // Extraire l'année (4 chiffres entre 1900 et 2099)
        preg_match('/[\.\s\(]?((?:19|20)\d{2})[\.\s\)]?/', $name, $yearMatch);
        $year = $yearMatch[1] ?? null;

        // Extraire le titre (tout avant l'année)
        $title = null;
        if ($year) {
            $titlePart = preg_split('/[\.\s\(]?' . $year . '/', $name)[0] ?? '';
            $title = str_replace(['.', '_'], ' ', trim($titlePart));
        }

        // Extraire résolution, qualité, codec (insensible à la casse)
        $resolution = $this->findMatch($name, self::RESOLUTIONS);
        $quality = $this->findMatch($name, self::QUALITIES);
        $codec = $this->findMatch($name, self::CODECS);

        return [
            'title' => $title,
            'year' => $year ? (int) $year : null,
            'resolution' => $resolution,
            'quality' => $quality,
            'codec' => $codec,
        ];
    }

    private function findMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return $needle;
            }
        }
        return null;
    }
}
```

