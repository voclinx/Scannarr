# Scanarr — Gestion des Hardlinks et Remplacement de Fichier Lecteur

> **Prérequis** : [DELETION.md](DELETION.md), [PATH_MAPPING.md](PATH_MAPPING.md)
> **Version** : V1.5

---

## 1. Contexte

Un film peut avoir plusieurs fichiers de qualité différente :

```
Film: Inception (2010)
├── torrents/movies/Inception.2010.720p.BluRay.x264.mkv      (8 GB, ratio 0.1)
├── torrents/movies/Inception.2010.1080p.BluRay.x264.mkv     (15 GB, ratio 0.2)
├── torrents/movies/Inception.2010.2160p.BluRay.x265.mkv     (50 GB, ratio 1.8)
│
└── media/movies/Inception/Inception.mkv  ← hardlink du 1080p (Plex lit celui-ci)
```

L'utilisateur veut supprimer le 720p et le 1080p (mauvais ratios), mais garder le 4K. Problème : le 1080p est celui utilisé par Plex. Il faut **remplacer** le hardlink dans `media/` par un hardlink du 4K avant de supprimer le 1080p.

---

## 2. Flow de remplacement

```
1. Utilisateur demande suppression du 1080p (actuellement lié à Plex)
2. Scanarr détecte que ce fichier est le fichier lecteur
3. Proposition : remplacer par le 4K (suggestion auto = meilleure qualité restante)
   → L'utilisateur peut choisir un autre fichier manuellement
4. Watcher crée un hardlink du 4K dans media/ (nouveau chemin)
5. Watcher supprime l'ancien hardlink du 1080p dans media/
6. API met à jour Radarr pour pointer sur le nouveau fichier
7. API refresh Plex/Jellyfin
8. Watcher supprime le 1080p dans torrents/ (+ tous ses hardlinks)
9. qBit : supprimer le torrent du 1080p
```

---

## 3. Détection du fichier lecteur

Un fichier est considéré comme "fichier lecteur" si :
- `media_file.is_linked_media_player = true` (existant V1)
- OU s'il existe dans un chemin `media/` (détecté via le volume mapping)

### 3.1 Identification via Radarr

Radarr sait quel fichier est associé au film (`GET /api/v3/movie/{id}` → `movieFile`). Ce fichier est celui dans le root folder Radarr (= `media/`). C'est le fichier lecteur.

---

## 4. Suggestion automatique de remplacement

Quand l'utilisateur sélectionne un fichier lecteur pour suppression, Scanarr propose automatiquement un remplacement :

### 4.1 Algorithme de sélection

```
Fichiers restants du même film (non sélectionnés pour suppression)
  → Trier par priorité :
     1. Plus haute résolution (2160p > 1080p > 720p)
     2. À résolution égale : plus haute qualité (Remux > BluRay > WEB-DL)
     3. À qualité égale : plus petite taille
  → Proposer le premier comme remplacement par défaut
  → L'utilisateur peut sélectionner un autre
```

### 4.2 UI

Quand un fichier lecteur est sélectionné pour suppression :

```
┌────────────────────────────────────────────────────────────┐
│ ⚠️ Ce fichier est utilisé par votre lecteur (Plex)        │
│                                                            │
│ Fichier de remplacement :                                  │
│ ● Inception.2010.2160p.BluRay.x265.mkv (50 GB) [Suggéré] │
│ ○ Inception.2010.720p.BluRay.x264.mkv (8 GB)              │
│                                                            │
│ ☑ Désactiver auto-search Radarr                            │
│ ☑ Mettre à jour Radarr avec le nouveau fichier             │
│ ☑ Rafraîchir Plex après remplacement                       │
└────────────────────────────────────────────────────────────┘
```

---

## 5. Commande WebSocket : `command.files.hardlink`

Nouvelle commande pour la création de hardlinks par le watcher.

### 5.1 API → Watcher

```json
{
  "type": "command.files.hardlink",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "source_path": "/mnt/user/data/torrents/movies/Inception.2010.2160p.BluRay.x265.mkv",
    "target_path": "/mnt/user/data/media/movies/Inception/Inception (2010) [2160p].mkv",
    "volume_path": "/mnt/user/data"
  }
}
```

- `source_path` : fichier existant (le 4K dans torrents/)
- `target_path` : chemin du nouveau hardlink à créer (dans media/)
- `volume_path` : pour la validation de sécurité (les deux chemins doivent être sous ce root)

### 5.2 Watcher → API

