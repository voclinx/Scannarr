<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\MediaFile;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class FileModifiedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private WatcherFileHelper $helper,
        private FileCreatedHandler $fileCreatedHandler,
        private LoggerInterface $logger,
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
        if (!$volume instanceof Volume) {
            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if (!$mediaFile instanceof MediaFile) {
            $this->fileCreatedHandler->handleCreated($data);

            return;
        }

        $this->updateFileMetadata($mediaFile, $data, $relativePath);
    }

    /** @param array<string, mixed> $data */
    private function updateFileMetadata(MediaFile $mediaFile, array $data, string $relativePath): void
    {
        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
        $this->helper->applyOptionalFields($mediaFile, $data);
        $this->em->flush();

        $this->logger->info('File modified', ['path' => $relativePath, 'size' => $data['size_bytes'] ?? 0]);
    }
}
