# Architecture Code — Scanarr

> Ce document définit l'architecture logicielle, les conventions de codage et les patterns à suivre pour tout développement sur Scanarr. Il fait autorité sur le code existant.

---

## 1. Principes fondamentaux

### 1.1 SOLID

| Principe | Application concrète |
|----------|---------------------|
| **S** — Single Responsibility | Un service = un domaine métier. Un controller = valider, déléguer, répondre. |
| **O** — Open/Closed | Interfaces pour tout point d'extension (clients torrent, media managers, notifications). Ajouter une implémentation, jamais modifier l'existante. |
| **L** — Liskov Substitution | `TransmissionClient` remplace `QBittorrentClient` sans casser le code appelant. |
| **I** — Interface Segregation | Pas d'interface fourre-tout. `TorrentClientInterface` ne contient pas de méthodes de notification. |
| **D** — Dependency Inversion | Les services dépendent d'interfaces, jamais d'implémentations concrètes. Injection via le container Symfony. |

### 1.2 Règles absolues

- **Zéro logique métier dans les controllers**
- **Zéro accès DB dans les controllers** (ni repository, ni EntityManager, ni QueryBuilder)
- **Zéro accès filesystem dans l'API** — seul le watcher touche au disque
- **UUID partout** — jamais d'auto-increment
- **Pas de `any` en TypeScript** — tout est typé explicitement

---

## 2. Architecture en couches (API Symfony)

```
Requête HTTP
    ↓
Controller          Valide l'input, appelle UN service, retourne la réponse
    ↓
Service             Logique métier, orchestre les dépendances
    ↓
Repository          Seul accès à la DB, requêtes nommées
    ↓
Entity              POPO, relations Doctrine, zéro logique
```

### 2.1 Controller

```php
#[Route('/api/v1/deletions', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateDeletionRequest $request): JsonResponse
{
    $result = $this->deletionService->scheduleDeletion($request);
    return $this->json($result, Response::HTTP_CREATED);
}
```

**Fait :**
- Valider via `#[MapRequestPayload]` ou ParamConverter
- Appeler un seul service
- Retourner un DTO de réponse

