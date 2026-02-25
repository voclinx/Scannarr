# Scanarr

Application de gestion et surveillance de bibliothèque multimédia. Scanarr surveille vos volumes de fichiers, les associe à vos films Radarr et permet de planifier des suppressions automatisées avec notifications Discord.

## Architecture

| Composant | Stack | Déploiement |
|-----------|-------|-------------|
| **API** | Symfony 7 / PHP 8.3 / Doctrine / JWT | Docker |
| **Front** | Vue.js 3 / TypeScript / PrimeVue / Tailwind | Docker (Nginx) |
| **Base de données** | PostgreSQL 16 | Docker |
| **Watcher** | Go 1.22+ / fsnotify / gorilla/websocket | Binaire natif + systemd |

> Le watcher tourne **en dehors de Docker** pour un accès filesystem direct (inotify, hardlinks).

## Prérequis

- Docker & Docker Compose
- Go 1.22+ (pour compiler le watcher)
- Un serveur Linux avec accès aux volumes médias

## Installation rapide

### 1. Cloner le projet

```bash
git clone https://github.com/voclinx/Scannarr.git
cd Scannarr
```

### 2. Configurer l'environnement

```bash
cp .env.example .env
```

Éditez `.env` avec vos paramètres :

```env
DB_PASSWORD=votre_mot_de_passe_db
APP_SECRET=une_chaine_aleatoire_de_32_caracteres
JWT_PASSPHRASE=votre_passphrase_jwt
WATCHER_AUTH_TOKEN=un_token_partage_avec_le_watcher
TMDB_API_KEY=votre_cle_api_tmdb
MEDIA_VOLUME_1=/chemin/vers/vos/medias
```

### 3. Lancer les services Docker

```bash
docker compose up -d
```

Cela démarre :
- **PostgreSQL** sur le port 5432
- **API Symfony** sur le port 8080 (REST) et 8081 (WebSocket)
- **Front Vue.js** sur le port 3000

### 4. Setup initial

Ouvrez `http://localhost:3000` dans votre navigateur. Un assistant de configuration vous guidera pour créer le compte administrateur.

### 5. Installer le watcher (sur le serveur hôte)

```bash
cd watcher/
chmod +x install.sh
sudo ./install.sh
```

Le script :
1. Compile le binaire Go
2. Crée l'utilisateur système `scanarr`
3. Installe le binaire dans `/usr/local/bin/`
4. Copie la configuration dans `/etc/scanarr/watcher.env`
5. Installe le service systemd

Éditez la configuration du watcher :

```bash
sudo nano /etc/scanarr/watcher.env
```

```env
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WATCH_PATHS=/chemin/vers/vos/medias
SCANARR_AUTH_TOKEN=meme_token_que_dans_le_env_docker
```

> **Important** : `SCANARR_AUTH_TOKEN` doit correspondre à `WATCHER_AUTH_TOKEN` dans le `.env` Docker.

Démarrez le watcher :

```bash
sudo systemctl start scanarr-watcher
sudo systemctl enable scanarr-watcher
```

Vérifiez le statut :

```bash
sudo systemctl status scanarr-watcher
sudo journalctl -u scanarr-watcher -f
```

## Développement

### Lancer en mode développement

```bash
# Utiliser l'overlay de développement
export COMPOSE_FILE=docker-compose.yml:docker-compose.dev.yml
docker compose up -d
```

Ports en développement :
- **API** : 8082 (REST), 8083 (WebSocket)
- **Front** : 3001 (Vite dev server avec HMR)
- **PostgreSQL** : 5433

### Structure du projet

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
│   ├── docker/             # Config Docker (nginx, supervisor, crontab)
│   └── Dockerfile
├── front/                  # Frontend Vue.js 3
│   ├── src/
│   │   ├── views/          # Pages (Dashboard, Movies, Files, Settings...)
│   │   ├── components/     # Composants réutilisables
│   │   ├── stores/         # Pinia stores
│   │   ├── composables/    # Hooks (useApi, useAuth)
│   │   └── types/          # Types TypeScript
│   ├── nginx.conf
│   └── Dockerfile
├── watcher/                # Watcher Go natif
│   ├── internal/           # Packages internes
│   │   ├── config/         # Chargement des variables d'env
│   │   ├── watcher/        # Surveillance fsnotify
│   │   ├── scanner/        # Scan récursif
│   │   ├── websocket/      # Client WebSocket
│   │   ├── hardlink/       # Détection hardlinks
│   │   └── filter/         # Filtrage fichiers
│   ├── install.sh          # Script d'installation
│   ├── scanarr-watcher.service
│   └── watcher.env.example
├── docker-compose.yml
├── docker-compose.dev.yml
└── .env.example
```

## Fonctionnalités

- **Surveillance en temps réel** des volumes médias via le watcher Go (fsnotify)
- **Explorateur de fichiers** avec recherche, filtres et pagination
- **Gestion des films** : import automatique depuis Radarr + enrichissement TMDB
- **Association automatique** fichiers ↔ films (API Radarr + parsing nom de fichier)
- **Suppression planifiée** avec date programmée et exécution automatique à 23h55
- **Notifications Discord** : rappels configurables X jours avant suppression
- **Détection des hardlinks** pour éviter les suppressions involontaires
- **Multi-utilisateurs** avec 4 niveaux de rôles (Admin, Avancé, Utilisateur, Invité)
- **Dashboard** avec statistiques agrégées et activité récente
- **Intégrations** : Radarr, TMDB, Plex, Jellyfin, Discord, qBittorrent

## Tâches planifiées

| Heure | Commande | Description |
|-------|----------|-------------|
| 23:55 | `scanarr:process-deletions` | Exécute les suppressions planifiées du jour |
| 09:00 | `scanarr:send-reminders` | Envoie les rappels Discord |

## Correspondance des chemins

Le watcher envoie les **chemins hôte** (ex: `/mnt/media1/film.mkv`). L'API traduit ces chemins via la table `volumes` qui contient :
- `path` : chemin vu par l'API dans Docker (ex: `/mnt/volume1`)
- `host_path` : chemin réel sur le serveur hôte (ex: `/mnt/media1`)

## Licence

Projet privé.
