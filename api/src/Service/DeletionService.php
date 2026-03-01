<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\ExternalService\MediaManager\RadarrService;
use App\ExternalService\TorrentClient\QBittorrentService;
use App\Repository\MediaFileRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DeletionService — orchestrates deletion chain.
 *
 * Phase 1 (synchronous, runs in API):
 *   1. Radarr dereference or disable auto-search (HTTP)
 *   2. qBittorrent cleanup (HTTP, best-effort)
 *   3. Send command.files.delete to watcher via WebSocket
 *
 * Phase 2 (asynchronous, runs in watcher):
 *   - Physical file deletion + empty dir cleanup
 *
 * Phase 3 (async, via WebSocket handlers):
 *   - DB cleanup, Plex/Jellyfin refresh, Discord notification
 *
 * The API NEVER does unlink(), rmdir(), or file_exists().
 */
class DeletionService
{
    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly RadarrService $radarrService,
        private readonly QBittorrentService $qBittorrentService,
        private readonly WatcherCommandService $watcherCommandService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a scheduled deletion — Phase 1 only.
     *
     * After this method returns, the deletion status will be:
     * - EXECUTING: command sent to watcher, waiting for completion
     * - WAITING_WATCHER: watcher offline, will be retried on reconnection
     * - COMPLETED: no files to delete (Radarr/qBit only)
     */
    public function executeDeletion(ScheduledDeletion $deletion): void
    {
        $deletion->setStatus(DeletionStatus::EXECUTING);
        $this->em->flush();

        $allFilesToDelete = $this->processItems($deletion);

        if ($allFilesToDelete === []) {
            $this->completeWithNoFiles($deletion);

            return;
        }

        $this->sendToWatcher($deletion, $allFilesToDelete);
        $this->em->flush();
    }

    /**
     * Process all deletion items: handle Radarr and collect files.
     *
     * @return array<int, array{media_file_id: string, volume_path: string, file_path: string}>
     */
    private function processItems(ScheduledDeletion $deletion): array
    {
        $allFilesToDelete = [];
        $seenFileIds = [];

        foreach ($deletion->getItems() as $item) {
            $this->handleRadarrForItem($deletion, $item->getMovie());

            if (!$deletion->isDeletePhysicalFiles()) {
                continue;
            }

            $this->collectFilesForItem($item, $seenFileIds, $allFilesToDelete);
        }

        return $allFilesToDelete;
    }

    private function handleRadarrForItem(ScheduledDeletion $deletion, ?Movie $movie): void
    {
        if (!$movie instanceof Movie) {
            return;
        }

        if ($deletion->isDeleteRadarrReference()) {
            $this->dereferenceFromRadarr($movie);

            return;
        }

        if ($deletion->isDisableRadarrAutoSearch()) {
            $this->disableRadarrAutoSearch($movie);
        }
    }

    /**
     * @param array<string, bool> $seenFileIds
     * @param array<int, array{media_file_id: string, volume_path: string, file_path: string}> $allFilesToDelete
     */
    private function collectFilesForItem(
        ScheduledDeletionItem $item,
        array &$seenFileIds,
        array &$allFilesToDelete,
    ): void {
        foreach ($item->getMediaFileIds() as $mediaFileId) {
            $mediaFile = $this->mediaFileRepository->find($mediaFileId);
            if ($mediaFile === null) {
                continue;
            }

            $filesToCollect = $this->collectWithSiblings($mediaFile);

            foreach ($filesToCollect as $fileToDelete) {
                $this->processFileForDeletion($fileToDelete, $seenFileIds, $allFilesToDelete);
            }
        }
    }

    /**
     * Collect a media file and its inode siblings (hardlinks on other volumes).
     *
     * @return list<MediaFile>
     */
    private function collectWithSiblings(MediaFile $mediaFile): array
    {
        $files = [$mediaFile];

        $deviceId = $mediaFile->getDeviceId();
        $inode = $mediaFile->getInode();
        if ($deviceId === null || $inode === null) {
            return $files;
        }

        foreach ($this->mediaFileRepository->findAllByInode($deviceId, $inode) as $sibling) {
            $files[] = $sibling;
        }

        return $files;
    }

    /**
     * @param array<string, bool> $seenFileIds
     * @param array<int, array{media_file_id: string, volume_path: string, file_path: string}> $allFilesToDelete
     */
    private function processFileForDeletion(
        MediaFile $fileToDelete,
        array &$seenFileIds,
        array &$allFilesToDelete,
    ): void {
        $fileId = (string)$fileToDelete->getId();
        if (isset($seenFileIds[$fileId])) {
            return;
        }
        $seenFileIds[$fileId] = true;

        $volume = $fileToDelete->getVolume();
        if (!$volume instanceof Volume) {
            return;
        }

        $this->cleanupQBittorrent($volume, $fileToDelete);

        $volumeHostPath = $volume->getHostPath();
        if ($volumeHostPath === null || $volumeHostPath === '') {
            $volumeHostPath = $volume->getPath();
        }

        $allFilesToDelete[] = [
            'media_file_id' => $fileId,
            'volume_path' => rtrim($volumeHostPath ?? '', '/'),
            'file_path' => $fileToDelete->getFilePath(),
        ];
    }

