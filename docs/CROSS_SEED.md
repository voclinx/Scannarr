# Scanarr — Gestion du Cross-Seed

> **Prérequis** : [QBIT_STATS_AND_SCORING.md](QBIT_STATS_AND_SCORING.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V1.5

---

## 1. Contexte

Le cross-seed permet de partager un même fichier sur plusieurs trackers. Un même fichier physique peut donc avoir **N torrents** dans qBittorrent (1 par tracker). La suppression d'un fichier cross-seedé impacte le seeding sur **tous** les trackers simultanément.

### 1.1 Setup typique

```
Fichier physique (1 seul inode) :
  /mnt/user/data/torrents/movies/Inception.2010.2160p.mkv

3 torrents dans qBittorrent, même fichier :
  hash: abc123 → tracker-a.com  (ratio: 0.8, seed 6 mois)
  hash: def456 → tracker-b.org  (ratio: 1.5, seed 3 mois)
  hash: ghi789 → tracker-c.net  (ratio: 0.3, seed 1 mois)
```

### 1.2 Implications

- **Score de suppression** : doit prendre en compte la valeur cumulée sur tous les trackers
- **Règles tracker** : chaque tracker est vérifié individuellement. Si un seul n'est pas satisfait → fichier bloqué
- **Affichage** : l'utilisateur doit voir tous les trackers d'un fichier avant de décider

---

## 2. Groupement des torrents cross-seed

### 2.1 Stratégie : matching par `content_path` qBit → media_file → partial_hash

Le matching torrent → fichier se fait dans le sync qBit (voir [QBIT_STATS_AND_SCORING.md](QBIT_STATS_AND_SCORING.md) §3.2). Une fois les torrents liés aux `media_files`, le groupement cross-seed est implicite : **tous les `torrent_stats` liés au même `media_file_id` forment un groupe cross-seed**.

Mais pour les fichiers cross-seed dans un répertoire séparé (pas dans `/data/torrents/` standard), le matching par path peut échouer. C'est là que le `partial_hash` intervient.

### 2.2 Partial hash

**Calcul** : SHA-256 des premiers 1 MB + derniers 1 MB du fichier.

```go
// watcher/internal/scanner/scanner.go

func calculatePartialHash(filePath string) (string, error) {
    f, err := os.Open(filePath)
    if err != nil {
        return "", err
    }
    defer f.Close()

    stat, err := f.Stat()
    if err != nil {
        return "", err
    }

    h := sha256.New()

    // Premiers 1 MB
    buf := make([]byte, 1024*1024)
    n, err := f.Read(buf)
    if err != nil && err != io.EOF {
        return "", err
    }
    h.Write(buf[:n])

    // Derniers 1 MB (si fichier > 2 MB)
    if stat.Size() > 2*1024*1024 {
        _, err = f.Seek(-1024*1024, io.SeekEnd)
        if err != nil {
            return "", err
        }
        n, err = f.Read(buf)
        if err != nil && err != io.EOF {
            return "", err
        }
        h.Write(buf[:n])
    }

    return hex.EncodeToString(h.Sum(nil)), nil
}
```

**Quand** : calculé par le watcher lors de chaque scan. Envoyé dans le message `scan.file` et stocké dans `media_files.partial_hash`.

**Usage** : deux `media_files` avec le même `partial_hash` + même `file_size_bytes` = même fichier physique (même inode ou copie identique).

### 2.3 Groupement cross-seed via partial_hash

Le sync qBit, après avoir matché un torrent à un `media_file`, vérifie s'il existe d'autres `media_files` avec le même `partial_hash`. Si oui, le torrent est aussi lié à ces fichiers (même contenu physique, potentiellement sur des chemins différents).

```php
// QBittorrentSyncService.php — après matching torrent → media_file

$matchedFile = $this->findMediaFileForTorrent($torrent);
if ($matchedFile === null) return;

// Chercher les cross-seed : mêmes fichiers physiques sur d'autres chemins
$crossSeedFiles = $this->mediaFileRepository->findBy([
    'partial_hash' => $matchedFile->getPartialHash(),
    'file_size_bytes' => $matchedFile->getFileSizeBytes(),
]);

// Le torrent_stats est lié au media_file principal
// Les autres media_files (cross-seed) partagent le même partial_hash
// → l'UI peut grouper via partial_hash
```

### 2.4 Résumé du flow

```
Sync qBit
  │
  ├── Pour chaque torrent dans qBit :
  │   ├── Match torrent → media_file (hash Radarr ou content_path)
  │   ├── Créer/MAJ torrent_stats (lié au media_file)
  │   └── Auto-détecter tracker
  │
  └── Résultat en BDD :
      media_file (partial_hash: "x7f...")
        ├── torrent_stats (hash: abc123, tracker-a.com, ratio: 0.8)
        ├── torrent_stats (hash: def456, tracker-b.org, ratio: 1.5)
        └── torrent_stats (hash: ghi789, tracker-c.net, ratio: 0.3)
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

Pour l'affichage et la prise de décision, les stats agrégées d'un fichier cross-seedé sont :

| Métrique | Calcul | Justification |
|----------|--------|---------------|
| Upload cumulé | Somme des `uploaded_bytes` de tous les torrents | Vraie contribution totale |
| Nombre de trackers | Count des `torrent_stats` distincts | Indicateur de valeur |
| Meilleur ratio | Max des `ratio` | Le fichier a de la valeur quelque part |
| Pire ratio | Min des `ratio` | Pour identifier les trackers sous-performants |
| Seed time | **Par tracker uniquement**, jamais cumulé | Non additif — 12 mois sur un tracker mort ≠ valeur |

> **Important** : Le seed time n'est JAMAIS cumulé entre trackers. C'est une donnée par tracker, utilisée individuellement pour les règles tracker et l'affichage détaillé.

---

## 4. Impact sur les règles tracker

Quand un fichier est cross-seedé sur N trackers, les règles tracker de **chacun** des N trackers doivent être satisfaites pour permettre la suppression :

```
Fichier: Inception.mkv
  tracker-a.com : seed 48h requis, actuellement 72h → ✅
  tracker-b.org : seed 24h requis, actuellement 12h → ❌ BLOQUÉ
  tracker-c.net : pas de règle → ✅

Résultat : fichier BLOQUÉ (tracker-b.org non satisfait)
```

La suppression d'un fichier cross-seedé impacte le seeding sur **tous** les trackers. On ne peut pas supprimer sélectivement le seeding sur un tracker — soit le fichier existe, soit il n'existe pas.

---

## 5. Impact sur la suppression

Quand un fichier cross-seedé est supprimé :

1. **Tous les hardlinks** du fichier sont supprimés (media/ + torrents/ + cross-seed/)
2. **Tous les torrents** liés sont supprimés de qBit (`POST /api/v2/torrents/delete` pour chaque hash)
3. Les `torrent_stats` correspondants sont marqués `status = 'removed'`
4. Les `media_files` correspondants (même `partial_hash`) sont supprimés de la BDD

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
│ 52 GB │ 2160p │ x265 │ 2 hardlinks │ CS: 3 trackers│
│─────────────────────────────────────────────────────│
│ Tracker          │ Ratio │ Seed time │ Upload │ Status│
│──────────────────┼───────┼───────────┼────────┼───────│
│ tracker-a.com    │  0.82 │ 6 mois    │ 42 GB  │ 🟢   │
│ tracker-b.org    │  1.50 │ 3 mois    │ 78 GB  │ 🟢   │
│ tracker-c.net    │  0.31 │ 1 mois    │ 16 GB  │ 🟢   │
│──────────────────┼───────┼───────────┼────────┼───────│
│ Cumulé           │       │           │ 136 GB │       │
└─────────────────────────────────────────────────────┘
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
