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

final readonly class ScanFileHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private ScanStateManager $scanState,
        private WatcherFileHelper $helper,
        private FileCreatedHandler $fileCreatedHandler,
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
            $this->fileCreatedHandler->handleCreated($data);

            return;
        }

        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->scanState->getVolume($scanId);
        if (!$volume instanceof Volume) {
            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $this->scanState->markPathSeen($scanId, $relativePath);

        $mediaFile = $this->resolveMediaFile($volume, $relativePath, $path, $data);
        $this->syncHardlinkSiblings($mediaFile, $data);
        $this->flushBatchIfNeeded($scanId, $volume);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveMediaFile(Volume $volume, string $relativePath, string $path, array $data): MediaFile
    {
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if (!$mediaFile instanceof MediaFile) {
            $mediaFile = $this->helper->createMediaFile($volume, $relativePath, $data);
            $this->em->persist($mediaFile);

            return $mediaFile;
        }

        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
        $mediaFile->setFileName($data['name'] ?? basename($path));
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

    private function flushBatchIfNeeded(string $scanId, Volume $volume): void
    {
        $batchCount = $this->scanState->incrementBatch($scanId);

        if ($batchCount < ScanStateManager::SCAN_BATCH_SIZE) {
            return;
        }

        $this->em->flush();
        $this->em->clear();
        $freshVolume = $this->em->getReference(Volume::class, $volume->getId());
        $this->scanState->updateVolume($scanId, $freshVolume);
        $this->scanState->resetBatch($scanId);
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
