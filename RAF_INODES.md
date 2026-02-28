# RAF INODES — Manquements restants après implémentation

> **Contexte** : Le pipeline de remontée inode (Go → WebSocket → PHP handlers → BDD) est **terminé et fonctionnel**. Ce RAF couvre uniquement les manquements identifiés lors de la review du code V2.
>
> **Ce qui est DÉJÀ fait** (ne pas toucher) :
> - Go : `hardlink.Info()` → `FileInfo{Nlink, Inode, DeviceID}` + propagation scanner + watcher + events + tests
> - PHP Entity : `MediaFile.inode` + `MediaFile.deviceId` (BIGINT nullable) + index composite `(device_id, inode)`
> - Migration : `Version20260228190542.php`
> - Repository : `findByInode()` + `findAllByInode()`
> - Handlers : ScanFile, FileCreated, FileModified, FileRenamed — tous propagent inode + device_id
> - `WatcherFileHelper::createMediaFile()` — propage inode + device_id
> - `ScanFileHandler::syncHardlinkSiblings()` — synchronise nlink entre siblings
> - `InodeMatchingStrategy` + `FileMatchingStrategyInterface` + `MatchResult`
> - `hardlink_test.go` — 4 tests complets

---

## 🔴 CRITIQUE — Impact fonctionnel direct

### 1. DeletionService : enrichir la liste de suppression avec les siblings inode

**Problème** : `DeletionService::executeDeletion()` ne collecte que les fichiers explicitement listés dans `ScheduledDeletionItem.mediaFileIds`. Les siblings inode (hardlinks du même fichier physique) ne sont **jamais** ajoutés automatiquement.

**Conséquence** : si l'utilisateur supprime `media/Inception.mkv`, le hardlink `torrents/Inception.mkv` (même inode) n'est pas inclus → l'espace disque n'est pas libéré.

**Fichier** : `api/src/Service/DeletionService.php`, méthode `executeDeletion()`

**Correction** : Après la boucle qui collecte chaque `$mediaFile` depuis `$item->getMediaFileIds()` (autour de la ligne ~78), enrichir avec les siblings. Ajouter un `$seenFileIds` pour dédupliquer :

```php
public function executeDeletion(ScheduledDeletion $deletion): void
{
    $deletion->setStatus(DeletionStatus::EXECUTING);
    $this->em->flush();

    $allFilesToDelete = [];
    $seenFileIds = [];   // ← AJOUTER pour dédupliquer

    foreach ($deletion->getItems() as $item) {
        $movie = $item->getMovie();

        // Radarr dereference (inchangé)
        if ($deletion->isDeleteRadarrReference() && $movie instanceof Movie) {
            $this->dereferenceFromRadarr($movie);
        } elseif ($deletion->isDisableRadarrAutoSearch() && $movie instanceof Movie) {
            $this->disableRadarrAutoSearch($movie);
        }

        if ($deletion->isDeletePhysicalFiles()) {
            foreach ($item->getMediaFileIds() as $mediaFileId) {
                $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                if ($mediaFile === null) {
                    continue;
                }

                // ── Collecter le fichier explicite + ses siblings inode ──
                $filesToCollect = [$mediaFile];

                $deviceId = $mediaFile->getDeviceId();
                $inode = $mediaFile->getInode();
                if ($deviceId !== null && $inode !== null) {
                    $siblings = $this->mediaFileRepository->findAllByInode($deviceId, $inode);
                    foreach ($siblings as $sibling) {
                        $filesToCollect[] = $sibling;
                    }
                }

                foreach ($filesToCollect as $fileToDelete) {
                    $fid = (string) $fileToDelete->getId();
                    if (isset($seenFileIds[$fid])) {
                        continue;
                    }
                    $seenFileIds[$fid] = true;

                    $volume = $fileToDelete->getVolume();
                    if ($volume === null) {
                        continue;
                    }

                    // qBittorrent cleanup (best-effort)
                    $hostPath = $volume->getHostPath();
                    if ($hostPath !== null && $hostPath !== '' && $this->qBittorrentService->isConfigured()) {
                        $absoluteHostPath = rtrim($hostPath, '/') . '/' . $fileToDelete->getFilePath();
                        try {
                            $this->qBittorrentService->findAndDeleteTorrent($absoluteHostPath);
                        } catch (Throwable $e) {
                            $this->logger->warning('qBittorrent cleanup failed', [
                                'file' => $absoluteHostPath,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $volumeHostPath = $volume->getHostPath();
                    if ($volumeHostPath === null || $volumeHostPath === '') {
                        $volumeHostPath = $volume->getPath();
                    }

                    $allFilesToDelete[] = [
                        'media_file_id' => $fid,
                        'volume_path' => rtrim($volumeHostPath ?? '', '/'),
                        'file_path' => $fileToDelete->getFilePath(),
                    ];
                }
            }
        }
    }

    // ... reste inchangé (envoi watcher, etc.)
}
```

