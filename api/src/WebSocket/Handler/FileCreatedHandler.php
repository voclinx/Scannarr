<?php

declare(strict_types=1);

namespace App\WebSocket\Handler;

use App\Contract\WebSocket\WatcherMessageHandlerInterface;
use App\Entity\MediaFile;
use App\Entity\MovieFile;
use App\Entity\Volume;
use App\Repository\MediaFileRepository;
use App\Service\MovieMatcherService;
use App\WebSocket\WatcherFileHelper;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

final readonly class FileCreatedHandler implements WatcherMessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaFileRepository $mediaFileRepository,
        private WatcherFileHelper $helper,
        private MovieMatcherService $movieMatcherService,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'file.created';
    }

    public function handle(array $message): void
    {
        $data = $message['data'] ?? [];
        $this->handleCreated($data);
    }

    /** @param array<string, mixed> $data */
    public function handleCreated(array $data): void
    {
        $path = $data['path'] ?? null;
        if (!$path) {
            return;
        }

        $volume = $this->helper->resolveVolume($path);
        if (!$volume instanceof Volume) {
            $this->logger->warning('No volume found for path', ['path' => $path]);

            return;
        }

        $relativePath = $this->helper->getRelativePath($path, $volume);
        $existing = $this->mediaFileRepository->findByVolumeAndFilePath($volume, $relativePath);

        if ($existing instanceof MediaFile) {
            $this->updateExistingFile($existing, $data, $relativePath, $volume);

            return;
        }

        $this->createNewFile($volume, $relativePath, $data);
    }

    /** @param array<string, mixed> $data */
    private function updateExistingFile(MediaFile $existing, array $data, string $relativePath, Volume $volume): void
    {
        $existing->setFileSizeBytes((int)($data['size_bytes'] ?? 0));
        $existing->setHardlinkCount((int)($data['hardlink_count'] ?? 1));
        $existing->setFileName($data['name'] ?? basename((string)($data['path'] ?? '')));
        $this->helper->applyOptionalFields($existing, $data);
        $this->em->flush();
        $this->logger->info('File updated (re-created)', ['path' => $relativePath, 'volume' => $volume->getName()]);
    }

    /** @param array<string, mixed> $data */
    private function createNewFile(Volume $volume, string $relativePath, array $data): void
    {
        $mediaFile = $this->helper->createMediaFile($volume, $relativePath, $data);
        $this->em->persist($mediaFile);
        $this->em->flush();

        $this->logger->info('File created', [
            'path' => $relativePath,
            'volume' => $volume->getName(),
            'size' => $data['size_bytes'] ?? 0,
        ]);

        $this->tryMatchMovie($mediaFile, $relativePath);
    }

    private function tryMatchMovie(MediaFile $mediaFile, string $relativePath): void
    {
        try {
            $movieFile = $this->movieMatcherService->matchSingleFile($mediaFile);
            if (!$movieFile instanceof MovieFile) {
                $this->logger->debug('No movie match found for file', ['path' => $relativePath]);

                return;
            }
            $this->logger->info('File matched to movie', [
                'path' => $relativePath,
                'movie' => $movieFile->getMovie()->getTitle(),
            ]);
        } catch (Exception $e) {
            $this->logger->warning('Movie matching failed for file', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
