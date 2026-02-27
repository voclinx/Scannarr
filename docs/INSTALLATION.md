# Installation Production — Scanarr

Guide d'installation de Scanarr sur un serveur de production.

**Architecture :**
- **Docker** : PostgreSQL 16, API Symfony (PHP-FPM + Nginx + WebSocket + Cron), Frontend Vue.js (Nginx)
- **Hote natif** : Watcher Go (binaire + systemd)

---

## Prerequis

| Composant | Version minimum |
|-----------|----------------|
| Docker | 24+ |
| Docker Compose | v2+ |
| Go | 1.22+ (pour compiler le watcher) |
| Git | 2+ |
| OS | Debian 12 / Ubuntu 22.04+ (ou equivalent) |

Le serveur doit avoir acces aux volumes media (NAS monte, disques locaux, etc.).

---

## 1. Cloner le projet

```bash
cd /opt
git clone https://github.com/voclinx/Scannarr.git scanarr
cd scanarr
```

---

## 2. Configuration

### 2.1 Variables d'environnement Docker

```bash
cp .env.example .env
```

Editer `.env` avec vos valeurs :

```bash
# === BASE DE DONNEES ===
DB_PASSWORD=un_mot_de_passe_fort_ici

# === SYMFONY ===
APP_ENV=prod
APP_SECRET=$(openssl rand -hex 32)
CORS_ALLOW_ORIGIN='^https?://(scanarr\.mondomaine\.fr)(:[0-9]+)?$'

# === JWT ===
JWT_PASSPHRASE=$(openssl rand -hex 16)
JWT_TOKEN_TTL=3600

# === TOKEN WATCHER (partage entre l'API et le watcher) ===
WATCHER_AUTH_TOKEN=$(openssl rand -hex 32)

# === VOLUMES MEDIA (chemins sur l'hote, montes dans le conteneur API) ===
MEDIA_VOLUME_1=/mnt/media/movies
MEDIA_VOLUME_2=/mnt/media/movies4k
```

> **Important :** Le `WATCHER_AUTH_TOKEN` doit etre identique dans `.env` (Docker) et dans `/etc/scanarr/watcher.env` (watcher natif).

### 2.2 Volumes media supplementaires

Par defaut, `docker-compose.yml` monte 2 volumes media. Pour en ajouter, editez `docker-compose.yml` dans la section `api.volumes` :

```yaml
volumes:
  - ${MEDIA_VOLUME_1:-/mnt/media1}:/mnt/volume1:rw
  - ${MEDIA_VOLUME_2:-/mnt/media2}:/mnt/volume2:rw
  - ${MEDIA_VOLUME_3:-/mnt/media3}:/mnt/volume3:rw  # ajouter
```

Et dans `.env` :

```bash
MEDIA_VOLUME_3=/mnt/nas/series
```

### 2.3 Correspondance des chemins

La table `volumes` dans Scanarr a deux champs :
- **`path`** : chemin vu par l'API dans Docker (ex: `/mnt/volume1`)
- **`host_path`** : chemin reel sur le serveur hote (ex: `/mnt/media/movies`)

Exemple de correspondance :

| host_path (serveur) | path (Docker) | Volume Docker |
|---------------------|---------------|---------------|
| `/mnt/media/movies` | `/mnt/volume1` | `MEDIA_VOLUME_1` |
| `/mnt/media/movies4k` | `/mnt/volume2` | `MEDIA_VOLUME_2` |

Le watcher envoie les chemins `host_path`. L'API traduit automatiquement les prefixes.

---

## 3. Lancement Docker

### 3.1 Build et demarrage

```bash
docker compose build
docker compose up -d
```

### 3.2 Verification

```bash
# Verifier que les 3 conteneurs tournent
docker compose ps

# Verifier les logs de l'API (migrations, cache)
docker compose logs -f api

# Verifier que l'API repond
curl -s http://localhost:8080/api/v1/auth/setup-status | jq .
```

Reponse attendue :

```json
{
  "data": {
    "setup_completed": false
  }
}
```

### 3.3 Services exposes

