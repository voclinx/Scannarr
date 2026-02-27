<?php

namespace App\WebSocket;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;
use App\Service\DiscordNotificationService;
use App\Service\MediaPlayerRefreshService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Processes incoming WebSocket messages from the watcher.
 * Handles all DB operations for file events and scan events.
 *
 * Path translation (STD 7.7):
 * The watcher sends absolute host_path paths (e.g. /mnt/media1/Movies/file.mkv).
 * We find the volume by matching the host_path prefix, then store the
 * relative path (e.g. Movies/file.mkv) in the MediaFile entity.
 */
class WatcherMessageProcessor
{
    /** @var array<string, array{volume: Volume, seenPaths: array<string, true>}> Scan state keyed by scan_id */
    private array $activeScans = [];

    /** Batch size for scan.file — flush every N files */
    private const int SCAN_BATCH_SIZE = 50;

    /** @var array<string, int> Counter of files processed per scan since last flush */
    private array $scanBatchCounters = [];

    public function __construct(
        private EntityManagerInterface $em,
        private readonly ManagerRegistry $managerRegistry,
        private readonly VolumeRepository $volumeRepository,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly MediaPlayerRefreshService $mediaPlayerRefreshService,
        private readonly DiscordNotificationService $discordNotificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process a decoded message from the watcher.
     *
     * @param array<string, mixed> $message
     */
    public function process(array $message): void
    {
        $type = $message['type'] ?? 'unknown';
        $data = $message['data'] ?? [];

        $this->logger->info('Processing watcher message', ['type' => $type]);

        // Ensure the EntityManager is open (long-running process safety)
        if (!$this->em->isOpen()) {
            $this->logger->warning('EntityManager was closed, resetting');
            $this->managerRegistry->resetManager();
            $this->em = $this->managerRegistry->getManager();
        }

        try {
            match ($type) {
                'file.created' => $this->handleFileCreated($data),
                'file.deleted' => $this->handleFileDeleted($data),
                'file.renamed' => $this->handleFileRenamed($data),
                'file.modified' => $this->handleFileModified($data),
                'scan.started' => $this->handleScanStarted($data),
                'scan.progress' => $this->handleScanProgress($data),
                'scan.file' => $this->handleScanFile($data),
                'scan.completed' => $this->handleScanCompleted($data),
                'watcher.status' => $this->handleWatcherStatus($data),
                'files.delete.progress' => $this->handleFilesDeleteProgress($data),
                'files.delete.completed' => $this->handleFilesDeleteCompleted($data),
                default => $this->logger->warning('Unknown message type', ['type' => $type]),
            };
        } catch (Throwable $e) {
            $this->logger->error('Error processing message', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Clear the EntityManager to avoid stale state
            $this->em->clear();
        }
    }

    // ──────────────────────────────────────────────
    // File deletion events (from watcher)
    // ──────────────────────────────────────────────

    /**
     * Handle per-file deletion progress from the watcher.
     * Just logs in V1 — detailed tracking can be added later.
     */
    private function handleFilesDeleteProgress(array $data): void
    {
        $this->logger->info('File deletion progress', [
            'deletion_id' => $data['deletion_id'] ?? '',
            'media_file_id' => $data['media_file_id'] ?? '',
            'status' => $data['status'] ?? '',
        ]);
    }

    /**
     * Handle deletion completion from the watcher — Phase 3 finalization.
     *
     * 1. Remove successfully deleted media_files from DB
     * 2. Update ScheduledDeletion items status
     * 3. Set execution report + final status
     * 4. Refresh Plex/Jellyfin (best-effort)
     * 5. Discord notification
     * 6. Activity log
     */
    private function handleFilesDeleteCompleted(array $data): void
    {
        $deletionId = $data['deletion_id'] ?? null;
        if ($deletionId === null) {
            $this->logger->warning('files.delete.completed without deletion_id');

            return;
        }

        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        if ($deletion === null) {
            $this->logger->warning('files.delete.completed for unknown deletion', [
                'deletion_id' => $deletionId,
            ]);

            return;
        }

        $results = $data['results'] ?? [];
        $successCount = $data['deleted'] ?? 0;
        $failedCount = $data['failed'] ?? 0;

        // 1. Remove successfully deleted media_files from DB, collect sizes as fallback
        $fileSizes = [];
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'deleted') {
                $mediaFile = $this->mediaFileRepository->find($result['media_file_id'] ?? '');
                if ($mediaFile !== null) {
                    $fileSizes[$result['media_file_id']] = $mediaFile->getFileSizeBytes();
                    $this->em->remove($mediaFile);
                }
            }
        }

        // 2. Update item statuses
        foreach ($deletion->getItems() as $item) {
            $itemFileIds = $item->getMediaFileIds();
            $itemErrors = [];
            foreach ($results as $r) {
                if (in_array($r['media_file_id'] ?? '', $itemFileIds, true) && ($r['status'] ?? '') === 'failed') {
                    $itemErrors[] = $r['error'] ?? 'Unknown';
                }
            }
            $item->setStatus(empty($itemErrors) ? 'deleted' : 'partial_failure');
            if (!empty($itemErrors)) {
                $item->setErrorMessage(implode('; ', $itemErrors));
            }
        }

        // 3. Build enriched items for execution report (used by Discord notifications)
        $itemReports = [];
        $totalSpaceFreed = 0;
        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            $itemErrors = [];
            $itemSpaceFreed = 0;

            foreach ($item->getMediaFileIds() as $fileId) {
                foreach ($results as $r) {
                    if (($r['media_file_id'] ?? '') === $fileId) {
                        if (($r['status'] ?? '') === 'failed') {
                            $itemErrors[] = $r['error'] ?? 'Unknown';
                        }
                        // Use watcher-reported size, fallback to DB size
                        $size = $r['size_bytes'] ?? 0;
                        if ($size === 0) {
                            $size = $fileSizes[$fileId] ?? 0;
                        }
                        $itemSpaceFreed += $size;
                    }
                }
            }

            $itemReports[] = [
                'movie' => $movie !== null ? $movie->getTitle() : 'Unknown',
                'year' => $movie !== null ? $movie->getYear() : null,
                'space_freed_bytes' => $itemSpaceFreed,
                'errors' => $itemErrors,
            ];
            $totalSpaceFreed += $itemSpaceFreed;
        }

