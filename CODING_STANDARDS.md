# Scanarr — Normes de Codage

> **Prérequis** : [ARCHITECTURE.md](ARCHITECTURE.md)
> **Version** : V2.0
> **Statut** : Ce document fait AUTORITÉ sur tout le code. Aucune PR ne doit être merge si elle viole ces règles.

---

## 1. Principes SOLID

### 1.1 Single Responsibility (S)

Chaque classe a UNE seule raison de changer.

**❌ Interdit — Le God Controller :**

```php
class MovieController extends AbstractController
{
    public function delete(string $id, Request $request): JsonResponse
    {
        $movie = $this->entityManager->find(Movie::class, $id);  // ❌ Repository dans controller
        
        // ❌ 50 lignes de logique métier dans le controller
        foreach ($movie->getFiles() as $file) {
            $torrent = $this->qbitService->findTorrent($file);
            if ($torrent) {
                $this->qbitService->delete($torrent['hash']);
            }
        }
        
        $this->entityManager->remove($movie);  // ❌ EntityManager dans controller
        $this->entityManager->flush();
        
        // ❌ Notification dans le controller
        $this->discordService->send("Film supprimé : " . $movie->getTitle());
        
        return $this->json(['status' => 'ok']);
    }
}
```

**✅ Correct — Controller mince, logique dans les services :**

```php
class MovieController extends AbstractController
{
    public function __construct(
        private readonly MovieService $movieService,
    ) {}

    #[Route('/api/v1/movies/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $dto = DeleteMovieRequest::fromRequest($request);
        $result = $this->movieService->delete(Uuid::fromString($id), $dto);

        return $this->json($result, Response::HTTP_ACCEPTED);
    }
}
```

### 1.2 Open/Closed (O)

Ouvert à l'extension, fermé à la modification. On ajoute du comportement en créant de nouvelles classes, pas en modifiant celles qui existent.

**Exemple concret** — Ajouter un nouveau lecteur média (Emby) :

```php
// ❌ Modifier MediaPlayerRefreshService pour ajouter Emby
// ✅ Créer une nouvelle classe qui implémente l'interface

class EmbyMediaPlayer implements MediaPlayerInterface
{
    public function supports(MediaPlayerInstance $instance): bool
    {
        return $instance->getType() === MediaPlayerType::EMBY;
    }

    public function testConnection(MediaPlayerInstance $instance): ConnectionTestResult { ... }
    public function refreshLibrary(MediaPlayerInstance $instance): void { ... }
}
// Symfony l'auto-détecte via le tag — aucune modification ailleurs.
```

### 1.3 Liskov Substitution (L)

Toute implémentation d'une interface doit être substituable sans casser le comportement.

```php
// Le FileMatchingService fonctionne avec N'IMPORTE quelle stratégie
// sans savoir laquelle est utilisée
class FileMatchingService
{
    /** @param FileMatchingStrategyInterface[] $strategies */
    public function match(string $externalPath): ?MatchResult
    {
        foreach ($this->strategies as $strategy) {
            $result = $strategy->match($externalPath);
            if ($result !== null) return $result;
        }
        return null;
    }
}

// Chaque stratégie est interchangeable — le service s'en fiche de laquelle répond
```

### 1.4 Interface Segregation (I)

Des interfaces spécifiques plutôt qu'une grosse interface générique.

```php
// ❌ Une interface "fourre-tout"
interface ExternalServiceInterface
{
    public function testConnection(): ConnectionTestResult;
    public function syncMovies(): array;
    public function refreshLibrary(): void;
    public function deleteTorrent(string $hash): bool;
}

// ✅ Interfaces séparées par responsabilité
interface ConnectionTestableInterface
{
    public function testConnection(/* ... */): ConnectionTestResult;
}

interface MediaManagerInterface extends ConnectionTestableInterface
{
    public function getMovies(/* ... */): array;
    public function getHistory(/* ... */): array;
    public function rescanMovie(/* ... */): void;
}

interface MediaPlayerInterface extends ConnectionTestableInterface
{
    public function refreshLibrary(/* ... */): void;
}

interface TorrentClientInterface extends ConnectionTestableInterface
{
    public function getAllTorrents(): array;
    public function getTorrentFiles(string $hash): array;
    public function deleteTorrents(array $hashes, bool $deleteFiles = false): void;
}
```