**Ne fait jamais :**
- `$em->flush()`
- `$repo->findBy()`
- `if ($entity->getStatus() === ...)` (c'est de la logique métier)
- `try/catch` métier (le service gère ses erreurs)

### 2.2 Service

```php
class DeletionOrchestrator
{
    public function __construct(
        private TorrentClientInterface $torrentClient,
        private MediaManagerInterface $mediaManager,
        private FilesystemGatewayInterface $filesystem,
        private NotificationChannelInterface $notifier,
        private ScheduledDeletionRepository $deletionRepo,
    ) {}
}
```

**Fait :**
- Porte toute la logique métier
- Dépend d'interfaces, jamais de classes concrètes
- Orchestre les appels entre repositories et services externes
- Dispatch des events pour les side-effects

**Ne fait jamais :**
- QueryBuilder ou DQL directement (→ repository)
- `file_exists()`, `stat()`, `unlink()` (→ watcher via FilesystemGateway)

### 2.3 Repository

```php
class MediaFileRepository extends ServiceEntityRepository
{
    /** @return MediaFile[] */
    public function findByInode(int $inodeNumber): array { ... }

    public function findUnlinkedFiles(): array { ... }

    public function findByVolumeAndType(Uuid $volumeId, PathType $type): array { ... }
}
```

**Fait :**
- Méthodes nommées explicitement (pas de `findBy` magique en dehors du repository)
- Seul endroit avec du QueryBuilder ou DQL
- Retourne des entities ou des DTOs de projection

**Ne fait jamais :**
- Logique métier, conditions if/else business
- Appels à d'autres services

### 2.4 Entity

```php
#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
class MediaFile
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'bigint')]
    private int $inodeNumber;

    #[ORM\Column(type: 'integer')]
    private int $hardlinkCount;

    #[ORM\OneToMany(targetEntity: FilePath::class, mappedBy: 'mediaFile', cascade: ['persist', 'remove'])]
    private Collection $paths;

    // Getters/setters uniquement — ZERO logique métier
}
```

---

## 3. Interfaces — Points d'extension

### 3.1 TorrentClientInterface

```php
interface TorrentClientInterface
{
    public function testConnection(): ConnectionResult;
    public function isConfigured(): bool;

    /** @return TorrentInfo[] */
    public function getAllTorrents(?string $category = null): array;

    /** @return TorrentFileInfo[] */
    public function getTorrentFiles(string $hash): array;

    public function deleteTorrent(string $hash): bool;

    /** @return array<string, int> path → inode (via watcher) */
    public function getContentPaths(): array;
}
```

| Implémentation | Client |
|----------------|--------|
| `QBittorrentClient` | qBittorrent Web API v2 |
| _futur_ | Transmission, Deluge, rTorrent |

### 3.2 MediaManagerInterface

```php
interface MediaManagerInterface
{
    public function getType(): MediaManagerType; // RADARR, SONARR, LIDARR...
    public function testConnection(): ConnectionResult;

    /** @return ExternalMedia[] */
    public function getAllMedia(): array;

    /** @return ExternalMediaFile[] */
    public function getMediaFiles(int $externalId): array;

    public function deleteMedia(int $externalId, bool $addExclusion = false): bool;
    public function disableAutoSearch(int $externalId): bool;
    public function rescanMedia(int $externalId): bool;

    public function parseTitle(string $filename): ?ParseResult;
    public function getRootFolders(): array;
}
```

| Implémentation | Service |
|----------------|---------|
| `RadarrClient` | Radarr API v3 |
| _futur_ | Sonarr API v3, Lidarr, Readarr |

### 3.3 MediaPlayerInterface

```php
interface MediaPlayerInterface
{
    public function testConnection(): ConnectionResult;
    public function refreshLibrary(?string $libraryId = null): bool;
    public function getLibraries(): array;
}
```

| Implémentation | Player |
|----------------|--------|
| `PlexClient` | Plex Media Server |
| `JellyfinClient` | Jellyfin / Emby |

### 3.4 NotificationChannelInterface

```php
interface NotificationChannelInterface
{
    public function send(Notification $notification): bool;
    public function test(): bool;
    public function isConfigured(): bool;
}
```

| Implémentation | Canal |
|----------------|-------|
| `DiscordWebhookChannel` | Discord Webhook |
| _futur_ | Telegram, Slack, Gotify, Email |

### 3.5 MetadataProviderInterface

```php
interface MetadataProviderInterface
{
    public function searchMovie(string $title, ?int $year = null): array;
    public function getMovieDetails(int $externalId): MovieMetadata;
    public function getMoviePoster(int $externalId): ?string;
}
```

| Implémentation | Source |
|----------------|--------|
| `TmdbProvider` | The Movie Database API v3 |
| _futur_ | OMDb, Trakt |

### 3.6 FilesystemGatewayInterface

Le watcher est le **seul** composant avec accès au filesystem. L'API communique avec lui via WebSocket.

```php
interface FilesystemGatewayInterface
{
    /**
     * Résout les inodes pour une liste de chemins.
     * @return array<string, InodeInfo> path → {inode, size, nlink}
     */
    public function resolveInodes(array $paths): array;

    /**
     * Supprime une liste de fichiers physiquement.
     * @return DeletionResult chemins supprimés, erreurs
     */
    public function deleteFiles(array $paths): DeletionResult;

    /**
     * Scanne un répertoire et retourne les fichiers détectés.
     * @return DetectedFile[] {path, inode, size, nlink}
     */
    public function scanDirectory(string $path): array;
}
```

Seule implémentation : `WatcherGateway` (communique via WebSocket avec le binaire Go).

### 3.7 FileMatchingStrategyInterface

Cascade de matching pour lier un `MediaFile` à un film, un torrent, etc.

```php
interface FileMatchingStrategyInterface
{
    public function match(MediaFile $file, array $candidates): ?MatchResult;
    public function getConfidence(): float;
    public function supports(MediaFile $file): bool;
    public function getName(): string;
}
```

**Implémentations par ordre de priorité :**

| Priorité | Stratégie | Confidence | Quand |
|----------|-----------|------------|-------|
| 100 | `InodeMatchingStrategy` | 1.0 | Hardlink : même inode |
| 80 | `BasenameMatchingStrategy` | 0.9 | Copy/move : même nom de fichier |
| 60 | `MediaManagerApiStrategy` | 0.85 | Parse API Radarr → tmdbId |
| 40 | `FileNameParserStrategy` | 0.5-0.8 | Parsing interne titre + année |

Enregistrement via tag Symfony :
```php
#[AutoconfigureTag('scanarr.matching_strategy')]
```

Orchestrateur :
```php
class FileMatchingService
{
    public function __construct(
        #[TaggedIterator('scanarr.matching_strategy', defaultPriorityMethod: 'getPriority')]
        private iterable $strategies
    ) {}

    public function match(MediaFile $file, array $candidates): ?MatchResult
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($file)) {
                $result = $strategy->match($file, $candidates);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }
}
```

---

## 4. Modèle de données — Hardlinks

### 4.1 Principe

Un fichier physique (1 inode) peut avoir N chemins (hardlinks). Scanarr stocke **tous les chemins connus**.

```
media_files           1 ligne = 1 fichier physique (identifié par inode)
    ↓ OneToMany
file_paths            1 ligne = 1 chemin connu vers ce fichier
```

### 4.2 Entités

```php
#[ORM\Entity]
class MediaFile
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $inodeNumber;

    #[ORM\Column(type: 'bigint')]
    private int $fileSizeBytes;

    #[ORM\Column(type: 'integer')]
    private int $hardlinkCount;          // nlink du filesystem

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $resolution;         // 1080p, 2160p...

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $codec;              // x265, H.264...

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $quality;            // BluRay, WEB-DL...

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $partialHash;        // pour cross-seed (V1.5)

    #[ORM\Column(type: 'boolean')]
    private bool $isProtected = false;

    #[ORM\Column]
    private DateTimeImmutable $detectedAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: FilePath::class, mappedBy: 'mediaFile', cascade: ['persist', 'remove'])]
    private Collection $paths;

    #[ORM\OneToMany(targetEntity: MovieFile::class, mappedBy: 'mediaFile', cascade: ['remove'])]
    private Collection $movieLinks;
}
```

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['media_file_id', 'path'])]
class FilePath
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MediaFile::class, inversedBy: 'paths')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaFile $mediaFile;

    #[ORM\Column(type: 'text')]
    private string $path;                // chemin complet

    #[ORM\Column(type: 'string', length: 500)]
    private string $fileName;            // basename extrait du path

    #[ORM\Column(type: 'string', length: 10, enumType: PathType::class)]
    private PathType $type;              // MEDIA | TORRENT

    #[ORM\ManyToOne(targetEntity: Volume::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Volume $volume;

    #[ORM\Column]
    private DateTimeImmutable $detectedAt;
}
```

```php
enum PathType: string
{
    case MEDIA = 'media';
    case TORRENT = 'torrent';
}
```

### 4.3 Exemple concret (setup TRaSH Synology)

```
media_files
└── id:aaa  inode:482156  size:4.89Go  hardlink_count:3  resolution:1080p