        // Execution report + final status
        $deletion->setExecutionReport([
            'finished_at' => (new DateTimeImmutable())->format('c'),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'dirs_removed' => $data['dirs_removed'] ?? 0,
            'results' => $results,
            'items' => $itemReports,
            'total_space_freed' => $totalSpaceFreed,
        ]);
        $deletion->setExecutedAt(new DateTimeImmutable());

        if ($successCount === 0 && $failedCount > 0) {
            $deletion->setStatus(DeletionStatus::FAILED);
        } else {
            $deletion->setStatus(DeletionStatus::COMPLETED);
        }

        // 4. Refresh Plex/Jellyfin (best-effort)
        if ($successCount > 0 && $deletion->isDeleteMediaPlayerReference()) {
            try {
                $this->mediaPlayerRefreshService->refreshAll();
            } catch (Throwable $e) {
                $this->logger->warning('Media player refresh failed after deletion', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 5. Discord notification
        try {
            if ($deletion->getStatus() === DeletionStatus::COMPLETED) {
                $this->discordNotificationService->sendDeletionSuccess($deletion);
            } else {
                $this->discordNotificationService->sendDeletionError($deletion);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Discord notification failed', ['error' => $e->getMessage()]);
        }

        // 6. Activity log
        $log = new ActivityLog();
        $log->setAction('scheduled_deletion.executed');
        $log->setEntityType('scheduled_deletion');
        $log->setEntityId($deletion->getId());
        $log->setDetails(['success' => $successCount, 'failed' => $failedCount]);
        if ($deletion->getCreatedBy() !== null) {
            $log->setUser($deletion->getCreatedBy());
        }
        $this->em->persist($log);
        $this->em->flush();

        $this->logger->info('Deletion completed', [
            'deletion_id' => $deletionId,
            'success' => $successCount,
            'failed' => $failedCount,
            'status' => $deletion->getStatus()->value,
        ]);
    }

    /**
     * Get pending deletion commands to resend on watcher reconnection.
     * Returns commands for all ScheduledDeletions in WAITING_WATCHER status.
     *
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function getPendingDeletionCommands(): array
    {
        $waitingDeletions = $this->em->getRepository(ScheduledDeletion::class)
            ->findBy(['status' => DeletionStatus::WAITING_WATCHER]);

        $commands = [];
        foreach ($waitingDeletions as $deletion) {
            $files = [];
            foreach ($deletion->getItems() as $item) {
                foreach ($item->getMediaFileIds() as $mediaFileId) {
                    $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                    if ($mediaFile === null || $mediaFile->getVolume() === null) {
                        continue;
                    }

                    $volume = $mediaFile->getVolume();
                    $volumeHostPath = $volume->getHostPath();
                    if ($volumeHostPath === null || $volumeHostPath === '') {
                        $volumeHostPath = $volume->getPath();
                    }

                    $files[] = [
                        'media_file_id' => (string)$mediaFile->getId(),
                        'volume_path' => rtrim($volumeHostPath ?? '', '/'),
                        'file_path' => $mediaFile->getFilePath(),
                    ];
                }
            }

            if (empty($files)) {
                $deletion->setStatus(DeletionStatus::COMPLETED);
                $deletion->setExecutedAt(new DateTimeImmutable());
                continue;
            }

            $deletion->setStatus(DeletionStatus::EXECUTING);
            $commands[] = [
                'type' => 'command.files.delete',
                'data' => [
                    'request_id' => (string)Uuid::v4(),
                    'deletion_id' => (string)$deletion->getId(),
                    'files' => $files,
                ],
            ];
        }
        $this->em->flush();

        return $commands;
    }

    // ──────────────────────────────────────────────
    // File events (real-time watch mode)
    // ──────────────────────────────────────────────

    private function handleFileCreated(array $data): void
    {
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->resolveVolume($path);
        if (!$volume instanceof Volume) {
            $this->logger->warning('No volume found for path', ['path' => $path]);

            return;
        }

        $relativePath = $this->getRelativePath($path, $volume);
        $existing = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($existing instanceof MediaFile) {
            // Update existing file (might have been re-created)
            $existing->setFileSizeBytes((int)($data['size_bytes'] ?? 0));
            $existing->setHardlinkCount((int)($data['hardlink_count'] ?? 1));
            $existing->setFileName($data['name'] ?? basename((string)$path));
            $this->em->flush();
            $this->logger->info('File updated (re-created)', ['path' => $relativePath, 'volume' => $volume->getName()]);

            return;
        }

        $mediaFile = $this->createMediaFile($volume, $relativePath, $data);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->logger->info('File created', [
            'path' => $relativePath,
            'volume' => $volume->getName(),
            'size' => $data['size_bytes'] ?? 0,
        ]);
    }

    private function handleFileDeleted(array $data): void
    {
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->resolveVolume($path);
        if (!$volume instanceof Volume) {
            $this->logger->warning('No volume found for path', ['path' => $path]);

            return;
        }

        $relativePath = $this->getRelativePath($path, $volume);
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if (!$mediaFile instanceof MediaFile) {
            $this->logger->debug('File not found in DB for deletion', ['path' => $relativePath]);

            return;
        }

        // Log the deletion in activity_logs
        $this->logActivity('file.deleted', 'MediaFile', $mediaFile->getId(), [
            'file_name' => $mediaFile->getFileName(),
            'file_path' => $relativePath,
            'volume' => $volume->getName(),
            'size_bytes' => $mediaFile->getFileSizeBytes(),
            'source' => 'watcher',
        ]);

        $this->em->remove($mediaFile);
        $this->em->flush();

        $this->logger->info('File deleted', ['path' => $relativePath, 'volume' => $volume->getName()]);
    }

    private function handleFileRenamed(array $data): void
    {
        $oldPath = $data['old_path'] ?? null;
        $newPath = $data['new_path'] ?? null;
        if (!$oldPath || !$newPath) {
            return;
        }

        $oldVolume = $this->resolveVolume($oldPath);
        $newVolume = $this->resolveVolume($newPath);

        if (!$oldVolume && !$newVolume) {
            $this->logger->warning('No volume found for rename', ['old' => $oldPath, 'new' => $newPath]);

            return;
        }

        $oldRelativePath = $oldVolume instanceof Volume ? $this->getRelativePath($oldPath, $oldVolume) : null;
        $newRelativePath = $newVolume instanceof Volume ? $this->getRelativePath($newPath, $newVolume) : null;

        // Find the old file
        $mediaFile = null;
        if ($oldVolume && $oldRelativePath) {
            $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($oldVolume, $oldRelativePath);
        }

        if ($mediaFile && $newVolume && $newRelativePath) {
            // Update existing file with new path
            $mediaFile->setVolume($newVolume);
            $mediaFile->setFilePath($newRelativePath);
            $mediaFile->setFileName($data['name'] ?? basename((string)$newPath));
            $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
            $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
            $this->em->flush();

            $this->logger->info('File renamed', ['old' => $oldRelativePath, 'new' => $newRelativePath]);
        } elseif (!$mediaFile && $newVolume && $newRelativePath) {
            // Old file not in DB — create the new one
            $newFile = $this->createMediaFile($newVolume, $newRelativePath, $data);
            $this->em->persist($newFile);
            $this->em->flush();

            $this->logger->info('File renamed (old not found, created new)', ['path' => $newRelativePath]);
        } elseif ($mediaFile && !$newVolume) {
            // Moved out of known volumes — delete the old entry
            $this->em->remove($mediaFile);
            $this->em->flush();

            $this->logger->info('File moved out of volumes (removed)', ['old' => $oldRelativePath]);
        }
    }

    private function handleFileModified(array $data): void
    {
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->resolveVolume($path);
        if (!$volume instanceof Volume) {
            return;
        }

        $relativePath = $this->getRelativePath($path, $volume);
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if (!$mediaFile instanceof MediaFile) {
            // File not in DB yet — treat as created
            $this->handleFileCreated($data);

            return;
        }

        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
        $this->em->flush();

        $this->logger->info('File modified', ['path' => $relativePath, 'size' => $data['size_bytes'] ?? 0]);
    }

    // ──────────────────────────────────────────────
    // Scan events
    // ──────────────────────────────────────────────

    private function handleScanStarted(array $data): void
    {
        $scanId = $data['scan_id'] ?? null;
        $path = $data['path'] ?? null;

        if (!$scanId || !$path) {
            $this->logger->warning('Scan started with missing data', $data);

            return;
        }

        $volume = $this->resolveVolume($path);
        if (!$volume instanceof Volume) {
            $this->logger->warning('No volume found for scan path', ['path' => $path]);

            return;
        }

        $this->activeScans[$scanId] = [
            'volume' => $volume,
            'seenPaths' => [],
        ];
        $this->scanBatchCounters[$scanId] = 0;

        $this->logger->info('Scan started', [
            'scan_id' => $scanId,
            'path' => $path,
            'volume' => $volume->getName(),
        ]);
    }

    private function handleScanProgress(array $data): void
    {
        $this->logger->debug('Scan progress', [
            'scan_id' => $data['scan_id'] ?? 'unknown',
            'files_scanned' => $data['files_scanned'] ?? 0,
            'dirs_scanned' => $data['dirs_scanned'] ?? 0,
        ]);
    }

    private function handleScanFile(array $data): void
    {
        $scanId = $data['scan_id'] ?? null;
        if (!$scanId || !isset($this->activeScans[$scanId])) {
            // No active scan context — treat as a standalone file creation
            $this->handleFileCreated($data);

            return;
        }

        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->activeScans[$scanId]['volume'];
        $relativePath = $this->getRelativePath($path, $volume);

        // Track seen file for cleanup on scan.completed
        $this->activeScans[$scanId]['seenPaths'][$relativePath] = true;

        // Upsert: find existing or create new
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($mediaFile instanceof MediaFile) {
            $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
            $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
            $mediaFile->setFileName($data['name'] ?? basename((string)$path));
        } else {
            $mediaFile = $this->createMediaFile($volume, $relativePath, $data);
            $this->em->persist($mediaFile);
        }

        // Batch flush for performance
        ++$this->scanBatchCounters[$scanId];
        if ($this->scanBatchCounters[$scanId] >= self::SCAN_BATCH_SIZE) {
            $this->em->flush();
            $this->em->clear();
            // Re-fetch the volume reference after clear
            $this->activeScans[$scanId]['volume'] = $this->em->getReference(Volume::class, $this->activeScans[$scanId]['volume']->getId());
            $this->scanBatchCounters[$scanId] = 0;
        }
    }

    private function handleScanCompleted(array $data): void
    {
        $scanId = $data['scan_id'] ?? null;

        // Final flush of any remaining batch
        $this->em->flush();

        if (!$scanId || !isset($this->activeScans[$scanId])) {
            $this->logger->warning('Scan completed for unknown scan_id', ['scan_id' => $scanId]);

            return;
        }

        $volume = $this->activeScans[$scanId]['volume'];
        $seenPaths = $this->activeScans[$scanId]['seenPaths'];

        // Find files in DB that were NOT seen during the scan → they've been deleted
        $allDbPaths = $this->mediaFileRepository->findAllFilePathsByVolume($volume);
        $stalePaths = array_diff($allDbPaths, array_keys($seenPaths));

        $removedCount = 0;
        if ($stalePaths !== []) {
            $removedCount = $this->mediaFileRepository->deleteByVolumeAndFilePaths($volume, $stalePaths);
            $this->logger->info('Scan cleanup: removed stale files', [
                'volume' => $volume->getName(),
                'removed' => $removedCount,
            ]);
        }

        // Update volume metadata
        $volume->setLastScanAt(new DateTimeImmutable());
        if (isset($data['total_size_bytes'])) {
            $volume->setUsedSpaceBytes((int)$data['total_size_bytes']);
        }
        $this->em->flush();

        // Log activity
        $this->logActivity('scan.completed', 'Volume', $volume->getId(), [
            'scan_id' => $scanId,
            'volume' => $volume->getName(),
            'total_files' => $data['total_files'] ?? 0,
            'total_dirs' => $data['total_dirs'] ?? 0,
            'total_size_bytes' => $data['total_size_bytes'] ?? 0,
            'duration_ms' => $data['duration_ms'] ?? 0,
            'stale_removed' => $removedCount,
        ]);

        $this->logger->info('Scan completed', [
            'scan_id' => $scanId,
            'volume' => $volume->getName(),
            'total_files' => $data['total_files'] ?? 0,
            'duration_ms' => $data['duration_ms'] ?? 0,
            'stale_removed' => $removedCount,
        ]);

        // Clean up scan state
        unset($this->activeScans[$scanId]);
        unset($this->scanBatchCounters[$scanId]);

        // Clear identity map to free memory after large scan
        $this->em->clear();
    }

    // ──────────────────────────────────────────────
    // Watcher status
    // ──────────────────────────────────────────────

    private function handleWatcherStatus(array $data): void
    {
        $this->logger->debug('Watcher status', [
            'status' => $data['status'] ?? 'unknown',
            'watched_paths' => $data['watched_paths'] ?? [],
            'uptime_seconds' => $data['uptime_seconds'] ?? 0,
        ]);
    }

    // ──────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────

    /**
     * Find the volume matching the given absolute host path.
     */
    private function resolveVolume(string $absolutePath): ?Volume
    {
        return $this->volumeRepository->findByHostPathPrefix($absolutePath);
    }

    /**
     * Convert an absolute host path to a path relative to the volume's host_path.
     * e.g., /mnt/media1/Movies/file.mkv with host_path=/mnt/media1 → Movies/file.mkv
     */
    private function getRelativePath(string $absolutePath, Volume $volume): string
    {
        $hostPath = rtrim((string)$volume->getHostPath(), '/');
        $relative = substr($absolutePath, strlen($hostPath));

        return ltrim($relative, '/');
    }

    /**
     * Create a new MediaFile entity from event data.
     */
    private function createMediaFile(Volume $volume, string $relativePath, array $data): MediaFile
    {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath($relativePath);
        $mediaFile->setFileName($data['name'] ?? basename($relativePath));
        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? 0));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? 1));

        return $mediaFile;
    }

    /**
     * Log an activity (without a user, since it comes from the watcher).
     */
    private function logActivity(string $action, string $entityType, ?Uuid $entityId, array $details): void
    {
        $log = new ActivityLog();
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setDetails($details);

        $this->em->persist($log);
        // Flushed by the caller or at next flush point
    }
}
