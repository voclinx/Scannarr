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

final readonly class FileDeletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private WatcherFileHelper $helper,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'file.deleted';
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
            $this->logger->warning('No volume found for path', ['path' => $path]);

            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if (!$mediaFile instanceof MediaFile) {
            $this->logger->debug('File not found in DB for deletion', ['path' => $relativePath]);

            return;
        }

        $this->deleteMediaFile($mediaFile, $relativePath, $volume);
    }

    private function deleteMediaFile(MediaFile $mediaFile, string $relativePath, Volume $volume): void
    {
        $this->helper->logActivity($this->em, 'file.deleted', 'MediaFile', $mediaFile->getId(), [
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
}