### 1.5 Dependency Inversion (D)

Dépendre des abstractions (interfaces), pas des implémentations concrètes.

```php
// ❌ Dépendance concrète
class DeletionService
{
    public function __construct(
        private readonly QBittorrentService $qbit,       // ❌ Concret
        private readonly DiscordNotificationService $discord,  // ❌ Concret
        private readonly PlexService $plex,               // ❌ Concret
    ) {}
}

// ✅ Dépendance sur les interfaces
class DeletionService
{
    public function __construct(
        private readonly TorrentClientInterface $torrentClient,
        private readonly NotificationChannelInterface $notifier,
        /** @var MediaPlayerInterface[] */
        #[TaggedIterator('scanarr.media_player')]
        private readonly iterable $mediaPlayers,
    ) {}
}
```

---

## 2. Architecture en couches

### 2.1 Flux d'une requête

```
Request
  → Controller        (validation input, appel service, format output)
  → DTO Request        (données typées en entrée)
  → Service           (logique métier, orchestration)
  → Repository        (accès BDD)
  → Entity            (modèle de données)
  → DTO Response       (données typées en sortie)
  → JsonResponse
```

### 2.2 Responsabilités par couche

| Couche | Responsabilité | Interdit |
|--------|---------------|----------|
| **Controller** | Valider le Request, appeler UN service, retourner un JsonResponse | Logique métier, EntityManager, requêtes BDD, appels HTTP |
| **DTO** | Porter les données entre couches, validation via attributs Symfony | Logique, accès BDD |
| **Service** | Logique métier, orchestration, transactions | Accès direct à `Request`, retourner `Response` |
| **Repository** | Requêtes BDD, critères de recherche | Logique métier, appels HTTP |
| **Entity** | Modèle de données, getters/setters, méthodes de domaine simples | Injection de service, requêtes BDD |
| **Interface** | Contrat, abstraction | Implémentation |

### 2.3 Controller — Règles strictes

```php
#[Route('/api/v1/movies')]
class MovieController extends AbstractController
{
    public function __construct(
        private readonly MovieService $movieService,
    ) {}

    /**
     * RÈGLE 1 : Pas de logique → juste input/output
     * RÈGLE 2 : Pas d'EntityManager → jamais
     * RÈGLE 3 : Pas de Repository → jamais
     * RÈGLE 4 : UN SEUL appel de service principal
     * RÈGLE 5 : Maximum ~15 lignes par méthode
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = MovieListFilters::fromRequest($request);
        $result = $this->movieService->list($filters);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $dto = DeleteMovieRequest::fromRequest($request);
        $result = $this->movieService->delete(Uuid::fromString($id), $dto);

        return $this->json($result, Response::HTTP_ACCEPTED);
    }

    #[Route('/{id}/protect', methods: ['PUT'])]
    public function protect(string $id, Request $request): JsonResponse
    {
        $dto = ProtectMovieRequest::fromRequest($request);
        $result = $this->movieService->protect(Uuid::fromString($id), $dto);

        return $this->json($result);
    }
}
```

### 2.4 DTO — Objets de transfert

```php
// --- Request DTO ---
final readonly class DeleteMovieRequest
{
    public function __construct(
        /** @var string[] */
        public array $fileIds,
        public bool $deleteRadarrReference = false,
        public bool $disableRadarrAutoSearch = false,
        /** @var array<string, string> file_id => replacement_file_id */
        public array $replacementMap = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return new self(
            fileIds: $data['file_ids'] ?? [],
            deleteRadarrReference: $data['delete_radarr_reference'] ?? false,
            disableRadarrAutoSearch: $data['disable_radarr_auto_search'] ?? false,
            replacementMap: $data['replacement_map'] ?? [],
        );
    }
}

// --- Response DTO ---
final readonly class MovieListResponse
{
    public function __construct(
        /** @var MovieSummaryDto[] */
        public array $data,
        public PaginationMeta $meta,
    ) {}
}
```

### 2.5 Service — Logique métier

