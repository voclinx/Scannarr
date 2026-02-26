<?php

namespace App\WebSocket;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\Repository\VolumeRepository;
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