file_paths
├── media_file:aaa  path:/filmarr/media/movies/Film (2012)/Film.mkv       type:MEDIA
├── media_file:aaa  path:/filmarr/torrents/movies/Film.Scene.x265.mkv     type:TORRENT
└── media_file:aaa  path:/filmarr/links/La Cale/Film.Scene.x265.mkv       type:TORRENT
```

Cross-seed détecté via `media_file_torrents` (voir section 5).

### 4.4 Suppression hardlink-aware

Pour libérer l'espace disque, il faut `unlink()` **tous** les chemins :

```php
$paths = $mediaFile->getPaths(); // 3 chemins
$this->filesystemGateway->deleteFiles(
    array_map(fn(FilePath $p) => $p->getPath(), $paths)
);
// nlink passe de 3 à 0 → espace libéré
```

---

## 5. Modèle de données — Torrents et cross-seed

### 5.1 Table de liaison media_file ↔ torrents qBit

```php
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['media_file_id', 'qbit_hash'])]
class MediaFileTorrent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MediaFile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaFile $mediaFile;

    #[ORM\Column(type: 'string', length: 40)]
    private string $qbitHash;

    #[ORM\Column(type: 'text', nullable: true)]
    private string $trackerUrl;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $ratio;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $seedingTimeSeconds;

    #[ORM\Column(type: 'string', length: 10)]
    private string $matchedBy;            // 'inode' | 'basename'

    #[ORM\Column]
    private DateTimeImmutable $syncedAt;
}
```

### 5.2 Cross-seed

Aucune table supplémentaire. Le cross-seed est **implicite** :

```sql
-- Trouver les groupes cross-seed
SELECT media_file_id, COUNT(*) as torrent_count
FROM media_file_torrents
GROUP BY media_file_id
HAVING COUNT(*) > 1;
```

Plusieurs `MediaFileTorrent` avec le même `media_file_id` = cross-seed. Chaque ligne a son `trackerUrl`, `ratio`, `seedingTimeSeconds`.

---

## 6. Events — Découplage des side-effects

### 6.1 Principe

Quand un service termine son action principale, il dispatch un event. Les listeners gèrent les side-effects de manière découplée.

```php
// Le service dispatch
$this->eventDispatcher->dispatch(new FileDetectedEvent($mediaFile, $filePath));

