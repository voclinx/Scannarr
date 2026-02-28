<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\ActivityLog;
use App\Entity\ScheduledDeletion;
use App\Enum\DeletionStatus;
use App\ExternalService\Notification\DiscordNotificationService;
use App\Repository\MediaFileRepository;
use App\Service\MediaPlayerRefreshService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class FilesDeleteCompletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly MediaPlayerRefreshService $mediaPlayerRefreshService,
        private readonly DiscordNotificationService $discordNotificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'files.delete.completed';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $deletionId = $data['deletion_id'] ?? null;

        if ($deletionId === null) {
            $this->logger->warning('files.delete.completed without deletion_id');

            return;
        }

        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        if ($deletion === null) {
            $this->logger->warning('files.delete.completed for unknown deletion', ['deletion_id' => $deletionId]);

            return;
        }

        $results = $data['results'] ?? [];
        $successCount = $data['deleted'] ?? 0;
        $failedCount = $data['failed'] ?? 0;

        // 1. Remove successfully deleted media_files from DB
        $fileSizes = [];
        $fileNames = [];
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'deleted') {
                $mediaFile = $this->mediaFileRepository->find($result['media_file_id'] ?? '');
                if ($mediaFile !== null) {
                    $fileSizes[$result['media_file_id']] = $mediaFile->getFileSizeBytes();
                    $fileNames[$result['media_file_id']] = $mediaFile->getFileName();
                    $this->em->remove($mediaFile);
                }
            }
        }

        // 2. Update item statuses
        foreach ($deletion->getItems() as $item) {
            $itemFileIds = $item->getMediaFileIds();
            $itemErrors = [];
            foreach ($results as $r) {
                if (in_array($r['media_file_id'] ?? '', $itemFileIds, true) && ($r['status'] ?? '') === 'failed') {
                    $itemErrors[] = $r['error'] ?? 'Unknown';
                }
            }
            $item->setStatus($itemErrors === [] ? 'deleted' : 'partial_failure');
            if ($itemErrors !== []) {
                $item->setErrorMessage(implode('; ', $itemErrors));
            }
        }

        // 3. Build enriched items for execution report
        $itemReports = [];
        $totalSpaceFreed = 0;
        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            $itemErrors = [];
            $itemSpaceFreed = 0;

            foreach ($item->getMediaFileIds() as $fileId) {
                foreach ($results as $r) {
                    if (($r['media_file_id'] ?? '') === $fileId) {
                        if (($r['status'] ?? '') === 'failed') {
                            $itemErrors[] = $r['error'] ?? 'Unknown';
                        }
                        $size = $r['size_bytes'] ?? 0;
                        if ($size === 0) {
                            $size = $fileSizes[$fileId] ?? 0;
                        }
                        $itemSpaceFreed += $size;
                    }
                }
            }

            if ($movie === null) {
                $names = array_values(array_filter(array_map(
                    fn (string $fid) => $fileNames[$fid] ?? null,
                    $item->getMediaFileIds(),
                )));
                $movieLabel = $names !== [] ? implode(', ', $names) : '(fichier orphelin)';
            } else {
                $movieLabel = $movie->getTitle();
            }

            $itemReports[] = [
                'movie' => $movieLabel,
                'year' => $movie?->getYear(),
                'space_freed_bytes' => $itemSpaceFreed,
                'errors' => $itemErrors,
            ];
            $totalSpaceFreed += $itemSpaceFreed;
        }

        $deletion->setExecutionReport([
            'finished_at' => (new DateTimeImmutable())->format('c'),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'dirs_removed' => $data['dirs_removed'] ?? 0,
            'results' => $results,
            'items' => $itemReports,
            'total_space_freed' => $totalSpaceFreed,
        ]);
        $deletion->setExecutedAt(new DateTimeImmutable());

        if ($successCount === 0 && $failedCount > 0) {
            $deletion->setStatus(DeletionStatus::FAILED);
        } else {
            $deletion->setStatus(DeletionStatus::COMPLETED);
        }

        // 4. Refresh Plex/Jellyfin (best-effort)
        if ($successCount > 0 && $deletion->isDeleteMediaPlayerReference()) {
            try {
                $this->mediaPlayerRefreshService->refreshAll();
            } catch (Throwable $e) {
                $this->logger->warning('Media player refresh failed after deletion', ['error' => $e->getMessage()]);
            }
        }

        // 5. Discord notification
        try {
            if ($deletion->getStatus() === DeletionStatus::COMPLETED) {
                $this->discordNotificationService->sendDeletionSuccess($deletion);
            } else {
                $this->discordNotificationService->sendDeletionError($deletion);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Discord notification failed', ['error' => $e->getMessage()]);
        }

        // 6. Activity log
        $log = new ActivityLog();
        $log->setAction('scheduled_deletion.executed');
        $log->setEntityType('scheduled_deletion');
        $log->setEntityId($deletion->getId());
        $log->setDetails(['success' => $successCount, 'failed' => $failedCount]);
        if ($deletion->getCreatedBy() !== null) {
            $log->setUser($deletion->getCreatedBy());
        }
        $this->em->persist($log);
        $this->em->flush();

        $this->logger->info('Deletion completed', [
            'deletion_id' => $deletionId,
            'success' => $successCount,
            'failed' => $failedCount,
            'status' => $deletion->getStatus()->value,
        ]);
    }
}
