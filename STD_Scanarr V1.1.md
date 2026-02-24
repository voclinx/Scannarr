# Scanarr — Spécification Technique Détaillée (STD)

> **Objectif de ce document** : Ce document est une spécification technique exhaustive destinée à être utilisée comme prompt pour un agent de développement IA (Claude Code). Il doit permettre de réaliser le projet Scanarr dans son intégralité avec le minimum d'ambiguïtés et d'erreurs.

---

## Table des matières

1. [Vue d'ensemble du projet](#1-vue-densemble-du-projet)
2. [Architecture et stack technique](#2-architecture-et-stack-technique)
3. [Structure des repositories](#3-structure-des-repositories)
4. [Base de données — Schéma PostgreSQL](#4-base-de-données--schéma-postgresql)
5. [Back-end Symfony — API REST](#5-back-end-symfony--api-rest)
6. [Front-end Vue.js](#6-front-end-vuejs)
7. [Watcher Go](#7-watcher-go)
8. [Authentification et autorisation](#8-authentification-et-autorisation)
9. [Intégrations externes](#9-intégrations-externes)
10. [Suppression planifiée](#10-suppression-planifiée)
11. [Notifications Discord](#11-notifications-discord)
12. [Docker et déploiement](#12-docker-et-déploiement)
13. [Cas de test](#13-cas-de-test)
14. [Ordre d'implémentation](#14-ordre-dimplémentation)

---

## 1. Vue d'ensemble du projet

### 1.1 Description

Scanarr est une application de gestion et surveillance de bibliothèque multimédia. Elle permet de :

- Centraliser la vue de tous les fichiers médias répartis sur plusieurs volumes (locaux + réseau NFS/SMB).
- Surveiller en temps réel les changements filesystem via un watcher Go.
- Intégrer Radarr (multi-instances), TMDB, Plex, Jellyfin, qBittorrent.
- Gérer la suppression fine (unitaire, globale, planifiée) des fichiers.
- Contrôler les accès via un système de rôles (Admin, Utilisateur avancé, Utilisateur, Invité).

### 1.2 Les 3 composants

| Composant | Technologie | Port par défaut | Rôle |
|-----------|------------|----------------|------|
| `scanarr-api` | Symfony 7 (PHP 8.3) | 8080 | API REST, logique métier, scheduler |
| `scanarr-front` | Vue.js 3 + Vite + Pinia + Vue Router | 3000 | SPA, interface utilisateur |
| `scanarr-watcher` | Go 1.22+ (binaire natif + systemd) | — | Daemon natif : écoute filesystem + scan + WebSocket client |
| `scanarr-db` | PostgreSQL 16 | 5432 | Base de données |

---

## 2. Architecture et stack technique

### 2.1 Back-end (scanarr-api)

- **Framework** : Symfony 7.x
- **PHP** : 8.3+
- **ORM** : Doctrine ORM
- **Auth** : JWT (lexik/jwt-authentication-bundle)
- **Serialization** : Symfony Serializer
- **Validation** : Symfony Validator
- **Scheduler** : symfony/scheduler (pour les suppressions planifiées)
- **WebSocket server** : Ratchet (cboden/ratchet) — écoute les connexions du watcher
- **HTTP client** : symfony/http-client (pour appels API Radarr, TMDB, Plex, Jellyfin, qBittorrent)
- **Migration** : Doctrine Migrations
- **Tests** : PHPUnit + Symfony test bundle

### 2.2 Front-end (scanarr-front)

- **Framework** : Vue.js 3 (Composition API + `<script setup>`)
- **Build tool** : Vite
- **State management** : Pinia
- **Router** : Vue Router 4
- **HTTP** : Axios
- **UI** : PrimeVue 4 (composants de tableau, modales, formulaires, toasts)
- **Icons** : PrimeIcons
- **CSS** : Tailwind CSS 3
- **Tests** : Vitest + Vue Test Utils

### 2.3 Watcher (scanarr-watcher)

- **Langage** : Go 1.22+
- **File watching** : `github.com/fsnotify/fsnotify`
- **WebSocket** : `github.com/gorilla/websocket`
- **Config** : variables d'environnement
- **Logging** : `log/slog` (structured logging)
- **Tests** : Go testing standard

### 2.4 Communication entre composants

```
┌─────────────┐     WebSocket (ws://scanarr-api:8081)     ┌──────────────┐
│   Watcher   │ ──────────────────────────────────────────▶│   Back-end   │
│    (Go)     │   JSON messages (events + scan results)    │  (Symfony)   │
└─────────────┘                                            └──────┬───────┘
                                                                  │
                                                           REST API (JSON)
                                                                  │
                                                           ┌──────▼───────┐
                                                           │  Front-end   │
                                                           │   (Vue.js)   │
                                                           └──────────────┘
```

- **Watcher → API** : WebSocket client. Le watcher (binaire natif sur le serveur hôte) se connecte au serveur WebSocket Ratchet intégré dans Symfony (port 8081). L'URL est configurable (ex: `ws://localhost:8081/ws/watcher` si l'API tourne en Docker avec le port exposé, ou `ws://192.168.1.10:8081/ws/watcher` si sur une autre machine).
- **Front → API** : HTTP REST (port 8080). Toutes les opérations CRUD passent par l'API REST.
- **API → Services externes** : HTTP client vers Radarr, TMDB, Plex, Jellyfin, qBittorrent.

---

## 3. Structure des repositories

### 3.1 Monorepo recommandé

```
scanarr/
├── docker-compose.yml
├── docker-compose.dev.yml
├── .env.example
├── README.md
│
├── api/                          # Symfony back-end
│   ├── Dockerfile
│   ├── composer.json
│   ├── config/
│   │   ├── packages/
│   │   │   ├── doctrine.yaml
│   │   │   ├── security.yaml
│   │   │   ├── lexik_jwt_authentication.yaml
│   │   │   └── messenger.yaml
│   │   ├── routes/
│   │   │   └── api.yaml
│   │   └── services.yaml
│   ├── migrations/
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── AuthController.php
│   │   │   ├── UserController.php
│   │   │   ├── VolumeController.php
│   │   │   ├── FileController.php
│   │   │   ├── MovieController.php
│   │   │   ├── RadarrController.php
│   │   │   ├── MediaPlayerController.php
│   │   │   ├── ScheduledDeletionController.php
│   │   │   ├── SettingController.php
│   │   │   └── DashboardController.php
│   │   ├── Entity/
│   │   │   ├── User.php
│   │   │   ├── Volume.php
│   │   │   ├── MediaFile.php
│   │   │   ├── Movie.php
│   │   │   ├── MovieFile.php
│   │   │   ├── RadarrInstance.php
│   │   │   ├── MediaPlayerInstance.php
│   │   │   ├── ScheduledDeletion.php
│   │   │   ├── ScheduledDeletionItem.php
│   │   │   ├── Setting.php
│   │   │   └── ActivityLog.php
│   │   ├── Repository/
│   │   ├── Service/
│   │   │   ├── RadarrService.php
│   │   │   ├── TmdbService.php
│   │   │   ├── PlexService.php
│   │   │   ├── JellyfinService.php
│   │   │   ├── QBittorrentService.php
│   │   │   ├── FileAnalyzerService.php
│   │   │   ├── MovieMatcherService.php
│   │   │   ├── DeletionService.php
│   │   │   ├── DiscordNotificationService.php
│   │   │   └── WatcherMessageHandler.php
│   │   ├── WebSocket/
│   │   │   ├── WatcherWebSocketServer.php
│   │   │   └── WatcherMessageProcessor.php
│   │   ├── Command/
│   │   │   ├── RunWebSocketServerCommand.php
│   │   │   ├── ProcessScheduledDeletionsCommand.php
│   │   │   ├── SendDeletionRemindersCommand.php
│   │   │   └── SyncRadarrCommand.php
│   │   ├── Security/
│   │   │   └── Voter/
│   │   │       ├── FileVoter.php
│   │   │       └── DeletionVoter.php
│   │   ├── Enum/
│   │   │   ├── UserRole.php
│   │   │   ├── VolumeType.php
│   │   │   ├── VolumeStatus.php
│   │   │   ├── FileEventType.php
│   │   │   └── DeletionStatus.php
│   │   └── EventListener/
│   │       └── ActivityLogListener.php
│   ├── tests/
│   │   ├── Controller/
│   │   ├── Service/
│   │   └── WebSocket/
│   └── .env
│
├── front/                        # Vue.js front-end
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   ├── vite.config.ts
│   ├── tailwind.config.js
│   ├── tsconfig.json
│   ├── index.html
│   ├── src/
│   │   ├── main.ts
│   │   ├── App.vue
│   │   ├── router/
│   │   │   └── index.ts
│   │   ├── stores/
│   │   │   ├── auth.ts
│   │   │   ├── volumes.ts
│   │   │   ├── files.ts
│   │   │   ├── movies.ts
│   │   │   ├── settings.ts
│   │   │   └── notifications.ts
│   │   ├── composables/
│   │   │   ├── useApi.ts
│   │   │   ├── useAuth.ts
│   │   │   └── useConfirmation.ts
│   │   ├── views/
│   │   │   ├── LoginView.vue
│   │   │   ├── SetupWizardView.vue
│   │   │   ├── DashboardView.vue
│   │   │   ├── FileExplorerView.vue
│   │   │   ├── MoviesListView.vue
│   │   │   ├── MovieDetailView.vue
│   │   │   ├── ScheduledDeletionsView.vue
│   │   │   ├── SettingsView.vue
│   │   │   └── UsersManagementView.vue
│   │   ├── components/
│   │   │   ├── layout/
│   │   │   │   ├── AppSidebar.vue
│   │   │   │   ├── AppHeader.vue
│   │   │   │   └── AppLayout.vue
│   │   │   ├── files/
│   │   │   │   ├── FileTable.vue
│   │   │   │   ├── FileRow.vue
│   │   │   │   └── FileDeleteModal.vue
│   │   │   ├── movies/
│   │   │   │   ├── MovieTable.vue
│   │   │   │   ├── MovieRow.vue
│   │   │   │   ├── MovieDetail.vue
│   │   │   │   ├── MovieFileList.vue
│   │   │   │   └── MovieGlobalDeleteModal.vue
│   │   │   ├── deletion/
│   │   │   │   ├── ScheduledDeletionForm.vue
│   │   │   │   └── ScheduledDeletionList.vue
│   │   │   ├── settings/
│   │   │   │   ├── RadarrSettings.vue
│   │   │   │   ├── MediaPlayerSettings.vue
│   │   │   │   ├── VolumeSettings.vue
│   │   │   │   ├── TorrentSettings.vue
│   │   │   │   └── DiscordSettings.vue
│   │   │   └── common/
│   │   │       ├── ConfirmModal.vue
│   │   │       ├── StatusBadge.vue
│   │   │       └── StatsCard.vue
│   │   ├── types/
│   │   │   └── index.ts
│   │   └── utils/
│   │       ├── formatters.ts
│   │       └── constants.ts
│   └── tests/
│
├── watcher/                      # Go watcher (binaire natif, hors Docker)
│   ├── go.mod
│   ├── go.sum
│   ├── main.go
│   ├── install.sh                # Script d'installation automatisé
│   ├── scanarr-watcher.service   # Fichier service systemd
│   ├── watcher.env.example       # Template de configuration
│   ├── cmd/
│   │   └── root.go
│   ├── internal/
│   │   ├── config/
│   │   │   └── config.go
│   │   ├── watcher/
│   │   │   ├── watcher.go
│   │   │   └── watcher_test.go
│   │   ├── scanner/
│   │   │   ├── scanner.go
│   │   │   └── scanner_test.go
│   │   ├── websocket/
│   │   │   ├── client.go
│   │   │   └── client_test.go
│   │   └── models/
│   │       └── events.go
│   └── tests/
│       └── integration_test.go
│
└── docs/
    ├── CDC_Scanarr.md
    └── STD_Scanarr.md
```

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
    detected_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(volume_id, file_path)
);

CREATE INDEX idx_media_files_volume ON media_files(volume_id);
CREATE INDEX idx_media_files_radarr ON media_files(is_linked_radarr);
CREATE INDEX idx_media_files_name ON media_files(file_name);
```

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
    -- 'pending', 'reminder_sent', 'executing', 'completed', 'failed', 'cancelled'
    delete_physical_files BOOLEAN NOT NULL DEFAULT true,
    delete_radarr_reference BOOLEAN NOT NULL DEFAULT false,
    delete_media_player_reference BOOLEAN NOT NULL DEFAULT false,
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
// Response 200
{
  "data": {
    "message": "Movie deletion completed",
    "files_deleted": 2,
    "radarr_dereferenced": true,
    "radarr_auto_search_disabled": true,
    "media_player_reference_kept": true
  }
}
```

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

## 6. Front-end Vue.js

### 6.1 Routes

```typescript
const routes = [
  // Public
  { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
  { path: '/setup', name: 'setup', component: SetupWizardView, meta: { guest: true } },

  // Authenticated (AppLayout wrapper)
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: DashboardView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'files', name: 'files', component: FileExplorerView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies', name: 'movies', component: MoviesListView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies/:id', name: 'movie-detail', component: MovieDetailView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'deletions', name: 'deletions', component: ScheduledDeletionsView, meta: { minRole: 'ROLE_USER' } },
      { path: 'settings', name: 'settings', component: SettingsView, meta: { minRole: 'ROLE_ADMIN' } },
      { path: 'users', name: 'users', component: UsersManagementView, meta: { minRole: 'ROLE_ADMIN' } },
    ]
  }
];
```

### 6.2 Types TypeScript

```typescript
// types/index.ts

export type UserRole = 'ROLE_ADMIN' | 'ROLE_ADVANCED_USER' | 'ROLE_USER' | 'ROLE_GUEST';

export interface User {
  id: string;
  email: string;
  username: string;
  role: UserRole;
  is_active: boolean;
  created_at: string;
  last_login_at?: string;
}

export interface Volume {
  id: string;
  name: string;
  path: string;
  host_path: string;
  type: 'local' | 'network';
  status: 'active' | 'inactive' | 'error';
  total_space_bytes?: number;
  used_space_bytes?: number;
  last_scan_at?: string;
}

export interface MediaFile {
  id: string;
  volume_id: string;
  volume_name: string;
  file_path: string;
  file_name: string;
  file_size_bytes: number;
  hardlink_count: number;
  resolution?: string;
  codec?: string;
  quality?: string;
  is_linked_radarr: boolean;
  is_linked_media_player: boolean;
  detected_at: string;
}

export interface Movie {
  id: string;
  tmdb_id?: number;
  title: string;
  original_title?: string;
  year?: number;
  synopsis?: string;
  poster_url?: string;
  backdrop_url?: string;
  genres?: string;
  rating?: number;
  runtime_minutes?: number;
  file_count: number;
  max_file_size_bytes: number;
  files_summary: MovieFileSummary[];
  is_monitored_radarr: boolean;
}

export interface MovieFileSummary {
  id: string;
  file_name: string;
  file_size_bytes: number;
  resolution: string;
  volume_name: string;
}

export interface MovieDetail extends Movie {
  files: MovieFileDetail[];
  radarr_instance?: { id: string; name: string };
  radarr_monitored: boolean;
}

export interface MovieFileDetail extends MediaFile {
  matched_by: 'radarr_api' | 'filename_parse' | 'manual';
  confidence: number;
}

export interface ScheduledDeletion {
  id: string;
  scheduled_date: string;
  execution_time: string;
  status: 'pending' | 'reminder_sent' | 'executing' | 'completed' | 'failed' | 'cancelled';
  delete_physical_files: boolean;
  delete_radarr_reference: boolean;
  delete_media_player_reference: boolean;
  reminder_days_before: number;
  items_count: number;
  total_files_count: number;
  created_by: string;
  created_at: string;
}

export interface RadarrInstance {
  id: string;
  name: string;
  url: string;
  api_key: string;
  is_active: boolean;
  root_folders?: RadarrRootFolder[];
  last_sync_at?: string;
}

export interface RadarrRootFolder {
  id: number;
  path: string;
  mapped_path?: string;
}

export interface MediaPlayerInstance {
  id: string;
  name: string;
  type: 'plex' | 'jellyfin';
  url: string;
  token: string;
  is_active: boolean;
}

export interface DashboardStats {
  total_movies: number;
  total_files: number;
  total_size_bytes: number;
  volumes: VolumeStats[];
  orphan_files_count: number;
  upcoming_deletions_count: number;
  recent_activity: ActivityLog[];
}

export interface VolumeStats {
  id: string;
  name: string;
  total_space_bytes: number;
  used_space_bytes: number;
  file_count: number;
}

export interface ActivityLog {
  action: string;
  entity_type: string;
  details: Record<string, unknown>;
  user: string;
  created_at: string;
}
```

### 6.3 Stores Pinia

#### Auth Store (`stores/auth.ts`)

```typescript
// Structure du store
interface AuthState {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  isAuthenticated: boolean;
}

// Actions
// login(email, password) → appel POST /auth/login, stocke tokens en localStorage
// logout() → supprime tokens, redirige vers /login
// refreshAccessToken() → appel POST /auth/refresh
// fetchMe() → appel GET /auth/me
// hasMinRole(role: UserRole) → boolean — vérifie la hiérarchie des rôles

// Hiérarchie des rôles (index = niveau de permission)
const ROLE_HIERARCHY = ['ROLE_GUEST', 'ROLE_USER', 'ROLE_ADVANCED_USER', 'ROLE_ADMIN'];
```

### 6.4 Composants clés — Comportement attendu

#### FileExplorerView.vue

- Sélecteur de volume en haut (dropdown avec les volumes actifs).
- Tableau PrimeVue DataTable avec les colonnes : Nom, Poids, Hardlinks, Radarr (badge vert/rouge), Lecteur (badge vert/rouge), Résolution, Actions.
- Barre de recherche en temps réel (debounce 300ms).
- Bouton "Supprimer" sur chaque ligne → ouvre `FileDeleteModal` avec les 2 options (physique seul / physique + Radarr).
- Bouton "Suppression globale" → ouvre modal avec avertissement recherche auto.

#### MoviesListView.vue

- Tableau PrimeVue DataTable avec les colonnes : Titre (+ année), Synopsis (tronqué), Nb fichiers, Poids max (avec tooltip), Actions (Voir / Supprimer).
- Barre de recherche + filtres (résolution, nombre de fichiers, monitored).
- Tri sur colonnes (titre, année, poids, nb fichiers).
- Clic sur une ligne → navigation vers MovieDetailView.

#### MovieDetailView.vue

- En-tête : affiche (poster TMDB à gauche), titre, année, genres, note, synopsis.
- Section "Fichiers liés" : tableau avec nom, volume, hardlinks, poids, résolution, actions.
- Bouton "Suppression globale" → ouvre `MovieGlobalDeleteModal` :
  - Liste des fichiers avec checkboxes (cochés par défaut).
  - Checkbox "Supprimer référence Radarr".
  - Checkbox "Supprimer référence lecteur multimédia".
  - Checkbox "Désactiver recherche automatique Radarr".
  - Bouton de confirmation rouge.

#### SettingsView.vue

- Navigation par onglets (Tabs PrimeVue) : Radarr, Lecteurs, Volumes, Torrent, Discord.
- Chaque onglet = composant dédié (RadarrSettings, etc.).
- RadarrSettings : liste des instances avec boutons Ajouter/Modifier/Supprimer/Tester.
- VolumeSettings : liste des volumes avec formulaire d'ajout (nom, chemin, type), bouton Scan.
- Bouton "Tester la connexion" pour chaque service externe avec feedback visuel (spinner → succès vert / erreur rouge).

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

## 8. Authentification et autorisation

### 8.1 JWT

- **Access token** : durée 1h, contient `user_id`, `username`, `role`.
- **Refresh token** : durée 30 jours, stocké en base (table `refresh_tokens` gérée par le bundle).
- Le front stocke les tokens dans `localStorage`.
- Axios interceptor : si 401 reçu → tente un refresh automatique → si échec → redirect /login.

### 8.2 Hiérarchie des rôles

```
ROLE_ADMIN > ROLE_ADVANCED_USER > ROLE_USER > ROLE_GUEST
```

Chaque rôle hérite des permissions du rôle inférieur.

### 8.3 Matrice de permissions (résumé)

| Action | Admin | Advanced | User | Guest |
|--------|-------|----------|------|-------|
| Voir le dashboard | ✅ | ✅ | ✅ | ✅ |
| Voir l'explorateur de fichiers | ✅ | ✅ | ✅ | ✅ |
| Voir la liste des films | ✅ | ✅ | ✅ | ✅ |
| Voir le détail d'un film | ✅ | ✅ | ✅ | ✅ |
| Supprimer un fichier | ✅ | ✅ (ses fichiers) | ❌ | ❌ |
| Suppression globale | ✅ | ✅ (ses fichiers) | ❌ | ❌ |
| Créer une suppression planifiée | ✅ | ✅ (ses fichiers) | ❌ | ❌ |
| Annuler une suppression planifiée | ✅ | ✅ (les siennes) | ❌ | ❌ |
| Voir les suppressions planifiées | ✅ | ✅ | ✅ | ❌ |
| Accéder aux paramètres | ✅ | ❌ | ❌ | ❌ |
| Gérer les utilisateurs | ✅ | ❌ | ❌ | ❌ |
| Déclencher un scan | ✅ | ❌ | ❌ | ❌ |
| Sync Radarr | ✅ | ❌ | ❌ | ❌ |

### 8.4 Notion de "ses fichiers" pour ROLE_ADVANCED_USER

Un utilisateur avancé peut supprimer un fichier si :

- Il a lui-même créé la suppression planifiée (champ `created_by`).
- Pour la suppression immédiate : tous les utilisateurs avancés ont accès — la restriction "ses fichiers" s'applique uniquement aux suppressions planifiées (il ne peut modifier/annuler que les siennes).

### 8.5 Watcher Authentication

Le watcher s'authentifie au WebSocket via un token statique configuré en variable d'environnement (partagé entre le watcher et l'API). Le premier message envoyé par le watcher est un message d'authentification :

```json
{
  "type": "auth",
  "data": {
    "token": "secret-watcher-token"
  }
}
```

L'API répond :

```json
{
  "type": "auth.result",
  "data": {
    "success": true
  }
}
```

---

## 9. Intégrations externes

### 9.1 Radarr (RadarrService.php)

**API utilisée** : Radarr API v3 (`/api/v3/`)

| Endpoint Radarr | Usage Scanarr |
|----------------|---------------|
| `GET /api/v3/system/status` | Test de connexion |
| `GET /api/v3/rootfolder` | Récupérer les root folders |
| `GET /api/v3/movie` | Récupérer tous les films |
| `GET /api/v3/movie/{id}` | Détail d'un film |
| `DELETE /api/v3/movie/{id}?deleteFiles=false` | Déréférencer un film |
| `GET /api/v3/movie/{id}/file` | Fichiers liés à un film |
| `DELETE /api/v3/moviefile/{id}` | Supprimer une référence de fichier |

**Logique de synchronisation** (`SyncRadarrCommand.php`) :

1. Pour chaque instance Radarr active :
   a. Appeler `GET /api/v3/movie` pour récupérer tous les films.
   b. Pour chaque film Radarr : créer/mettre à jour l'entrée dans `movies` (match par `tmdb_id`).
   c. Enrichir via TMDB si données manquantes (synopsis, affiche, etc.).
   d. Faire le lien avec les `media_files` existants (par chemin de fichier via les root folders mappés).
2. Mettre à jour `last_sync_at` sur l'instance.

### 9.2 TMDB (TmdbService.php)

**API utilisée** : TMDB API v3

| Endpoint TMDB | Usage Scanarr |
|---------------|---------------|
| `GET /3/movie/{id}?language=fr-FR` | Détails d'un film (titre FR, synopsis, genres) |
| `GET /3/movie/{id}/images` | Affiches et backdrops |
| `GET /3/search/movie?query={title}&year={year}` | Recherche de film par nom (fallback) |

**Clé API TMDB** : stockée dans les settings (`tmdb_api_key`). À ajouter dans le paramétrage.

### 9.3 Plex (PlexService.php)

**API utilisée** : Plex Media Server API

| Endpoint Plex | Usage Scanarr |
|---------------|---------------|
| `GET /` | Test de connexion (retourne info serveur) |
| `GET /library/sections` | Lister les bibliothèques |
| `GET /library/sections/{id}/all` | Lister les films d'une bibliothèque |

**Headers** : `X-Plex-Token: {token}`, `Accept: application/json`

### 9.4 Jellyfin (JellyfinService.php)

**API utilisée** : Jellyfin API

| Endpoint Jellyfin | Usage Scanarr |
|-------------------|---------------|
| `GET /System/Info` | Test de connexion |
| `GET /Items?IncludeItemTypes=Movie` | Lister les films |

**Headers** : `X-Emby-Token: {token}`

### 9.5 qBittorrent (QBittorrentService.php)

**API utilisée** : qBittorrent Web API v2

| Endpoint qBittorrent | Usage Scanarr |
|----------------------|---------------|
| `POST /api/v2/auth/login` | Authentification (retourne cookie SID) |
| `GET /api/v2/torrents/info` | Liste de tous les torrents |

**Usage** : récupérer la liste des torrents pour faire le lien avec les fichiers physiques (par `content_path`).

### 9.6 MovieMatcherService.php — Logique de liaison fichier ↔ film

**Étape 1 — Match via Radarr API** (prioritaire, confiance 1.0) :

Pour chaque instance Radarr, récupérer les films avec leurs fichiers. Matcher les fichiers Radarr avec les `media_files` en BDD via le chemin (en tenant compte du mapping root folder).

**Étape 2 — Match via parsing du nom de fichier** (fallback, confiance 0.5-0.9) :

Parser le nom du fichier pour extraire :
- Titre du film
- Année
- Résolution (720p, 1080p, 2160p)
- Codec (x264, x265, HEVC)
- Qualité (BluRay, WEB-DL, Remux, HDTV)

Regex de parsing (exemple) :

```php
// Pattern: Title.Year.Resolution.Quality.Codec-Group.ext
// Ex: Inception.2010.2160p.BluRay.x265-GROUP.mkv
$pattern = '/^(.+?)[\.\s](\d{4})[\.\s](\d{3,4}p)?[\.\s]?(BluRay|WEB-DL|WEBRip|Remux|HDTV|BDRip)?[\.\s]?(x264|x265|HEVC|AVC|H\.?264|H\.?265)?/i';
```

Puis rechercher dans la table `movies` par titre + année. Si pas de match, appeler TMDB en fallback.

**Étape 3** — Stocker le lien dans `movie_files` avec le champ `matched_by` et `confidence`.

---

## 10. Suppression planifiée

### 10.1 Flux complet

```
1. Utilisateur crée une suppression planifiée via POST /api/v1/scheduled-deletions
   → Status : "pending"
   → Items enregistrés avec leurs media_file_ids

2. Cron quotidien (SendDeletionRemindersCommand) vérifie chaque jour :
   → Pour chaque deletion "pending" dont scheduled_date - reminder_days_before <= aujourd'hui
   → Envoyer notification Discord "Rappel : X films seront supprimés le DD/MM/YYYY"
   → Status → "reminder_sent"

3. Cron quotidien (ProcessScheduledDeletionsCommand) vérifie chaque jour à 23h55 :
   → Pour chaque deletion dont scheduled_date == aujourd'hui et status in ("pending", "reminder_sent")
   → Status → "executing"
   → Pour chaque item :
     a. Si delete_physical_files : supprimer les fichiers physiques via unlink()
     b. Si delete_radarr_reference : appeler l'API Radarr pour déréférencer
     c. Si delete_media_player_reference : appeler l'API Plex/Jellyfin (V2)
     d. Mettre à jour le status de l'item ("deleted" ou "failed" + error_message)
     e. Supprimer les entrées media_files en BDD
   → Générer le execution_report (JSON)
   → Status → "completed" ou "failed" (si au moins un item failed)
   → Envoyer notification Discord de confirmation

4. L'utilisateur peut annuler à tout moment avant l'exécution :
   → DELETE /api/v1/scheduled-deletions/{id}
   → Status → "cancelled"
```

### 10.2 Commandes Symfony

```php
// ProcessScheduledDeletionsCommand.php
// Exécution : tous les jours à 23:55 via cron
// bin/console scanarr:process-deletions

// SendDeletionRemindersCommand.php
// Exécution : tous les jours à 09:00 via cron
// bin/console scanarr:send-reminders
```

### 10.3 Crontab Docker

```crontab
55 23 * * * /usr/local/bin/php /app/bin/console scanarr:process-deletions >> /var/log/scanarr/deletions.log 2>&1
0  9  * * * /usr/local/bin/php /app/bin/console scanarr:send-reminders >> /var/log/scanarr/reminders.log 2>&1
```

---

## 11. Notifications Discord

### 11.1 Format des messages

**Rappel avant suppression :**

```json
// POST vers discord_webhook_url
{
  "embeds": [
    {
      "title": "⚠️ Rappel — Suppression planifiée",
      "description": "**3 films** seront supprimés le **10/08/2026 à 23:59**.",
      "color": 16744448,
      "fields": [
        { "name": "Films concernés", "value": "• Inception (2010)\n• The Matrix (1999)\n• Avatar (2009)", "inline": false },
        { "name": "Fichiers à supprimer", "value": "5 fichiers (120 Go)", "inline": true },
        { "name": "Créé par", "value": "admin", "inline": true }
      ],
      "footer": { "text": "Scanarr — Annulez via l'interface si besoin" },
      "timestamp": "2026-08-07T09:00:00Z"
    }
  ]
}
```

**Confirmation après suppression :**

```json
{
  "embeds": [
    {
      "title": "✅ Suppression exécutée",
      "description": "**3 films** ont été supprimés avec succès.",
      "color": 3066993,
      "fields": [
        { "name": "Films supprimés", "value": "• Inception (2010) ✅\n• The Matrix (1999) ✅\n• Avatar (2009) ✅", "inline": false },
        { "name": "Espace libéré", "value": "120 Go", "inline": true },
        { "name": "Radarr déréférencé", "value": "Oui", "inline": true }
      ],
      "footer": { "text": "Scanarr" },
      "timestamp": "2026-08-10T23:59:00Z"
    }
  ]
}
```

**Rapport d'erreurs :**

```json
{
  "embeds": [
    {
      "title": "❌ Suppression — Erreurs détectées",
      "description": "La suppression planifiée du **10/08/2026** a rencontré des erreurs.",
      "color": 15158332,
      "fields": [
        { "name": "Succès", "value": "• Inception (2010) ✅\n• The Matrix (1999) ✅", "inline": false },
        { "name": "Échecs", "value": "• Avatar (2009) ❌ — Permission denied on /mnt/nas/...", "inline": false }
      ],
      "footer": { "text": "Scanarr — Vérifiez les permissions de fichiers" },
      "timestamp": "2026-08-10T23:59:00Z"
    }
  ]
}
```

---

## 12. Docker et déploiement

### 12.1 Stratégie de déploiement

| Composant | Mode de déploiement | Raison |
|-----------|---------------------|--------|
| PostgreSQL | Docker | Standard, aucun accès filesystem nécessaire |
| API Symfony | Docker | Standard, accès aux volumes médias via mount Docker |
| Front Vue.js | Docker (Nginx) | Standard, fichiers statiques |
| Watcher Go | **Binaire natif + systemd** | Accès filesystem direct, hardlinks fiables, inotify fiable sur NFS/SMB |

### 12.2 docker-compose.yml

```yaml
version: '3.8'

services:
  db:
    image: postgres:16-alpine
    container_name: scanarr-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: scanarr
      POSTGRES_USER: scanarr
      POSTGRES_PASSWORD: ${DB_PASSWORD:-scanarr_secret}
    volumes:
      - db_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U scanarr"]
      interval: 10s
      timeout: 5s
      retries: 5

  api:
    build:
      context: ./api
      dockerfile: Dockerfile
    container_name: scanarr-api
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      DATABASE_URL: postgresql://scanarr:${DB_PASSWORD:-scanarr_secret}@db:5432/scanarr
      APP_SECRET: ${APP_SECRET:-change_me_in_production}
      JWT_SECRET_KEY: '%kernel.project_dir%/config/jwt/private.pem'
      JWT_PUBLIC_KEY: '%kernel.project_dir%/config/jwt/public.pem'
      JWT_PASSPHRASE: ${JWT_PASSPHRASE:-scanarr_jwt}
      WATCHER_AUTH_TOKEN: ${WATCHER_AUTH_TOKEN:-secret-watcher-token}
      CORS_ALLOW_ORIGIN: '^https?://localhost(:[0-9]+)?$'
    ports:
      - "8080:8080"   # API REST
      - "8081:8081"   # WebSocket server (accessible par le watcher natif via localhost)
    volumes:
      # L'API a besoin d'accéder aux volumes médias pour la suppression physique
      - ${MEDIA_VOLUME_1:-/mnt/media1}:/mnt/volume1:rw
      - ${MEDIA_VOLUME_2:-/mnt/media2}:/mnt/volume2:rw

  front:
    build:
      context: ./front
      dockerfile: Dockerfile
    container_name: scanarr-front
    restart: unless-stopped
    depends_on:
      - api
    ports:
      - "3000:80"
    environment:
      VITE_API_URL: http://localhost:8080

  # NOTE : Le watcher n'est PAS dans Docker.
  # Il tourne en binaire natif sur le serveur hôte via systemd.
  # Voir section 12.5 pour l'installation et la configuration.

volumes:
  db_data:
```

### 12.3 .env.example (Docker)

```env
# Database
DB_PASSWORD=scanarr_secret

# Symfony
APP_SECRET=change_me_in_production_with_random_string
JWT_PASSPHRASE=scanarr_jwt

# Watcher auth (partagé entre l'API Docker et le watcher natif)
WATCHER_AUTH_TOKEN=secret-watcher-token

# Media volumes (host paths — montés dans le container API pour la suppression)
MEDIA_VOLUME_1=/mnt/media1
MEDIA_VOLUME_2=/mnt/media2
```

### 12.4 Dockerfiles

#### api/Dockerfile

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader

# Générer les clés JWT au build
RUN mkdir -p config/jwt && \
    openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096 -pass pass:${JWT_PASSPHRASE:-scanarr_jwt} && \
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:${JWT_PASSPHRASE:-scanarr_jwt}

# Installer cron
RUN apk add --no-cache dcron
COPY docker/crontab /etc/crontabs/root

EXPOSE 8080 8081

# Entrypoint : migrations + démarrage PHP-FPM + WebSocket server + cron
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
```

#### front/Dockerfile

```dockerfile
FROM node:20-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
```

### 12.5 Watcher — Build et installation native

Le watcher n'est **pas** déployé via Docker. C'est un binaire Go unique installé directement sur le serveur hôte.

#### Compilation du binaire

```bash
# Depuis le répertoire watcher/
cd watcher/

# Build pour Linux AMD64 (production)
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -o scanarr-watcher .

# Build pour Linux ARM64 (si serveur ARM, ex: Raspberry Pi)
CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -ldflags="-s -w" -o scanarr-watcher .
```

Le flag `-ldflags="-s -w"` réduit la taille du binaire en supprimant les symboles de debug.

#### Installation

```bash
# Copier le binaire
sudo cp scanarr-watcher /usr/local/bin/scanarr-watcher
sudo chmod +x /usr/local/bin/scanarr-watcher

# Créer le fichier de configuration
sudo mkdir -p /etc/scanarr
sudo cp watcher.env /etc/scanarr/watcher.env
sudo chmod 600 /etc/scanarr/watcher.env   # protéger le token
```

#### Fichier de configuration `/etc/scanarr/watcher.env`

```env
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WS_RECONNECT_DELAY=5s
SCANARR_WS_PING_INTERVAL=30s
SCANARR_WATCH_PATHS=/mnt/media/movies,/mnt/nas/movies4k
SCANARR_SCAN_ON_START=true
SCANARR_LOG_LEVEL=info
SCANARR_AUTH_TOKEN=secret-watcher-token
```

#### Service systemd `/etc/systemd/system/scanarr-watcher.service`

```ini
[Unit]
Description=Scanarr File Watcher
Documentation=https://github.com/your-user/scanarr
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
User=scanarr
Group=scanarr
EnvironmentFile=/etc/scanarr/watcher.env
ExecStart=/usr/local/bin/scanarr-watcher
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=scanarr-watcher

# Sécurité — limiter les capacités du processus
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
# Autoriser la lecture des volumes médias
ReadOnlyPaths=/mnt/media /mnt/nas

[Install]
WantedBy=multi-user.target
```

**Note sur `ReadOnlyPaths`** : le watcher n'a besoin que d'un accès en lecture aux volumes médias. C'est l'API (dans Docker) qui gère la suppression physique. Si le watcher devait aussi écrire, utiliser `ReadWritePaths` à la place.

#### Commandes de gestion

```bash
# Créer l'utilisateur système dédié (sans shell, sans home)
sudo useradd -r -s /usr/sbin/nologin scanarr

# Donner les permissions de lecture sur les volumes médias
sudo usermod -aG media scanarr   # si un groupe 'media' existe
# OU ajuster les ACL
sudo setfacl -R -m u:scanarr:rX /mnt/media /mnt/nas

# Activer et démarrer le service
sudo systemctl daemon-reload
sudo systemctl enable scanarr-watcher
sudo systemctl start scanarr-watcher

# Vérifier le statut
sudo systemctl status scanarr-watcher

# Voir les logs en temps réel
sudo journalctl -u scanarr-watcher -f

# Voir les logs des dernières 24h
sudo journalctl -u scanarr-watcher --since "24 hours ago"

# Redémarrer après modification de la config
sudo systemctl restart scanarr-watcher
```

#### Script d'installation automatisé `watcher/install.sh`

```bash
#!/bin/bash
set -e

INSTALL_DIR="/usr/local/bin"
CONFIG_DIR="/etc/scanarr"
SERVICE_USER="scanarr"
BINARY_NAME="scanarr-watcher"

echo "=== Scanarr Watcher — Installation ==="

# 1. Build
echo "[1/5] Compilation du binaire..."
CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o "$BINARY_NAME" .

# 2. Créer l'utilisateur système
echo "[2/5] Création de l'utilisateur système..."
if ! id "$SERVICE_USER" &>/dev/null; then
    sudo useradd -r -s /usr/sbin/nologin "$SERVICE_USER"
fi

# 3. Installer le binaire
echo "[3/5] Installation du binaire..."
sudo cp "$BINARY_NAME" "$INSTALL_DIR/$BINARY_NAME"
sudo chmod +x "$INSTALL_DIR/$BINARY_NAME"

# 4. Configuration
echo "[4/5] Installation de la configuration..."
sudo mkdir -p "$CONFIG_DIR"
if [ ! -f "$CONFIG_DIR/watcher.env" ]; then
    sudo cp watcher.env.example "$CONFIG_DIR/watcher.env"
    sudo chmod 600 "$CONFIG_DIR/watcher.env"
    sudo chown "$SERVICE_USER:$SERVICE_USER" "$CONFIG_DIR/watcher.env"
    echo "    → Éditez /etc/scanarr/watcher.env avec vos paramètres"
else
    echo "    → Configuration existante conservée"
fi

# 5. Service systemd
echo "[5/5] Installation du service systemd..."
sudo cp scanarr-watcher.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable scanarr-watcher

echo ""
echo "=== Installation terminée ==="
echo "1. Éditez /etc/scanarr/watcher.env"
echo "2. Lancez : sudo systemctl start scanarr-watcher"
echo "3. Vérifiez : sudo systemctl status scanarr-watcher"
echo "4. Logs : sudo journalctl -u scanarr-watcher -f"
```

---

## 13. Cas de test

### 13.1 Tests Back-end (PHPUnit)

#### AuthController

```
TEST-AUTH-001: Setup initial — créer le premier admin quand setup_completed=false
  → POST /api/v1/auth/setup avec email, username, password valides
  → Attendu : 201, user créé avec ROLE_ADMIN, setting setup_completed=true
  → Vérifier : password hashé en BDD (pas en clair)

TEST-AUTH-002: Setup initial — refuser si déjà complété
  → POST /api/v1/auth/setup quand setup_completed=true
  → Attendu : 403, message "Setup already completed"

TEST-AUTH-003: Login — credentials valides
  → POST /api/v1/auth/login avec email + password corrects
  → Attendu : 200, access_token + refresh_token retournés

TEST-AUTH-004: Login — credentials invalides
  → POST /api/v1/auth/login avec mauvais password
  → Attendu : 401, message "Invalid credentials"

TEST-AUTH-005: Login — compte désactivé
  → POST /api/v1/auth/login avec un compte is_active=false
  → Attendu : 401, message "Account is disabled"

TEST-AUTH-006: Refresh token — token valide
  → POST /api/v1/auth/refresh avec un refresh_token valide
  → Attendu : 200, nouveau access_token

TEST-AUTH-007: Refresh token — token expiré
  → POST /api/v1/auth/refresh avec un refresh_token expiré
  → Attendu : 401

TEST-AUTH-008: Me — utilisateur connecté
  → GET /api/v1/auth/me avec Bearer token valide
  → Attendu : 200, infos utilisateur
```

#### Permissions / Rôles

```
TEST-ROLE-001: Admin accède aux paramètres
  → GET /api/v1/settings avec token ROLE_ADMIN
  → Attendu : 200

TEST-ROLE-002: Utilisateur standard ne peut pas accéder aux paramètres
  → GET /api/v1/settings avec token ROLE_USER
  → Attendu : 403

TEST-ROLE-003: Invité ne peut pas supprimer un fichier
  → DELETE /api/v1/files/{id} avec token ROLE_GUEST
  → Attendu : 403

TEST-ROLE-004: Utilisateur avancé peut supprimer un fichier
  → DELETE /api/v1/files/{id} avec token ROLE_ADVANCED_USER
  → Attendu : 200

TEST-ROLE-005: Utilisateur avancé ne peut annuler que ses propres suppressions planifiées
  → DELETE /api/v1/scheduled-deletions/{id} avec token ROLE_ADVANCED_USER sur une deletion créée par un autre
  → Attendu : 403

TEST-ROLE-006: Admin peut annuler n'importe quelle suppression planifiée
  → DELETE /api/v1/scheduled-deletions/{id} avec token ROLE_ADMIN sur une deletion de quelqu'un d'autre
  → Attendu : 200
```

#### Volumes

```
TEST-VOL-001: Créer un volume — succès
  → POST /api/v1/volumes avec name, path, type valides
  → Attendu : 201, volume créé

TEST-VOL-002: Créer un volume — chemin dupliqué
  → POST /api/v1/volumes avec un path déjà existant
  → Attendu : 422, "Path already exists"

TEST-VOL-003: Créer un volume — chemin inexistant sur le filesystem
  → POST /api/v1/volumes avec path=/nonexistent/path
  → Attendu : 422, "Path does not exist or is not accessible"

TEST-VOL-004: Déclencher un scan
  → POST /api/v1/volumes/{id}/scan
  → Attendu : 202, message "Scan initiated"
  → Vérifier : message envoyé au watcher via WebSocket

TEST-VOL-005: Supprimer un volume
  → DELETE /api/v1/volumes/{id}
  → Attendu : 204, volume supprimé + media_files associés supprimés (CASCADE)
```

#### Films

```
TEST-MOVIE-001: Lister les films — pagination
  → GET /api/v1/movies?page=1&limit=10
  → Attendu : 200, max 10 films, meta.total correct

TEST-MOVIE-002: Lister les films — recherche par titre
  → GET /api/v1/movies?search=inception
  → Attendu : 200, résultats contenant "inception" dans le titre

TEST-MOVIE-003: Lister les films — tri par année descendant
  → GET /api/v1/movies?sort=year&order=desc
  → Attendu : 200, films triés du plus récent au plus ancien

TEST-MOVIE-004: Détail d'un film — inclut les fichiers liés
  → GET /api/v1/movies/{id}
  → Attendu : 200, objet movie avec tableau files[] rempli

TEST-MOVIE-005: Suppression globale — à la carte
  → DELETE /api/v1/movies/{id} avec file_ids, delete_radarr_reference=true
  → Attendu : 200, fichiers physiques supprimés, Radarr déréférencé
  → Vérifier : media_files supprimés en BDD, log d'activité créé

TEST-MOVIE-006: Suppression globale — film inexistant
  → DELETE /api/v1/movies/{uuid-inexistant}
  → Attendu : 404

TEST-MOVIE-007: Sync Radarr — récupération des films
  → POST /api/v1/movies/sync
  → Attendu : 202
  → Vérifier : films Radarr importés en BDD, enrichis via TMDB
```

#### Suppression planifiée

```
TEST-SCHED-001: Créer une suppression planifiée — succès
  → POST /api/v1/scheduled-deletions avec date future, items valides
  → Attendu : 201, status="pending"

TEST-SCHED-002: Créer une suppression planifiée — date passée
  → POST /api/v1/scheduled-deletions avec date dans le passé
  → Attendu : 422, "Scheduled date must be in the future"

TEST-SCHED-003: Créer une suppression planifiée — movie_id inexistant
  → POST /api/v1/scheduled-deletions avec un movie_id invalide dans items
  → Attendu : 422, "Movie not found: {id}"

TEST-SCHED-004: Annuler une suppression planifiée
  → DELETE /api/v1/scheduled-deletions/{id} (status=pending)
  → Attendu : 200, status="cancelled"

TEST-SCHED-005: Annuler une suppression déjà exécutée
  → DELETE /api/v1/scheduled-deletions/{id} (status=completed)
  → Attendu : 422, "Cannot cancel a completed deletion"

TEST-SCHED-006: Exécution automatique — vérifier que les fichiers sont supprimés à la date prévue
  → Créer une deletion pour aujourd'hui
  → Exécuter ProcessScheduledDeletionsCommand
  → Attendu : fichiers physiques supprimés, status="completed", notification Discord envoyée

TEST-SCHED-007: Exécution — fichier introuvable (déjà supprimé manuellement)
  → Créer une deletion pour un fichier qui n'existe plus sur le filesystem
  → Exécuter ProcessScheduledDeletionsCommand
  → Attendu : item status="failed", error_message="File not found", notification Discord d'erreur

TEST-SCHED-008: Rappel Discord — envoyé X jours avant
  → Créer une deletion pour dans 3 jours avec reminder_days_before=3
  → Exécuter SendDeletionRemindersCommand
  → Attendu : webhook Discord appelé, status="reminder_sent"
```

#### Watcher Message Handler

```
TEST-WH-001: Réception d'un événement file.created
  → Simuler un message WebSocket file.created
  → Attendu : media_file créé en BDD avec les bonnes infos

TEST-WH-002: Réception d'un événement file.deleted
  → Simuler un message WebSocket file.deleted pour un fichier en BDD
  → Attendu : media_file supprimé de la BDD

TEST-WH-003: Réception d'un événement file.renamed
  → Simuler un message WebSocket file.renamed
  → Attendu : media_file mis à jour (path, name)

TEST-WH-004: Réception scan.file — création de fichier
  → Simuler un message scan.file pour un fichier non existant en BDD
  → Attendu : media_file créé

TEST-WH-005: Réception scan.file — fichier déjà existant (mise à jour)
  → Simuler un message scan.file pour un fichier existant avec taille différente
  → Attendu : media_file mis à jour (size, hardlink_count)

TEST-WH-006: Réception scan.completed — mise à jour du volume
  → Simuler un message scan.completed
  → Attendu : volume.last_scan_at mis à jour, total_space et used_space mis à jour
```

### 13.2 Tests Front-end (Vitest)

```
TEST-FRONT-001: LoginView — connexion réussie redirige vers dashboard
TEST-FRONT-002: LoginView — erreur affichée si credentials invalides
TEST-FRONT-003: SetupWizard — formulaire de création admin affiché si non configuré
TEST-FRONT-004: AppLayout — sidebar masque les liens selon le rôle
TEST-FRONT-005: MoviesListView — recherche filtre le tableau
TEST-FRONT-006: MoviesListView — clic sur ligne navigue vers détail
TEST-FRONT-007: MovieDetailView — affiche les infos du film + fichiers
TEST-FRONT-008: MovieGlobalDeleteModal — checkboxes fonctionnent et envoient la bonne requête
TEST-FRONT-009: FileExplorerView — changement de volume recharge les fichiers
TEST-FRONT-010: ScheduledDeletionForm — validation de date future
TEST-FRONT-011: SettingsView — onglets navigation entre les composants
TEST-FRONT-012: RadarrSettings — test connexion affiche succès/erreur
TEST-FRONT-013: Auth store — refresh token automatique sur 401
TEST-FRONT-014: Auth guard — redirige vers login si non authentifié
TEST-FRONT-015: Auth guard — redirige vers dashboard si rôle insuffisant
```

### 13.3 Tests Watcher Go

```
TEST-GO-001: Config — charge correctement les variables d'environnement
TEST-GO-002: Config — valeurs par défaut si env vars manquantes
TEST-GO-003: Watcher — détecte la création d'un fichier .mkv
TEST-GO-004: Watcher — détecte la suppression d'un fichier
TEST-GO-005: Watcher — détecte le renommage d'un fichier
TEST-GO-006: Watcher — ignore les fichiers temporaires (.part, .tmp)
TEST-GO-007: Watcher — ignore les fichiers cachés (.*) 
TEST-GO-008: Watcher — ignore les dossiers système (@eaDir, .Trash)
TEST-GO-009: Scanner — scan récursif retourne tous les fichiers médias
TEST-GO-010: Scanner — calcule correctement le nombre de hardlinks
TEST-GO-011: Scanner — ignore les fichiers non-médias
TEST-GO-012: Scanner — envoie scan.progress toutes les 100 fichiers
TEST-GO-013: Scanner — envoie scan.completed avec les bonnes stats
TEST-GO-014: WebSocket client — connexion réussie avec token valide
TEST-GO-015: WebSocket client — reconnexion automatique après déconnexion
TEST-GO-016: WebSocket client — reconnexion avec backoff exponentiel
TEST-GO-017: WebSocket client — envoie un ping périodique
TEST-GO-018: WebSocket client — réception et exécution de command.scan
TEST-GO-019: WebSocket client — réception et exécution de command.watch.add
TEST-GO-020: WebSocket client — réception et exécution de command.watch.remove
```

### 13.4 Tests d'intégration

```
TEST-INT-001: Flux complet — ajout d'un volume → scan → fichiers visibles dans l'explorateur
  1. POST /api/v1/volumes (créer un volume)
  2. POST /api/v1/volumes/{id}/scan (déclencher scan)
  3. Le watcher scanne et envoie les fichiers via WebSocket
  4. GET /api/v1/files?volume_id={id} → fichiers présents

TEST-INT-002: Flux complet — sync Radarr → films liés aux fichiers
  1. Configurer une instance Radarr (POST /api/v1/radarr-instances)
  2. Déclencher sync (POST /api/v1/movies/sync)
  3. GET /api/v1/movies → films présents avec file_count > 0

TEST-INT-003: Flux complet — suppression planifiée de bout en bout
  1. Créer une deletion planifiée pour aujourd'hui
  2. Vérifier que le fichier existe physiquement
  3. Exécuter ProcessScheduledDeletionsCommand
  4. Vérifier que le fichier n'existe plus physiquement
  5. Vérifier que media_file est supprimé en BDD
  6. Vérifier que la notification Discord a été envoyée (mock)

TEST-INT-004: Flux complet — watcher détecte un nouveau fichier → matching avec film
  1. Volume configuré + films synchro depuis Radarr
  2. Simuler création d'un fichier dans le volume
  3. Watcher envoie file.created
  4. Back-end crée le media_file + tente le matching
  5. GET /api/v1/movies/{id} → fichier présent dans la liste

TEST-INT-005: Flux complet — suppression globale d'un film
  1. Film avec 3 fichiers sur 2 volumes
  2. DELETE /api/v1/movies/{id} avec tous les file_ids + delete_radarr=true
  3. Vérifier : 3 fichiers supprimés physiquement
  4. Vérifier : référence Radarr supprimée (mock API)
  5. Vérifier : activity_log créé
```

---

## 14. Ordre d'implémentation

### Phase 1 — Fondations (semaine 1-2)

```
1.1 Initialiser le monorepo (structure de dossiers)
1.2 Docker compose avec PostgreSQL + API + Front (sans watcher)
1.3 Symfony : installation + configuration Doctrine + JWT
1.4 BDD : créer toutes les migrations (tables + index)
1.5 Auth : setup wizard + login/logout + refresh + JWT
1.6 User CRUD (admin uniquement)
1.7 Vue.js : installation + routing + Pinia + Axios + PrimeVue + Tailwind
1.8 Front : LoginView + SetupWizardView + AppLayout + Auth store + guards
```

### Phase 2 — Watcher + Explorateur (semaine 3-4)

```
2.1 Go watcher : structure projet, config, WebSocket client
2.2 Go watcher : module fsnotify (watch mode)
2.3 Go watcher : module scanner (scan mode)
2.4 Go watcher : filtrage des fichiers + calcul hardlinks
2.5 Go watcher : script install.sh + fichier service systemd + watcher.env.example
2.6 Symfony : WebSocket server (Ratchet) + auth watcher par token
2.7 Symfony : WatcherMessageHandler (traitement des events)
2.8 Symfony : Volume CRUD + endpoint scan
2.9 Symfony : File listing + recherche + filtres
2.10 Front : FileExplorerView + FileTable + sélecteur de volumes
2.11 Front : FileDeleteModal (suppression simple + option Radarr)
```

### Phase 3 — Films + Intégrations (semaine 5-6)

```
3.1 Symfony : RadarrService + TmdbService
3.2 Symfony : Radarr instance CRUD + test connexion
3.3 Symfony : SyncRadarrCommand (import films + enrichissement TMDB)
3.4 Symfony : MovieMatcherService (liaison fichiers ↔ films)
3.5 Symfony : Movie listing + détail + recherche/filtres
3.6 Symfony : Movie deletion globale (à la carte)
3.7 Symfony : PlexService + JellyfinService (test connexion + liaison)
3.8 Symfony : MediaPlayer CRUD
3.9 Front : MoviesListView + MovieTable
3.10 Front : MovieDetailView + MovieFileList + MovieGlobalDeleteModal
3.11 Front : SettingsView (onglets Radarr, Lecteurs, Volumes, Torrent)
```

### Phase 4 — Suppression planifiée + Notifications (semaine 7-8)

```
4.1 Symfony : ScheduledDeletion CRUD
4.2 Symfony : DeletionService (logique de suppression)
4.3 Symfony : ProcessScheduledDeletionsCommand
4.4 Symfony : DiscordNotificationService
4.5 Symfony : SendDeletionRemindersCommand
4.6 Front : ScheduledDeletionsView + formulaire de création
4.7 Front : ScheduledDeletionList (liste avec statuts)
4.8 Front : Intégration suppression planifiée depuis MovieDetailView
4.9 Front : SettingsView — onglet Discord
```

### Phase 5 — Dashboard + Polish (semaine 9-10)

```
5.1 Symfony : DashboardController (stats agrégées)
5.2 Symfony : ActivityLog (listener Doctrine)
5.3 Front : DashboardView (cards stats, liste activité récente)
5.4 Front : UsersManagementView (CRUD users admin)
5.5 Tests : écrire tous les tests unitaires back-end
5.6 Tests : écrire tous les tests unitaires front-end
5.7 Tests : écrire les tests Go
5.8 Tests : écrire les tests d'intégration
5.9 Docker : finaliser les Dockerfiles API + Front + docker-compose prod (sans watcher)
5.10 Watcher : finaliser install.sh + systemd service + documentation installation
5.11 Documentation : README.md avec instructions d'installation (Docker + watcher natif)
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

## Annexe B — Variables d'environnement complètes

### Docker (.env pour docker-compose)

```env
# === DATABASE ===
DB_PASSWORD=scanarr_secret

# === SYMFONY ===
APP_ENV=prod
APP_SECRET=your_random_32_char_secret_here
CORS_ALLOW_ORIGIN='^https?://(localhost|scanarr\.local)(:[0-9]+)?$'

# === JWT ===
JWT_PASSPHRASE=scanarr_jwt
JWT_TOKEN_TTL=3600

# === WATCHER AUTH (partagé avec le watcher natif) ===
WATCHER_AUTH_TOKEN=secret-watcher-token

# === TMDB ===
TMDB_API_KEY=your_tmdb_api_key_here

# === VOLUMES (host paths montés dans le container API) ===
MEDIA_VOLUME_1=/mnt/media1
MEDIA_VOLUME_2=/mnt/media2
```

### Watcher natif (/etc/scanarr/watcher.env)

```env
# === WEBSOCKET ===
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WS_RECONNECT_DELAY=5s
SCANARR_WS_PING_INTERVAL=30s

# === VOLUMES À SURVEILLER (vrais chemins du serveur hôte) ===
SCANARR_WATCH_PATHS=/mnt/media1,/mnt/media2

# === COMPORTEMENT ===
SCANARR_SCAN_ON_START=true
SCANARR_LOG_LEVEL=info

# === AUTH (doit correspondre au WATCHER_AUTH_TOKEN du Docker .env) ===
SCANARR_AUTH_TOKEN=secret-watcher-token
```

> **Important** : `WATCHER_AUTH_TOKEN` (côté Docker/API) et `SCANARR_AUTH_TOKEN` (côté watcher natif) doivent avoir la même valeur. C'est le secret partagé qui authentifie le watcher auprès de l'API.
>
> **Important** : Les chemins dans `SCANARR_WATCH_PATHS` sont les vrais chemins du serveur hôte (ex: `/mnt/media1`). Les chemins dans `MEDIA_VOLUME_1`/`MEDIA_VOLUME_2` du docker-compose sont les mêmes chemins hôte, montés dans le container API. Ils doivent correspondre pour que l'API puisse retrouver les fichiers signalés par le watcher.
