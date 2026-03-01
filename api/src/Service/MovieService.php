<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Request\DeleteMovieRequest;
use App\Dto\Response\DeletionResultDto;
use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\TorrentStat;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\Enum\TorrentStatus;
use App\Repository\MovieFileRepository;
use App\Repository\MovieRepository;
use App\Repository\TorrentStatRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
final readonly class MovieService
{
    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private EntityManagerInterface $em,
        private MovieRepository $movieRepository,
        private MovieFileRepository $movieFileRepository,
        private TorrentStatRepository $torrentStatRepository,
        private DeletionService $deletionService,
        private HardlinkReplacementService $hardlinkReplacementService,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * List movies with search, filters, sort, pagination.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $result = $this->movieRepository->findWithFilters($filters);

        return [
            'data' => array_map($this->serializeForList(...), $result['data']),
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'limit' => $result['limit'],
                'total_pages' => $result['total_pages'],
            ],
        ];
    }

    /**
     * Get full movie detail. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $id): ?array
    {
        $movie = $this->movieRepository->find($id);
        if (!$movie instanceof Movie) {
            return null;
        }

        return $this->serializeDetail($movie);
    }

    /**
     * Toggle movie protection. Returns serialized summary or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function protect(string $id, bool $isProtected): ?array
    {
        $movie = $this->movieRepository->find($id);
        if (!$movie instanceof Movie) {
            return null;
        }

        $movie->setIsProtected($isProtected);
        $this->em->flush();

        return ['id' => (string)$movie->getId(), 'is_protected' => $movie->isProtected()];
    }

    /**
     * Trigger Radarr sync in background.
     */
    public function sync(): void
    {
        $consolePath = $this->projectDir . '/bin/console';
        $logPath = $this->projectDir . '/var/log/sync-radarr.log';
        $command = sprintf(
            'nohup php %s scanarr:sync-radarr --no-interaction >> %s 2>&1 &',
            escapeshellarg($consolePath),
            escapeshellarg($logPath),
        );

        exec($command);
    }

    /**
     * Execute movie deletion — standard or hardlink flow.
     */
    public function delete(string $movieId, DeleteMovieRequest $dto, User $user): DeletionResultDto
    {
        $movie = $this->em->find(Movie::class, $movieId);
        if ($movie === null) {
            return new DeletionResultDto('', 'not_found', 0);
        }

        $validationError = $this->validateDeleteRequest($movie, $dto);
        if ($validationError instanceof DeletionResultDto) {
            return $validationError;
        }

        $deletion = $this->createDeletion($movie, $dto, $user);
        $this->em->persist($deletion);
        $this->em->flush();

        $this->executeDeletionFlow($deletion, $movie, $dto);
        $this->em->flush();

        $warning = $this->buildWarning($movie, $dto);
        $this->logActivity($movie, $deletion, $dto, $user);

        return DeletionResultDto::fromDeletion($deletion, count($dto->fileIds), $warning);
    }

    private function validateDeleteRequest(Movie $movie, DeleteMovieRequest $dto): ?DeletionResultDto
    {
        if ($dto->fileIds !== []) {
            $invalidIds = $this->validateFileIds($movie, $dto->fileIds);
            if ($invalidIds !== null) {
                return new DeletionResultDto('', 'invalid_files', 0, 'Some file_ids do not belong to this movie', null, $invalidIds);
            }
        }

        if ($dto->replacementMap !== []) {
            $replacementError = $this->validateReplacementMap($movie, $dto->replacementMap);
            if ($replacementError !== null) {
                return new DeletionResultDto('', 'invalid_replacement_map', 0, $replacementError);
            }
        }

        return null;
    }

    private function executeDeletionFlow(ScheduledDeletion $deletion, Movie $movie, DeleteMovieRequest $dto): void
    {
        if ($dto->replacementMap === []) {
            $this->deletionService->executeDeletion($deletion);

            return;
        }

        $sent = $this->processReplacementMap($deletion, $movie, $dto->replacementMap);
        if (!$sent) {
            $deletion->setStatus(DeletionStatus::WAITING_WATCHER);
            $this->em->flush();
        }
    }

    /** @return array<string, mixed> */
    private function serializeForList(Movie $movie): array
    {
        $filesData = $this->buildFilesSummaryAndStats($movie);

        return array_merge(
            $this->serializeMovieBase($movie),
            $filesData,
        );
    }

    /** @return array<string, mixed> */
    private function serializeMovieBase(Movie $movie): array
    {
        return [
            'id' => (string)$movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'is_monitored_radarr' => $movie->isRadarrMonitored() ?? false,
            'is_protected' => $movie->isProtected(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildFilesSummaryAndStats(Movie $movie): array
    {
        [$filesSummary, $torrentAgg, $fileSeedingStatuses] = $this->collectFilesData($movie);

        return [
            'file_count' => count($filesSummary),
            'max_file_size_bytes' => $torrentAgg['max_file_size'],
            'files_summary' => $filesSummary,
            'multi_file_badge' => count($filesSummary) > 1,
            'best_ratio' => $torrentAgg['best_ratio'],
            'worst_ratio' => $torrentAgg['worst_ratio'],
            'total_seed_time_max_seconds' => $torrentAgg['max_seed_time'],
            'seeding_status' => $this->resolveOverallSeedingStatus($fileSeedingStatuses),
            'cross_seed_count' => $torrentAgg['cross_seed_count'],
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: list<string>} */
    private function collectFilesData(Movie $movie): array
    {
        $filesSummary = [];
        $torrentAgg = $this->createEmptyTorrentAggregation();
        $fileSeedingStatuses = [];

        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $filesSummary[] = $this->serializeFileSummary($mediaFile);
            $this->updateMaxFileSize($torrentAgg, $mediaFile->getFileSizeBytes());
            $stats = $this->torrentStatRepository->findByMediaFile($mediaFile);
            $this->aggregateTorrentStats($torrentAgg, $stats);
            $fileSeedingStatuses[] = $this->resolveFileSeedingStatus($stats);
        }

        return [$filesSummary, $torrentAgg, $fileSeedingStatuses];
    }

    /** @return array<string, mixed> */
    private function serializeFileSummary(MediaFile $mediaFile): array
    {
        return [
            'id' => (string)$mediaFile->getId(),
            'file_name' => $mediaFile->getFileName(),
            'file_size_bytes' => $mediaFile->getFileSizeBytes(),
            'resolution' => $mediaFile->getResolution(),
            'volume_name' => $mediaFile->getVolume()?->getName(),
        ];
    }

    /** @return array{max_file_size: int, best_ratio: float|null, worst_ratio: float|null, max_seed_time: int|null, cross_seed_count: int} */
    private function createEmptyTorrentAggregation(): array
    {
        return [
            'max_file_size' => 0,
            'best_ratio' => null,
            'worst_ratio' => null,
            'max_seed_time' => null,
            'cross_seed_count' => 0,
        ];
    }

    /** @param array{max_file_size: int, best_ratio: float|null, worst_ratio: float|null, max_seed_time: int|null, cross_seed_count: int} $agg */
    private function updateMaxFileSize(array &$agg, int $size): void
    {
        if ($size > $agg['max_file_size']) {
            $agg['max_file_size'] = $size;
        }
    }

    /**
     * @param array{max_file_size: int, best_ratio: float|null, worst_ratio: float|null, max_seed_time: int|null, cross_seed_count: int} $agg
     * @param TorrentStat[] $stats
     */
    private function aggregateTorrentStats(array &$agg, array $stats): void
    {
        $agg['cross_seed_count'] += count($stats);

        foreach ($stats as $stat) {
            $ratio = (float)$stat->getRatio();
            if ($agg['best_ratio'] === null || $ratio > $agg['best_ratio']) {
                $agg['best_ratio'] = $ratio;
            }
            if ($agg['worst_ratio'] === null || $ratio < $agg['worst_ratio']) {
                $agg['worst_ratio'] = $ratio;
            }
            $seedTime = $stat->getSeedTimeSeconds();
            if ($agg['max_seed_time'] === null || $seedTime > $agg['max_seed_time']) {
                $agg['max_seed_time'] = $seedTime;
            }
        }
    }

    /** @param TorrentStat[] $stats */
    private function resolveFileSeedingStatus(array $stats): string
    {
        if ($stats === []) {
            return 'orphan';
        }

        foreach ($stats as $stat) {
            if (in_array($stat->getStatus(), [TorrentStatus::SEEDING, TorrentStatus::STALLED], true)) {
                return 'seeding';
            }
        }

        return 'inactive';
    }

    /** @param string[] $statuses */
    private function resolveOverallSeedingStatus(array $statuses): string
    {
        $unique = array_unique($statuses);

        if ($unique === []) {
            return 'orphan';
        }

        if (count($unique) === 1) {
            return $unique[0];
        }

        return 'mixed';
    }

    /** @return array<string, mixed> */
    private function serializeDetail(Movie $movie): array
    {
        $files = $this->serializeDetailFiles($movie);
        $radarrInstance = $movie->getRadarrInstance();

        return [
            'id' => (string)$movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'backdrop_url' => $movie->getBackdropUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float)$movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'is_protected' => $movie->isProtected(),
            'radarr_instance' => $radarrInstance instanceof RadarrInstance ? [
                'id' => (string)$radarrInstance->getId(),
                'name' => $radarrInstance->getName(),
            ] : null,
            'radarr_monitored' => $movie->isRadarrMonitored() ?? false,
            'files' => $files,
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @return list<array<string, mixed>>
     */
    private function serializeDetailFiles(Movie $movie): array
    {
        $files = [];
        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $torrentStats = $this->torrentStatRepository->findByMediaFile($mediaFile);
            $files[] = [
                'id' => (string)$mediaFile->getId(),
                'volume_id' => (string)$mediaFile->getVolume()?->getId(),
                'volume_name' => $mediaFile->getVolume()?->getName(),
                'file_path' => $mediaFile->getFilePath(),
                'file_name' => $mediaFile->getFileName(),
                'file_size_bytes' => $mediaFile->getFileSizeBytes(),
                'hardlink_count' => $mediaFile->getHardlinkCount(),
                'inode' => $mediaFile->getInode(),
                'device_id' => $mediaFile->getDeviceId(),
                'resolution' => $mediaFile->getResolution(),
                'codec' => $mediaFile->getCodec(),
                'quality' => $mediaFile->getQuality(),
                'is_linked_radarr' => $mediaFile->isLinkedRadarr(),
                'is_linked_media_player' => $mediaFile->isLinkedMediaPlayer(),
                'is_protected' => $mediaFile->isProtected(),
                'matched_by' => $mf->getMatchedBy(),
                'confidence' => $mf->getConfidence() !== null ? (float)$mf->getConfidence() : null,
                'cross_seed_count' => count($torrentStats),
                'torrents' => array_map($this->serializeTorrentStat(...), $torrentStats),
            ];
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function serializeTorrentStat(TorrentStat $stat): array
    {
        return [
            'torrent_hash' => $stat->getTorrentHash(),
            'torrent_name' => $stat->getTorrentName(),
            'tracker_domain' => $stat->getTrackerDomain(),
            'ratio' => (float)$stat->getRatio(),
            'seed_time_seconds' => $stat->getSeedTimeSeconds(),
            'uploaded_bytes' => $stat->getUploadedBytes(),
            'downloaded_bytes' => $stat->getDownloadedBytes(),
            'size_bytes' => $stat->getSizeBytes(),
            'status' => $stat->getStatus()->value,
            'added_at' => $stat->getAddedAt()?->format('c'),
            'last_activity_at' => $stat->getLastActivityAt()?->format('c'),
        ];
    }

    /** @param string[] $fileIds */
    /**
     * Returns array of invalid IDs, or null if all IDs are valid.
     *
     * @param string[] $fileIds
     *
     * @return string[]|null
     */
    private function validateFileIds(Movie $movie, array $fileIds): ?array
    {
        $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
        $validFileIds = [];
        foreach ($movieFiles as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile !== null) {
                $validFileIds[] = (string)$mediaFile->getId();
            }
        }

        $invalidIds = array_values(array_diff($fileIds, $validFileIds));

        return $invalidIds !== [] ? $invalidIds : null;
    }

    /**
     * Validates that all old files in the replacement map are media player files.
     *
     * @param array<string, string> $replacementMap
     */
    private function validateReplacementMap(Movie $movie, array $replacementMap): ?string
    {
        $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
        $fileById = [];
        foreach ($movieFiles as $mf) {
            $mf2 = $mf->getMediaFile();
            if ($mf2 !== null) {
                $fileById[(string)$mf2->getId()] = $mf2;
            }
        }

        foreach (array_keys($replacementMap) as $oldFileId) {
            $oldFile = $fileById[$oldFileId] ?? null;
            if ($oldFile !== null && !$oldFile->isLinkedMediaPlayer()) {
                return "File {$oldFileId} is not a media player file";
            }
        }

        return null;
    }

    private function createDeletion(Movie $movie, DeleteMovieRequest $dto, User $user): ScheduledDeletion
    {
        $deletion = new ScheduledDeletion();
        $deletion->setCreatedBy($user);
        $deletion->setScheduledDate(new DateTime('today'));
        $deletion->setDeletePhysicalFiles($dto->fileIds !== []);
        $deletion->setDeleteRadarrReference($dto->deleteRadarrReference);
        $deletion->setDeleteMediaPlayerReference($dto->deleteMediaPlayerReference);
        $deletion->setDisableRadarrAutoSearch($dto->disableRadarrAutoSearch);

        $item = new ScheduledDeletionItem();
        $item->setMovie($movie);
        $item->setMediaFileIds($dto->fileIds);
        $deletion->addItem($item);

        return $deletion;
    }

    /** @param array<string, string> $replacementMap */
    private function processReplacementMap(ScheduledDeletion $deletion, Movie $movie, array $replacementMap): bool
    {
        $fileById = $this->buildFileByIdIndex($movie);

        foreach ($replacementMap as $oldFileId => $newFileId) {
            $oldFile = $fileById[$oldFileId] ?? null;
            $newFile = $fileById[$newFileId] ?? null;

            if ($oldFile === null || $newFile === null || !$oldFile->isLinkedMediaPlayer()) {
                return false;
            }

            $sent = $this->hardlinkReplacementService->requestReplacement(
                (string)$deletion->getId(),
                $oldFile,
                $newFile,
            );

            if (!$sent) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, MediaFile>
     */
    private function buildFileByIdIndex(Movie $movie): array
    {
        $fileById = [];
        foreach ($this->movieFileRepository->findBy(['movie' => $movie]) as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile !== null) {
                $fileById[(string)$mediaFile->getId()] = $mediaFile;
            }
        }

        return $fileById;
    }

    private function buildWarning(Movie $movie, DeleteMovieRequest $dto): ?string
    {
        if (!$dto->disableRadarrAutoSearch && !$dto->deleteRadarrReference
            && $movie->isRadarrMonitored() && $movie->getRadarrInstance() instanceof RadarrInstance) {
            return 'Radarr auto-search is still enabled for this movie. It may be re-downloaded.';
        }

        return null;
    }

    private function logActivity(Movie $movie, ScheduledDeletion $deletion, DeleteMovieRequest $dto, User $user): void
    {
        $log = new ActivityLog();
        $log->setAction('movie.deleted');
        $log->setEntityType('movie');
        $log->setEntityId($movie->getId());
        $log->setDetails([
            'title' => $movie->getTitle(),
            'deletion_id' => (string)$deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'radarr_dereferenced' => $dto->deleteRadarrReference,
            'files_count' => count($dto->fileIds),
        ]);
        $log->setUser($user);
        $this->em->persist($log);
        $this->em->flush();
    }
}