```php
class MovieService
{
    public function __construct(
        private readonly MovieRepository $movieRepository,
        private readonly DeletionService $deletionService,
        private readonly MediaFileRepository $mediaFileRepository,
    ) {}

    public function delete(Uuid $movieId, DeleteMovieRequest $dto): DeletionResultDto
    {
        $movie = $this->movieRepository->findOrFail($movieId);
        $files = $this->mediaFileRepository->findByIds($dto->fileIds);

        // Logique métier ici — PAS dans le controller
        $this->validateDeletionPermissions($movie, $files);

        return $this->deletionService->scheduleImmediate(
            movie: $movie,
            files: $files,
            options: DeletionOptions::fromRequest($dto),
        );
    }
}
```

---

## 3. Interfaces obligatoires

### 3.1 Carte des interfaces

Chaque point d'extension DOIT avoir une interface. Voici la liste exhaustive :

```
src/
├── Contract/                              # TOUTES les interfaces ici
│   ├── TorrentClient/
│   │   └── TorrentClientInterface.php     # qBittorrent, (futur: Deluge, Transmission)
│   ├── MediaManager/
│   │   └── MediaManagerInterface.php      # Radarr, (futur: Sonarr, Lidarr)
│   ├── MediaPlayer/
│   │   └── MediaPlayerInterface.php       # Plex, Jellyfin, (futur: Emby)
│   ├── Metadata/
│   │   └── MetadataProviderInterface.php  # TMDB, (futur: OMDb, TVDb)
│   ├── Notification/
│   │   └── NotificationChannelInterface.php  # Discord, (futur: Telegram, Email, Gotify)
│   ├── Matching/
│   │   └── FileMatchingStrategyInterface.php # Inode, Suffix, RadarrHash, FileNameParser
│   ├── WebSocket/
│   │   └── WatcherMessageHandlerInterface.php # Un handler par type de message
│   └── Sync/
│       └── SyncProgressEmitterInterface.php   # Pour les barres de progression
```

### 3.2 Définition des interfaces

```php
// --- TorrentClientInterface ---
interface TorrentClientInterface extends ConnectionTestableInterface
{
    public function getAllTorrents(): array;
    public function getTorrentFiles(string $hash): array;
    public function deleteTorrents(array $hashes, bool $deleteFiles = false): void;
    public function getPathPrefix(): string;  // ex: "/data/" pour le suffix matching
}

// --- MediaManagerInterface ---
interface MediaManagerInterface extends ConnectionTestableInterface
{
    public function getMovies(): array;
    public function getHistory(string $eventType, int $pageSize = 1000): array;
    public function rescanMovie(int $externalMovieId): void;
    public function deleteMovie(int $externalMovieId, bool $addExclusion = false): void;
    public function setMonitored(int $externalMovieId, bool $monitored): void;
}

// --- MediaPlayerInterface ---
interface MediaPlayerInterface extends ConnectionTestableInterface
{
    public function refreshLibrary(): void;
}

// --- MetadataProviderInterface ---
interface MetadataProviderInterface
{
    public function searchMovie(string $title, ?int $year = null): array;
    public function getMovieDetails(int $externalId): ?MovieMetadataDto;
}

// --- NotificationChannelInterface ---
interface NotificationChannelInterface extends ConnectionTestableInterface
{
    public function sendDeletionReminder(DeletionReminderDto $dto): void;
    public function sendDeletionReport(DeletionReportDto $dto): void;
    public function sendError(string $title, string $message): void;
}

// --- FileMatchingStrategyInterface ---
interface FileMatchingStrategyInterface
{
    /**
     * Tente de matcher un chemin externe (qBit, Radarr) avec un MediaFile en BDD.
     * Retourne null si cette stratégie ne peut pas matcher.
     */
    public function match(string $externalPath, array $context = []): ?MatchResult;

    /**
     * Priorité de la stratégie (plus haut = essayé en premier).
     */
    public static function getPriority(): int;
}

// --- WatcherMessageHandlerInterface ---
interface WatcherMessageHandlerInterface
{
    public function supports(string $messageType): bool;
    public function handle(array $message): void;
}

// --- ConnectionTestableInterface ---
interface ConnectionTestableInterface
{
    public function testConnection(): ConnectionTestResult;
    public function isConfigured(): bool;
}
```

### 3.3 Enregistrement Symfony (services.yaml)

