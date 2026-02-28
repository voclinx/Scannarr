# Scanarr — Gestion des Hardlinks et Remplacement de Fichier Lecteur

> **Prérequis** : [DELETION.md](DELETION.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V2.0

---

## 1. Contexte

Un film peut avoir plusieurs fichiers de qualité différente. Chaque fichier est un `media_file` distinct (inode différent) avec ses propres `file_paths` :

```
Film: Inception (2010)

media_file A (inode 11111, 8 GB, ratio 0.1)
  └── file_paths: /torrents/movies/Inception.2010.720p.BluRay.x264.mkv

media_file B (inode 22222, 15 GB, ratio 0.2) ← fichier lecteur (Plex)
  ├── file_paths: /torrents/movies/Inception.2010.1080p.BluRay.x264.mkv
  └── file_paths: /media/movies/Inception/Inception.mkv

media_file C (inode 33333, 50 GB, ratio 1.8)
  ├── file_paths: /torrents/movies/Inception.2010.2160p.BluRay.x265.mkv
  └── file_paths: /links/movies/Inception.2010.2160p.BluRay.x265.mkv
```

L'utilisateur veut supprimer le 720p et le 1080p. Problème : le 1080p est celui utilisé par Plex. Il faut **remplacer** le hardlink dans `media/` par un hardlink du 4K avant de supprimer le 1080p.

---

## 2. Flow de remplacement

```
1. Utilisateur demande suppression du media_file B (1080p, fichier lecteur)
2. Scanarr détecte que ce media_file a un file_path dans un volume "media"
3. Proposition : remplacer par le media_file C (4K, suggestion auto = meilleure qualité restante)
4. Watcher crée un hardlink du 4K dans media/ (nouveau file_path)
5. Watcher supprime l'ancien hardlink du 1080p dans media/
6. API met à jour Radarr (RescanMovie)
7. API refresh Plex/Jellyfin
8. Watcher supprime TOUS les file_paths du media_file B
9. qBit : supprimer les torrents liés au media_file B
```

---

## 3. Détection du fichier lecteur (V2.0)

Un fichier est considéré comme "fichier lecteur" si :
- `media_file.is_linked_media_player = true`
- OU s'il possède un `file_path` dans un volume dont le nom/chemin contient "media" (heuristique)
- OU via Radarr : `GET /api/v3/movie/{id}` → `movieFile` identifie le fichier dans le root folder

**V2.0** : La détection est facilitée par les `file_paths` qui montrent explicitement dans quels volumes le fichier est présent. Un fichier présent dans un volume "media" et un volume "torrents" est clairement identifiable.

---

## 4. Suggestion automatique de remplacement

### 4.1 Algorithme de sélection

```
Fichiers restants du même film (non sélectionnés pour suppression)
  → Trier par priorité :
     1. Plus haute résolution (2160p > 1080p > 720p)
     2. À résolution égale : plus haute qualité (Remux > BluRay > WEB-DL)
     3. À qualité égale : plus petite taille
  → Proposer le premier comme remplacement par défaut
```

### 4.2 UI

```
┌────────────────────────────────────────────────────────────┐
│ ⚠️ Ce fichier est utilisé par votre lecteur (Plex)        │
│                                                            │
│ Chemins actuels :                                          │
│  • /media/movies/Inception/Inception.mkv                   │
│  • /torrents/movies/Inception.2010.1080p.mkv               │
│                                                            │
│ Fichier de remplacement :                                  │
│ ● Inception.2010.2160p.BluRay.x265.mkv (50 GB) [Suggéré] │
│ ○ Inception.2010.720p.BluRay.x264.mkv (8 GB)              │
│                                                            │
│ ☑ Mettre à jour Radarr avec le nouveau fichier             │
│ ☑ Rafraîchir Plex après remplacement                       │
└────────────────────────────────────────────────────────────┘
```

---

## 5. Commande WebSocket : `command.files.hardlink`

### 5.1 API → Watcher

```json
{
  "type": "command.files.hardlink",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "source_volume_path": "/volume1/filmarr/torrents/movies",
    "source_relative_path": "Inception.2010.2160p.BluRay.x265.mkv",
    "target_volume_path": "/volume1/filmarr/media/movies",
    "target_relative_path": "Inception/Inception (2010) [2160p].mkv"
  }
}
```

Le watcher reconstruit les chemins absolus et crée le hardlink.

### 5.2 Watcher → API

```json
{
  "type": "files.hardlink.completed",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "created",
    "source_inode": 33333,
    "target_inode": 33333,
    "error": null
  }
}
```

**V2.0** : La réponse inclut les inodes pour que l'API puisse mettre à jour les `file_paths` et `media_files` correctement. Le source et target doivent avoir le même inode (hardlink).

### 5.3 Implémentation watcher

(Inchangée, voir V1.5 — validation sécurité paths + `os.Link()`)

---

## 6. Mise à jour BDD après remplacement (V2.0)

Après réception de `files.hardlink.completed` :

1. **Créer un nouveau `file_path`** pour le media_file C (4K) dans le volume media :
   - `media_file_id` = media_file C
   - `volume_id` = volume "media"
   - `relative_path` = "Inception/Inception (2010) [2160p].mkv"

2. **Mettre à jour `media_file.hardlink_count`** de C (maintenant nlink + 1)

3. **Puis** procéder à la suppression standard du media_file B (tous ses file_paths)

---

## 7. Flow complet ordonné

```
Phase 1 — Préparation (API, synchrone) :
  1. Valider la demande de remplacement
  2. Identifier source (file_path du 4K dans torrents/) et target (chemin dans media/)

Phase 2 — Création hardlink (Watcher, via WebSocket) :
  3. Envoyer command.files.hardlink
  4. Watcher crée le hardlink
  5. Watcher répond files.hardlink.completed

Phase 3 — Mise à jour (API) :
  6. Créer le file_path pour le nouveau hardlink
  7. Radarr : RescanMovie

Phase 4 — Suppression de l'ancien fichier (chaîne standard) :
  8. Supprimer tous les file_paths du media_file B
  9. Nettoyer qBit
  10. Refresh Plex/Jellyfin
  11. Discord notification

Si Phase 2 échoue → annuler. Ne pas supprimer l'ancien fichier.
```

---

## 8. Naming du fichier de remplacement

Le chemin dans media/ suit le naming scheme Radarr si disponible :

```
Radarr naming scheme : {Movie Title} ({Release Year}) [{Quality Full}]
Exemple : Inception (2010) [Bluray-2160p x265].mkv
```

Si non disponible, utiliser le nom du fichier source. Le dossier dans media/ reste le même.
