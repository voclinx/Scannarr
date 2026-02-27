# Scanarr — Architecture et Déploiement

> **Prérequis** : Aucun
> **Version** : V1.2.1

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
# Autoriser la lecture ET l'écriture des volumes médias (suppression de fichiers)
ReadWritePaths=/mnt/media /mnt/nas

[Install]
WantedBy=multi-user.target
```

**Note sur `ReadWritePaths`** : le watcher a besoin d'un accès en écriture aux volumes médias pour la suppression physique des fichiers (commande `command.files.delete`). Si le watcher ne doit pas supprimer de fichiers (mode lecture seule), utiliser `ReadOnlyPaths` à la place.

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