**Point d'attention** : `$this->mediaFileRepository` doit déjà être injecté dans `DeletionService`. Vérifier.

---

### 2. SuggestionService::calculateRealFreedBytes() — logique naïve, n'utilise pas l'inode

**Problème** : L'implémentation actuelle (ligne ~441) :
```php
private function calculateRealFreedBytes(MediaFile $file): int
{
    return $file->getHardlinkCount() > 1 ? 0 : $file->getFileSizeBytes();
}
```

Si `hardlink_count == 2` et que Scanarr connaît les 2 chemins (via inode), alors supprimer les 2 **libère** l'espace. La méthode actuelle retourne `0` dans ce cas → les suggestions affichent "0 octets libérés" pour tous les fichiers avec hardlinks, ce qui est trompeur.

**Fichier** : `api/src/Service/SuggestionService.php`

**Correction** :
```php
private function calculateRealFreedBytes(MediaFile $file): int
{
    $nlink = $file->getHardlinkCount();

    if ($nlink <= 1) {
        return $file->getFileSizeBytes();
    }

    $deviceId = $file->getDeviceId();
    $inode = $file->getInode();

    if ($deviceId === null || $inode === null) {
        // Pas d'info inode → on ne peut pas savoir → pessimiste
        return 0;
    }

    $knownSiblings = count($this->mediaFileRepository->findAllByInode($deviceId, $inode));

    // Si Scanarr connaît au moins autant de chemins que le nlink réel,
    // supprimer tous les chemins connus libère l'espace.
    return $knownSiblings >= $nlink ? $file->getFileSizeBytes() : 0;
}
```

**Dépendance** : `MediaFileRepository` doit être injecté dans `SuggestionService`. Vérifier s'il l'est déjà, sinon l'ajouter dans le constructeur.

**⚠️ Performance** : Cette méthode est appelée pour chaque fichier dans la liste des suggestions. Pour éviter N+1, envisager un pré-chargement batch des counts inode si les perfs sont un souci. Pour une V1, la requête individuelle est acceptable (index composite `(device_id, inode)` en place).

---

## 🟡 IMPORTANT — Complétude fonctionnelle

### 3. Sérialisation API — inode + device_id absents des réponses JSON

Les champs `inode` et `device_id` sont stockés en BDD mais **jamais exposés** dans les réponses API. Le front ne peut donc pas les utiliser.

**3 fichiers à modifier** :

**a) `api/src/Service/FileService.php`** — méthode `serializeFile()` (ligne ~107)
```php
public function serializeFile(MediaFile $file): array
{
    return [
        'id' => (string)$file->getId(),
        // ... champs existants ...
        'hardlink_count' => $file->getHardlinkCount(),
        'inode' => $file->getInode(),             // ← AJOUTER
        'device_id' => $file->getDeviceId(),      // ← AJOUTER
        // ... reste inchangé ...
    ];
}
```

**b) `api/src/Service/MovieService.php`** — méthode `serializeDetail()` (ligne ~260), dans le bloc `$files[]`
```php
$files[] = [
    'id' => (string) $mediaFile->getId(),
    // ... champs existants ...
    'hardlink_count' => $mediaFile->getHardlinkCount(),
    'inode' => $mediaFile->getInode(),             // ← AJOUTER
    'device_id' => $mediaFile->getDeviceId(),      // ← AJOUTER
    // ... reste inchangé ...
];
```

