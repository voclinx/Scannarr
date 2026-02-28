<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Repository\MediaFileRepository;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class FileModifiedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly WatcherFileHelper $helper,
        private readonly FileCreatedHandler $fileCreatedHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'file.modified';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->helper->resolveVolume($path);
        if ($volume === null) {
            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($mediaFile === null) {
            // File not in DB yet — treat as created
            $this->fileCreatedHandler->handleCreated($data);

            return;
        }

        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
        if (isset($data['partial_hash']) && $data['partial_hash'] !== '') {
            $mediaFile->setPartialHash($data['partial_hash']);
        }
        if (isset($data['inode']) && $data['inode'] > 0) {
            $mediaFile->setInode((string) $data['inode']);
        }
        if (isset($data['device_id']) && $data['device_id'] > 0) {
            $mediaFile->setDeviceId((string) $data['device_id']);
        }
        $this->em->flush();

        $this->logger->info('File modified', ['path' => $relativePath, 'size' => $data['size_bytes'] ?? 0]);
    }
}