```yaml
# Autoconfiguration par interface → tag Symfony
services:
    _instanceof:
        App\Contract\TorrentClient\TorrentClientInterface:
            tags: ['scanarr.torrent_client']

        App\Contract\MediaManager\MediaManagerInterface:
            tags: ['scanarr.media_manager']

        App\Contract\MediaPlayer\MediaPlayerInterface:
            tags: ['scanarr.media_player']

        App\Contract\Notification\NotificationChannelInterface:
            tags: ['scanarr.notification_channel']

        App\Contract\Matching\FileMatchingStrategyInterface:
            tags: ['scanarr.matching_strategy']

        App\Contract\WebSocket\WatcherMessageHandlerInterface:
            tags: ['scanarr.watcher_message_handler']
```

### 3.4 Injection de collections

```php
// Quand un service a besoin de TOUTES les implémentations d'une interface
class MediaPlayerRefreshService
{
    /** @param MediaPlayerInterface[] $players */
    public function __construct(
        #[TaggedIterator('scanarr.media_player')]
        private readonly iterable $players,
        private readonly MediaPlayerInstanceRepository $instanceRepository,
    ) {}

    public function refreshAll(): void
    {
        $instances = $this->instanceRepository->findAllActive();

        foreach ($instances as $instance) {
            foreach ($this->players as $player) {
                if ($player->supports($instance)) {
                    try {
                        $player->refreshLibrary($instance);
                    } catch (\Throwable $e) {
                        // Best-effort, log and continue
                    }
                }
            }
        }
    }
}
```

---

## 4. Design Patterns appliqués

### 4.1 Strategy Pattern — File Matching

Le matching entre chemins externes (qBit, Radarr) et fichiers connus utilise une chaîne de stratégies ordonnées par priorité.

```php
// Stratégie 1 — Match par inode (priorité 100)
#[AutoconfigureTag('scanarr.matching_strategy')]
class InodeMatchingStrategy implements FileMatchingStrategyInterface
{
    public function __construct(
        private readonly FilePathRepository $filePathRepository,
    ) {}

    public static function getPriority(): int { return 100; }

    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        // Si le context contient un inode (ex: message watcher), match direct
        $inode = $context['inode'] ?? null;
        $deviceId = $context['device_id'] ?? null;

        if ($inode === null || $deviceId === null) {
            return null;
        }

        $mediaFile = $this->mediaFileRepository->findByInode($deviceId, $inode);
        if ($mediaFile === null) return null;

        return new MatchResult(
            mediaFile: $mediaFile,
            strategy: 'inode',
            confidence: 1.0,
        );
    }
}

// Stratégie 2 — Match par suffixe de chemin progressif (priorité 80)
#[AutoconfigureTag('scanarr.matching_strategy')]
class SuffixMatchingStrategy implements FileMatchingStrategyInterface
{
    public function __construct(
        private readonly FilePathRepository $filePathRepository,
    ) {}

    public static function getPriority(): int { return 80; }

    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        $segments = explode('/', trim($externalPath, '/'));

        // Du plus spécifique au moins spécifique
        // JAMAIS le filename seul (dernier segment)
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $suffix = implode('/', array_slice($segments, $i));
            $candidates = $this->filePathRepository->findBySuffix($suffix);

            if (count($candidates) === 0) continue;

            // Dédupliquer par media_file_id
            $uniqueMediaFiles = [];
            foreach ($candidates as $fp) {
                $uniqueMediaFiles[$fp->getMediaFile()->getId()->toString()] = $fp->getMediaFile();
            }

            if (count($uniqueMediaFiles) === 1) {
                return new MatchResult(
                    mediaFile: reset($uniqueMediaFiles),
                    strategy: 'suffix',
                    confidence: 0.9,
                    matchedSuffix: $suffix,
                );
            }

            // 2+ media_files distincts → ambiguïté
            return MatchResult::ambiguous(array_values($uniqueMediaFiles), $suffix);
        }

        return null;
    }
}

// Stratégie 3 — Match par hash Radarr history (priorité 60)
#[AutoconfigureTag('scanarr.matching_strategy')]
class MediaManagerHashStrategy implements FileMatchingStrategyInterface
{
    public static function getPriority(): int { return 60; }

    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        $torrentHash = $context['torrent_hash'] ?? null;
        if ($torrentHash === null) return null;

        // Chercher dans l'historique Radarr
        // hash → radarrMovieId → movie Scanarr → media_files
        // ...
    }
}

// Stratégie 4 — Parse le nom de fichier + lookup TMDB (priorité 40)
#[AutoconfigureTag('scanarr.matching_strategy')]
class FileNameParserStrategy implements FileMatchingStrategyInterface
{
    public static function getPriority(): int { return 40; }

    public function match(string $externalPath, array $context = []): ?MatchResult
    {
        // Parser "Inception.2010.2160p.BluRay.x265-GROUP.mkv"
        // → title: "Inception", year: 2010
        // → chercher dans movies par titre + année
        // → si pas trouvé, lookup TMDB
    }
}

// --- L'orchestrateur ---
class FileMatchingService
{
    /** @var FileMatchingStrategyInterface[] */
    private array $sortedStrategies;

    public function __construct(
        #[TaggedIterator('scanarr.matching_strategy')]
        iterable $strategies,
    ) {
        $this->sortedStrategies = iterator_to_array($strategies);
        usort($this->sortedStrategies, fn($a, $b) => $b::getPriority() <=> $a::getPriority());
    }

    public function match(string $externalPath, array $context = []): MatchResult
    {
        foreach ($this->sortedStrategies as $strategy) {
            $result = $strategy->match($externalPath, $context);
            if ($result !== null) return $result;
        }

        return MatchResult::notFound($externalPath);
    }
}
```

