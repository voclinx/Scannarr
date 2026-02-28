# Scanarr — Gestion du Cross-Seed

> **Prérequis** : [QBIT_STATS_AND_SCORING.md](QBIT_STATS_AND_SCORING.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V2.0

---

## 1. Contexte

Le cross-seed permet de partager un même fichier sur plusieurs trackers. Un même fichier physique peut donc avoir **N torrents** dans qBittorrent (1 par tracker). La suppression d'un fichier cross-seedé impacte le seeding sur **tous** les trackers simultanément.

### 1.1 Setup typique

```
Fichier physique (1 seul inode 12345) :
  /mnt/user/data/torrents/movies/Inception.2010.2160p.mkv
  /mnt/user/data/links/movies/Inception.2010.2160p.mkv     ← hardlink cross-seed
  /mnt/user/data/media/movies/Inception/Inception.mkv       ← hardlink media

3 torrents dans qBittorrent, même fichier physique :
  hash: abc123 → tracker-a.com  (ratio: 0.8, seed 6 mois)
  hash: def456 → tracker-b.org  (ratio: 1.5, seed 3 mois)
  hash: ghi789 → tracker-c.net  (ratio: 0.3, seed 1 mois)
```

### 1.2 Implications

- **Score de suppression** : doit prendre en compte la valeur cumulée sur tous les trackers
- **Règles tracker** : chaque tracker est vérifié individuellement. Si un seul n'est pas satisfait → fichier bloqué
- **Affichage** : l'utilisateur doit voir tous les trackers d'un fichier avant de décider

---

## 2. Détection du cross-seed (V2.0 — inode-based)

### 2.1 Détection implicite par inode

En V2.0, le cross-seed est détecté **automatiquement** sans aucun calcul supplémentaire :

- Le watcher scanne **tous les volumes** (media/, torrents/, links/)
- Tous les hardlinks d'un fichier partagent le même inode
- L'API regroupe par `(device_id, inode)` → un seul `media_file` avec N `file_paths`
- Tous les `torrent_stats` matchés à ce `media_file` forment le groupe cross-seed

```
media_file (inode: 12345, device_id: 2049)
  ├── file_paths:
  │     ├── /torrents/movies/Inception.2010.2160p.mkv
  │     ├── /links/movies/Inception.2010.2160p.mkv
  │     └── /media/movies/Inception/Inception.mkv
  │
  └── torrent_stats:
        ├── hash: abc123 → tracker-a.com (ratio: 0.8)
        ├── hash: def456 → tracker-b.org (ratio: 1.5)
        └── hash: ghi789 → tracker-c.net (ratio: 0.3)
```

### 2.2 Ce qui est éliminé

| V1.x | V2.0 |
|------|------|
| `partial_hash` (SHA-256 premiers 1MB + derniers 1MB) | Plus nécessaire — l'inode suffit |
| Calcul I/O intensif par le watcher à chaque scan | Aucun I/O supplémentaire (inode = métadonnée stat()) |
| Groupement explicite par `partial_hash` + `file_size_bytes` | Groupement implicite par `media_file_id` |

### 2.3 Nombre de trackers

Le nombre de trackers d'un fichier = nombre de `torrent_stats` liés au même `media_file_id` :

```sql
SELECT COUNT(DISTINCT tracker_domain)
FROM torrent_stats
WHERE media_file_id = :id AND match_status = 'matched'
```

---

## 3. Impact sur le score de suppression

### 3.1 Critère cross-seed dans les presets

Le critère `cross_seed` dans un preset est un **bonus négatif** (protection) : plus un fichier est cross-seedé, moins il devrait être supprimé.

```json
"cross_seed": {
  "enabled": true,
  "weight": -15,
  "per_tracker": true
}
```

Calcul : `score += weight * (nombre_trackers - 1)`

Un fichier sur 3 trackers : `score += -15 * 2 = -30`. Ça protège fortement les fichiers bien cross-seedés.

### 3.2 Agrégat cross-seed

| Métrique | Calcul | Justification |
|----------|--------|---------------|
| Upload cumulé | Somme des `uploaded_bytes` de tous les torrents | Vraie contribution totale |
| Nombre de trackers | Count des `torrent_stats` distincts | Indicateur de valeur |
| Meilleur ratio | Max des `ratio` | Le fichier a de la valeur quelque part |
| Pire ratio | Min des `ratio` | Pour identifier les trackers sous-performants |
| Seed time | **Par tracker uniquement**, jamais cumulé | Non additif |

> **Important** : Le seed time n'est JAMAIS cumulé entre trackers. C'est une donnée par tracker, utilisée individuellement pour les règles tracker et l'affichage détaillé.

---

## 4. Impact sur les règles tracker

Quand un fichier est cross-seedé sur N trackers, les règles tracker de **chacun** des N trackers doivent être satisfaites pour permettre la suppression :

```
Fichier: Inception.mkv (media_file, inode 12345)
  tracker-a.com : seed 48h requis, actuellement 72h → ✅
  tracker-b.org : seed 24h requis, actuellement 12h → ❌ BLOQUÉ
  tracker-c.net : pas de règle → ✅

Résultat : fichier BLOQUÉ (tracker-b.org non satisfait)
```

---

## 5. Impact sur la suppression

Quand un fichier cross-seedé est supprimé :

1. **Tous les `file_paths`** du `media_file` sont collectés et envoyés au watcher
2. Le watcher supprime chaque chemin physiquement
3. **Tous les torrents** liés sont supprimés de qBit (`POST /api/v2/torrents/delete` pour chaque hash)
4. Les `torrent_stats` correspondants sont marqués `status = 'removed'`
5. Le `media_file` et ses `file_paths` sont supprimés de la BDD

---

## 6. Affichage dans l'UI

### 6.1 Liste films

Colonne "CS" (Cross-Seed) : nombre de trackers. Badge coloré :
- `1` = pas cross-seedé (pas de badge)
- `2+` = badge avec le nombre (ex: "CS 3")

### 6.2 Page détail film

Section dédiée par fichier :

```
┌─────────────────────────────────────────────────────┐
│ Inception.2010.2160p.BluRay.x265.mkv                │
│ 52 GB │ 2160p │ x265 │ 3 chemins │ CS: 3 trackers  │
│─────────────────────────────────────────────────────│
│ Tracker          │ Ratio │ Seed time │ Upload │ Status│
│──────────────────┼───────┼───────────┼────────┼───────│
│ tracker-a.com    │  0.82 │ 6 mois    │ 42 GB  │ 🟢   │
│ tracker-b.org    │  1.50 │ 3 mois    │ 78 GB  │ 🟢   │
│ tracker-c.net    │  0.31 │ 1 mois    │ 16 GB  │ 🟢   │
│──────────────────┼───────┼───────────┼────────┼───────│
│ Cumulé           │       │           │ 136 GB │       │
│                                                       │
│ 📂 Chemins connus (3/3 hardlinks) :                   │
│  • /media/movies/Inception/Inception.mkv              │
│  • /torrents/movies/Inception.2010.2160p.mkv          │
│  • /links/movies/Inception.2010.2160p.mkv             │
└───────────────────────────────────────────────────────┘
```

### 6.3 Page suggestions

Le score breakdown montre le bonus cross-seed :

```json
{
  "ratio": 30,
  "seed_time": 20,
  "file_size": 10,
  "orphan_qbit": 0,
  "cross_seed": -30,
  "total": 30
}
```

Tooltip : "Cross-seed sur 3 trackers : -30 points (protection)"
