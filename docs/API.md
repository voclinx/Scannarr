# Scanarr — API REST (Symfony)

> **Prérequis** : [DATABASE.md](DATABASE.md)
> **Version** : V2.0

---

## 5. Back-end Symfony — API REST

### 5.1 Convention d'API

- **Préfixe** : `/api/v1/`
- **Format** : JSON
- **Auth** : Bearer token JWT (header `Authorization: Bearer <token>`)
- **Pagination** : `?page=1&limit=25` (défaut : page=1, limit=25)
- **Tri** : `?sort=title&order=asc`
- **Recherche** : `?search=<query>`
- **Codes HTTP** : 200, 201, 202, 204, 400, 401, 403, 404, 422, 500

### 5.2 Format de réponse standard

```json
// Succès (single)
{ "data": { ... }, "meta": {} }

// Succès (collection)
{ "data": [ ... ], "meta": { "total": 150, "page": 1, "limit": 25, "total_pages": 6 } }

// Erreur
{ "error": { "code": 422, "message": "Validation failed", "details": { ... } } }
```

### 5.3 Endpoints détaillés

#### Auth

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `POST` | `/api/v1/auth/setup` | — | Setup initial (premier admin) |
| `POST` | `/api/v1/auth/login` | — | Connexion, retourne JWT |
| `POST` | `/api/v1/auth/refresh` | — | Rafraîchir le JWT |
| `GET` | `/api/v1/auth/me` | Guest | Infos utilisateur connecté |

#### Users

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/users` | Admin | Liste des utilisateurs |
| `POST` | `/api/v1/users` | Admin | Créer un utilisateur |
| `PUT` | `/api/v1/users/{id}` | Admin | Modifier un utilisateur |
| `DELETE` | `/api/v1/users/{id}` | Admin | Supprimer un utilisateur |

#### Watchers (V2.0 — remplace Volumes)

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/watchers` | Guest | Liste des watchers avec leurs volumes |
| `POST` | `/api/v1/watchers` | Admin | Créer un watcher (génère un token) |
| `GET` | `/api/v1/watchers/{id}` | Guest | Détail d'un watcher |
| `PUT` | `/api/v1/watchers/{id}` | Admin | Modifier un watcher (nom, extensions, volumes) |
| `DELETE` | `/api/v1/watchers/{id}` | Admin | Supprimer un watcher |
| `POST` | `/api/v1/watchers/{id}/regenerate-token` | Admin | Régénérer le token d'auth |
| `POST` | `/api/v1/watchers/{id}/scan` | Admin | Déclencher un scan de tous les volumes |
| `POST` | `/api/v1/watchers/{id}/scan/{volumeId}` | Admin | Déclencher un scan d'un volume |

**POST `/api/v1/watchers`**
```json
// Request
{
  "name": "Watcher NAS Principal",
  "scan_extensions": ["mkv", "mp4", "avi", "m4v", "ts", "wmv"],
  "disable_deletion": false,
  "volumes": [
    {"name": "Films HD", "path": "/volume1/filmarr/media/movies"},
    {"name": "Torrents HD", "path": "/volume1/filmarr/torrents/movies"},
    {"name": "Cross-seed", "path": "/volume1/filmarr/links"}
  ]
}
// Response 201
{
  "data": {
    "id": "uuid",
    "name": "Watcher NAS Principal",
    "token": "watcher-generated-token-xyz789",
    "status": "disconnected",
    "scan_extensions": ["mkv", "mp4", "avi", "m4v", "ts", "wmv"],
    "disable_deletion": false,
    "volumes": [
      {"id": "uuid-v1", "name": "Films HD", "path": "/volume1/filmarr/media/movies", "status": "active"},
      {"id": "uuid-v2", "name": "Torrents HD", "path": "/volume1/filmarr/torrents/movies", "status": "active"},
      {"id": "uuid-v3", "name": "Cross-seed", "path": "/volume1/filmarr/links", "status": "active"}
    ]
  }
}
```