    private function cleanupQBittorrent(Volume $volume, MediaFile $fileToDelete): void
    {
        $hostPath = $volume->getHostPath();
        if ($hostPath === null || $hostPath === '' || !$this->qBittorrentService->isConfigured()) {
            return;
        }

        $absoluteHostPath = rtrim($hostPath, '/') . '/' . $fileToDelete->getFilePath();
        try {
            $this->qBittorrentService->findAndDeleteTorrent($absoluteHostPath);
        } catch (Throwable $throwable) {
            $this->logger->warning('qBittorrent cleanup failed (best-effort)', [
                'file' => $absoluteHostPath,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function completeWithNoFiles(ScheduledDeletion $deletion): void
    {
        $deletion->setStatus(DeletionStatus::COMPLETED);
        $deletion->setExecutedAt(new DateTimeImmutable());
        $deletion->setExecutionReport([
            'finished_at' => (new DateTimeImmutable())->format('c'),
            'success_count' => 0,
            'failed_count' => 0,
            'message' => 'No physical files to delete',
        ]);

        $this->logDeletionActivity($deletion, 0, 0);
        $this->em->flush();
    }

    /**
     * @param array<int, array{media_file_id: string, volume_path: string, file_path: string}> $filesToDelete
     */
    private function sendToWatcher(ScheduledDeletion $deletion, array $filesToDelete): void
    {
        $watcherReached = $this->watcherCommandService->requestFilesDelete(
            (string)$deletion->getId(),
            $filesToDelete,
        );

        if ($watcherReached) {
            return;
        }

        $deletion->setStatus(DeletionStatus::WAITING_WATCHER);
        $this->logger->info('Watcher offline, deletion queued for reconnection', [
            'deletion_id' => (string)$deletion->getId(),
            'files_count' => count($filesToDelete),
        ]);
    }

    /**
     * Dereference a movie from Radarr (remove without deleting files in Radarr).
     * addExclusion is always false — the user wants to be able to re-download later.
     *
     * @return array{success: bool, error: ?string}
     */
    public function dereferenceFromRadarr(Movie $movie): array
    {
        $radarrInstance = $movie->getRadarrInstance();
        $radarrId = $movie->getRadarrId();

        if (!$radarrInstance instanceof RadarrInstance || $radarrId === null) {
            return ['success' => true, 'error' => null]; // Nothing to dereference
        }

        try {
            $this->radarrService->deleteMovie(
                $radarrInstance,
                $radarrId,
                false, // Don't delete files via Radarr
                false,  // Don't add exclusion (allow re-download)
            );

            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            $this->logger->error('Failed to dereference from Radarr', [
                'movie' => $movie->getTitle(),
                'radarr_id' => $radarrId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Disable Radarr auto-search (set monitored=false).
     *
     * @return array{success: bool, error: ?string}
     */
    public function disableRadarrAutoSearch(Movie $movie): array
    {
        $radarrInstance = $movie->getRadarrInstance();
        $radarrId = $movie->getRadarrId();

        if (!$radarrInstance instanceof RadarrInstance || $radarrId === null) {
            return ['success' => true, 'error' => null];
        }

        try {
            $radarrMovie = $this->radarrService->getMovie($radarrInstance, $radarrId);
            $radarrMovie['monitored'] = false;
            $this->radarrService->updateMovie($radarrInstance, $radarrId, $radarrMovie);

            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            $this->logger->error('Failed to disable Radarr auto-search', [
                'movie' => $movie->getTitle(),
                'radarr_id' => $radarrId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculate total size of files in a scheduled deletion.
     */
    public function calculateTotalSize(ScheduledDeletion $deletion): int
    {
        $totalSize = 0;

        foreach ($deletion->getItems() as $item) {
            foreach ($item->getMediaFileIds() as $mediaFileId) {
                $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                if ($mediaFile !== null) {
                    $totalSize += $mediaFile->getFileSizeBytes();
                }
            }
        }

        return $totalSize;
    }

    /**
     * Log a deletion activity.
     */
    private function logDeletionActivity(ScheduledDeletion $deletion, int $success, int $failed): void
    {
        $log = new ActivityLog();
        $log->setAction('scheduled_deletion.executed');
        $log->setEntityType('scheduled_deletion');
        $log->setEntityId($deletion->getId());
        $log->setDetails([
            'success' => $success,
            'failed' => $failed,
            'total_items' => $deletion->getItems()->count(),
        ]);

        $user = $deletion->getCreatedBy();
        if ($user instanceof User) {
            $log->setUser($user);
        }

        $this->em->persist($log);
    }
}