// Les listeners réagissent indépendamment
class MatchMovieOnFileDetected { ... }       // tente le matching movie
class EnrichMetadataOnFileDetected { ... }   // appelle TMDB si besoin
class NotifyOnFileDetected { ... }           // notifie si configuré
```

### 6.2 Events métier

| Event | Déclenché quand | Listeners typiques |
|-------|-----------------|-------------------|
| `FileDetectedEvent` | Le watcher signale un nouveau fichier | Matching movie, enrichissement TMDB |
| `FileRemovedEvent` | Le watcher confirme la suppression physique | Cleanup DB, refresh player, notification |
| `DeletionScheduledEvent` | L'utilisateur planifie une suppression | Notification Discord rappel |
| `DeletionExecutedEvent` | La chaîne de suppression est terminée | Rapport Discord, refresh Plex/Jellyfin |
| `TorrentSyncCompletedEvent` | Sync qBittorrent terminé | Recalcul scores, détection cross-seed |
| `MovieMatchedEvent` | Un fichier est lié à un film | Update isLinked, enrichissement TMDB |

---

## 7. DTOs — Transport de données

### 7.1 Request DTOs

```php
class CreateDeletionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $mediaFileId,
        public readonly bool $deletePhysical = true,
        public readonly bool $deleteRadarrReference = true,
        public readonly bool $disableAutoSearch = false,
        public readonly ?DateTimeImmutable $scheduledAt = null,
    ) {}
}
```

Utilisé avec `#[MapRequestPayload]` dans le controller — validation automatique, pas de `$request->get()`.

### 7.2 Response DTOs

```php
class MediaFileResponse
{
    public function __construct(
        public readonly string $id,
        public readonly int $inodeNumber,
        public readonly int $fileSizeBytes,
        public readonly int $hardlinkCount,
        public readonly ?string $resolution,
        public readonly ?string $codec,
        /** @var FilePathResponse[] */
        public readonly array $paths,
        /** @var TorrentInfoResponse[] */
        public readonly array $torrents,
    ) {}

    public static function fromEntity(MediaFile $entity): self { ... }
}
```

