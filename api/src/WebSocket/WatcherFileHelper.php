<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Repository\VolumeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Shared helper methods for watcher message handlers.
 * Provides volume resolution, path translation, and entity creation utilities.
 */
final readonly class WatcherFileHelper
{
    public function __construct(
        private VolumeRepository $volumeRepository,
    ) {
    }

    /**
     * Find the volume matching the given absolute host path.
     */
    public function resolveVolume(string $absolutePath): ?Volume
    {
        return $this->volumeRepository->findByHostPathPrefix($absolutePath);
    }

    /**
     * Convert an absolute host path to a path relative to the volume's host_path.
     * e.g., /mnt/media1/Movies/file.mkv with host_path=/mnt/media1 → Movies/file.mkv
     */
    public function getRelativePath(string $absolutePath, Volume $volume): string
    {
        $hostPath = rtrim((string)$volume->getHostPath(), '/');
        $relative = substr($absolutePath, strlen($hostPath));

        return ltrim($relative, '/');
    }

    /**
     * Create a new MediaFile entity from event data (does NOT persist/flush).
     *
     * @param array<string, mixed> $data
     */
    public function createMediaFile(Volume $volume, string $relativePath, array $data): MediaFile
    {
        $mediaFile = new MediaFile();
        $mediaFile->setVolume($volume);
        $mediaFile->setFilePath($relativePath);
        $mediaFile->setFileName($data['name'] ?? basename($relativePath));
        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? 0));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? 1));

        if (isset($data['partial_hash']) && $data['partial_hash'] !== '') {
            $mediaFile->setPartialHash($data['partial_hash']);
        }

        if (isset($data['inode']) && $data['inode'] > 0) {
            $mediaFile->setInode((string)$data['inode']);
        }
        if (isset($data['device_id']) && $data['device_id'] > 0) {
            $mediaFile->setDeviceId((string)$data['device_id']);
        }

        return $mediaFile;
    }

    /**
     * Apply optional fields (partial_hash, inode, device_id) from event data to a MediaFile.
     *
     * @param array<string, mixed> $data
     */
    public function applyOptionalFields(MediaFile $mediaFile, array $data): void
    {
        if (isset($data['partial_hash']) && $data['partial_hash'] !== '') {
            $mediaFile->setPartialHash($data['partial_hash']);
        }
        if (isset($data['inode']) && $data['inode'] > 0) {
            $mediaFile->setInode((string)$data['inode']);
        }
        if (isset($data['device_id']) && $data['device_id'] > 0) {
            $mediaFile->setDeviceId((string)$data['device_id']);
        }
    }

    /**
     * Persist an ActivityLog entry (does NOT flush — caller is responsible).
     *
     * @param array<string, mixed> $details
     */
    public function logActivity(
        EntityManagerInterface $em,
        string $action,
        string $entityType,
        ?Uuid $entityId,
        array $details,
    ): void {
        $log = new ActivityLog();
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setDetails($details);

        $em->persist($log);
    }
}
