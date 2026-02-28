# Scanarr — Correspondance des Chemins (Path Mapping)

> **Prérequis** : [ARCHITECTURE.md](ARCHITECTURE.md)
> **Version** : V1.5.1 (collecte inode + enrichissement automatique des hardlinks)

---

## 1. Le problème

Chaque service dans un setup typique voit le filesystem différemment. Le même fichier physique sur le NAS a des chemins différents selon le service qui y accède :

```
Même fichier physique sur le NAS :
/mnt/user/data/media/movies/Inception/Inception.2010.2160p.mkv

Ce que chaque service voit (Docker mounts différents) :
───────────────────────────────────────────────────────────
qBittorrent :  /downloads/movies/Inception.2010.2160p.mkv       (hardlink dans torrents/)
Radarr :       /movies/Inception/Inception.2010.2160p.mkv       (mount /data/media/movies → /movies)
Plex :         /media/movies/Inception/Inception.2010.2160p.mkv (mount /data/media → /media)
Scanarr API :  /mnt/volume1/movies/Inception/Inception.2010.2160p.mkv (mount Docker API)
Watcher :      /mnt/user/data/media/movies/Inception/Inception.2010.2160p.mkv (natif, chemin réel)
```

### 1.1 Hardlinks : un fichier, plusieurs chemins

Avec le setup recommandé (TRaSH Guides), un même fichier physique (même inode) a 2+ hardlinks :

```
Inode 12345 (50 GB, stocké UNE seule fois sur disque)
  ├── /mnt/user/data/torrents/movies/Inception.2010.2160p.mkv   ← qBit seed depuis ici
  └── /mnt/user/data/media/movies/Inception/Inception.2010.2160p.mkv  ← Plex/Radarr lisent ici
```

**Conséquence suppression** : supprimer UN seul hardlink ne libère AUCUN espace. L'inode (et donc l'espace disque) n'est libéré que quand le **dernier** hardlink est supprimé. Scanarr doit donc identifier et supprimer **tous les hardlinks** d'un fichier pour réellement libérer de l'espace.

### 1.2 Radarr peut renommer

Radarr applique un naming scheme. Le fichier dans `torrents/` garde le nom original du torrent, mais celui dans `media/` peut être renommé :

```
torrents/ : Inception.2010.2160p.UHD.BluRay.x265-GROUP.mkv   ← nom original
media/    : Inception (2010) [2160p] [Bluray] [x265].mkv     ← renommé par Radarr
```

→ Le matching par nom de fichier ne suffit pas entre `torrents/` et `media/`.

---

## 2. Stratégie de mapping dans Scanarr

### 2.1 Volumes Scanarr (existant V1)

Chaque volume en BDD a deux chemins :

| Champ | Usage | Exemple |
|-------|-------|---------|
| `path` | Chemin vu par l'API Docker | `/mnt/volume1` |
| `host_path` | Chemin réel sur le serveur hôte (watcher) | `/mnt/user/data/media/movies` |

Le watcher utilise `host_path`. La commande `command.files.delete` envoie `host_path` (avec fallback sur `path`).

### 2.2 Radarr Root Folder Mapping (existant V1)

Chaque instance Radarr a des root folders avec un `mapped_path` dans Scanarr :

```json
// radarr_instances.root_folders
[
  {"id": 1, "path": "/movies", "mapped_path": "/mnt/user/data/media/movies"}
]
```

`path` = ce que Radarr voit. `mapped_path` = chemin réel sur le host. Utilisé pour la sync Radarr → matching fichier.

### 2.3 qBittorrent Path Mapping (V1.5)

Nouvelle configuration dans les settings pour mapper les chemins qBit vers les chemins réels :

```
Settings → qBittorrent → Path Mappings
┌──────────────────────────────────────────────────────┐
│  Chemin qBittorrent        Chemin réel (host)        │
│  /downloads/movies    →    /mnt/user/data/torrents/movies │
│  /downloads/tv        →    /mnt/user/data/torrents/tv     │
└──────────────────────────────────────────────────────┘
```

Stocké dans la table `settings` :

