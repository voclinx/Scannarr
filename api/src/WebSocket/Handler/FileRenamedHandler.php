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

final readonly class FileRenamedHandler implements WatcherMessageHandlerInterface
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

        $mediaFile = $this->findExistingFile($oldVolume, $oldPath);
        $newRelativePath = $newVolume instanceof Volume ? $this->helper->getRelativePath($newPath, $newVolume) : null;

        $this->processRename($mediaFile, $newVolume, $newRelativePath, $data);
    }

    private function findExistingFile(?Volume $oldVolume, string $oldPath): ?MediaFile
    {
        if (!$oldVolume instanceof Volume) {
            return null;
        }

        $oldRelativePath = $this->helper->getRelativePath($oldPath, $oldVolume);

        return $this->mediaFileRepository->findByVolumeAndFilePath($oldVolume, $oldRelativePath);
    }

    /** @param array<string, mixed> $data */
    private function processRename(?MediaFile $mediaFile, ?Volume $newVolume, ?string $newRelativePath, array $data): void
    {
        if ($mediaFile instanceof MediaFile && $newVolume instanceof Volume && $newRelativePath !== null) {
            $this->moveFile($mediaFile, $newVolume, $newRelativePath, $data);

            return;
        }

        if (!$mediaFile instanceof MediaFile && $newVolume instanceof Volume && $newRelativePath !== null) {
            $this->createFileAtNewLocation($newVolume, $newRelativePath, $data);

            return;
        }

        if ($mediaFile instanceof MediaFile && !$newVolume instanceof Volume) {
            $this->removeFileMovedOutOfVolumes($mediaFile);
        }
    }

    /** @param array<string, mixed> $data */
    private function moveFile(MediaFile $mediaFile, Volume $newVolume, string $newRelativePath, array $data): void
    {
        $mediaFile->setVolume($newVolume);
        $mediaFile->setFilePath($newRelativePath);
        $mediaFile->setFileName($data['name'] ?? basename((string)($data['new_path'] ?? '')));
        $mediaFile->setFileSizeBytes((int)($data['size_bytes'] ?? $mediaFile->getFileSizeBytes()));
        $mediaFile->setHardlinkCount((int)($data['hardlink_count'] ?? $mediaFile->getHardlinkCount()));
        $this->helper->applyOptionalFields($mediaFile, $data);
        $this->em->flush();

        $this->logger->info('File renamed', ['new' => $newRelativePath]);
    }

    /** @param array<string, mixed> $data */
    private function createFileAtNewLocation(Volume $newVolume, string $newRelativePath, array $data): void
    {
        $newFile = $this->helper->createMediaFile($newVolume, $newRelativePath, $data);
        $this->em->persist($newFile);
        $this->em->flush();

        $this->logger->info('File renamed (old not found, created new)', ['path' => $newRelativePath]);
    }

    private function removeFileMovedOutOfVolumes(MediaFile $mediaFile): void
    {
        $oldPath = $mediaFile->getFilePath();
        $this->em->remove($mediaFile);
        $this->em->flush();

        $this->logger->info('File moved out of volumes (removed)', ['old' => $oldPath]);
    }
}
