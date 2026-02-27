# Scanarr — Intégrations Externes

> **Prérequis** : [DATABASE.md](DATABASE.md)
> **Version** : V1.2.1 (enrichissements V1.5 dans QBIT_STATS_AND_SCORING.md)

Couvre : Radarr, TMDB, Plex, Jellyfin, qBittorrent, Discord.

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
| `GET /library/sections/{id}/refresh` | Forcer un scan de la bibliothèque (après suppression de fichiers) |

**Headers** : `X-Plex-Token: {token}`, `Accept: application/json`

**Refresh bibliothèque** : Après une suppression physique de fichier(s), Scanarr doit appeler `refreshLibrary()` sur chaque instance Plex active pour que les films disparus soient retirés de l'affichage. Le refresh est asynchrone côté Plex (retour immédiat HTTP 200).

### 9.4 Jellyfin (JellyfinService.php)

**API utilisée** : Jellyfin API

| Endpoint Jellyfin | Usage Scanarr |
|-------------------|---------------|
| `GET /System/Info` | Test de connexion |
| `GET /Items?IncludeItemTypes=Movie` | Lister les films |
| `POST /Library/Refresh` | Forcer un scan de toutes les bibliothèques (après suppression de fichiers) |

**Headers** : `X-Emby-Token: {token}`

**Refresh bibliothèque** : Même principe que Plex — après suppression physique, appeler `refreshLibrary()` sur chaque instance Jellyfin active. Le refresh est asynchrone côté Jellyfin (retour immédiat HTTP 204).

### 9.5 qBittorrent (QBittorrentService.php)

**API utilisée** : qBittorrent Web API v2

| Endpoint qBittorrent | Usage Scanarr |
|----------------------|---------------|
| `POST /api/v2/auth/login` | Authentification (retourne cookie `SID`) |
| `GET /api/v2/torrents/info` | Liste de tous les torrents avec `content_path` |
| `POST /api/v2/torrents/delete` | Supprimer un ou plusieurs torrents |

**Authentification** : qBittorrent utilise une auth par cookie. Appeler `POST /api/v2/auth/login` avec `username` et `password` en `application/x-www-form-urlencoded`. La réponse contient un header `Set-Cookie: SID=xxx`. Ce cookie doit être renvoyé dans tous les appels suivants.

**Recherche de torrent par fichier** : Pour un fichier physique donné (chemin absolu sur le host), chercher dans la liste des torrents celui dont `content_path` correspond. `content_path` dans qBittorrent est le chemin absolu du fichier ou du dossier du torrent. Comparer en normalisant les chemins :

```php
// Stratégie de matching :
// 1. Pour chaque torrent, vérifier si le content_path du torrent est un préfixe du chemin du fichier
// 2. OU si le content_path correspond exactement au chemin du fichier (torrent single-file)
// Normaliser avec rtrim('/')
public function findTorrentByFilePath(string $absoluteFilePath): ?array
```

**Suppression de torrent** : `POST /api/v2/torrents/delete` avec `hashes={hash}&deleteFiles=false`. Important : `deleteFiles=false` car Scanarr gère la suppression physique lui-même. On veut uniquement retirer le torrent de la liste qBittorrent (arrêter le seed).

**Gestion des erreurs** : Si qBittorrent n'est pas configuré (settings vides) ou injoignable, la chaîne de suppression continue — le nettoyage qBittorrent est best-effort, non bloquant. Logger un warning mais ne pas échouer la suppression.

**Méthodes requises** :

```php
class QBittorrentService
{
    public function isConfigured(): bool;              // vérifie si URL + credentials sont renseignés
    public function testConnection(): array;           // {success: bool, version?: string, error?: string}
    public function findTorrentByFilePath(string $absoluteFilePath): ?array;  // retourne le torrent ou null
    public function deleteTorrent(string $hash, bool $deleteFiles = false): bool;  // supprime de qBit
    public function findAndDeleteTorrent(string $absoluteFilePath): bool;  // helper : find + delete
}
```

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