### 4.2 Strategy Pattern — WebSocket Message Handlers

Chaque type de message WebSocket a son propre handler. Plus de switch/case géant.

```php
// --- Un handler par type de message ---

#[AutoconfigureTag('scanarr.watcher_message_handler')]
class ScanFileHandler implements WatcherMessageHandlerInterface
{
    public function supports(string $messageType): bool
    {
        return $messageType === 'scan.file';
    }

    public function handle(array $message): void
    {
        // Traiter scan.file : créer/MAJ media_file + file_path
    }
}

#[AutoconfigureTag('scanarr.watcher_message_handler')]
class FilesDeleteCompletedHandler implements WatcherMessageHandlerInterface
{
    public function supports(string $messageType): bool
    {
        return $messageType === 'files.delete.completed';
    }

    public function handle(array $message): void
    {
        // Finaliser la suppression : cleanup BDD, refresh players, Discord
    }
}

#[AutoconfigureTag('scanarr.watcher_message_handler')]
class HardlinkCompletedHandler implements WatcherMessageHandlerInterface
{
    public function supports(string $messageType): bool
    {
        return $messageType === 'files.hardlink.completed';
    }

    public function handle(array $message): void
    {
        // MAJ BDD, rescan Radarr, refresh players, continuer chaîne suppression
    }
}

// --- Le dispatcher (remplace WatcherMessageProcessor) ---

class WatcherMessageDispatcher
{
    /** @var WatcherMessageHandlerInterface[] */
    private array $handlers;

    public function __construct(
        #[TaggedIterator('scanarr.watcher_message_handler')]
        iterable $handlers,
    ) {
        $this->handlers = iterator_to_array($handlers);
    }

    public function dispatch(string $rawJson): void
    {
        $message = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        $type = $message['type'] ?? throw new \InvalidArgumentException('Missing message type');

        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                $handler->handle($message);
                return;
            }
        }

        // Aucun handler → log warning, ne pas crasher
        $this->logger->warning('No handler for watcher message type', ['type' => $type]);
    }
}
```

### 4.3 Registry Pattern — Services externes

Pour accéder à toutes les instances d'un type de service externe :

```php
class MediaPlayerRegistry
{
    /** @var MediaPlayerInterface[] */
    private array $adapters;

    public function __construct(
        #[TaggedIterator('scanarr.media_player')]
        iterable $adapters,
    ) {
        $this->adapters = iterator_to_array($adapters);
    }

    public function getAdapterFor(MediaPlayerInstance $instance): MediaPlayerInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($instance)) {
                return $adapter;
            }
        }
        throw new UnsupportedMediaPlayerException($instance->getType());
    }
}
```

