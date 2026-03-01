<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\Service\MovieMatcherService;
use App\WebSocket\ScanStateManager;
use App\WebSocket\WatcherFileHelper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
final readonly class ScanCompletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private ScanStateManager $scanState,
        private WatcherFileHelper $helper,
        private MovieMatcherService $movieMatcherService,
        private LoggerInterface $logger,
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

        $this->em->flush();

        if (!$scanId || !$this->scanState->hasScan($scanId)) {
            $this->logger->warning('Scan completed for unknown scan_id', ['scan_id' => $scanId]);

            return;
        }

        $volume = $this->scanState->getVolume($scanId);

        if (!$volume instanceof Volume) {
            $this->scanState->endScan($scanId);

            return;
        }

        $removedCount = $this->removeStaleFiles($volume, $scanId);
        $this->updateVolumeStats($volume, $data);
        $this->logScanCompletion($volume, $data, $scanId, $removedCount);

        $this->scanState->endScan($scanId);
        $this->runPostScanMatching($scanId);

        $this->em->clear();
    }

    private function removeStaleFiles(Volume $volume, string $scanId): int
    {
        $seenPaths = $this->scanState->getSeenPaths($scanId);
        $allDbPaths = $this->mediaFileRepository->findAllFilePathsByVolume($volume);
        $stalePaths = array_diff($allDbPaths, array_keys($seenPaths));

        if ($stalePaths === []) {
            return 0;
        }

        $removedCount = $this->mediaFileRepository->deleteByVolumeAndFilePaths($volume, $stalePaths);
        $this->logger->info('Scan cleanup: removed stale files', [
            'volume' => $volume->getName(),
            'removed' => $removedCount,
        ]);

        return $removedCount;
    }

    /** @param array<string, mixed> $data */
    private function updateVolumeStats(Volume $volume, array $data): void
    {
        $volume->setLastScanAt(new DateTimeImmutable());
        if (isset($data['total_size_bytes'])) {
            $volume->setUsedSpaceBytes((int)$data['total_size_bytes']);
        }
        $this->em->flush();
    }

    /** @param array<string, mixed> $data */
    private function logScanCompletion(Volume $volume, array $data, string $scanId, int $removedCount): void
    {
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
    }

    private function runPostScanMatching(string $scanId): void
    {
        $matchResult = $this->movieMatcherService->matchAll();
        $this->logger->info('Post-scan matching completed', [
            'scan_id' => $scanId,
            'radarr_matched' => $matchResult['radarr_matched'],
            'parse_matched' => $matchResult['parse_matched'],
            'total_links' => $matchResult['total_links'],
        ]);
    }
}
