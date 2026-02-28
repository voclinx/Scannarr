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

final class HardlinkCompletedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $mediaFileRepository,
        private readonly DeletionService $deletionService,
        private readonly RadarrService $radarrService,
        private readonly WatcherFileHelper $helper,
        private readonly LoggerInterface $logger,
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
        $status = $data['status'] ?? 'failed';
        $targetPath = $data['target_path'] ?? '';
        $error = $data['error'] ?? '';

        if ($deletionId === null) {
            $this->logger->warning('files.hardlink.completed without deletion_id');

            return;
        }

        $deletion = $this->em->getRepository(ScheduledDeletion::class)->find($deletionId);
        if ($deletion === null) {
            $this->logger->warning('files.hardlink.completed for unknown deletion', ['deletion_id' => $deletionId]);

            return;
        }

        if ($status === 'failed') {
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

            return;
        }

        $this->logger->info('Hardlink created successfully', ['deletion_id' => $deletionId, 'target' => $targetPath]);

        // a. Create/update MediaFile for the new hardlink in media/
        if ($targetPath !== '') {
            $targetVolume = $this->helper->resolveVolume($targetPath);
            if ($targetVolume instanceof Volume) {
                $relPath = $this->helper->getRelativePath($targetPath, $targetVolume);
                $existing = $this->mediaFileRepository->findByVolumeAndFilePath($targetVolume, $relPath);
                if ($existing instanceof MediaFile) {
                    $existing->setIsLinkedMediaPlayer(true);
                } else {
                    $newFile = new MediaFile();
                    $newFile->setVolume($targetVolume);
                    $newFile->setFilePath($relPath);
                    $newFile->setFileName(basename((string)$targetPath));
                    $newFile->setIsLinkedMediaPlayer(true);
                    $this->em->persist($newFile);
                }
                $this->em->flush();
            }
        }

        // b. Radarr rescan (best-effort)
        foreach ($deletion->getItems() as $item) {
            $movie = $item->getMovie();
            if ($movie === null) {
                continue;
            }
            $radarrInstance = $movie->getRadarrInstance();
            $radarrId = $movie->getRadarrId();
            if ($radarrInstance !== null && $radarrId !== null) {
                try {
                    $this->radarrService->rescanMovie($radarrInstance, $radarrId);
                } catch (Throwable $e) {
                    $this->logger->warning('Radarr rescan failed after hardlink', ['error' => $e->getMessage()]);
                }
            }
        }

        // c. Continue deletion chain
        try {
            $this->deletionService->executeDeletion($deletion);
        } catch (Throwable $e) {
            $this->logger->error('Failed to continue deletion after hardlink', ['error' => $e->getMessage()]);
            $deletion->setStatus(DeletionStatus::FAILED);
            $this->em->flush();
        }
    }
}