---

## 5. Structure des dossiers (API)

```
src/
├── Command/                          # Commandes Symfony (cron, CLI)
│   ├── SyncQBittorrentCommand.php
│   ├── SyncRadarrCommand.php
│   ├── ProcessScheduledDeletionsCommand.php
│   ├── SendDeletionRemindersCommand.php
│   ├── CleanTorrentHistoryCommand.php
│   └── RunWebSocketServerCommand.php
│
├── Contract/                         # TOUTES les interfaces
│   ├── Matching/
│   │   └── FileMatchingStrategyInterface.php
│   ├── MediaManager/
│   │   └── MediaManagerInterface.php
│   ├── MediaPlayer/
│   │   └── MediaPlayerInterface.php
│   ├── Metadata/
│   │   └── MetadataProviderInterface.php
│   ├── Notification/
│   │   └── NotificationChannelInterface.php
│   ├── Sync/
│   │   └── SyncProgressEmitterInterface.php
│   ├── TorrentClient/
│   │   └── TorrentClientInterface.php
│   ├── WebSocket/
│   │   └── WatcherMessageHandlerInterface.php
│   └── ConnectionTestableInterface.php
│
├── Controller/                       # Controllers minces
│   ├── AuthController.php
│   ├── DashboardController.php
│   ├── DeletionPresetController.php
│   ├── FileController.php
│   ├── MediaPlayerController.php
│   ├── MovieController.php
│   ├── QBittorrentController.php
│   ├── RadarrController.php
│   ├── ScheduledDeletionController.php
│   ├── SettingController.php
│   ├── SuggestionController.php
│   ├── TrackerRuleController.php
│   ├── UserController.php
│   ├── VolumeController.php
│   └── WatcherController.php
│
├── Dto/                              # Objets de transfert
│   ├── Request/                      # Input DTOs (fromRequest)
│   │   ├── DeleteMovieRequest.php
│   │   ├── MovieListFilters.php
│   │   ├── CreatePresetRequest.php
│   │   ├── BatchDeleteRequest.php
│   │   └── ...
│   ├── Response/                     # Output DTOs
│   │   ├── MovieSummaryDto.php
│   │   ├── MovieDetailDto.php
│   │   ├── DeletionResultDto.php
│   │   ├── SyncProgressDto.php
│   │   └── ...
│   └── Internal/                     # DTOs internes (entre services)
│       ├── MatchResult.php
│       ├── ConnectionTestResult.php
│       ├── DeletionOptions.php
│       ├── MovieMetadataDto.php
│       └── ...
│
├── Entity/                           # Entités Doctrine
│   ├── MediaFile.php
│   ├── FilePath.php
│   ├── Movie.php
│   ├── Volume.php
│   ├── TorrentStat.php
│   ├── TorrentStatHistory.php
│   ├── DeletionPreset.php
│   ├── TrackerRule.php
│   ├── Watcher.php
│   ├── ...
│
├── Enum/                             # Enums PHP 8.1+
│   ├── UserRole.php
│   ├── VolumeStatus.php
│   ├── DeletionStatus.php
│   ├── TorrentStatus.php
│   ├── MediaPlayerType.php
│   └── ...
│
├── EventListener/
│   └── ActivityLogListener.php
│
├── ExternalService/                  # Implémentations concrètes des interfaces
│   ├── TorrentClient/
│   │   └── QBittorrentClient.php     # implements TorrentClientInterface
│   ├── MediaManager/
│   │   └── RadarrClient.php          # implements MediaManagerInterface
│   ├── MediaPlayer/
│   │   ├── PlexPlayer.php            # implements MediaPlayerInterface
│   │   └── JellyfinPlayer.php        # implements MediaPlayerInterface
│   ├── Metadata/
│   │   └── TmdbProvider.php          # implements MetadataProviderInterface
│   └── Notification/
│       └── DiscordChannel.php        # implements NotificationChannelInterface
│
├── Matching/                         # Stratégies de matching
│   ├── InodeMatchingStrategy.php
│   ├── SuffixMatchingStrategy.php
│   ├── MediaManagerHashStrategy.php
│   └── FileNameParserStrategy.php
│
├── Repository/                       # Repositories Doctrine
│   ├── MediaFileRepository.php
│   ├── FilePathRepository.php
│   ├── MovieRepository.php
│   └── ...
│
├── Security/
│   └── Voter/
│       ├── DeletionVoter.php
│       └── FileVoter.php
│
├── Service/                          # Services métier
│   ├── MovieService.php
│   ├── DeletionService.php
│   ├── FileMatchingService.php       # Orchestrateur des stratégies
│   ├── SuggestionService.php
│   ├── HardlinkReplacementService.php
│   ├── SyncService.php               # Orchestrateur des syncs
│   ├── MediaPlayerRefreshService.php  # Utilise MediaPlayerInterface[]
│   ├── NotificationService.php        # Utilise NotificationChannelInterface[]
│   └── WatcherVolumeSyncService.php
│
├── WebSocket/
│   ├── WatcherWebSocketServer.php
│   ├── WatcherMessageDispatcher.php   # Dispatch vers les handlers
│   └── Handler/                       # Un fichier par type de message
│       ├── AuthHandler.php
│       ├── ScanFileHandler.php
│       ├── ScanCompletedHandler.php
│       ├── FileCreatedHandler.php
│       ├── FileDeletedHandler.php
│       ├── FileRenamedHandler.php
│       ├── FilesDeleteCompletedHandler.php
│       ├── HardlinkCompletedHandler.php
│       └── WatcherStatusHandler.php
│
└── Kernel.php
```

