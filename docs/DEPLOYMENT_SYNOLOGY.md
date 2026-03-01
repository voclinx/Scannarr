# Déploiement Scanarr — Synology DS923+ avec Portainer

## Prérequis

- Synology DS923+ sous DSM 7.x
- **Container Manager** (Docker) installé via le Centre de Paquets DSM
- **Portainer CE** déployé dans Container Manager
- Accès SSH activé sur le NAS

---

## Ce qui est automatique

Au premier démarrage (et à chaque mise à jour), le container `scanarr-api` exécute automatiquement :

| Étape | Mécanisme |
|-------|-----------|
| Attente de PostgreSQL | `healthcheck` + boucle `until` sur PDO |
| Migrations Doctrine | `doctrine:migrations:migrate --no-interaction` |
| Cache Symfony | `cache:clear` + `cache:warmup` |
| Démarrage des services | supervisord → nginx, php-fpm, websocket Go, cron |

**La seule action manuelle** après le premier déploiement : créer le compte administrateur (étape 3).

---

## 1. Préparer les secrets GitHub (une seule fois)

Le workflow GitHub Actions génère les clés JWT au build. La passphrase utilisée doit correspondre à la valeur runtime dans Portainer.

Dans **GitHub → Settings → Secrets and variables → Actions → New repository secret** :

| Secret | Valeur |
|--------|--------|
| `JWT_PASSPHRASE` | `openssl rand -hex 32` (valeur de votre choix, à conserver) |

---

## 2. Variables d'environnement Portainer

Lors du déploiement de la stack, renseigner ces 4 variables :

| Variable | Description | Génération |
|----------|-------------|------------|
| `DB_PASSWORD` | Mot de passe PostgreSQL | `openssl rand -hex 32` |
| `APP_SECRET` | Clé secrète Symfony | `openssl rand -hex 32` |
| `JWT_PASSPHRASE` | Passphrase JWT | **Même valeur que le secret GitHub `JWT_PASSPHRASE`** |
| `WATCHER_AUTH_TOKEN` | Token d'auth du watcher | `openssl rand -hex 32` |

> Générer les valeurs en SSH sur le NAS :
> ```bash
> ssh admin@192.168.1.91
> openssl rand -hex 32   # répéter pour chaque variable
> ```

---

## 3. Déployer la stack dans Portainer

1. Ouvrir Portainer → **Stacks** → **Add stack**
2. Nommer la stack : `scanarr`
3. Onglet **Web editor** → coller le contenu de `docker-compose.portainer.yml`
   _ou_ onglet **Upload** → choisir le fichier `docker-compose.portainer.yml`
4. Section **Environment variables** → ajouter les 4 variables ci-dessus
5. Cliquer **Deploy the stack**

Portainer attend que le container `db` soit healthy avant de démarrer `api`. Les logs du container `scanarr-api` montrent la progression :

```
Waiting for database...
Database is ready.
Running migrations...
  [OK] Already at the latest version (...)
  [OK] Cache for the 'prod' environment was successfully cleared.
  [OK] Cache for the 'prod' environment was successfully warmed.
supervisord started with pid 1
...
success: nginx entered RUNNING state
success: php-fpm entered RUNNING state
success: websocket entered RUNNING state
success: cron entered RUNNING state
```

L'interface est disponible sur : **http://192.168.1.91:8585**

---

## 4. Créer le compte administrateur (première fois uniquement)

Via la **Console** du container `scanarr-api` dans Portainer, ou en SSH :

```bash
docker exec scanarr-api php bin/console app:create-user \
  admin admin@scanarr.local VotreMotDePasse ROLE_ADMIN
```

---

## 5. Installer le Watcher sur le NAS

Le watcher est un binaire Go natif qui surveille le filesystem et communique avec l'API via WebSocket. Il tourne **en dehors de Docker** pour un accès filesystem direct.

> ⚠️ **Important** : le binaire lit ses variables de configuration via `os.Getenv()`. Il **ne charge pas** le fichier `watcher.env` automatiquement — les variables doivent être **exportées dans le shell** avant de lancer le binaire.

### 5a. Télécharger le binaire

Les binaires sont attachés aux releases GitHub :