Jamais de `return $this->json($entity)` — toujours passer par un DTO de réponse. Contrôle explicite de ce qui est exposé.

---

## 8. Value Objects

Pour les concepts métier qui ne sont pas de simples strings :

```php
final readonly class InodeNumber
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('Inode must be positive');
        }
    }
}

final readonly class QbitHash
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $value)) {
            throw new \InvalidArgumentException('Invalid qBit hash');
        }
    }
}
```

Empêche de passer un `int` quelconque là où on attend un inode, ou un `string` quelconque là où on attend un hash.

---

## 9. Watcher (Go) — Protocole WebSocket

### 9.1 Principe

Le watcher est un binaire Go natif sur le serveur hôte. C'est le **seul** composant avec accès au filesystem. L'API communique avec lui par WebSocket.

### 9.2 Commands API → Watcher

| Command | Payload | Réponse |
|---------|---------|---------|
| `scan_directory` | `{path: string}` | `DetectedFile[]` |
| `resolve_inodes` | `{paths: string[]}` | `{path → InodeInfo}` |
| `delete_files` | `{paths: string[], cleanup_empty_dirs: bool}` | `DeletionResult` |
| `watch_path` | `{path: string, type: "media"\|"torrent"}` | `{status: "ok"}` |

### 9.3 Events Watcher → API

| Event | Payload | Description |
|-------|---------|-------------|
| `file.detected` | `{path, inode, size, nlink, type}` | Nouveau fichier détecté par fsnotify |
| `file.removed` | `{path, inode}` | Fichier supprimé détecté |
| `file.modified` | `{path, inode, size, nlink}` | Fichier modifié (taille, nlink changé) |
| `scan.completed` | `{path, file_count, duration_ms}` | Scan terminé |
| `delete.progress` | `{total, completed, current_path}` | Progression suppression |
| `delete.completed` | `{deleted_count, errors, freed_bytes}` | Suppression terminée |

### 9.4 Structure Go

```
watcher/
├── cmd/
│   └── scanarr-watcher/
│       └── main.go
├── internal/
│   ├── config/          // Configuration YAML, watch_paths
│   ├── scanner/         // Scan de répertoires, stat(), inode
│   ├── watcher/         // fsnotify, événements filesystem
│   ├── websocket/       // Connexion WS vers l'API, reconnexion auto
│   ├── commands/        // Handlers pour les commands API → Watcher
│   └── models/          // Structs partagées
└── go.mod
```

---

## 10. Architecture des Services (API)

### 10.1 Arborescence cible

