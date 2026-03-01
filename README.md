# Scanarr

Application de gestion et surveillance de bibliothèque multimédia. Scanarr surveille vos volumes de fichiers, les associe à vos films Radarr et permet de planifier des suppressions automatisées avec notifications Discord.

## Fonctionnalités

- **Surveillance en temps réel** des volumes médias via le watcher Go (fsnotify)
- **Explorateur de fichiers** avec recherche, filtres et pagination
- **Gestion des films** : import depuis Radarr + enrichissement TMDB
- **Association automatique** fichiers ↔ films (API Radarr + parsing nom de fichier)
- **Suppression planifiée** avec date programmée et exécution automatique (23h55)
- **Notifications Discord** : rappels configurables X jours avant suppression
- **Détection des hardlinks** pour éviter les suppressions involontaires
- **Multi-utilisateurs** avec 4 niveaux de rôles (Admin, Avancé, Utilisateur, Invité)
- **Dashboard** avec statistiques agrégées et activité récente
- **Intégrations** : Radarr, TMDB, Plex, Jellyfin, Discord, qBittorrent

---

## Architecture

| Composant | Stack | Déploiement |
|-----------|-------|-------------|
| **API** | Symfony 7 / PHP 8.3 / Doctrine / JWT | Docker |
| **Front** | Vue.js 3 / TypeScript / PrimeVue 4 / Tailwind | Docker (Nginx) |
| **Base de données** | PostgreSQL 16 | Docker |
| **Watcher** | Go 1.22+ / fsnotify / gorilla/websocket | Binaire natif + systemd |

> Le watcher tourne **en dehors de Docker** pour un accès filesystem direct (inotify, hardlinks).

```
Scannarr/
├── api/                    # Backend Symfony 7
│   ├── src/
│   │   ├── Controller/     # Endpoints REST
│   │   ├── Entity/         # Entités Doctrine (UUID)
│   │   ├── Repository/     # Requêtes BDD
│   │   ├── Service/        # Logique métier (Radarr, TMDB, Discord...)
│   │   ├── Command/        # Commandes CLI (cron)
│   │   ├── Enum/           # Enums PHP 8.1+
│   │   └── Security/       # Voters (FileVoter, DeletionVoter)
│   ├── tests/              # PHPUnit (Unit + Functional)
│   ├── docker/             # Config Docker (nginx, supervisor, crontab)
│   └── Dockerfile
├── front/                  # Frontend Vue.js 3
│   ├── src/
│   │   ├── views/          # Pages (Dashboard, Movies, Files, Settings...)
│   │   ├── components/     # Composants réutilisables
│   │   ├── stores/         # Pinia stores
│   │   ├── composables/    # Hooks (useApi, useAuth)
│   │   └── types/          # Types TypeScript
│   ├── tests/              # Vitest
│   ├── nginx.conf
│   └── Dockerfile
├── watcher/                # Watcher Go natif
│   ├── internal/           # Packages internes (config, watcher, scanner, websocket, filter, hardlink)
│   ├── install.sh          # Script d'installation systemd
│   └── watcher.env.example
├── docker-compose.yml      # Production
├── docker-compose.dev.yml  # Développement (HMR, volumes montés)
├── docker-compose.prod.yml # Déploiement via registry (GitLab CI)
├── .gitlab-ci.yml          # Pipeline CI/CD
├── Makefile                # Commandes de gestion
└── .env.example
```

---

## Prérequis

| Composant | Version |
|-----------|---------|
| Docker | 24+ |
| Docker Compose | v2+ |
| Go | 1.22+ (compilation du watcher) |
| Git | 2+ |
| OS | Debian 12 / Ubuntu 22.04+ |

---

## Installation rapide

### 1. Cloner et configurer

```bash
git clone https://github.com/voclinx/Scannarr.git
cd Scannarr
cp .env.example .env
```

Éditez `.env` :

```env
DB_PASSWORD=votre_mot_de_passe_db
APP_SECRET=$(openssl rand -hex 32)
JWT_PASSPHRASE=$(openssl rand -hex 16)
WATCHER_AUTH_TOKEN=$(openssl rand -hex 32)
MEDIA_VOLUME_1=/chemin/vers/vos/medias
MEDIA_VOLUME_2=/chemin/vers/vos/medias4k
```

### 2. Lancer les services

```bash
docker compose up -d
```

Services démarrés :

