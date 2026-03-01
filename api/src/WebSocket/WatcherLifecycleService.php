<?php

declare(strict_types=1);

namespace App\WebSocket;

use App\Entity\ScheduledDeletion;
use App\Entity\Watcher;
use App\Enum\DeletionStatus;
use App\Enum\WatcherStatus;
use App\Repository\MediaFileRepository;
use App\Repository\WatcherRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles watcher lifecycle operations: registration, authentication, disconnection, and pending command retrieval.
 * Extracted from WatcherMessageProcessor.
 */
final readonly class WatcherLifecycleService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WatcherRepository $watcherRepository,
        private MediaFileRepository $mediaFileRepository,
    ) {
    }

    /**
     * Find or create a Watcher entity from a hello message.
     * Creates with PENDING status if unknown; updates hostname/version/lastSeenAt otherwise.
     */
    public function findOrCreateWatcher(string $watcherId, ?string $hostname, ?string $version): Watcher
    {
        $watcher = $this->watcherRepository->findByWatcherId($watcherId);

        if ($watcher instanceof Watcher) {
            $this->em->refresh($watcher);
        }

        if (!$watcher instanceof Watcher) {
            $watcher = new Watcher();
            $watcher->setWatcherId($watcherId);
            $watcher->setName($hostname ?? $watcherId);
            $watcher->setStatus(WatcherStatus::PENDING);
            $this->em->persist($watcher);
        }

        $watcher->setHostname($hostname);
        $watcher->setVersion($version);
        $watcher->setLastSeenAt(new DateTimeImmutable());
        $this->em->flush();

        return $watcher;
    }

    /**
     * Authenticate a watcher by its auth token.
     * Returns null if not found, revoked, or token mismatch.
     */
    public function authenticateWatcher(string $token): ?Watcher
    {
        $watcher = $this->watcherRepository->findByAuthToken($token);

        if (!$watcher instanceof Watcher) {
            return null;
        }

        if ($watcher->getStatus() === WatcherStatus::REVOKED) {
            return null;
        }

        $watcher->setStatus(WatcherStatus::CONNECTED);
        $watcher->setLastSeenAt(new DateTimeImmutable());
        $this->em->flush();

        return $watcher;
    }

    /**
     * Mark a watcher as disconnected when its WebSocket connection closes.
     */
    public function handleWatcherDisconnect(string $watcherId): void
    {
        $watcher = $this->watcherRepository->findByWatcherId($watcherId);
        if (!$watcher instanceof Watcher) {
            return;
        }

        if ($watcher->getStatus() === WatcherStatus::CONNECTED) {
            $watcher->setStatus(WatcherStatus::DISCONNECTED);
            $this->em->flush();
        }
    }

    /**
     * Get pending deletion commands to resend on watcher reconnection.
     *
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function getPendingDeletionCommands(): array
    {
        $waitingDeletions = $this->em->getRepository(ScheduledDeletion::class)
            ->findBy(['status' => DeletionStatus::WAITING_WATCHER]);

        $commands = [];
        foreach ($waitingDeletions as $deletion) {
            $files = $this->buildFilesForDeletion($deletion);

            if ($files === []) {
                $deletion->setStatus(DeletionStatus::COMPLETED);
                $deletion->setExecutedAt(new DateTimeImmutable());
                continue;
            }

            $deletion->setStatus(DeletionStatus::EXECUTING);
            $commands[] = [
                'type' => 'command.files.delete',
                'data' => [
                    'request_id' => (string)Uuid::v4(),
                    'deletion_id' => (string)$deletion->getId(),
                    'files' => $files,
                ],
            ];
        }
        $this->em->flush();

        return $commands;
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @return array<int, array{media_file_id: string, volume_path: string, file_path: ?string}>
     */
    private function buildFilesForDeletion(ScheduledDeletion $deletion): array
    {
        $files = [];
        foreach ($deletion->getItems() as $item) {
            foreach ($item->getMediaFileIds() as $mediaFileId) {
                $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                if ($mediaFile === null) {
                    continue;
                }
                if ($mediaFile->getVolume() === null) {
                    continue;
                }

                $volume = $mediaFile->getVolume();
                $volumeHostPath = $volume->getHostPath();
                if ($volumeHostPath === null || $volumeHostPath === '') {
                    $volumeHostPath = $volume->getPath();
                }

                $files[] = [
                    'media_file_id' => (string)$mediaFile->getId(),
                    'volume_path' => rtrim($volumeHostPath ?? '', '/'),
                    'file_path' => $mediaFile->getFilePath(),
                ];
            }
        }

        return $files;
    }
}
