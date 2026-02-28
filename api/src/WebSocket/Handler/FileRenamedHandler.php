<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Repository\MediaFileRepository;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class FileRenamedHandler implements WatcherMessageHandlerInterface
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
        return $messageType === 'file.renamed';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $oldPath = $data['old_path'] ?? null;
        $newPath = $data['new_path'] ?? null;

        if (!$oldPath || !$newPath) {
            return;
        }

        $oldVolume = $this->helper->resolveVolume($oldPath);
        $newVolume = $this->helper->resolveVolume($newPath);

        if (!$oldVolume && !$newVolume) {
            $this->logger->warning('No volume found for rename', ['old' => $oldPath, 'new' => $newPath]);

            return;
        }

        $oldRelativePath = $oldVolume !== null ? $this->helper->getRelativePath($oldPath, $oldVolume) : null;
        $newRelativePath = $newVolume !== null ? $this->helper->getRelativePath($newPath, $newVolume) : null;

        $mediaFile = null;
        if ($oldVolume && $oldRelativePath) {
            $mediaFile = $this->mediaFileRepository->findByVolumeAndFilePath($oldVolume, $oldRelativePath);
        }

        if ($mediaFile && $newVolume && $newRelativePath) {
            $mediaFile->setVolume($newVolume);
            $mediaFile->setFilePath($newRelativePath);
            $mediaFile->setFileName($data['name'] ?? basename((string)$newPath));
            $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
            $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
            if (isset($data['inode']) && $data['inode'] > 0) {
                $mediaFile->setInode((string) $data['inode']);
            }
            if (isset($data['device_id']) && $data['device_id'] > 0) {
                $mediaFile->setDeviceId((string) $data['device_id']);
            }
            $this->em->flush();

            $this->logger->info('File renamed', ['old' => $oldRelativePath, 'new' => $newRelativePath]);
        } elseif (!$mediaFile && $newVolume && $newRelativePath) {
            $newFile = $this->helper->createMediaFile($newVolume, $newRelativePath, $data);
            $this->em->persist($newFile);
            $this->em->flush();

            $this->logger->info('File renamed (old not found, created new)', ['path' => $newRelativePath]);
        } elseif ($mediaFile && !$newVolume) {
            $this->em->remove($mediaFile);
            $this->em->flush();

            $this->logger->info('File moved out of volumes (removed)', ['old' => $oldRelativePath]);
        }
    }
}