```
api/src/
├── Controller/
│   ├── FileController.php
│   ├── MovieController.php
│   ├── DeletionController.php
│   ├── SettingsController.php
│   ├── SyncController.php
│   └── AuthController.php
├── Service/
│   ├── Deletion/
│   │   └── DeletionOrchestrator.php
│   ├── Matching/
│   │   ├── FileMatchingService.php        // orchestrateur
│   │   ├── Strategy/
│   │   │   ├── InodeMatchingStrategy.php
│   │   │   ├── BasenameMatchingStrategy.php
│   │   │   ├── MediaManagerApiStrategy.php
│   │   │   └── FileNameParserStrategy.php
│   │   └── FileNameParser.php
│   ├── Sync/
│   │   ├── TorrentSyncService.php
│   │   └── MediaManagerSyncService.php
│   └── Gateway/
│       └── WatcherGateway.php             // implémente FilesystemGatewayInterface
├── Client/
│   ├── TorrentClient/
│   │   ├── TorrentClientInterface.php
│   │   └── QBittorrentClient.php
│   ├── MediaManager/
│   │   ├── MediaManagerInterface.php
│   │   └── RadarrClient.php
│   ├── MediaPlayer/
│   │   ├── MediaPlayerInterface.php
│   │   ├── PlexClient.php
│   │   └── JellyfinClient.php
│   ├── Notification/
│   │   ├── NotificationChannelInterface.php
│   │   └── DiscordWebhookChannel.php
│   └── Metadata/
│       ├── MetadataProviderInterface.php
│       └── TmdbProvider.php
├── Entity/
│   ├── MediaFile.php
│   ├── FilePath.php
│   ├── Movie.php
│   ├── MovieFile.php                      // liaison Movie ↔ MediaFile
│   ├── MediaFileTorrent.php               // liaison MediaFile ↔ torrent qBit
│   ├── Volume.php
│   ├── ScheduledDeletion.php
│   ├── ScheduledDeletionItem.php
│   ├── RadarrInstance.php
│   ├── Setting.php
│   └── User.php
├── Repository/
│   ├── MediaFileRepository.php
│   ├── FilePathRepository.php
│   ├── MovieRepository.php
│   └── ...
├── DTO/
│   ├── Request/
│   │   ├── CreateDeletionRequest.php
│   │   ├── SyncTorrentsRequest.php
│   │   └── ...
│   ├── Response/
│   │   ├── MediaFileResponse.php
│   │   ├── MovieResponse.php
│   │   └── ...
│   └── Internal/
│       ├── MatchResult.php
│       ├── ConnectionResult.php
│       ├── InodeInfo.php
│       ├── DeletionResult.php
│       └── ...
├── ValueObject/
│   ├── InodeNumber.php
│   └── QbitHash.php
├── Enum/
│   ├── PathType.php                       // MEDIA | TORRENT
│   ├── DeletionStatus.php
│   ├── VolumeStatus.php
│   ├── VolumeType.php
│   └── MediaManagerType.php               // RADARR | SONARR | ...
├── Event/
│   ├── FileDetectedEvent.php
│   ├── FileRemovedEvent.php
│   ├── DeletionScheduledEvent.php
│   ├── DeletionExecutedEvent.php
│   ├── TorrentSyncCompletedEvent.php
│   └── MovieMatchedEvent.php
├── EventListener/
│   ├── MatchMovieOnFileDetected.php
│   ├── EnrichMetadataOnMovieMatched.php
│   ├── RefreshPlayerOnDeletionExecuted.php
│   └── NotifyOnDeletionExecuted.php
└── WebSocket/
    └── WatcherMessageProcessor.php        // reçoit les events du watcher
```

---

## 11. Flow complet — Exemples

### 11.1 Sync qBittorrent (détection cross-seed)

```
1. SyncController::syncTorrents()
2.   → TorrentSyncService::sync()
3.       → TorrentClientInterface::getAllTorrents()         // récupère 998 torrents
4.       → Extraire les content_paths uniques
5.       → FilesystemGatewayInterface::resolveInodes(paths) // watcher stat() → inodes
6.       → Pour chaque torrent :
7.           MediaFileRepository::findByInode(inode)
8.           Si trouvé → créer/update MediaFileTorrent
9.           Si pas trouvé → log unmatched
10.      → dispatch TorrentSyncCompletedEvent
```

### 11.2 Suppression hardlink-aware

```
1. DeletionController::create()
2.   → DeletionOrchestrator::executeDeletion(request)
3.       → MediaManagerInterface::deleteMedia(id)           // dereference Radarr
4.       → TorrentClientInterface::deleteTorrent(hash)      // pour chaque MediaFileTorrent
5.       → Collecter TOUS les paths : mediaFile->getPaths()
6.       → FilesystemGatewayInterface::deleteFiles(paths)   // watcher unlink tout
7.       → dispatch DeletionExecutedEvent
8.           → RefreshPlayerOnDeletionExecuted               // Plex/Jellyfin refresh
9.           → NotifyOnDeletionExecuted                      // Discord rapport
```

### 11.3 Fichier détecté par le watcher