```
https://github.com/voclinx/Scannarr/releases/latest
→ scanarr-watcher-linux-amd64   (DS923+ = processeur AMD Ryzen)
```

Déposer le binaire sur le NAS (ici dans `/volume1/docker/scannar/`) :

```bash
# Depuis votre machine locale
scp scanarr-watcher-linux-amd64 admin@192.168.1.91:/volume1/docker/scannar/scanarr-watcher-linux-amd64
ssh admin@192.168.1.91 "chmod +x /volume1/docker/scannar/scanarr-watcher-linux-amd64"
```

### 5b. Créer le fichier de configuration

```bash
ssh admin@192.168.1.91
sudo mkdir -p /etc/scanarr
sudo tee /etc/scanarr/watcher.env << 'EOF'
# URL WebSocket de l'API Scanarr
SCANARR_WS_URL=ws://192.168.1.91:8081/ws/watcher

# Identifiant unique de ce watcher (visible dans l'UI)
SCANARR_WATCHER_ID=synology-nas

# Token d'authentification (doit correspondre à WATCHER_AUTH_TOKEN dans Portainer)
SCANARR_AUTH_TOKEN=VOTRE_WATCHER_AUTH_TOKEN_ICI

# Chemin du fichier d'état (persistance entre redémarrages)
SCANARR_STATE_PATH=/etc/scanarr/watcher-state.json
EOF

# Rendre le fichier lisible par l'utilisateur courant (créé par sudo = root)
sudo chmod 644 /etc/scanarr/watcher.env
```

### 5c. Tester le watcher manuellement

Avant de configurer le démarrage automatique, tester en SSH :

```bash
ssh admin@192.168.1.91
export $(grep -v "^#" /etc/scanarr/watcher.env | xargs) && /volume1/docker/scannar/scanarr-watcher-linux-amd64
```

Le watcher doit se connecter et afficher quelque chose comme :
```
time=... level=INFO msg="Connecting to WebSocket" url=ws://192.168.1.91:8081/ws/watcher
time=... level=INFO msg="Connected"
```

`Ctrl+C` pour arrêter, puis configurer le démarrage automatique.

### 5d. Démarrage automatique via DSM Task Scheduler

Dans **Panneau de configuration** → **Planificateur de tâches** :

1. **Créer** → **Tâche déclenchée** → **Au démarrage**
2. Nom : `Scanarr Watcher`
3. Utilisateur : `root`
4. Commande :
   ```bash
   /bin/sh -c 'export $(grep -v "^#" /etc/scanarr/watcher.env | xargs) && /volume1/docker/scannar/scanarr-watcher-linux-amd64 >> /var/log/scanarr-watcher.log 2>&1 &'
   ```
5. Cocher **Activer** → Sauvegarder

Démarrer immédiatement sans redémarrer le NAS :

```bash
ssh admin@192.168.1.91
/bin/sh -c 'export $(grep -v "^#" /etc/scanarr/watcher.env | xargs) && /volume1/docker/scannar/scanarr-watcher-linux-amd64 >> /var/log/scanarr-watcher.log 2>&1 &'
```

Vérifier que le watcher tourne :

```bash
pgrep -a scanarr-watcher
```

Logs du watcher :

```bash
tail -f /var/log/scanarr-watcher.log
```

---

## 6. Configurer les volumes dans l'UI Scanarr

Une fois connecté à http://192.168.1.91:8585 :

1. **Paramètres** → **Watchers** → le watcher `synology-nas` doit apparaître comme connecté
2. **Paramètres** → **Volumes** → ajouter le volume :
   - Chemin Docker (API) : `/mnt/filmarr`
   - Chemin hôte réel : `/volume1/filmarr`

---

## 7. Mise à jour

### 7a. Mettre à jour les containers

```bash
# Portainer : Stacks → scanarr → Update the stack → activer "Re-pull image and redeploy" → Update
```

Les migrations éventuelles s'appliquent automatiquement au redémarrage.

### 7b. Mettre à jour le watcher

