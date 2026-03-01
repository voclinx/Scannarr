<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\MediaFile;
use App\Entity\ScheduledDeletion;
use App\Entity\Volume;
use App\Enum\DeletionStatus;
use App\ExternalService\MediaManager\RadarrService;
use App\Repository\MediaFileRepository;
use App\Service\DeletionService;
use App\WebSocket\WatcherFileHelper;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class HardlinkCompletedHandler implements WatcherMessageHandlerInterface
{
    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private DeletionService $deletionService,
        private RadarrService $radarrService,
        private WatcherFileHelper $helper,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'files.hardlink.completed';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $deletionId = $data['deletion_id'] ?? null;

        if ($deletionId === null) {
            $this->logger->warning('files.hardlink.completed without deletion_id');

            return;
        }

        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        if ($deletion === null) {
            $this->logger->warning('files.hardlink.completed for unknown deletion', ['deletion_id' => $deletionId]);

            return;
        }

        $status = $data['status'] ?? 'failed';
        if ($status === 'failed') {
            $this->handleHardlinkError($deletion, $deletionId, $data['error'] ?? '');

            return;
        }

        $this->processHardlinkSuccess($deletion, $deletionId, $data);
    }

    private function handleHardlinkError(ScheduledDeletion $deletion, string $deletionId, string $error): void
    {
        $this->logger->error('Hardlink creation failed — aborting deletion', [
            'deletion_id' => $deletionId,
            'error' => $error,
        ]);
        $deletion->setStatus(DeletionStatus::FAILED);
        $deletion->setExecutionReport([
            'finished_at' => (new DateTimeImmutable())->format('c'),
            'error' => 'Hardlink creation failed: ' . $error,
        ]);
        $this->em->flush();
    }

    /** @param array<string, mixed> $data */
    private function processHardlinkSuccess(ScheduledDeletion $deletion, string $deletionId, array $data): void
    {
        $targetPath = $data['target_path'] ?? '';
        $this->logger->info('Hardlink created successfully', ['deletion_id' => $deletionId, 'target' => $targetPath]);

        $this->updateFileAfterHardlink($targetPath);
        $this->rescanRadarrMovies($deletion);
        $this->continueDeletionChain($deletion);
    }

    private function updateFileAfterHardlink(string $targetPath): void
    {
        if ($targetPath === '') {
            return;
        }

        $targetVolume = $this->helper->resolveVolume($targetPath);
        if (!$targetVolume instanceof Volume) {
            return;
        }

        $relPath = $this->helper->getRelativePath($targetPath, $targetVolume);
        $existing = $this->mediaFileRepository->findByVolumeAndFilePath($targetVolume, $relPath);

        if ($existing instanceof MediaFile) {
            $existing->setIsLinkedMediaPlayer(true);
            $this->em->flush();

            return;
        }

        $newFile = new MediaFile();
        $newFile->setVolume($targetVolume);
        $newFile->setFilePath($relPath);
        $newFile->setFileName(basename($targetPath));
        $newFile->setIsLinkedMediaPlayer(true);
        $this->em->persist($newFile);
        $this->em->flush();
    }

    private function rescanRadarrMovies(ScheduledDeletion $deletion): void
    {
        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            if ($movie === null) {
                continue;
            }
            $radarrInstance = $movie->getRadarrInstance();
            $radarrId = $movie->getRadarrId();
            if ($radarrInstance === null) {
                continue;
            }
            if ($radarrId === null) {
                continue;
            }
            try {
                $this->radarrService->rescanMovie($radarrInstance, $radarrId);
            } catch (Throwable $e) {
                $this->logger->warning('Radarr rescan failed after hardlink', ['error' => $e->getMessage()]);
            }
        }
    }

    private function continueDeletionChain(ScheduledDeletion $deletion): void
    {
        try {
            $this->deletionService->executeDeletion($deletion);
        } catch (Throwable $e) {
            $this->logger->error('Failed to continue deletion after hardlink', ['error' => $e->getMessage()]);
            $deletion->setStatus(DeletionStatus::FAILED);
            $this->em->flush();
        }
    }
}