```
1. Watcher fsnotify → file.detected {path, inode, size, nlink, type}
2. WatcherMessageProcessor reçoit l'event
3.   → MediaFileRepository::findByInode(inode)
4.       Si existe → ajouter FilePath (nouveau chemin pour même inode)
5.       Si n'existe pas → créer MediaFile + FilePath
6.   → dispatch FileDetectedEvent
7.       → MatchMovieOnFileDetected                          // cascade de matching
8.       → EnrichMetadataOnMovieMatched                      // TMDB si match trouvé
```

---

## 12. Conventions de nommage

### PHP / Symfony

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Classe | PascalCase | `DeletionOrchestrator` |
| Méthode | camelCase | `scheduleDeletion()` |
| Variable | camelCase | `$mediaFile` |
| Constante | UPPER_SNAKE | `MAX_RETRY_COUNT` |
| Interface | PascalCase + Interface | `TorrentClientInterface` |
| Enum | PascalCase | `PathType::MEDIA` |
| DTO | PascalCase + suffixe | `CreateDeletionRequest`, `MediaFileResponse` |
| Event | PascalCase + Event | `FileDetectedEvent` |
| Listener | Action + On + Event | `MatchMovieOnFileDetected` |

### Go

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Package | lowercase | `scanner`, `websocket` |
| Struct exportée | PascalCase | `DetectedFile` |
| Méthode exportée | PascalCase | `ResolveInodes()` |
| Variable locale | camelCase | `filePath` |
| Constante | PascalCase ou UPPER_SNAKE | `MaxRetryCount` |

### TypeScript / Vue.js

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Type/Interface | PascalCase | `MediaFile`, `FilePath` |
| Composant | PascalCase | `MovieDetail.vue` |
| Composable | camelCase + use | `useWatcherSocket` |
| Store | camelCase | `useMediaStore` |
| Variable | camelCase | `mediaFile` |

### Base de données

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Table | snake_case pluriel | `media_files`, `file_paths` |
| Colonne | snake_case | `inode_number`, `hardlink_count` |
| Index | `idx_{table}_{columns}` | `idx_file_paths_media_file_id` |
| FK | `fk_{table}_{ref}` | `fk_file_paths_media_file` |
| Unique | `uniq_{table}_{columns}` | `uniq_media_files_inode` |

---

## 13. Règles de tests

### PHP

- Tests unitaires pour chaque service (`tests/Unit/Service/`)
- Tests d'intégration pour les repositories (`tests/Integration/Repository/`)
- Tests fonctionnels pour les controllers (`tests/Functional/Controller/`)
- Mock des interfaces dans les tests unitaires — jamais de mock de classes concrètes

### Go

- Tests dans des fichiers `_test.go` adjacents
- Table-driven tests pour les cas multiples
- Mock des interfaces via struct embedding

### TypeScript

- Tests unitaires pour les stores et composables (Vitest)
- Tests composants pour les pages (Vitest + Vue Test Utils)

---

## 14. Résumé visuel

```
                    ┌────────────────┐
                    │  Vue.js SPA    │
                    └───────┬────────┘
                            │ HTTP/JSON
                    ┌───────▼────────┐
                    │  Controllers   │  ← valide, délègue, répond
                    └───────┬────────┘
                            │
                    ┌───────▼────────┐
                    │   Services     │  ← logique métier
                    │   (SOLID)      │
                    └──┬────┬────┬───┘
                       │    │    │
            ┌──────────┘    │    └──────────┐
            ▼               ▼               ▼
    ┌──────────────┐ ┌────────────┐ ┌──────────────────┐
    │ Repositories │ │  Clients   │ │ FilesystemGateway │
    │ (PostgreSQL) │ │ (Interfaces│ │   (WebSocket)     │
    └──────────────┘ │  SOLID)    │ └────────┬─────────┘
                     └────────────┘          │
                     │ qBit, Radarr│          │
                     │ Plex, TMDB  │  ┌──────▼──────┐
                     │ Discord     │  │   Watcher   │
                     └─────────────┘  │ (Go, host)  │
                                      │ seul accès  │
                                      │ filesystem  │
                                      └─────────────┘
```
