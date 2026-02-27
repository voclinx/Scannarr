# Scanarr — Changelog

---

## V1.5 — Stats qBittorrent, Score de Suppression & Cross-Seed

### Nouvelles fonctionnalités

**Sync qBittorrent**
- Cron périodique (configurable, défaut 30min) + refresh manuel
- Matching torrent → film via hash historique Radarr + fallback content_path
- Stockage ratio, seed time, upload par torrent en BDD
- Auto-détection des trackers depuis qBit
- Historisation snapshots pour tendance du ratio

**Score de suppression avec presets**
- 3 presets système : Conservateur, Modéré, Agressif
- Presets custom avec critères configurables (ratio, seed time, taille, orphelin qBit, cross-seed)
- Chaque preset stocke toute la logique de décision (rejouable programmatiquement)
- Live preview : recalcul temps réel côté front lors de la config
- Filtre seeding : tous / orphelins uniquement / en seed uniquement

**Page Suggestions de suppression**
- Page dédiée avec classement par score
- Barre d'objectif d'espace : compteur qui se remplit en cochant des films
- Sélection par lot : suppression immédiate ou ajout à planification
- Calcul espace réellement libéré (hardlink-aware)
- Badge multi-fichiers avec filtre

**Cross-seed**
- Détection des torrents cross-seed (N torrents → 1 fichier physique)
- Partial hash (SHA-256 premiers 1MB + derniers 1MB) calculé par le watcher
- Groupement par media_file_id + partial_hash
- Critère cross-seed dans les presets (bonus négatif = protection)
- Affichage détail par tracker + agrégat upload cumulé

**Règles tracker (garde-fou global)**
- Seed time minimum et ratio minimum par tracker
- Auto-détection des trackers depuis qBit + config manuelle des règles
- Bloque la suppression si au moins un tracker non satisfait
- Indépendant des presets, vérification sur tous les trackers d'un fichier cross-seedé

**Protection de films**
- Flag `is_protected` sur movies et media_files
- Films protégés exclus des suggestions
- Action protéger/déprotéger depuis la liste films et le détail

**Gestion des hardlinks et remplacement fichier lecteur**
- Détection des films multi-fichiers (plusieurs qualités)
- Remplacement automatique : suggestion meilleure qualité restante + choix manuel
- Nouvelle commande WebSocket `command.files.hardlink` pour création de hardlinks
- Mise à jour Radarr (RescanMovie) après remplacement
- Option désactiver auto-search Radarr
- Refresh Plex/Jellyfin après remplacement

**Tendance du ratio**
- Historique snapshots (90 jours)
- Calcul tendance : rising / stable / falling (comparaison J-7)
- Affichage icône dans la liste films

**Dashboard**
- Graphique historique espace libéré par mois

**Stats qBit dans l'UI existante**
- Colonnes ratio + seed time dans la liste films (triables/filtrables)
- Colonne seeding status (🟢 En seed, 🔴 Orphelin, 🟡 Mixte)
- Badge multi-fichiers avec nombre et taille totale
- Détail par fichier avec tous les torrents dans la page film

### Nouvelles tables BDD
- `torrent_stats` — Stats par torrent (ratio, seed time, tracker, etc.)
- `torrent_stats_history` — Snapshots pour tendance
- `deletion_presets` — Presets de score
- `tracker_rules` — Règles par tracker

### Modifications BDD
- `media_files` : ajout `partial_hash`, `is_protected`
- `movies` : ajout `is_protected`

### Nouveaux endpoints
- `POST /api/v1/qbittorrent/sync` — Sync manuel
- `GET /api/v1/qbittorrent/sync/status` — Status dernier sync
- CRUD `/api/v1/deletion-presets`
- `GET /api/v1/suggestions` — Liste suggestions avec score
- `POST /api/v1/suggestions/batch-delete` — Suppression par lot
- `POST /api/v1/suggestions/batch-schedule` — Planification par lot
- `GET /api/v1/tracker-rules` — Liste règles tracker
- `PUT /api/v1/tracker-rules/{id}` — Modifier règle tracker
- `PUT /api/v1/movies/{id}/protect` — Protéger/déprotéger un film

### Nouvelles vues front
- `SuggestionsView.vue` — Page suggestions
- `PresetsSettingsView.vue` — Config presets avec live preview
- `TrackerRulesSettingsView.vue` — Règles tracker

### Watcher
- Calcul `partial_hash` au scan
- Nouvelle commande `command.files.hardlink`
- Nouveau message `files.hardlink.completed`

---

## V1.2.1 — Chaîne de suppression via Watcher

### Changements
- Suppression de la table `deletion_requests` au profit de `ScheduledDeletion` éphémère
- Les suppressions immédiates créent une ScheduledDeletion avec `scheduled_date = today`
- Pipeline unifié : toute suppression passe par `ScheduledDeletion → DeletionService → Watcher`
- Format réponse Movie DELETE : `deletion_id` au lieu de `request_id`
- Message WebSocket `command.files.delete` : utilise `volume_path` + `file_path`
- Systemd : `ReadOnlyPaths` → `ReadWritePaths` pour le watcher
- Ajout `disable_radarr_auto_search` dans le TypeScript interface

---

## V1.2 — Suppression déléguée au Watcher

### Changements
- Toute opération filesystem (unlink, rmdir) déléguée au watcher via WebSocket
- L'API ne fait jamais de suppression physique directement
- Gestion offline watcher avec status `waiting_watcher` et renvoi automatique à la reconnexion
- Nettoyage qBittorrent best-effort (non bloquant)
- Refresh Plex/Jellyfin après suppression
- Notifications Discord (rappel + confirmation + erreurs)

---

## V1.1 — Intégrations et améliorations

### Changements
- Intégration Radarr multi-instances
- Intégration TMDB (enrichissement métadonnées)
- Intégration Plex et Jellyfin
- Intégration qBittorrent (nettoyage torrent à la suppression)

---

## V1.0 — MVP

### Fonctionnalités initiales
- Authentification JWT avec 4 rôles (Admin, AdvancedUser, User, Guest)
- Gestion des volumes (local + réseau)
- Watcher Go avec fsnotify + scanner récursif
- Communication WebSocket watcher ↔ API
- Explorateur de fichiers avec tri/filtre
- Liste et détail des films
- Suppression unitaire, globale et planifiée
- Dashboard avec statistiques
- Setup wizard au premier lancement