---

## 6. Règles de nommage

### 6.1 PHP

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Classe | PascalCase | `MovieService`, `QBittorrentClient` |
| Interface | PascalCase + `Interface` | `TorrentClientInterface` |
| Méthode | camelCase | `findByInode()`, `scheduleImmediate()` |
| Variable | camelCase | `$mediaFile`, `$torrentHash` |
| Constante | UPPER_SNAKE | `MAX_RETRY_COUNT` |
| Enum case | PascalCase | `DeletionStatus::WaitingWatcher` |
| DTO | PascalCase + suffixe contexte | `DeleteMovieRequest`, `MovieSummaryDto` |
| Event | PascalCase + `Event` | `DeletionCompletedEvent` |
| Service tag | `scanarr.{domain}` | `scanarr.media_player` |

### 6.2 Fichiers et dossiers

| Élément | Convention |
|---------|-----------|
| Entity | Singulier (`Movie.php`, pas `Movies.php`) |
| Repository | Singulier + `Repository` (`MovieRepository.php`) |
| Controller | Singulier + `Controller` (`MovieController.php`) |
| Interface | Dans `Contract/{Domain}/` |
| Implémentation externe | Dans `ExternalService/{Domain}/` |
| Handler WebSocket | Dans `WebSocket/Handler/` |
| Migration | Doctrine auto-generated naming |

---

## 7. Gestion des erreurs

### 7.1 Exceptions métier

```php
// Exceptions dans src/Exception/

class EntityNotFoundException extends \RuntimeException
{
    public static function movie(Uuid $id): self
    {
        return new self(sprintf('Movie not found: %s', $id));
    }

    public static function mediaFile(Uuid $id): self
    {
        return new self(sprintf('MediaFile not found: %s', $id));
    }
}

class DeletionNotAllowedException extends \DomainException { }
class AmbiguousMatchException extends \RuntimeException { }
class ExternalServiceException extends \RuntimeException { }
```

### 7.2 Exception Listener global

```php
class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $response = match (true) {
            $exception instanceof EntityNotFoundException
                => new JsonResponse(['error' => $exception->getMessage()], 404),
            $exception instanceof DeletionNotAllowedException
                => new JsonResponse(['error' => $exception->getMessage()], 403),
            $exception instanceof ValidationException
                => new JsonResponse(['error' => $exception->getMessage(), 'violations' => $exception->getViolations()], 422),
            default => null,
        };

        if ($response !== null) {
            $event->setResponse($response);
        }
    }
}
```

### 7.3 Best-effort pour les services externes

```php
// Les appels aux services externes (Plex, Discord, qBit cleanup)
// ne doivent JAMAIS bloquer l'opération principale.

try {
    $this->notifier->sendDeletionReport($report);
} catch (\Throwable $e) {
    $this->logger->warning('Notification failed', [
        'channel' => $this->notifier::class,
        'error' => $e->getMessage(),
    ]);
    // Continue — ne pas faire échouer la suppression parce que Discord est down
}
```