| Service | Port | Description |
|---------|------|-------------|
| API REST | `8080` | Endpoints `/api/v1/*` |
| WebSocket | `8081` | Connexion watcher `/ws/watcher` |
| Frontend | `3000` | Interface web Vue.js |
| PostgreSQL | `5432` | Base de donnees (optionnel, retirer du compose en prod) |

---

## 4. Installation du Watcher (hote natif)

Le watcher est un binaire Go qui surveille le filesystem en temps reel. Il **ne tourne PAS dans Docker** car il a besoin d'un acces direct aux fichiers via `fsnotify`.

### 4.1 Installation automatique

```bash
cd /opt/scanarr/watcher
sudo ./install.sh
```

Le script :
1. Compile le binaire Go (`scanarr-watcher`)
2. Cree l'utilisateur systeme `scanarr`
3. Installe le binaire dans `/usr/local/bin/`
4. Copie la config dans `/etc/scanarr/watcher.env`
5. Installe le service systemd

### 4.2 Configuration du watcher

```bash
sudo nano /etc/scanarr/watcher.env
```

```bash
# URL WebSocket de l'API (port 8081)
SCANARR_WS_URL=ws://localhost:8081/ws/watcher
SCANARR_WS_RECONNECT_DELAY=5s
SCANARR_WS_PING_INTERVAL=30s

# Chemins a surveiller (les MEMES que host_path, separes par des virgules)
SCANARR_WATCH_PATHS=/mnt/media/movies,/mnt/media/movies4k

# Scanner tous les volumes au demarrage
SCANARR_SCAN_ON_START=true

# Niveau de log : debug, info, warn, error
SCANARR_LOG_LEVEL=info

# Token d'authentification (DOIT correspondre a WATCHER_AUTH_TOKEN dans .env Docker)
SCANARR_AUTH_TOKEN=meme_token_que_dans_env_docker
```

### 4.3 Permissions du watcher

L'utilisateur `scanarr` doit pouvoir lire les volumes media :

```bash
# Ajouter l'utilisateur scanarr au groupe qui a acces aux medias
sudo usermod -aG media scanarr  # adapter le groupe

# Ou ajuster les permissions des repertoires
sudo chmod -R o+rx /mnt/media/movies /mnt/media/movies4k
```

Si vos volumes sont sur un NAS (NFS/CIFS), verifier que le montage autorise la lecture par l'utilisateur `scanarr`.

### 4.4 Demarrage du watcher

```bash
sudo systemctl start scanarr-watcher
sudo systemctl enable scanarr-watcher   # demarrage automatique au boot
```

### 4.5 Verification

```bash
# Status du service
sudo systemctl status scanarr-watcher

# Logs en temps reel
sudo journalctl -u scanarr-watcher -f
```

Logs attendus au demarrage :

```
INFO WebSocket connected url=ws://localhost:8081/ws/watcher
INFO Starting scan path=/mnt/media/movies scan_id=startup-xxxx
INFO Scan completed path=/mnt/media/movies total_files=150 duration_ms=230
INFO Watching path=/mnt/media/movies
INFO Watching path=/mnt/media/movies4k
```

---

## 5. Setup initial (premier lancement)

### 5.1 Creer le compte administrateur

Ouvrir le navigateur sur `http://<IP_SERVEUR>:3000`.

Scanarr redirige automatiquement vers l'assistant de configuration. Remplir :
- **Email** : votre adresse email
- **Nom d'utilisateur** : admin
- **Mot de passe** : minimum 8 caracteres

### 5.2 Configurer les volumes

1. Aller dans **Parametres > Volumes**
2. Ajouter chaque volume :
   - **Nom** : `Films HD` (libre)
   - **Chemin (Docker)** : `/mnt/volume1` (chemin dans le conteneur)
   - **Chemin hote** : `/mnt/media/movies` (chemin reel sur le serveur)
   - **Type** : `local` ou `network`

### 5.3 Configurer Radarr

1. Aller dans **Parametres > Radarr**
2. Ajouter une instance :
   - **Nom** : `Radarr 4K`
   - **URL** : `http://192.168.1.10:7878`
   - **Cle API** : depuis Radarr > Settings > General > API Key
3. Cliquer **Tester la connexion**

### 5.4 Configurer Discord (optionnel)