```json
// setting_key: "qbittorrent_path_mappings"
// setting_type: "json"
[
  {"qbit_path": "/downloads/movies", "host_path": "/mnt/user/data/torrents/movies"},
  {"qbit_path": "/downloads/tv", "host_path": "/mnt/user/data/torrents/tv"}
]
```

### 2.4 Matching torrent → fichier (V1.5)

Le matching utilise le **hash torrent via l'historique Radarr** comme pont principal :

```
qBit API                    Radarr API                     Scanarr BDD
───────                     ──────────                     ───────────
hash: abc123   ←── match ──► history: hash abc123          
ratio: 0.8                   → movieId: 42                 movie.tmdb_id: 550
seed_time: 48h               → tmdbId: 550         ──────► movie "Inception"
                                                            └── media_files
```

**Fallback** (fichiers non passés par Radarr) : matching par `content_path` de qBit + path mapping → chemin host → comparaison avec `media_file.file_path`.

**Matching par inode (V1.5.1)** : Un `FileMatchingService` orchestre des stratégies de matching par priorité. `InodeMatchingStrategy` (priorité 100, confidence 1.0) permet un matching garanti lorsque le couple `(device_id, inode)` est connu en BDD — aucune ambiguïté possible car l'inode identifie physiquement le fichier. Ce matching repose sur les données déjà indexées en BDD (remontées par le watcher au scan), il ne nécessite pas d'appel au watcher.

---

## 3. Suppression complète des hardlinks

Quand l'utilisateur demande la suppression d'un fichier, l'API doit collecter **tous les chemins connus** de ce fichier pour les envoyer au watcher :

1. **Chemin media/** : connu via `media_file.file_path` + `volume.host_path`
2. **Chemin torrents/** : connu via le matching qBit (torrent `content_path` + path mapping)
3. **Chemins cross-seed** : connus via le groupement cross-seed (voir [CROSS_SEED.md](CROSS_SEED.md))
4. **Siblings inode (V1.5.1)** : connus via `findAllByInode(device_id, inode)` — tous les `media_files` partageant le même inode

L'API envoie tous ces chemins dans la commande `command.files.delete`. Le watcher supprime chacun d'eux. Quand tous les hardlinks sont supprimés → espace libéré.

> **Note V1.5.1** : Le watcher remonte désormais `inode` et `device_id` pour chaque événement fichier (`scan.file`, `file.created`, `file.modified`, `file.renamed`). L'API regroupe automatiquement les hardlinks via le couple `(device_id, inode)` en BDD. `DeletionService::executeDeletion()` collecte automatiquement les siblings inode pour chaque fichier sélectionné avant envoi au watcher, éliminant les doublons via un set `seenFileIds`.
>
> Le watcher ne fait **pas** de discovery inode (il ne scanne pas le filesystem pour trouver des chemins inconnus). Il supprime uniquement les chemins que l'API lui fournit. Les hardlinks dans des répertoires non surveillés par un volume Scanarr ne seront pas découverts ni supprimés.

---

## 4. Calcul de l'espace réellement libéré (hardlink-aware)

Le `hardlink_count` (nlink) de chaque `media_file` est stocké en BDD (remonté par le watcher au scan). Pour calculer l'espace réellement libéré :

```
Si nlink == 1 : espace libéré = file_size (dernier lien, inode supprimé)
Si nlink == 2 et on supprime les 2 chemins connus : espace libéré = file_size
Si nlink == 3 et on supprime 2 chemins : espace libéré = 0 (1 lien inconnu reste)
Si nlink > chemins connus : espace libéré = 0 (des liens inconnus existent)
```

L'UI affiche toujours **l'espace réellement libéré**, pas la taille brute du fichier. Un tooltip explique la différence si applicable : "Ce fichier a X hardlinks, seuls Y sont connus de Scanarr".

> **Implémentation V1.5.1** : `SuggestionService::calculateRealFreedBytes()` utilise `findAllByInode(device_id, inode)` pour compter les chemins connus (`knownSiblings`). Si `knownSiblings >= nlink`, l'espace sera libéré ; sinon, l'espace affiché est 0 (des liens inconnus existent quelque part). Cela remplace la logique naïve V1.5 (`hardlink_count > 1 ? 0 : file_size`) qui retournait toujours 0 pour tout fichier avec hardlinks, même quand Scanarr connaissait tous les chemins.