---

## 8. Tests

### 8.1 Structure

```
tests/
├── Unit/
│   ├── Service/
│   │   ├── FileMatchingServiceTest.php
│   │   ├── DeletionServiceTest.php
│   │   └── SuggestionServiceTest.php
│   ├── Matching/
│   │   ├── InodeMatchingStrategyTest.php
│   │   ├── SuffixMatchingStrategyTest.php
│   │   └── MediaManagerHashStrategyTest.php
│   └── Dto/
│       └── MatchResultTest.php
├── Functional/
│   ├── Controller/
│   │   ├── MovieControllerTest.php
│   │   └── SuggestionControllerTest.php
│   └── WebSocket/
│       └── WatcherMessageDispatcherTest.php
└── Integration/
    └── Sync/
        └── QBittorrentSyncTest.php
```

### 8.2 Règles de test

Chaque interface DOIT avoir un mock utilisable dans les tests. Grâce aux interfaces, on peut injecter des implémentations fake :

```php
class MovieServiceTest extends TestCase
{
    public function testDeleteCallsDeletionService(): void
    {
        // Grâce à l'interface, on mocke facilement
        $torrentClient = $this->createMock(TorrentClientInterface::class);
        $torrentClient->expects($this->once())->method('deleteTorrents');

        $notifier = $this->createMock(NotificationChannelInterface::class);
        
        $service = new DeletionService($torrentClient, $notifier, /* ... */);
        // ...
    }
}
```

---

## 9. Règles diverses

### 9.1 PHP strict

```php
// OBLIGATOIRE en haut de chaque fichier PHP
declare(strict_types=1);
```

### 9.2 Typage

Tout est typé. Pas de `mixed`, pas de `@param` sans type natif PHP. Return types obligatoires.

```php
// ❌
function getMovies($filters) { ... }

// ✅
function getMovies(MovieListFilters $filters): MovieListResponse { ... }
```

### 9.3 Readonly et Final

Les DTOs sont `final readonly`. Les services sont `final` sauf besoin explicite d'héritage (rare).

```php
final readonly class MatchResult { ... }
final class MovieService { ... }
```

### 9.4 Pas de `new` dans les services

Les services ne doivent pas instancier d'autres services. Tout passe par l'injection de dépendance Symfony. L'exception : les DTOs et value objects.

### 9.5 Transactions

Les transactions sont gérées dans la couche Service, jamais dans le Controller ni dans le Repository.

```php
// Dans le service
$this->entityManager->wrapInTransaction(function () use ($movie, $files) {
    // Toutes les opérations BDD ici
});
```

### 9.6 Pas de `string` magique

Les types de messages, statuts, rôles etc. sont TOUJOURS des Enums PHP, jamais des strings en dur.

```php
// ❌
if ($message['type'] === 'scan.file') { ... }

// ✅
if (WatcherMessageType::from($message['type']) === WatcherMessageType::ScanFile) { ... }
// Ou mieux : le dispatcher s'en charge, le handler ne voit que ses messages
```

---

## 10. Checklist de validation

Avant chaque PR, vérifier :

- [ ] **Aucune logique** dans les controllers (max ~15 lignes, 1 appel service)
- [ ] **Aucun** `EntityManager`, `Repository`, `->find()` dans les controllers
- [ ] **Aucun** `Request` ni `Response` dans les services
- [ ] **Toute** dépendance externe passe par une interface
- [ ] **Tout** nouveau service externe implémente l'interface du dossier `Contract/`
- [ ] **Tout** message WebSocket a son handler dédié dans `WebSocket/Handler/`
- [ ] **Tout** type est une Enum, pas un string
- [ ] **Toute** classe a `declare(strict_types=1)`
- [ ] **Tout** est typé (paramètres, retours, propriétés)
- [ ] **Tout** nouveau service est `final`
- [ ] **Tout** DTO est `final readonly`
- [ ] **Les tests** mockent les interfaces, pas les classes concrètes
- [ ] **Best-effort** sur les services externes (try/catch, log, continue)