**c) `api/src/Service/SuggestionService.php`** — bloc `$filesData[]` (ligne ~268)
```php
$filesData[] = [
    // ... champs existants ...
    'hardlink_count' => $mediaFile->getHardlinkCount(),
    'inode' => $mediaFile->getInode(),             // ← AJOUTER
    'device_id' => $mediaFile->getDeviceId(),      // ← AJOUTER
    // ... reste inchangé ...
];
```

---

### 4. Frontend TypeScript — type `MediaFile` incomplet

**Fichier** : `front/src/types/index.ts`, interface `MediaFile` (ligne ~25)

```typescript
export interface MediaFile {
  id: string;
  volume_id: string;
  volume_name: string;
  file_path: string;
  file_name: string;
  file_size_bytes: number;
  hardlink_count: number;
  resolution?: string;
  codec?: string;
  quality?: string;
  is_linked_radarr: boolean;
  is_linked_media_player: boolean;
  detected_at: string;
  partial_hash?: string;
  is_protected: boolean;
  inode?: string;        // ← AJOUTER
  device_id?: string;    // ← AJOUTER
}
```

Optionnels car `null` pour les fichiers non encore re-scannés.

---

### 5. FileMatchingService — orchestrateur manquant

`InodeMatchingStrategy` existe mais aucun service ne l'appelle. Le TODO dans `QBittorrentSyncService` (ligne ~254) le confirme :
```
// TODO(RAF_INODES #7): add inode-based matching via FileMatchingService once that
// service exists (requires stat() on host paths — not feasible from inside Docker).
```

**Créer** `api/src/Service/FileMatchingService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Matching\FileMatchingStrategyInterface;
use App\Contract\Matching\MatchResult;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class FileMatchingService
{
    /** @var list<FileMatchingStrategyInterface> */
    private readonly array $strategies;

    /**
     * @param iterable<FileMatchingStrategyInterface> $strategies
     */
    public function __construct(
        #[TaggedIterator('scanarr.matching_strategy', defaultPriorityMethod: 'getPriority')]
        iterable $strategies,
    ) {
        // Sort by descending priority (highest first)
        $arr = iterator_to_array($strategies);
        usort($arr, static fn ($a, $b) => $b::getPriority() <=> $a::getPriority());
        $this->strategies = $arr;
    }

    /**
     * Try each strategy in priority order. Return the first match, or null.
     *
     * @param array<string, mixed> $context
     */
    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        foreach ($this->strategies as $strategy) {
            $result = $strategy->match($externalPath, $context);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
```

**Note sur le TODO Docker** : Le commentaire dit que le `stat()` n'est pas faisable depuis Docker. C'est vrai pour un stat live, mais le matching par inode fonctionne si l'inode est déjà en BDD (remonté par le watcher au scan). Le `FileMatchingService` est donc utilisable pour le matching BDD via les valeurs stockées. Le TODO peut être partiellement résolu : utiliser le service pour le matching BDD, documenter que le stat live reste hors scope.

---

### 6. MediaFileRepository : ajouter findSiblingsByInode()

`findAllByInode()` retourne **tous** les fichiers d'un inode y compris self. Il manque une variante excluant le fichier courant — utile pour l'endpoint siblings et l'affichage front.

**Fichier** : `api/src/Repository/MediaFileRepository.php`

```php
/**
 * Find all other MediaFiles sharing the same physical inode (excluding self).
 *
 * @return list<MediaFile>
 */
public function findSiblingsByInode(MediaFile $file): array
{
    $deviceId = $file->getDeviceId();
    $inode = $file->getInode();

    if ($deviceId === null || $inode === null) {
        return [];
    }

    return $this->createQueryBuilder('mf')
        ->where('mf.deviceId = :deviceId')
        ->andWhere('mf.inode = :inode')
        ->andWhere('mf.id != :selfId')
        ->setParameter('deviceId', $deviceId)
        ->setParameter('inode', $inode)
        ->setParameter('selfId', $file->getId())
        ->getQuery()
        ->getResult();
}
```

---

## 🔵 MINEUR — Polish

### 7. Endpoint GET /api/v1/files/{id}/siblings

Utile pour le front : afficher "ce fichier partage son inode avec X autres chemins".

**Fichier** : `api/src/Controller/FileController.php`

