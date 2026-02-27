# Scanarr — Stats qBittorrent, Score de Suppression & Suggestions

> **Prérequis** : [DATABASE.md](DATABASE.md), [EXTERNAL_SERVICES.md](EXTERNAL_SERVICES.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V1.5

---

## 1. Vue d'ensemble

### 1.1 Objectif

Permettre une prise de décision éclairée pour la suppression en intégrant les données de seeding qBittorrent, un score de suppression configurable avec presets, et une page de suggestions dédiée.

### 1.2 Modules

| Module | Description |
|--------|-------------|
| **Sync qBittorrent** | Cron périodique + refresh manuel. Pull les stats torrents et les mappe aux fichiers en BDD. |
| **Stats qBit dans l'UI** | Colonnes ratio / seed time dans la liste films + détail par fichier. |
| **Presets de score** | Algorithme configurable avec presets (agressif/modéré/conservateur) + custom. Live preview. |
| **Suggestions de suppression** | Page dédiée avec classement par score, sélection par lot, objectif d'espace. |
| **Règles tracker** | Garde-fou global : seed time / ratio minimum par tracker. |
| **Protection de films** | Flag "protégé" pour exclure des films des suggestions. |

---

## 2. Base de données — Nouvelles tables

### 2.1 `torrent_stats`

Stocke les données de chaque torrent lié à un fichier Scanarr. Un `media_file` peut avoir N `torrent_stats` (cross-seed).

```sql
CREATE TABLE torrent_stats (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    media_file_id UUID NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
    torrent_hash VARCHAR(100) NOT NULL,         -- info_hash du torrent dans qBit
    torrent_name VARCHAR(500),                  -- nom affiché dans qBit
    tracker_domain VARCHAR(255),                -- domaine du tracker (ex: tracker.exemple.com)
    ratio DECIMAL(10,4) DEFAULT 0,
    seed_time_seconds BIGINT DEFAULT 0,         -- temps de seed en secondes
    uploaded_bytes BIGINT DEFAULT 0,            -- total upload
    downloaded_bytes BIGINT DEFAULT 0,          -- total download
    size_bytes BIGINT DEFAULT 0,                -- taille du torrent
    status VARCHAR(30) DEFAULT 'seeding',       -- 'seeding', 'paused', 'stalled', 'error', 'completed'
    added_at TIMESTAMP,                         -- date d'ajout dans qBit
    last_activity_at TIMESTAMP,                 -- dernière activité upload/download
    qbit_content_path VARCHAR(1000),            -- content_path brut de qBit (avant mapping)
    first_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),  -- première fois vu dans un sync
    last_synced_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(torrent_hash)
);

CREATE INDEX idx_torrent_stats_media_file ON torrent_stats(media_file_id);
CREATE INDEX idx_torrent_stats_tracker ON torrent_stats(tracker_domain);
CREATE INDEX idx_torrent_stats_hash ON torrent_stats(torrent_hash);
```

### 2.2 `torrent_stats_history`

Snapshots périodiques pour calculer la tendance du ratio.

```sql
CREATE TABLE torrent_stats_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    torrent_stats_id UUID NOT NULL REFERENCES torrent_stats(id) ON DELETE CASCADE,
    ratio DECIMAL(10,4),
    uploaded_bytes BIGINT,
    seed_time_seconds BIGINT,
    recorded_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_torrent_history_stats ON torrent_stats_history(torrent_stats_id);
CREATE INDEX idx_torrent_history_date ON torrent_stats_history(recorded_at DESC);
```

**Rétention** : garder 1 snapshot par jour pendant 90 jours, puis supprimer (cron de nettoyage).

### 2.3 `deletion_presets`

Stocke les presets de score de suppression. Chaque preset contient tous les critères et poids nécessaires pour être exécuté de manière programmatique (en prévision d'un nettoyage automatique futur).

```sql
CREATE TABLE deletion_presets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    is_system BOOLEAN NOT NULL DEFAULT false,   -- true pour les 3 presets par défaut
    is_default BOOLEAN NOT NULL DEFAULT false,  -- preset actif par défaut
    criteria JSONB NOT NULL,                    -- voir structure ci-dessous
    filters JSONB NOT NULL DEFAULT '{}',        -- filtres additionnels
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

**Structure `criteria`** :

```json
{
  "ratio": {
    "enabled": true,
    "threshold": 1.0,
    "weight": 30,
    "operator": "below"
  },
  "seed_time": {
    "enabled": true,
    "threshold_days": 180,
    "weight": 20,
    "operator": "above"
  },
  "file_size": {
    "enabled": true,
    "threshold_gb": 40,
    "weight": 10,
    "operator": "above"
  },
  "orphan_qbit": {
    "enabled": true,
    "weight": 25
  },
  "cross_seed": {
    "enabled": true,
    "weight": -15,
    "per_tracker": true
  }
}
```

**Structure `filters`** (pour future automation) :

```json
{
  "seeding_status": "all",
  "exclude_protected": true,
  "min_score": 0,
  "max_results": null
}
```

- `seeding_status` : `"all"` | `"orphans_only"` | `"seeding_only"`
- `exclude_protected` : toujours exclure les films protégés
- `min_score` : score minimum pour apparaître (0 = tout montrer)

### 2.4 `tracker_rules`

Garde-fou global par tracker. Indépendant des presets.

```sql
CREATE TABLE tracker_rules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tracker_domain VARCHAR(255) NOT NULL UNIQUE, -- ex: tracker.exemple.com
    min_seed_time_hours INTEGER DEFAULT 0,       -- seed time minimum en heures
    min_ratio DECIMAL(10,4) DEFAULT 0,           -- ratio minimum
    is_auto_detected BOOLEAN DEFAULT true,       -- détecté automatiquement depuis qBit
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

Les trackers sont **auto-détectés** lors du sync qBit (extraction du domaine depuis l'URL tracker). L'utilisateur configure ensuite les règles manuellement.

### 2.5 Modification de `media_files`

Ajouter :

```sql
ALTER TABLE media_files ADD COLUMN partial_hash VARCHAR(128);
ALTER TABLE media_files ADD COLUMN is_protected BOOLEAN NOT NULL DEFAULT false;

CREATE INDEX idx_media_files_partial_hash ON media_files(partial_hash);
```

- `partial_hash` : hash des premiers 1MB + derniers 1MB du fichier. Calculé par le watcher au scan. Utilisé pour le groupement cross-seed (voir [CROSS_SEED.md](CROSS_SEED.md)).
- `is_protected` : flag de protection contre la suppression.

### 2.6 Modification de `movies`

Ajouter :

```sql
ALTER TABLE movies ADD COLUMN is_protected BOOLEAN NOT NULL DEFAULT false;
```

Un film protégé n'apparaît jamais dans les suggestions de suppression.

---

## 3. Sync qBittorrent

### 3.1 Fonctionnement

**Cron périodique** : `SyncQBittorrentCommand` exécuté toutes les 30 minutes (configurable dans settings : `qbittorrent_sync_interval_minutes`).

**Refresh manuel** : `POST /api/v1/qbittorrent/sync` (ROLE_ADMIN).

### 3.2 Algorithme de sync

```
1. GET /api/v2/auth/login → obtenir SID (cacher pour les appels suivants)
2. GET /api/v2/torrents/info → liste complète des torrents
3. Pour chaque torrent :
   a. Extraire le domaine du tracker (URL → parse → domaine)
   b. Auto-détecter le tracker dans tracker_rules (créer si nouveau)
   c. Matching torrent → media_file :
      - Priorité 1 : match par hash via historique Radarr (GET /api/v3/history?eventType=grabbed)
        torrent.hash → radarr history → movieId → tmdbId → movie Scanarr → media_files
      - Priorité 2 : match par content_path + qBit path mapping → chemin host → media_file
      - Priorité 3 : non matché → loguer en warning
   d. Créer/mettre à jour l'entrée torrent_stats
   e. Sauvegarder un snapshot dans torrent_stats_history (1 par jour max)
4. Marquer les torrent_stats non vus dans ce sync comme potentiellement supprimés :
   - Si absent depuis 3 syncs consécutifs → status = 'removed'
5. flush()
```

### 3.3 Matching par hash Radarr (détail)

```php
// QBittorrentSyncService.php

// 1. Récupérer l'historique Radarr (grabbed events)
// GET /api/v3/history?eventType=grabbed&pageSize=1000
// Chaque event contient : downloadId (= hash torrent en uppercase), movieId

// 2. Construire un map hash → movieId
$hashToRadarrMovie = [];
foreach ($radarrHistory as $event) {
    $hash = strtolower($event['downloadId']);
    $hashToRadarrMovie[$hash] = $event['movieId'];
}

// 3. Pour chaque torrent qBit, chercher dans le map
$radarrMovieId = $hashToRadarrMovie[$torrent['hash']] ?? null;
if ($radarrMovieId !== null) {
    // Trouver le movie Scanarr via radarr_id
    // Puis trouver les media_files liés via movie_files
}
```

### 3.4 Cache SID qBittorrent

Le SID qBittorrent doit être caché en mémoire (ou settings) avec un TTL de 30 minutes. Re-authentifier uniquement sur HTTP 403.

```php
// QBittorrentService.php
private ?string $cachedSid = null;
private ?DateTimeImmutable $sidExpiry = null;

private function getSid(): string
{
    if ($this->cachedSid && $this->sidExpiry > new DateTimeImmutable()) {
        return $this->cachedSid;
    }
    // POST /api/v2/auth/login → extract SID
    $this->cachedSid = $sid;
    $this->sidExpiry = new DateTimeImmutable('+30 minutes');
    return $sid;
}
```

### 3.5 Cron

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
| `POST` | `/api/v1/suggestions/batch-schedule` | AdvancedUser | Ajouter à une suppression planifiée par lot |

**`GET /api/v1/suggestions`** :

```
Query params:
  preset_id=uuid            (obligatoire)
  seeding_status=all        (all | orphans_only | seeding_only)
  min_score=0               (filtre score minimum)
  volume_id=uuid            (optionnel, filtrer par volume)
  sort=score_desc           (score_desc | ratio_asc | size_desc | seed_time_desc)
  page=1&per_page=50
```

```json
// Response
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
          "file_name": "Inception.2010.2160p.mkv",
          "file_size_bytes": 53687091200,
          "real_freed_bytes": 53687091200,
          "hardlink_count": 2,
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
      "score": 72,
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
    },
    "volume_space": {
      "volume_id": "uuid",
      "free_bytes": 214748364800,
      "total_bytes": 4000000000000
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

```json
// Request
{ "is_protected": true }
```

### 4.6 Stats qBit dans les films existants

Les endpoints existants sont enrichis :

**`GET /api/v1/movies`** — Colonnes additionnelles dans la réponse :

```json
{
  "data": [
    {
      "id": "uuid",
      "title": "Inception",
      "year": 2010,
      "file_count": 3,
      "total_size_bytes": 120000000000,
      "is_protected": false,
      "multi_file_badge": true,
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

- `best_ratio` / `worst_ratio` : parmi tous les torrents de tous les fichiers du film
- `seeding_status` : `"seeding"` (au moins 1 torrent actif), `"orphan"` (aucun torrent), `"mixed"`
- `ratio_trend` : `"rising"`, `"stable"`, `"falling"` (basé sur l'historique 7 derniers jours)
- `multi_file_badge` : `true` si le film a > 1 media_file

**`GET /api/v1/movies/{id}`** — Détail enrichi par fichier avec tous les torrents et leurs stats.

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
      const factor = 1 - (bestRatio / c.ratio.threshold); // 0 à 1
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

  // Taille fichier : plus c'est gros au-dessus du seuil, plus le score monte
  if (c.file_size.enabled) {
    const sizeGb = file.file_size_bytes / 1073741824;
    if (sizeGb > c.file_size.threshold_gb) {
      const excess = (sizeGb - c.file_size.threshold_gb) / c.file_size.threshold_gb;
      score += Math.round(c.file_size.weight * Math.min(excess, 1));
    }
  }

  // Orphelin qBit : pas de torrent associé = score fixe
  if (c.orphan_qbit.enabled && file.torrents.length === 0) {
    score += c.orphan_qbit.weight;
  }

  // Cross-seed : bonus négatif (protection) par tracker actif
  if (c.cross_seed.enabled && file.cross_seed_count > 1) {
    score += c.cross_seed.weight * (file.cross_seed_count - 1);
  }

  return Math.max(0, score);
}
```

### 5.2 Presets par défaut

**Conservateur** — Privilégie la rétention, ne suggère que les cas évidents :

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

**Modéré** — Équilibre entre rétention et nettoyage :

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

**Agressif** — Nettoyage maximal, ne garde que ce qui seed bien :

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

Les règles tracker sont **indépendantes des presets**. C'est un garde-fou qui empêche la suppression d'un fichier si les conditions minimales du tracker ne sont pas remplies.

Vérification : pour chaque torrent lié au fichier, vérifier que **toutes** les règles du tracker sont satisfaites :

```php
function isBlockedByTrackerRules(MediaFile $file): ?string
{
    foreach ($file->getTorrentStats() as $torrent) {
        $rule = $this->trackerRuleRepo->findByDomain($torrent->getTrackerDomain());
        if ($rule === null) continue; // pas de règle = pas de blocage

        if ($torrent->getSeedTimeSeconds() < $rule->getMinSeedTimeHours() * 3600) {
            return "Seed time minimum non atteint sur {$torrent->getTrackerDomain()} "
                 . "({$this->formatDuration($torrent->getSeedTimeSeconds())} / "
                 . "{$rule->getMinSeedTimeHours()}h requises)";
        }

        if ($torrent->getRatio() < $rule->getMinRatio()) {
            return "Ratio minimum non atteint sur {$torrent->getTrackerDomain()} "
                 . "({$torrent->getRatio()} / {$rule->getMinRatio()} requis)";
        }
    }
    return null; // pas bloqué
}
```

### 6.2 Impact sur l'UI

- **Page suggestions** : les fichiers bloqués sont affichés avec un badge 🔒 et un tooltip expliquant la raison. Ils ne sont **pas sélectionnables** pour suppression.
- **Suppression immédiate/planifiée** : si un fichier bloqué est inclus, une modale d'avertissement s'affiche. Pas de possibilité de forcer (le garde-fou est absolu).
- **Cross-seed** : un fichier est bloqué si **au moins un** de ses trackers n'est pas satisfait.

### 6.3 Auto-détection des trackers

Lors du sync qBit, pour chaque torrent :

```php
// Extraire le domaine du tracker
$trackerUrl = $torrent['tracker']; // ex: "https://tracker.exemple.com:443/announce"
$domain = parse_url($trackerUrl, PHP_URL_HOST); // "tracker.exemple.com"

// Créer le tracker_rule s'il n'existe pas (avec min_seed_time = 0, min_ratio = 0)
$rule = $this->trackerRuleRepo->findByDomain($domain);
if ($rule === null) {
    $rule = new TrackerRule();
    $rule->setTrackerDomain($domain);
    $rule->setIsAutoDetected(true);
    $this->em->persist($rule);
}
```

L'utilisateur voit ensuite tous les trackers détectés dans les settings et configure les règles manuellement.

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
│  Preset: [Modéré ▼]    Filtre: [Tous ▼]    Volume: [Tous ▼]         │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │ 📊 NAS Principal : 200 GB libre / 4 TB                        │  │
│  │ 🎯 Objectif : [____500____] GB    Sélectionné : 0 / 500 GB    │  │
│  │ ████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ 0%             │  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ☐ │ Score │ Film              │ Fichiers │ Ratio │ Seed     │ Taille│
│  ──┼───────┼───────────────────┼──────────┼───────┼──────────┼───────│
│  ☐ │ 🔴 85 │ Inception (2010)  │ 3 📁     │  0.18 │ 14 mois  │ 52 GB│
│  ☐ │ 🔴 75 │ Avatar (2009)     │ 1        │  0.31 │ 11 mois  │ 68 GB│
│  ☐ │ 🟡 45 │ Dune (2021)       │ 2 📁     │  0.92 │ 8 mois   │ 35 GB│
│  🔒│ 🟡 40 │ Blade Runner      │ 1        │  0.20 │ 12h      │ 40 GB│
│  ──│───────│  └ ⚠️ tracker-a.com : seed time min 48h non atteint    │
│  ☐ │ 🟢 10 │ Oppenheimer       │ 1        │  2.40 │ 3 mois   │ 41 GB│
│  🛡│ 🟢  5 │ Interstellar      │ 1        │  3.10 │ 2 mois   │ 28 GB│
│  ──│───────│  └ 🛡 Film protégé                                      │
│                                                                       │
│  [Supprimer immédiatement (2)]  [Ajouter à planification (2)]        │
└───────────────────────────────────────────────────────────────────────┘
```

- **Barre d'objectif** : se remplit au fur et à mesure que l'utilisateur coche des films. Affiche l'espace réellement libéré (hardlink-aware).
- **Badge 📁** : film multi-fichiers, avec le nombre de fichiers.
- **Badge 🔒** : bloqué par règle tracker, non sélectionnable.
- **Badge 🛡** : film protégé, non sélectionnable.
- **Couleurs score** : 🔴 >= 60, 🟡 30-59, 🟢 < 30.

### 7.3 Config Preset avec Live Preview (`PresetsSettingsView.vue`)

```
┌─────────────────────────────────────────────────────────────┐
│  Preset: [Modéré ▼] [+ Nouveau]  [Dupliquer]  [Sauvegarder]│
│─────────────────────────────────────────────────────────────│
│                                                             │
│  ☑ Ratio < seuil   ──────●──────────  Seuil: [1.0]  ×[30] │
│  ☑ Seed time >     ──────────●──────  Seuil: [180]j ×[20] │
│  ☑ Taille fichier  ────●────────────  Seuil: [40]GB ×[10] │
│  ☑ Orphelin qBit   ──────────────●──               ×[25]  │
│  ☑ Cross-seed      ────────●────────  (par tracker) ×[-15] │
│                                                             │
│  Filtre seeding : [Tous ▼]    ☑ Exclure protégés           │
│                                                             │
│─────────────── Prévisualisation live ───────────────────────│
│                                                             │
│  Score │ Film              │ Ratio │ Seed    │ Taille │ CS  │
│  ──────┼───────────────────┼───────┼─────────┼────────┼─────│
│  🔴 85 │ Inception (2010)  │  0.18 │ 14 mois │ 52 GB  │ 0  │
│  🔴 75 │ Avatar (2009)     │  0.31 │ 11 mois │ 68 GB  │ 0  │
│  🟡 45 │ Dune (2021)       │  0.92 │ 8 mois  │ 35 GB  │ 2  │
│  🟢 10 │ Oppenheimer       │  2.40 │ 3 mois  │ 41 GB  │ 1  │
│  🟢  5 │ Interstellar      │  3.10 │ 2 mois  │ 28 GB  │ 3  │
│                                                             │
│  Films affichés : 234 │ Score moyen : 38 │ > 50 : 67 films │
└─────────────────────────────────────────────────────────────┘
```

**Live preview** : le score est recalculé côté front à chaque modification de slider/seuil/poids. L'API fournit les données brutes une seule fois (via `GET /api/v1/suggestions?preset_id=...`). Les recalculs sont instantanés en JS.

### 7.4 Colonnes enrichies dans la liste films (`MoviesListView.vue`)

Nouvelles colonnes triables/filtrables :

| Colonne | Description |
|---------|-------------|
| Ratio | Meilleur ratio parmi tous les torrents du film. Couleur : 🔴 < 0.5, 🟡 0.5-1.0, 🟢 > 1.0 |
| Seed time | Seed time le plus long, format humain (Xj / Xm / Xa) |
| Seeding | Badge : 🟢 En seed, 🔴 Orphelin, 🟡 Mixte |
| Fichiers | Badge "3 📁 • 120 GB" si multi-fichiers. Filtrable : "multi-fichiers uniquement" |
| 🛡 | Icône si film protégé |

### 7.5 Types TypeScript

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
```

---

## 8. Tendance du ratio

### 8.1 Calcul

Basé sur `torrent_stats_history` — comparer le ratio d'il y a 7 jours vs maintenant :

```typescript
type RatioTrend = 'rising' | 'stable' | 'falling';

function calculateTrend(currentRatio: number, ratioWeekAgo: number): RatioTrend {
  const delta = currentRatio - ratioWeekAgo;
  if (delta > 0.05) return 'rising';    // +0.05 en 7j = monte
  if (delta < -0.02) return 'falling';  // -0.02 en 7j = descend (rare mais possible)
  return 'stable';
}
```

Affiché comme icône dans la liste films : ↗️ rising, ➡️ stable, ↘️ falling.

---

## 9. Dashboard — Historique espace libéré

Nouvelle section dans le dashboard :

```
┌─────────────────────────────────────────────┐
│  Espace libéré                              │
│  ▁▂▃▄▅▆▇█▇▅▃▂                              │
│  Jan Feb Mar Apr May Jun Jul Aug Sep Oct    │
│  Total 2026 : 2.4 TB libérés               │
└─────────────────────────────────────────────┘
```

Données agrégées depuis `activity_logs` (action = `scheduled_deletion.executed`) + `execution_report.results` pour extraire les tailles.

---

## 10. Ordre d'implémentation V1.5

### Phase 1 — Fondations (semaine 1)

```
1.1 Migrations BDD : torrent_stats, torrent_stats_history, deletion_presets, tracker_rules
1.2 Ajouter partial_hash + is_protected sur media_files
1.3 Ajouter is_protected sur movies
1.4 Modifier le watcher : calcul partial_hash au scan (premiers 1MB + derniers 1MB → SHA256)
1.5 QBittorrentService : cache SID, méthodes enrichies
```

### Phase 2 — Sync qBit + matching (semaine 2)

```
2.1 QBittorrentSyncService : sync complet avec matching hash Radarr + fallback path
2.2 SyncQBittorrentCommand (cron)
2.3 Auto-détection trackers
2.4 Endpoint POST /api/v1/qbittorrent/sync
2.5 Historisation snapshots torrent_stats_history
```

### Phase 3 — Presets + Score (semaine 3)

```
3.1 CRUD presets (entity, controller, repository)
3.2 Seeder : 3 presets système (conservateur, modéré, agressif)
3.3 Enrichir GET /api/v1/movies avec stats qBit
3.4 Endpoint GET /api/v1/suggestions (données brutes pour calcul front)
3.5 Calcul du score côté front (composable useScore)
3.6 TrackerRule : entity, CRUD, auto-détection
```

### Phase 4 — UI Suggestions (semaine 4)

```
4.1 SuggestionsView.vue : page complète avec tableau, filtres, barre objectif
4.2 PresetsSettingsView.vue : config preset avec live preview
4.3 TrackerRulesSettingsView.vue
4.4 Colonnes enrichies dans MoviesListView.vue (ratio, seed time, seeding, multi-fichier)
4.5 Badge protégé + action protéger/déprotéger
4.6 Actions batch : suppression immédiate + ajout planification
```

### Phase 5 — Tendances + Dashboard (semaine 5)

```
5.1 Calcul tendance ratio (rising/stable/falling)
5.2 Cron nettoyage torrent_stats_history (90 jours)
5.3 Dashboard : graphique espace libéré par mois
5.4 Tests unitaires + intégration
```