| Service | Port | Description |
|---------|------|-------------|
| Frontend | `8585` | Interface web (point d'entrée unique) |
| WebSocket watcher | `8081` | Connexion directe du watcher natif |
| PostgreSQL | non exposé | Base de données (réseau interne Docker) |

> Les endpoints `/api/*` et `/ws/*` (navigateur) sont accessibles via le frontend (nginx proxy) sur le port `8585`.

### 3. Setup initial

Ouvrez `http://localhost:8585`. Créez le compte administrateur via la console :

```bash
docker exec scanarr-api php bin/console app:create-user \
  admin admin@scanarr.local VotreMotDePasse ROLE_ADMIN
```

### 4. Installer le watcher

```bash
cd watcher/
sudo ./install.sh
```

Éditez `/etc/scanarr/watcher.env` :

```env
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WATCH_PATHS=/chemin/vers/vos/medias,/chemin/vers/vos/medias4k
SCANARR_AUTH_TOKEN=meme_token_que_WATCHER_AUTH_TOKEN
SCANARR_SCAN_ON_START=true
SCANARR_LOG_LEVEL=info
```

```bash
sudo systemctl enable --now scanarr-watcher
```

> Pour une installation détaillée (reverse proxy, sauvegarde, dépannage), voir [INSTALLATION.md](docs/INSTALLATION.md).

---

## Développement

### Lancer en mode dev

```bash
# Ajouter dans .env :
# COMPOSE_FILE=docker-compose.yml:docker-compose.dev.yml
# FRONT_CONTAINER_PORT=5173

make up     # ou: docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

| Service | Port dev | Description |
|---------|----------|-------------|
| API REST | `8082` | Avec hot-reload Symfony |
| WebSocket | `8083` | Rechargement auto |
| Frontend | `3001` | Vite HMR |
| PostgreSQL | `5433` | Isolé du prod |

### Commandes Makefile

```bash
# Docker
make up                   # Démarrer (dev)
make down                 # Arrêter
make restart              # Redémarrer
make logs                 # Voir les logs

# API
make api-shell            # Shell dans le conteneur API
make db-migrate           # Lancer les migrations
make db-diff              # Générer une migration
make composer-install     # Installer les dépendances

# Frontend
make front-shell          # Shell dans le conteneur front
make npm-install          # npm ci
make npm-build            # Build production

# Tests
make test                 # Tous les tests (API + Front + Go)
make test-api             # PHPUnit
make test-api-unit        # PHPUnit — tests unitaires
make test-api-functional  # PHPUnit — tests fonctionnels
make test-front           # Vitest
make test-go              # Go tests

# Qualité de code
make quality              # Tous les checks (dry-run)
make cs-check             # PHP CS Fixer (dry-run)
make cs-fix               # PHP CS Fixer (fix)
make rector               # Rector (fix)
make rector-check         # Rector (dry-run)
make phpmd                # PHPMD

# Watcher
make watcher-build        # Compiler le binaire Go
make watcher-run          # Lancer le watcher localement
```

---

## Tests

| Suite | Framework | Commande | Couverture |
|-------|-----------|----------|------------|
| API — Unit | PHPUnit 12 | `make test-api-unit` | Voters, WebSocket processor |
| API — Functional | PHPUnit 12 | `make test-api-functional` | Auth, Volumes, Movies, Deletions, RBAC |
| Frontend | Vitest 4 | `make test-front` | Stores, Views (Login, Files, Movies, Settings...) |
| Watcher | Go testing | `make test-go` | Config, Filter, Scanner, WebSocket client |

```bash
# Lancer tous les tests d'un coup
make test

# Avec couverture
make test-api-coverage
make test-front-coverage
make test-go-coverage
```

---

## CI/CD (GitHub Actions)

Le workflow `.github/workflows/docker-build-push.yml` se déclenche sur chaque push sur `master` et sur les tags `v*`.

```
push master ──► build-api ──► ghcr.io/voclinx/scannarr-api:latest
             ├► build-front ──► ghcr.io/voclinx/scannarr-front:latest
             └► build-watcher ──► artifacts (amd64 + arm64)

tag v*       ──► idem + attach binaires watcher à la GitHub Release
```

### Jobs

| Job | Déclencheur | Résultat |
|-----|-------------|---------|
| `build-watcher` | push master, tag `v*` | Binaires Go linux/amd64 + linux/arm64 |
| `build-api` | push master, tag `v*` | Image `ghcr.io/…/scannarr-api` (amd64 + arm64) |
| `build-front` | push master, tag `v*` | Image `ghcr.io/…/scannarr-front` (amd64 + arm64) |

### Configuration requise

Dans **GitHub → Settings → Secrets and variables → Actions** :

| Secret | Description |
|--------|-------------|
| `JWT_PASSPHRASE` | Passphrase utilisée pour générer les clés JWT dans l'image — **doit correspondre à la valeur `JWT_PASSPHRASE` configurée dans Portainer** |

> `GITHUB_TOKEN` est fourni automatiquement par GitHub Actions pour pousser les images sur `ghcr.io`.

### Déployer une release

```bash
git tag v1.0.0
git push --tags
```

Le pipeline build les images Docker (amd64 + arm64), les pousse sur `ghcr.io`, et attache les binaires watcher à la release GitHub.

### Déploiement Synology / Portainer

Voir **[docs/DEPLOYMENT_SYNOLOGY.md](docs/DEPLOYMENT_SYNOLOGY.md)** pour la procédure complète.

---

## Correspondance des chemins

Le watcher envoie les **chemins hôte** (ex: `/mnt/media1/film.mkv`). L'API traduit ces chemins via la table `volumes` :

| Champ | Exemple | Description |
|-------|---------|-------------|
| `path` | `/mnt/volume1` | Chemin vu par l'API dans Docker |
| `host_path` | `/mnt/media/movies` | Chemin réel sur le serveur |

La correspondance est définie dans `docker-compose.yml` :

```yaml
volumes:
  - /mnt/media/movies:/mnt/volume1:rw    # MEDIA_VOLUME_1
  - /mnt/media/movies4k:/mnt/volume2:rw  # MEDIA_VOLUME_2
```

---

## Tâches automatiques

| Heure | Commande | Description |
|-------|----------|-------------|
| **23:55** | `scanarr:process-deletions` | Exécute les suppressions planifiées du jour |
| **09:00** | `scanarr:send-reminders` | Envoie les rappels Discord |

Exécution manuelle :

```bash
docker exec scanarr-api php bin/console scanarr:process-deletions -v
docker exec scanarr-api php bin/console scanarr:send-reminders -v
docker exec scanarr-api php bin/console scanarr:sync-radarr -v
```

---

## Licence

Projet privé.