```bash
# Depuis votre machine locale
scp scanarr-watcher-linux-amd64 admin@192.168.1.91:/volume1/docker/scannar/scanarr-watcher-linux-amd64
ssh admin@192.168.1.91 "chmod +x /volume1/docker/scannar/scanarr-watcher-linux-amd64 && pkill scanarr-watcher-linux-amd64"
# DSM Task Scheduler le relance automatiquement au prochain démarrage du NAS
# Pour le relancer immédiatement sans redémarrer :
ssh admin@192.168.1.91 "/bin/sh -c 'export \$(grep -v \"^#\" /etc/scanarr/watcher.env | xargs) && /volume1/docker/scannar/scanarr-watcher-linux-amd64 >> /var/log/scanarr-watcher.log 2>&1 &'"
```

---

## 8. Architecture réseau

```
Synology DS923+ (192.168.1.91)
│
├── :8585  → Container scanarr-front (Nginx)
│    ├── /api/  → proxifié vers scanarr-api:8080 (REST PHP-FPM)
│    ├── /ws/   → proxifié vers scanarr-api:8081 (WebSocket navigateur)
│    └── /      → SPA Vue.js
│
├── :8081  → Container scanarr-api (WebSocket direct pour le watcher natif)
│
└── scanarr-watcher (binaire natif DSM)
     └── → ws://192.168.1.91:8081/ws/watcher
```

> **PostgreSQL** (port 5432) n'est **pas** exposé à l'extérieur — accès réseau interne Docker uniquement.

---

## 9. Dépannage

### Container scanarr-api en boucle de redémarrage

Vérifier les logs : **Portainer → Containers → scanarr-api → Logs**

| Symptôme | Cause | Solution |
|----------|-------|----------|
| `Waiting for database...` en boucle | Mauvais mot de passe DB | Vérifier que `DB_PASSWORD` dans Portainer = `POSTGRES_PASSWORD` du service `db` |
| `Unable to read "/app/.env"` | Fichier `.env` absent dans l'image | L'`entrypoint` override dans le YAML crée le fichier automatiquement — vérifier que la ligne `entrypoint:` est bien présente |
| `Environment variable not found: "JWT_SECRET_KEY"` | Variables JWT manquantes | Vérifier que `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE` sont dans l'env du service `api` |
| `passphrase mismatch` sur `/ws/watcher` | JWT_PASSPHRASE runtime ≠ build | S'assurer que `JWT_PASSPHRASE` dans Portainer = secret GitHub `JWT_PASSPHRASE` utilisé au build |

### Vérifier que tous les processus sont lancés

Dans **Portainer → Containers → scanarr-api → Logs**, en fin de démarrage :

```
success: cron entered RUNNING state
success: nginx entered RUNNING state
success: php-fpm entered RUNNING state
success: websocket entered RUNNING state   ← doit être présent
```

Si `websocket` n'est pas en RUNNING : problème JWT (voir tableau ci-dessus).

### Watcher : `SCANARR_WATCHER_ID is required`

Le binaire lit ses variables via `os.Getenv()` — il ne charge **pas** le fichier `watcher.env` automatiquement. Il faut exporter les variables avant de lancer le binaire :

```bash
# ❌ Ne fonctionne pas
./scanarr-watcher-linux-amd64

# ✅ Correct
export $(grep -v "^#" /etc/scanarr/watcher.env | xargs) && ./scanarr-watcher-linux-amd64
```

Vérifier que le fichier est lisible et contient bien `SCANARR_WATCHER_ID` :

```bash
# Vérifier les permissions (doit être -rw-r--r--)
ls -la /etc/scanarr/watcher.env

# Si "Permission denied", corriger avec :
sudo chmod 644 /etc/scanarr/watcher.env

# Vérifier le contenu
grep SCANARR_WATCHER_ID /etc/scanarr/watcher.env
```

### Tester l'API manuellement

```bash
# Depuis le NAS ou votre réseau local
curl -s http://192.168.1.91:8585/api/health
# Attendu : {"status":"ok"} ou similaire
```

### Recréer la base de données (reset complet)

```bash
ssh admin@192.168.1.91
docker exec scanarr-db psql -U scanarr -c "DROP DATABASE scanarr; CREATE DATABASE scanarr;"
# Puis redémarrer scanarr-api pour rejouer les migrations
docker restart scanarr-api
```
