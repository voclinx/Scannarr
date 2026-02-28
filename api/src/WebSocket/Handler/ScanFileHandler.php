<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\WebSocket\ScanStateManager;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;

final class ScanFileHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly ScanStateManager $scanState,
        private readonly WatcherFileHelper $helper,
        private readonly FileCreatedHandler $fileCreatedHandler,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'scan.file';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $scanId = $data['scan_id'] ?? null;

        if (!$scanId || !$this->scanState->hasScan($scanId)) {
            // No active scan context — treat as a standalone file creation
            $this->fileCreatedHandler->handleCreated($data);

            return;
        }

        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->scanState->getVolume($scanId);
        if ($volume === null) {
            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $this->scanState->markPathSeen($scanId, $relativePath);

        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($mediaFile !== null) {
            $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
            $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
            $mediaFile->setFileName($data['name'] ?? basename((string)$path));
            if (isset($data['partial_hash']) && $data['partial_hash'] !== '') {
                $mediaFile->setPartialHash($data['partial_hash']);
            }
            if (isset($data['inode']) && $data['inode'] > 0) {
                $mediaFile->setInode((string) $data['inode']);
            }
            if (isset($data['device_id']) && $data['device_id'] > 0) {
                $mediaFile->setDeviceId((string) $data['device_id']);
            }
        } else {
            $mediaFile = $this->helper->createMediaFile($volume, $relativePath, $data);
            $this->em->persist($mediaFile);
        }

        $this->syncHardlinkSiblings($mediaFile, $data);

        $batchCount = $this->scanState->incrementBatch($scanId);

        if ($batchCount >= ScanStateManager::SCAN_BATCH_SIZE) {
            $this->em->flush();
            $this->em->clear();
            // Re-fetch the volume reference after clear
            $freshVolume = $this->em->getReference(Volume::class, $volume->getId());
            $this->scanState->updateVolume($scanId, $freshVolume);
            $this->scanState->resetBatch($scanId);
        }
    }

    /**
     * When a file has a known (device_id, inode), ensure all DB siblings
     * (= other paths pointing to the same physical inode) share the same hardlink_count.
     *
     * @param array<string, mixed> $data
     */
    private function syncHardlinkSiblings(MediaFile $mediaFile, array $data): void
    {
        $deviceId = $mediaFile->getDeviceId();
        $inode = $mediaFile->getInode();
        $nlink = (int)($data['hardlink_count'] ?? 1);

        if ($deviceId === null || $inode === null || $nlink <= 1) {
            return;
        }

        $siblings = $this->mediaFileRepository->findAllByInode($deviceId, $inode);
        if (count($siblings) <= 1) {
            return;
        }

        foreach ($siblings as $sibling) {
            if ($sibling->getHardlinkCount() !== $nlink) {
                $sibling->setHardlinkCount($nlink);
            }
        }
    }
}
