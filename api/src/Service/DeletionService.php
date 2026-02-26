<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\MediaFile;
use App\Entity\Movie;
use App\Entity\RadarrInstance;
use App\Entity\ScheduledDeletion;
use App\Entity\ScheduledDeletionItem;
use App\Entity\User;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\Repository\MediaFileRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Throwable;

class DeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly RadarrService $radarrService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a scheduled deletion — process all items.
     *
     * @return array{success: int, failed: int, report: array<string, mixed>}
     */
    public function executeDeletion(ScheduledDeletion $deletion): array
    {
        $deletion->setStatus(DeletionStatus::EXECUTING);
        $this->em->flush();

        $successCount = 0;
        $failedCount = 0;
        $report = [
            'started_at' => (new DateTimeImmutable())->format('c'),
            'items' => [],
        ];

        foreach ($deletion->getItems() as $item) {
            $itemReport = $this->processItem($deletion, $item);

            if ($item->getStatus() === 'deleted') {
                ++$successCount;
            } else {
                ++$failedCount;
            }

            $report['items'][] = $itemReport;
        }

        $report['finished_at'] = (new DateTimeImmutable())->format('c');
        $report['success_count'] = $successCount;
        $report['failed_count'] = $failedCount;

        // Set final status
        if ($failedCount === 0) {
            $deletion->setStatus(DeletionStatus::COMPLETED);
        } else {
            $deletion->setStatus(DeletionStatus::FAILED);
        }

        $deletion->setExecutedAt(new DateTimeImmutable());
        $deletion->setExecutionReport($report);

        // Log activity
        $log = new ActivityLog();
        $log->setAction('scheduled_deletion.executed');
        $log->setEntityType('scheduled_deletion');
        $log->setEntityId($deletion->getId());
        $log->setDetails([
            'success' => $successCount,
            'failed' => $failedCount,
            'total_items' => $deletion->getItems()->count(),
        ]);

        $user = $deletion->getCreatedBy();
        if ($user instanceof User) {
            $log->setUser($user);
        }

        $this->em->persist($log);
        $this->em->flush();

        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'report' => $report,
        ];
    }

    /**
     * Process a single deletion item.
     *
     * @return array<string, mixed>
     */
    private function processItem(ScheduledDeletion $deletion, ScheduledDeletionItem $item): array
    {
        $movie = $item->getMovie();
        $movieTitle = $movie?->getTitle() ?? 'Unknown';
        $movieYear = $movie?->getYear();

        $itemReport = [
            'movie' => $movieTitle,
            'year' => $movieYear,
            'files_deleted' => 0,
            'files_failed' => 0,
            'errors' => [],
        ];

        try {
            $totalSpaceFreed = 0;

            // 1. Delete physical files if requested
            if ($deletion->isDeletePhysicalFiles()) {
                foreach ($item->getMediaFileIds() as $mediaFileId) {
                    $mediaFile = $this->mediaFileRepository->find($mediaFileId);

                    if ($mediaFile === null) {
                        $itemReport['errors'][] = "Media file not found: {$mediaFileId}";
                        ++$itemReport['files_failed'];
                        continue;
                    }

                    $deleted = $this->deletePhysicalFile($mediaFile);

                    if ($deleted) {
                        $totalSpaceFreed += $mediaFile->getFileSizeBytes();
                        ++$itemReport['files_deleted'];

                        // Remove from database (cascades to movie_files)
                        $this->em->remove($mediaFile);
                    } else {
                        ++$itemReport['files_failed'];
                        $itemReport['errors'][] = "Failed to delete: {$mediaFile->getFileName()}";
                    }
                }

                $this->em->flush();
            }

            $itemReport['space_freed_bytes'] = $totalSpaceFreed;

            // 2. Delete Radarr reference if requested
            if ($deletion->isDeleteRadarrReference() && $movie instanceof Movie) {
                $radarrResult = $this->dereferenceFromRadarr($movie);
                $itemReport['radarr_dereferenced'] = $radarrResult['success'];
                if (!$radarrResult['success'] && $radarrResult['error'] !== null) {
                    $itemReport['errors'][] = 'Radarr: ' . $radarrResult['error'];
                }
            }

            // 3. Delete media player reference if requested (V2 placeholder)
            if ($deletion->isDeleteMediaPlayerReference()) {
                $itemReport['media_player_dereferenced'] = false;
                // Not implemented in V1
            }

            // Determine item status
            if (empty($itemReport['errors'])) {
                $item->setStatus('deleted');
            } elseif ($itemReport['files_deleted'] > 0) {
                // Partial success
                $item->setStatus('deleted');
                $item->setErrorMessage(implode('; ', $itemReport['errors']));
            } else {
                $item->setStatus('failed');
                $item->setErrorMessage(implode('; ', $itemReport['errors']));
            }
        } catch (Throwable $e) {
            $this->logger->error('Error processing deletion item', [
                'movie' => $movieTitle,
                'error' => $e->getMessage(),
            ]);

            $item->setStatus('failed');
            $item->setErrorMessage($e->getMessage());
            $itemReport['errors'][] = $e->getMessage();
        }

        return $itemReport;
    }

    /**
     * Delete a physical file from disk.
     */
    private function deletePhysicalFile(MediaFile $mediaFile): bool
    {
        $volume = $mediaFile->getVolume();
        if (!$volume instanceof Volume) {
            $this->logger->warning('Cannot delete file: no volume associated', [
                'file_id' => (string)$mediaFile->getId(),
            ]);

            return false;
        }

        $physicalPath = rtrim($volume->getPath() ?? '', '/') . '/' . $mediaFile->getFilePath();

        if (!file_exists($physicalPath)) {
            $this->logger->info('File already deleted from disk', ['path' => $physicalPath]);

            // Consider it a success since the file is gone
            return true;
        }

        if (@unlink($physicalPath)) {
            $this->logger->info('File deleted successfully', ['path' => $physicalPath]);

            return true;
        }

        $this->logger->error('Failed to delete file', [
            'path' => $physicalPath,
            'error' => error_get_last()['message'] ?? 'Unknown error',
        ]);

        return false;
    }

    /**
     * Dereference a movie from Radarr (remove without deleting files in Radarr).
     *
     * @return array{success: bool, error: ?string}
     */
    private function dereferenceFromRadarr(Movie $movie): array
    {
        $radarrInstance = $movie->getRadarrInstance();
        $radarrId = $movie->getRadarrId();

        if (!$radarrInstance instanceof RadarrInstance || $radarrId === null) {
            return ['success' => true, 'error' => null]; // Nothing to dereference
        }

        try {
            $this->radarrService->deleteMovie(
                $radarrInstance,
                $radarrId,
                false, // Don't delete files via Radarr
                false,  // Don't add exclusion
            );

            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            $this->logger->error('Failed to dereference from Radarr', [
                'movie' => $movie->getTitle(),
                'radarr_id' => $radarrId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculate total size of files in a scheduled deletion.
     */
    public function calculateTotalSize(ScheduledDeletion $deletion): int
    {
        $totalSize = 0;

        foreach ($deletion->getItems() as $item) {
            foreach ($item->getMediaFileIds() as $mediaFileId) {
                $mediaFile = $this->mediaFileRepository->find($mediaFileId);
                if ($mediaFile !== null) {
                    $totalSize += $mediaFile->getFileSizeBytes();
                }
            }
        }

        return $totalSize;
    }
}
