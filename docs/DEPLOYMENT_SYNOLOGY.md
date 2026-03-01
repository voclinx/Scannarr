# Déploiement Scanarr — Synology DS923+ avec Portainer

## Prérequis

- Synology DS923+ sous DSM 7.x
- **Container Manager** (Docker) installé via le Centre de Paquets DSM
- **Portainer** déployé dans Container Manager
- Accès SSH activé sur le NAS

---

## 1. Variables d'environnement à configurer dans Portainer

Lors de l'ajout de la stack dans Portainer, renseigner ces variables :

| Variable | Description | Exemple |
|----------|-------------|---------|
| `DB_PASSWORD` | Mot de passe PostgreSQL | `MonMotDePasseDB!` |
| `APP_SECRET` | Clé secrète Symfony (32+ chars aléatoires) | `openssl rand -hex 32` |
| `JWT_PASSPHRASE` | Passphrase pour les clés JWT | `MonPassphraseJWT!` |
| `WATCHER_AUTH_TOKEN` | Token d'auth du watcher (même valeur que dans watcher.env) | `openssl rand -hex 32` |

> Générer des valeurs sécurisées via SSH :
> ```bash
> openssl rand -hex 32
> ```

---

## 2. Déployer la stack dans Portainer

1. Ouvrir Portainer → **Stacks** → **Add stack**
2. Nommer la stack : `scanarr`
3. Onglet **Upload** → choisir `docker-compose.portainer.yml`
4. Section **Environment variables** → ajouter les 4 variables ci-dessus
5. Cliquer **Deploy the stack**

L'interface sera disponible sur : **http://192.168.1.91:8585**

---

## 3. Initialiser la base de données (premier démarrage)

Après le premier démarrage des containers, exécuter les migrations Doctrine :

```bash
# Via SSH sur le NAS ou via Portainer (Console du container scanarr-api)
docker exec scanarr-api php bin/console doctrine:migrations:migrate --no-interaction

# Créer le compte admin initial
docker exec scanarr-api php bin/console app:create-user admin admin@scanarr.local VotreMotDePasse ROLE_ADMIN
```

---

## 4. Installer le Watcher sur le NAS

Le watcher est un binaire Go natif qui surveille le filesystem et communique avec l'API via WebSocket. Il ne tourne **pas** dans Docker.

### 4a. Copier le binaire

Via SSH :
```bash
# Copier le binaire sur le NAS (depuis votre machine)
scp watcher/bin/scanarr-watcher-linux-amd64 admin@192.168.1.91:/usr/local/bin/scanarr-watcher
ssh admin@192.168.1.91 "chmod +x /usr/local/bin/scanarr-watcher"
```

Ou via File Station DSM : déposer le fichier `scanarr-watcher-linux-amd64` dans `/usr/local/bin/` et le renommer en `scanarr-watcher`.

### 4b. Créer le fichier de configuration

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
```

### 4c. Démarrage automatique via DSM Task Scheduler

Dans **Panneau de configuration** → **Planificateur de tâches** :

1. **Créer** → **Tâche déclenchée** → **Au démarrage**
2. Nom : `Scanarr Watcher`
3. Utilisateur : `root`
4. Commande :
   ```bash
   /bin/sh -c 'export $(cat /etc/scanarr/watcher.env | grep -v "^#" | xargs); /usr/local/bin/scanarr-watcher >> /var/log/scanarr-watcher.log 2>&1 &'
   ```
5. Cocher **Activer**
6. Sauvegarder

Pour arrêter le watcher manuellement :
```bash
pkill scanarr-watcher
```

Pour voir les logs :
```bash
tail -f /var/log/scanarr-watcher.log
```

---

## 5. Configurer les volumes dans l'UI Scanarr

Une fois connecté à http://192.168.1.91:8585 :

1. **Paramètres** → **Watchers** → le watcher `synology-nas` doit apparaître comme connecté
2. Configurer le volume dans les paramètres :
   - Chemin Docker (API) : `/mnt/filmarr`
   - Chemin hôte réel : `/volume1/filmarr`

---

## 6. Mise à jour

```bash
# Portainer : Stacks > scanarr > Pull and redeploy
# OU via SSH :
docker pull ghcr.io/voclinx/scannarr-api:latest
docker pull ghcr.io/voclinx/scannarr-front:latest
# Puis redémarrer la stack dans Portainer

# Mettre à jour le watcher :
scp watcher/bin/scanarr-watcher-linux-amd64 admin@192.168.1.91:/usr/local/bin/scanarr-watcher
ssh admin@192.168.1.91 "chmod +x /usr/local/bin/scanarr-watcher && pkill scanarr-watcher"
# Le Task Scheduler le relancera au prochain redémarrage
# Pour le relancer immédiatement :
ssh admin@192.168.1.91 "/bin/sh -c 'export \$(cat /etc/scanarr/watcher.env | grep -v \"^#\" | xargs); /usr/local/bin/scanarr-watcher >> /var/log/scanarr-watcher.log 2>&1 &'"
```

---

## 7. Architecture réseau

```
Synology DS923+ (192.168.1.91)
│
├── :8585  → Container scanarr-front (Nginx)
│    ├── /api/   → proxifié vers scanarr-api:8080 (interne)
│    ├── /ws/    → proxifié vers scanarr-api:8081 (interne, browser WS)
│    └── /       → SPA Vue.js (interface web)
│
├── :8081  → Container scanarr-api (WebSocket direct pour le watcher)
│
└── scanarr-watcher (binaire natif DSM)
     └── → ws://192.168.1.91:8081/ws/watcher
```

> **PostgreSQL** (port 5432) n'est **pas** exposé à l'extérieur — accès interne uniquement.
