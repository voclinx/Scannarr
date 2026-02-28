<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\User;
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

        $allFilesToDelete = [];
        $seenFileIds = [];

        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();

            // Step 1: Radarr dereference (BEFORE any file deletion)
            if ($deletion->isDeleteRadarrReference() && $movie instanceof Movie) {
                $this->dereferenceFromRadarr($movie);
            } elseif ($deletion->isDisableRadarrAutoSearch() && $movie instanceof Movie) {
                $this->disableRadarrAutoSearch($movie);
            }

            // Step 2: qBittorrent cleanup + collect files for watcher
            if ($deletion->isDeletePhysicalFiles()) {
                foreach ($item->getMediaFileIds() as $mediaFileId) {
                    $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                    if ($mediaFile === null) {
                        continue;
                    }

                    // Collect explicit file + inode siblings (hardlinks on other volumes)
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

                        // qBittorrent uses host paths
                        $hostPath = $volume->getHostPath();
                        if ($hostPath !== null && $hostPath !== '' && $this->qBittorrentService->isConfigured()) {
                            $absoluteHostPath = rtrim($hostPath, '/') . '/' . $fileToDelete->getFilePath();
                            try {
                                $this->qBittorrentService->findAndDeleteTorrent($absoluteHostPath);
                            } catch (Throwable $e) {
                                $this->logger->warning('qBittorrent cleanup failed (best-effort)', [
                                    'file' => $absoluteHostPath,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Collect file for watcher deletion — use hostPath for the watcher
                        $volumeHostPath = $volume->getHostPath();
                        if ($volumeHostPath === null || $volumeHostPath === '') {
                            $volumeHostPath = $volume->getPath(); // fallback
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

        // If no physical files to delete, complete immediately
        if ($allFilesToDelete === []) {
            $deletion->setStatus(DeletionStatus::COMPLETED);
            $deletion->setExecutedAt(new DateTimeImmutable());
            $deletion->setExecutionReport([
                'finished_at' => (new DateTimeImmutable())->format('c'),
                'success_count' => 0,
                'failed_count' => 0,
                'message' => 'No physical files to delete',
            ]);

            // Log activity
            $this->logDeletionActivity($deletion, 0, 0);
            $this->em->flush();

            return;
        }

        // Step 3: Send delete command to watcher
        $watcherReached = $this->watcherCommandService->requestFilesDelete(
            (string)$deletion->getId(),
            $allFilesToDelete,
        );

        if (!$watcherReached) {
            $deletion->setStatus(DeletionStatus::WAITING_WATCHER);
            $this->logger->info('Watcher offline, deletion queued for reconnection', [
                'deletion_id' => (string)$deletion->getId(),
                'files_count' => count($allFilesToDelete),
            ]);
        }
        // If watcher reached → status stays EXECUTING, completion comes async via WS

        $this->em->flush();
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
