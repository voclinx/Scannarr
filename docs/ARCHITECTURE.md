# Scanarr — Architecture et Déploiement

> **Prérequis** : Aucun
> **Version** : V2.0

---

## 1. Vue d'ensemble du projet

### 1.1 Description

Scanarr est une application de gestion et surveillance de bibliothèque multimédia. Elle permet de :

- Centraliser la vue de tous les fichiers médias répartis sur plusieurs volumes et serveurs.
- Surveiller en temps réel les changements filesystem via des watchers Go distribués.
- Identifier automatiquement les fichiers physiques par inode (hardlinks, cross-seed).
- Intégrer Radarr (multi-instances), TMDB, Plex, Jellyfin, qBittorrent.
- Gérer la suppression fine (unitaire, globale, planifiée) des fichiers avec connaissance complète des hardlinks.
- Contrôler les accès via un système de rôles (Admin, Utilisateur avancé, Utilisateur, Invité).

### 1.2 Les 3 composants

| Composant | Technologie | Port par défaut | Rôle |
|-----------|------------|----------------|------|
| `scanarr-api` | Symfony 7 (PHP 8.3) | 8080 / 8081 (WS) | API REST, logique métier, scheduler, serveur WebSocket |
| `scanarr-front` | Vue.js 3 + Vite + Pinia + Vue Router | 3000 | SPA, interface utilisateur |
| `scanarr-watcher` | Go 1.22+ (binaire natif + systemd) | — | Daemon natif : scan filesystem + inode + WebSocket client |
| `scanarr-db` | PostgreSQL 16 | 5432 | Base de données |

---

## 2. Architecture et stack technique

### 2.1 Back-end (scanarr-api)

- **Framework** : Symfony 7.x, PHP 8.3+
- **ORM** : Doctrine ORM
- **Auth** : JWT (lexik/jwt-authentication-bundle)
- **WebSocket server** : Ratchet (cboden/ratchet)
- **HTTP client** : symfony/http-client (Radarr, TMDB, Plex, Jellyfin, qBittorrent)
- **Scheduler** : symfony/scheduler
- **Tests** : PHPUnit

### 2.2 Front-end (scanarr-front)

- **Framework** : Vue.js 3 (Composition API + `<script setup>`)
- **Build** : Vite, **State** : Pinia, **Router** : Vue Router 4
- **HTTP** : Axios, **UI** : PrimeVue 4, **CSS** : Tailwind CSS 3
- **Tests** : Vitest + Vue Test Utils

### 2.3 Watcher (scanarr-watcher)

- **Langage** : Go 1.22+
- **File watching** : `github.com/fsnotify/fsnotify`
- **WebSocket** : `github.com/gorilla/websocket`
- **Config** : variables d'environnement (minimales) + config dynamique depuis l'API
- **Persistance d'état** : fichier JSON local
- **Logging** : `log/slog`

### 2.4 Communication entre composants

```
┌─────────────┐     WebSocket (ws://scanarr-api:8081)     ┌──────────────┐
│  Watcher 1  │ ──────────────────────────────────────────▶│              │
│   (Go)      │   hello/auth/config + events + commands    │  Back-end    │
└─────────────┘                                            │  (Symfony)   │
┌─────────────┐     WebSocket (ws://scanarr-api:8081)     │              │
│  Watcher 2  │ ──────────────────────────────────────────▶│              │
│   (Go)      │                                            └──────┬───────┘
└─────────────┘                                                   │
                                                           REST API (JSON)
                                                                  │
                                                           ┌──────▼───────┐
                                                           │  Front-end   │
                                                           │   (Vue.js)   │
                                                           └──────────────┘
```

- **Watcher → API** : WebSocket avec protocole hello/auth/config. Chaque watcher a son propre token.
- **Front → API** : HTTP REST (port 8080).
- **API → Services externes** : HTTP client vers Radarr, TMDB, Plex, Jellyfin, qBittorrent.

---

## 3. Structure des repositories

### 3.1 Monorepo recommandé

