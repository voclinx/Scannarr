<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Request\DeleteMovieRequest;
use App\Dto\Response\DeletionResultDto;
use App\Entity\ActivityLog;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MovieService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovieRepository $movieRepository,
        private readonly MovieFileRepository $movieFileRepository,
        private readonly TorrentStatRepository $torrentStatRepository,
        private readonly DeletionService $deletionService,
        private readonly HardlinkReplacementService $hardlinkReplacementService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

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

        return ['id' => (string) $movie->getId(), 'is_protected' => $movie->isProtected()];
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

        $deletion = $this->createDeletion($movie, $dto, $user);
        $this->em->persist($deletion);
        $this->em->flush();

        if ($dto->replacementMap !== []) {
            $sent = $this->processReplacementMap($deletion, $movie, $dto->replacementMap);
            if (!$sent) {
                $deletion->setStatus(DeletionStatus::WAITING_WATCHER);
                $this->em->flush();
            }
        } else {
            $this->deletionService->executeDeletion($deletion);
        }

        $this->em->flush();

        $warning = $this->buildWarning($movie, $dto);
        $this->logActivity($movie, $deletion, $dto, $user);

        return DeletionResultDto::fromDeletion($deletion, count($dto->fileIds), $warning);
    }

    /** @return array<string, mixed> */
    private function serializeForList(Movie $movie): array
    {
        $movieFiles = $movie->getMovieFiles();
        $filesSummary = [];
        $maxFileSize = 0;
        $bestRatio = null;
        $worstRatio = null;
        $maxSeedTime = null;
        $crossSeedCount = 0;
        $fileSeedingStatuses = [];

        foreach ($movieFiles as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $size = $mediaFile->getFileSizeBytes();
            if ($size > $maxFileSize) {
                $maxFileSize = $size;
            }

            $filesSummary[] = [
                'id' => (string) $mediaFile->getId(),
                'file_name' => $mediaFile->getFileName(),
                'file_size_bytes' => $size,
                'resolution' => $mediaFile->getResolution(),
                'volume_name' => $mediaFile->getVolume()?->getName(),
            ];

            $stats = $this->torrentStatRepository->findByMediaFile($mediaFile);
            $crossSeedCount += count($stats);
            $hasActive = false;

            foreach ($stats as $stat) {
                $ratio = (float) $stat->getRatio();
                if ($bestRatio === null || $ratio > $bestRatio) {
                    $bestRatio = $ratio;
                }
                if ($worstRatio === null || $ratio < $worstRatio) {
                    $worstRatio = $ratio;
                }
                $seedTime = $stat->getSeedTimeSeconds();
                if ($maxSeedTime === null || $seedTime > $maxSeedTime) {
                    $maxSeedTime = $seedTime;
                }
                if (in_array($stat->getStatus(), [TorrentStatus::SEEDING, TorrentStatus::STALLED], true)) {
                    $hasActive = true;
                }
            }

            $fileSeedingStatuses[] = $stats === [] ? 'orphan' : ($hasActive ? 'seeding' : 'inactive');
        }

        $uniqueStatuses = array_unique($fileSeedingStatuses);
        if ($uniqueStatuses === []) {
            $seedingStatus = 'orphan';
        } elseif (count($uniqueStatuses) === 1) {
            $seedingStatus = $uniqueStatuses[0];
        } else {
            $seedingStatus = 'mixed';
        }

        return [
            'id' => (string) $movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float) $movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'file_count' => count($filesSummary),
            'max_file_size_bytes' => $maxFileSize,
            'files_summary' => $filesSummary,
            'is_monitored_radarr' => $movie->isRadarrMonitored() ?? false,
            'is_protected' => $movie->isProtected(),
            'multi_file_badge' => count($filesSummary) > 1,
            'best_ratio' => $bestRatio,
            'worst_ratio' => $worstRatio,
            'total_seed_time_max_seconds' => $maxSeedTime,
            'seeding_status' => $seedingStatus,
            'cross_seed_count' => $crossSeedCount,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(Movie $movie): array
    {
        $files = [];

        foreach ($movie->getMovieFiles() as $mf) {
            $mediaFile = $mf->getMediaFile();
            if ($mediaFile === null) {
                continue;
            }

            $torrentStats = $this->torrentStatRepository->findByMediaFile($mediaFile);

            $files[] = [
                'id' => (string) $mediaFile->getId(),
                'volume_id' => (string) $mediaFile->getVolume()?->getId(),
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
                'confidence' => $mf->getConfidence() !== null ? (float) $mf->getConfidence() : null,
                'cross_seed_count' => count($torrentStats),
                'torrents' => array_map($this->serializeTorrentStat(...), $torrentStats),
            ];
        }

        $radarrInstance = $movie->getRadarrInstance();

        return [
            'id' => (string) $movie->getId(),
            'tmdb_id' => $movie->getTmdbId(),
            'title' => $movie->getTitle(),
            'original_title' => $movie->getOriginalTitle(),
            'year' => $movie->getYear(),
            'synopsis' => $movie->getSynopsis(),
            'poster_url' => $movie->getPosterUrl(),
            'backdrop_url' => $movie->getBackdropUrl(),
            'genres' => $movie->getGenres(),
            'rating' => $movie->getRating() !== null ? (float) $movie->getRating() : null,
            'runtime_minutes' => $movie->getRuntimeMinutes(),
            'is_protected' => $movie->isProtected(),
            'radarr_instance' => $radarrInstance instanceof RadarrInstance ? [
                'id' => (string) $radarrInstance->getId(),
                'name' => $radarrInstance->getName(),
            ] : null,
            'radarr_monitored' => $movie->isRadarrMonitored() ?? false,
            'files' => $files,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTorrentStat(TorrentStat $stat): array
    {
        return [
            'torrent_hash' => $stat->getTorrentHash(),
            'torrent_name' => $stat->getTorrentName(),
            'tracker_domain' => $stat->getTrackerDomain(),
            'ratio' => (float) $stat->getRatio(),
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
                $validFileIds[] = (string) $mediaFile->getId();
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
                $fileById[(string) $mf2->getId()] = $mf2;
            }
        }

        foreach ($replacementMap as $oldFileId => $newFileId) {
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
        $movieFiles = $this->movieFileRepository->findBy(['movie' => $movie]);
        $fileById = [];
        foreach ($movieFiles as $mf) {
            $mf2 = $mf->getMediaFile();
            if ($mf2 !== null) {
                $fileById[(string) $mf2->getId()] = $mf2;
            }
        }

        foreach ($replacementMap as $oldFileId => $newFileId) {
            $oldFile = $fileById[$oldFileId] ?? null;
            $newFile = $fileById[$newFileId] ?? null;

            if ($oldFile === null || $newFile === null || !$oldFile->isLinkedMediaPlayer()) {
                return false;
            }

            $sent = $this->hardlinkReplacementService->requestReplacement(
                (string) $deletion->getId(),
                $oldFile,
                $newFile,
            );

            if (!$sent) {
                return false;
            }
        }

        return true;
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
            'deletion_id' => (string) $deletion->getId(),
            'status' => $deletion->getStatus()->value,
            'radarr_dereferenced' => $dto->deleteRadarrReference,
            'files_count' => count($dto->fileIds),
        ]);
        $log->setUser($user);
        $this->em->persist($log);
        $this->em->flush();
    }
}
