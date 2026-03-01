<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Repository\MediaFileRepository;
use App\Service\MovieMatcherService;
use App\WebSocket\ScanStateManager;
use App\WebSocket\WatcherFileHelper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ScanCompletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly ScanStateManager $scanState,
        private readonly WatcherFileHelper $helper,
        private readonly MovieMatcherService $movieMatcherService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'scan.completed';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $scanId = $data['scan_id'] ?? null;

        // Final flush of any remaining batch
        $this->em->flush();

        if (!$scanId || !$this->scanState->hasScan($scanId)) {
            $this->logger->warning('Scan completed for unknown scan_id', ['scan_id' => $scanId]);

            return;
        }

        $volume = $this->scanState->getVolume($scanId);
        $seenPaths = $this->scanState->getSeenPaths($scanId);

        if ($volume === null) {
            $this->scanState->endScan($scanId);

            return;
        }

        // Find files in DB that were NOT seen during the scan → they've been deleted
        $allDbPaths = $this->mediaFileRepository->findAllFilePathsByVolume($volume);
        $stalePaths = array_diff($allDbPaths, array_keys($seenPaths));

        $removedCount = 0;
        if ($stalePaths !== []) {
            $removedCount = $this->mediaFileRepository->deleteByVolumeAndFilePaths($volume, $stalePaths);
            $this->logger->info('Scan cleanup: removed stale files', [
                'volume' => $volume->getName(),
                'removed' => $removedCount,
            ]);
        }

        $volume->setLastScanAt(new DateTimeImmutable());
        if (isset($data['total_size_bytes'])) {
            $volume->setUsedSpaceBytes((int)$data['total_size_bytes']);
        }
        $this->em->flush();

        $this->helper->logActivity($this->em, 'scan.completed', 'Volume', $volume->getId(), [
            'scan_id' => $scanId,
            'volume' => $volume->getName(),
            'total_files' => $data['total_files'] ?? 0,
            'total_dirs' => $data['total_dirs'] ?? 0,
            'total_size_bytes' => $data['total_size_bytes'] ?? 0,
            'duration_ms' => $data['duration_ms'] ?? 0,
            'stale_removed' => $removedCount,
        ]);
        $this->em->flush();

        $this->logger->info('Scan completed', [
            'scan_id' => $scanId,
            'volume' => $volume->getName(),
            'total_files' => $data['total_files'] ?? 0,
            'duration_ms' => $data['duration_ms'] ?? 0,
            'stale_removed' => $removedCount,
        ]);

        $this->scanState->endScan($scanId);

        $matchResult = $this->movieMatcherService->matchAll();
        $this->logger->info('Post-scan matching completed', [
            'scan_id' => $scanId,
            'radarr_matched' => $matchResult['radarr_matched'],
            'filename_matched' => $matchResult['filename_matched'],
            'total_links' => $matchResult['total_links'],
        ]);

        $this->em->clear();
    }
}