```
scanarr/
├── docker-compose.yml
├── .env.example
├── README.md
│
├── api/
│   ├── Dockerfile
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── AuthController.php
│   │   │   ├── UserController.php
│   │   │   ├── WatcherController.php          # V2.0 (remplace VolumeController)
│   │   │   ├── FileController.php
│   │   │   ├── MovieController.php
│   │   │   ├── RadarrController.php
│   │   │   ├── MediaPlayerController.php
│   │   │   ├── QBittorrentController.php      # V2.0
│   │   │   ├── ScheduledDeletionController.php
│   │   │   ├── SuggestionController.php
│   │   │   ├── DeletionPresetController.php
│   │   │   ├── TrackerRuleController.php
│   │   │   ├── SettingController.php
│   │   │   └── DashboardController.php
│   │   ├── Entity/
│   │   │   ├── User.php
│   │   │   ├── Watcher.php                    # V2.0 (remplace Volume)
│   │   │   ├── WatcherVolume.php              # V2.0
│   │   │   ├── MediaFile.php                  # V2.0 (inode-based)
│   │   │   ├── FilePath.php                   # V2.0 (nouveau)
│   │   │   ├── Movie.php
│   │   │   ├── MovieFile.php
│   │   │   ├── RadarrInstance.php
│   │   │   ├── MediaPlayerInstance.php
│   │   │   ├── TorrentStats.php
│   │   │   ├── TorrentStatsHistory.php
│   │   │   ├── DeletionPreset.php
│   │   │   ├── TrackerRule.php
│   │   │   ├── ScheduledDeletion.php
│   │   │   ├── ScheduledDeletionItem.php
│   │   │   ├── Setting.php
│   │   │   └── ActivityLog.php
│   │   ├── Service/
│   │   │   ├── RadarrService.php
│   │   │   ├── TmdbService.php
│   │   │   ├── PlexService.php
│   │   │   ├── JellyfinService.php
│   │   │   ├── QBittorrentService.php
│   │   │   ├── QBittorrentSyncService.php
│   │   │   ├── SuffixMatcherService.php       # V2.0
│   │   │   ├── FileAnalyzerService.php
│   │   │   ├── MovieMatcherService.php
│   │   │   ├── DeletionService.php
│   │   │   ├── ScoringService.php
│   │   │   ├── DiscordNotificationService.php
│   │   │   └── WatcherMessageHandler.php
│   │   ├── WebSocket/
│   │   │   ├── WatcherWebSocketServer.php
│   │   │   └── WatcherMessageProcessor.php
│   │   └── ...
│   └── tests/
│
├── front/
│   ├── src/
│   │   ├── views/
│   │   │   ├── SuggestionsView.vue
│   │   │   └── ...
│   │   ├── components/
│   │   │   ├── settings/
│   │   │   │   ├── WatcherSettings.vue        # V2.0 (remplace VolumeSettings)
│   │   │   │   ├── WatcherConfigDialog.vue    # V2.0
│   │   │   │   └── ...
│   │   │   └── ...
│   │   └── ...
│   └── tests/
│
├── watcher/
│   ├── main.go
│   ├── internal/
│   │   ├── config/config.go
│   │   ├── watcher/watcher.go
│   │   ├── scanner/scanner.go                 # V2.0 : remonte inode + device_id
│   │   ├── websocket/client.go
│   │   ├── state/state.go                     # V2.0 : persistance locale
│   │   └── models/events.go
│   └── tests/
│
└── docs/
```

---

## 8. Authentification et autorisation

### 8.1 JWT

- **Access token** : durée 1h, contient `user_id`, `username`, `role`.
- **Refresh token** : durée 30 jours.
- Axios interceptor : si 401 → refresh → si échec → redirect /login.

### 8.2 Hiérarchie des rôles

```
ROLE_ADMIN > ROLE_ADVANCED_USER > ROLE_USER > ROLE_GUEST
```

### 8.3 Watcher Authentication (V2.0)

Chaque watcher a un token unique généré par l'API lors de sa création. Le protocole de connexion est un handshake en 3 étapes (hello/auth/config) documenté dans [WATCHER.md](WATCHER.md) §7.2.

Le token est transmis dans le header WebSocket ou dans le premier message. L'API identifie le watcher et envoie sa configuration dynamiquement.

---

## 12. Docker et déploiement

### 12.1 Stratégie de déploiement

| Composant | Mode | Raison |
|-----------|------|--------|
| PostgreSQL | Docker | Standard |
| API Symfony | Docker | Standard, pas d'accès filesystem direct nécessaire |
| Front Vue.js | Docker (Nginx) | Fichiers statiques |
| Watcher Go | **Binaire natif + systemd** | Accès filesystem, hardlinks, inotify |

**V2.0** : L'API n'a plus besoin de monter les volumes médias. Toutes les opérations fichier (scan, suppression, hardlink) sont déléguées au watcher via WebSocket.

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

  api:
    build: ./api
    container_name: scanarr-api
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      DATABASE_URL: postgresql://scanarr:${DB_PASSWORD:-scanarr_secret}@db:5432/scanarr
      APP_SECRET: ${APP_SECRET:-change_me_in_production}
      JWT_PASSPHRASE: ${JWT_PASSPHRASE:-scanarr_jwt}
      CORS_ALLOW_ORIGIN: '^https?://localhost(:[0-9]+)?$'
    ports:
      - "8080:8080"   # API REST
      - "8081:8081"   # WebSocket (watchers se connectent ici)
    # V2.0 : plus besoin de monter les volumes médias

  front:
    build: ./front
    container_name: scanarr-front
    restart: unless-stopped
    depends_on:
      - api
    ports:
      - "3000:80"

volumes:
  db_data:
```

### 12.3 .env.example

```env
# Database
DB_PASSWORD=scanarr_secret

# Symfony
APP_SECRET=change_me_in_production_with_random_string
JWT_PASSPHRASE=scanarr_jwt
```

**V2.0** : Plus de `WATCHER_AUTH_TOKEN` global (chaque watcher a son propre token). Plus de `MEDIA_VOLUME_*` (l'API ne monte plus les volumes).

### 12.4 Watcher — Installation native

Le watcher est un binaire Go installé sur le serveur hôte. Configuration minimale :

```env
# /etc/scanarr/watcher.env
SCANARR_API_URL=ws://localhost:8081/ws/watcher
SCANARR_AUTH_TOKEN=token-généré-par-l-api
```

Le reste de la configuration (volumes, extensions) est envoyé dynamiquement par l'API au handshake.

Voir [WATCHER.md](WATCHER.md) pour les détails complets d'installation.

---

## Annexe B — Variables d'environnement complètes

### Docker (.env pour docker-compose)

```env
DB_PASSWORD=scanarr_secret
APP_SECRET=your_random_32_char_secret_here
JWT_PASSPHRASE=scanarr_jwt
TMDB_API_KEY=your_tmdb_api_key_here
```

### Watcher natif (/etc/scanarr/watcher.env)

```env
SCANARR_API_URL=ws://192.168.1.10:8081/ws/watcher
SCANARR_AUTH_TOKEN=token-from-api
SCANARR_RECONNECT_DELAY=5s
SCANARR_PING_INTERVAL=30s
SCANARR_LOG_LEVEL=info
SCANARR_STATE_FILE=/var/lib/scanarr/watcher-state.json
```