1. Aller dans **Parametres > Discord**
2. Coller l'URL du webhook Discord
3. Configurer le nombre de jours de rappel avant suppression
4. Cliquer **Tester** pour verifier

### 5.5 Premier import

1. Aller dans **Films**
2. Cliquer **Synchroniser** pour importer les films depuis Radarr + TMDB

---

## 6. Taches automatiques (Cron)

L'API execute automatiquement via cron (dans le conteneur Docker) :

| Heure | Commande | Description |
|-------|----------|-------------|
| **23:55** | `scanarr:process-deletions` | Execute les suppressions planifiees du jour |
| **09:00** | `scanarr:send-reminders` | Envoie les rappels Discord (X jours avant suppression) |

Les logs cron sont dans le conteneur :

```bash
docker exec scanarr-api cat /var/log/scanarr/deletions.log
docker exec scanarr-api cat /var/log/scanarr/reminders.log
```

---

## 7. Reverse Proxy (recommande)

En production, placer un reverse proxy (Nginx, Traefik, Caddy) devant Scanarr pour :
- Terminaison SSL/TLS
- Nom de domaine
- Compression gzip

### Exemple Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name scanarr.mondomaine.fr;

    ssl_certificate /etc/letsencrypt/live/scanarr.mondomaine.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/scanarr.mondomaine.fr/privkey.pem;

    # Frontend
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # API
    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSocket (watcher)
    location /ws/ {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }
}
```

> **Note :** Mettre a jour `CORS_ALLOW_ORIGIN` dans `.env` et `SCANARR_WS_URL` dans `watcher.env` si vous utilisez un domaine.

---

## 8. Mise a jour

```bash
cd /opt/scanarr

# 1. Recuperer les mises a jour
git pull

# 2. Rebuild et relancer Docker
docker compose build
docker compose up -d

# Les migrations BDD s'executent automatiquement au demarrage

# 3. Recompiler le watcher si necessaire
cd watcher
sudo ./install.sh
sudo systemctl restart scanarr-watcher
```

---

## 9. Sauvegarde

### Base de donnees

```bash
docker exec scanarr-db pg_dump -U scanarr scanarr > backup_$(date +%Y%m%d).sql
```

### Restauration

```bash
cat backup_20260226.sql | docker exec -i scanarr-db psql -U scanarr scanarr
```

### Fichiers a sauvegarder

| Element | Chemin |
|---------|--------|
| Variables d'environnement | `/opt/scanarr/.env` |
| Config watcher | `/etc/scanarr/watcher.env` |
| Donnees PostgreSQL | Volume Docker `scanarr_db_data` |
| Cles JWT | Generees dans le conteneur (rebuild = nouvelles cles) |

---

## 10. Depannage

### L'API ne demarre pas

```bash
docker compose logs api
# Verifier : migrations echouees, permissions, connexion DB
```

### Le watcher ne se connecte pas

```bash
sudo journalctl -u scanarr-watcher -f
# Verifier :
#   - SCANARR_AUTH_TOKEN correspond a WATCHER_AUTH_TOKEN
#   - Le port 8081 est accessible depuis l'hote
#   - Tester : curl -v http://localhost:8081
```

### Les fichiers ne sont pas detectes

```bash
# Verifier que le watcher surveille les bons chemins
sudo journalctl -u scanarr-watcher | grep "Watching"

# Verifier les permissions
sudo -u scanarr ls /mnt/media/movies

# Verifier la limite inotify (si beaucoup de fichiers)
cat /proc/sys/fs/inotify/max_user_watches
# Augmenter si necessaire :
echo "fs.inotify.max_user_watches=524288" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

### Les suppressions planifiees ne s'executent pas

```bash
# Verifier que le cron tourne dans le conteneur
docker exec scanarr-api ps aux | grep cron

# Verifier les logs cron
docker exec scanarr-api cat /var/log/scanarr/deletions.log

# Lancer manuellement
docker exec scanarr-api php bin/console scanarr:process-deletions -v
```

### Reset complet (tout supprimer)

```bash
docker compose down -v   # supprime les conteneurs ET les volumes (BDD)
sudo systemctl stop scanarr-watcher
```
