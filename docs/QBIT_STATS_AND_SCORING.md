# Scanarr — Stats qBittorrent, Score de Suppression & Suggestions

> **Prérequis** : [DATABASE.md](DATABASE.md), [EXTERNAL_SERVICES.md](EXTERNAL_SERVICES.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V2.0

---

## 1. Vue d'ensemble

### 1.1 Objectif

Permettre une prise de décision éclairée pour la suppression en intégrant les données de seeding qBittorrent, un score de suppression configurable avec presets, et une page de suggestions dédiée.

### 1.2 Modules

| Module | Description |
|--------|-------------|
| **Sync qBittorrent** | Cron périodique + refresh manuel. Pull les stats torrents et les mappe aux fichiers en BDD via matching par suffixe. |
| **Rapport de sync** | Affiche les torrents matchés, non matchés et ambigus avec résolution manuelle. |
| **Stats qBit dans l'UI** | Colonnes ratio / seed time dans la liste films + détail par fichier. |
| **Presets de score** | Algorithme configurable avec presets (agressif/modéré/conservateur) + custom. Live preview. |
| **Suggestions de suppression** | Page dédiée avec classement par score, sélection par lot, objectif d'espace. |
| **Règles tracker** | Garde-fou global : seed time / ratio minimum par tracker. |
| **Protection de films** | Flag "protégé" pour exclure des films des suggestions. |

---

## 2. Base de données — Tables

Les tables `torrent_stats`, `torrent_stats_history`, `deletion_presets`, `tracker_rules` et les modifications de `media_files`/`movies` sont documentées dans [DATABASE.md](DATABASE.md).

**Changements V2.0 notables** :
- `torrent_stats.media_file_id` peut être NULL (torrent non matché)
- Ajout de `torrent_stats.match_status` : `'matched'`, `'unmatched'`, `'ambiguous'`, `'pending'`
- Ajout de `torrent_stats.match_reason` : explication pour les torrents non matchés/ambigus
- Suppression de `media_files.partial_hash` (remplacé par l'inode)

---

## 3. Sync qBittorrent

### 3.1 Fonctionnement

**Cron périodique** : `SyncQBittorrentCommand` exécuté toutes les 30 minutes (configurable dans settings : `qbittorrent_sync_interval_minutes`).

**Refresh manuel** : `POST /api/v1/qbittorrent/sync` (ROLE_ADMIN).

**Pré-requis** : les volumes doivent avoir été scannés au moins une fois (table `file_paths` peuplée) pour que le matching par suffixe fonctionne.

### 3.2 Algorithme de sync V2.0

```
1. GET /api/v2/auth/login → obtenir SID
2. GET /api/v2/torrents/info → liste complète des torrents
3. Pour chaque torrent (par batch de 10, avec émission de progression) :
   a. Extraire le domaine du tracker (URL → parse → domaine)
   b. Auto-détecter le tracker dans tracker_rules (créer si nouveau)
   c. Matching torrent → media_file :
      - Priorité 1 : match par hash via historique Radarr
        torrent.hash → GET /api/v3/history?eventType=grabbed
        → radarr movieId → tmdbId → movie Scanarr → media_files
      - Priorité 2 : match par suffixe progressif (voir PATH_MAPPING.md §4)
        torrent.content_path → extraction filename → filtre file_paths.filename
        → matching par suffixe progressif → media_file
      - Non matché → match_status = 'unmatched', match_reason = "no matching file_path"
      - Ambigu → match_status = 'ambiguous', match_reason = "2+ media_files for suffix ..."
   d. Créer/mettre à jour l'entrée torrent_stats
   e. Sauvegarder un snapshot dans torrent_stats_history (1 par jour max)
4. Émettre sync.progress entre chaque batch (SSE ou WebSocket front)
5. Marquer les torrent_stats non vus dans ce sync :
   - Si absent depuis 3 syncs consécutifs → status = 'removed'
6. Générer le rapport de sync (matchés, non matchés, ambigus)
7. flush()
```

### 3.3 Matching par hash Radarr (détail)

```php
// QBittorrentSyncService.php

// 1. Récupérer l'historique Radarr (grabbed events)
$hashToRadarrMovie = [];
foreach ($radarrHistory as $event) {
    $hash = strtolower($event['downloadId']);
    $hashToRadarrMovie[$hash] = $event['movieId'];
}

// 2. Pour chaque torrent qBit, chercher dans le map
$radarrMovieId = $hashToRadarrMovie[$torrent['hash']] ?? null;
if ($radarrMovieId !== null) {
    // Trouver le movie Scanarr via radarr_id → media_files
}
```

### 3.4 Matching par suffixe progressif (détail)

```php
// QBittorrentSyncService.php

private function matchBySuffix(string $contentPath): ?MatchResult
{
    // 1. Extraire le filename
    $filename = basename($contentPath);

    // 2. Chercher les candidats par filename exact (requête indexée)
    $candidates = $this->filePathRepository->findBy(['filename' => $filename]);

    if (count($candidates) === 0) {
        return new MatchResult(null, 'unmatched', "no file_path with filename '$filename'");
    }

    if (count($candidates) === 1) {
        return new MatchResult($candidates[0]->getMediaFile(), 'matched', null);
    }

    // 3. Matching par suffixe progressif (N > 1 candidats)
    $segments = explode('/', trim($contentPath, '/'));
    // Construire les suffixes du plus court au plus long (min 1 dir + filename)
    for ($i = count($segments) - 2; $i >= 0; $i--) {
        $suffix = implode('/', array_slice($segments, $i));
        $filtered = array_filter($candidates, fn($fp) => str_ends_with($fp->getRelativePath(), $suffix));

        if (count($filtered) === 0) continue;

        // Regrouper par media_file_id
        $mediaFileIds = array_unique(array_map(fn($fp) => $fp->getMediaFileId(), $filtered));

        if (count($mediaFileIds) === 1) {
            return new MatchResult(
                $filtered[0]->getMediaFile(),
                'matched',
                null
            );
        }
        // Plusieurs media_files → continuer avec suffixe plus long
    }

    // Tous les suffixes épuisés → ambiguïté
    return new MatchResult(null, 'ambiguous', "multiple media_files for '$filename'");
}
```

### 3.5 Rapport de sync

Chaque sync génère un rapport stocké en mémoire et accessible via l'API :

```json
{
  "sync_id": "uuid",
  "started_at": "2026-02-28T10:00:00Z",
  "completed_at": "2026-02-28T10:02:30Z",
  "total_torrents": 250,
  "matched": 230,
  "unmatched": 15,
  "ambiguous": 5,
  "new_trackers_detected": ["tracker-c.net"],
  "unmatched_torrents": [
    {
      "torrent_hash": "abc123",
      "torrent_name": "Film.2026.2160p.x265-GRP",
      "content_path": "/data/torrents/movies/Film.2026.2160p.x265-GRP.mkv",
      "reason": "no file_path with filename 'Film.2026.2160p.x265-GRP.mkv'"
    }
  ],
  "ambiguous_torrents": [
    {
      "torrent_hash": "def456",
      "torrent_name": "Inception.2010.2160p.x265-GRP",
      "content_path": "/data/torrents/movies/Inception.2010.2160p.x265-GRP.mkv",
      "candidates": [
        {"media_file_id": "uuid-1", "relative_path": "media/movies/Inception/Inception.2010.2160p.mkv"},
        {"media_file_id": "uuid-2", "relative_path": "links/movies/Inception.2010.2160p.mkv"}
      ]
    }
  ]
}
```

### 3.6 Progression du sync (batches de 10)

Le sync traite les torrents par batches de 10 et émet des événements de progression :

```json
{
  "type": "sync.progress",
  "data": {
    "sync_type": "qbittorrent",
    "processed": 80,
    "total": 250,
    "matched": 72,
    "unmatched": 6,
    "ambiguous": 2
  }
}
```

L'UI affiche une barre de progression persistante :

```
🔄 Sync qBittorrent   ████████░░  80/250
🔄 Sync Radarr        ██████████  200/200 ✅
```

### 3.7 Cache SID qBittorrent

```php
private ?string $cachedSid = null;
private ?DateTimeImmutable $sidExpiry = null;

private function getSid(): string
{
    if ($this->cachedSid && $this->sidExpiry > new DateTimeImmutable()) {
        return $this->cachedSid;
    }
    $this->cachedSid = $sid;
    $this->sidExpiry = new DateTimeImmutable('+30 minutes');
    return $sid;
}
```

### 3.8 Cron

```crontab
*/30 * * * * /usr/local/bin/php /app/bin/console scanarr:sync-qbittorrent >> /var/log/scanarr/qbit-sync.log 2>&1
```

---

## 4. Endpoints API

### 4.1 Sync qBittorrent

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `POST` | `/api/v1/qbittorrent/sync` | Admin | Déclencher un sync manuel |
| `GET` | `/api/v1/qbittorrent/sync/status` | Admin | Status du dernier sync (date, résultat, stats) |
| `GET` | `/api/v1/qbittorrent/sync/report` | Admin | Rapport détaillé du dernier sync (non matchés, ambigus) |
| `PUT` | `/api/v1/qbittorrent/resolve/{torrent_hash}` | Admin | Résoudre manuellement un torrent ambigu |

**`PUT /api/v1/qbittorrent/resolve/{torrent_hash}`** :

```json
// Request
{ "media_file_id": "uuid-chosen-file" }
// Response 200
{ "data": { "match_status": "matched", "media_file_id": "uuid-chosen-file" } }
```

### 4.2 Presets

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/deletion-presets` | User | Liste des presets |
| `POST` | `/api/v1/deletion-presets` | AdvancedUser | Créer un preset custom |
| `GET` | `/api/v1/deletion-presets/{id}` | User | Détail d'un preset |
| `PUT` | `/api/v1/deletion-presets/{id}` | AdvancedUser | Modifier un preset (pas les system) |
| `DELETE` | `/api/v1/deletion-presets/{id}` | AdvancedUser | Supprimer un preset custom |

### 4.3 Suggestions

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/suggestions` | AdvancedUser | Liste des films avec score calculé |
| `POST` | `/api/v1/suggestions/batch-delete` | AdvancedUser | Suppression immédiate par lot |
| `POST` | `/api/v1/suggestions/batch-schedule` | AdvancedUser | Ajouter à une planification par lot |

**`GET /api/v1/suggestions`** :

```
Query params:
  preset_id=uuid            (obligatoire)
  seeding_status=all        (all | orphans_only | seeding_only)
  min_score=0               (filtre score minimum)
  watcher_id=uuid           (optionnel, filtrer par watcher)
  sort=score_desc           (score_desc | ratio_asc | size_desc | seed_time_desc)
  page=1&per_page=50
```

```json
{
  "data": [
    {
      "movie": {
        "id": "uuid",
        "title": "Inception",
        "year": 2010,
        "poster_url": "...",
        "is_protected": false
      },
      "files": [
        {
          "media_file_id": "uuid",
          "file_paths": [
            {"volume_name": "Films HD", "relative_path": "Inception/Inception.2010.2160p.mkv"},
            {"volume_name": "Torrents HD", "relative_path": "Inception.2010.2160p.x265-GRP.mkv"}
          ],
          "file_size_bytes": 53687091200,
          "real_freed_bytes": 53687091200,
          "hardlink_count": 2,
          "known_paths_count": 2,
          "resolution": "2160p",
          "torrents": [
            {
              "torrent_hash": "abc123",
              "tracker_domain": "tracker-a.com",
              "ratio": 0.82,
              "seed_time_seconds": 3888000,
              "uploaded_bytes": 44023414784,
              "status": "seeding",
              "tracker_rule_satisfied": true
            }
          ],
          "cross_seed_count": 2,
          "seeding_status": "seeding",
          "is_protected": false
        }
      ],
      "score": 45,
      "score_breakdown": {
        "ratio": 30,
        "seed_time": 20,
        "file_size": 10,
        "orphan_qbit": 0,
        "cross_seed": -15,
        "total": 45
      },
      "total_size_bytes": 53687091200,
      "total_freed_bytes": 53687091200,
      "files_count": 1,
      "multi_file": false,
      "blocked_by_tracker_rules": false,
      "blocked_reason": null
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 234 },
    "summary": {
      "total_score_above_50": 45,
      "total_selectable_size": 2456789012345,
      "trackers_detected": ["tracker-a.com", "tracker-b.org"]
    }
  }
}
```

### 4.4 Tracker Rules

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `GET` | `/api/v1/tracker-rules` | AdvancedUser | Liste des règles tracker |
| `PUT` | `/api/v1/tracker-rules/{id}` | Admin | Modifier les règles d'un tracker |

### 4.5 Protection de films

| Méthode | Endpoint | Rôle min. | Description |
|---------|----------|-----------|-------------|
| `PUT` | `/api/v1/movies/{id}/protect` | AdvancedUser | Protéger/déprotéger un film |

### 4.6 Stats qBit dans les films existants

**`GET /api/v1/movies`** — Colonnes additionnelles :

```json
{
  "data": [
    {
      "id": "uuid",
      "title": "Inception",
      "year": 2010,
      "file_count": 1,
      "paths_count": 3,
      "total_size_bytes": 53687091200,
      "is_protected": false,
      "best_ratio": 2.1,
      "worst_ratio": 0.2,
      "total_seed_time_max_seconds": 12960000,
      "seeding_status": "seeding",
      "cross_seed_count": 2,
      "ratio_trend": "rising"
    }
  ]
}
```

---

## 5. Algorithme de score de suppression

### 5.1 Calcul

Le score est calculé **côté front** à partir des données brutes retournées par l'API. Cela permet le live preview lors de la configuration des presets.

```typescript
function calculateScore(file: FileWithTorrents, preset: DeletionPreset): number {
  let score = 0;
  const c = preset.criteria;

  // Ratio : plus le ratio est bas par rapport au seuil, plus le score monte
  if (c.ratio.enabled) {
    const bestRatio = Math.max(...file.torrents.map(t => t.ratio), 0);
    if (bestRatio < c.ratio.threshold) {
      const factor = 1 - (bestRatio / c.ratio.threshold);
      score += Math.round(c.ratio.weight * factor);
    }
  }

  // Seed time : plus le seed time dépasse le seuil, plus le score monte
  if (c.seed_time.enabled) {
    const maxSeedDays = Math.max(...file.torrents.map(t => t.seed_time_seconds / 86400), 0);
    if (maxSeedDays > c.seed_time.threshold_days) {
      const excess = (maxSeedDays - c.seed_time.threshold_days) / c.seed_time.threshold_days;
      score += Math.round(c.seed_time.weight * Math.min(excess, 1));
    }
  }

  // Taille fichier
  if (c.file_size.enabled) {
    const sizeGb = file.file_size_bytes / 1073741824;
    if (sizeGb > c.file_size.threshold_gb) {
      const excess = (sizeGb - c.file_size.threshold_gb) / c.file_size.threshold_gb;
      score += Math.round(c.file_size.weight * Math.min(excess, 1));
    }
  }

  // Orphelin qBit : pas de torrent = score fixe
  if (c.orphan_qbit.enabled && file.torrents.length === 0) {
    score += c.orphan_qbit.weight;
  }

  // Cross-seed : bonus négatif (protection)
  if (c.cross_seed.enabled && file.cross_seed_count > 1) {
    score += c.cross_seed.weight * (file.cross_seed_count - 1);
  }

  return Math.max(0, score);
}
```

### 5.2 Presets par défaut

**Conservateur** :
```json
{
  "name": "Conservateur",
  "criteria": {
    "ratio": { "enabled": true, "threshold": 0.5, "weight": 20, "operator": "below" },
    "seed_time": { "enabled": true, "threshold_days": 365, "weight": 15, "operator": "above" },
    "file_size": { "enabled": false, "threshold_gb": 50, "weight": 5, "operator": "above" },
    "orphan_qbit": { "enabled": true, "weight": 20 },
    "cross_seed": { "enabled": true, "weight": -20, "per_tracker": true }
  }
}
```

**Modéré** :
```json
{
  "name": "Modéré",
  "criteria": {
    "ratio": { "enabled": true, "threshold": 1.0, "weight": 30, "operator": "below" },
    "seed_time": { "enabled": true, "threshold_days": 180, "weight": 20, "operator": "above" },
    "file_size": { "enabled": true, "threshold_gb": 40, "weight": 10, "operator": "above" },
    "orphan_qbit": { "enabled": true, "weight": 25 },
    "cross_seed": { "enabled": true, "weight": -15, "per_tracker": true }
  }
}
```

**Agressif** :
```json
{
  "name": "Agressif",
  "criteria": {
    "ratio": { "enabled": true, "threshold": 2.0, "weight": 35, "operator": "below" },
    "seed_time": { "enabled": true, "threshold_days": 90, "weight": 25, "operator": "above" },
    "file_size": { "enabled": true, "threshold_gb": 20, "weight": 15, "operator": "above" },
    "orphan_qbit": { "enabled": true, "weight": 30 },
    "cross_seed": { "enabled": true, "weight": -10, "per_tracker": true }
  }
}
```

---

## 6. Règles tracker (garde-fou global)

### 6.1 Fonctionnement

Les règles tracker sont **indépendantes des presets**. Vérification : pour chaque torrent lié au fichier, toutes les règles du tracker doivent être satisfaites.

### 6.2 Impact sur l'UI

- **Page suggestions** : fichiers bloqués avec badge 🔒 et tooltip. Non sélectionnables.
- **Cross-seed** : bloqué si au moins un tracker n'est pas satisfait.

### 6.3 Auto-détection des trackers

Lors du sync qBit, extraction du domaine et création auto de la règle (min = 0).

---

## 7. Front-end

### 7.1 Nouvelles routes

| Route | Vue | Description |
|-------|-----|-------------|
| `/suggestions` | `SuggestionsView.vue` | Page suggestions de suppression |
| `/settings/presets` | `PresetsSettingsView.vue` | Gestion des presets avec live preview |
| `/settings/trackers` | `TrackerRulesSettingsView.vue` | Règles par tracker |

### 7.2 Page Suggestions (`SuggestionsView.vue`)

```
┌───────────────────────────────────────────────────────────────────────┐
│  Suggestions de suppression                     [🔄 Sync qBit]       │
│───────────────────────────────────────────────────────────────────────│
│                                                                       │
│  Preset: [Modéré ▼]    Filtre: [Tous ▼]    Watcher: [Tous ▼]        │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ 🎯 Objectif : [____500____] GB    Sélectionné : 0 / 500 GB    │  │
│  │ ████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ 0%             │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ☐ │ Score │ Film              │ Fichiers │ Ratio │ Seed     │ Taille│
│  ──┼───────┼───────────────────┼──────────┼───────┼──────────┼───────│
│  ☐ │ 🔴 85 │ Inception (2010)  │ 3 📂     │  0.18 │ 14 mois  │ 52 GB│
│  ☐ │ 🔴 75 │ Avatar (2009)     │ 1        │  0.31 │ 11 mois  │ 68 GB│
│  ☐ │ 🟡 45 │ Dune (2021)       │ 2 📂     │  0.92 │ 8 mois   │ 35 GB│
│  🔒│ 🟡 40 │ Blade Runner      │ 1        │  0.20 │ 12h      │ 40 GB│
│  ──│───────│  └ ⚠️ tracker-a.com : seed time min 48h non atteint    │
│  ☐ │ 🟢 10 │ Oppenheimer       │ 1        │  2.40 │ 3 mois   │ 41 GB│
│  🛡│ 🟢  5 │ Interstellar      │ 1        │  3.10 │ 2 mois   │ 28 GB│
│  ──│───────│  └ 🛡 Film protégé                                      │
│                                                                       │
│  [Supprimer immédiatement (2)]  [Ajouter à planification (2)]        │
└───────────────────────────────────────────────────────────────────────┘
```

- **Badge 📂** : film avec N chemins connus (ex: "3 📂" = 3 file_paths)
- **Badge 🔒** : bloqué par règle tracker
- **Badge 🛡** : film protégé
- **Couleurs score** : 🔴 >= 60, 🟡 30-59, 🟢 < 30

### 7.3 Rapport de sync qBit

Nouvelle section dans Settings > qBittorrent ou accessible via toast après sync :

```
┌───────────────────────────────────────────────────────────────────────┐
│  Rapport sync qBittorrent — 28/02/2026 10:02                        │
│───────────────────────────────────────────────────────────────────────│
│  ✅ 230 matchés  │  ⚠️ 15 non matchés  │  🔶 5 ambigus              │
│                                                                       │
│  ─── Torrents ambigus (résolution manuelle) ──────────────────────── │
│                                                                       │
│  🔶 Inception.2010.2160p.x265-GROUP.mkv                              │
│     Suffixe: "/movies/Inception.2010.2160p.x265-GROUP.mkv"           │
│     Candidats:                                                        │
│     ○ /media/movies/Inception/Inception.2010.2160p.mkv (inode 12345) │
│     ○ /links/movies/Inception.2010.2160p.mkv (inode 67890)           │
│     [Résoudre ▾]                                                      │
│                                                                       │
│  ─── Torrents non matchés ────────────────────────────────────────── │
│                                                                       │
│  ⚠️ Film.2026.2160p.x265-NEW.mkv                                     │
│     Raison: aucun fichier trouvé avec ce nom                          │
│     → Probablement pas encore scanné par le watcher                   │
└───────────────────────────────────────────────────────────────────────┘
```

### 7.4 Config Preset avec Live Preview (`PresetsSettingsView.vue`)

(Inchangé par rapport à V1.5)

### 7.5 Colonnes enrichies dans la liste films (`MoviesListView.vue`)

| Colonne | Description |
|---------|-------------|
| Ratio | Meilleur ratio parmi tous les torrents du film. Couleur : 🔴 < 0.5, 🟡 0.5-1.0, 🟢 > 1.0 |
| Seed time | Seed time le plus long, format humain (Xj / Xm / Xa) |
| Seeding | Badge : 🟢 En seed, 🔴 Orphelin, 🟡 Mixte |
| Chemins | Badge "3 📂" si multi-paths (remplace le badge fichiers) |
| 🛡 | Icône si film protégé |

### 7.6 Types TypeScript

```typescript
export interface TorrentStat {
  id: string;
  torrent_hash: string;
  torrent_name: string;
  tracker_domain: string;
  ratio: number;
  seed_time_seconds: number;
  uploaded_bytes: number;
  status: 'seeding' | 'paused' | 'stalled' | 'error' | 'completed' | 'removed';
  match_status: 'matched' | 'unmatched' | 'ambiguous' | 'pending';
  match_reason?: string;
  added_at: string;
  tracker_rule_satisfied: boolean;
}

export interface DeletionPreset {
  id: string;
  name: string;
  is_system: boolean;
  is_default: boolean;
  criteria: PresetCriteria;
  filters: PresetFilters;
}

export interface PresetCriteria {
  ratio: { enabled: boolean; threshold: number; weight: number; operator: string };
  seed_time: { enabled: boolean; threshold_days: number; weight: number; operator: string };
  file_size: { enabled: boolean; threshold_gb: number; weight: number; operator: string };
  orphan_qbit: { enabled: boolean; weight: number };
  cross_seed: { enabled: boolean; weight: number; per_tracker: boolean };
}

export interface PresetFilters {
  seeding_status: 'all' | 'orphans_only' | 'seeding_only';
  exclude_protected: boolean;
  min_score: number;
  max_results: number | null;
}

export interface TrackerRule {
  id: string;
  tracker_domain: string;
  min_seed_time_hours: number;
  min_ratio: number;
  is_auto_detected: boolean;
}

export interface SuggestionItem {
  movie: MovieSummary;
  files: SuggestionFile[];
  score: number;
  score_breakdown: Record<string, number>;
  total_size_bytes: number;
  total_freed_bytes: number;
  files_count: number;
  multi_file: boolean;
  blocked_by_tracker_rules: boolean;
  blocked_reason: string | null;
}

export interface SyncReport {
  sync_id: string;
  total_torrents: number;
  matched: number;
  unmatched: number;
  ambiguous: number;
  unmatched_torrents: UnmatchedTorrent[];
  ambiguous_torrents: AmbiguousTorrent[];
}

export interface AmbiguousTorrent {
  torrent_hash: string;
  torrent_name: string;
  content_path: string;
  candidates: { media_file_id: string; relative_path: string }[];
}
```

---

## 8. Tendance du ratio

### 8.1 Calcul

Basé sur `torrent_stats_history` — comparer le ratio d'il y a 7 jours vs maintenant :

```typescript
type RatioTrend = 'rising' | 'stable' | 'falling';

function calculateTrend(currentRatio: number, ratioWeekAgo: number): RatioTrend {
  const delta = currentRatio - ratioWeekAgo;
  if (delta > 0.05) return 'rising';
  if (delta < -0.02) return 'falling';
  return 'stable';
}
```

---

## 9. Dashboard — Historique espace libéré

Données agrégées depuis `activity_logs` (action = `scheduled_deletion.executed`) + `execution_report.results`.

---

## 10. Ordre d'implémentation V2.0

Voir [IMPLEMENTATION_ORDER.md](IMPLEMENTATION_ORDER.md) pour le planning global.
