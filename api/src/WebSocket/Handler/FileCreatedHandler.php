<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\MediaFile;
use App\Repository\MediaFileRepository;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class FileCreatedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly WatcherFileHelper $helper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'file.created';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $this->handleCreated($data);
    }

    /** @param array<string, mixed> $data */
    public function handleCreated(array $data): void
    {
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->helper->resolveVolume($path);
        if ($volume === null) {
            $this->logger->warning('No volume found for path', ['path' => $path]);

            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $existing = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($existing instanceof MediaFile) {
            $existing->setFileSizeBytes((int)($data['size_bytes'] ?? 0));
            $existing->setHardlinkCount((int)($data['hardlink_count'] ?? 1));
            $existing->setFileName($data['name'] ?? basename((string)$path));
            if (isset($data['partial_hash']) && $data['partial_hash'] !== '') {
                $existing->setPartialHash($data['partial_hash']);
            }
            if (isset($data['inode']) && $data['inode'] > 0) {
                $existing->setInode((string) $data['inode']);
            }
            if (isset($data['device_id']) && $data['device_id'] > 0) {
                $existing->setDeviceId((string) $data['device_id']);
            }
            $this->em->flush();
            $this->logger->info('File updated (re-created)', ['path' => $relativePath, 'volume' => $volume->getName()]);

            return;
        }

        $mediaFile = $this->helper->createMediaFile($volume, $relativePath, $data);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->logger->info('File created', [
            'path' => $relativePath,
            'volume' => $volume->getName(),
            'size' => $data['size_bytes'] ?? 0,
        ]);
    }
}