```json
{
  "type": "files.hardlink.completed",
  "data": {
    "request_id": "uuid-request",
    "deletion_id": "uuid-scheduled-deletion",
    "status": "created",
    "source_path": "/mnt/user/data/torrents/movies/Inception.2010.2160p.BluRay.x265.mkv",
    "target_path": "/mnt/user/data/media/movies/Inception/Inception (2010) [2160p].mkv",
    "error": null
  }
}
```

### 5.3 Implémentation watcher

```go
func (d *Deleter) CreateHardlink(source, target, volumeRoot string) HardlinkResult {
    result := HardlinkResult{
        SourcePath: source,
        TargetPath: target,
        Status:     "created",
    }

    // Validation sécurité : les deux chemins doivent être sous volumeRoot
    cleanSource := filepath.Clean(source)
    cleanTarget := filepath.Clean(target)
    cleanRoot := filepath.Clean(volumeRoot)

    if !strings.HasPrefix(cleanSource, cleanRoot+"/") || !strings.HasPrefix(cleanTarget, cleanRoot+"/") {
        result.Status = "failed"
        result.Error = "path traversal: paths must be under volume root"
        return result
    }

    // Vérifier que la source existe
    if _, err := os.Stat(cleanSource); os.IsNotExist(err) {
        result.Status = "failed"
        result.Error = "source file does not exist"
        return result
    }

    // Créer les répertoires parents de la cible si nécessaire
    targetDir := filepath.Dir(cleanTarget)
    if err := os.MkdirAll(targetDir, 0755); err != nil {
        result.Status = "failed"
        result.Error = fmt.Sprintf("failed to create target directory: %s", err)
        return result
    }

    // Supprimer la cible si elle existe déjà (remplacement)
    os.Remove(cleanTarget) // ignore error if not exists

    // Créer le hardlink
    if err := os.Link(cleanSource, cleanTarget); err != nil {
        result.Status = "failed"
        result.Error = fmt.Sprintf("failed to create hardlink: %s", err)
        return result
    }

    slog.Info("Hardlink created", "source", cleanSource, "target", cleanTarget)
    return result
}
```

---

## 6. Mise à jour Radarr après remplacement

Après création du hardlink, l'API doit mettre à jour Radarr pour qu'il reconnaisse le nouveau fichier :

```php
// RadarrService.php

// Option 1 : Rescan du film dans Radarr
// POST /api/v3/command
// { "name": "RescanMovie", "movieId": 42 }
// Radarr détecte automatiquement le nouveau fichier dans son root folder

public function rescanMovie(RadarrInstance $instance, int $radarrMovieId): void
{
    $this->httpClient->request('POST', $instance->getUrl() . '/api/v3/command', [
        'headers' => ['X-Api-Key' => $instance->getApiKey()],
        'json' => [
            'name' => 'RescanMovie',
            'movieId' => $radarrMovieId,
        ],
    ]);
}
```

Le rescan Radarr est la méthode la plus fiable : Radarr détecte le nouveau fichier, met à jour ses métadonnées (résolution, codec, qualité), et pointe dessus automatiquement.

---

## 7. Flow complet ordonné

```
Phase 1 — Préparation (API, synchrone) :
  1. Valider la demande de remplacement
  2. Construire le target_path pour le hardlink (basé sur le naming Radarr ou le chemin existant)

Phase 2 — Création hardlink (Watcher, via WebSocket) :
  3. Envoyer command.files.hardlink au watcher
  4. Watcher crée le hardlink : source (4K dans torrents/) → target (media/)
  5. Watcher répond files.hardlink.completed

Phase 3 — Mise à jour services (API, synchrone) :
  6. Radarr : RescanMovie pour détecter le nouveau fichier
  7. Créer/mettre à jour le media_file en BDD pour le nouveau hardlink

Phase 4 — Suppression de l'ancien fichier (chaîne standard) :
  8. Supprimer l'ancien fichier lecteur (media/ + torrents/ + tous hardlinks)
  9. Nettoyer qBit (supprimer le torrent de l'ancien fichier)
  10. Refresh Plex/Jellyfin
  11. Discord notification

Si Phase 2 échoue → annuler toute l'opération. Ne pas supprimer l'ancien fichier.
```

---

## 8. Naming du fichier de remplacement

Le `target_path` (nouveau hardlink dans media/) suit le naming scheme de Radarr si disponible :

```
Radarr naming scheme : {Movie Title} ({Release Year}) [{Quality Full}]
Exemple : Inception (2010) [Bluray-2160p x265].mkv
```

Si le naming scheme Radarr n'est pas disponible, utiliser le nom du fichier source tel quel.

Le chemin du répertoire dans media/ reste le même que l'ancien fichier (même dossier film).
