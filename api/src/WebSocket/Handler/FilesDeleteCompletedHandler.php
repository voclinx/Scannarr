<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\ActivityLog;
use App\Entity\ScheduledDeletion;
use App\Entity\User;
use App\Enum\DeletionStatus;
use App\ExternalService\Notification\DiscordNotificationService;
use App\Repository\MediaFileRepository;
use App\Service\MediaPlayerRefreshService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class FilesDeleteCompletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private MediaPlayerRefreshService $mediaPlayerRefreshService,
        private DiscordNotificationService $discordNotificationService,
        private LoggerInterface $logger,
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

        $this->processDeletion($deletion, $data, $deletionId);
    }

    /** @param array<string, mixed> $data */
    private function processDeletion(ScheduledDeletion $deletion, array $data, string $deletionId): void
    {
        $results = $data['results'] ?? [];
        $successCount = $data['deleted'] ?? 0;
        $failedCount = $data['failed'] ?? 0;

        $fileSizes = [];
        $fileNames = [];
        $this->processFileResults($results, $fileSizes, $fileNames);
        $this->updateItemStatuses($deletion, $results);

        $this->buildExecutionReport($deletion, $results, $fileSizes, $fileNames, $successCount, $failedCount, $data);
        $this->updateDeletionStatus($deletion, $successCount, $failedCount);
        $this->refreshMediaPlayers($deletion, $successCount);
        $this->sendDiscordNotification($deletion);
        $this->logDeletionActivity($deletion, $deletionId, $successCount, $failedCount);
    }

    /**
     * Remove successfully deleted media_files from DB and collect their sizes/names.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $fileSizes
     * @param array<string, string> $fileNames
     */
    private function processFileResults(array $results, array &$fileSizes, array &$fileNames): void
    {
        foreach ($results as $result) {
            if (($result['status'] ?? '') !== 'deleted') {
                continue;
            }
            $mediaFile = $this->mediaFileRepository->find($result['media_file_id'] ?? '');
            if ($mediaFile === null) {
                continue;
            }
            $fileSizes[$result['media_file_id']] = $mediaFile->getFileSizeBytes();
            $fileNames[$result['media_file_id']] = $mediaFile->getFileName();
            $this->em->remove($mediaFile);
        }
    }

    /**
     * Update each deletion item's status based on per-file results.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function updateItemStatuses(ScheduledDeletion $deletion, array $results): void
    {
        foreach ($deletion->getItems() as $item) {
            $itemErrors = $this->collectItemErrors($item->getMediaFileIds(), $results);
            $item->setStatus($itemErrors === [] ? 'deleted' : 'partial_failure');
            if ($itemErrors !== []) {
                $item->setErrorMessage(implode('; ', $itemErrors));
            }
        }
    }

    /**
     * Collect error messages for a given set of media file IDs.
     *
     * @param array<int, string> $mediaFileIds
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<int, string>
     */
    private function collectItemErrors(array $mediaFileIds, array $results): array
    {
        $errors = [];
        foreach ($results as $r) {
            if (!in_array($r['media_file_id'] ?? '', $mediaFileIds, true)) {
                continue;
            }
            if (($r['status'] ?? '') === 'failed') {
                $errors[] = $r['error'] ?? 'Unknown';
            }
        }

        return $errors;
    }

    /**
     * Build enriched execution report with per-item details.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $fileSizes
     * @param array<string, string> $fileNames
     * @param array<string, mixed> $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    private function buildExecutionReport(
        ScheduledDeletion $deletion,
        array $results,
        array $fileSizes,
        array $fileNames,
        int $successCount,
        int $failedCount,
        array $data,
    ): int {
        $itemReports = [];
        $totalSpaceFreed = 0;

        foreach ($deletion->getItems() as $item) {
            $itemReport = $this->buildItemReport($item, $results, $fileSizes, $fileNames);
            $itemReports[] = $itemReport;
            $totalSpaceFreed += $itemReport['space_freed_bytes'];
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

        return $totalSpaceFreed;
    }

    /**
     * Build a single item report entry.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $fileSizes
     * @param array<string, string> $fileNames
     *
     * @return array<string, mixed>
     */
    private function buildItemReport(object $item, array $results, array $fileSizes, array $fileNames): array
    {
        $movie = $item->getMovie();
        [$itemErrors, $itemSpaceFreed] = $this->collectFileStats($item->getMediaFileIds(), $results, $fileSizes);

        return [
            'movie' => $this->resolveMovieLabel($movie, $item->getMediaFileIds(), $fileNames),
            'year' => $movie?->getYear(),
            'space_freed_bytes' => $itemSpaceFreed,
            'errors' => $itemErrors,
        ];
    }

    /**
     * @param array<int, string> $fileIds
     * @param array<int, array<string, mixed>> $results
     * @param array<string, int> $fileSizes
     *
     * @return array{0: array<string>, 1: int}
     */
    private function collectFileStats(array $fileIds, array $results, array $fileSizes): array
    {
        $errors = [];
        $spaceFreed = 0;

        foreach ($fileIds as $fileId) {
            $fileResult = $this->findFileResult($fileId, $results);
            if ($fileResult === null) {
                continue;
            }
            if (($fileResult['status'] ?? '') === 'failed') {
                $errors[] = $fileResult['error'] ?? 'Unknown';
            }
            $size = $fileResult['size_bytes'] ?? 0;
            $spaceFreed += $size === 0 ? ($fileSizes[$fileId] ?? 0) : $size;
        }

        return [$errors, $spaceFreed];
    }

    /**
     * Find a result entry matching a given media file ID.
     *
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<string, mixed>|null
     */
    private function findFileResult(string $fileId, array $results): ?array
    {
        foreach ($results as $r) {
            if (($r['media_file_id'] ?? '') === $fileId) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Resolve a human-readable label for the movie or orphan files.
     *
     * @param array<int, string> $mediaFileIds
     * @param array<string, string> $fileNames
     */
    private function resolveMovieLabel(?object $movie, array $mediaFileIds, array $fileNames): string
    {
        if ($movie !== null) {
            return $movie->getTitle();
        }

        $names = array_values(array_filter(array_map(
            fn (string $fid) => $fileNames[$fid] ?? null,
            $mediaFileIds,
        )));

        return $names !== [] ? implode(', ', $names) : '(fichier orphelin)';
    }

    private function updateDeletionStatus(ScheduledDeletion $deletion, int $successCount, int $failedCount): void
    {
        if ($successCount === 0 && $failedCount > 0) {
            $deletion->setStatus(DeletionStatus::FAILED);

            return;
        }

        $deletion->setStatus(DeletionStatus::COMPLETED);
    }

    private function refreshMediaPlayers(ScheduledDeletion $deletion, int $successCount): void
    {
        if ($successCount <= 0 || !$deletion->isDeleteMediaPlayerReference()) {
            return;
        }

        try {
            $this->mediaPlayerRefreshService->refreshAll();
        } catch (Throwable $e) {
            $this->logger->warning('Media player refresh failed after deletion', ['error' => $e->getMessage()]);
        }
    }

    private function sendDiscordNotification(ScheduledDeletion $deletion): void
    {
        try {
            if ($deletion->getStatus() === DeletionStatus::COMPLETED) {
                $this->discordNotificationService->sendDeletionSuccess($deletion);

                return;
            }
            $this->discordNotificationService->sendDeletionError($deletion);
        } catch (Throwable $e) {
            $this->logger->warning('Discord notification failed', ['error' => $e->getMessage()]);
        }
    }

    private function logDeletionActivity(ScheduledDeletion $deletion, string $deletionId, int $successCount, int $failedCount): void
    {
        $log = new ActivityLog();
        $log->setAction('scheduled_deletion.executed');
        $log->setEntityType('scheduled_deletion');
        $log->setEntityId($deletion->getId());
        $log->setDetails(['success' => $successCount, 'failed' => $failedCount]);
        if ($deletion->getCreatedBy() instanceof User) {
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