```php
#[Route('/api/v1/files/{id}/siblings', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
public function siblings(string $id): JsonResponse
{
    $mediaFile = $this->mediaFileRepository->find($id);
    if ($mediaFile === null) {
        return $this->json(['error' => ['code' => 404, 'message' => 'File not found']], 404);
    }

    $siblings = $this->mediaFileRepository->findSiblingsByInode($mediaFile);

    return $this->json([
        'data' => array_map(fn (MediaFile $f) => [
            'id' => (string) $f->getId(),
            'file_path' => $f->getFilePath(),
            'file_name' => $f->getFileName(),
            'file_size_bytes' => $f->getFileSizeBytes(),
            'volume_id' => (string) $f->getVolume()?->getId(),
            'volume_name' => $f->getVolume()?->getName(),
        ], $siblings),
        'meta' => [
            'inode' => $mediaFile->getInode(),
            'device_id' => $mediaFile->getDeviceId(),
            'hardlink_count_on_disk' => $mediaFile->getHardlinkCount(),
            'known_in_scanarr' => count($siblings) + 1,
        ],
    ]);
}
```

**Dépendance** : `MediaFileRepository` doit être injecté dans `FileController`.

---

### 8. Double tagging InodeMatchingStrategy

Même problème que les handlers WebSocket : `InodeMatchingStrategy` a `#[AutoconfigureTag('scanarr.matching_strategy')]` **ET** `services.yaml` a un bloc `_instanceof` pour `FileMatchingStrategyInterface`.

**Choisir l'une des deux approches** (être cohérent avec le choix fait pour les handlers) :
- **Option A** : Retirer `#[AutoconfigureTag]` de `InodeMatchingStrategy.php` — garder le `_instanceof` dans `services.yaml`
- **Option B** : Retirer le bloc `_instanceof` pour `FileMatchingStrategyInterface` dans `services.yaml` — garder l'attribut

Si les handlers WebSocket utilisent `#[AutoconfigureTag]` + `_instanceof`, alors garder la cohérence. Si le RAF CODING_STANDARDS supprime le `_instanceof` pour les handlers, faire pareil ici.

---

### 9. Documentation — Mettre à jour PATH_MAPPING.md et DATABASE.md

**a) PATH_MAPPING.md** : La note V1.5 section 3 dit :
> "Le watcher ne fait PAS de `find` par inode. Il supprime uniquement les chemins qu'on lui fournit."

**Mettre à jour** : Le watcher remonte désormais l'inode et le device_id pour chaque fichier. L'API peut regrouper les hardlinks par inode en BDD. La limitation `find` par inode reste (le watcher ne fait pas de discovery), mais la collecte automatique des chemins connus par inode est opérationnelle via `DeletionService`.

**b) DATABASE.md** : Ajouter les colonnes `inode BIGINT` et `device_id BIGINT` dans le schéma de la table `media_files`, avec l'index composite.

---

## Checklist

- [ ] `DeletionService::executeDeletion()` : enrichir avec siblings inode + dédupliquer
- [ ] `SuggestionService::calculateRealFreedBytes()` : utiliser `findAllByInode()` pour calcul précis
- [ ] `FileService::serializeFile()` : ajouter `inode` + `device_id`
- [ ] `MovieService::serializeDetail()` : ajouter `inode` + `device_id` dans le bloc files
- [ ] `SuggestionService` : ajouter `inode` + `device_id` dans `$filesData[]`
- [ ] `front/src/types/index.ts` : ajouter `inode?` + `device_id?` à `MediaFile`
- [ ] `FileMatchingService` créé avec `TaggedIterator` et tri par priorité
- [ ] `MediaFileRepository::findSiblingsByInode()` ajouté
- [ ] Endpoint `GET /api/v1/files/{id}/siblings` ajouté
- [ ] Double tagging `InodeMatchingStrategy` résolu (cohérent avec choix handlers)
- [ ] `PATH_MAPPING.md` mis à jour (note V1.5 actualisée)
- [ ] `DATABASE.md` mis à jour (colonnes inode + device_id documentées)
- [ ] `vendor/bin/phpunit` ✅
- [ ] `php bin/console lint:container` ✅
