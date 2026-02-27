# Scanarr — Ordre d'Implémentation

> **Prérequis** : Tous les fichiers de spec
> **Version** : V1.2.1 (phases V1.5 dans QBIT_STATS_AND_SCORING.md)

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