> **Important** : Le `token` n'est affiché en clair qu'à la création et lors de la régénération. Ensuite, seul un masque est retourné.

#### Files (Explorateur de fichiers)

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/files` | Guest | Liste des media_files (avec filtres) |
| `GET` | `/api/v1/files/{id}` | Guest | Détail d'un media_file avec tous ses file_paths |
| `DELETE` | `/api/v1/files/{id}` | AdvancedUser | Suppression d'un media_file (tous ses chemins) |

**GET `/api/v1/files?watcher_id={uuid}&volume_id={uuid}&search=inception&page=1&limit=25`**
```json
{
  "data": [
    {
      "id": "uuid",
      "inode": 12345,
      "device_id": 2049,
      "file_size_bytes": 50000000000,
      "hardlink_count": 3,
      "known_paths_count": 3,
      "resolution": "2160p",
      "codec": "x265",
      "quality": "BluRay",
      "is_protected": false,
      "is_linked_radarr": true,
      "is_linked_media_player": true,
      "file_paths": [
        {
          "id": "uuid-fp1",
          "volume_id": "uuid-v1",
          "volume_name": "Films HD",
          "relative_path": "Inception/Inception.2010.2160p.BluRay.x265.mkv",
          "filename": "Inception.2010.2160p.BluRay.x265.mkv"
        },
        {
          "id": "uuid-fp2",
          "volume_id": "uuid-v2",
          "volume_name": "Torrents HD",
          "relative_path": "Inception.2010.2160p.x265-GROUP.mkv",
          "filename": "Inception.2010.2160p.x265-GROUP.mkv"
        }
      ],
      "created_at": "2026-02-20T14:30:00Z"
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
// Response 202
{
  "data": {
    "message": "Deletion initiated",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "executing",
    "paths_to_delete": 3
  }
}
```

V2.0 : la suppression d'un `media_file` supprime automatiquement **tous ses `file_paths`**. Plus besoin de `/files/{id}/global`.

#### Movies

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/movies` | Guest | Liste des films |
| `GET` | `/api/v1/movies/{id}` | Guest | Détail d'un film avec fichiers et chemins |
| `DELETE` | `/api/v1/movies/{id}` | AdvancedUser | Suppression d'un film |
| `POST` | `/api/v1/movies/sync` | Admin | Déclencher une synchro Radarr + TMDB |
| `PUT` | `/api/v1/movies/{id}/protect` | AdvancedUser | Protéger/déprotéger |

**GET `/api/v1/movies/{id}`**
```json
{
  "data": {
    "id": "uuid",
    "tmdb_id": 27205,
    "title": "Inception",
    "year": 2010,
    "synopsis": "...",
    "poster_url": "...",
    "is_protected": false,
    "radarr_monitored": true,
    "files": [
      {
        "media_file_id": "uuid-mf1",
        "inode": 12345,
        "file_size_bytes": 50000000000,
        "hardlink_count": 3,
        "resolution": "2160p",
        "codec": "x265",
        "quality": "BluRay",
        "is_protected": false,
        "matched_by": "suffix_match",
        "confidence": 1.0,
        "file_paths": [
          {"volume_name": "Films HD", "relative_path": "Inception/Inception.2010.2160p.mkv"},
          {"volume_name": "Torrents HD", "relative_path": "Inception.2010.2160p.x265-GROUP.mkv"},
          {"volume_name": "Cross-seed", "relative_path": "movies/Inception.2010.2160p.x265-GROUP.mkv"}
        ],
        "torrents": [
          {
            "torrent_hash": "abc123",
            "tracker_domain": "tracker-a.com",
            "ratio": 0.82,
            "seed_time_seconds": 3888000,
            "uploaded_bytes": 44023414784,
            "status": "seeding",
            "tracker_rule_satisfied": true
          }
        ]
      }
    ]
  }
}
```

**DELETE `/api/v1/movies/{id}`**
```json
// Request — suppression à la carte
{
  "media_file_ids": ["uuid-mf1"],
  "delete_radarr_reference": true,
  "delete_media_player_reference": false,
  "disable_radarr_auto_search": true
}
// Response 202
{
  "data": {
    "message": "Deletion initiated",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "executing",
    "media_files_count": 1,
    "total_paths_count": 3
  }
}
```

#### Scheduled Deletions

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/scheduled-deletions` | User | Liste des suppressions planifiées |
| `POST` | `/api/v1/scheduled-deletions` | AdvancedUser | Créer une suppression planifiée |
| `GET` | `/api/v1/scheduled-deletions/{id}` | User | Détail |
| `PUT` | `/api/v1/scheduled-deletions/{id}` | AdvancedUser | Modifier |
| `DELETE` | `/api/v1/scheduled-deletions/{id}` | AdvancedUser | Annuler |

#### Radarr

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/radarr-instances` | Admin | Liste des instances |
| `POST` | `/api/v1/radarr-instances` | Admin | Ajouter |
| `PUT` | `/api/v1/radarr-instances/{id}` | Admin | Modifier |
| `DELETE` | `/api/v1/radarr-instances/{id}` | Admin | Supprimer |
| `POST` | `/api/v1/radarr-instances/{id}/test` | Admin | Tester la connexion |
| `GET` | `/api/v1/radarr-instances/{id}/root-folders` | Admin | Lister root folders |

**Note V2.0** : Les root folders n'ont plus de `mapped_path`. Le mapping est automatique par suffixe.

#### Media Players

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/media-players` | Admin | Liste |
| `POST` | `/api/v1/media-players` | Admin | Ajouter |
| `PUT` | `/api/v1/media-players/{id}` | Admin | Modifier |
| `DELETE` | `/api/v1/media-players/{id}` | Admin | Supprimer |
| `POST` | `/api/v1/media-players/{id}/test` | Admin | Tester |

#### qBittorrent (V2.0)

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `POST` | `/api/v1/qbittorrent/sync` | Admin | Sync manuel |
| `GET` | `/api/v1/qbittorrent/sync/status` | Admin | Status dernier sync |
| `GET` | `/api/v1/qbittorrent/sync/report` | Admin | Rapport (matchés, non matchés, ambigus) |
| `PUT` | `/api/v1/qbittorrent/resolve/{hash}` | Admin | Résoudre un torrent ambigu |

#### Settings

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/settings` | Admin | Récupérer tous les settings |
| `PUT` | `/api/v1/settings` | Admin | Mettre à jour |
| `POST` | `/api/v1/settings/test-discord` | Admin | Tester webhook Discord |
| `POST` | `/api/v1/settings/test-qbittorrent` | Admin | Tester connexion qBit |

**Note V2.0** : Plus de setting `qbittorrent_path_mappings`.

#### Dashboard

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/dashboard` | Guest | Statistiques du dashboard |

**GET `/api/v1/dashboard`**
```json
{
  "data": {
    "total_movies": 450,
    "total_files": 1230,
    "total_paths": 3200,
    "total_size_bytes": 25000000000000,
    "watchers": [
      {
        "id": "uuid",
        "name": "Watcher NAS Principal",
        "status": "connected",
        "volumes_count": 3,
        "total_space_bytes": 16000000000000,
        "used_space_bytes": 12000000000000,
        "file_count": 800
      }
    ],
    "orphan_files_count": 23,
    "unmatched_torrents_count": 15,
    "upcoming_deletions_count": 2,
    "recent_activity": [
      {
        "action": "file.deleted",
        "entity_type": "media_file",
        "details": { "filename": "OldMovie.2005.720p.mkv", "paths_deleted": 2 },
        "user": "admin",
        "created_at": "2026-02-24T14:00:00Z"
      }
    ]
  }
}
```

---

## Annexe A — Regex de parsing des noms de fichiers

(Inchangé, voir V1.x)

---
